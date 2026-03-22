# Database Load Methods

This document describes the two database (re)load mechanisms used by GigHive and how they differ.

## Overview

GigHive currently supports two practical ways to wipe and repopulate the MySQL database with media data:

1. **Admin-triggered runtime import** (web admin UI: `import_database.php`, `import_normalized.php`)
2. **MySQL initialization load** (container bootstrap via `/docker-entrypoint-initdb.d/`) (advanced)

Both approaches ultimately load per-table CSVs into MySQL, but they differ in *when* they run, *where* data lives during load, and how much infrastructure is involved.

---

## Method 1: Admin-triggered runtime import (web admin, Option A)

### Where it lives

- Runs from the **Apache/PHP container** via an admin-only endpoint:
  - `import_database.php`
  - `import_normalized.php`
- The admin UI (`admin.php`) provides a CSV upload control and triggers the import.

### When it runs

- Runs **on demand** while the system is live:
  - admin uploads a new source CSV
  - admin triggers “wipe + reload”

### How preprocessing works

- Uploads a single source CSV (typically the canonical `database.csv`).
- Runs the existing preprocessing script:
  - `mysqlPrep_full.py`
- That script generates normalized per-table CSVs (e.g., `sessions.csv`, `songs.csv`, etc.) in a job directory.

---

## For Bands/Event Planners. Admin UI Section 3A (Legacy): Upload Single CSV and Reload Database (destructive)

- Endpoint: `import_database.php`
- Upload field name: `database_csv`

### Minimum required headers in the uploaded source CSV

The uploaded source CSV must include a header row. The admin UI performs a preflight check before starting an import.

Minimum required headers:

- `t_title` (sessions title)
- `d_date` (session date)
- `d_merged_song_lists` (songs source)
- `f_singles` (files source)

---

## For Bands/Event Planners. Admin UI Section 3B (Normalized): Upload Sessions + Session Files and Reload Database (destructive)

- Endpoint: `import_normalized.php`
- Upload field names:
  - `sessions_csv`
  - `session_files_csv`

### Minimum required headers in `sessions.csv`

- `session_key`
- `t_title`
- `d_date`

### Minimum required headers in `session_files.csv`

- `session_key`
- `source_relpath`

### Where uploads and job artifacts are stored

Each admin-triggered import creates a unique job directory under:

- `/var/www/private/import_jobs/<jobId>/`

This includes Section 3B imports, which stage the uploaded `sessions.csv` and `session_files.csv` into the same job directory before loading.

The uploaded CSV is saved as:

- `/var/www/private/import_jobs/<jobId>/database.csv`

The preprocessing script writes generated per-table CSVs to:

- `/var/www/private/import_jobs/<jobId>/prepped_csvs/`

These paths are under `/var/www/private/` and are not served publicly by Apache.

Example directory structure:

```bash
root@0f034bf13860:/var/www/private/import_jobs# ll
total 12
drwxr-xr-x 3 www-data www-data 4096 Dec 24 19:19 ./
drwxrwxr-x 1 www-data www-data 4096 Dec 24 19:19 ../
drwxr-xr-x 3 www-data www-data 4096 Dec 24 19:20 20251224-191954-377d73b02838/

root@0f034bf13860:/var/www/private/import_jobs# ll 20251224-191954-377d73b02838/
total 156
drwxr-xr-x 3 www-data www-data  4096 Dec 24 19:20 ./
drwxr-xr-x 3 www-data www-data  4096 Dec 24 19:19 ../
-rw-r--r-- 1 www-data www-data  4158 Dec 24 19:20 import_local.sql
drwxr-xr-x 2 www-data www-data  4096 Dec 24 19:19 prepped_csvs/
-rw-r--r-- 1 www-data www-data 92908 Dec 24 19:19 session_files.csv
-rw-r--r-- 1 www-data www-data 41127 Dec 24 19:19 sessions.csv

root@0f034bf13860:/var/www/private/import_jobs# ll 20251224-191954-377d73b02838/prepped_csvs/
total 212
drwxr-xr-x 2 www-data www-data   4096 Dec 24 19:19 ./
drwxr-xr-x 3 www-data www-data   4096 Dec 24 19:20 ../
-rw-r--r-- 1 www-data www-data 122888 Dec 24 19:19 files.csv
-rw-r--r-- 1 www-data www-data    437 Dec 24 19:19 musicians.csv
-rw-r--r-- 1 www-data www-data   4801 Dec 24 19:19 session_musicians.csv
-rw-r--r-- 1 www-data www-data   6170 Dec 24 19:19 session_songs.csv
-rw-r--r-- 1 www-data www-data  24818 Dec 24 19:19 sessions.csv
-rw-r--r-- 1 www-data www-data   5692 Dec 24 19:19 song_files.csv
-rw-r--r-- 1 www-data www-data  20756 Dec 24 19:19 songs.csv
```

