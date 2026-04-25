# Admin Export Media

## Purpose

The **Export Media** feature allows an admin to download a ZIP archive of media files that are currently present on disk.

Its main use cases are:

- Preserve media before destructive database operations
- Export a subset of media for a specific Event/band name
- Export only audio or only video files
- Re-import preserved files later via **Import Media (folder)**

In the current admin layout, this feature appears at:

- **System & Recovery → Section D: Export Media to ZIP**

Source files:

- Frontend UI + JavaScript: `ansible/roles/docker/files/apache/webroot/admin/admin_system.php`
- Backend export endpoint: `ansible/roles/docker/files/apache/webroot/admin/export_media.php`

## High-Level Flow

The export is intentionally split into three observable stages:

1. **Query database**
2. **Build archive**
3. **Download**

The browser does not immediately request a ZIP build. Instead, it first calls the backend in a lightweight `prepare` mode to discover what would be exported.

That `prepare` call returns:

- number of matching files found on disk
- number of matching DB rows skipped
- aggregate size in bytes of the source files

The UI then:

- shows the Query step as complete
- prompts the user to confirm the size of the export
- starts the real ZIP build only after confirmation

## UI Behavior

## Entry Point

The export UI is rendered in `admin_system.php` as Section D.

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
  - label changes to `Building ZIP…` while the flow is running
  - disabled during the operation
  - restored to `Download ZIP` when finished or failed

Status container:

- `exportMediaStatus`
  - rendered using shared progress UI from `import_progress.js`

## Progress Display

The feature uses `renderImportStepsShared()` to display three steps:

- **Query database**
- **Build archive**
- **Download**

This reuses the same visual progress component used elsewhere in admin import flows.

## Confirmation Dialog

After the `prepare` call succeeds, the UI shows a native browser `confirm()` dialog before starting the build.

Current confirmation text is equivalent to:

```text
You are about to zip X MB/GB of files.

Make sure you have enough free space to accommodate this download.

Do you wish to continue?
```

If the admin clicks **Cancel**:

- the ZIP build is never started
- the Build step is left pending with `Canceled before ZIP build`
- the Download step remains pending

## Frontend Request Sequence

The client code lives in `doExportMedia()` inside `admin_system.php`.

### Step 1: Prepare

The browser sends:

```text
POST /admin/export_media.php
mode=prepare
org_name=<value>
file_type=<all|audio|video>
```

If successful, the response is JSON:

```json
{
  "success": true,
  "count": 123,
  "skipped": 4,
  "total_bytes": 987654321
}
```

The UI then marks Query complete and displays a summary such as:

```text
123 file(s) ready to export (941.9 MB)
```

### Step 2: Build

If the user confirms, the browser sends a second request:

```text
POST /admin/export_media.php
mode=build
org_name=<value>
file_type=<all|audio|video>
```

This request performs the actual ZIP creation on the server.

### Step 3: Download

When the build request starts returning a ZIP response, the browser reads the response body as a `ReadableStream` and updates the Download step incrementally.

The browser accumulates the chunks into a `Blob`, then triggers the download with a temporary object URL and an `<a download>` click.

## Backend Behavior

The backend endpoint is `admin/export_media.php`.

## Access Control

Only the Basic Auth admin user may call this endpoint.

If the authenticated user is not `admin`, the endpoint returns:

- HTTP `403`
- JSON error payload

The endpoint also only accepts `POST`.

## Input Parameters

The endpoint reads:

- `org_name`
- `file_type`
- `mode`

Normalization rules:

- `file_type` is restricted to `all`, `audio`, or `video`
- invalid `file_type` falls back to `all`
- `mode` is restricted to `prepare` or `build`
- invalid `mode` falls back to `build`

## Database Query

The export query joins canonical media tables:

- `assets`
- `event_items`
- `events`

The query selects distinct assets:

- `asset_id`
- `checksum_sha256`
- `file_type`
- `file_ext`
- `source_relpath`

Optional filters:

- exact `events.org_name = :org_name` when `org_name` is provided
- `assets.file_type = 'audio'` when audio-only export is requested
- `assets.file_type = 'video'` when video-only export is requested

