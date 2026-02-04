# TUS Protocol Implementation Guide

## Overview

This document outlines adding TUS (Tus Resumable Upload) protocol support to the GigHive iOS client for bypassing Cloudflare's 100MB upload limit. 

## Rationale (Condensed)

### Recommendation
Implement TUS support for Cloudflare compatibility, using a dedicated TUS endpoint (recommended: `tusd` behind Apache) and keeping the existing upload path as a fallback.

### Why this matters
- Typical gig/event videos are frequently **>100MB**.
- Cloudflare Free plan enforces a **100MB per-request body limit**, making remote uploads of typical content fail with HTTP 413.
- TUS resolves this by uploading via **multiple requests** (resumable), keeping each request under the limit.

### Why it’s feasible in this codebase
- `MultipartInputStream` already solves memory usage for large files.
- Upload progress + cancellation infrastructure already exists.
- The change is mainly swapping the upload transport to TUS (multiple requests) while keeping the existing UI and upload abstractions.

### Trade-offs / caveats
- Requires a **TUS server endpoint** (the existing `/api/uploads.php` cannot speak TUS).
- Adds operational surface area (a `tusd` service and temp storage/cleanup).

### Note on Cloudflare Tunnel environments
If an environment’s origin is only reachable via Cloudflare Tunnel, a DNS-only upload subdomain does not bypass Cloudflare limits unless the origin is also publicly reachable. In these environments, TUS (or direct-to-object-storage uploads) is the practical approach.

### ✅ Already Implemented
- **MultipartInputStream**: Custom `InputStream` that streams files without loading into memory ✅
- **Direct Streaming**: Files of any size upload successfully (tested up to 4.7GB) ✅
- **Memory Efficient**: Only 4MB chunks in memory at a time ✅
- **Progress Tracking**: Real network progress via `URLSessionTaskDelegate` ✅
- **Project Structure**: Clean XcodeGen-based project with `project.yml` configuration ✅

## Updated Context (January 2026)

### Cloudflare Tunnel Constraint
The GigHive origin in some environments is only reachable via Cloudflare Tunnel (for example, routing `lab.gighive.app` to a private IP). In that setup:

- A DNS-only "upload" subdomain cannot bypass Cloudflare's 100MB limit if it still resolves to a Cloudflare Tunnel hostname.
- To keep Cloudflare Free plan and Tunnel-only origins, the practical options are:
  - TUS (multiple HTTP requests, each < 100MB)
  - Direct-to-object-storage uploads (signed URLs) as a future phase

### Important: TUS Requires a Separate Server Endpoint
The existing `/api/uploads.php` endpoint expects a single `multipart/form-data` request (PHP `$_FILES`). TUS uses multiple requests (typically `POST` then multiple `PATCH` requests with `application/offset+octet-stream`). Therefore:

- TUS cannot be implemented by pointing the client at `/api/uploads.php`.
- Add a dedicated TUS endpoint (public: `/tus` via `tusd`) and keep `/api/uploads.php` available temporarily as a legacy fallback for testing/rollback.

**Current Behavior (Before TUS Cutover):**
- ✅ Files <100MB: Work through Cloudflare
- ❌ Files >100MB: Cloudflare returns HTTP 413 (tested: 177MB, 241MB, 350MB all fail)
- ✅ All files: Work on local network (no Cloudflare)

**See:** `UPLOAD_OPTIONS.md` for detailed analysis

## Integration Points Validation

### Existing Flow Compatibility
1. **UploadPayload**: ✅ No changes needed - same structure
2. **Progress Callbacks**: ✅ Same signature `(Int64, Int64) -> Void`
3. **Error Handling**: ✅ Same async/await pattern
4. **Authentication**: ✅ Basic auth preserved
5. **Server Endpoint**: ⚠️ Requires a dedicated TUS endpoint (not `/api/uploads.php`)
6. **Finalize Step**: ⚠️ Required - after TUS completes, the client must call a finalize endpoint so the server can move/register the file into the existing `/audio`/`/video` locations and write DB metadata

## Server Plan (Recommended): `tusd` Sidecar Container

### Why `tusd`
`tusd` is the reference TUS server implementation and is available as a Docker image. Running it as a sidecar keeps the upload logic out of the PHP endpoint and provides well-tested resumable upload semantics.

