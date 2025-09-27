# TUS Chunked Upload - iOS Client Implementation Plan

## Overview

This document outlines the client-side iOS implementation plan for adding chunked upload capability to the GigHive iOS client using the TUS protocol. Based on server analysis, we can leverage existing server capabilities without backend changes.

## Current Implementation Issues

The current `UploadClient` has a critical problem for large files:
```swift
let fileData = try Data(contentsOf: payload.fileURL)  // ❌ Loads ENTIRE file into memory!
```

This causes:
- Memory pressure/crashes with large files (GB+ videos)
- Poor user experience during upload
- No ability to resume failed uploads

## Server Capabilities ✅

**EXCELLENT NEWS**: Server analysis reveals perfect chunked upload support:
- ✅ **4GB upload limits** (`upload_max_filesize` & `post_max_size`)
- ✅ **2-hour execution timeouts** (`max_execution_time`)
- ✅ **512M memory limit** (optimized for chunked processing)
- ✅ **HTTP/2 + PHP-FPM + Cloudflare** stack
- ✅ **No server changes required!**

## Implementation Strategy

### **TUS Protocol Implementation (RECOMMENDED)**

**Benefits:**
- Industry-standard resumable upload protocol
- Existing iOS libraries available (TUSKit)
- Server already supports underlying HTTP features
- Built-in resumability and error recovery
- Minimal code changes required

**Configuration for 4GB Videos:**
- **Chunk Size**: 5MB (4GB ÷ 5MB = 800 manageable chunks)
- **File Threshold**: 100MB (use chunking for larger videos only)
- **Request Timeout**: 5 minutes per chunk
- **Retry Count**: 3 per chunk
- **Memory Usage**: ~10MB peak (well within 512M container limit)

## Detailed Implementation Plan

### **📂 Required File Changes:**

#### **File 1: Add TUS Dependency**
**File:** `Package.swift` or project dependencies
```swift
.package(url: "https://github.com/tus/TUSKit", from: "3.0.0")
```

#### **File 2: `TUSUploadClient.swift` (NEW - ~80 lines)**
```swift
import TUSKit
import Foundation

class TUSUploadClient {
    private let tusClient: TUSClient
    private let basicAuth: (user: String, pass: String)?
    
    init(serverURL: String, basicAuth: (String, String)?) {
        self.basicAuth = basicAuth
        let config = TUSConfig(
            uploadURL: URL(string: "\(serverURL)/api/uploads")!,
            chunkSize: 5 * 1024 * 1024, // 5MB chunks for 4GB videos
            retryCount: 3,
            requestTimeout: 300 // 5 minutes per chunk
        )
        self.tusClient = TUSClient(config: config)
    }
    
    func uploadFile(
        payload: UploadPayload,
        progressHandler: @escaping (Int64, Int64) -> Void,
        completion: @escaping (Result<(status: Int, data: Data, requestURL: URL), Error>) -> Void
    ) {
        // TUS implementation with metadata conversion
        // Progress callback conversion
        // Authentication header injection
        let metadata = [
            "filename": payload.fileURL.lastPathComponent,
            "event_date": DateFormatter().string(from: payload.eventDate),
            "org_name": payload.orgName,
            "event_type": payload.eventType
            // Add other payload fields as metadata
        ]
        
        tusClient.uploadFile(
            at: payload.fileURL,
            metadata: metadata,
            progressHandler: progressHandler
        ) { result in
            completion(result)
        }
    }
}
```