### `files.source_relpath` population behavior

GigHive stores an optional `files.source_relpath` value for traceability back to the original folder structure.

- **Admin UI Section 3A / 3B (Upload CSV(s) and Reload Database)**:
  - If the uploaded source CSV contains subdirectory paths in `f_singles` (e.g. `set1/20021024_3.mp3`), those relative paths are persisted to `files.source_relpath`.
  - If the CSV contains only basenames, `files.source_relpath` will effectively match the basename.
- **Admin UI Section 4 (Folder Scan → Import-Ready CSV)**:
  - The browser provides per-file relative paths (via `webkitRelativePath`) relative to the selected folder. These are written into `f_singles` and persisted to `files.source_relpath`.
- **Direct media uploads (Upload API / `UploadService.php`)**:
  - The browser does not provide a true local absolute path; uploads therefore cannot persist a full local path.
  - For consistency with the Section 4/5 “hash-first” convention, the server computes `files.checksum_sha256` and stores the file on disk as `{sha256}.{ext}` under `/audio/` or `/video/`.
  - The server also persists a canonical, human-readable filename (org + date + seq + label) into `files.source_relpath` for provenance (e.g. `stormpigs20251222_00001_fountain.mp4`).
  - This ensures downloads can reliably serve `{sha256}.{ext}` while the UI can still display the canonical name.

### How data is loaded

- Uses **client-side** loading from the Apache container into the MySQL server:
  - `LOAD DATA LOCAL INFILE ...`

This relies on two independent settings:

- **Server-side gate**: MySQL must have `local_infile=ON`
- **Client-side gate**: the mysql client must be invoked with `--local-infile=1` (client defaults often show `local-infile FALSE`)

With LOCAL INFILE, the CSV files do **not** need to be mounted into the MySQL container. The mysql client streams the file contents over the connection.

### Wipe + reseed behavior

The runtime import will:

- Truncate tables in the same safe order used by the existing admin “clear media” endpoint (`clear_media.php`).
- Reseed reference data such as `genres` and `styles` (matching the seed behavior in `01-load_and_transform.sql`).

### Pros

- **No Ansible run** required to reload data.
- **No shared mount** required to place per-table CSVs into the MySQL container.
- Much better admin workflow: upload CSV → click reload → done.

### Cons

- Must be carefully protected (admin-only), since it is a destructive operation.
- Requires runtime tooling in the Apache container:
  - `python3` + `pandas` (for preprocessing)
  - `mysql` client (recommended for predictable `LOAD DATA LOCAL INFILE` execution)

---

## Summary Table

| Aspect | MySQL initialization load (advanced) | Admin-triggered runtime import (Option A) |
|---|---|---|
| Trigger | MySQL entrypoint on fresh datadir | Admin action in web UI |
| Best for | First install / provisioning | Ongoing admin reloads |
| Data file location | Must be readable by MySQL server (`/var/lib/mysql-files/`) | Can remain on Apache container; streamed via LOCAL |
| Load statement | `LOAD DATA INFILE` | `LOAD DATA LOCAL INFILE` |
| Requires shared mount into MySQL | Typically yes | No |
| Requires `local_infile` | No | Yes (server ON + client `--local-infile=1`) |

---

## Operational Notes

- `LOAD DATA INFILE` working does **not** imply `local_infile` is enabled. `local_infile` is only relevant to `LOAD DATA LOCAL INFILE`.
- For the official `mysql:8.0` image, mysqld reads includes from:
  - `/etc/my.cnf` and `!includedir /etc/mysql/conf.d/`
  Mount custom config files into `/etc/mysql/conf.d/` to ensure settings like `local-infile=1` are applied.

---

## For Media Librarians. Admin UI Section 4: Folder Scan → Import-Ready CSV (destructive)

### What was added

`admin.php` now includes a folder scan/import section titled:

**Choose a Folder to Scan & Update the Database**

This section includes:

- A folder picker (`<input type="file" webkitdirectory ...>`)
- A preview panel showing detected sessions and counts
- A **Scan Folder and Update DB** button that generates an import-ready CSV and uploads it to `import_database.php`

### What it does

- The browser enumerates files from the selected folder and filters to supported media extensions.
- Files are grouped into sessions by derived `d_date`.
- An in-memory CSV is generated with the required headers:
  - `t_title`
  - `d_date`
  - `d_merged_song_lists`
  - `f_singles`
- The CSV is uploaded directly to `import_database.php` as `database.csv` (same upload field name `database_csv`).

### What Section 4 actually does (implementation details)

