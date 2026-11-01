# Admin: Clear Media Files (Section 2C)

## Rationale

The upload media service does not delete audio or video files from the Docker host VM when
database records are removed or when the database is wiped. This creates a gap between the
database state and the filesystem state.

Two distinct destructive operations exist (and are intentionally kept separate):

| Operation | What it touches | Recoverable? |
|-----------|----------------|-------------|
| Section B — Clear Sample Media (`clear_media.php`) | Database tables only | Yes, from DB backup |
| Section C — Delete Media Files (`clear_media_files.php`) | Actual audio/video/thumbnail files on disk | **No** |

Keeping them separate preserves a common legitimate workflow: wipe and reimport the database
while leaving the files in place (e.g. re-run an import with corrected metadata, or restore a
DB backup without re-uploading all media). Coupling file deletion to the DB wipe would make
that workflow impossible without re-uploading everything.

## Directory structure

Files live on the host VM as bind-mounts into the container:

| Host VM path | Container path | Contents |
|---|---|---|
| `/home/ubuntu/audio/` | `/var/www/html/audio/` | Audio files (flat) |
| `/home/ubuntu/video/` | `/var/www/html/video/` | Video files (flat) |
| `/home/ubuntu/video/thumbnails/` | `/var/www/html/video/thumbnails/` | Thumbnails for both audio and video |

The container's `www-data` process owns these directories (set by `entrypoint.sh.j2`) so PHP
can unlink files there directly, and the deletions are visible on the host immediately.

## Implementation

### New file: `clear_media_files.php`

Backend endpoint at the webroot alongside `clear_media.php`.

- Auth gate: admin Basic-Auth user only, POST only (same pattern as `clear_media.php`)
- Reads `MEDIA_SEARCH_DIRS` env var (`/var/www/html/audio:/var/www/html/video`). **Hard-fails**
  with a 500 response if the var is absent or empty — avoids silently succeeding with 0
  deletions when misconfigured.
- Derives three explicit target paths: `{audio_dir}`, `{video_dir}`, `{video_dir}/thumbnails`.
  No new env vars are needed.
- Checks `is_dir()` on each path before processing. Missing dirs are logged and skipped (not
  treated as an error), since `thumbnails/` may not exist on a fresh install.
- Deletes files using `glob($path . '/*') ?: []` + `unlink()`. The `?: []` guard is required
  because `glob()` returns `false` (not `[]`) on an empty directory — iterating `false` would
  throw a PHP warning.
- **Continues on per-file `unlink()` failure** — collects all errors and reports them in the
  response rather than aborting mid-run on a single permissions problem.
- **No open-ended recursion** — only the three explicit paths are processed; the directories
  themselves are preserved.
- For very large collections, `glob()` loads all filenames into memory at once. This is
  acceptable for v1; if scale becomes a concern, migrate to `opendir()`/`readdir()`.
- Returns JSON:
  ```json
  {
    "success": true,
    "audio_files_deleted": 12,
    "video_files_deleted": 4,
    "thumbnail_files_deleted": 16,
    "total_deleted": 32,
    "errors": []
  }
  ```
- Logs start/finish to `error_log` (consistent with `clear_media.php`).

### Edit: `admin.php`

New **Section C** added to `admin.php` (between Section B and Section D — note: Section 2B
"Upload Files Individually" has since been relocated to `admin_database_load_import.php` as
Section C on `admin_database_load_import.php`, and the former Sections 3A/3B have also moved to that page as Sections A and B).

- HTML block with description, warning box (more severe than 2A — emphasises that files
  are not recoverable from a database backup, and that no uploads should be in progress).
- JS function `confirmClearMediaFiles()` uses a two-step `confirm()` dialog before calling
  `clear_media_files.php` via `fetch POST`.
- On success, displays per-path file counts from the JSON response.

### Caveat: TUS uploads in progress

If a TUS chunked upload is in flight to `audio/` or `video/` when the wipe runs, the partial
file will be unlinked by `clear_media_files.php`. On Linux the open file descriptor keeps the
inode alive until the upload process closes it, but the upload will then fail when the server
tries to finalise it. The Section 2C UI copy warns the operator to ensure no uploads are in
progress before running the wipe.

## Touched files

| Action | File |
|--------|------|
| Create | `ansible/roles/docker/files/apache/webroot/clear_media_files.php` |
| Edit | `ansible/roles/docker/files/apache/webroot/admin.php` |

No Ansible changes, no compose changes, no new env vars.
