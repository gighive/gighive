# Week 1B: TUS Protocol for Cloudflare Compatibility - Implementation Guide

**Document Status:** Updated October 8, 2025 to reflect current codebase

## Overview

This document outlines adding TUS (Tus Resumable Upload) protocol support to the GigHive iOS client for bypassing Cloudflare's 100MB upload limit. 

**Important:** The original memory issue has been solved with `MultipartInputStream` (October 2025). This guide now focuses on adding TUS specifically for Cloudflare compatibility.

## Current State Analysis (October 2025)

### ✅ Already Implemented
- **MultipartInputStream**: Custom `InputStream` that streams files without loading into memory ✅
- **Direct Streaming**: Files of any size upload successfully (tested up to 4.7GB) ✅
- **Memory Efficient**: Only 4MB chunks in memory at a time ✅
- **Progress Tracking**: Real network progress via `URLSessionTaskDelegate` ✅
- **Project Structure**: Clean XcodeGen-based project with `project.yml` configuration ✅
- **Server Ready**: Apache configuration supports 5GB uploads ✅

### 🎯 New Goal: Cloudflare Compatibility
**Cloudflare 100MB Limit**: Files >100MB fail with HTTP 413 when uploaded through Cloudflare proxy. TUS protocol will enable chunked uploads (multiple HTTP requests) to bypass this limit.

**Current Behavior:**
- ✅ Files <100MB: Work through Cloudflare
- ❌ Files >100MB: Cloudflare returns HTTP 413 (tested: 177MB, 241MB, 350MB all fail)
- ✅ All files: Work on local network (no Cloudflare)

**See:** `CLOUDFLARE_UPLOAD_LIMIT.md` for detailed analysis

## Week 1 Implementation Tasks

### Task 1: Add TUSKit Dependency
**File:** `project.yml`
**Priority:** High
**Changes Required:**
```yaml
packages:
  TUSKit:
    url: https://github.com/tus/TUSKit
    from: 3.0.0

# Update dependencies in targets:
targets:
  GigHive:
    dependencies:
      - package: TUSKit
```

### Task 2: Create TUSUploadClient.swift (NEW FILE)
**Location:** `/Sources/App/TUSUploadClient.swift`
**Size:** ~80 lines
**Priority:** High

**Purpose:** Wrapper around TUSKit to match existing UploadClient interface

**Key Features:**
- 5MB chunk size (optimized for 4GB videos = 800 chunks)
- Basic auth integration
- Progress callback conversion
- Metadata handling for existing payload structure

### Task 3: Enhance UploadClient.swift
**File:** `Sources/App/UploadClient.swift`
**Changes:** Add ~30 lines
**Priority:** High

**New Method:**
```swift
func uploadWithTUS(
    _ payload: UploadPayload, 
    progress: ((Int64, Int64) -> Void)? = nil
) async throws -> (status: Int, data: Data, requestURL: URL)
```

**Important Naming:**
- ⚠️ `uploadWithMultipartInputStream()` already exists (single HTTP request with streaming)
- ✅ New method should be `uploadWithTUS()` (multiple HTTP requests via TUS protocol)
- Don't confuse "streaming" (memory efficiency) with "chunking" (multiple requests)

**Logic:**
- Files > 100MB → Use TUS protocol (multiple PATCH requests)
- Files ≤ 100MB → Use existing `MultipartInputStream` (single POST request)
- Maintains backward compatibility

### Task 4: Update UploadView.swift
**File:** `Sources/App/UploadView.swift`
**Changes:** Minimal - just change line 512
**Priority:** Medium

```swift
// CURRENT:
let (status, data, requestURL) = try await client.uploadWithMultipartInputStream(payload, progress: { ... })

// AFTER TUS IMPLEMENTATION:
let (status, data, requestURL) = try await client.uploadWithTUSIfNeeded(payload, progress: { ... })
```

**Note:** The method name was recently changed from `uploadWithChunking()` to `uploadWithMultipartInputStream()` to clarify it's single-request streaming, not TUS protocol.

## Implementation Strategy

### Phase Approach
1. **Dependency First**: Add TUSKit to project.yml and regenerate project
2. **Wrapper Creation**: Build TUSUploadClient.swift with basic functionality
3. **Integration**: Add chunking logic to existing UploadClient
4. **Testing**: Verify both small and large file uploads work

### Risk Mitigation
- ✅ **Zero Breaking Changes**: Existing small file uploads continue unchanged
- ✅ **Gradual Enhancement**: Only large files use new chunked method
- ✅ **Easy Rollback**: Can disable chunking with simple threshold change

## Expected Benefits After TUS Implementation

### Immediate Improvements
- ✅ **Cloudflare Compatibility**: Files >100MB work through Cloudflare proxy
- ✅ **Resumable Uploads**: Can resume after network interruption
- ✅ **Better Progress**: 800 granular updates for 4GB video (vs current single request)
- ✅ **Network Resilience**: Individual chunk failures don't kill entire upload

