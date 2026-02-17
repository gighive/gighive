# PR / Design Doc: iPhone “Delete Uploaded File” (Uploader Capability Tokens)

## Proposed change
Add an iPhone app UX section, **“My uploads on this device”**, on the upload screen so an `admin` or `uploader` user can delete media files they uploaded **from that device**.

The feature relies on the existing server-side uploader delete design:
- `POST /api/uploads/finalize` may return a one-time `delete_token` (only when a new `files` row is created; not for deduped uploads).
- `POST /db/delete_media_files.php` supports uploader token delete with JSON `{ "file_id": <int>, "delete_token": <string> }` and Basic Auth user `uploader` (also supports admin bulk delete).

On iPhone:
- When finalize returns a `delete_token`, the app stores a mapping from `file_id -> delete_token` **persistently** in the app sandbox.
- The upload screen renders a list of stored entries for the current host and exposes a Delete button per entry.

## Rationale
- Guests using the shared `uploader` identity sometimes upload the wrong media and need a self-serve delete.
- Username/password alone cannot authorize “delete my upload” because the uploader identity is shared.
- Capability tokens allow delete authorization without per-guest accounts.
- The server intentionally avoids minting tokens in dedupe cases to prevent deleting canonical/shared assets.

## Constraints / non-goals
- No per-guest account system.
- No token expiry window.
- Only `admin` and `uploader` are allowed to upload media (the UI will be visible/usable for both).
- Entries should be kept **indefinitely** until deleted or manually cleared.
- Tokens must be scoped per server host (base URL) to avoid mixing environments.

## Clarifications / decisions (implementation)
- **Token store scope:** Store tokens scoped by `baseURL.host` only (not `host+username`). Deletion authorization is based on possession of the capability token, not the username, so a single “this device” list per host is sufficient and matches the web localStorage behavior.
- **Finalize success codes:** Treat HTTP `200` and `201` as success for `POST /api/uploads/finalize`. The endpoint is idempotent and may return `200` on a repeated finalize (which will not include `delete_token`).
- **Delete success criteria:** Treat HTTP `200` with `deleted_count == 1` as success for a single-file delete request.

## UX requirements
- The “My uploads on this device” section lives on the iPhone upload screen (`UploadView`).
- List items should be “rich” enough so users remember what they uploaded (event date, org name, event type, label/file name, plus file id).
- Deleting requires a confirmation dialog.
- If deletion fails with **403 Invalid delete token**, prompt the user to either **Remove** the local entry or **Keep** it.
- If an upload finalizes successfully but no `delete_token` is present, show an informational message:
  - “File uploaded successfully, but no delete token was discovered. This upload can't be deleted from the server via the app. Contact contactgighive@gmail.com to request a manual deletion. You will need to submit the following information: event_date, org_name, event_type, label or file name.”

## Data contract (reference)
### Finalize response example (new row)
```json
{
  "id": 18,
  "file_name": "stormpigs20260215_00001_timeaverage.mp4",
  "file_type": "video",
  "mime_type": "video/mp4",
  "size_bytes": 12864030,
  "checksum_sha256": "1548317fe7eba9844d0e45f5019a5035b7cf990e5e2f77ba10ad25cc4cc4e219",
  "session_id": 5,
  "event_date": "2026-02-15",
  "org_name": "StormPigs",
  "event_type": "band",
  "seq": 1,
  "label": "timeaverage",
  "participants": "",
  "keywords": "",
  "duration_seconds": 10,
  "delete_token": "761372258dac267fbee4a35211edda6310a692437af77e75ca8909f71564c2b9"
}
```

### Delete request (uploader)
`POST /db/delete_media_files.php`
```json
{ "file_id": 18, "delete_token": "…" }
```

## Implementation plan (milestone summary)
1. **Keychain token store (per-host):** add a Keychain-backed store for `[file_id -> delete_token + metadata]`.
   - File: `GigHive/Sources/App/UploaderDeleteTokenStore.swift` (new)
2. **Finalize parsing + persistence:** decode finalize success JSON in upload flow; store token entry immediately; show “no token” info message when absent.
   - File: `GigHive/Sources/App/UploadView.swift`
3. **Delete API client:** implement `POST /db/delete_media_files.php` call in the iOS API layer.
   - File: `GigHive/Sources/App/DatabaseAPIClient.swift`
4. **UploadView “My uploads on this device” UI:** render stored entries (rich rows) for current host.
   - File: `GigHive/Sources/App/UploadView.swift`
5. **Delete UX flow:** confirm delete; call API; on success remove entry from Keychain; on 403 prompt Remove/Keep.
   - File: `GigHive/Sources/App/UploadView.swift`
6. **(Optional but recommended) Clear list:** add a “Clear this list” action for the current host.
   - File: `GigHive/Sources/App/UploadView.swift`

## Full implementation plan

