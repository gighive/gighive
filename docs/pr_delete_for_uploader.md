# Feature request: Uploader delete capability (token-based)

## Summary
Enable a shared Basic Auth identity (`uploader`) to delete **only** the media files that uploader created, without requiring per-guest accounts.

This is achieved via a **capability token** (`delete_token`) minted at upload time and stored on the client. A hashed form is stored server-side (`delete_token_hash`) and is required to authorize deletion.

## Background / Problem
- GigHive supports a shared uploader identity intended for guests (e.g. wedding guests) to upload videos.
- Guests sometimes upload the wrong file and need a way to delete their own upload.
- Because the identity is shared, username/password alone cannot be used to authorize “delete my own upload”.

## Constraints / Current behavior
### Global deduplication by checksum
The system enforces global deduplication by `checksum_sha256`:
- MySQL schema has `UNIQUE (checksum_sha256)` on `files`.
- Server upload code rejects duplicate uploads with HTTP 409 to prevent duplicates.

## Goals
- Allow the shared `uploader` identity to delete an upload **only if the client possesses a previously-issued capability token**.
- Ensure deletion can be performed later (e.g. days later), as long as the guest still has the app installed.
- Keep the implementation minimal and compatible with existing admin deletion tooling.

## Non-goals
- No per-guest account system.
- No token expiry window.
- No attempt to track “ownership” by uploader beyond possession of the capability.

## Key decisions
### D1: Delete semantics under deduplication
**Chosen:** Option A
- Only mint a deletion capability when a **new `files` row** is created.
- If the upload dedupes (existing `file_id` reused due to matching checksum), do **not** mint/return a token.

**Rationale:** Prevents a later guest from deleting an existing canonical asset that may be referenced elsewhere.

### D2: One-time return of capability token
**Chosen:** R1
- Return `delete_token` **only on the first successful finalize response**.
- Do **not** store `delete_token` in the on-disk finalize marker JSON.

**Rationale:** Minimizes accidental leakage and enforces that clients store the token immediately.

### D3: Where to implement delete
**Chosen:** Reuse existing endpoint
- Keep using `db/delete_media_files.php`.
- Extend it to support a new “uploader token delete” mode, while preserving the current admin bulk delete behavior.

### D4: Server-side storage
**Chosen:** Store only a hash
- Add `files.delete_token_hash` containing `sha256(delete_token)`.

**Rationale:** Server can verify authorization without storing the raw token.

## Local storage definitions (client-side)
### Web uploader (browser)
“LocalStorage” means browser `window.localStorage`:
- Persistent key/value storage scoped to the site origin.
- Survives page reload and browser restart.
- Can be unavailable or non-persistent in some private/incognito modes.

Use in this feature:
- Store `file_id -> delete_token` mapping immediately after finalize returns a token.
- Implement a persistence gate: if localStorage cannot persist reliably, deny upload to avoid issuing a one-time token that cannot be saved.

### iPhone app
There is no browser localStorage.
“Local storage” means app persistent storage inside the iOS app sandbox (implementation choice):
- UserDefaults, Keychain, or a small local database/file.

Use in this feature:
- Store `file_id -> delete_token` mapping so the guest can delete later as long as the app remains installed.

## API / Data flow overview
### Upload
1. Client uploads via tus.
2. Client calls `POST /api/uploads/finalize`.
3. Server processes the file, computes checksum, and inserts into `files` (or dedupes).
4. If a new `files` row is created:
   - Mint `delete_token`.
   - Store `delete_token_hash` on the `files` row.
   - Return `delete_token` in the finalize response.
5. If deduped:
   - Return normal finalize response without `delete_token`.

### Delete
- Client calls `db/delete_media_files.php` with Basic Auth `uploader` and body containing `{file_id, delete_token}`.
- Server hashes provided token and compares to stored `files.delete_token_hash`.
- If valid, perform deletion.

## Request/response shapes
### Finalize success (new row)
Response includes (existing fields plus):
- `delete_token`: string (hex)

### Finalize success (deduped)
Response:
- No `delete_token`.
- (Optional future nicety) include a boolean like `deduped: true`.

### Delete request (uploader)
`POST db/delete_media_files.php`
```json
{
  "file_id": 123,
  "delete_token": "<hex token>"
}
```

### Delete request (admin)
Unchanged existing behavior (bulk deletion).

