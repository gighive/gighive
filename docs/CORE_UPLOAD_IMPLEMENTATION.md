# GigHive Upload Architecture Documentation

## Overview

The GigHive app uses a **memory-efficient streaming upload system** with **real network progress tracking** and **proper cancellation handling**.

---

## Upload Size Limits

### iOS App Limit
- **Location:** `GigHive/Sources/App/AppConstants.swift`
- **Current Limit:** 6 GB (6,442,450,944 bytes)
- **Purpose:** Pre-upload validation to prevent wasted time uploading files that will be rejected

```swift
enum AppConstants {
    static let MAX_UPLOAD_SIZE_BYTES: Int64 = 6_442_450_944  // 6 GB
    static var MAX_UPLOAD_SIZE_FORMATTED: String {
        ByteCountFormatter.string(fromByteCount: MAX_UPLOAD_SIZE_BYTES, countStyle: .file)
    }
}
```

### Server-Side Limits

#### PHP Configuration
- **Location:** Server Dockerfile at `~/scripts/gighive/ansible/roles/docker/files/apache/Dockerfile`
- **Current Limit:** 6 GB (6,144 MB)
- **Configuration:**
  ```dockerfile
  RUN sed -i 's/upload_max_filesize = .*/upload_max_filesize = 6144M/' /etc/php/${PHP_VERSION}/fpm/php.ini && \
      sed -i 's/post_max_size = .*/post_max_size = 6144M/' /etc/php/${PHP_VERSION}/fpm/php.ini
  ```

#### ModSecurity Configuration
- **Location:** `~/scripts/gighive/ansible/roles/docker/templates/modsecurity.conf.j2`
- **Current Limit:** 6 GB (6,442,450,944 bytes)
- **Purpose:** Web Application Firewall (WAF) request body size limits
- **Configuration:**
  ```apache
  # Global limits
  SecRequestBodyLimit 6442450944
  SecRequestBodyNoFilesLimit 6442450944
  
  # Upload endpoint specific
  <LocationMatch "^/api/uploads\.php$">
      SecRequestBodyLimit 6442450944
  </LocationMatch>
  ```

#### Application Validator
- **Location:** `~/scripts/gighive/ansible/roles/docker/files/apache/webroot/src/Validation/UploadValidator.php`
- **Current Limit:** 6 GB (6,442,450,944 bytes)
- **Purpose:** Application-level validation before processing upload
- **Configuration:**
  ```php
  // Default to 6 GB if not specified; allow override via env UPLOAD_MAX_BYTES
  $env = getenv('UPLOAD_MAX_BYTES');
  $defaultMax = 6 * 1024 * 1024 * 1024; // 6 GB calculated at runtime
  $this->maxBytes = $maxBytes ?? ($env !== false && ctype_digit((string)$env) ? (int)$env : $defaultMax);
  ```
- **Environment Override:** Set `UPLOAD_MAX_BYTES` environment variable to override the default

**Note:** All limits are now aligned at 6 GB across iOS app, ModSecurity, PHP configuration, and application validator.

---

## Environment Configuration

### FILENAME_SEQ_PAD
- **Location:** `~/scripts/gighive/ansible/roles/docker/templates/.env.j2`
- **Used In:** `~/scripts/gighive/ansible/roles/docker/files/apache/webroot/src/Services/UploadService.php` (line 76-77)
- **Purpose:** Controls the zero-padding width for sequence numbers in generated filenames
- **Default:** 5 (if not set or invalid)
- **Valid Range:** 1-9
- **Example:**
  - `FILENAME_SEQ_PAD=5` → `stormpigs20251010_00001_mysong.mp4`
  - `FILENAME_SEQ_PAD=3` → `stormpigs20251010_001_mysong.mp4`

**Code:**
```php
$padWidthEnv = getenv('FILENAME_SEQ_PAD');
$padWidth = is_string($padWidthEnv) && ctype_digit($padWidthEnv) ? max(1, min(9, (int)$padWidthEnv)) : 5;
$seqPadded = str_pad((string)$seq, $padWidth, '0', STR_PAD_LEFT);
```

**Note:** This is a deployment-specific preference and can be configured per environment without code changes.

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

## TODO: Centralize Upload Limit Configuration

**Current State:** Upload limit (6 GB) is defined in 4 separate locations.

**Goal:** Use Ansible variable as single source of truth for server-side systems.

### Step 2: Update Templates to Reference Ansible Variable

After defining `upload_max_bytes` and `upload_max_mb` in Ansible variables (Step 1 - DONE), update these files:

#### 2.1 Dockerfile
**File:** `~/scripts/gighive/ansible/roles/docker/files/apache/Dockerfile`
```dockerfile
# Change from hardcoded values:
RUN sed -i 's/upload_max_filesize = .*/upload_max_filesize = 6144M/' ...

# To Ansible variable:
RUN sed -i 's/upload_max_filesize = .*/upload_max_filesize = {{ upload_max_mb }}M/' ...
```

#### 2.2 ModSecurity Configuration
**File:** `~/scripts/gighive/ansible/roles/docker/templates/modsecurity.conf.j2`
```apache
# Change from hardcoded values:
SecRequestBodyLimit 6442450944

# To Ansible variable:
SecRequestBodyLimit {{ upload_max_bytes }}
```

#### 2.3 UploadValidator.php
**File:** `~/scripts/gighive/ansible/roles/docker/files/apache/webroot/src/Validation/UploadValidator.php`

**Option A:** Pass via environment variable (recommended)
- Keep code as-is (reads from `UPLOAD_MAX_BYTES` env var)
- Restore `UPLOAD_MAX_BYTES` in `.env.j2` using Ansible variable:
  ```bash
  UPLOAD_MAX_BYTES={{ upload_max_bytes }}
  ```

**Option B:** Generate PHP file from template
- Rename to `UploadValidator.php.j2`
- Replace hardcoded value with `{{ upload_max_bytes }}`

#### 2.4 iOS App (Manual Sync Required)
**File:** `GigHive/Sources/App/AppConstants.swift`

⚠️ **This must be manually updated** when Ansible variable changes.

Add comment linking to Ansible variable:
```swift
// IMPORTANT: Keep in sync with Ansible variable 'upload_max_bytes'
// Location: ~/scripts/gighive/ansible/group_vars/all.yml
static let MAX_UPLOAD_SIZE_BYTES: Int64 = 6_442_450_944  // 6 GB
```

### Benefits After Implementation
- ✅ Single source of truth for server configuration
- ✅ Change limit in one place, redeploy to update all systems
- ✅ Self-documenting (variable name explains purpose)
- ⚠️ iOS app still requires manual sync (document clearly)

---

**Architecture is clean, efficient, and maintainable! 🎉**
