# Add media_info + media_info_tool to files table

## Summary
This document describes the planned changes to add media metadata capture to the `files` table.

Two new columns will be added:

- `files.media_info` (MySQL `JSON`, nullable)
- `files.media_info_tool` (nullable string)

The data will be populated at two ingestion points:

1. **Initial database load (controller machine)** via the Python CSV-prep utilities (`mysqlPrep_full.py` / `mysqlPrep_sample.py`).
2. **File upload (Apache/PHP API)** during upload handling.

No code changes are included in this document; it is a specification/plan.

## Goals

- Persist full `ffprobe` JSON describing the container/format, streams, and (when present) chapters/programs.
- Record which tool/version produced the stored JSON (`media_info_tool`).
- Keep the system tolerant of missing tooling (store NULLs when `ffprobe` is unavailable or probing fails).

## Decisions (already made)

- **Column name**: `media_info` (not `stream_info`).
- **Column type**: `media_info` is **MySQL `JSON`**.
- **`media_info_tool`**: nullable.
- **Tool identity format** (`media_info_tool`): **Option A**
  - `ffprobe <version>`
  - `<version>` is derived from the first line of `ffprobe -version`.
- **ffprobe content**: include **format**, **streams**, plus **chapters** and **programs**.

## Canonical ffprobe command

Use the same command in both the controller Python utilities and the PHP upload flow to keep the stored JSON consistent:

```bash
ffprobe -v error -print_format json -show_format -show_streams -show_chapters -show_programs <file>
```

Notes:

- `-v error` ensures the output is clean JSON (no banner/log noise).
- For typical MP3/MP4 files, chapters/programs will usually be absent (or empty), which is expected.

## Database changes

### 1) Schema: add columns to `files`

**File:** `ansible/roles/docker/files/mysql/externalConfigs/create_music_db.sql`

Planned additions to the `CREATE TABLE files (...)` statement:

- `media_info JSON NULL`
- `media_info_tool VARCHAR(255) NULL`

### 2) Loader: import columns from files.csv

**File:** `ansible/roles/docker/files/mysql/externalConfigs/load_and_transform.sql`

The loader currently imports:

- `(file_id, file_name, file_type, @duration_seconds)`
- `SET duration_seconds = NULLIF(@duration_seconds, '');`

Planned changes:

- Extend `LOAD DATA INFILE` to read two more CSV fields: `@media_info`, `@media_info_tool`.
- Extend the `SET` clause:
  - `media_info = NULLIF(@media_info, '')`
  - `media_info_tool = NULLIF(@media_info_tool, '')`

Important constraint:

- Since `media_info` is `JSON`, imported values must be valid JSON when non-empty.

### 3) Existing DB upgrade path (recommended)

If upgrading an existing database without dropping/recreating it, apply a one-time migration:

```sql
ALTER TABLE files
  ADD COLUMN media_info JSON NULL,
  ADD COLUMN media_info_tool VARCHAR(255) NULL;
```

## Controller initial-load pipeline changes (Python)

The initial-load pipeline computes metadata during CSV generation, then MySQL imports it.

### Touch points

- `ansible/roles/docker/files/mysql/dbScripts/loadutilities/mysqlPrep_full.py`
- `ansible/roles/docker/files/mysql/dbScripts/loadutilities/mysqlPrep_sample.py`

### Planned behavior

- Add a probe function that runs the canonical ffprobe command and returns:
  - a compact, single-line JSON string (preferred), or
  - empty string on failure.
- Add a helper that returns `media_info_tool` in the format `ffprobe <version>` (cached).

When building the in-memory `files` map (where `duration_seconds` is currently added), also add:

- `media_info`
- `media_info_tool`

### Update files.csv format

The prep scripts currently emit `files.csv` with:

- `file_id, file_name, file_type, duration_seconds`

Planned new columns:

- `file_id, file_name, file_type, duration_seconds, media_info, media_info_tool`

### Requirements

- `ffprobe` must be installed on the machine executing `mysqlPrep_full.py` / `mysqlPrep_sample.py` (often the Ansible controller/admin machine).

## Upload pipeline changes (Apache/PHP)

### Touch points

- `ansible/roles/docker/files/apache/webroot/src/Services/UploadService.php`
- `ansible/roles/docker/files/apache/webroot/src/Repositories/FileRepository.php`

### Planned behavior

- Add probing to capture `media_info` using the canonical ffprobe command.
- Derive `media_info_tool = "ffprobe <version>"` from `ffprobe -version`.
- Extend the `files` insert logic to include `media_info` and `media_info_tool`.

Failure behavior:

- If ffprobe is not available or probing fails, store NULL for `media_info` and `media_info_tool`.

### Container requirement

- Add `ffmpeg` (provides `ffprobe`) to the Apache container build.

**File:** `ansible/roles/docker/files/apache/Dockerfile`

## Backfill (optional)

Once the columns exist, you may want to backfill existing rows that have NULL `media_info`.

Recommended approach:

- A controller-side Python utility that:
  - selects rows with `media_info IS NULL`
  - resolves the file path
  - probes with ffprobe
  - updates `media_info` and `media_info_tool`

## Validation checklist

- Confirm JSON is valid and imports cleanly via `LOAD DATA INFILE`.
- Confirm JSON fields do not contain newlines (prefer compact output) to avoid CSV parsing issues.
- Verify:
  - MP3/MP4 upload stores `media_info` + `media_info_tool`.
  - A file with chapters/programs stores those sections in the JSON.
- Confirm NULL behavior when ffprobe is missing.

## Open questions (optional refinements)

These are not blockers for the initial implementation, but are worth deciding later:

- Should `format.tags` and `stream.tags` be retained as-is (may be noisy)?
- Overwrite policy: only fill if NULL vs always overwrite vs overwrite when tool version changes.
- Any future indexing needs on `media_info` (generated columns / JSON indexes).
