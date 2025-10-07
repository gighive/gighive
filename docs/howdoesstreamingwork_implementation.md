
# Direct Streaming Implementation Plan (iPhone 12–safe)

This document describes the concrete steps and design choices for switching GigHive uploads to **direct streaming** (no full file in RAM) while preserving diagnostics and compatibility with iPhone 12 (iOS 14+).

---

## Scope

- **PickerBridges.swift:** Keep the `Result<URL, PickerError>` API. No functional change required.
- **UploadView.swift:** Present size and device diagnostics from metadata only; no full-file reads.
- **UploadFormView.swift:** No structural changes; keep optional size preview using metadata.
- **UploadClient.swift (Primary change):** Build a *multipart/form-data* body **on disk** (incrementally in chunks) and upload it via `URLSession.uploadTask(fromFile:)` with progress, cleanup, and guardrails.

---

## Compatibility (iPhone 12 baseline)

- iOS 14+ APIs used:
  - `PHPickerViewController` (Photos).
  - `FileHandle.read(upToCount:)` for chunked file copying.
  - `URLSession.uploadTask(with:fromFile:)` for streaming from disk.
  - `URLResourceValues` (e.g., `.fileSizeKey`, `.volumeAvailableCapacityForImportantUsageKey`) for size and disk checks.
- No iOS 15+ only APIs required; safe for iPhone 12.

---

## Guardrails & Behavior

1. **No full-file reads** (no `Data(contentsOf:)` for media).
2. **Disk headroom preflight:**
   - Required ≈ `videoSize + 5 MB` (headers + footer, conservative).
   - Safety margin: `+ 200 MB` (configurable).
   - If insufficient, show alert and abort before assembly.
3. **Multipart assembly on disk (Layer 1):**
   - Create `multipart-<UUID>.tmp` in `temporaryDirectory`.
   - Write leading boundary + text fields.
   - Stream video bytes in 1–4 MB chunks into the multipart file.
   - Write closing boundary; close handle; record file size.
4. **Upload from file (Layer 2):**
   - Build `URLRequest` with `Content-Type: multipart/form-data; boundary=...` and Basic Auth.
   - Use `URLSession.uploadTask(with: request, fromFile: tempBodyURL)`.
   - Observe `task.progress` for realtime UI updates.
   - On completion/cancel/failure, delete `tempBodyURL`.
5. **Backgrounding (optional):**
   - If enabled in settings, use background `URLSessionConfiguration.background` for reliability.
6. **Resumability (optional):**
   - PHP multipart cannot resume mid-stream.
   - Keep TUS path for very large files (> 2 GB) if desired; otherwise use streaming for all.

---

## Acceptance Tests

1. Small video (Photos): size visible, upload succeeds, stable memory.
2. Large video (Photos): if not local → picker error; when local → upload streams with progress.
3. Files picker path: same behavior and stability.
4. Low disk: preflight aborts with friendly alert, no crash.
5. Backgrounding (if enabled): upload continues; completion received later.
6. Network drop: graceful failure; (optional TUS path should resume if retained).

---

## Files Touched

- **PickerBridges.swift**: (no change) — optional: add one debug log line for temp path + size.
- **UploadView.swift**: (minor) ensure size & diagnostics are metadata-only; no full reads.
- **UploadFormView.swift**: (none).
- **UploadClient.swift**: (major) implement streaming multipart via temp file with guardrails.

---

## Decision Defaults (can be changed later)

- Background uploads: **Off** by default; can be toggled in settings.
- Resumability for big files: **Streaming for all** (simple); TUS path retained for future switch.
- Free-disk safety margin: **200 MB**.
