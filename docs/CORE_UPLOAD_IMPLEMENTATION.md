# GigHive Upload Architecture Documentation

## Overview

The GigHive app uses a **memory-efficient streaming upload system** with **real network progress tracking** and **proper cancellation handling**.

---

## Call Flow

```
UploadView.doUpload()
    ↓
UploadClient.uploadWithMultipartInputStream()
    ↓
NetworkProgressUploadClient.uploadFile() [async/await]
    ↓
URLSession delegates (streaming + progress)
```

---

## Component Responsibilities

### **1. UploadView.swift**
**Role:** UI Layer  
**Responsibilities:**
- Presents upload form to user
- Collects file and metadata (event date, org name, etc.)
- Displays upload progress (0%, 5%, 10%, etc.)
- Handles cancellation via "Upload" button
- Shows success/error alerts

**Key Code:**
```swift
uploadTask = Task {
    let (status, data, _) = try await client.uploadWithMultipartInputStream(
        payload,
        progress: { completed, total in
            // Update UI with progress
        }
    )
}

// Cancel on button press:
uploadTask?.cancel()
currentUploadClient?.cancelCurrentUpload()
```

---

### **2. UploadClient.swift**
**Role:** Public API / Cancellation Handler  
**Responsibilities:**
- Provides clean public API: `uploadWithMultipartInputStream()`
- Creates `NetworkProgressUploadClient` instance
- Handles Swift Task cancellation via `withTaskCancellationHandler`
- Propagates cancellation to network layer
- Stores reference for manual cancellation

**Key Code:**
```swift
func uploadWithMultipartInputStream(...) async throws -> (...) {
    let networkClient = NetworkProgressUploadClient(...)
    self.currentNetworkClient = networkClient
    
    return try await withTaskCancellationHandler {
        try await networkClient.uploadFile(
            payload: payload,
            progressHandler: { completed, total in
                progress?(completed, total)
            }
        )
    } onCancel: {
        print("⚠️ Task cancelled - cancelling network upload")
        networkClient.cancelUpload()
    }
}
```

**Why it exists:**
- Provides stable public API (can change implementation without breaking callers)
- Handles Task cancellation detection
- Manages client lifecycle

---

### **3. NetworkProgressUploadClient.swift**
**Role:** Core Upload Implementation  
**Responsibilities:**
- **Builds multipart request** with form fields and file
- **Creates MultipartInputStream** for memory-efficient streaming
- **Configures URLSession** with streaming delegates
- **Tracks REAL network progress** via `didSendBodyData` delegate
- **Handles upload completion** via `didCompleteWithError` delegate
- **Bridges URLSession delegates to async/await** using continuation
- **Manages cancellation** of underlying URLSession task

**Key Features:**
- ✅ **Memory efficient** - Streams file directly from disk (no loading into RAM)
- ✅ **Real network progress** - Tracks actual bytes sent over network
- ✅ **Async/await native** - Modern Swift concurrency
- ✅ **Proper cancellation** - Resumes continuation on cancel

**Key Code:**
```swift
func uploadFile(...) async throws -> (status: Int, data: Data, requestURL: URL) {
    return try await withCheckedThrowingContinuation { continuation in
        self.continuation = continuation
        
        // Create MultipartInputStream
        let stream = try MultipartInputStream(...)
        
        // Start URLSession upload task
        let task = session.uploadTask(withStreamedRequest: request)
        task.resume()
        
        // Delegates will resume continuation when done
    }
}

// URLSession delegate:
func urlSession(..., didCompleteWithError error: Error?) {
    if let error = error {
        continuation?.resume(throwing: error)
    } else {
        continuation?.resume(returning: (status, data, url))
    }
}
```

---

### **4. MultipartInputStream.swift**
**Role:** Streaming Data Source  
**Responsibilities:**
- Implements `InputStream` protocol
- Streams multipart form data on-the-fly
- Provides data in phases: header → file content → footer
- Never loads entire file into memory
- Calculates total content length upfront