Important consequence:

- export is based on canonical asset/event relationships, not on a raw directory listing
- if there is no matching DB record, the file will not be included
- if there is a DB record but the underlying file is missing from disk, that row is skipped

## Disk Locations

The endpoint expects media files at:

- audio: `/var/www/html/audio`
- video: `/var/www/html/video`

The on-disk served filename is derived from:

- `checksum_sha256`
- `file_ext`

If `file_ext` is present, the endpoint looks for:

- `<sha256>.<ext>`

Otherwise it looks for:

- `<sha256>`

## `prepare` Mode

`prepare` mode does **not** build a ZIP.

Instead it:

- iterates through the matching DB rows
- validates each checksum
- resolves the expected on-disk path
- counts files that actually exist
- sums file sizes with `filesize()`
- counts skipped rows

Returned fields:

- `count`
  - number of exportable files found on disk
- `skipped`
  - matching rows that could not be exported
- `total_bytes`
  - sum of source file sizes

If no exportable files are found on disk, the endpoint returns:

- HTTP `404`
- JSON error explaining that no media files were found on disk

## `build` Mode

`build` mode performs the real archive construction.

### Temp File

The ZIP is built into a temporary file created by:

- `tempnam(sys_get_temp_dir(), 'gighive_export_')`

This means the archive is staged in the container temp directory rather than in the webroot.

### ZIP Construction

The server uses PHP `ZipArchive`.

For each matching row:

- validate checksum format
- resolve the correct media directory based on `file_type`
- verify the source file exists
- compute the ZIP entry name
- add the file to the archive

Files that fail validation or are missing on disk are skipped.

If no files are actually added, the temp file is deleted and a `404` JSON error is returned.

## ZIP Entry Naming

ZIP entry names are based on `source_relpath` when available.

Rules:

- base filename is `basename(source_relpath)`
- path separators and NUL are replaced with `_`
- if the resulting name is empty, the served checksum-based filename is used instead

Collision handling:

- if the same entry name appears more than once, a short checksum suffix is appended
- suffix format is effectively:
  - `<basename>_<first8sha>.<ext>`

This prevents duplicate ZIP entry names from overwriting one another.

## Response Streaming

After the ZIP is built, the server streams it to the browser.

Important implementation choices:

- `Content-Type: application/zip`
- `Content-Disposition: attachment; filename="..."`
- **no `Content-Length` header**
- `Cache-Control`, `Pragma`, and `Expires` headers disable caching

The omission of `Content-Length` is intentional.

### Why `Content-Length` Is Omitted

When PHP is running behind Apache `mod_proxy_fcgi`, providing a fixed `Content-Length` can allow buffering behavior that prevents the browser from observing incremental download progress.

By omitting `Content-Length`, Apache is forced to stream the response incrementally instead of waiting on the entire body first.

### Buffering Controls

Before streaming, the endpoint does the following:

- disables `zlib.output_compression`
- clears all active output buffers with `ob_end_clean()` in a loop

The ZIP is then streamed in `256 KB` chunks using `fread()` + `echo` + `flush()`.

After streaming completes, the temp ZIP file is deleted.

## Progress Meter Semantics

## 1. Query Database

This step is real and authoritative.

It completes when the `prepare` call returns success.

The step reports:

- matching file count found on disk
- total source size in human-readable units

## 2. Build Archive

This step currently provides **elapsed-time observability**, not granular per-file progress.

The UI starts a timer when the `build` request is sent and stops it when the ZIP response begins.

What it means:

- the timer is tied to real wall-clock server activity for the build request
- it is not a fake animation unrelated to network/server state

What it does **not** mean:

- it does not know which file is currently being zipped
- it does not know what percentage of ZIP construction is complete

Current messages look like:

- running: `Zipping 12 file(s)… 4.2s`
- complete: `Archive built in 6.1s`

## 3. Download

This step shows incremental byte progress in the browser.

The browser uses the `total_bytes` value returned by `prepare` as the denominator.

