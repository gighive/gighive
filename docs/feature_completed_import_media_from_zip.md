# Feature: Import Media from ZIP (and Export Progress Refactor)

**Status:** Planned — not yet implemented  
**Date:** 2026-06-21

---

## Overview

Two related features sharing a common async job + polling infrastructure pattern:

**Phase 1 — Export Media to ZIP — Progress Refactor:**  
The existing `doExportMedia()` function in `admin_system.php` uses a single synchronous HTTP request for the "Build archive" step, providing only an elapsed-time ticker with no real per-file feedback. Phase 1 refactors the export backend into an async worker + polling pattern, giving the operator a live `N / M files` progress bar during ZIP construction. The worker and polling infrastructure established here is directly reused in Phase 2.

**Phase 2 — Import Media from ZIP:**  
A new **Section F: Import Media from ZIP** on `admin/admin_system.php` that is the direct companion to Section E (Export Media to ZIP). The operator uploads a ZIP file; the server spawns a background worker that streams each entry directly to its final destination in `/var/www/html/audio/` or `/var/www/html/video/` as `{sha256}.{ext}` — the destination path is read directly from the entry name, which is already the SHA-256 hash (GigHive export convention). No database records are created — this is a pure file-layer restore with a live per-file progress bar, built on the infrastructure proven in Phase 1.

Both phases share a common `pollJobStatus()` JS helper extracted into `admin/assets/import_progress.js`.

---

## Primary Use Case and Scope

> **Phases 1 and 2 are scoped to a single use case: export all media / import all media.** This is a full-corpus backup and restore tool — not a selective or filtered transfer mechanism.
>
> **Core assumption: files embedded in a GigHive export ZIP are already content-addressed.** The export worker names each ZIP entry `{sha256}.{ext}` — the SHA-256 hash of the file's content, matching the server's canonical storage naming. The import worker reads the destination path directly from the entry name and requires no hash recomputation. A ZIP produced outside GigHive (or with non-hash entry names) will have all entries counted as `unsupported` at `prepare` time and skipped at import time.
>
> **Core assumption: a media export ZIP and a database backup are always created and restored as a matched pair.** The media files on disk and the database records that reference them must stay in sync. A ZIP exported on a given date should be restored alongside the database backup from that same point in time. Importing a ZIP without a matching database restore (or vice versa) leaves the system in a partially consistent state.
>
> The canonical full-instance backup sequence is: **Create DB Backup (Section C)** then **Export Media ZIP (Section E)**. The canonical restore sequence is: **Clear DB (Section A)** → **Restore DB from Backup (Section B)** → **Import Media ZIP (Section F)**.
>
> Filtered export (by band, date range, file type, etc.) and selective import (import only a subset of files from a ZIP based on database criteria) are deferred to **Phase 3**. Do not scope Phase 1 or Phase 2 work to accommodate per-file or per-event selection.

The existing Section E Export UI retains its `org_name` and `file_type` filter fields for backward compatibility during Phase 1, but the progress refactor work is driven entirely by the full-corpus use case. Phase 3 will revisit and expand the filter surface properly.

---

## Use Cases

**Import from ZIP:**
1. **Full instance restore** — The primary use case described in Section E's own description: "Use this to preserve custom files before a database reset, then re-import after rebuilding." The complete restore sequence is: Export ZIP (Section E) → Clear DB (Section A) → Restore DB from backup (Section B) → Import ZIP (Section F). After step B the database records already reference the correct `{sha256}.{ext}` filenames; step F puts the raw files back where the DB expects them.
2. **Cross-instance migration** — Export a ZIP from one GigHive instance, import it into another to transfer media files to disk without re-uploading through the TUS pipeline.
3. **Bulk file restore without TUS** — Admin convenience for re-adding raw media to disk after partial data loss.

**Export progress refactor:**
1. **Large corpus visibility** — An operator exporting thousands of files has no feedback that the ZIP build is progressing; the browser just shows an elapsed timer. The refactor provides `847 / 2341 files (36%)` live feedback.
2. **Early error surfacing** — If a file is unreadable mid-build, the synchronous approach fails silently until the entire request fails. The worker can surface per-file errors in the progress JSON immediately.

---

## How File Placement Works (Phase 2)

The server stores all media files using content-addressed naming: `/var/www/html/{type}/{sha256}.{ext}`. The export worker uses the SHA-256 hash as the ZIP entry name — `$entryName = $sha256 . '.' . $ext`. The import worker therefore reads the destination filename directly from the ZIP entry name: **no `hash_file()` computation is needed at import time**.

This also eliminates the need for a temp extraction directory. The import worker uses `ZipArchive::getStream()` to stream each entry directly to its final destination on disk, one file at a time, giving a real per-file progress meter with no temp disk overhead.

**Idempotency guarantee:** if `{sha256}.{ext}` already exists on disk the file is skipped and counted in `already_exists`. Running the same import twice produces the same result as running it once. This makes it safe to re-run a partial import after a timeout or interruption.

**No database records are created.** ZIP import is a file-layer restore only. Database records come from a separate path: Section B (Restore Database from Backup) for restoring an existing dataset, or the Import Media / Catalog workflow for fresh ingestion of previously unknown files.

---

## Async Job + Polling Pattern (Both Phases)

Both phases follow the **Background PHP Worker + Job Directory + Browser Polling** pattern documented in [`docs/patterns_async_worker.md`](../docs/patterns_async_worker.md). The section below captures the conventions specific to this feature; refer to that document for the reusable boilerplate.

Both phases use the same three-component pattern:

| Component | Import | Export |
|---|---|---|
| **Start endpoint** | `import_media_zip.php` mode=`start` | `export_media.php` mode=`start` |
| **Background worker** | `import_media_zip_worker.php` | `export_media_worker.php` |
| **Status endpoint** | `import_media_zip_status.php` | `export_media_status.php` |

### Job directory layout (shared convention)

Each job gets its own directory under `sys_get_temp_dir()`. All job-related files live inside it — this matches the existing `iphone_import_worker.php` / `iphone_import_server_scan.php` pattern already in production.

**Export job:**
```
sys_get_temp_dir()/gighive_export_{job_id}/
  status.json      ← written by start endpoint + updated by worker
  filelist.json    ← written by start endpoint, deleted by worker after reading
  archive.zip      ← written by worker, deleted by download endpoint after streaming
```

**Import job:**
```
sys_get_temp_dir()/gighive_import_{job_id}/
  status.json      ← written by start endpoint + updated by worker
  upload.zip       ← moved here from prepare token path by start endpoint
  worker.log       ← worker stdout/stderr for crash diagnosis
```
No `extracted/` subdirectory — the worker streams each ZIP entry directly to its destination using `ZipArchive::getStream()`, requiring no temp disk space beyond the ZIP itself.

### Status JSON format (shared convention)

Every `status.json` write uses `file_put_contents(..., LOCK_EX)` — matching `iphone_import_worker.php`'s `$writeStatus` closure. This makes all writes atomic with respect to concurrent readers, eliminating partial-read issues entirely.

Every write includes `updated_at: date('c')`. The status endpoint uses this field for stale job detection — comparing `updated_at` against the current time is more reliable and readable than filesystem `filemtime()`.

**Worker writes (import, running):**
```json
{
  "success": true,
  "job_id": "a3f8c2d91e4b7f05",
  "state": "running",
  "updated_at": "2026-06-21T14:22:10+00:00",
  "processed": 847,
  "total": 2341,
  "added": 710,
  "already_exists": 137,
  "skipped_unsupported": 0,
  "bytes_added": 1572864000,
  "steps": [
    { "name": "Import files", "status": "running", "message": "847 / 2341", "progress": { "processed": 847, "total": 2341 } }
  ]
}
```

**Worker writes (export, running):**
```json
{
  "success": true,
  "job_id": "b1e9f3a2c7d50846",
  "state": "running",
  "updated_at": "2026-06-21T14:22:10+00:00",
  "processed": 847,
  "total": 2341,
  "added": 847,
  "skipped": 0,
  "bytes_added": 3146000000,
  "filename": "gighive_export_all_20260621_142315.zip",
  "steps": [
    { "name": "Build archive", "status": "running", "message": "847 / 2341 written", "progress": { "processed": 847, "total": 2341 } }
  ]
}
```

`state` is one of: `running` | `done` | `error`. On `done`, the worker adds a `completed_at` ISO timestamp. On `error`, the worker adds an `error_message` string. The status endpoint removes the job directory for jobs older than 1 hour to prevent temp dir accumulation.

---

## Section Numbering Change in `admin_system.php` (Phase 2)

The existing optional Disk Resize section is currently **Section F** (conditionally shown based on `$__show_disk_resize`). Since Import Media from ZIP is always shown and is the logical companion to Section E, it inserts as the new **Section F**; Disk Resize becomes **Section G**. Only the `<h2>` heading text changes for Disk Resize — no functional impact. This change occurs in Phase 2; Phase 1 makes no structural changes to the section layout.

---

# Phase 2: Import Media from ZIP

## Files

### New (3):

