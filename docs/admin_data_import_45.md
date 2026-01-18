# Admin UI: Data Import (Sections 4 & 5)

This document explains how to use **Section 4** and **Section 5** on `admin.php` to import media metadata into the GigHive database.

## How to use Sections 4/5 (high level)

### What Sections 4/5 do

Both sections:

- Let you pick a folder on your local machine in the browser.
- Filter to supported media files.
- Compute **SHA-256 checksums in the browser**.
- Upload a JSON “manifest” (metadata + checksums) to the server.
- The server imports metadata into MySQL.

The difference:

- **Section 4 (Reload / destructive)**: truncates and rebuilds media tables.
- **Section 5 (Add / non-destructive)**: keeps existing tables; inserts new files; duplicates are skipped.

### Typical workflow

1. In **Section 4** or **Section 5**, click **Browse…** and choose your media folder.
2. Click the main action button:
   - Section 4: **Scan Folder and Update DB**
   - Section 5: **Scan Folder and Add to DB**
3. Wait for hashing to finish (this can take a while for large folders).
4. After hashing completes, the browser uploads the manifest and the server starts a background DB import.
5. When the DB import finishes, the UI shows a final **OK** or **Error** result.

### What the status and progress mean

- **Status line (top of section)**
  - Shows the latest completed job for that section (e.g. last Reload job).
- **Job header while running**
  - Shows `Job <job_id>: queued/running/...` and an elapsed time counter.
- **Progress steps**
  - Each step shows `OK`, `PENDING`, or `ERROR`.
  - For long steps like **Insert files**, you may see a progress meter (processed/total).

### Canceling a DB import

- While a DB import is running, the button changes to **Cancel DB import**.
- Clicking it submits a cancellation request and the UI shows a confirmation message.
- Cancellation is cooperative: the worker stops at the next safe checkpoint.

### Recovery / Replay (when something failed)

- Expand **Previous Jobs (Recovery)**.
- This list is intentionally focused on jobs that are **not OK** (failed or incomplete).
- Select a job and click **Replay** to re-run it from the saved manifest.

### After metadata import: uploading the actual media files

Sections 4/5 import **metadata + hashes**. After the DB import, upload/copy media to the server using the `upload_media_by_hash.py` tool referenced in the UI.

## Architecture & implementation notes

### Manifest and hashing

- The browser computes SHA-256 for supported files and builds a JSON manifest.
- Each manifest item includes (at minimum):
  - `file_name`, `source_relpath`, `file_type`, `event_date`, `size_bytes`, `checksum_sha256`.
- Hashes are cached client-side (browser storage). **Clear cached hashes** resets that client-side cache.

### Async job model

- Imports are persisted as jobs under:
  - `/var/www/private/import_jobs/<job_id>/`
    - `meta.json` (job type/mode/timestamps)
    - `manifest.json` (the uploaded manifest)
    - `status.json` (live status for polling)
    - `result.json` (final output)
- A background worker performs the DB work and updates `status.json` during processing.

### Concurrency control

- A database lock file prevents concurrent imports:
  - `/var/www/private/import_database.lock`
- A worker lock directory ensures only one worker runs at a time:
  - `/var/www/private/import_worker.lock`

### Polling and caching

- The UI polls `import_manifest_status.php?job_id=...` to show live status.
- `import_manifest_*.php` endpoints are configured to be **no-store/no-cache** to prevent stale “queued” responses.

### Recovery endpoints

- `import_manifest_jobs.php`
  - Returns `last_job` and a Recovery list (typically error/unknown only).
- `import_manifest_replay.php`
  - Starts an async replay job from a previously saved `manifest.json`.

