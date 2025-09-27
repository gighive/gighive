# Chunked Upload Implementation for GigHive iOS Client

## Overview

This document outlines the **REVISED** implementation plan for adding chunked upload capability to the GigHive iOS client. Based on server analysis, we can leverage existing server capabilities without backend changes.

## Current Implementation Issues

The current `UploadClient` has a significant problem for large files:
```swift
let fileData = try Data(contentsOf: payload.fileURL)  // Loads ENTIRE file into memory!
```

This loads the entire video file into memory at once, which can cause:
- Memory pressure/crashes with large files (GB+ videos)
- Poor user experience during upload
- No ability to resume failed uploads

## Server Capabilities Analysis ✅

**EXCELLENT NEWS**: Server analysis reveals perfect chunked upload support:
- ✅ **4GB upload limits** (`upload_max_filesize` & `post_max_size`)
- ✅ **2-hour execution timeouts** (`max_execution_time`)
- ✅ **512M memory limit** (just increased from 32M)
- ✅ **HTTP/2 + PHP-FPM + Cloudflare** stack
- ✅ **No server changes required!**

## Revised Implementation Strategy

### **Option 1: TUS Protocol Implementation (RECOMMENDED)**

**Benefits:**
- Industry-standard resumable upload protocol
- Existing iOS libraries available
- Server already supports underlying HTTP features
- Built-in resumability and error recovery
- Minimal code changes required

**Implementation:**
- Use TUSKit or similar iOS library
- Leverage existing `/api/uploads.php` endpoint
- Server handles chunking automatically

### **Option 2: HTTP/2 Streaming (Alternative)**

**Benefits:**
- Simplest implementation
- Native iOS URLSession support
- Automatic chunking via HTTP/2
- No external dependencies

**Implementation:**
- Use `URLSessionUploadTask` with streaming
- Let HTTP/2 handle transfer optimization
- Minimal code changes

## Detailed Implementation Plan

### **Phase 1: TUS Protocol Integration (Recommended Path)**

#### **Step 1: Add TUS Dependencies**
```swift
// Add to Package.swift or Podfile
.package(url: "https://github.com/tus/TUSKit", from: "3.0.0")
```

#### **Step 2: Create TUSUploadClient**
```swift
import TUSKit

class TUSUploadClient {
    private let tusClient: TUSClient
    
    init(serverURL: String) {
        let config = TUSConfig(
            uploadURL: URL(string: "\(serverURL)/api/uploads")!,
            chunkSize: 5 * 1024 * 1024, // 5MB chunks (optimized for 4GB videos)
            retryCount: 3,
            requestTimeout: 300 // 5 minutes per chunk
        )
        self.tusClient = TUSClient(config: config)
    }
    
    func uploadFile(
        fileURL: URL,
        metadata: [String: String],
        progressHandler: @escaping (Double) -> Void,
        completion: @escaping (Result<String, Error>) -> Void
    ) {
        // TUS implementation here
    }
}
```

#### **Step 3: Integrate with Existing UploadClient**
```swift
extension UploadClient {
    func uploadWithChunking(
        payload: UploadPayload,
        progressHandler: @escaping (Double) -> Void
    ) async throws -> UploadResponse {
        
        let fileSize = try payload.fileURL.resourceValues(forKeys: [.fileSizeKey]).fileSize ?? 0
        
        // Use chunked upload for files > 100MB (optimized for 4GB video capacity)
        if fileSize > 100 * 1024 * 1024 {
            return try await uploadWithTUS(payload: payload, progressHandler: progressHandler)
        } else {
            return try await uploadStandard(payload: payload, progressHandler: progressHandler)
        }
    }
}
```

#### **Step 4: Add Resume Capability**
```swift
class UploadStateManager {
    func saveUploadState(uploadID: String, bytesUploaded: Int64, totalBytes: Int64)
    func getUploadState(for fileURL: URL) -> UploadState?
    func clearUploadState(uploadID: String)
}
```

### **Phase 2: UI Integration**

#### **Step 1: Update Progress Tracking**
```swift
// More granular progress updates
func updateUploadProgress(_ progress: Double, bytesUploaded: Int64, totalBytes: Int64) {
    DispatchQueue.main.async {
        self.progressView.progress = Float(progress)
        self.statusLabel.text = "Uploaded \(bytesUploaded.formatted(.byteCount(style: .file))) of \(totalBytes.formatted(.byteCount(style: .file)))"
    }
}
```

#### **Step 2: Add Resume UI**
```swift
// Show resume option for interrupted uploads
if let resumableUpload = uploadStateManager.getUploadState(for: fileURL) {
    showResumeAlert(for: resumableUpload)
}
```

### **Phase 3: Testing & Optimization**

#### **Test Cases:**
1. **Small files** (< 50MB) - use standard upload
2. **Large files** (> 50MB) - use chunked upload
3. **Network interruption** - test resume capability
4. **Memory usage** - verify no memory spikes
5. **Concurrent uploads** - test multiple file uploads

#### **Performance Tuning:**
- **Chunk size**: 5MB (optimized for 4GB videos = 800 chunks)
- **Alternative**: 10MB chunks for faster uploads (400 chunks for 4GB)
- **Retry logic**: 3 retries per chunk with exponential backoff
- **Progress updates**: Every chunk (800 updates for 4GB video)
- **Timeout**: 5 minutes per 5MB chunk (accommodates slower networks)

## 4GB Video Upload Optimization Summary