1. `admin/import_media_zip.php` — `prepare` mode (inspect ZIP, no write) and `start` mode (save ZIP to temp, spawn worker, return `job_id`)
2. `admin/import_media_zip_worker.php` — background PHP script: streams each ZIP entry directly to `/var/www/html/{type}/` using `ZipArchive::getStream()`, writes progress JSON
3. `admin/import_media_zip_status.php` — poll endpoint: reads progress JSON, returns `{ state, steps }`

### Modified (1):

4. `admin/admin_system.php` — add Section F HTML; add `doImportMediaZip()` JS; rename Disk Resize heading to Section G; `pollJobStatus()` is already available from Phase 1's extraction into `import_progress.js`

---

## Implementation Checklist

Implement in this order (each file is independently testable before the next):

### 1. `admin/import_media_zip.php`
- [ ] POST-only guard; HTTP 403 if not admin
- [ ] `mode=prepare`:
  - [ ] `isset($_FILES['zip_file'])` guard → HTTP 400 `"No file uploaded"` (must be first)
  - [ ] `$_FILES['zip_file']['error'] === UPLOAD_ERR_OK` → HTTP 400
  - [ ] `.zip` extension check → HTTP 400
  - [ ] `$rc = $zip->open($_FILES['zip_file']['tmp_name'], ZipArchive::RDONLY)` → check `=== true` → HTTP 400
  - [ ] Check `$zip->numFiles > 50000` immediately after open → HTTP 400 (before any iteration)
  - [ ] Iterate `statIndex()`: count `$audioCount`, `$videoCount`, `$unsupportedCount`, accumulate `$uncompressedTotal` (all entries) and `$totalBytes` (audio+video only); abort loop early if `$uncompressedTotal > 2 × upload_max_filesize` → HTTP 400
  - [ ] `$zip->close()` after scan
  - [ ] `move_uploaded_file($_FILES['zip_file']['tmp_name'], ...)` → check `!== false` → HTTP 500
  - [ ] Return JSON: `success`, `prepare_token`, `audio_count`, `video_count`, `unsupported_count`, `file_count`, `total_bytes`
- [ ] `mode=start`:
  - [ ] Validate `prepare_token` `/^[a-f0-9]{16}$/` → HTTP 400
  - [ ] Resolve prep file; check `!is_file()` or expired (`filemtime < time() - 1800`) → HTTP 410
  - [ ] `function_exists('exec')` → HTTP 500 if false (before any file ops)
  - [ ] `bin2hex(random_bytes(8))` → `$jobId`
  - [ ] `mkdir($jobDir, 0700, true)` → check → HTTP 500
  - [ ] `rename($prepPath, $jobDir . 'upload.zip')` → check → HTTP 500
  - [ ] Write initial `status.json` with `LOCK_EX` (state=running, steps with "Scanning archive…")
  - [ ] `exec('php ... --job_id=... >> worker.log 2>&1 &')`
  - [ ] Return `{ "success": true, "job_id": "..." }`
- [ ] Invalid `mode` value → HTTP 400

### 2. `admin/import_media_zip_worker.php`
- [ ] `declare(strict_types=1)` + `PHP_SAPI !== 'cli'` guard
- [ ] Parse `--job_id=` arg; validate `/^[a-f0-9]{16}$/`; build `$jobDir`, `$jsonPath`, `$zipPath`
- [ ] Set up `$writeStatus` closure (captures `$jobDir`/`$jsonPath`, uses `LOCK_EX` — per `docs/patterns_async_worker.md` boilerplate)
- [ ] `set_time_limit(0)`
- [ ] Validate `$zipPath` exists and is readable → write error state and exit
- [ ] Load audio/video ext lists from env (with fallback defaults)
- [ ] `$rc = $zip->open($zipPath, ZipArchive::RDONLY)` → check `=== true` → write error state and exit
- [ ] Initialize counters: `$processed = $added = $alreadyExists = $bytesAdded = $unsupportedCount = 0; $errors = [];`
- [ ] Pre-scan: accumulate `$total` (valid entries only) and `$uncompressedTotal` (all entries)
- [ ] Write `status.json` with real `$total` after pre-scan
- [ ] Disk space check: `disk_free_space('/var/www/html') < $uncompressedTotal * 1.1` → write error state and exit
- [ ] Destination dir check: `is_dir('/var/www/html/audio')` && `is_dir('/var/www/html/video')` → throw if either missing
- [ ] Iterate entries:
  - [ ] Entry validation: path separator / SHA-256 / ext — on fail: `$unsupportedCount++`, `continue`
  - [ ] `$processed++`
  - [ ] Classify `$type` by extension; build `$dest`
  - [ ] `is_file($dest)` → `$alreadyExists++`, `continue`
  - [ ] `$zip->getStream($name)` → check `=== false` → `$errors[]`, `continue`
  - [ ] `fopen($dest, 'wb')` → check `=== false` → `fclose($stream)`, `$errors[]`, `continue`
  - [ ] `stream_copy_to_stream()` → check `=== false` → `fclose($fp)`, `fclose($stream)`, `@unlink($dest)`, `$errors[]`, `continue`
  - [ ] `fclose($fp)` → check `false` → `fclose($stream)`, `@unlink($dest)`, `$errors[]`, `continue`
  - [ ] `fclose($stream)`
  - [ ] `$added++`, `$bytesAdded += $stat['size']`
  - [ ] Write progress JSON every 10 files
- [ ] `$zip->close()`
- [ ] `@unlink($zipPath)`
- [ ] Check `$added === 0 && $alreadyExists === 0` → write `state: error` and exit
- [ ] Build final message: include stream error count if `count($errors) > 0`
- [ ] Write final `status.json` (`state: done`, `completed_at`, `errors` array, `steps`)
- [ ] Outer `catch (Throwable $e)` → write `state: error` with `steps` containing `$e->getMessage()`

### 3. `admin/import_media_zip_status.php`
- [ ] HTTP 403 if not admin
- [ ] Validate `$_GET['job_id']` → `/^[a-f0-9]{16}$/` → HTTP 400
- [ ] Build `$jobDir`, `$jsonPath`
- [ ] `!is_file($jsonPath)` → HTTP 404
- [ ] `$raw = @file_get_contents($jsonPath)` → `=== false` → return running fallback
- [ ] `$data = json_decode($raw, true)` → `null` → return running fallback
- [ ] Stale detection: `$data['state'] === 'running'` && age > 3600s → remove `$jobDir`, return error steps
- [ ] Pass `steps` from `$data` if present; synthesize fallback if absent
- [ ] Return `{ "success": true, "state": ..., "steps": [...] }`

### 4. `admin/admin_system.php`
- [ ] Add Section F HTML block: heading, description paragraphs (including DB-restore warning), file input, `#importZipStatus` div, `#importZipBtn` button
- [ ] Rename existing Disk Resize heading from Section F to Section G
- [ ] Add `doImportMediaZip()` JS:
  - [ ] Step 0: no-file guard → show error in `#importZipStatus`, re-enable button, return
  - [ ] Step 1: XHR POST `import_media_zip.php` mode=prepare via `FormData`; disable button; render `Upload ZIP` step with `upload.onprogress` live counter; on `upload.onload` transition to `Inspect ZIP` step; on `xhr.onload` resolve inspect step with found counts
  - [ ] Step 2: `window.confirm()` with full message (audio/video counts, bytes, unsupported note); re-enable + return on cancel
  - [ ] Step 3: POST mode=start with `prepare_token`; HTTP 410 → show error, re-enable; call `resetProgressLatch()` before `pollJobStatus()`
  - [ ] Step 4: `onDone` renders `data.steps[0].message`; re-enable button
  - [ ] `finally`-equivalent: `importZipBtn.disabled = false` on all exit paths (cancel, 410, error state, catch)

---

## Environment Variables

No new environment variables. Phase 2 uses only already-present vars:

| Var | Purpose in ZIP import |
|---|---|
| `UPLOAD_AUDIO_EXTS_JSON` | Classify extracted files as `file_type = 'audio'` |
| `UPLOAD_VIDEO_EXTS_JSON` | Classify extracted files as `file_type = 'video'` |

Fallback defaults (same as `catalog_scan_start.php`):
- Audio: `mp3, wav, flac, aac, ogg, m4a`
- Video: `mp4, mov, mkv, avi, webm, m4v`

---

## New Files

### 1. `admin/import_media_zip.php` — Start endpoint

**Auth:** `$user === 'admin'` (same pattern as `export_media.php`). Returns HTTP 403 JSON if not admin.  
**Method:** POST only.  
**Content-Type:** `multipart/form-data` (file upload).

**Input fields:**
- `zip_file` — binary ZIP upload (`$_FILES['zip_file']`)
- `mode` — `"prepare"` or `"start"`

---

#### `mode=prepare`

Opens `$_FILES['zip_file']['tmp_name']` read-only via `ZipArchive::open()` without extracting to disk. Iterates all entries using `$zip->statIndex()` to read name and uncompressed size. Classifies each entry by **both SHA-256 filename format and extension**: an entry is counted as audio or video only if its filename matches `/^[a-f0-9]{64}\.(ext)$/` at root level (no path separator) and the extension is in the supported audio or video list. Any entry that fails the hash format check or has an unsupported extension is counted as `unsupported_count`. **Saves the uploaded file** under a `prepare_token` path so `start` does not need to re-upload the ZIP. Returns aggregate counts, total bytes, and the token.