### Milestone 1 — Keychain-backed per-host storage
**Objective:** Persist tokens and metadata across app restarts, scoped by `baseURL.host`.

**Design:**
- Create a new Keychain service dedicated to delete-token storage (do not reuse the credential Keychain item).
- Store a single JSON blob per host.

**Proposed types:**
- `struct UploadedFileTokenEntry: Codable, Identifiable`
  - `fileId: Int` (maps to finalize `id`)
  - `deleteToken: String`
  - `createdAt: Date`
  - `eventDate: String`
  - `orgName: String`
  - `eventType: String`
  - `label: String?`
  - `fileName: String?`
  - `fileType: String?`
- `enum UploaderDeleteTokenStore`
  - `load(host: String) -> [UploadedFileTokenEntry]`
  - `upsert(host: String, entry: UploadedFileTokenEntry)`
  - `remove(host: String, fileId: Int)`
  - `clear(host: String)`

**Keychain details:**
- `kSecClassGenericPassword`
- `kSecAttrService`: e.g. `com.gighive.uploader_delete_tokens`
- `kSecAttrAccount`: host

### Milestone 2 — Parse finalize response + persist token
**Objective:** Capture and persist the one-time `delete_token` returned by finalize.

**Where:** `UploadView` upload completion (treat `case 200` and `case 201` as success).

**Steps:**
1. Decode finalize response JSON to a minimal struct.
2. If `delete_token` exists:
   - Build `UploadedFileTokenEntry` with rich metadata from the finalize response.
   - Upsert into Keychain store for `baseURL.host`.
   - Refresh the in-memory list displayed in UploadView.
3. If `delete_token` is missing:
   - Show the agreed informational message (manual deletion instructions).

### Milestone 3 — Delete endpoint client
**Objective:** Provide an async iOS call to delete using `{file_id, delete_token}`.

**Where:** Add method(s) to `DatabaseAPIClient`.

**Request:**
- URL: `baseURL + /db/delete_media_files.php`
- Method: `POST`
- Body: JSON `{file_id, delete_token}`
- Auth: existing Basic Auth header from `session.credentials`

**Response:**
- Treat HTTP 200 as a response with JSON containing `success`, `deleted_count`, `error_count`.
- If 403, surface “Invalid delete token” path.

### Milestone 4 — UploadView UI for “My uploads on this device”
**Objective:** Render and manage stored entries.

**Where:** `UploadView` beneath the upload form.

**UI behaviors:**
- Only show section if there is at least one entry for current host.
- Each entry shows:
  - `event_date`, `org_name`, `event_type`
  - `label` or `file_name`
  - `File ID`
  - Delete button

### Milestone 5 — Delete flow + error handling
**Objective:** End-to-end delete UX.

**Steps:**
1. Tap Delete -> confirmation dialog.
2. If confirmed:
   - Call `DatabaseAPIClient.deleteMediaFile(fileId:deleteToken:)`.
3. On success (`deleted_count == 1`):
   - Remove entry from Keychain store.
   - Refresh UI list.
4. On 403 Invalid delete token:
   - Prompt user: Remove local entry or Keep.

### Milestone 6 — Optional “Clear list” action
**Objective:** Allow manual cleanup since entries are retained indefinitely.

**Behavior:**
- “Clear this list” button in section header.
- Confirmation required.
- Clears Keychain entries for current host.

## Testing / verification (manual)
- Upload a new unique file; confirm finalize returns `delete_token` and app stores it.
- Confirm the new section appears with correct metadata.
- Delete via the app; confirm server deletes and the entry disappears.
- Force 403 (e.g., tamper token) and confirm Remove/Keep prompt works.
- Upload a file that dedupes; confirm finalize has no token and the informational message displays.

## Rollout notes
- Existing uploads without a stored token cannot be deleted from the app.
- Tokens remain valid as long as the server retains the stored `delete_token_hash` and the app remains installed (and Keychain entry remains).

## Notes: Web `db/upload_form.php` parity
The web testing UI at `db/upload_form.php` uses the same uploader-delete capability token design and the same delete endpoint as iOS.

**Shared behavior:**
- Both store `file_id -> delete_token` locally (web: `localStorage`; iOS: Keychain per-host) only when finalize returns `delete_token`.
- Both delete via `POST /db/delete_media_files.php`.
- Both rely on Basic Auth for `/db/*` routes (web: browser session via `fetch(..., credentials: 'same-origin')`; iOS: explicit `Authorization: Basic ...` header).

**Minor differences:**
- Web sends `{ file_ids: [id], file_id: id, delete_token: token }` (includes admin-style `file_ids`); iOS sends `{ file_id: id, delete_token: token }`.
- Web deletes the local token entry on 200 OK and re-renders; iOS removes from Keychain and reloads the list.

**Important consequence:**
- If finalize does not return `delete_token` (often for deduped uploads), neither web nor iOS can delete that upload via capability token.