## Exact file-by-file implementation plan
### High-level implementation plan (steps 1-7)
1. Add `files.delete_token_hash CHAR(64) NULL` to the MySQL schema (`create_music_db.sql`).
2. Add `FileRepository` helpers to read/write `delete_token_hash` (`getDeleteTokenHashById()` and `setDeleteTokenHashIfNull()`).
3. Update `UploadService::handleUpload()` to mint `delete_token` only when a new `files` row is created, store `sha256(token)` in `delete_token_hash`, and include `delete_token` in the response.
4. Update `UploadService::finalizeTusUpload()` to enforce R1 by stripping `delete_token` from the persisted finalize marker JSON while still returning it in the first HTTP response.
5. Extend `db/delete_media_files.php` to support uploader token-delete mode `{file_id, delete_token}` (verify against `delete_token_hash`) while keeping admin bulk delete unchanged.
6. Extend the existing TUS smoke test in `ansible/roles/post_build_checks/tasks/main.yml` to validate R1 token behavior and uploader delete success + wrong-token 403.
7. Update `db/upload_form.php` to gate uploads on localStorage persistence, store tokens under a single key, and show a conditional “My uploads on this device” section below the form and above debug/log output.

### 1) DB schema
File:
- `ansible/roles/docker/files/mysql/externalConfigs/create_music_db.sql`

Change:
- Add nullable column to `files` table:
  - `delete_token_hash CHAR(64) NULL`

### 2) Upload finalize + token minting
File:
- `ansible/roles/docker/files/apache/webroot/src/Services/UploadService.php`

Changes:
- In the “new insert” path:
  - Generate token using `random_bytes(32)` and hex encoding.
  - Compute hash via `hash('sha256', $token)`.
  - Update `files.delete_token_hash` for the created `file_id` (guarded by `IS NULL`).
  - Add `delete_token` to the response.

- In the duplicate-checksum reuse path:
  - Do not create/store/return a token.

- In `finalizeTusUpload()` finalize marker behavior (R1):
  - Write marker JSON without `delete_token`.
  - Return `delete_token` only in the immediate HTTP response for that first finalize.

### 3) Repository helpers (optional)
File:
- `ansible/roles/docker/files/apache/webroot/src/Repositories/FileRepository.php`

Possible additions:
- `setDeleteTokenHashIfNull(fileId, hash)`
- `getDeleteTokenHashById(fileId)`

### 4) Delete endpoint extension
File:
- `ansible/roles/docker/files/apache/webroot/db/delete_media_files.php`

Changes:
- Preserve admin mode unchanged.
- Add uploader mode:
  - Accept `{file_id, delete_token}`.
  - Validate and cast `file_id` to int.
  - Fetch `delete_token_hash` for the row.
  - Compare with `hash_equals()`.
  - If valid, perform same deletion logic.

### 5) Web test UI
File:
- `ansible/roles/docker/files/apache/webroot/db/upload_form.php`

Changes:
- Add localStorage persistence gate (deny upload if not persistent).
- Store returned `delete_token` in localStorage keyed by `file_id`.
- Add minimal UI section listing stored uploads and allowing delete via `db/delete_media_files.php`.
  - Only render/show the “My uploads on this device” section if at least 1 token entry exists in localStorage.
  - Place the “My uploads on this device” section below the upload form and above the debug/logging area.
  - Store tokens under a single localStorage key (e.g. `uploader_delete_tokens_v1`) as JSON, and re-render the section after successful finalize and after successful delete.

### 6) Post-build checks
File:
- `ansible/roles/post_build_checks/tasks/main.yml`

Changes (extend existing TUS block):
- After the first finalize call (`tus_finalize_1`):
  - Assert `tus_finalize_1.json.delete_token` is present.
  - Test A: call `POST /db/delete_media_files.php` as Basic Auth `uploader` with `{file_id, delete_token}` and assert success.
  - Test B: call the same endpoint with a bogus token and assert a 403 response.
- Update the existing finalize idempotency assertion to additionally assert that the second finalize response does not include `delete_token` (R1).

## Security / SonarQube considerations
Expected SonarQube scan risk is low if we follow these patterns:
- Token generation: `random_bytes()` (avoid `rand()`, `mt_rand()`, `uniqid()`).
- Hash comparison: `hash_equals()` (avoid naive equality).
- SQL: prepared statements only; do not interpolate user input.
- Filesystem: do not accept any user-provided filesystem paths.
- Logging: do not log raw `delete_token`.

## Testing / Verification
- Upload a new unique file; finalize response should include `delete_token` exactly once.
- Repeat finalize for same `upload_id`; response should not include `delete_token`.
- Upload same file again (same checksum); finalize should not include `delete_token`.
- Delete as uploader with correct token → success.
- Delete as uploader with wrong token → 403.
- Delete as uploader when `delete_token_hash` is NULL (legacy file) → 403.
- Admin bulk delete → unchanged.

## Rollout notes
- Existing files will have `delete_token_hash = NULL` and cannot be deleted by uploader token mode.
- This is acceptable for the initial feature; tokens apply to uploads after the feature is deployed.

## Staging/Prod migration steps
1. Confirm a recent DB backup exists for the target environment.
2. Apply an in-place schema change:
   - `ALTER TABLE files ADD COLUMN delete_token_hash CHAR(64) NULL;`
3. Deploy the application changes.
4. Run/verify `ansible/roles/post_build_checks/tasks/main.yml` (TUS smoke test + uploader delete tests) against the environment.