**Guards enforced before reading (in order):**
- `isset($_FILES['zip_file'])` — reject HTTP 400 `"No file uploaded"` if field is absent entirely; must be first
- `$_FILES['zip_file']['error'] === UPLOAD_ERR_OK` — reject any PHP upload error
- File extension of `$_FILES['zip_file']['name']` must be `.zip`
- `ZipArchive::open()` must return `true` (not `ZipArchive::ER_OK`/`0` — see Phase 1 bug fix) — validates ZIP format regardless of MIME type. Check: `if ($rc !== true) { return HTTP 400 "Invalid or corrupt ZIP file"; }`
- Entry count capped at 50,000 — reject with HTTP 400 `"ZIP contains too many entries"` if exceeded
- Total uncompressed size capped at `2 × upload_max_filesize` — reject with HTTP 400 `"Uncompressed ZIP content exceeds safety limit"` if exceeded (zip bomb protection)

After passing all guards: **call `$zip->close()`** to release the read handle on `tmp_name`. Then generate `$prepareToken = bin2hex(random_bytes(8))` and save the uploaded file via `move_uploaded_file($_FILES['zip_file']['tmp_name'], ...)` to `sys_get_temp_dir() . '/gighive_zip_prepare_' . $prepareToken . '.zip'`. If `move_uploaded_file()` returns `false`: return HTTP 500 `"Failed to save uploaded ZIP"` — do not issue a token. The prep file expires after 30 minutes — `start` mode rejects tokens where the prep file's `filemtime()` is older than 1800 seconds.

**Field definitions:**
- `audio_count` — entries with valid SHA-256 name + supported audio extension
- `video_count` — entries with valid SHA-256 name + supported video extension
- `unsupported_count` — all other entries (bad hash format, unsupported ext, subdirectory)
- `file_count` = `audio_count + video_count + unsupported_count` (total ZIP entry count)
- `total_bytes` — sum of uncompressed sizes of **audio and video entries only** (what will actually be written to disk; used for disk space estimation in the confirm dialog)

**Response:**
```json
{
  "success": true,
  "prepare_token": "f2a9d3e1b5c8f047",
  "audio_count": 80,
  "video_count": 62,
  "unsupported_count": 8,
  "file_count": 150,
  "total_bytes": 5494140928
}
```

---

#### `mode=start`

**No file upload** — the ZIP was already saved during `prepare`. Accepts `prepare_token` as a plain POST field.

1. Validate `prepare_token` matches `/^[a-f0-9]{16}$/` — return HTTP 400 if missing or invalid format
2. Resolve prep file: `$prepPath = sys_get_temp_dir() . '/gighive_zip_prepare_' . $prepareToken . '.zip'`
3. If `!is_file($prepPath)` or `filemtime($prepPath) < time() - 1800`: return HTTP 410 `"Prepare token expired or not found"` — operator must re-upload the ZIP
4. **Check `function_exists('exec')`** — return HTTP 500 `"exec() is disabled"` if unavailable. Must happen before any file operations so the prep file is not consumed on a non-recoverable error.
5. Generate `$jobId = bin2hex(random_bytes(8))` (16 hex chars)
6. Create job directory: `$jobDir = sys_get_temp_dir() . '/gighive_import_' . $jobId . '/';` — `if (!mkdir($jobDir, 0700, true))` return HTTP 500 `"Failed to create job directory"`
7. Move prep file into job directory: `if (!rename($prepPath, $jobDir . 'upload.zip'))` return HTTP 500 `"Failed to move ZIP into job directory"` (covers cross-device move failure)
8. Write initial `$jobDir . 'status.json'` with `LOCK_EX`: `{ "success": true, "job_id": "...", "state": "running", "updated_at": date('c'), "processed": 0, "total": 0, "added": 0, "already_exists": 0, "bytes_added": 0, "steps": [{ "name": "Import files", "status": "running", "message": "Scanning archive…", "progress": { "processed": 0, "total": 1 } }] }` — includes `steps` so the first poll returns a meaningful display before the worker's pre-scan completes. `total: 0` is expected at this stage; the worker updates it after the pre-scan.
9. Spawn worker: `exec('php ' . escapeshellarg(__DIR__ . '/import_media_zip_worker.php') . ' --job_id=' . escapeshellarg($jobId) . ' >> ' . escapeshellarg($jobDir . 'worker.log') . ' 2>&1 &')` — named `--job_id=` arg per `docs/patterns_async_worker.md` convention; log retained in job directory for crash diagnosis
10. Return:
```json
{
  "success": true,
  "job_id": "a3f8c2d91e4b7f05"
}
```

If `rename()` fails (disk error, cross-device): return HTTP 500 JSON error; do not spawn worker.

---

### 2. `admin/import_media_zip_worker.php` — Background worker

Called as a CLI PHP process: `php import_media_zip_worker.php --job_id={job_id}`

Follows the worker boilerplate in `docs/patterns_async_worker.md`: `declare(strict_types=1)`, `PHP_SAPI !== 'cli'` guard, `--job_id=` named arg, `$writeStatus` closure with `LOCK_EX`, `try/catch (Throwable $e)` wrapper.

```
$jobDir  = sys_get_temp_dir() . '/gighive_import_' . $jobId . '/'
$jsonPath = $jobDir . 'status.json'
$zipPath  = $jobDir . 'upload.zip'
```

**Execution flow:**

1. `set_time_limit(0)` — large archives may take minutes
2. Validate `$zipPath` exists and is readable; if not, write `{ "state": "error", "error_message": "ZIP file not found" }` and exit
3. Load audio/video ext lists from env (same fallback defaults as `catalog_scan_start.php`)
4. Open ZIP: `$rc = $zip->open($zipPath, ZipArchive::RDONLY)` — if `$rc !== true`: write `{ "state": "error", "error_message": "ZipArchive::open failed (code $rc)" }` and exit. Read-only; the ZIP stays open for the entire run while entries are streamed one-by-one
5. **Initialise all counters** before the pre-scan:
   ```php
   $processed = 0; $added = 0; $alreadyExists = 0;
   $bytesAdded = 0; $unsupportedCount = 0; $errors = [];
   $total = 0; $uncompressedTotal = 0;
   ```
   (`$total` and `$uncompressedTotal` are set in the pre-scan below; the others accumulate during the main iteration loop.)

5a. **Pre-scan all entries** to count valid media files — establishes the progress bar denominator:
   ```php
   for ($i = 0; $i < $zip->numFiles; $i++) {
       $stat = $zip->statIndex($i);
       $uncompressedTotal += (int)($stat['size'] ?? 0); // all entries, for disk space check
       $name = $stat['name'];
       $ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION));
       $hash = pathinfo($name, PATHINFO_FILENAME);
       // Valid GigHive export entry: {sha256}.{supported_ext} at root level (no path separator)
       if (strpos($name, '/') === false
           && preg_match('/^[a-f0-9]{64}$/', $hash)
           && (isset($audioExtsSet[$ext]) || isset($videoExtsSet[$ext]))) {
           $total++;
       }
   }
   ```
6. Update `status.json` after pre-scan — first substantive write; sets the real `$total` denominator:
```json
{
  "success": true,
  "job_id": "...",
  "state": "running",
  "updated_at": "...",
  "processed": 0,
  "total": 2341,
  "added": 0,
  "already_exists": 0,
  "bytes_added": 0,
  "steps": [
    { "name": "Import files", "status": "running", "message": "0 / 2341 files imported", "progress": { "processed": 0, "total": 2341 } }
  ]
}
```
7. **Disk space check** against the destination: `disk_free_space('/var/www/html') < $uncompressedTotal * 1.1` — if true, write error and exit. Checks the destination volume, not temp dir, since we are writing directly to `/var/www/html/{type}/`.
7a. **Destination directory check** (pre-loop, use `throw`): `is_dir('/var/www/html/audio')` and `is_dir('/var/www/html/video')` — if either is missing throw immediately. No individual file can be written without these; a missing dir means the volume is not mounted or the container is misconfigured.
8. Iterate all entries by index (`for $i = 0; $i < $zip->numFiles; $i++`):
   - `$stat = $zip->statIndex($i); $name = $stat['name']`
   - `$ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION))`
   - `$hash = pathinfo($name, PATHINFO_FILENAME)`
   - **Entry validation (replaces ZipSlip path traversal check):** skip entry if `strpos($name, '/') !== false` (subdirectory), `!preg_match('/^[a-f0-9]{64}$/', $hash)` (not a valid SHA-256), or ext not in audio/video list. On skip: `$unsupportedCount++`, `continue` — not counted in `$processed`.
   - `$processed++` — incremented for every entry that passes validation (valid SHA-256 + supported ext)
   - `$type = isset($audioExtsSet[$ext]) ? 'audio' : 'video'`
   - `$dest = '/var/www/html/' . $type . '/' . $hash . '.' . $ext`
   - If `is_file($dest)`: `$alreadyExists++`, continue — idempotent skip
   - Stream to destination — four checked steps (use `continue` not `throw` so remaining files are still processed):
     1. `$stream = $zip->getStream($name)` — if `=== false`: `$errors[] = "getStream failed: $name"`, `continue`. Cause: corrupt or unsupported-compression entry.
     2. `$fp = fopen($dest, 'wb')` — if `=== false`: `fclose($stream)`, `$errors[] = "fopen failed: $dest"`, `continue`. Cause: missing dir, permissions, disk full. Close `$stream` to avoid resource leak.
     3. `$copied = stream_copy_to_stream($stream, $fp)` — if `=== false`: `fclose($fp)`, `fclose($stream)`, `@unlink($dest)`, `$errors[] = "stream_copy failed: $name"`, `continue`. `@unlink` removes the partial file so a re-run does not incorrectly count it as `$alreadyExists`.
     4. `if (!fclose($fp))`: `fclose($stream)`, `@unlink($dest)`, `$errors[] = "fclose failed (disk full?): $name"`, `continue`. Catches buffered-write flush failures that `stream_copy_to_stream` did not detect.
     5. `fclose($stream)` — return value ignored (ZIP entry stream).
   - On success (all five steps passed): `$added++`, `$bytesAdded += $stat['size']`
   - Write progress JSON every 10 files (includes `steps` — matching Phase 1 worker pattern):
