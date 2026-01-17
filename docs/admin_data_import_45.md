# Admin UI: Data Import via Sections 4 and 5

This note documents how **Section 4** and **Section 5** on `admin.php` work for importing media metadata into the GigHive database.

Source file:
- `ansible/roles/docker/files/apache/webroot/admin.php`

Related endpoints:
- `ansible/roles/docker/files/apache/webroot/import_manifest_reload.php`
- `ansible/roles/docker/files/apache/webroot/import_manifest_add.php`

## High-level idea

Both sections:
- Let you pick a **local folder** in the browser.
- Filter to “supported” media files.
- Compute **SHA-256** checksums **in the browser**.
- POST a JSON “manifest” (metadata + checksum) to the server.
- The server validates the request and imports metadata into MySQL.

The key difference:
- **Section 4**: destructive **reload** (truncates/rebuilds media tables).
- **Section 5**: non-destructive **add** (keeps existing media tables; inserts new + skips duplicates).

## What the “manifest” is

In both Section 4 and Section 5, the client builds a JSON payload containing:
- `org_name` (hard-coded in the JS payload as `'default'`)
- `event_type` (hard-coded as `'band'`)
- `items`: array of objects, one per media file

Each `items[]` entry includes:
- `file_name`
- `source_relpath`
- `file_type` (e.g. `audio` or `video`)
- `event_date` (derived from file naming/structure logic)
- `size_bytes`
- `checksum_sha256` (SHA-256 hex)

## Section 4: Folder scan + refresh DB (destructive)

UI elements:
- Folder input: `#media_folder` (`webkitdirectory`, `multiple`)
- Buttons:
  - “Scan Folder and Update DB”
  - “Stop hashing and reload DB with hashed files”
  - “Clear cached hashes for this folder”

Client-side flow (`confirmScanFolderImport()`):
1. **Collect files** from the selected folder.
2. **Filter** files:
   - Excludes zero-byte files.
   - Keeps only files with an extension in `MEDIA_EXTS`.
   - Requires a recognized `file_type` via `inferFileTypeFromName(...)`.
3. **Hash** each supported file:
   - Uses SHA-256.
   - Shows progress, elapsed time, and ETA.
   - Supports “Stop” via `AbortController`.
4. Builds the `items[]` manifest entries.
5. POSTs JSON to:
   - `import_manifest_reload.php`

Server-side effect (`import_manifest_reload.php`):
- Validates the JSON body.
- Uses a lock to prevent concurrent imports.
- **Truncates media tables** (sessions/songs/files/etc.).
- Seeds `genres` and `styles`.
- Upserts sessions.
- Inserts files, deduping by `checksum_sha256`.
- Creates/links song labels based on file name.

## Section 5: Folder scan + add to DB (non-destructive)

UI elements:
- Folder input: `#media_folder_add` (`webkitdirectory`, `multiple`)
- Buttons:
  - “Scan Folder and Add to DB”
  - “Stop hashing and import hashed”
  - “Clear cached hashes for this folder”

Client-side flow:
- Same shape as Section 4:
  - collect files
  - filter supported files
  - hash (SHA-256) client-side
  - POST manifest
- POSTs JSON to:
  - `import_manifest_add.php`

Server-side effect (`import_manifest_add.php`):
- Validates the JSON body.
- Uses the same lock to prevent concurrent imports.
- **Does not truncate tables**.
- Ensures sessions exist.
- Inserts files and handles duplicates (based on DB constraints / checksum checks).
- Creates/links song labels based on file name.

Response reporting:
- The UI renders an “Add-to-DB summary” using fields like:
  - `inserted_count`
  - `duplicate_count`
  - optional `duplicates` sample list

## Where the manifest is stored (directory/path)

The manifest is **not written to disk on the server** by Section 4 or Section 5.

- The client sends the manifest in the HTTP request body.
- The server reads it with:
  - `file_get_contents('php://input')`

The only server-side file created/used by these endpoints is a **lock file**:
- `/var/www/private/import_database.lock`

That lock file is not the manifest; it only prevents two imports from running at the same time.

## Hash cache behavior

The hashing cache referenced by the UI (“Clear cached hashes for this folder”) is implemented client-side in the browser via helper functions (e.g. `getCachedSha256`, `putCachedSha256`, `clearCachedSha256ForFolder`). This is separate from any server filesystem path.

## Relationship to `upload_media_by_hash.py`

Sections 4/5 primarily import **metadata + checksums** into MySQL.

The UI then points you at:
- `ansible/roles/docker/files/apache/webroot/tools/upload_media_by_hash.py`

That script is used to upload/copy the **actual media files** to the server keyed by hash (so the DB rows and server-side file storage can line up).
