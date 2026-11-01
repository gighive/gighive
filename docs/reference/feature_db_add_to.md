# Add-to-Database Feature (Section 5)

## Goal

Provide a **non-destructive** (“add only”) way to scan a folder, hash files, and **add** new media rows to the DB (no truncation), with a clear inserted vs skipped report.

This feature is designed to support a two-step workflow:

1) Build the DB inventory (Section 5)
2) Upload the actual bytes to the GigHive host (controller-side uploader)

## Why it works this way

- The DB stores a file’s identity as `files.checksum_sha256`.
- The DB stores provenance as `files.source_relpath` (a **relative** path captured by the browser folder picker).
- Uploading bytes later requires a base folder on the controller machine (`--source-root`) so the uploader can resolve:
  - `--source-root` + `/` + `source_relpath`

See `docs/uploadMediaByHash.md` for the upload step.

## Recommended workflow (most users): one stable root

Pick one stable top-level folder that contains all your media (e.g. `~/videos` or `/mnt/scottsfiles/videos`) and keep using it.

1) In **admin.php Section 5**, choose your stable root (or a subfolder within it) and run **Scan Folder and Add to DB**.
2) After hashing completes, run the controller uploader with:
  - `--source-root` pointing at that same stable top-level folder.
3) Repeat as needed.

## Piecemeal scans (power users)

You can scan smaller subfolders over time, but the easiest way to avoid confusion is still:

- Always upload with `--source-root` pointing at the stable top-level root that contains all scanned subfolders.

If you upload from a subfolder while the DB rows you selected refer to other subtrees, the uploader will report `MISSING_SRC`.

## Context (current state)

The existing admin import mechanisms are destructive:

- **Section 3A / 3B**: upload CSV(s) and reload database (truncates media tables).
- **Section 4**: browser folder scan → generates `database.csv` → posts to `import_database.php` (also truncates media tables).

These flows rely on preprocessing scripts that generate explicit numeric IDs (`session_id`, `song_id`, `file_id`) and then `LOAD DATA ...` into empty tables.

## Feature overview

### New admin UI: Section 5

Add **Section 5** to `admin.php`:

- Uses a **folder picker** (`webkitdirectory`) like Section 4.
- Scans the selected folder for supported media file extensions.
- Performs a **non-destructive** “add to DB” operation.
- Displays a summary report:
  - Inserted count
  - Duplicate/skipped count
  - Sample list of duplicates skipped

### Global dedupe policy

- Dedupe scope is **global**.
- Dedupe key is **`files.checksum_sha256`**.
- Duplicates are dropped and reported.

`source_relpath` is treated as **metadata only** (provenance/traceability), not as a dedupe key.

## Data model assumptions

The current schema supports storing:

- Immutable identity: `files.checksum_sha256` (SHA-256), `files.file_id` (DB PK)
- Provenance: `files.source_relpath` (optional)
- Type: `files.file_type` (`audio` / `video`)

### Required schema change

Add a unique constraint to enforce global dedupe:

- Add `UNIQUE (checksum_sha256)` on `files`

Note: MySQL permits multiple `NULL` values in a UNIQUE index, so rows without a checksum are still allowed.

## Implementation plan (proposed)

### 1) Schema update

- File: `ansible/roles/docker/files/mysql/externalConfigs/create_music_db.sql`
- Change: add a unique index on `files.checksum_sha256`.

### 2) Loader consistency (provenance)

Ensure `source_relpath` is loaded where available:

- **Runtime import** (`import_database.php`) already maps `source_relpath` from `files.csv`.
- **MySQL init loader** (`load_and_transform.sql`) currently does not load `source_relpath` for the `files` table; update the `LOAD DATA INFILE ... INTO TABLE files (...)` column list to include it.

### 3) Admin UI changes (Section 5)

- File: `ansible/roles/docker/files/apache/webroot/admin.php`
- Add a new section with:
  - Folder picker input
  - Preview panel
  - Status / reporting panel
  - “Scan Folder and ADD to DB” button
 - Notes / UX requirements:
   - Hashing is **mandatory** to support idempotency and the long-term viability of the media library (`UNIQUE(checksum_sha256)`).
   - Hashing may take time for large folders (especially video); the UI must show a progress meter.
   - Chrome/Chromium should be the recommended browser.