```json
{
  "success": true, "job_id": "...", "state": "running", "updated_at": "...",
  "processed": 847, "total": 2341, "added": 710, "already_exists": 137, "bytes_added": 805306368,
  "steps": [
    { "name": "Import files", "status": "running", "message": "847 / 2341 files imported", "progress": { "processed": 847, "total": 2341 } }
  ]
}
```
9. `$zip->close()`
10. Delete `$zipPath` — the uploaded ZIP is no longer needed once all entries are streamed to their destinations. The job directory (`$jobDir`) is retained; it contains only `status.json` and `worker.log` at this point.
11. If `$added === 0 && $alreadyExists === 0`: write `state: error`, `error_message: "No valid GigHive media entries found in ZIP"` and exit.
12. Write final `status.json`:
```json
{
  "success": true, "job_id": "...", "state": "done",
  "processed": 2341, "total": 2341, "added": 2195, "already_exists": 137,
  "bytes_added": 1572864000, "completed_at": "2026-06-21T14:23:11Z", "errors": [],
  "steps": [
    { "name": "Import files", "status": "ok",
      "message": "2195 added, 137 already on disk, 9 skipped (unsupported), 0 errors (1.5 GB added)",
      "progress": { "processed": 2341, "total": 2341 } }
  ]
}
```

Final message format: `"{$added} added, {$alreadyExists} already on disk, {$unsupportedCount} skipped (unsupported){$errNote} ({$bytesHuman} added)"` where `$errNote = count($errors) > 0 ? ', ' . count($errors) . ' stream error(s) — see worker.log' : ''`. Stream errors are non-zero when `getStream()`/`fopen()`/`fclose()` failures occurred; the operator can inspect `worker.log` for details. The state remains `done` (not `error`) since a partial import is a valid outcome — the operator can re-run safely (idempotency guarantee).

If an uncaught exception occurs at any point, write the following and exit — include `steps` so the status endpoint passes the actual exception message through to the operator without relying on fallback synthesis:
```json
{ "state": "error", "error_message": "<exception message>", "steps": [{ "name": "Import files", "status": "error", "message": "<exception message>" }] }
```

**Notes:**
- No `hash_file()` call — the SHA-256 is read directly from the ZIP entry name; this eliminates the previous bottleneck entirely
- No temp extraction directory — `ZipArchive::getStream()` streams each entry directly to its destination; no extra disk space required beyond the ZIP itself
- Progress reflects **real files written to their final destination**, one at a time
- All `status.json` writes use `LOCK_EX` (via the `$writeStatus` closure per `docs/patterns_async_worker.md`) — atomic with respect to concurrent readers; no partial-read risk

---

### 3. `admin/import_media_zip_status.php` — Poll endpoint

**Auth:** `$user === 'admin'` guard. Returns HTTP 403 JSON if not admin.  
**Method:** GET  
**Input:** `?job_id={job_id}`

**Logic:**
1. Validate `$jobId` matches `/^[a-f0-9]{16}$/` — reject any other pattern with HTTP 400
2. Build `$jobDir = sys_get_temp_dir() . '/gighive_import_' . $jobId . '/'`; `$jsonPath = $jobDir . 'status.json'`
3. If `!is_file($jsonPath)`: return `{ "success": false, "error": "Job not found" }` HTTP 404
4. Read and decode: `$raw = @file_get_contents($jsonPath)` — if `=== false` (race: file deleted between step 3 and here): return `{ "success": true, "state": "running", "steps": [...fallback...] }`. `$data = json_decode($raw, true)` — if `null` (partial write): same running fallback.
5. **Stale detection** (uses decoded `$data`): if `$data['state'] === 'running'` and `(time() - strtotime($data['updated_at'] ?? 'now')) > 3600` — remove `$jobDir` tree, return `{ "success": true, "state": "error", "steps": [{ "name": "Import files", "status": "error", "message": "Worker timed out or failed to start" }] }`
6. Use `steps` from `$data` if present; synthesise a fallback if absent (matches Phase 1 status endpoint pattern). Return the response directly — the worker writes fully-formed `steps` into `status.json` so synthesis is only a safety net.

Example running response (steps passed through from `status.json`):
```json
{
  "success": true,
  "state": "running",
  "steps": [
    {
      "name": "Import files",
      "status": "running",
      "message": "847 / 2341 files imported",
      "progress": { "processed": 847, "total": 2341 }
    }
  ]
}
```

When `state=done`:
```json
{
  "success": true,
  "state": "done",
  "steps": [
    {
      "name": "Import files",
      "status": "ok",
      "message": "2195 added, 137 already on disk, 9 skipped (unsupported) (1.5 GB added)",
      "progress": { "processed": 2341, "total": 2341 }
    }
  ]
}
```

When `state=error`:
```json
{
  "success": true,
  "state": "error",
  "steps": [
    {
      "name": "Import files",
      "status": "error",
      "message": "Worker error: ZIP file not found"
    }
  ]
}
```

**Job directory cleanup (Phase 2):** Unlike Phase 1, there is no download endpoint to trigger cleanup. The import job directory (`gighive_import_{job_id}/`) retains `status.json` and `worker.log` after completion. Cleanup options: (a) the status endpoint deletes `$jobDir` when `state: done` and the read is more than 30 minutes old — `updated_at` acts as the TTL anchor; (b) a periodic OS-level temp dir cleanup cron removes directories older than N days; (c) explicit JS call to a dedicated `import_media_zip_cleanup.php` endpoint after the success message is rendered. Approach (b) is the simplest for Phase 2 and requires no code; approaches (a) and (c) are deferred to a future polish pass.

---

## Section F HTML in `admin_system.php`

```html
<div class="section-divider">
  <h2>Section F: Import Media from ZIP</h2>
  <p class="muted">
    Restores media files to disk from a ZIP archive. Files are placed in the audio or video
    directory using their SHA-256 hash as the filename. Files already on disk are skipped —
    safe to re-run. <strong>No database records are created.</strong>
  </p>
  <p class="muted" style="color:#f59e0b">
    <strong>Important:</strong> This operation is the companion to Section B (Restore Database
    from Backup). Always restore the database backup <em>before</em> importing the ZIP, using
    the backup and ZIP created at the same point in time. Importing a ZIP without a matching
    database restore leaves files on disk with no corresponding records.
  </p>
  <div class="row">
    <label for="import_zip_file">ZIP file</label>
    <input type="file" id="import_zip_file" name="import_zip_file" accept=".zip" />
  </div>
  <div id="importZipStatus"></div>
  <button type="button" id="importZipBtn" onclick="doImportMediaZip()">Import ZIP</button>
</div>
```

---

## `doImportMediaZip()` JS — Three Rendered Steps

Three steps are rendered in `#importZipStatus` via `renderImportStepsShared()`. A no-file guard and a confirm dialog sit between the steps but do not appear in the progress panel.

| # | Guard / Step | Status messages shown |
|---|---|---|
| — | **No-file guard** | Before any fetch: if `fileInput.files[0]` is absent, set `importZipStatus.innerHTML` to `"Please select a ZIP file first."` and return early (button never disabled). |
| 1 | **Upload ZIP** | `"Uploading…"` → `"123.4 MB / 455.1 MB uploaded"` (live, via XHR `upload.onprogress`) → `"455.1 MB uploaded"` ✓ |
| 2 | **Inspect ZIP** | `"Scanning entries…"` (while PHP scans the ZIP central directory after upload completes) → `"10 audio + 5 video found (455.1 MB)"` ✓ |
| — | **Confirm** | `window.confirm()` dialog (outside the progress panel): `"{N} audio + {M} video files ready to import ({fmtBytes}).\n\n{if unsupported: "{K} entries will be skipped (unsupported format).\n\n"}Files already on disk are skipped safely.\n\nDo you wish to import?"` — if canceled: step 3 shows `"Canceled."` and button re-enabled. |
| 3 | **Import files** | `"Starting…"` → `"847 / 2341 files imported"` (live, via `pollJobStatus()` every 1500 ms) → `"2195 added, 137 already on disk, 9 skipped (unsupported) (1.5 GB added)"` ✓ |

