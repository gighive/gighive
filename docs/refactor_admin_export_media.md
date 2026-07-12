# Admin Export/Import Media — ZIP → tar.gz Migration

> **Document status: migration planning.**
> This document describes the current ZIP-based implementation and contains the full plan
> to migrate to tar.gz. The current production state is ZIP. The target state is tar.gz.

## Purpose

The **Export Media** feature allows an admin to download an archive of all (or a filtered subset of) media files currently on disk. The paired **Import Media** feature re-imports that archive.

Its main use cases are:

- Preserve media before destructive database operations
- Export a subset of media for a specific Event/band name
- Export only audio or only video files
- Re-import preserved files later via **Import Media (folder)**

**Current** admin layout (ZIP — being replaced):

- **System & Recovery → Section E: Export Media to ZIP**
- **System & Recovery → Section F: Import Media from ZIP**

**Target** admin layout (tar.gz — post-migration):

- **System & Recovery → Section E: Export Media Archive**
- **System & Recovery → Section F: Import Media Archive**

## Executive Summary

### Problem
The current ZIP-based media export is fragile for large corpora (>400 GB): PHP's `ZipArchive`
opens and closes the archive **once per file**, making multi-thousand-file exports extremely
slow and prone to timeout failure. The 4 GB-per-file ZIP64 limit is also a real risk for large
video files.

### What's Changing

**1. ZIP → tar.gz export (primary goal)**
Replace the per-file `ZipArchive` loop in the export worker with a single `tar` invocation.
One system call builds the entire archive regardless of file count or individual file size.
Exports that currently take tens of minutes or fail outright will complete in a fraction of
the time with no size ceiling.

**2. tar.gz import (Section F)**
Extend the import side to accept `.tar.gz` in addition to `.zip`. Legacy ZIP exports remain
fully supported for backward compatibility.

**3. `admin_media_lib.php` — shared helper library**
Extract four patterns copy-pasted across 5–42 files into a single shared include:
- Extension loading from env (`$jsonEnvArray` — currently in 5 files)
- Media entry validation (SHA-256 filename check — currently in 10 files)
- `runTar()` proc_open wrapper — centralises the array-form call, `LC_ALL=C`, pipe cleanup,
  and exit-code check that the migration needs in 3 new places
- `writeJobStatus()` — identical write pattern in all async workers

**4. Dead code removal**
Delete 110 unreachable lines in `export_media.php` (the deprecated `mode=build` ZipArchive
block) flagged by SonarQube S1763.

### Benefits

| Benefit | Detail |
|---|---|
| **Reliability** | Single `tar` call cannot partially fail mid-file-list the way the per-file ZipArchive loop can |
| **Performance** | No per-file archive open/close overhead; gzip compression runs as a stream |
| **No size ceiling** | tar.gz has no 4 GB-per-file or 65K-entry limits |
| **Security** | Centralised `runTar()` enforces array-form proc_open (injection-immune), `realpath()` containment on extraction, and pipe resource cleanup — impossible to forget at individual call sites |
| **Maintainability** | Bug fixes to entry validation or tar invocation land in one place, not 5–10 files |
| **SonarQube** | Removes S1763 dead code finding; prevents S4721 / S5042 / S2083 findings before they are introduced |

### Scope

- **11 files total**: 1 new shared lib, 9 existing PHP files, 1 test file
- No new endpoints, no schema changes, no Ansible role changes
- UI changes are label/text only (Section E and F headings, button text, file `accept` attribute)

---

## Files Touched

Numbered to match Migration Plan touch point order (Phase 0 first). Cross-reference:
Phase 0 = #1; Phase 1 = #2–5; Phase 2 = #6; Phase 3 = #7–9; Phase 4 = #10–11.

**#1 — `admin/admin_media_lib.php`** 🆕 NEW *(Phase 0 — implemented first)*
- `loadMediaExtensions()` — shared env-based extension loading (replaces copy-paste in 5 files)
- `isValidMediaEntry()` — shared SHA-256 + extension + flat-name validation (replaces copy-paste in 10 files)
- `runTar()` — array-form `proc_open` wrapper with `LC_ALL=C`, `try/finally` cleanup, exit-code return
- `writeJobStatus()` — shared `updated_at` + `json_encode` + `file_put_contents(LOCK_EX)` for all workers

**#2 — `admin/export_media_worker.php`** ✏️ Modified *(Phase 1 — heaviest change)*
- Replace per-file `ZipArchive` open/close loop with single `runTar()` invocation
- Build `audio_files.txt` / `video_files.txt` filelists; omit empty lists; zero-file pre-tar guard
- Verbose-pipe progress tracking (Option A via callback or inline); partial archive unlink on error/catch
- Write `archive_bytes` (compressed `.tar.gz` size via `filesize()`) to the `done` status JSON — used by the JS as a `Content-Length` fallback when the proxy strips that header
- `require_once admin_media_lib.php`

**#3 — `admin/export_media.php`** ✏️ Modified *(Phase 1)*
- Change generated filename extension `.zip` → `.tar.gz`
- Add `proc_open` availability check alongside existing `exec()` check
- Delete unreachable dead code (lines 200–309, deprecated `mode=build`)

**#4 — `admin/export_media_download.php`** ✏️ Modified *(Phase 1)*
- `$archivePath = $jobDir . 'archive.tar.gz'`
- `Content-Type: application/gzip`

**#5 — `admin/export_media_status.php`** ✏️ Modified *(Phase 1 — cosmetic)*
- Update hardcoded step-name fallback strings (lines 56, 77) to match worker step name

**#6 — `admin/admin_system.php` — Section E** ✏️ Modified *(Phase 1 + Phase 2)*
- **Phase 1**: JS Content-Type gate in `doExportMedia()` changed from `application/zip` → `application/gzip` (line 1286); without this the download step shows ERROR even though the server returns HTTP 200
- **Phase 1**: Download streaming progress — Apache/mod_proxy_fcgi rewrites the response as chunked transfer, stripping `Content-Length`; `contentLength` is therefore 0 and the original `if (contentLength > 0)` guard prevents all mid-stream updates, leaving the UI at a static "Receiving…". Fix: read `archive_bytes` from the done status JSON (written by the worker) as `archiveBytes`; set `effectiveLength = contentLength || archiveBytes`; streaming loop always updates — shows `X / Y` with a real progress bar when size is known, `X received…` every 5 MB when neither source is available
- **Phase 2**: Heading `Export Media to ZIP` → `Export Media Archive`; description "Download a ZIP" → "Download a tar.gz archive" and "the ZIP and DB backup" → "the archive and DB backup"; button `Download ZIP` → `Download Archive`; JS in-progress `'Building ZIP…'` → `'Building archive…'`; `finally` reset `'Download ZIP'` → `'Download Archive'`