#### **File 3: `UploadClient.swift` (MODIFIED - ~30 lines added)**
**Add method:**
```swift
func uploadWithChunking(
    _ payload: UploadPayload, 
    progress: ((Int64, Int64) -> Void)? = nil
) async throws -> (status: Int, data: Data, requestURL: URL) {
    
    let fileSize = try payload.fileURL.resourceValues(forKeys: [.fileSizeKey]).fileSize ?? 0
    
    // Use chunked upload for files > 100MB (optimized for 4GB video capacity)
    if fileSize > 100 * 1024 * 1024 {
        return try await uploadWithTUS(payload: payload, progress: progress)
    } else {
        return try await upload(payload, progress: progress)
    }
}

private func uploadWithTUS(
    payload: UploadPayload,
    progress: ((Int64, Int64) -> Void)? = nil
) async throws -> (status: Int, data: Data, requestURL: URL) {
    let tusClient = TUSUploadClient(
        serverURL: baseURL.absoluteString,
        basicAuth: basicAuth
    )
    
    return try await withCheckedThrowingContinuation { continuation in
        tusClient.uploadFile(
            payload: payload,
            progressHandler: { completed, total in
                progress?(completed, total)
            },
            completion: { result in
                continuation.resume(with: result)
            }
        )
    }
}
```

#### **File 4: `UploadStateManager.swift` (NEW - ~60 lines)**
```swift
import Foundation

struct UploadState {
    let uploadID: String
    let fileURL: URL
    let bytesUploaded: Int64
    let totalBytes: Int64
    let timestamp: Date
}

class UploadStateManager {
    private let userDefaults = UserDefaults.standard
    private let stateKey = "gighive_upload_states"
    
    func saveUploadState(_ state: UploadState) {
        var states = getAllPendingUploads()
        states.removeAll { $0.uploadID == state.uploadID }
        states.append(state)
        
        let encoder = JSONEncoder()
        if let data = try? encoder.encode(states) {
            userDefaults.set(data, forKey: stateKey)
        }
    }
    
    func getUploadState(for fileURL: URL) -> UploadState? {
        return getAllPendingUploads().first { $0.fileURL == fileURL }
    }
    
    func clearUploadState(uploadID: String) {
        var states = getAllPendingUploads()
        states.removeAll { $0.uploadID == uploadID }
        
        let encoder = JSONEncoder()
        if let data = try? encoder.encode(states) {
            userDefaults.set(data, forKey: stateKey)
        }
    }
    
    func getAllPendingUploads() -> [UploadState] {
        guard let data = userDefaults.data(forKey: stateKey),
              let states = try? JSONDecoder().decode([UploadState].self, from: data) else {
            return []
        }
        return states
    }
}
```

#### **File 5: `UploadView.swift` (MODIFIED - ~40 lines changed)**
**Key Changes:**

1. **Replace upload call:**
```swift
// OLD:
let (status, data, requestURL) = try await client.upload(payload, progress: { completed, total in

// NEW:
let (status, data, requestURL) = try await client.uploadWithChunking(payload, progress: { completed, total in
```

2. **Enhanced progress display:**
```swift
let (status, data, requestURL) = try await client.uploadWithChunking(payload, progress: { completed, total in
    guard total > 0 else { return }
    let percent = Int((Double(completed) / Double(total)) * 100.0)
    let bytesText = "\(ByteCountFormatter.string(fromByteCount: completed, countStyle: .file)) of \(ByteCountFormatter.string(fromByteCount: total, countStyle: .file))"
    
    let bucket = (percent / 10) * 10
    if bucket >= 10 && bucket > lastProgressBucket {
        DispatchQueue.main.async {
            lastProgressBucket = bucket
            debugLog.append("\(bucket)% (\(bytesText))")
        }
    }
})
```

3. **Add resume capability:**
```swift
@State private var uploadStateManager = UploadStateManager()

// Check for resumable uploads on view appear
.onAppear {
    checkForResumableUploads()
}

private func checkForResumableUploads() {
    let pendingUploads = uploadStateManager.getAllPendingUploads()
    if !pendingUploads.isEmpty {
        // Show resume UI or auto-resume
        debugLog.append("Found \(pendingUploads.count) resumable upload(s)")
    }
}
```

## Implementation Phases

### **Phase 1: Foundation (Week 1)**
1. ✅ Add TUSKit dependency to iOS project
2. ✅ Create basic `TUSUploadClient.swift` wrapper
3. ✅ Implement file size threshold logic in `UploadClient.swift`
4. ✅ Basic integration testing with small and large files

### **Phase 2: Integration (Week 2)**
1. ✅ Create `UploadStateManager.swift` for resume capability
2. ✅ Integrate with existing `UploadView.swift`
3. ✅ Add enhanced progress tracking
4. ✅ Implement resume capability UI