### Proposed Routing
- External: `https://<env>.gighive.app/files` (through Cloudflare, same hostname)
- Public URL path is `/files` (Apache), which reverse-proxies to `tusd`'s internal `/files/` handler
- `tusd` stores partial uploads in a mounted directory

#### Routing invariant (important)
- Public client-facing path: `/files/`
- tusd upload creation/patch path: `/files/`

If you see `200 OK` with a body like "Welcome to tusd" when creating an upload, you are likely hitting tusd's welcome endpoint instead of its TUS handler (`/files/`).

#### Ansible variables required for TUS
The Ansible playbooks (including `post_build_checks`) assume the TUS-related variables are defined for the inventory/group being deployed (for example under `ansible/inventories/group_vars/<group>/<group>.yml`). Key variables include:

- `tusd_port`
- `tusd_base_path` (expected: `/files`)
- `tus_public_path` (expected: `/files`)
- `gighive_scheme`, `gighive_host`, `gighive_base_url`
- `gighive_validate_certs`
- `gighive_hostname_for_host_header` (optional, but needed when accessing by IP and Apache vhost routing depends on `Host`)

### ModSecurity Note
Current ModSecurity configuration enforces `multipart/form-data` for `/api/uploads.php` and `/api/media-files`. TUS requests are not multipart. To avoid conflicts:

- Do not use `/api/uploads.php` for TUS traffic.
- Expose TUS under a separate path (e.g. `/files`) and ensure ModSecurity rules do not block `PATCH` or the TUS content type on that path.

### Operational verification (quick checks)

#### Direct tusd (from inside the Apache container)
- `GET /files/` should return `405` with `Allow: POST`.
- `POST /files/` should return `201 Created` with a `Location` header.

#### Through Apache (external URL path)
- `OPTIONS /files/` without auth should return `401/403`.
- Authenticated `POST /files/` should return `201 Created` with a `Location` header.

### Post-build validation (Ansible)

The Ansible role `post_build_checks` includes a TUS smoke test to validate the end-to-end upload pipeline, including reverse proxying, authentication, the tusd hook, and the application-side finalize flow.

- Implementation:
  - `ansible/roles/post_build_checks/tasks/main.yml`

The test exercises these behaviors:

- `OPTIONS /files/` (unauthenticated)
  - Expected: `401` or `403`
- `POST /files/` (authenticated)
  - Expected: `201 Created`
  - Requires `Location` header for the new upload resource
- `PATCH <Location>` (authenticated)
  - Uploads a small payload
  - Expected: `204 No Content`
- `HEAD <Location>`
  - Verifies the upload offset equals the payload length
- Wait for tusd post-finish hook output
  - Ensures the hook JSON file exists before finalizing
- `POST /api/uploads/finalize` (authenticated)
  - Expected: `201 Created`
- Finalize again (idempotency regression)
  - Calls finalize a second time and asserts it returns the same `id` and `file_name`

This is intended to catch regressions where:

- Apache proxying is misrouted (hitting tusd welcome endpoint vs TUS handler)
- `PATCH` / TUS headers are blocked
- the post-finish hook does not fire or cannot write to its shared volume
- finalize is not idempotent for the same `upload_id`

### Server-side artifact layout (/var/www/private)

The tusd container and Apache container share volumes that are mounted into the Apache container under `/var/www/private`.

- Staging upload data (tusd write target):
  - `/var/www/private/tus-data/<upload_id>`
- Hook output (written by tusd post-finish hook):
  - `/var/www/private/tus-hooks/uploads/<upload_id>.json`
- Finalize marker (written by the app after successful finalize):
  - `/var/www/private/tus-hooks/finalized/<upload_id>.json`

Finalize reads the hook JSON and the tusd staging upload file, then writes the final served media and DB metadata via the existing upload handling logic.

Final served media locations:

- Audio:
  - `/var/www/html/audio/<sha256>.<ext>`
- Video:
  - `/var/www/html/video/<sha256>.<ext>`
  - Thumbnails: `/var/www/html/video/thumbnails/<sha256>.png`

### Optional cleanup (avoid DB/filesystem pollution)

The TUS smoke test can optionally clean up the validation media record and staging artifacts.