**Implementation notes:**
- Steps 1 and 2 share a single HTTP round-trip (XHR POST `import_media_zip.php` mode=`prepare`). `upload.onprogress` drives step 1's live counter; `upload.onload` transitions the panel to step 2 `"Scanning entries…"`; `xhr.onload` resolves step 2 once the PHP response arrives.
- The `prepare_token` received from the inspect response is passed as a plain POST field to `mode=start` — ZIP bytes are not re-transmitted.
- If the start response is HTTP 410 (expired token), step 3 shows `"Prepare token expired — please re-select the ZIP and try again."`

**Button label progression:** `"Import ZIP"` → `"Uploading ZIP…"` → `"Inspecting ZIP…"` → `"Importing…"` → `"Import ZIP"` (re-enabled via `finally`).

**Button re-enable:** `importZipBtn.disabled = false` must happen on **every** exit path — happy path (`state: done`), error path (`state: error`), cancel after confirm, and any `catch` block. Implemented via `importRun().finally(...)`.

---

## Security

| Concern | Mitigation |
|---|---|
| Path traversal (ZipSlip) | Entry name validated as `{sha256}.{ext}` with no path separator before any extraction; only entries matching `/^[a-f0-9]{64}\.(ext)$/` at root level are processed — no path traversal possible |
| Zip bomb (huge uncompressed content) | `prepare` mode caps total uncompressed size at `2 × upload_max_filesize` before issuing the token; `start` does not re-inspect the ZIP (already validated in `prepare`) |
| Entry count bomb | Reject ZIPs with more than 50,000 entries |
| Non-ZIP MIME spoofing | Validate via `ZipArchive::open()`, not `$_FILES['zip_file']['type']` which the browser can forge |
| `job_id` injection | `job_id` validated against `/^[a-f0-9]{16}$/` before constructing any temp file path |
| Disk space exhaustion | `disk_free_space('/var/www/html')` checked against 110% of total uncompressed size before extraction begins; destination volume checked (not temp dir) since files stream directly to `/var/www/html/{type}/` |

---

## Constraints and Known Limitations

**PHP upload size:**  
The ZIP must fit within PHP's `upload_max_filesize`, which the Dockerfile sets to `upload_max_bytes × 1.04` (the same limit used for single TUS file uploads). A full corpus export ZIP can be much larger than any individual file. Operators should export in filtered chunks by `org_name` if the full corpus ZIP exceeds this limit.

**Cloudflare upload limit:**  
If accessing through Cloudflare, platform upload body limits apply (100 MB on free plans). Import large ZIPs via a direct connection to the VM, bypassing the Cloudflare proxy.

**`exec()` availability:**  
The background worker is spawned via `exec(...&)`. If `exec()` is disabled in `php.ini` (`disable_functions`), the worker cannot be spawned. The start endpoint must check `function_exists('exec')` before spawning and return HTTP 500 with a descriptive error if unavailable.

**Thumbnails not included:**  
The export ZIP does not include video thumbnails (derived assets). After a ZIP import + DB restore, thumbnail images will 404 in the UI until regenerated. No DB integrity issue — thumbnails are not stored in the database.

**Matched pair requirement — DB backup and media ZIP must be from the same point in time:**  
The database records reference files by `{sha256}.{ext}`. A media ZIP exported at time T contains exactly the files that T's database backup expects. Restoring a database backup from a different date than the ZIP will result in missing files (DB records with no file on disk) or orphaned files (files on disk with no DB record). Operators must treat the DB backup and the media ZIP as an inseparable restore unit.

**Orphaned files if ZIP is imported without DB restore:**  
If the DB was not restored from backup (e.g., operator cleared the DB and started fresh), the imported files land on disk with no database records. The operator must then run Import Media (folder) or Catalog + Promote to create records. This is a valid recovery path but is not the primary use case.

---

# Phase 1: Export Media to ZIP — Progress Refactor

## Motivation

The current `doExportMedia()` "Build archive" step (`mode=build`) is a single synchronous POST. For large exports the browser receives no feedback except an elapsed-time ticker. For an operator exporting 3 GB across 2,000 files this can run for 60+ seconds with no indication of progress. Phase 1 refactors the build step into an async worker + polling pattern. The worker infrastructure, progress JSON format, status endpoint shape, and `pollJobStatus()` JS helper established here are all reused by Phase 2 (import) without modification.

---

## How the Planned Progress Meter for Export Works

### Step 1 — Prepare (unchanged)
The existing `prepare` POST runs instantly: queries the DB, counts files and total bytes, and shows the operator a confirmation dialog. No change to this step.

### Step 2 — Start (new async kick-off)
Instead of the old synchronous `build` POST, clicking Confirm now sends a `start` POST to `export_media.php`. The server:
- Runs the same DB query to get the file list (`$rows`)
- Generates a `job_id` (16 hex chars)
- Writes `$rows` to a temp JSON filelist on disk
- Writes an initial progress JSON: `{ "state": "running", "processed": 0, "total": N }`
- Spawns `export_media_worker.php` as a background CLI process via `exec(...&)` — returns immediately
- Returns `{ "job_id": "..." }` to the browser in under 1 second

### Step 3 — Background Worker
`export_media_worker.php` runs entirely independently of the HTTP request:
- Iterates all `$rows`; for each file: opens the ZIP with `ZipArchive::CREATE`, calls `addFile()`, then calls `close()` immediately — **each `close()` writes that one file's bytes to disk before moving to the next**
- Increments `$processed` for **every** row (including files skipped as missing), so the counter always reaches `$total`
- Writes progress JSON every 10 rows: `{ "state": "running", "processed": 847, "total": 2341, "added": 834, "skipped": 13 }` — progress reflects **real bytes written to disk**, not entries queued
- After the loop: writes `"state": "done"` — no deferred `close()` step; the archive is fully written by the time the loop ends

### Step 4 — Browser Polls
`pollJobStatus()` fires every 1500 ms against `export_media_status.php?job_id=...`:
- Status endpoint reads the progress JSON and returns a structured `steps` array
- `renderImportStepsShared()` renders the live bar: **`847 / 2341 files (36%)`** — this reflects files actually written to the ZIP on disk
- When `state: "done"`, the response includes `"ready_for_download": true` and polling stops

### Step 5 — Download (new separate endpoint)
Once `ready_for_download` is set, the JS issues a GET to `export_media_download.php?job_id=...`:
- Streams the pre-built temp ZIP with a known `Content-Length` header
- Browser's native download progress indicator works correctly because the file size is known
- After streaming completes, the temp ZIP and progress JSON are both deleted from disk

### Comparison: current vs. refactored

| | Current (`build` mode) | Phase 1 (refactored) |
|---|---|---|
| Build feedback | Elapsed timer only | Live `N / M files written (X%)` progress bar |
| Progress accuracy | No progress | Reflects real bytes written to disk (per-file open/close) |
| Download progress | Unknown size, no browser indicator | `Content-Length` set — browser shows % |
| Error surfacing | Entire request fails silently at end | Per-row errors visible during polling |

---

## Files

### New (3):

1. `admin/export_media_worker.php` — background PHP script: reads DB file list, builds ZIP file-by-file, writes progress JSON
2. `admin/export_media_status.php` — poll endpoint: reads progress JSON, returns `{ state, steps }`
3. `admin/export_media_download.php` — download endpoint: verifies `job_id`, streams pre-built temp ZIP with correct headers

### Modified (2):

4. `admin/export_media.php` — add `start` mode; keep `prepare` mode unchanged; deprecate `build` mode (kept for backward compatibility but no longer called by the UI)
5. `admin/admin_system.php` — update `doExportMedia()` to use `start` + `pollJobStatus()` + download trigger; update `import_progress.js` `<script>` tag to ensure `pollJobStatus()` is available; **update Section E description paragraph** to add size-guideline disclaimer (see below)

**Planned Section E description update in `admin_system.php`:**

The existing `<p class="muted">` in Section E currently ends after the Import Media folder link. Add the following sentence:

> *"This tool is designed for full-corpus backup and restore of small-to-medium libraries (guideline: under 20 GB). Always create a database backup (Section C) at the same time — the ZIP and DB backup form a matched restore pair. For libraries larger than 20 GB, rsync or direct volume backup is recommended — see `docs/feature_import_media_from_zip.md` for guidance."*

### Modified (1 — shared JS):

6. `admin/assets/import_progress.js` — extract `pollJobStatus()` helper (used by both import and export); see *Shared Infrastructure* section below

---

## Environment Variables

No new environment variables. Phase 1 uses the same `org_name` and `file_type` filter logic already present in `export_media.php`.

---

## File Details

### 4. `admin/export_media.php` — `start` mode addition