Important nuance:

- `total_bytes` is the sum of source file sizes
- it is not the actual ZIP file byte length
- for already-compressed media formats, this is usually a good approximation
- it is used because the streamed ZIP response intentionally omits `Content-Length`

During streaming, the UI updates approximately every additional 1% received and yields back to the browser with `setTimeout(0)` so the progress bar actually repaints.

## Why Download Progress Works Incrementally

Several layers were adjusted so progress updates appear during the transfer instead of jumping straight to 100%.

### Browser Side

The frontend:

- reads `buildResp.body` as a stream
- updates step state per chunk
- yields before the loop begins so the initial `0 B / X MB` state paints
- yields every ~1% to force repaints during fast chunk delivery

### PHP Side

The backend:

- avoids `readfile()`
- streams manually in chunks
- disables compression and clears output buffers

### Apache/PHP-FPM Side

The implementation is designed around Apache + PHP-FPM via `mod_proxy_fcgi`.

The critical behavior is:

- do not send `Content-Length` on the ZIP response
- stream chunked output so the browser can observe partial delivery

## Error Handling

Frontend error surfaces:

- network failure on prepare
- JSON error on prepare
- network failure on build
- non-ZIP build response interpreted as JSON error when possible

Backend failure cases include:

- unauthorized user
- wrong HTTP method
- database connection failure
- query failure
- no matching DB rows
- no exportable files present on disk
- temp file creation failure
- ZIP archive creation failure

## Permissions and Runtime Requirements

For export to work, the web process must be able to:

- read files under `/var/www/html/audio`
- read files under `/var/www/html/video`
- create temp files in `sys_get_temp_dir()`

The feature does **not** require write access to the media directories themselves.

In containerized deployments, the important permission question is usually runtime ownership/mode of the media files, not just Docker image ownership.

If bundle/runtime media are copied in later, they must still be readable by the web process (typically `www-data`).

## One-Shot Bundle Considerations

No special Export Media-specific `chmod` or `chown` change is currently required in `ansible/roles/docker/templates/Dockerfile.j2` purely because of this feature.

Reason:

- export only reads media files
- export writes the temporary ZIP to the temp directory
- the Dockerfile already assigns `www-data` ownership to the webroot contents at image build time

The actual risk area for one-shot bundle installs is:

- whether runtime media files placed into `/var/www/html/audio` and `/var/www/html/video` are readable by `www-data`

If a permission issue appears in bundle installs, the best fix point is likely the runtime copy/install path, not the image build itself.

## Operational Notes

## Intended Use Before Destructive Actions

A common workflow is:

1. Export media from Section D
2. Perform destructive DB/media operations if needed
3. Re-import preserved files via **Import Media (folder)**

This is especially useful for custom files such as tutorial media that should survive a rebuild.

## Exact Matching on Event/Band Filter

The current backend filter for `org_name` is exact equality:

- `e.org_name = :org_name`

This means the admin must supply the stored Event/band name exactly as it exists in the database.

## `total_bytes` Is an Estimate for Download Progress

Because the ZIP response is streamed without `Content-Length`, the UI uses source file total size as the progress denominator.

This is good enough for observability, but it should not be interpreted as the exact final ZIP byte length.

## Possible Future Enhancements

Not currently implemented:

- true ZIP build percentage based on server-side file-by-file progress
- per-file build status display
- polling or SSE for server-side archive generation milestones
- partial/fuzzy Event/band matching
- alternate archive formats

A true archive-build percentage would require additional backend instrumentation beyond the current request/response flow.

## Summary

Export Media is a two-request admin workflow:

- `prepare` discovers what can be exported and how large it is
- `build` creates and streams the ZIP

The current implementation provides:

- admin-only access control
- exact Event/band and file-type filtering
- size-aware confirmation before archive creation
- honest elapsed-time feedback during ZIP construction
- incremental browser download progress during transfer
- ZIP filename collision protection
- temp-file cleanup after streaming

This makes the feature suitable both for operational backups of media-on-disk and for preserving selected media before destructive maintenance tasks.
