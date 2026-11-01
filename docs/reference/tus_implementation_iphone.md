# TUS iPhone Upload Implementation Plan (GigHive)

## Goal
Refactor the GigHive iPhone app to upload media using the server’s TUS implementation to bypass Cloudflare’s 100MB per-request limit, while keeping UI changes minimal (ideally none) and performing a hard cutover (no legacy multipart upload path).

## Server endpoints (contract)
- TUS endpoint (tusd via Apache):
  - `POST/PATCH/HEAD {baseURL}/files/…`
- Finalize endpoint (PHP):
  - `POST {baseURL}/api/uploads/finalize`

## Required auth
- **Basic Auth** must be sent for:
  - TUS requests (`POST`, `PATCH`, `HEAD`)
  - Finalize request (`POST /api/uploads/finalize`)

## Base URL derivation (no hardcoding)
The app’s base URL is user-selectable (from the Settings/UI). All endpoints must be derived from the selected base URL.

- `tusBaseURL = authSession.baseURL.appendingPathComponent("files/")`
- `finalizeURL = authSession.baseURL.appendingPathComponent("api/uploads/finalize")`

## Client behavior (high-level)
1. **Create + upload** media via TUS (chunked).
2. **Extract `upload_id`** from the TUS upload URL (typically last path component of `Location`).
3. **Finalize** the completed upload via `POST /api/uploads/finalize`.
4. Return the finalize response as the upload result.

## Progress semantics (no UI changes)
- During TUS upload: report `(bytesSent, totalBytes)`.
- During finalize: keep progress pinned at **100%** while finalize runs.

## Finalize request payload
Send JSON body containing:
- Required:
  - `upload_id` (string)
- Recommended (send full metadata from `UploadPayload`, especially because the server requires `label`):
  - `event_date` (YYYY-MM-DD)
  - `org_name`
  - `event_type`
  - `label`
  - `participants`
  - `keywords`
  - `location`
  - `rating`
  - `notes`

## iOS code changes (file-by-file)

### 1) `GigHive/project.yml`
- Enable the SwiftPM dependency for **TUSKit** (currently commented out).
- Add/uncomment TUSKit in the app target’s dependencies.
- Regenerate the Xcode project via XcodeGen.

### 2) `GigHive/Sources/App/TUSUploadClient.swift` (new)
Implement a dedicated TUS transport client (using TUSKit):
- Configure the TUS endpoint URL derived from `AuthSession.baseURL`.
- Use chunked uploads (e.g., 5MB chunk size).
- Ensure **Basic Auth** is applied to all TUS requests.
- Respect `allowInsecureTLS` for dev/test environments.
- Provide progress callback: `(sentBytes, totalBytes)`.
- Return the final upload URL (or extracted `upload_id`).

### 3) `GigHive/Sources/App/UploadClient.swift`
Hard cutover to TUS while keeping the existing UploadView call site unchanged:
- Replace implementation of `uploadWithMultipartInputStream(...)`:
  1. Perform TUS upload using `TUSUploadClient`.
  2. Extract `upload_id`.
  3. Call `POST /api/uploads/finalize` with `upload_id` + metadata.
  4. Return the finalize response.
- Update `cancelCurrentUpload()` to cancel the active TUS upload.

### 4) `GigHive/Sources/App/NetworkProgressUploadClient.swift`
- Leave in place initially, but it should become **unused** after the cutover.
- Remove later once TUS path is stable and verified.

## Testing checklist
- Upload <100MB (should still use TUS path).
- Upload >100MB (should no longer hit Cloudflare 413).
- Cancel mid-upload (ensure TUS upload stops and no further PATCH requests occur).
- Verify finalize response is 201 and media appears in DB and is playable.
- (Optional) Verify finalize idempotency doesn’t cause duplicate DB records.