**#7 — `admin/import_media_zip.php`** ✏️ Modified *(Phase 3)*
- Replace `pathinfo()` extension check with `str_ends_with()` to correctly handle `.tar.gz`
- Format-aware prepare-token temp path (`.zip` vs `.tar.gz`); try both extensions in `mode=start`
- Write `format.txt` to job dir (start mode only — job dir does not exist in prepare mode)
- `tar tzvf` prepare-mode entry scan via `runTar()` for tar.gz uploads; 50,000 entry cap applied to both paths
- `proc_open` availability guard before the tar.gz prepare branch (ZIP path uses `ZipArchive` which is always available)
- `mode=start` uses `copy() + @unlink()` instead of `rename()` for safety (tmpfs → job dir are same FS, but cross-device safety is consistent with worker pattern)
- `require_once admin_media_lib.php`

**#8 — `admin/import_media_zip_status.php`** ➡️ No change *(Phase 3)*
- Reads `status.json` generically; format-transparent; stale detection covers both file types

**#9 — `admin/import_media_zip_worker.php`** ✏️ Modified *(Phase 3)*
- Read + strictly validate `format.txt` (allowlist: `zip` or `tar.gz`)
- tar.gz branch: pre-scan with `tar -tzvf` to get `$total` denominator; **rejects entire archive** if any entry name contains `..` (`throw RuntimeException`) — defence-in-depth before extraction; array-form `runTar()` with `--directory`; `realpath()` containment check on every extracted file; extraction subdir cleanup in `try/finally`
- Known limitation: `$unsupportedCount` is incremented in both the pre-scan and the post-extraction glob, so non-media entries are counted twice in the summary message for tar.gz imports. Informational only — no functional impact.
- Disk space check (present in ZIP branch via `ZipArchive::statIndex` sizes) is absent from the tar.gz branch; Apache `upload_max_filesize` acts as the upload gate
- ZIP branch unchanged (backward compatibility)
- `require_once admin_media_lib.php`

**#10 — `admin/admin_system.php` — Section F** ✏️ Modified *(Phase 3 partial + Phase 4)*
- **Phase 3 (pre-fix)**: `accept=".zip,.tar.gz,.tgz"` on file input; label text `ZIP file` → `Archive file (.zip or .tar.gz)`; no-file guard message updated
- **Phase 4**: Heading `Import Media from ZIP` → `Import Media Archive`; description "GigHive export ZIP" → "GigHive export archive (.tar.gz or .zip)"; button `Import ZIP` → `Import Archive`; JS in-progress `'Uploading ZIP…'` → `'Uploading…'`; step names `'Upload ZIP'`/`'Inspect ZIP'` → `'Upload archive'`/`'Inspect archive'`; `finally` reset `'Import ZIP'` → `'Import Archive'`

**#11 — `ansible/roles/playwright_admin_tests/files/tests/admin-pages.spec.ts`** 🧪 Test *(Phase 4)*
- Line 71 comment: "Section E: Export Media to ZIP" → "Section E: Export Media Archive"

## Implementation Plan Summary

What we are doing to move from ZIP to tar.gz, in order:

**Phase 0 — Shared library (no behaviour change)**
- Create `admin/admin_media_lib.php` with four shared functions: `loadMediaExtensions()`, `isValidMediaEntry()`, `runTar()`, `writeJobStatus()`
- Wire `require_once` into `import_media_zip.php` and `import_media_zip_worker.php`, replacing their inline copies
- Replace the `$writeStatus` closure in `import_media_zip_worker.php` with `writeJobStatus()`
- Decide Option A (stdout-pipe progress callback) vs Option B (file-size polling) before coding — choice determines `runTar()` signature
- ✅ Gate: full Playwright suite + existing ZIP import passes with no visible change

**Phase 1 — Export side: produce tar.gz instead of ZIP**
- `export_media_worker.php`: replace `ZipArchive` per-file loop with a single `runTar()` call; build `audio_files.txt` / `video_files.txt` filelists; add zero-file guard; add partial-archive unlink on error; replace `$writeStatus` closure with `writeJobStatus()`
- `export_media.php`: change output filename to `.tar.gz`; add `proc_open` availability check; delete 110 lines of unreachable dead code (`mode=build`)
- `export_media_download.php`: point at `archive.tar.gz`; change `Content-Type` to `application/gzip`
- `admin_system.php`: fix JS Content-Type gate in `doExportMedia()` — `startsWith('application/zip')` → `startsWith('application/gzip')` (download step shows ERROR without this even when server returns HTTP 200); add `archive_bytes`-based `effectiveLength` fallback for streaming progress (proxy strips `Content-Length`)
- `export_media_status.php`: update two hardcoded step-name fallback strings
- ✅ Gate: export downloads as tar.gz; flat `<sha256>.<ext>` entries confirmed; progress updates mid-build; >4 GB file exports without truncation; worker failure leaves no partial archive
- ✅ Download streaming progress: live `X / Y` progress bar confirmed working via `archive_bytes` fallback
- ⚠️ Deploy Phase 1 and Phase 3 together — a tar.gz export cannot be re-imported until Phase 3 is live

**Phase 2 — UI text: Section E (export side)**
- `admin_system.php` Section E: update heading, description, button text, and in-progress label from "ZIP" → "Archive"
- ✅ Gate: admin page shows updated labels; Playwright suite passes

**Phase 3 — Import side: accept tar.gz in addition to ZIP**
- `import_media_zip.php`: `str_ends_with()` extension detection; format-aware temp path; write `format.txt` (always `tar.gz`, never `tgz`); `tar -tzvf` pre-scan via `runTar()`; 50k entry cap; `proc_open` guard; `copy()+@unlink()` in `mode=start`
- `import_media_zip_worker.php`: strict `format.txt` validation; tar.gz branch — pre-scan rejects `..` entries; `runTar(['tar','-xzvf',...,'--directory',...])` extraction; `realpath()` containment check; `try/finally` extraction subdir cleanup; `copy()+unlink()` cross-device move; ZIP branch unchanged
- `import_media_zip_status.php`: no changes needed
- `admin_system.php` Section F: `accept=".zip,.tar.gz,.tgz"` added as immediate Phase 3 unblock (file picker defaulted to `.zip` only, making tar.gz exports unselectable)
- ✅ Gate confirmed: tar.gz import of 15 files (10 audio + 5 video, 451.5 MB); idempotent re-import skips duplicates; legacy ZIP import unchanged