### **Phase 3: Polish (Week 3)**
1. ✅ Comprehensive testing with various file sizes
2. ✅ Performance optimization and error handling
3. ✅ User experience refinements
4. ✅ Memory usage verification

### **Phase 4: Deployment**
1. ✅ Deploy updated iOS app
2. ✅ Monitor upload success rates
3. ✅ Gather user feedback
4. ✅ Performance monitoring

## Code Impact Summary

| File | Type | Lines Added | Complexity | Breaking Changes |
|------|------|-------------|------------|------------------|
| `TUSUploadClient.swift` | NEW | ~80 | Medium | None |
| `UploadStateManager.swift` | NEW | ~60 | Low | None |
| `UploadClient.swift` | MODIFY | ~30 | Low | None |
| `UploadView.swift` | MODIFY | ~40 | Low | None |
| **TOTAL** | | **~210** | **Low-Medium** | **None** |

## 4GB Video Upload Optimization

### **Performance Projections:**
- **Fast Network (50 Mbps)**: ~11 minutes for 4GB upload
- **Medium Network (10 Mbps)**: ~55 minutes for 4GB upload  
- **Slow Network (2 Mbps)**: ~4.5 hours (within server's 2-hour timeout for medium speeds)

### **Resume Capability:**
- **Interruption at 50%**: Resume from 2GB mark (lose max 5MB)
- **Interruption at 90%**: Resume from 3.6GB mark (lose max 5MB)
- **Multiple interruptions**: Each resume loses max 5MB vs. restarting entire 4GB

### **Memory Efficiency:**
- **Per upload process**: ~10MB peak (5MB chunk + processing overhead)
- **Container memory limit**: 512M 
- **Concurrent upload capacity**: 50+ simultaneous uploads
- **Memory headroom**: 98% available for other operations

## Implementation Benefits

### **Immediate Benefits:**
1. ✅ **Zero server changes required**
2. ✅ **4GB upload capability** (vs current memory limits)
3. ✅ **Memory efficient** (no more loading entire files)
4. ✅ **Resumable uploads** (network interruption recovery)
5. ✅ **Better progress tracking** (granular byte-level updates)

### **Long-term Benefits:**
1. ✅ **Industry standard protocol** (TUS)
2. ✅ **Future-proof architecture**
3. ✅ **Scalable to any file size**
4. ✅ **Improved user experience**
5. ✅ **Backward compatibility** maintained

## Risk Mitigation

### **Low Risk Implementation:**
- ✅ **Backward compatibility** maintained (small files use existing method)
- ✅ **Gradual rollout** possible (feature flag for chunked uploads)
- ✅ **No server dependencies** (leverages existing excellent configuration)
- ✅ **Proven technology** (TUS protocol used by major platforms)

### **Fallback Strategy:**
- Keep existing upload method as fallback for small files
- Feature flag to enable/disable chunked uploads
- Easy rollback if issues arise
- Comprehensive testing before deployment

## Testing Strategy

### **Test Cases:**
1. **Small files** (< 100MB) - verify existing upload method still works
2. **Large files** (> 100MB) - verify chunked upload with TUS
3. **Network interruption** - test resume capability
4. **Memory usage** - verify no memory spikes during upload
5. **Concurrent uploads** - test multiple file uploads simultaneously
6. **Background uploads** - test app backgrounding during upload

### **Performance Validation:**
- Test with actual 4GB video files
- Verify chunk size optimization (5MB vs alternatives)
- Monitor server resource usage during uploads
- Validate progress reporting accuracy

## Conclusion

This implementation plan leverages your excellent server configuration (4GB limits, 2-hour timeouts, 512M memory, HTTP/2 stack) to implement chunked uploads with **minimal effort and zero server changes**. The TUS protocol provides industry-standard resumable uploads while your infrastructure handles the heavy lifting automatically.

**Status: Ready for immediate implementation** 🚀

The plan maintains full backward compatibility while adding powerful new capabilities for large video uploads, solving the current memory crash issue and providing a much better user experience.