**Phases:**
1. **Header** - Form fields and file metadata
2. **File** - Streams file content directly from disk
3. **Footer** - Multipart boundary closing

---

## Data Flow Example

### **Upload a 6GB Video:**

```
1. User selects video.mov (6GB)
   ↓
2. UploadView creates UploadPayload
   ↓
3. UploadClient.uploadWithMultipartInputStream()
   ↓
4. NetworkProgressUploadClient.uploadFile()
   ↓
5. MultipartInputStream created
   - Calculates: 6GB + headers = 6,000,123,456 bytes total
   ↓
6. URLSession.uploadTask(withStreamedRequest:)
   - Reads from MultipartInputStream in chunks
   - Streams directly to network
   ↓
7. Progress callbacks fire:
   - didSendBodyData: 300MB / 6GB (5%)
   - didSendBodyData: 600MB / 6GB (10%)
   - ... etc
   ↓
8. UploadView updates UI: "10%..."
   ↓
9. Upload completes
   ↓
10. didCompleteWithError fires
    ↓
11. Continuation resumes with (status: 201, data: {...}, url: ...)
    ↓
12. UploadView shows success alert
```

**Memory used:** ~10-20MB (buffers only, not the 6GB file!)

---

## Cancellation Flow

```
1. User hits "Upload" button (shows "Uploading...")
   ↓
2. UploadView calls:
   - uploadTask?.cancel()
   - currentUploadClient?.cancelCurrentUpload()
   ↓
3. Task cancellation detected by withTaskCancellationHandler
   ↓
4. onCancel block fires:
   - networkClient.cancelUpload()
   ↓
5. NetworkProgressUploadClient.cancelUpload():
   - currentUploadTask?.cancel()
   ↓
6. URLSession cancels task
   ↓
7. didCompleteWithError fires with URLError.cancelled
   ↓
8. Continuation resumes: continuation?.resume(throwing: error)
   ↓
9. UploadView catches error, shows "cancelled" in debug log
   ↓
10. UI returns to normal state
```

**Result:** Clean cancellation, no continuation leak! ✅

---

## Key Design Decisions

### **Why Streaming Instead of Loading File?**
- ❌ **Loading:** `Data(contentsOf: url)` loads entire 6GB into RAM → crashes
- ✅ **Streaming:** `MultipartInputStream` reads chunks → uses ~10MB RAM

### **Why URLSession Delegates Instead of Simple Upload?**
- ❌ **Simple:** `URLSession.upload(from: data)` gives fake progress (buffer, not network)
- ✅ **Delegates:** `didSendBodyData` gives REAL network progress

### **Why Async/Await Instead of Callbacks?**
- ❌ **Callbacks:** Nested closures, hard to cancel, error-prone
- ✅ **Async/Await:** Linear code, built-in cancellation, modern Swift

### **Why Continuation in NetworkProgressUploadClient?**
- URLSession uses **delegate callbacks** (old style)
- Swift concurrency uses **async/await** (new style)
- Continuation **bridges** the two worlds

---

## Files Summary

| File | Lines | Purpose |
|------|-------|---------|
| `UploadView.swift` | ~750 | UI and user interaction |
| `UploadClient.swift` | ~105 | Public API + cancellation handling |
| `NetworkProgressUploadClient.swift` | ~300 | Core upload implementation |
| `MultipartInputStream.swift` | ~200 | Streaming data source |
| `UploadPayload.swift` | ~15 | Data model |
| `AppConstants.swift` | ~15 | Max upload size constant |

**Total:** ~1,385 lines of upload-related code

---

## Testing Checklist

- ✅ Upload small file (< 100MB) - Works
- ✅ Upload large file (> 1GB) - Works, low memory usage
- ✅ Cancel during upload - Clean cancellation, no leak
- ✅ Network error handling - Proper error propagation
- ✅ Progress tracking - Real network progress displayed

---

**Architecture is clean, efficient, and maintainable! 🎉**