**Phase 4 — UI text: Section F (import side) + test comment**
- `admin_system.php` Section F: heading, description, button, JS step names, in-progress text (see #10 above); `accept` already done in Phase 3
- Playwright spec line 71: comment updated
- ✅ Gate: full Playwright end-to-end suite passes with all updated labels

> **Where to find the detail:**
> - **Per-file change specs** → [Migration Plan: ZIP → tar.gz](#migration-plan-zip--targz) → Touch Points #1–9
> - **Files changed, with change summaries** → [Files Touched](#files-touched) (#1–11, numbered to match touch points)
> - **Test gates per phase** → [Implementation Phases](#implementation-phases)
> - **Security / SonarQube requirements** → [Coding Best Practices and SonarQube Considerations](#coding-best-practices-and-sonarqube-considerations)
> - **Full test checklist** → [Testing Checklist](#testing-checklist)

---

## High-Level Flow (Current — Async Worker)

The export is split into four observable stages:

1. **Query database** (`prepare`) — discover what would be exported and the total size
2. **Build archive** (`start` + async worker) — PHP CLI worker builds the archive in the background
3. **Poll progress** — browser polls `export_media_status.php` until `state=done`
4. **Download** — browser fetches the pre-built archive from `export_media_download.php`

The browser never holds a long-lived HTTP connection open during archive construction.
The async worker runs as a detached PHP CLI process, writes per-file progress to `status.json`,
and the browser polls that file until the worker signals `done`.

### Why async matters

For a synchronous build-and-stream approach (the old deprecated `mode=build`), the browser must
keep an HTTP connection open for the entire construction time. Any proxy timeout, sleep, or tab
close kills the transfer mid-build. The async worker model decouples archive construction from the
download step entirely.

## UI Behavior

### Entry Point

The export UI is rendered in `admin_system.php` as Section E.

Inputs:

- `export_org_name`
  - free-text filter for `events.org_name`
  - blank means export all matching media
- `export_file_type`
  - `all`
  - `audio`
  - `video`

Action button:

- `exportMediaBtn`
  - disabled during the operation
  - restored to `Download ZIP` when finished or failed *(current — changes to `Download Archive` in Phase 2)*

Status container:

- `exportMediaStatus`
  - rendered using the shared progress UI (`renderImportStepsShared()`)

### Progress Display

The feature uses `renderImportStepsShared()` to display steps matching the async flow:

- **Query database**
- **Build archive** (per-file progress from worker)
- **Download**

### Confirmation Dialog

After `prepare` succeeds, the UI shows a native `confirm()` dialog before starting the worker.

If the admin clicks **Cancel**:

- the worker is never spawned
- the Build step is left in a canceled state
- the Download step remains pending

## Frontend Request Sequence

The client code lives in `doExportMedia()` inside `admin_system.php`.

### Step 1: Prepare

```text
POST /admin/export_media.php
mode=prepare
org_name=<value>
file_type=<all|audio|video>
```

Response on success:

```json
{
  "success": true,
  "count": 123,
  "skipped": 4,
  "total_bytes": 987654321
}
```

The UI marks Query complete and shows a summary such as:

```text
123 file(s) ready to export (941.9 MB)
```

### Step 2: Start async worker

If the admin confirms, the browser sends:

```text
POST /admin/export_media.php
mode=start
org_name=<value>
file_type=<all|audio|video>
```

The server:

- re-validates all rows that exist on disk (mirrors prepare)
- creates a job directory: `sys_get_temp_dir()/gighive_export_<jobId>/`
- writes `filelist.json` with the filtered row set
- writes initial `status.json`
- spawns the worker via `exec('php export_media_worker.php --job_id=<id> >> worker.log 2>&1 &')`
- returns immediately with `job_id` and `total`

```json
{ "success": true, "job_id": "a1b2c3d4e5f6a7b8", "total": 123 }
```

### Step 3: Poll progress

The browser calls `pollJobStatus()` against:

```text
GET /admin/export_media_status.php?job_id=<id>
```

The status endpoint reads `status.json` written by the worker and returns:

```json
{
  "success": true,
  "state": "running",
  "steps": [
    {
      "name": "Build archive",
      "status": "running",
      "message": "45 / 123 written",
      "progress": { "processed": 45, "total": 123 }
    }
  ]
}
```

When `state=done`, the response also includes `"ready_for_download": true`.

Stale detection: if `state=running` and `updated_at` is more than 3600 seconds old, the status
endpoint treats the job as failed, cleans up the job directory, and returns an error state.

### Step 4: Download

Once polling detects `state=done`, the browser fetches:

```text
GET /admin/export_media_download.php?job_id=<id>
```

The download endpoint:

- validates the job is `done`
- sets `Content-Type: application/zip` *(current — changes to `application/gzip` in Phase 1)*, `Content-Length`, `Content-Disposition`
- streams `archive.zip` *(current — changes to `archive.tar.gz` in Phase 1)* in 256 KB chunks
- cleans up the entire job directory after streaming

Because the archive is pre-built, `Content-Length` is available and accurate.

## Backend: `export_media.php`

### Access Control

Only the Basic Auth `admin` user may call this endpoint. Returns HTTP 403 otherwise.
Only accepts `POST`.

### Input Parameters

- `org_name` — optional band/event filter
- `file_type` — `all` | `audio` | `video`; invalid values fall back to `all`
- `mode` — `prepare` | `start`; `build` is deprecated (returns HTTP 410)

### Database Query

Joins canonical tables:

- `assets`
- `event_items`
- `events`

Selects distinct assets: `asset_id`, `checksum_sha256`, `file_type`, `file_ext`, `source_relpath`.

Optional filters: exact `events.org_name = :org_name` when provided;
`assets.file_type` when audio-only or video-only is requested.

Export is based on canonical asset/event relationships, not a raw directory listing.
DB records with no matching on-disk file are skipped. Files with no DB record are not exported.

### Disk Locations

- audio: `/var/www/html/audio`
- video: `/var/www/html/video`

On-disk filename: `<sha256>.<ext>` (or `<sha256>` when no extension).

## Backend: `export_media_worker.php`

PHP CLI only (`PHP_SAPI !== 'cli'` guard).

Accepts `--job_id=<16-hex-char>`.

Reads `filelist.json` from the job directory (then deletes it), builds the archive file,
and writes `status.json` every 10 files.

On success, writes `state=done`. On failure, writes `state=error` with `error_message`.

### Current Archive Construction (ZIP — known performance issue)

The worker currently uses `ZipArchive` with `set_time_limit(0)`.

**Known bug**: the worker opens and closes `ZipArchive` once per file (open → addFile → close
inside the loop). This rewrites the ZIP central directory on every iteration. For a corpus with
thousands of files this is extremely slow and wastes significant I/O. This is a known issue
targeted for fix as part of the tar.gz migration (see Migration Plan below).

Archive entry naming: `<sha256>.<ext>` (hash-based, import-compatible with Section F).

## Backend: `export_media_status.php`

GET endpoint. Admin-only.

Reads `sys_get_temp_dir()/gighive_export_<jobId>/status.json`.

Returns the `state`, `steps` array, and `ready_for_download: true` when done.

Stale detection at 3600 s with automatic job directory cleanup.

## Backend: `export_media_download.php`

GET endpoint. Admin-only.

Streams the pre-built `archive.zip` *(current — changes to `archive.tar.gz` in Phase 1)* to the browser in 256 KB chunks with `Content-Length` set.
Cleans up the entire job directory after streaming completes.

## Section F — Import Media from ZIP

The paired import feature in `admin_system.php` (Section F) accepts a `.zip` upload and extracts
it back onto the server media volumes.

Flow is also async:
1. **Upload ZIP** — XHR with upload progress
2. **Prepare** — server validates the uploaded archive
3. **Start** — spawns `import_media_zip_worker.php`
4. **Poll** — browser polls `import_media_zip_status.php`

The import worker processes only files with SHA-256 hash names and supported extensions.
Files already present on disk are skipped (idempotent).

## Error Handling

### Frontend

- network failure or JSON error on prepare
- admin cancels confirmation dialog
- network failure on start
- poll timeout / stale job detection
- network failure on download

### Backend

- unauthorized user (403)
- wrong HTTP method (405)
- DB connection failure (500)
- query failure (500)
- no matching DB rows (404)
- no exportable files on disk (404)
- `exec()` disabled (500)
- `proc_open()` disabled (500) *(added by Phase 1 — guards worker dependency)*
- job directory creation failure (500)
- `filelist.json` write failure (500)
- worker: archive open/write failure (error state in status.json)
- download: job not done (202), archive missing (410)

## Permissions and Runtime Requirements

For **export** to work, the web process must be able to:

- read files under `/var/www/html/audio` and `/var/www/html/video`
- create and write to directories under `sys_get_temp_dir()`
- call `exec()` to spawn the PHP CLI worker
- call `proc_open()` (added by Phase 1)

Export does **not** require write access to the media directories themselves.

For **import** to work, the worker process must be able to:

- write files to `/var/www/html/audio` and `/var/www/html/video` (existing requirement, unchanged)
- create a temp extraction subdir inside the import job dir under `sys_get_temp_dir()`
- call `proc_open()` for `tar xzf` extraction (tar.gz path only)

In containerized deployments, both media directories must be read **and write** accessible by
`www-data`. In production (Orange Pi), media volumes are typically bind-mounted; confirm
`www-data` has write permission on the mount point.

## Operational Notes

### Intended Use Before Destructive Actions

Common workflow:

1. Export media from Section E (and database backup from Section C)
2. Perform destructive DB/media operations
3. Re-import preserved files via Section F or Import Media (folder)

### Exact Matching on Event/Band Filter

The `org_name` filter uses exact equality (`e.org_name = :org_name`).
The admin must supply the stored band/event name exactly as it exists in the database.

### Guideline for Current Implementation

The current implementation (ZIP, async worker) is suitable for small-to-medium libraries.
The admin_system.php UI already notes an informal guideline of under 20 GB; rsync or
direct volume backup is recommended for larger stores.

---

## Format Analysis: ZIP vs tar.gz

The current archive format is ZIP (`ZipArchive`). This section documents the rationale for
migrating to tar.gz.

### ZIP limitations at scale

| Concern | ZIP (current) | tar.gz |
|---|---|---|
| **Per-file size limit** | 4 GB without ZIP64 (ZIP64 not currently enabled) | No per-file limit |
| **Archive construction speed** | Very slow due to per-file open/close bug (see above) | Single `tar` invocation — no repeated central-directory writes |
| **Temp space needed** | Full staged archive in `sys_get_temp_dir()` | Same (must stage for async model); no difference |
| **Compression CPU** | Per-entry; media files already compressed — wasted CPU | `tar cf archive.tar` (no compression) or `tar czf archive.tar.gz` (single gzip pass) |
| **Connection timeout risk** | Solved by async model | Solved equally by async model |
| **Resumability** | None | None (same) |
| **Corruption on truncation** | Unusable (central directory at end) | Unusable |
| **Import tooling** | PHP `ZipArchive` | shell `tar` (array-form `proc_open`) — `PharData` is not used |
| **Standard Unix interop** | Requires unzip | Requires tar (universal) |

### Why tar.gz is preferred

- Eliminates the 4 GB per-file ZIP limit without needing to explicitly enable ZIP64.
- Replaces the per-file open/close `ZipArchive` loop with a single `tar` invocation — dramatically faster for large corpora.
- Standard Unix format; no specialist tool needed on the receiving end.
- Compression can be disabled (omit the `z` flag: `tar cf archive.tar --files-from=...`) for media-heavy exports since audio/video files are already compressed, saving CPU with no size penalty.

### What tar.gz does NOT solve

- Temp disk space requirement is identical (async model must stage a complete file before serving the download link).
- Connection timeout during download is not a new risk (the async model already decouples build from browser session for both formats).
- Resumable downloads are not implemented for either format.

---

## Migration Plan: ZIP → tar.gz

### Scope

Changes span existing PHP files, the admin UI, and one new shared library.
No new endpoints, no schema changes, no Ansible role changes are needed.

### Critical Constraint: Flat Entry Names Required

The import worker (`import_media_zip_worker.php`) rejects any archive entry whose name
contains a path separator (`strpos($name, '/') !== false`). Entry names must be flat:
`<sha256>.<ext>` with no directory prefix.

This means `tar --files-from` with absolute paths is **not usable as-is** — it would
produce entry names like `/var/www/html/audio/abc123.mp4`, which the import side would
silently skip. The tar invocation must produce flat entry names via one of:

- **Preferred**: two separate `-C` + `--files-from` pairs:
  ```
  tar -czf archive.tar.gz \
    -C /var/www/html/audio --files-from=audio.txt \
    -C /var/www/html/video --files-from=video.txt
  ```
  Where `audio.txt` and `video.txt` contain bare filenames (`<sha256>.<ext>` only, one per line).
  GNU tar applies the current `-C` base to each subsequent `--files-from`.

- **Alternative**: GNU tar `--transform` to strip directory prefixes:
  ```
  tar --transform='s|.*/||' -czf archive.tar.gz /var/www/html/audio/*.mp3 ...
  ```
  This is GNU tar-specific. Since the container base is Ubuntu, GNU tar is available, but
  this is less portable than the `-C` approach.

The `-C` approach is preferred: it is explicit, POSIX-compatible, and produces exactly the
flat naming that the import worker expects.

### Touch Points

#### 1. `export_media_worker.php`

- Replace the `ZipArchive` per-file open/close loop with a single `tar` invocation via
  `proc_open`.
- Build two plaintext filelists in the job directory: `audio_files.txt` and `video_files.txt`,
  each containing bare filenames (`<sha256>.<ext>`, one per line) from the filtered row set.
  These are not mappings — just bare filenames relative to their respective `-C` base dirs.
  **Source**: the worker reads `filelist.json` (written by `mode=start`) to get the file rows;
  `audio_files.txt` / `video_files.txt` are derived from it inside the worker.
- Run tar as:
  ```
  tar -czf /tmp/.../archive.tar.gz \
    -C /var/www/html/audio --files-from=/tmp/.../audio_files.txt \
    -C /var/www/html/video --files-from=/tmp/.../video_files.txt
  ```
  If one of the lists is empty (audio-only or video-only export), omit that `-C` pair entirely
  to avoid a tar error on an empty `--files-from`.
- Change `$zipPath = $jobDir . 'archive.zip'` → `$archivePath = $jobDir . 'archive.tar.gz'`.
- Update all `status.json` writes to reference `archive.tar.gz`.
- `set_time_limit(0)` stays.
- `proc_open` check: `proc_open` is always available in PHP CLI — `disable_functions` from
  the web `php.ini` does not apply to CLI. No runtime check is needed in the worker.
  The check for `exec()` already in `export_media.php` (mode=start) is sufficient to gate
  the feature; no additional guard is required inside the worker.
- **Partial archive cleanup on error**: The catch block currently only writes to `status.json`.
  After this migration, a failed `proc_open`/tar run may leave a partial `archive.tar.gz` in
  the job dir. The catch block and any early `exit(1)` after archive creation begins must
  call `@unlink($archivePath)` to avoid wasting disk until the 1-hour stale cleanup fires.
- **Filelist cleanup**: `audio_files.txt` and `video_files.txt` contain bare server-side
  filenames and should not persist indefinitely. `@unlink()` both files inside the same
  `try/finally` as the archive, immediately after the tar call returns (success or failure).
  The job dir stale cleanup would eventually remove them, but explicit unlink is cleaner.
- **Zero-file guard before tar invocation**: If both `audio_files.txt` and `video_files.txt`
  are empty (zero exportable rows), no `-C` pairs would be added and tar would be called with
  no files. Detect this before calling tar and exit with `'No exportable files found on disk'`
  — consistent with the current `$added === 0` check.

#### Progress Tracking for Single `tar` Invocation

Replacing the per-file loop with a single `tar` call makes per-file progress tracking harder.
Two options:

**Option A — `--verbose` pipe parsing (more granular)**: Open `proc_open` with `tar --verbose`
and read from the **stdout pipe** (`$pipes[1]`). When the archive is written to a file
(`-czf archive.tar.gz`), GNU tar sends the verbose filename listing to **stdout**. Stderr
(`$pipes[2]`) carries only error messages. Each stdout line is a filename just added;
increment a counter per line and write `status.json` every 10 lines. This gives the same
per-file progress as the current ZIP implementation.

> Note: if the archive were written to stdout (`-czf -`), verbose output would move to
> stderr instead. This migration always writes to a file, so stdout is the correct pipe.

**Option B — archive size polling (simpler)**: Write the filelist, launch `tar` as a
background process, then poll the growing `archive.tar.gz` file size against the expected
total source bytes (already known from `prepare`). Write `status.json` every poll interval.
This gives approximate byte-level progress with no pipe parsing.

**Compatibility note**: `runTar()` as designed in `admin_media_lib.php` buffers all output
and only returns after `proc_close()`. This is incompatible with Option A, which requires
reading stdout line-by-line *in real time while tar is running*.

Two ways to resolve:
- **Extend `runTar()`**: add an optional `callable $onStdoutLine = null` parameter. The
  wrapper calls the closure for each line read from the stdout pipe before `proc_close()`.
  This keeps all proc_open boilerplate centralised and is the preferred approach.
- **Inline proc_open in the worker**: skip `runTar()` for the export case and open proc_open
  directly with a progress-reading loop. `runTar()` is still used for import (where real-time
  streaming is not needed).

If the callback extension is too complex, prefer Option B — it uses `runTar()` cleanly.

#### 2. `export_media.php` (mode=start)

- Change the generated filename from `.zip` to `.tar.gz`:
  `$filename = 'gighive_export_' . $labelPart . $typePart . '_' . date('Ymd_His') . '.tar.gz';`
- Add a `proc_open` availability check here (alongside the existing `exec()` check) since
  the worker will use `proc_open`. `function_exists('proc_open')` — return 500 if disabled.
- `filename` already written to `status.json` initial payload — just the extension changes.
- No other changes needed in this file.

#### 3. `export_media_download.php`

- Change `$zipPath = $jobDir . 'archive.zip'` → `$archivePath = $jobDir . 'archive.tar.gz'`.
- Change `Content-Type` from `application/zip` to `application/gzip`.
- `Content-Disposition` filename comes from `status.json['filename']` — no hardcoded change
  needed there; it already carries `archive.tar.gz` from the updated `export_media.php`.
- `Content-Length` stays (pre-built file, exact size is known).
- No streaming logic changes needed.

#### 4. `export_media_status.php`

- **Keep step name as `'Build archive'`** (unchanged). The migrated worker must continue to
  write `'Build archive'` as the step name in `status.json` — do not change it. This avoids
  any frontend JS impact (the UI reads step names for display) and means lines 56 and 77 in
  `export_media_status.php` require no update at all.

#### 5. `admin_system.php` — Section E (Export)

- Update section heading: `Section E: Export Media to ZIP` → `Section E: Export Media Archive`
- Update description text to reference tar.gz instead of ZIP.
- Update button text: `Download ZIP` → `Download Archive`.
- Update `btn.textContent = 'Building ZIP…'` in `doExportMedia()` → `'Building Archive…'`.
- Update button restore text accordingly.

#### 6. `import_media_zip.php` — prepare mode and start mode

Several hardcoded assumptions must change:

- **Extension check (critical)**: Line 74 uses `pathinfo($origName, PATHINFO_EXTENSION) !== 'zip'`.
  `pathinfo('archive.tar.gz', PATHINFO_EXTENSION)` returns `gz`, not `tar.gz` — this silently
  rejects all tar.gz uploads. Replace with explicit suffix logic:
  ```php
  $lowerName = strtolower($origName);
  $isTarGz   = str_ends_with($lowerName, '.tar.gz') || str_ends_with($lowerName, '.tgz');
  $isZip     = str_ends_with($lowerName, '.zip');
  if (!$isZip && !$isTarGz) { /* reject */ }
  ```
- **Prepare token temp file path**: Currently hardcoded as `.zip`:
  `'/gighive_zip_prepare_' . $prepareToken . '.zip'`. Change to carry the format, e.g.:
  `'/gighive_zip_prepare_' . $prepareToken . ($isTarGz ? '.tar.gz' : '.zip')`.
  The `mode=start` handler must reconstruct the same path — simplest approach is to try
  both extensions (`.tar.gz` then `.zip`) and use whichever exists.
- **`format.txt` (start mode only)**: When `mode=start` creates the job dir and moves the
  prepared file in, it must also write `format.txt` to the job dir so the worker knows which
  branch to take. This cannot happen in prepare mode because the job dir does not exist yet at
  that point. **The value must always be `tar.gz` (never `tgz`) even when the uploaded file
  has a `.tgz` extension** — the worker allowlist validates against `zip` or `tar.gz` only;
  writing `tgz` would cause a hard error in the worker.
- **Zip bomb guard**: The current guard sums `ZipArchive::statIndex()['size']` (uncompressed
  bytes) per entry and aborts if the total exceeds `2× upload_max_filesize`. For tar.gz, there
  is no direct PHP equivalent without extracting. Options:
  - Gate at Apache upload size only (`LimitRequestBody` / `upload_max_filesize`) and skip the
    uncompressed-content check for tar.gz (acceptable since media files are already compressed
    and ratio is ~1:1).
  - Run `tar tzvf <path>` via `proc_open` during prepare, parse sizes from output, and abort
    if the sum exceeds the threshold.
  The Apache-limit-only option is the simpler path for initial implementation.
- **`ZipArchive` entry scan (prepare mode)**: The entry pre-scan (counting valid media entries
  and reporting `audio_count`, `video_count`) uses `ZipArchive` APIs. For tar.gz, replace with
  a `runTar(['tar','tzvf',...])` call (array form) and parse the verbose listing to count and
  sum entries. Entry validation rules are unchanged: flat name, 64-char hex stem, known media
  extension. **Apply the same `MAX_ZIP_ENTRIES` (or equivalent) entry count cap to tar.gz** —
  the guard exists in the ZIP path to prevent exhausting resources on malicious archives and
  must carry over.
- **`proc_open` availability check (tar.gz path only)**: The prepare-mode entry scan for tar.gz
  calls `runTar()`, which uses `proc_open`. Add `function_exists('proc_open')` guard before
  the tar.gz branch: if disabled, return an error response rather than a fatal. ZIP uploads are
  unaffected — they use `ZipArchive` which is always available.

#### 7. `import_media_zip_status.php`

- No changes required. Reads `status.json` generically; archive format is transparent to this
  endpoint. Stale detection uses `glob($jobDir . '*')` which covers both `upload.zip` and
  `upload.tar.gz` without modification.

#### 8. `import_media_zip_worker.php` — worker

- **Archive filename**: The worker hardcodes `$zipPath = $jobDir . 'upload.zip'`. For tar.gz
  imports, the start mode saves the file as `upload.tar.gz`. The worker reads `format.txt`
  (written by `mode=start` — see touch point 6) on startup and branches on its value.
- **Extraction logic**: For ZIP, the worker uses `ZipArchive::getStream()` to stream entries
  directly to destination without a full extract. For tar.gz, use array-form `proc_open` (via
  `runTar()`) to extract into a temp subdirectory within the job dir, then iterate the extracted
  files, validate each by filename, and `rename()` or `copy()` to the audio/video destination.
  This avoids `PharData` entirely (simpler, more reliable). Do **not** use string-form
  `proc_open('tar xzf ...')` — it violates S4721.
- **Entry validation after extraction**: After extracting to a temp subdir, apply the same
  validation: `basename($file)` must match `^[a-f0-9]{64}\.(ext)$` with no subdirectory.
  The current check `strpos($name, '/') !== false` maps to checking that the file lives
  directly in the temp subdir, not in a nested folder.
- **Disk space check**: The `ZipArchive`-based disk space check uses uncompressed sizes from
  `statIndex()`. For tar.gz, use the pre-extraction file count and the upload file size as a
  proxy (since media is already compressed, compressed ≈ uncompressed). Or run `tar tzvf`
  first and sum sizes. Align approach with whatever is done in the prepare step.
- **Idempotency**: Unchanged — check `is_file($dest)` before copying.
- **Cross-device move**: Do **not** use `rename()` to move extracted files to the audio/video
  destination. If `sys_get_temp_dir()` (`/tmp`) and `/var/www/html/audio` (or `/video`) are on
  different filesystem mounts — as is likely when media lives on a separate Docker volume or
  bind-mount from a large storage volume — `rename()` fails with EXDEV (cross-device link)
  and returns `false` silently. Always use `copy($src, $dest) + unlink($src)` for the move.
- **ZIP backward compatibility**: ZIP import path stays entirely intact. The branch on `format.txt`
  (or equivalent) selects which code path runs.
- **Temp extraction subdir cleanup**: After file validation and moves (success or failure), the
  temp extraction subdir created by `tar xzf --directory` must be recursively deleted — it is
  separate from the job dir. A `try/finally` or explicit cleanup on all exit paths is required.
  The overall job dir cleanup (stale detection + download endpoint) does not reach nested dirs
  created inside it without `glob($jobDir . '**')` — don't rely on it.

#### 9. `admin_system.php` — Section F (Import)

- Update section heading: `Section F: Import Media from ZIP` → `Section F: Import Media Archive`
- Update `accept=".zip"` on the file input to `accept=".zip,.tar.gz,.tgz"`.
- Update description text to reference both ZIP (for legacy exports) and tar.gz.
- Update button text: `Import ZIP` → `Import Archive`.
- Update `btn.textContent = 'Import ZIP'` in `doImportMediaZip()` finalize block similarly.

### Coding Best Practices and SonarQube Considerations

All issues below must be addressed during implementation. They are categorised using the
project's established SonarQube rule references (see `docs/security_sonarqube_recommendations.md`).

#### 🔴 Security — Critical

**S5042 / CWE-22 (Zip Slip) — archive extraction path traversal**

`tar xzf upload.tar.gz` honours absolute paths and `../` traversal sequences embedded in
archive entry names. A crafted `.tar.gz` could write to `/etc/passwd`, overwrite system files,
or escape the temp extraction directory entirely.

Mitigations required in `import_media_zip_worker.php`:
- Use `--directory=$extractDir` in the array-form `proc_open` call to confine extraction to a
  specific temp subdir. GNU tar already strips leading `/` from member names by default (warns
  and continues). **`--no-absolute-paths` is not a valid GNU tar extraction flag — do not use it.**
- After extraction, apply a `realpath()` containment check on every extracted file:
  `str_starts_with(realpath($extractedFile), realpath($extractDir) . '/')`. Reject (unlink +
  abort) any file whose resolved path falls outside the temp subdir — this is the primary
  defence against `../` traversal entries in the archive.
- Only then validate the filename and move to the audio/video destination.

This is the same family of vulnerability as Zip Slip in ZIP archives — it applies equally to tar.

**S4721 — OS command injection on `proc_open` calls**

The existing `exec()` call in `export_media.php` line 188 correctly uses `escapeshellarg()` on
every argument. New `proc_open` calls introduced by this migration must use the **array form**:

```php
proc_open(
    ['tar', '-czf', $archivePath,
     '-C', '/var/www/html/audio', '--files-from', $audioListPath,
     '-C', '/var/www/html/video', '--files-from', $videoListPath],
    $descriptors,
    $pipes
)
```

Array form is completely immune to command injection — no shell interpretation occurs.
Using a shell string form (`'tar -czf ' . $archivePath . ' ...'`) requires `escapeshellarg()`
on every variable path and is strongly discouraged for new code. Array form is the required
approach for all new `proc_open` calls in this migration.

The same applies to `tar tzvf` calls in `import_media_zip.php` prepare mode and
`tar xzf` calls in `import_media_zip_worker.php`. The extraction call should look like:

```php
proc_open(
    ['tar', 'xzf', $jobDir . 'upload.tar.gz', '--directory', $extractDir],
    $descriptors, $pipes
)
```

#### 🟡 Medium

**Unchecked return values of `proc_open` and `proc_close`**

`proc_open()` returns `false` on failure (e.g., `tar` binary not found, env misconfiguration).
Any subsequent `fread()` or `fwrite()` on a `false` handle silently does nothing. All callers
must check `$handle !== false` immediately after `proc_open()` and fail explicitly.

`proc_close($handle)` returns the tar process exit code. A non-zero exit means tar failed
(disk full, a source file disappeared, I/O error, etc.). The plan must check this return value
and write an error state to `status.json` if the exit code is non-zero, rather than silently
marking the job as done.

**Resource leak from `proc_open` pipes**

All `$pipes[]` streams and `$handle` from `proc_open` must be closed in every exit path —
success, explicit error returns, and exceptions. Use a `try/finally` block:

```php
try {
    // pipe read loop
} finally {
    if (is_resource($pipes[1])) fclose($pipes[1]);
    if (is_resource($pipes[2])) fclose($pipes[2]);
    if (is_resource($handle))   proc_close($handle);
}
```

For stdin (`$pipes[0]`): use `0 => ['file', '/dev/null', 'r']` in the descriptor array rather
than `['pipe', 'r']`. This means tar never gets a piped stdin (it reads from files), and
`$pipes[0]` is not created — eliminating one resource to track. If stdin is piped for any
reason, add `if (is_resource($pipes[0])) fclose($pipes[0]);` before `proc_close()`.

Failing to close pipes causes file descriptor exhaustion under concurrent exports and is
flagged as a resource leak.

**S1763 — Dead code in `export_media.php`**

Lines 200–309 of `export_media.php` are completely unreachable. The `mode=build` deprecation
block exits at line 198 (`exit`), making the entire original `ZipArchive` build code dead.
SonarQube S1763 ("Code after a jump statement") flags this. The migration must delete lines
200–309 as part of the cleanup.

**`format.txt` discriminator must be strictly validated**

The worker reads `format.txt` and branches on its content. The read value must be validated
against a strict allowlist before branching:

```php
$format = trim((string)@file_get_contents($jobDir . 'format.txt'));
if ($format !== 'zip' && $format !== 'tar.gz') {
    writeJobStatus($statusPath, ['state' => 'error', 'error_message' => 'Unknown archive format']);
    exit(1);
}
```

*(Uses `writeJobStatus()` from `admin_media_lib.php` — the inline `$writeStatus` closure is
removed in Phase 0.)*

Without this, an unexpected value (corrupt file, empty write, unexpected newline) causes a
silent fallthrough where neither branch executes, leaving `state=running` indefinitely.

#### 🟠 Minor

**S2083 — Path injection consistency on CLI arg**

The project's confirmed SonarQube S2083 fix pattern is `basename()` on user-supplied IDs
before filesystem path construction (see `docs/security_sonarqube_recommendations.md`). The
worker receives `$jobId` as a CLI argument and already validates it with
`preg_match('/^[a-f0-9]{16}$/')`. Applying `basename($jobId)` to the CLI arg before
`$jobDir` construction is consistent with the pattern used in the status/download endpoints
and avoids any future S2083 finding.

**`tar tzvf` output parsing requires `LC_ALL=C`**

GNU tar's `--verbose` listing output format varies by system locale — date formats,
column widths, and separators differ between `LC_ALL=C` and locale-specific settings.
All `proc_open` calls that parse `tar` verbose output must set `LC_ALL=C` in the environment
array to guarantee a consistent, parseable format:

```php
proc_open([...], $descriptors, $pipes, null, ['LC_ALL' => 'C'])
```

### Implementation Phases

Each phase is independently deployable and testable before the next begins.

---

#### Phase 0 — Shared Library (foundation, no behaviour change)

**Files:** create `admin/admin_media_lib.php`

Implement the four shared functions:
- `loadMediaExtensions(): array` — replaces the `$jsonEnvArray` + ext-set block in 5 files
- `isValidMediaEntry(string $name, array $audioExtsSet, array $videoExtsSet): bool` — replaces the SHA-256 + extension + no-path-separator check in 10 files
- `runTar(array $args, ?string $cwd = null, array $env = []): array` — array-form `proc_open` wrapper with `LC_ALL=C`, `try/finally` pipe cleanup, `proc_close` exit-code return
- `writeJobStatus(string $jsonPath, array $payload): void` — `updated_at` + `json_encode` + `file_put_contents(LOCK_EX)`

Wire `require_once` into `import_media_zip.php` and `import_media_zip_worker.php`, replacing
their local copies of the `$jsonEnvArray` closure and inline entry validation. In
`import_media_zip_worker.php`, also **delete the inline `$writeStatus` closure and replace all
calls with `writeJobStatus()`**. Leave the other 3 existing files (`catalog_scan_start.php`,
`iphone_import_status.php`, `iphone_import_worker.php`) for a separate cleanup pass.

**Decision required before coding Phase 0:** choose Option A or Option B for export progress
tracking (see Touch Point #1 / Progress Tracking section). If Option A (callback-extended
`runTar()`), the `runTar()` signature in Phase 0 must include `?callable $onStdoutLine = null`.
If Option B (archive size polling), `runTar()` is built without a callback. **This decision
must be made before Phase 0 is coded** — changing the signature after Phase 1 wires callers
requires revisiting all call sites.

**✅ Test gate:** deploy, run the full Playwright admin suite + an existing ZIP import — confirm nothing broken. No user-visible change.

---

#### Phase 1 — Export side: tar.gz output

**Files:** `export_media_worker.php`, `export_media.php`, `export_media_download.php`, `admin_system.php` (JS only), `export_media_status.php`

- `export_media_worker.php`: replace ZipArchive loop with `runTar()` invocation; two plaintext filelists (`audio_files.txt`, `video_files.txt`); zero-file pre-tar guard; verbose-pipe progress (Option A or B per decision made in Phase 0); partial archive unlink on error/catch; `require_once admin_media_lib.php`; **delete inline `$writeStatus` closure and replace all calls with `writeJobStatus()`**
- `export_media.php`: change filename extension to `.tar.gz`; add `proc_open` availability check; delete dead code lines 200–309
- `export_media_download.php`: `$archivePath = $jobDir . 'archive.tar.gz'`; `Content-Type: application/gzip`
- `admin_system.php` (JS, `doExportMedia()`): change Content-Type gate from `startsWith('application/zip')` → `startsWith('application/gzip')` — this is a Phase 1 **functional** requirement, not a Phase 2 cosmetic change; without it the download step reports ERROR on HTTP 200
- `admin_system.php` (JS, download streaming): add `archive_bytes`-based `effectiveLength` fallback; streaming loop always updates progress — real `X / Y` bar when size is known, `X received…` every 5 MB otherwise (root cause: Apache/mod_proxy_fcgi strips `Content-Length`, leaving `contentLength === 0`)
- `export_media_worker.php`: write `archive_bytes` (compressed size) to `done` status JSON — feeds the JS `effectiveLength` fallback
- `export_media_status.php`: update step-name fallback strings (lines 56, 77) for consistency

**✅ Test gate:**
- `docker exec <php-container> which tar` passes
- Export small corpus → `archive.tar.gz` downloads
- `tar tzf archive.tar.gz` shows flat `<sha256>.<ext>` entries, no path prefixes
- **Progress reporting works**: poll `export_media_status.php` during a mid-size export and
  confirm `status.json` updates (count/percentage advances) before the download link appears
- **Download streaming progress works**: the Download step shows a live `X / Y` progress bar
  (not a static "Receiving…") throughout the file transfer; confirm `archive_bytes` is present
  in the done `status.json`
- Export with audio-only and video-only filters (no empty `--files-from` error)
- Export corpus with a file >4 GB (no truncation)
- Simulate worker failure → status returns error, no partial archive left on disk

> ⚠️ **Deployment note**: Phase 1 and Phase 3 should be deployed in the same release or in
> immediate succession. If Phase 1 ships without Phase 3, newly-exported tar.gz archives
> cannot be re-imported until Phase 3 is live. Do not leave this window open across a sprint
> boundary.

---

#### Phase 2 — UI: Section E text updates

**Files:** `admin_system.php` (Section E only)

Update heading, description, button text, and in-progress label from ZIP → Archive. Purely cosmetic — no backend change.

**✅ Test gate:** Load admin page, confirm Section E labels read correctly. Playwright suite passes (button click + status div non-empty check still works).

---

#### Phase 3 — Import side: tar.gz input

**Files:** `import_media_zip.php`, `import_media_zip_worker.php`

- `import_media_zip.php`: `str_ends_with()` extension detection; format-aware temp path (`upload.zip` vs `upload.tar.gz`); write `format.txt` to job dir; `tar -tzvf` prepare-mode scan via `runTar()`; Apache-limit-only zip bomb guard for tar.gz; `proc_open` check before tar.gz prepare branch; `copy() + @unlink()` in `mode=start` (consistent cross-device pattern)
- `import_media_zip_worker.php`: read and strictly validate `format.txt`; tar.gz branch — pre-scan rejects `..` entries before extraction; `runTar(['tar', '-xzvf', ..., '--directory', $extractDir])`; `realpath()` containment check; extraction subdir cleanup in `try/finally`; ZIP path unchanged
- Known limitation: `$unsupportedCount` double-counted for tar.gz (pre-scan + glob); informational only

**✅ Test gate:**
- Import a tar.gz produced by Phase 1 → files land in correct audio/video dirs
- Idempotent re-import of same tar.gz → already-present files skipped
- Import a legacy `.zip` export → backward compatibility confirmed
- Upload a file named `archive.tar.gz` → extension check passes (pathinfo fix)
- Upload a crafted tar.gz with `../` entry → rejected, no files outside destination dirs
- Attempt with missing `format.txt` → error state, not silent hang

---

#### Phase 4 — UI: Section F text updates + Playwright comment

**Files:** `admin_system.php` (Section F), Playwright spec line 71

Update Section F heading, description, button text, and `accept=".zip,.tar.gz,.tgz"`. Update Playwright comment from "Export Media to ZIP" → "Export Media Archive".

**✅ Test gate:** Full Playwright admin suite end-to-end — all steps pass including the updated Section E and F labels.

### Testing Checklist

- **Pre-flight**: `docker exec <php-container> which tar` — verify `tar` binary is present in
  the PHP/Apache container before any testing begins
- Export a small corpus (< 100 MB) — verify `archive.tar.gz` is produced and downloadable
- Verify tar.gz is structurally valid: `tar tzf archive.tar.gz` shows flat `<sha256>.<ext>` entries (no path prefixes)
- Verify file contents intact: spot-check an extracted audio/video file
- Export a corpus where a single file exceeds 4 GB — verify no truncation (was a ZIP64 risk)
- Export with `org_name` filter — verify only matching assets are in the archive
- Export audio-only and video-only — verify correct subsets (no empty `--files-from` error)
- Import the resulting tar.gz via Section F — verify files land in correct audio/video dirs
- Idempotent re-import of tar.gz — verify already-present files are skipped, not duplicated
- Import a legacy `.zip` export — verify backward compatibility is maintained
- Attempt to upload a `.tar.gz` file named `archive.tar.gz` — verify `pathinfo` fix allows it through
- Cancel after prepare — verify no job dir or temp prepare file is left behind
- Simulate worker failure (remove filelist after start) — verify status endpoint returns error
  state and no partial `archive.tar.gz` remains in the job dir
- Attempt to import a crafted tar.gz with a `../` traversal entry — verify it is rejected and
  no files land outside the audio/video destination dirs
- Run the full Playwright admin-pages test suite — verify all steps pass with updated button
  text and archive format

---

## Possible Future Enhancements

- Partial/fuzzy Event/band matching on `org_name` filter
- Split archives (e.g. N-GB chunks) for very large corpora
- Resumable download support
- Direct rsync export path for on-prem installations (admin SSH terminal workflow, documented as supported procedure)

## Summary

Export Media is an async admin workflow:

- `prepare` discovers what can be exported and how large it is
- `start` spawns a background PHP CLI worker that builds the archive
- polling provides per-file build progress
- `download` serves the pre-built archive; `Content-Length` may be stripped by the Apache/mod_proxy_fcgi proxy, so `archive_bytes` from the worker status JSON is used as a fallback to drive the streaming progress bar in the browser

The current implementation (ZIP) has a known performance bug (per-file ZipArchive open/close)
and a 4 GB per-file limit. The planned migration to tar.gz addresses both issues while
preserving the async architecture and all existing UI behavior. Section F (Import) must be
updated in tandem to accept tar.gz archives while retaining ZIP backward compatibility.
