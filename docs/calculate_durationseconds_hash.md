# Calculating `files.duration_seconds` and `files.checksum_sha256`

This document summarizes where GigHive populates the `files.duration_seconds` and `files.checksum_sha256` columns, and which programs are responsible for computing vs merely consuming these values.

## TL;DR

- `files.duration_seconds`
  - **Web uploads (PHP)**: computed at upload time in `UploadService` and stored during the initial `INSERT` into `files`.
  - **`upload_media_by_hash.py` (Python)**: computed by `ffprobe` on the destination host and stored via an `UPDATE files SET duration_seconds = ... WHERE checksum_sha256 = ...`.
  - **DB build/import utilities (Python)**: computed by `ffprobe` when generating import CSV/rows.

- `files.checksum_sha256`
  - **Web uploads (PHP)**: computed from uploaded bytes using `hash_file('sha256', ...)`.
  - **`upload_media_by_hash.py` (Python)**: does **not** compute hashes; it requires `files.checksum_sha256` to already exist.
  - **Manifest import endpoints (PHP)**: do **not** compute hashes; they validate and insert the provided `checksum_sha256` from the request payload.

## `files.duration_seconds`

### A) Web upload path (PHP)

- **Program**: web app upload pipeline
- **File**: `ansible/roles/docker/files/apache/webroot/src/Services/UploadService.php`
- **When**: during upload handling, after the file is moved into the final location.
- **How**:
  - `UploadService::probeDuration($targetPath)` is called.
  - It tries (in order):
    - `getID3` (pure PHP)n
    - `ffprobe` fallback if available in `PATH`.
- **Where it is stored**:
  - Inserted into the DB via `FileRepository::create()` as part of the `INSERT INTO files (..., duration_seconds, ...)`.

### B) Hash-based uploader path (Python)

- **Program**: `upload_media_by_hash.py`
- **File**: `ansible/roles/docker/files/apache/webroot/tools/upload_media_by_hash.py`
- **When**:
  - After the script rsyncs a media file to the destination host path.
  - Then it runs `ffprobe` (via SSH) against the destination file.
- **How**:
  - `remote_ffprobe_json(ssh_target, remote_path)` parses `format.duration` and converts it to integer seconds.
- **Where it is stored**:
  - `mysql_update_media_info(...)` executes:
    - `UPDATE files SET duration_seconds = <dur>, ... WHERE checksum_sha256 = <sha>;`

### C) DB build / import utilities (Python)

These utilities are used when building/loading a dataset (not necessarily for runtime uploads).

- **Programs**:
  - `ansible/roles/docker/files/mysql/dbScripts/loadutilities/mysqlPrep_full.py`
  - `ansible/roles/docker/files/mysql/dbScripts/loadutilities/mysqlPrep_sample.py`
  - `ansible/roles/docker/files/apache/webroot/tools/mysqlPrep_full.py`
  - `ansible/roles/docker/files/apache/webroot/tools/mysqlPrep_normalized.py`
- **How**:
  - `probe_duration_seconds(filepath)` runs `ffprobe` if available and returns integer seconds.
- **Where it is stored**:
  - Included as `duration_seconds` in the generated `files` data that is later loaded into MySQL.

## `files.checksum_sha256`

### A) Web upload path (PHP)

- **Program**: web app upload pipeline
- **File**: `ansible/roles/docker/files/apache/webroot/src/Services/UploadService.php`
- **When**: during upload handling (before move), to hash the uploaded temp file reliably.
- **How**:
  - `hash_file('sha256', $tmpPath)`
- **Where it is stored**:
  - Inserted into the DB via `FileRepository::create()` as `checksum_sha256`.
- **Additional behavior**:
  - If the checksum is valid hex, the file is stored on disk as `{sha256}.{ext}` under `/audio` or `/video`.

### B) Hash-based uploader path (Python)

- **Program**: `upload_media_by_hash.py`
- **File**: `ansible/roles/docker/files/apache/webroot/tools/upload_media_by_hash.py`
- **Behavior**:
  - Does **not** compute `sha256`.
  - Requires that `files.checksum_sha256` already exists in the DB.
  - Uses it to name the destination file: `{checksum_sha256}.{ext}`.

### C) Manifest import endpoints (PHP)

These endpoints accept a payload containing `checksum_sha256` and insert rows into `files`.

- **Files**:
  - `ansible/roles/docker/files/apache/webroot/import_manifest_add.php`
  - `ansible/roles/docker/files/apache/webroot/import_manifest_reload.php`
- **Behavior**:
  - Validate `checksum_sha256` matches `/^[0-9a-f]{64}$/`.
  - Insert it into the DB.
  - Do **not** hash any file bytes.

## Notes / Implications

- `upload_media_by_hash.py` will process only rows where `files.checksum_sha256` is non-empty, so any ingestion path that fails to populate checksums will effectively block this tool.
- Because both media storage and metadata are keyed by `checksum_sha256`, it is a good stable identifier to key derived artifacts (e.g., video thumbnails) as `video/thumbnails/<sha>.png`.