**Existing `prepare` mode:** unchanged. Returns `{ success, count, skipped, total_bytes }` instantly.

**New `start` mode:**
1. Run the same DB query as `prepare` to get `$rows`
2. Generate `$jobId = bin2hex(random_bytes(8))`
3. Determine output filename: same logic as current `build` mode (`gighive_export_{label}_{type}_{Ymd_His}.zip`)
4. **Check `function_exists('exec')`** — return HTTP 500 `"exec() is disabled; background worker cannot be spawned"` if unavailable. Must happen before any file operations so no orphaned temp files are created on a non-recoverable error.
5. Create job directory: `$jobDir = sys_get_temp_dir() . '/gighive_export_' . $jobId . '/'; mkdir($jobDir, 0700, true)`
6. Write `$rows` to `$jobDir . 'filelist.json'`
7. Write **one** complete initial `$jobDir . 'status.json'` using `file_put_contents(..., LOCK_EX)`: `{ "success": true, "job_id": "...", "state": "running", "updated_at": date('c'), "processed": 0, "total": count($rows), "added": 0, "skipped": 0, "bytes_added": 0, "filename": "..." }` — `filename` included from the start so it is readable throughout the job lifecycle
8. Spawn worker: `exec('php ' . escapeshellarg(__DIR__ . '/export_media_worker.php') . ' --job_id=' . escapeshellarg($jobId) . ' >> ' . escapeshellarg($jobDir . 'worker.log') . ' 2>&1 &')` — named `--job_id=` arg matches `iphone_import_worker.php` convention; log retained in job directory for crash diagnosis
9. Return `{ "success": true, "job_id": "...", "total": count($rows) }`

**Deprecated `build` mode:** retained in the file but returns HTTP 410 Gone with `{ "error": "build mode deprecated; use start mode" }` once Phase 1 is deployed.

---

### 5. `admin/export_media_worker.php` — Background worker

Called as a CLI PHP process: `php export_media_worker.php --job_id={job_id}`

```php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit(1); }

// Parse --job_id= named arg (matches iphone_import_worker.php convention)
$jobId = '';
foreach ($argv as $arg) {
    if (str_starts_with((string)$arg, '--job_id=')) {
        $jobId = substr((string)$arg, strlen('--job_id='));
    }
}
if ($jobId === '' || !preg_match('/^[a-f0-9]{16}$/', $jobId)) {
    fwrite(STDERR, "export_media_worker: invalid or missing --job_id\n");
    exit(1);
}

$jobDir       = sys_get_temp_dir() . '/gighive_export_' . $jobId . '/';
$filelistPath = $jobDir . 'filelist.json';
$jsonPath     = $jobDir . 'status.json';
$zipPath      = $jobDir . 'archive.zip';

// $writeStatus closure — LOCK_EX on every write (matches iphone_import_worker.php)
$writeStatus = function(array $payload) use ($jsonPath): void {
    $payload['updated_at'] = date('c');
    @file_put_contents($jsonPath, json_encode($payload, JSON_UNESCAPED_SLASHES) . "\n", LOCK_EX);
};
```

**Execution flow (inside `try { ... } catch (Throwable $e) { ... }`):**

1. `set_time_limit(0)`
2. Read `$rows` from `$filelistPath`; delete `$filelistPath` immediately after reading (cleanup)
3. Set `$total = count($rows)` — stored as a PHP variable for use in all JSON writes
4. **No initial JSON write** — the `start` endpoint already wrote `status.json` with `filename` included; the worker's first JSON write is the progress update inside the iteration loop
5. Iterate `$rows` — for each row:
   - Validate SHA-256 format, determine `$dir` (audio/video), construct `$filePath`
   - `$processed++` — **incremented for every row**, including skips, so the bar reaches 100%
   - If `!is_file($filePath)`: `$skipped++`, continue
   - `$entryName = $sha256 . '.' . $ext` — **hash-based entry name**; the import worker reads the destination path directly from this name without recomputing the hash
   - `$zip = new ZipArchive();` `$rc = $zip->open($zipPath, ZipArchive::CREATE);` — `CREATE` creates a new archive on the first file; appends to the existing one on subsequent files. (`ZipArchive::OVERWRITE` is intentionally not used — it would truncate the archive on every iteration.) If `$rc !== true`: `throw new RuntimeException("ZipArchive::open failed (code $rc)")` — note: `ZipArchive::open()` returns `true` on success (not `ZipArchive::ER_OK`/`0`); the catch block writes `state: error` and exits cleanly.
   - `$zip->addFile($filePath, $entryName)`
   - `$zip->close()` — **writes this one file's bytes to disk immediately**; progress after this call reflects real bytes on disk
   - `$added++`, `$bytesAdded += filesize($filePath)`
   - Write progress JSON every 10 files
6. After loop: if `$added === 0 && !is_file($zipPath)` — all rows were skipped (no matching files on disk); write `state: error`, `error_message: "No exportable files found on disk"` and exit. Do not write `state: done` if no archive was created.
7. Write final status via `$writeStatus([..., 'state' => 'done', 'completed_at' => date('c'), ...])` — the archive is fully written; no deferred I/O

**Catch block:** `catch (Throwable $e)` — calls `$writeStatus(['state' => 'error', 'error_message' => $e->getMessage()])` and `exit(1)` — mirrors `iphone_import_worker.php` fatal error handling.

The job directory is **not** deleted by the worker — it is retained for the download step. The download endpoint streams `archive.zip` and then deletes the entire `$jobDir` directory.

---

### 6. `admin/export_media_status.php` — Poll endpoint

Stands alone as the first implementation of this pattern. Guards `job_id` against `/^[a-f0-9]{16}$/`. Reads `sys_get_temp_dir() . '/gighive_export_' . $jobId . '/status.json'`. Constructs and returns a `steps` array for `renderImportStepsShared()`.

**Stale job detection** uses `updated_at` from the JSON (not `filemtime()`): if `state === 'running'` and `(new DateTime())->getTimestamp() - (new DateTime($data['updated_at']))->getTimestamp() > 3600`, treat as crashed — remove `$jobDir` and return an error step. Phase 2's `import_media_zip_status.php` follows this same structure.

When `state=running`:
```json
{
  "success": true,
  "state": "running",
  "steps": [
    {
      "name": "Build archive",
      "status": "running",
      "message": "847 / 2341 files written",
      "progress": { "processed": 847, "total": 2341 }
    }
  ]
}
```

When `state=done`, includes `"ready_for_download": true` so the JS can trigger the download step:
```json
{
  "success": true,
  "state": "done",
  "ready_for_download": true,
  "steps": [
    {
      "name": "Build archive",
      "status": "ok",
      "message": "2328 file(s) written (13 skipped)",
      "progress": { "processed": 2341, "total": 2341 }
    }
  ]
}
```

---

### 7. `admin/export_media_download.php` — Download endpoint

**Auth:** `$user === 'admin'` guard.  
**Method:** GET  
**Input:** `?job_id={job_id}`

**Logic:**
1. Validate `$jobId` against `/^[a-f0-9]{16}$/`
2. Read progress JSON; confirm `state === 'done'` — return HTTP 202 `"Job not complete"` if not (covers `running` state)
3. Construct `$jobDir = sys_get_temp_dir() . '/gighive_export_' . $jobId . '/'` and confirm `is_file($jobDir . 'archive.zip')` — return HTTP 410 `"Archive no longer available"` if missing
4. Read `$filename` from `$jobDir . 'status.json'`
5. Set response headers:
   - `Content-Type: application/zip`
   - `Content-Disposition: attachment; filename="{$filename}"`
   - `Content-Length: ` + `filesize($jobDir . 'archive.zip')` — pre-built file has a known size; the browser download dialog will show a real progress indicator
   - `Cache-Control: no-cache, no-store, must-revalidate`
   - `Pragma: no-cache`
   - `Expires: 0`
6. Disable output buffering and compression (same as current `build` mode): `@ini_set('zlib.output_compression', 'off')` then `while (ob_get_level() > 0) ob_end_clean()`
7. Stream ZIP in 256 KB chunks (same `fread()` loop as current build mode)
8. After streaming: `rmdir`-tree the entire `$jobDir` directory (removes `archive.zip`, `status.json`, and the directory itself)

The JS `fetch()` download step follows the same pattern as the pre-refactor `build` response in `doExportMedia()` — stream body, collect chunks, create Blob, trigger `<a download>`.

---

## Updated `doExportMedia()` JS — Three Steps

| Step | Before Phase 1 (current state) | Phase 1 (refactored) |
|---|---|---|
| 1 — Query database | POST `prepare` → count + bytes + confirm | Unchanged |
| 2 — Build archive | POST `build` → synchronous, elapsed timer only | POST `start` → `job_id`; poll `export_media_status.php` → live per-file bar |
| 3 — Download | Stream body from the `build` response | GET `export_media_download.php?job_id=...` when `ready_for_download=true`; stream body; trigger save |
| — | N/A | `Content-Length` now known → browser download dialog shows real progress |

---

# Shared Infrastructure

## `pollJobStatus()` in `admin/assets/import_progress.js`

Extracted as a reusable helper for any async job that follows the `{ state, steps }` polling convention:

```js
/**
 * Poll a job status endpoint and optionally render steps via renderImportStepsShared().
 * @param {string}        jobId        - The job_id returned by the start endpoint
 * @param {string}        statusUrl    - URL to poll (job_id appended as ?job_id=...)
 * @param {Element|null}  stepsEl      - DOM element to auto-render steps into; pass null to skip
 * @param {Function}      onDone       - Called with (state, data) when state is done|error
 * @param {number}        [intervalMs] - Poll interval in ms (default 1500)
 * @param {Object}        [renderOpts] - Options for renderImportStepsShared when stepsEl is set
 * @param {Function}      [onProgress] - Called with (data) on every successful poll response
 * @returns {{ stop: Function }}       - Call stop() to halt polling externally
 */
function pollJobStatus(jobId, statusUrl, stepsEl, onDone, intervalMs, renderOpts, onProgress) { ... }
```

`doImportMediaZip()` calls (auto-renders into `statusEl`):
```js
resetProgressLatch(); // clear any stale latch from a prior run before starting the poll
pollJobStatus(jobId, 'import_media_zip_status.php', statusEl, (state, data) => { ... });
```

`doExportMedia()` calls (manages a 3-step array; passes `null` for `stepsEl` and uses `onProgress` to merge the archive step into the outer array and re-render):
```js
pollJobStatus(jobId, 'export_media_status.php', null, function (state, data) {
  resolve({ state: state, data: data });
}, 1500, null, function (data) {
  if (data && Array.isArray(data.steps) && data.steps.length > 0) {
    steps[1] = data.steps[0]; // merge into the 3-step steps[] array
  }
  render();
});
```

---

## Code Reuse Summary

| Component | Export (Phase 1) | Import (Phase 2) |
|---|---|---|
| Background worker spawn (`exec + &`) | `export_media_worker.php` | `import_media_zip_worker.php` |
| Progress JSON format (`state`, `processed`, `total`) | Defined in Phase 1 | Reused unchanged |
| Status endpoint shape (`{ state, steps }`) | `export_media_status.php` | `import_media_zip_status.php` |
| `job_id` validation regex | `/^[a-f0-9]{16}$/` in both | Same |
| Stale file TTL cleanup (1 hour) | `export_media_status.php` | `import_media_zip_status.php` |
| JS polling | `pollJobStatus()` shared in `import_progress.js` | Same |
| `renderImportStepsShared()` | Already in `import_progress.js` | Already in `import_progress.js` |

---

# Design Decisions Log

**File-layer only (no DB records on import)**  
The ZIP export is a file-layer backup; its restore pair is purely file-layer. DB records have their own restore path (Section B). Creating DB records from filenames alone would require guessing org/event metadata from the filename, which is error-prone and outside scope. The existing Import Media / Catalog workflows handle fresh DB ingestion.

**SHA-256 read from ZIP entry name, not recomputed from file content**  
GigHive export ZIPs use content-addressed entry names (`{sha256}.{ext}`). The import worker reads the destination path directly from the entry name — no `hash_file()` call needed. Entries whose filename does not match the 64-hex SHA-256 pattern are rejected as non-GigHive content. This guarantees idempotency: a file with that name already on disk is identical by definition and is safely skipped.

**Already-exists handling: skip silently, count in `already_exists`**  
A file with the same SHA-256 already on disk is by definition identical in content. Overwriting is wasteful and changes the `mtime`, which could affect downstream tools. Skipping is correct and makes the operation safely re-runnable.

**Extension lists from `UPLOAD_AUDIO_EXTS_JSON` / `UPLOAD_VIDEO_EXTS_JSON`**  
Single source of truth for supported file types across the entire pipeline (catalog scan, TUS upload, ZIP import). Consistent classification regardless of where files enter the system.

**Two-phase prepare + start (single upload, token handoff)**  
The operator sees what's in the ZIP before any files are written to disk — a ZIP with 8,000 unsupported `.VOB` files would waste significant extraction time without the prepare step. The ZIP is uploaded once during `prepare`; `start` takes a `prepare_token` and `rename()`s the already-saved file, avoiding a second HTTP upload. The token expires after 30 minutes, at which point the operator must re-upload.

**Progress JSON written every 10 files**  
Every-file writes would cause excessive I/O on large archives. Every-10-files provides sub-second latency (polling at 1500 ms, typical archives process well under 10 files per 1500 ms for large video files) while keeping I/O overhead negligible. The export worker's per-file `close()` already adds overhead; writing status JSON on every file would compound this unnecessarily.

**Progress JSON uses `LOCK_EX` for atomic writes**  
The `$writeStatus` closure (modelled on `iphone_import_worker.php`) calls `file_put_contents($jsonPath, ..., LOCK_EX)` on every write. This makes each write atomic with respect to concurrent readers — the status endpoint can never read a partial JSON. No special handling for malformed JSON is required, though `json_decode()` returning `null` is still treated as a fallback to the last known state for defensive completeness.

**Export entry names changed from `source_relpath` basename to `{sha256}.{ext}`**  
The old synchronous `build` mode used the file's `source_relpath` basename as the ZIP entry name (with collision dedup via `$seen` map). The new `start` mode worker uses `{sha256}.{ext}` — the hash-based name required for Phase 2 import compatibility. This is a breaking format change: ZIPs produced by the old `build` mode have `source_relpath` basenames; Phase 2's import worker validates entry names against the SHA-256 hex pattern and will count all old-format entries as `unsupported` and skip them. Operators who produced ZIPs with the old `build` mode must re-export using Phase 1's `start` mode before they can use Phase 2 import.

**Export `build` mode retained but deprecated (returns HTTP 410)**  
Allows any external tooling that POSTs to `export_media.php` with `mode=build` to receive a clear, actionable error rather than a silent hang. Can be removed in a future cleanup pass.

**`Content-Length` header added to download response in Phase 1**  
The current `build` mode intentionally omits `Content-Length` to avoid Apache `mod_proxy_fcgi` buffering the full response before streaming. With the async worker pattern the ZIP is pre-built to a temp file whose size is known — setting `Content-Length` is safe and allows the browser's native download progress indicator to work correctly.

**Section F / Section G renaming**  
Import is always shown and is the logical companion to Export (Section E). Inserting it as Section F and pushing the conditional Disk Resize to Section G requires only a heading text change with no functional impact.

---

# Known Limitations / Future Review Items

### `exec()` dependency

The background worker pattern requires `exec()` to be enabled in PHP. If `exec()` appears in `disable_functions` in `php.ini`, the start endpoint must detect this (`function_exists('exec')`) and return a clear HTTP 500 error. A fallback synchronous mode (Pattern A — elapsed timer only) could be offered as a degraded path but is not planned for Phase 2.

### Large ZIPs and Cloudflare upload limits

Import requires the entire ZIP to be uploaded as a single multipart POST. The PHP `upload_max_filesize` limit is currently `upload_max_bytes × 1.04` (set in the Dockerfile). A Cloudflare-proxied request is additionally subject to Cloudflare's body size limits. Operators importing through Cloudflare should be aware of this constraint. For now the recommended workaround is to import via a direct connection to the VM (bypassing the Cloudflare proxy). Phase 3 will address filtered/partial exports that produce smaller ZIPs.

### PHP `max_execution_time` in `import_media_zip.php` start mode

The `start` mode does minimal work (move file, write JSON, exec) and should complete in under 1 second regardless of ZIP size. The worker runs as a CLI process with `set_time_limit(0)` and is not subject to web server timeouts. However, if `exec()` is unavailable, a fallback synchronous implementation in `start` mode would be subject to the PHP web execution time limit — this is an additional reason to surface the `exec()` unavailability error clearly.

### Temp dir disk space

The import worker streams each ZIP entry directly to `/var/www/html/{type}/` using `ZipArchive::getStream()` — no temp extraction directory is needed. The only temp disk usage is the uploaded ZIP itself (already present in the job directory). The disk space check (step 7 of the worker flow) verifies the **destination volume** (`/var/www/html`) rather than temp dir. The check is advisory — disk space can change between check and extraction — but the destination is the correct place to check.

### Export worker has no disk space pre-check

The import worker checks `disk_free_space('/var/www/html')` before streaming files to disk. The export worker has no equivalent check — it builds `archive.zip` in `sys_get_temp_dir()` without verifying available space first. If temp disk is exhausted mid-build, `ZipArchive::close()` will fail and the catch block will write `state: error`. This is handled gracefully but the operator gets no early warning. A future improvement is to add a pre-scan pass in the export worker: iterate `$rows`, sum `filesize()` for existing files, and compare against `disk_free_space(sys_get_temp_dir())` before opening the first ZIP.

### Confirm dialog count vs progress bar total

The `prepare` mode counts only files actually on disk (e.g., 2,195). The `start` endpoint writes `total: count($rows)` to `status.json` (e.g., 2,341 — all DB rows). The operator approves "2,195 files" in the confirm dialog then sees the progress bar denominator as 2,341. Not a logic bug — `$processed` increments for every DB row so the bar reaches 100% — but the number mismatch may confuse operators. Resolving this cleanly requires the `start` endpoint to do its own `is_file()` pass, which duplicates `prepare`. Deferred to a future polish pass.