- The folder scan runs **in the browser** via the folder picker input (`webkitdirectory`) and the `FileList` it provides.
  - The server is not scanning its own filesystem; it only receives the generated CSV upload.
- Media detection is based on file extension:
  - `mp3`, `wav`, `aac`, `flac`, `m4a`, `mp4`, `mov`, `mkv`, `webm`, `avi`
- Session grouping behavior:
  - Each media file is assigned a `d_date`.
  - Files with the same derived `d_date` are grouped into a single session.
- `d_date` derivation order:
  - First: a date in the file path/name matching `YYYY-MM-DD`
  - Second: a date in the file path/name matching `YYYYMMDD`
  - Third: any year in the file path/name (`19xx` or `20xx`) mapped to `YYYY-01-01`
  - Fourth: the file’s `lastModified` timestamp
  - Fallback: `1970-01-01`
- Import trigger:
  - The generated CSV is posted to `import_database.php` using the `database_csv` form field (same endpoint/field as Section 3A).

### How to Scan your files and upload using Section 4

#### 1) Prereqs (quick verification)

- Use a browser with good `webkitdirectory` support (Chrome / Chromium / Edge).
- Ensure the folder you select contains at least one supported media file extension:
  - `mp3`, `wav`, `aac`, `flac`, `m4a`, `mp4`, `mov`, `mkv`, `webm`, `avi`
- Remember: this action will **truncate and replace** media tables (sessions/songs/files/etc.). The `users` table is preserved.

#### 2) Run the UI flow

- Go to `/admin.php`
- Scroll to **Section 4**
- Click **Select folder**
- Choose a folder that contains supported media files
- Confirm the preview populates with:
  - `Files selected: X`
  - `Supported media files: Y`
  - `Sessions detected: Z`
  - Any fallback counters (year/timestamp/1970) if applicable
- Confirm the button **Scan Folder and Update DB** becomes enabled.
  - It will remain disabled unless `supportedCount > 0` *and* `sessions.length > 0`.

#### 3) Execute the import

- Click **Scan Folder and Update DB**
- Accept the confirmation dialog
- You should see **Processing request...**
- On success you should get:
  - A success message
  - A link **See Updated Database** → `/db/database.php`
  - A step list / table counts if returned by `import_database.php`

#### 4) Validate results

- Click **See Updated Database**
- Sanity-check that:
  - Sessions are roughly consistent with **Sessions detected**
  - Files are roughly consistent with the number of supported media files selected
- Spot-check at least one known date-based session (e.g. if you have files named with `YYYY-MM-DD` or `YYYYMMDD`, verify that date shows up as a session).

#### 5) If it fails (what to capture)

- Copy the preview numbers (Files selected / Supported / Sessions detected).
- Copy the exact error text shown in the Section 4 status box.
- Note your browser + version.

---

## For Media Librarians. Admin UI Section 5: Folder Scan → Add to the Database (non-destructive)

Section 5 is intended for building a long-term media library without wiping existing data.

- The browser hashes files (SHA-256) and uploads a JSON manifest to the server.
- The server adds new items and skips duplicates.

### Endpoint and payload

- Endpoint: `import_manifest_add.php`
- Request body: JSON
- Minimum fields:
  - `org_name`
  - `event_type`
  - `items` (array)

Each item includes:

- `file_name`
- `source_relpath`
- `file_type`
- `event_date`
- `size_bytes`
- `checksum_sha256`

---

## For Advanced Users

### MySQL initialization load (container bootstrap)

#### Where it lives

- **Docker Compose / Ansible** mounts initialization SQL scripts into the MySQL container:
  - `/docker-entrypoint-initdb.d/00-create_music_db.sql`
  - `/docker-entrypoint-initdb.d/01-load_and_transform.sql`

#### When it runs

- Runs **only when the MySQL container initializes a fresh/empty data directory**.
- In practical terms this happens when:
  - the MySQL container is created with a new/empty `/var/lib/mysql`
  - a persistent volume is removed/reset

If the MySQL container is restarted with an already-initialized datadir, the `/docker-entrypoint-initdb.d/` scripts are typically **not re-run**.

#### How data is loaded

- Uses **server-side** loading:
  - `LOAD DATA INFILE '/var/lib/mysql-files/<table>.csv' ...`
- This relies on:
  - `secure_file_priv` permitting `/var/lib/mysql-files/`
  - the per-table CSVs being present in the MySQL container filesystem (usually via a volume mount)

#### Pros

- Great for **first-time setup** and predictable provisioning.
- Works without exposing any “dangerous” import capability in the web UI.

#### Cons

- Not convenient for frequent reloads (often implies container lifecycle steps).
- Generally tied to infrastructure actions (rebuild/recreate container, reset volume, rerun playbooks, etc.).