### Already Solved (via MultipartInputStream)
- ✅ **4GB Video Support**: Already working (no memory crashes)
- ✅ **Memory Efficiency**: Already at ~10MB peak usage
- ✅ **Direct Streaming**: Already implemented

### Performance Projections
- **4GB Upload Time**: 11 minutes (50 Mbps) to 55 minutes (10 Mbps)
- **Resume Capability**: Lose max 5MB on interruption vs. restarting 4GB
- **Concurrent Uploads**: 50+ simultaneous uploads possible

## Integration Points Validation

### Existing Flow Compatibility
1. **UploadPayload**: ✅ No changes needed - same structure
2. **Progress Callbacks**: ✅ Same signature `(Int64, Int64) -> Void`
3. **Error Handling**: ✅ Same async/await pattern
4. **Authentication**: ✅ Basic auth preserved
5. **Server Endpoint**: ✅ Same `/api/uploads.php` endpoint

### UI Integration
- **Progress Display**: ✅ Existing 10% bucket system works perfectly
- **Cancel Functionality**: ✅ Task cancellation preserved
- **Error Messages**: ✅ Same status code handling
- **Success Flow**: ✅ Same database link generation

## Implementation Checklist

### High Priority Tasks
- [x] Add TUSKit dependency to project.yml and regenerate Xcode project
- [x] Create TUSUploadClient.swift wrapper (~177 lines) - **COMPLETED**
- [x] Add uploadWithChunking method to UploadClient.swift (~30 lines) - **COMPLETED**
- [x] Updated to use chunked uploads for ALL files - **IMPROVED UX**
- [ ] Test chunked uploads work for all file sizes
### Medium Priority Tasks
- [ ] Verify memory usage stays low during large file uploads
- [ ] Verify progress tracking works correctly for both methods

## Success Metrics for Week 1

### Key Features Implemented

#### **Memory-Efficient Streaming:**
- **No More Memory Crashes**: Files are read in 5MB chunks instead of loading entire file
- **Progressive Upload**: Data is streamed directly to the server without accumulating in memory
- **Universal Chunking**: ALL files use chunked method for consistent UX and better cancellation
- **Backward Compatibility**: All current features work unchanged

## Technical Details

### File Size Threshold Logic

```swift
// Use TUS protocol for files > 100MB (Cloudflare limit)
if fileSize > 100 * 1024 * 1024 {
    // Multiple PATCH requests via TUS protocol
    return try await uploadWithTUS(payload: payload, progress: progress)
} else {
    // Single POST request with MultipartInputStream
    return try await uploadWithMultipartInputStream(payload: payload, progress: progress)
}
```

### Key Differences

| Method | HTTP Requests | Use Case | Cloudflare Compatible |
|--------|---------------|----------|----------------------|
| `uploadWithMultipartInputStream()` | 1 POST | Files <100MB | ✅ Yes |
| `uploadWithTUS()` | Multiple PATCH | Files >100MB | ✅ Yes |
| Old `upload()` method | 1 POST (loads to memory) | Deprecated | ⚠️ <100MB only |

### TUS Configuration
- **Chunk Size**: 5MB (optimal for 4GB videos = 800 chunks)
- **Retry Count**: 3 per chunk
- **Request Timeout**: 5 minutes per chunk
- **Memory Usage**: ~10MB peak (5MB chunk + overhead)

## Ready for Implementation (When Needed)

**Current Status:** TUS implementation is **not urgent** because:
- ✅ Memory issues already solved with `MultipartInputStream`
- ✅ All file sizes work on local network
- ⏸️ No users uploading through Cloudflare yet

**When to Implement:**
- Users need to upload >100MB files through Cloudflare proxy
- Need resumable uploads for unreliable networks
- Want to offer public internet uploads (not just local network)

**Implementation Readiness:**
1. **✅ Server Ready**: Apache configuration already supports 5GB uploads
2. **✅ Client Architecture**: `MultipartInputStream` provides solid foundation
3. **✅ Minimal Changes**: Only ~110 lines of new code + 1 line change
4. **⚠️ Server Changes Needed**: Must add TUS protocol endpoint (separate from existing `/api/uploads.php`)
5. **✅ Backward Compatible**: Small files continue using proven `MultipartInputStream`

**Alternative Solution:**
Instead of TUS, consider creating `upload.gighive.app` DNS record (DNS-only, bypassing Cloudflare) for simpler implementation. See `CLOUDFLARE_UPLOAD_LIMIT.md` for details.

---

**Related Documentation:**
- `STREAMING_ARCHITECTURE_20251008.md` - Current streaming implementation
- `CLOUDFLARE_UPLOAD_LIMIT.md` - Cloudflare 100MB limit analysis
- `MultipartInputStream.swift` - Current streaming implementation