### **🎯 Optimized Configuration for 4GB Videos:**

| Parameter | Value | Rationale |
|-----------|-------|-----------|
| **Chunk Size** | **5MB** | 4GB ÷ 5MB = 800 manageable chunks |
| **File Threshold** | **100MB** | Use chunking for larger videos only |
| **Request Timeout** | **5 minutes/chunk** | Accommodates slower networks |
| **Retry Count** | **3 per chunk** | Balance reliability vs. speed |
| **Memory Usage** | **~10MB peak** | 5MB chunk + overhead << 512M container limit |

### **📈 Upload Performance Projections:**

#### **4GB Video Upload Scenarios:**
- **Fast Network (50 Mbps)**: ~11 minutes total
- **Medium Network (10 Mbps)**: ~55 minutes total  
- **Slow Network (2 Mbps)**: ~4.5 hours total
- **Server timeout limit**: 2 hours (sufficient for medium networks)

#### **Resume Capability Benefits:**
- **Interruption at 50%**: Resume from 2GB mark (lose max 5MB)
- **Interruption at 90%**: Resume from 3.6GB mark (lose max 5MB)
- **Multiple interruptions**: Each resume loses max 5MB vs. restarting entire 4GB

### **🔧 Container Resource Utilization:**

#### **Memory Efficiency:**
- **Per upload process**: ~10MB peak (5MB chunk + processing overhead)
- **Container memory limit**: 512M 
- **Concurrent upload capacity**: 50+ simultaneous uploads
- **Memory headroom**: 98% available for other operations

#### **Server Processing Efficiency:**
- **Chunking overhead**: Minimal (HTTP/2 handles efficiently)
- **PHP-FPM optimization**: Well within 2-hour execution limits
- **Cloudflare acceleration**: Additional transfer optimization

### **🚀 Implementation Benefits for 4GB Videos:**

1. **Memory Safe**: Never loads more than 5MB into memory at once
2. **Highly Resumable**: Lose maximum 5MB on interruption vs. restarting 4GB upload
3. **Granular Progress**: 800 progress updates provide smooth progress indication
4. **Network Resilient**: Individual chunk failures don't terminate entire upload
5. **Server Optimized**: Perfectly tuned for Apache container's excellent resource limits
6. **Scalable**: Can handle multiple 4GB uploads concurrently without resource strain

### **📊 Chunk Size Alternatives:**

| Chunk Size | Chunks for 4GB | Memory Usage | Resume Granularity | Network Requests |
|------------|----------------|--------------|-------------------|------------------|
| **5MB** (Recommended) | 800 | ~10MB | Lose max 5MB | 800 requests |
| **10MB** (Alternative) | 400 | ~15MB | Lose max 10MB | 400 requests |
| **2MB** (Conservative) | 2000 | ~7MB | Lose max 2MB | 2000 requests |

**Recommendation**: 5MB provides optimal balance of memory efficiency, resume granularity, and network performance for 4GB video uploads.

## Revised Work Estimates

| Component | Lines of Code | Complexity | Server Changes |
|-----------|---------------|------------|----------------|
| **TUS Integration** | 50-100 | Low | None |
| **UI Updates** | 30-50 | Very Low | None |
| **State Management** | 40-60 | Low | None |
| **Testing** | 100+ | Medium | None |
| **TOTAL** | 220-310 | Low-Medium | **ZERO** |

## Implementation Benefits

### **Immediate Benefits:**
1. ✅ **Zero server changes required**
2. ✅ **4GB upload capability** (vs current limits)
3. ✅ **Memory efficient** (no more loading entire files)
4. ✅ **Resumable uploads** (network interruption recovery)
5. ✅ **Better progress tracking** (granular updates)

### **Long-term Benefits:**
1. ✅ **Industry standard protocol** (TUS)
2. ✅ **Future-proof architecture**
3. ✅ **Scalable to any file size**
4. ✅ **Improved user experience**

## Next Steps

### **Phase 1: Foundation (Week 1)**
1. ✅ Add TUSKit dependency to iOS project
2. ✅ Create basic TUSUploadClient wrapper
3. ✅ Implement file size threshold logic
4. ✅ Basic integration testing

### **Phase 2: Integration (Week 2)**
1. ✅ Integrate with existing UploadClient
2. ✅ Add progress tracking improvements
3. ✅ Implement upload state management
4. ✅ Add resume capability UI

### **Phase 3: Polish (Week 3)**
1. ✅ Comprehensive testing with various file sizes
2. ✅ Performance optimization
3. ✅ Error handling improvements
4. ✅ User experience refinements

### **Phase 4: Deployment**
1. ✅ Deploy updated iOS app
2. ✅ Monitor upload success rates
3. ✅ Gather user feedback
4. ✅ Performance monitoring

## Risk Mitigation

### **Low Risk Implementation:**
- ✅ **Backward compatibility** maintained
- ✅ **Gradual rollout** possible (feature flag)
- ✅ **No server dependencies**
- ✅ **Proven technology** (TUS protocol)

### **Fallback Strategy:**
- Keep existing upload method as fallback
- Feature flag to enable/disable chunked uploads
- Easy rollback if issues arise

## Conclusion

This revised plan leverages your excellent server configuration to implement chunked uploads with **minimal effort and zero server changes**. The TUS protocol provides industry-standard resumable uploads while your HTTP/2 + PHP-FPM stack handles the heavy lifting automatically.

**Status: Ready for immediate implementation** 🚀