### 4) New admin endpoint (non-destructive)

Add a new endpoint (name TBD, e.g. `import_manifest_add.php`) that:

- Is admin-only (same access gate as other admin endpoints)
- Uses a lock file (same pattern as `import_database.php`) to prevent concurrent imports
- Accepts a request representing scanned media items
- Inserts only *new* items, deduping globally via `checksum_sha256`
- Returns a report including duplicates skipped
 - Notes:
   - The endpoint expects hashes from the client so it can reliably dedupe and produce duplicate-skip reporting.

### 5) Label derivation (media-library mode)

For media-library mode, the label/title must be derived from filename (no user input).

- Derive label from basename (no extension)
- This is used to create/link the existing “song” entities for consistency with current schema.

**Note:** GigHive has a dual nature (live jam session uploads vs. media library). Renaming “song” to “media” is a backlog item and is out-of-scope for this feature.

## Notes / constraints

### Hashing and uploads

Hashing (`checksum_sha256`) is required to enforce reliable global dedupe.

For Section 5, the current decision is:

- The browser will compute **SHA-256 during the folder scan** (using WebCrypto) and include it in the request payload to the server.
- This enables the database to enforce `UNIQUE (checksum_sha256)` immediately for folder-scan based ingestion.

### Where `file_type` is currently determined

For the “scan folder + hash + import manifest” workflow, `files.file_type` is derived client-side in `admin.php` (JavaScript) and inserted by the manifest endpoint:

- `ansible/roles/docker/files/apache/webroot/admin.php`
  - `inferFileTypeFromName(name)` infers `file_type` (`audio` / `video`) from the filename extension using allowlists (e.g. `AUDIO_EXTS`, `VIDEO_EXTS`).
  - The browser builds an `items[]` manifest containing `file_name`, `source_relpath`, `file_type`, `event_date`, `size_bytes`, and `checksum_sha256`.
- `ansible/roles/docker/files/apache/webroot/import_manifest_add.php`
  - Validates `file_type` is one of `audio` / `video`.
  - Inserts rows into `files` using the provided `file_type` and `checksum_sha256`.

A separate upload flow also exists:

- `ansible/roles/docker/files/apache/webroot/src/Services/UploadService.php`
  - Infers `file_type` from MIME type and/or extension for direct uploads.

### Media file destinations (bind mounts)

The Apache container’s media directories (`media_search_dir_audio`, `media_search_dir_video`) are backed by host bind mounts on the VM:

- Host paths:
  - `/home/{{ ansible_user }}/audio`
  - `/home/{{ ansible_user }}/video`
- Container paths:
  - `{{ media_search_dir_audio }}` (e.g. `/var/www/html/audio`)
  - `{{ media_search_dir_video }}` (e.g. `/var/www/html/video`)

See: `ansible/roles/docker/templates/docker-compose.yml.j2` volume bindings.

User-facing expectations:

- Hashing requires reading the **entire file contents** and may take time for large libraries (especially video).
- The UI must show progress (e.g. “Hashing 12 / 240…”) and should warn that the operation can take minutes for large selections.
- The UI should disable the action button while hashing/importing to prevent duplicate runs.

Practical constraints:

- Browser hashing can be CPU/memory intensive for large files.
- Not all browsers behave equally for very large files; Chrome/Chromium is the expected baseline.

### Reporting requirement

The Section 5 workflow must report:

- How many items were inserted
- How many were skipped due to duplicate checksum
- A sample list of skipped items (paths/filenames)

## Backlog / future work

- Replace “song” terminology with “media” in schema and code.
- Introduce an immutable download/serve strategy by `file_id` (Phase 1) with future migration to sha256-based storage paths.
- Eliminate comma-separated list ingestion (`f_singles` parsing) as a data integrity hazard by standardizing on normalized CSV inputs.
- Enhance the media upload/attach workflow (e.g., verify checksums, support migration/cleanup tooling, and improve dedupe behavior/reporting).