- Variable:
  - `tus_cleanup_after_check`
    - Default: `true`
    - Set to `false` to keep artifacts for debugging

Cleanup behavior:

- Verifies the finalize response looks like the smoke-test record (label `TUS_VALIDATE` and file name contains `tus-validate`).
- Deletes the created DB row and served file via:
  - `POST /db/delete_media_files.php` (admin-only)
- Removes staging artifacts for the `upload_id`:
  - `/var/www/private/tus-data/<upload_id>`
  - `/var/www/private/tus-hooks/uploads/<upload_id>.json`
  - `/var/www/private/tus-hooks/finalized/<upload_id>.json`

### UI Integration
- **Progress Display**: ✅ Existing 10% bucket system works perfectly
- **Cancel Functionality**: ✅ Task cancellation preserved
- **Error Messages**: ✅ Same status code handling
- **Success Flow**: ✅ Same database link generation

## Implementation Checklist

### High Priority Tasks
- [ ] Refactor legacy PHP upload form to use TUS-only: `ansible/roles/docker/files/apache/webroot/db/upload_form.php`
- [ ] Refactor legacy PHP admin upload form to use TUS-only: `ansible/roles/docker/files/apache/webroot/db/upload_form_admin.php`
- [x] Add TUSKit dependency to project.yml and regenerate Xcode project
- [x] Create TUSUploadClient.swift wrapper (~177 lines) - **COMPLETED**
- [x] Add `uploadWithTUS` method to UploadClient.swift (~30 lines) - **COMPLETED**
- [ ] Add a `tusd` service to `docker-compose.yml.j2` (`tusproject/tusd`) with a persistent volume for upload storage
- [ ] Add Apache reverse proxy for `/tus` to `tusd` (e.g. `http://tusd:1080/files/`)
- [ ] Protect `/tus` with Basic Auth (same users as uploads)
- [ ] Ensure ModSecurity does not block `PATCH` and TUS content-types on `/tus` (keep multipart enforcement on `/api/uploads.php`)
- [ ] Configure `tusd` hooks to record a server-side mapping from `upload_id` to the completed upload’s storage location (Option A)
- [ ] Implement server-side finalize flow for TUS uploads
- [ ] Test uploads (small + large) through Cloudflare Tunnel (should succeed without 413), verify resume/cancel behavior, and confirm final files land in the same `/audio`/`/video` locations
- [ ] Test TUS uploads work for all file sizes
### Medium Priority Tasks
- [ ] Verify memory usage stays low during large file uploads
- [ ] Verify progress tracking works correctly for both methods

## Success Metrics for Week 1

### Pass/Fail Criteria
- Uploads through Cloudflare Tunnel succeed for files >100MB (no HTTP 413)
- TUS uploads can be resumed after interruption and complete successfully
- Finalize is idempotent for a given `upload_id`
- Finalize moves the completed file into the expected `/audio` or `/video` destination and DB metadata is written
- iOS upload progress is monotonic and reaches 100%

## Technical Details

### File Size Threshold Logic

```swift
// Hard cutover: use TUS protocol for all files
return try await uploadWithTUS(payload: payload, progress: progress)
```

### Key Differences

| Method | HTTP Requests | Use Case | Cloudflare Compatible |
|--------|---------------|----------|----------------------|
| `uploadWithTUS()` | Multiple PATCH | All uploads | ✅ Yes |
| `uploadWithMultipartInputStream()` | 1 POST | Legacy fallback only | ✅ Yes |
| Old `upload()` method | 1 POST (loads to memory) | Deprecated | ⚠️ |

### TUS Configuration
- **Chunk Size**: 5MB (optimal for 4GB videos = 800 chunks)
- **Retry Count**: 3 per chunk
- **Request Timeout**: 5 minutes per chunk
- **Memory Usage**: ~10MB peak (5MB chunk + overhead)

## Ready for Implementation (When Needed)

### Next Steps
- Complete the remaining items in the Implementation Checklist (server + finalize)
- Run the Success Metrics pass/fail checks through Cloudflare Tunnel
- Once stable, remove the legacy `/api/uploads.php` fallback

---

**Related Documentation:**
- `UPLOAD_OPTIONS.md` - Cloudflare 100MB limit analysis