### Concurrent imports / exports

Multiple simultaneous jobs are safe because each job uses a unique `$jobId`-keyed temp file path. No global locking is needed. However, concurrent large export jobs can exhaust temp disk space in `sys_get_temp_dir()` (each export builds a full archive there). Concurrent imports have minimal temp impact — only the uploaded `upload.zip` sits in temp; extracted files stream directly to `/var/www/html/`. No concurrency cap is enforced in Phase 2.

### PHP and environment limits for export

| Limit | Typical default | Binds at | Notes |
|---|---|---|---|
| `memory_limit` (web, `export_media.php`) | 128 MB | ~100K–200K rows | `fetchAll()` and `json_encode($rows)` both live in memory simultaneously in `start` mode |
| `max_execution_time` (web, `prepare`) | 30 s | ~75K–100K files | `is_file()` per row ≈ 0.3 ms each; `start` mode has no `is_file()` loop so it completes in < 1 s regardless of corpus size |
| Temp disk (export archive) | Free space in `sys_get_temp_dir()` | = full corpus size | Entire ZIP is built to temp before download begins — no streaming construction |
| Worker runtime (per-file open/close) | None — `set_time_limit(0)` | > ~10K files | Central directory rewrites are O(N²): ~1 min for 5K files, ~15 min for 20K files on SSD |
| ZIP format (standard) | 4 GB / 65,535 entries | > 4 GB or > 65K files | ZIP64 auto-activates with libzip ≥ 1.0 + PHP ≥ 7.2; transparent on GigHive's stack |
| Download transfer time | Network bandwidth | > ~5 GB | 100 Mbps ≈ 90 s/GB; PHP imposes no limit once streaming begins since the file is pre-built |

**Practical binding constraint for GigHive:** temp disk space, not PHP limits. A typical installation (thousands of events, tens of thousands of files) is far below the `memory_limit` and `max_execution_time` thresholds. The `prepare` `is_file()` loop is the first PHP-imposed limit you would hit — only above ~75K files.

### Browser limits for export and import

| Limit | Applies to | Practical cap | Notes |
|---|---|---|---|
| **Blob / ArrayBuffer heap** (export download) | Export | ~1 GB safe; ~2 GB on Chrome/Firefox with ample RAM | `doExportMedia()` collects all response chunks into a JS array then calls `new Blob(chunks)` — the entire ZIP lives in browser heap simultaneously before the save dialog appears. Safari is more conservative (~1 GB). |
| **FormData upload buffer** (import prepare) | Import | Same ~1–2 GB | `FormData` reads the entire ZIP from disk into browser memory before the first byte is sent. A 1.5 GB ZIP upload requires ~1.5 GB of browser heap. |
| **`fetch()` timeout** | Both | None | `fetch()` has no built-in timeout; the browser will not abort an active, flowing transfer. A stalled or idle connection (server not sending / not receiving) may be dropped by the OS TCP stack, not the browser. |
| **File input size** | Import | No hard browser limit | `<input type="file">` imposes no size cap; the constraint is FormData buffering (above) and the server's `upload_max_filesize`. |

**Practical browser constraint:** both export download and import upload require the file to fit in the browser tab's heap. The safe cross-browser ceiling is **~1 GB**. Chrome and Firefox on machines with 16+ GB RAM handle ~2 GB reliably. Beyond this, operators should use direct VM access (`rsync`, `scp`) rather than the browser UI.

**Future mitigation (not planned for Phase 1/2):** The File System Access API (`showSaveFilePicker()`) allows streaming a fetch response directly to disk without buffering the Blob in heap — eliminating the export browser limit entirely. Streaming upload (fetch with `ReadableStream` body) would similarly eliminate the import upload buffer. Both require browser support (Chrome 86+ / Edge 86+; not supported in Firefox or Safari as of mid-2026).

### Prepare token expiry

The prep file saved during `prepare` mode expires after 30 minutes. If the operator inspects the ZIP, gets distracted, and clicks Confirm more than 30 minutes later, `start` will return HTTP 410 and the JS must prompt them to re-upload. This is intentional — indefinitely retaining uploaded ZIPs in `sys_get_temp_dir()` would accumulate disk usage silently.

---

# Long-term Architecture Considerations: Large Libraries

**The ZIP-based approach is appropriate for full-corpus libraries under approximately 20 GB.** Beyond this threshold the following problems compound:

| Library size | ZIP assessment |
|---|---|---|
| < 5 GB | Fully appropriate — current plan is suitable |
| 5–20 GB | Workable; operator should import via direct VM connection (no Cloudflare) |
| 20–100 GB | Problematic — export requires temp disk equal to archive size, timeout risk, slow single-stream download |
| > 100 GB | Wrong tool — use rsync or volume-level backup |

## Why ZIP degrades at scale

- **Temp disk requirement:** The export worker must build the entire archive before it can be streamed. A 300 GB export consumes 300 GB of temp space simultaneously.
- **No resumability:** A dropped connection at 250 GB out of 300 GB means starting the entire download over.
- **Zero compression benefit:** MP3 and MP4 are already compressed codecs. ZIP overhead adds CPU cost with essentially 0% size reduction.
- **Central directory rewrite overhead (export):** The per-file open/close approach rewrites the ZIP central directory on every `close()`. Each rewrite is proportional to the number of entries already in the archive — O(N) per file, O(N²) total. For small-to-medium archives (< ~5,000 files) this is negligible on SSD storage. For very large file counts it adds measurable overhead. Batching N files per open/close cycle (e.g., 25 files per close) would reduce this to O(N²/25) at the cost of coarser progress granularity.

## Better approaches for large libraries

**Rsync (recommended for server-to-server migration)**  
`rsync -av --checksum /var/www/html/audio/ user@dest:/var/www/html/audio/` runs incrementally, resumes automatically, verifies checksums, and skips files already present. No temp disk overhead. Requires SSH access between hosts. This is the correct tool for cross-instance migration of large libraries — not a browser-based workflow.

**`tar` piped to stdout (no temp file needed)**  
`tar cf - /audio /video` streams directly to stdout without writing a temp archive. PHP can pipe this to the HTTP response body. Eliminates the temp disk doubling problem entirely while keeping a single-stream browser download. No resumability, but removes the biggest bottleneck of the current ZIP approach. A low-risk improvement to consider for a future Phase 1 revision (export).

**Manifest-first transfer (long-term scalable approach)**  
Export generates a `manifest.json` listing all SHA-256 hashes and metadata. The operator downloads the manifest (tiny), then pulls individual files via scripted HTTP (`curl`, `aria2c`) or the existing TUS pipeline. Each file is independently resumable. This is the most robust approach for very large libraries and aligns naturally with Phase 3 selective import.

## Practical guidance for operators today

- Use Phase 1/2 ZIP import/export for libraries under ~20 GB.
- For larger libraries: use `rsync` for server-to-server moves, or use Phase 3 filter-based export (when available) to produce smaller, targeted ZIPs by org/date.
- The size guideline is documented in the Section E description on `admin_system.php` so operators encounter it before initiating a large export.

---

# Phase 3: Selective Export / Import (Future Deliverable)

**Status:** Not planned — future scope only.

Phases 1 and 2 operate on the entire media corpus. Phase 3 introduces database-criteria-driven filtering for both export and import, allowing operators to transfer targeted subsets of media rather than the full library.

## Proposed Scope

### Export (selective)

Expand the Section E export UI to support richer filter criteria drawn from the database:

| Filter | Source table | Example |
|---|---|---|
| Organisation / band name | `events.org_name` | Export all files for a single artist |
| Event date range | `events.event_date` | Export a season or year |
| File type | `assets.file_type` | Audio only, video only |
| Event type | `events.event_type` | Weddings only, bands only |
| Tagged / untagged | `taggings` | Export only AI-tagged assets |

The export worker already iterates a `$rows` result set from a DB query — Phase 3 extends the `WHERE` clause rather than changing the worker architecture.

### Import (selective)

Add an optional filter step after the `prepare` inspection: display a file list from the ZIP and allow the operator to deselect individual files or filter by extension/type before committing to `start`. The worker skips entries not in the approved set.

Alternatively, import could accept a manifest file alongside the ZIP (a `.json` sidecar listing approved SHA-256 hashes) generated by the export tool at export time — enabling hash-based selective re-import without requiring the operator to manually review the file list.

## Dependencies

- Phases 1 and 2 must be complete and stable before Phase 3 begins.
- The `prepare` response shape may need to be extended to return per-file metadata (filename, size, detected type) for the file-list UI. This is a non-breaking addition to the existing JSON response.
- No schema changes are anticipated for Phase 3 — filtering is applied at the query layer, not the storage layer.

## Out of Scope for Phase 3

- Differential sync (comparing on-disk state against DB and exporting only missing files) — this is a separate feature.
- Encrypted ZIPs — not planned.
- Streaming ZIP construction (building the ZIP on-the-fly without a temp file) — deferred indefinitely due to PHP `ZipArchive` API constraints.
