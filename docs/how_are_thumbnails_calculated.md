# How are video thumbnails calculated?

This document describes the planned behavior for video thumbnail generation in GigHive.

The implementation is designed to:

- Generate **one** thumbnail per video.
- Choose a thumbnail frame at a **random-looking** timestamp *within the video duration*.
- Make that “random” selection **stable/deterministic per video** (so thumbnails don’t churn).
- Store thumbnails at a deterministic path derived from the video’s SHA-256.
- Include operational hardening: idempotency, atomic writes, timeouts, and safe failure behavior.

## Storage + URL mapping (host ↔ container)

The video directory is bind-mounted into the Apache container:

- Host (VM): `/home/{{ ansible_user }}/video`
- Container: `/var/www/html/video` (from `media_search_dir_video`)
- Public URL: `/video/...`

Therefore, thumbnails should be written to:

- Host (VM): `/home/{{ ansible_user }}/video/thumbnails/<sha256>.png`
  - Typically `/home/ubuntu/video/thumbnails/<sha256>.png`
- Container: `/var/www/html/video/thumbnails/<sha256>.png`
- URL: `/video/thumbnails/<sha256>.png`

## Step 1 — Common thumbnail rules

### 1.1 Inputs

Thumbnail generation uses:

- `duration_seconds` (integer seconds, nullable)
- `checksum_sha256` (64 lowercase hex characters)

### 1.2 Deterministic “random” timestamp selection

We want a timestamp that appears random across the library, but is stable for a given file.

Algorithm:

- If `duration_seconds` is missing/invalid:
  - Pick `t = 0` (or skip generation if desired).
- If `duration_seconds` is very small (e.g., `< 4`):
  - Pick `t = duration_seconds / 2`.
- Otherwise:
  - Define a safe window to avoid opening black frames and end fades:
    - `start = min(2.0, duration_seconds * 0.10)`
    - `end = max(duration_seconds - 2.0, start)`
  - Derive a stable fraction from the SHA-256:
    - `r = int(sha256[0:8], 16) / 0xFFFFFFFF`
  - Choose:
    - `t = start + r * (end - start)`

This yields “random within duration” without changing across page refreshes or reimports.

### 1.3 Output naming

- Thumbnail filename: `<sha256>.png`
- Directory: `video/thumbnails/`

## Step 2 — `upload_media_by_hash.py` (non-web upload flow)

### 2.1 When thumbnail generation happens

For each DB row processed, the script already does:

1. Copy media to destination (rsync)
2. Probe metadata using remote `ffprobe` (via SSH)
3. Update MySQL `files.duration_seconds` / `files.media_info`

Thumbnail generation should happen **after step (2)** (after duration is known), and only for:

- `file_type == 'video'`

### 2.2 Where it writes

- Remote directory: `args.dest_video + '/thumbnails'`
  - Default `args.dest_video` is `/home/ubuntu/video`
- Remote output file:
  - `/home/ubuntu/video/thumbnails/<sha256>.png`

### 2.3 How it runs

- Use `ssh` to run `ffmpeg` on the VM host, using the already-copied video file as input.

## Step 3 — Web upload flow (PHP `UploadService.php`)

### 3.1 When thumbnail generation happens

During a normal web upload, `UploadService` computes:

- `checksum_sha256` (from the upload temp file)
- `duration_seconds` (from `getID3` or `ffprobe`)

Thumbnail generation should happen **after the file is moved** and **after duration is computed**, and only for:

- `file_type == 'video'`
- valid `checksum_sha256`

### 3.2 Where it writes

Inside the container, write to:

- `/var/www/html/video/thumbnails/<sha256>.png`

(Which corresponds to the VM host path `/home/{{ ansible_user }}/video/thumbnails/<sha256>.png`.)

### 3.3 How it runs

- Use local `ffmpeg` (in the container) against the final video path.

## Step 4 — Display on `db/database.php`

### 4.1 Derived URL

For each `files` row, the thumbnail URL is derived from `checksum_sha256`:

- `/video/thumbnails/<sha256>.png`

### 4.2 Always visible with lazy loading

- Add a thumbnail column that always renders for video rows.
- Use HTML `loading="lazy"` so the browser loads thumbs only as needed.
- Constrain display size (e.g., width ~80–120px in the table), while the stored image is generated at 320px width.

### 4.3 Missing thumbnail behavior

If the thumbnail file doesn’t exist:

- Display a placeholder or blank cell.
- Do not break page rendering.

## Step 5 — Operational hardening (required)

The thumbnail implementation must include:

- **Idempotency**
  - If `/video/thumbnails/<sha>.png` already exists, skip regeneration (unless a force flag is used).

- **Atomic writes**
  - Write to a temp file and rename to the final name.
  - Prevents partial/corrupt thumbnails if ffmpeg fails mid-write.

- **Timeouts**
  - Ensure `ffmpeg` calls cannot hang indefinitely.
  - Python: `subprocess.run(..., timeout=<seconds>)`
  - PHP: use a controlled process execution strategy with a timeout.

- **Safe escaping / injection resistance**
  - Never interpolate paths without proper escaping/quoting.
  - In PHP use `escapeshellarg()`; in Python prefer argv lists where possible.

- **Graceful failure**
  - Thumbnail generation failures should be logged, but should not abort the upload/import.

- **Directory creation**
  - Ensure `video/thumbnails/` exists at runtime (`mkdir -p` / `ensureDir`).

## Step 6 — Testing and verification

Minimum verification steps:

- Upload a video via the web UI
  - Confirm thumbnail created at `/video/thumbnails/<sha>.png`
  - Confirm it displays on `db/database.php`

- Import/copy a video via `upload_media_by_hash.py`
  - Confirm thumbnail created on the VM host under `/home/ubuntu/video/thumbnails/`
  - Confirm it displays via the container URL `/video/thumbnails/<sha>.png`

- Edge cases
  - Very short videos
  - Missing duration
  - ffmpeg failure (ensure upload still succeeds)

- Performance
  - Confirm `db/database.php` remains responsive (lazy loading, small table thumb size).

## Making sure thumbnails will show for open source user

If you commit `assets/video/thumbnails/*.png` into the repo, then a new user who provisions a VM via the `app_flavor=gighive` path should see thumbnails, because the `assets/video` tree (including `thumbnails/`) will be synced to the VM and served by Apache.

This depends on the following conditions:

1. The thumbnails must actually be committed
   - Ensure `assets/video/thumbnails/` is not gitignored and is included in the repository the user clones.

2. The database must match the shipped media
   - `db/database.php` will derive the thumbnail URL from `files.checksum_sha256`.
   - The default database loaded during provisioning must contain `files.checksum_sha256` values that correspond to the sample videos synced into `/var/www/html/video/`.
   - If the shipped DB and shipped media do not match (different bytes → different sha), the derived URL will not point at the correct thumbnail.

3. Apache must serve the thumbnails directory
   - Because `media_search_dir_video` is `/var/www/html/video`, anything written to `/var/www/html/video/thumbnails/` should be reachable at `/video/thumbnails/...` as long as Apache is configured to serve `/var/www/html` normally.

Also note:

- The Ansible `synchronize` task for `video_reduced` syncs the whole reduced tree (`{{ repo_root }}/assets/video`), so a `thumbnails/` subdirectory will be copied.
- The `synchronize` tasks shown do not include `--delete` (or `delete: yes`), so they should not wipe extra files on the destination that are not in the source.
