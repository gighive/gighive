# Upload Media By Hash (controller-side)

This guide explains how to use the controller-side script:

- `ansible/tools/upload_media_by_hash.py`

Purpose of the script is to copy files from your local media library to the GigHive host **by checksum** (the destination filename is `{sha256}.{ext}`), using rows already present in the GigHive database.

## What this script does

- Reads rows from the `files` table where `checksum_sha256` and `source_relpath` are present.
- For each row:
  - Builds a destination filename `{checksum_sha256}.{ext}` (extension comes from `source_relpath`).
  - Copies the media file from your **controller** media store (e.g. Pop!_OS mount) to the **GigHive host** via `rsync` over SSH.
  - Runs `ffprobe` on the remote host to collect `media_info` and `duration_seconds`.
  - Updates the row in the database (`files.media_info`, `files.media_info_tool`, `files.duration_seconds`) using `mysql`.

## Why `--source-root` exists

The DB stores `files.source_relpath` (a **relative** path). The uploader needs a base directory (`--source-root`) so it can find the file on disk:

- Local source path = `--source-root` + `/` + `files.source_relpath`

## Where it runs

Run this script from your **Ansible controller machine** (e.g. Pop!_OS) where your media library is accessible locally.

The script can be run from **any directory** on your filesystem as long as:

- the `python3` invocation points to the correct `upload_media_by_hash.py` path, and
- `--source-root` correctly points at the local folder containing the media referenced by `files.source_relpath`.

It copies files to the remote GigHive host via SSH.

## Prerequisites

### Local controller requirements

- `python3`
- `python3-yaml` (PyYAML)
- `mysql` CLI
- `ssh`
- `rsync`

### Remote host requirements

- `ffprobe` available on the remote host (`ubuntu@gighive2` in examples)

## Supported formats and extensions

The script infers media type (`audio` vs `video`) from the file extension when:

- the DB row has a missing/invalid `file_type` and you pass `--infer-type-if-missing`.

By default, the script loads the audio/video extension lists from Ansible `group_vars`:

- `ansible/inventories/group_vars/gighive2/gighive2.yml`

Keys used:

- `gighive_upload_audio_exts`
- `gighive_upload_video_exts`

If PyYAML is missing or the file/keys can’t be read, the script falls back to built-in defaults.

## Recommended workflow (most users): one stable root

Pick a single top-level directory that contains **all** of your media (e.g. `~/videos` or `/mnt/scottsfiles/videos`) and keep using it consistently.

1) In **admin.php Section 4 or 5**, select your folder and let the browser compute hashes (this populates/updates DB rows including `source_relpath`).
2) Run the uploader and point `--source-root` at the same stable root.
3) Re-run as needed (use `--limit` for smaller batches).

Example:

```bash
sodo@pop-os:~/videos$ MYSQL_PASSWORD='musiclibrary' python3 ~/scripts/gighive/ansible/tools/upload_media_by_hash.py \
  --source-root /mnt/scottsfiles/videos \
  --ssh-target ubuntu@gighive2 \
  --db-host gighive2 \
  --db-user root \
  --db-name music_db \
  --limit 500
```

## Piecemeal uploads (power users)

You can hash/scan smaller subfolders over time (e.g. `~/videos/projects/blog` today, `~/videos/bandsShows` tomorrow) **as long as** you still run the uploader with `--source-root` pointing at the stable top-level root that contains all of them.

If `--source-root` points at a subfolder but the DB rows you selected refer to other subtrees, you will see `MISSING_SRC`.

## `--source-root` and `source_relpath`

- `files.source_relpath` is treated as a path **relative to** `--source-root`.
- When files are added via **admin.php Section 4 / Section 5** folder scanning, browsers typically populate `webkitRelativePath`, which includes the selected folder name as the first path segment (e.g. `bandsShows/blackelk/1.avi`).
- To match user expectations, `upload_media_by_hash.py` will also handle the common case where `--source-root` is set to the selected folder itself (e.g. `/mnt/scottsfiles/videos/bandsShows`) while `source_relpath` still starts with `bandsShows/`.

Example (admin folder scan):

- Folder selected in browser: `/mnt/scottsfiles/videos/bandsShows`
- DB `source_relpath`: `bandsShows/blackelk/1.avi`

```bash
MYSQL_PASSWORD='musiclibrary' python3 ~/scripts/gighive/ansible/tools/upload_media_by_hash.py \
  --source-root /mnt/scottsfiles/videos/bandsShows \
  --ssh-target ubuntu@gighive2 \
  --db-host gighive2 \
  --db-user root \
  --db-name music_db \
  --limit 500
```

## Common options

- `--source-root`
  - Absolute directory containing the content referenced by `files.source_relpath`.
- `--ssh-target`
  - SSH destination for file copy and remote ffprobe.
- `--dest-audio` / `--dest-video`
  - Destination directories on the remote host.
- `--db-host` / `--db-port` / `--db-user` / `--db-password` / `--db-name`
  - MySQL connection details.
- `--limit`
  - Max number of DB rows to process per run.
- `--only-type audio|video`
  - Only process rows with a specific `file_type`.
- `--single-source-relpath <relpath>`
  - Only process the DB row whose `files.source_relpath` matches exactly.
  - This is the fastest way to upload a single missing file.
- `--single-checksum <sha256>`
  - Only process the DB row whose `files.checksum_sha256` matches exactly.
- `--infer-type-if-missing`
  - If `file_type` is not `audio`/`video`, infer from extension lists.
- `--dry-run`
  - Do not copy; print what would happen.
- `--force-recopy`
  - If remote file already exists, overwrite it via rsync, then refresh DB info.

## Uploading a single missing file (recommended)

If you know the DB `source_relpath` for the missing file, run:

```bash
MYSQL_PASSWORD='[password]' python3 ~/scripts/gighive/ansible/tools/upload_media_by_hash.py \
  --source-root /mnt/scottsfiles/videos \
  --ssh-target ubuntu@gighive2 \
  --db-host gighive2 \
  --db-user root \
  --db-name music_db \
  --single-source-relpath 'morbiusEyes/my_video-1.mkv'
```

If you only know the sha256:

```bash
MYSQL_PASSWORD='[password]' python3 ~/scripts/gighive/ansible/tools/upload_media_by_hash.py \
  --source-root /mnt/scottsfiles/videos \
  --ssh-target ubuntu@gighive2 \
  --db-host gighive2 \
  --db-user root \
  --db-name music_db \
  --single-checksum '08fc8f1bb8981f2e19adbcfea2e34093b61d03f01a362c3e90647d6cb57076f4'
```

## group_vars override / disabling group_vars

- Override the group_vars path:

```bash
GIGHIVE_GROUP_VARS=/home/sodo/scripts/gighive/ansible/inventories/group_vars/gighive2/gighive2.yml \
python3 ansible/tools/upload_media_by_hash.py ...
```

or:

```bash
python3 ansible/tools/upload_media_by_hash.py ... \
  --group-vars /home/sodo/scripts/gighive/ansible/inventories/group_vars/gighive2/gighive2.yml
```

- Disable group_vars loading and use built-in defaults:

```bash
python3 ansible/tools/upload_media_by_hash.py ... --no-group-vars
```

## Output and troubleshooting

The script prints tab-delimited status rows:

- `COPIED`, `RECOPIED`, `SKIP_COPY_EXISTS`, `MISSING_SRC`, `FAILED`, `DB_REFRESHED`, `DB_REFRESH_FAILED`

If you see `INFO: using built-in extension defaults (PyYAML not installed)`, install controller prereqs (or ensure `python3-yaml` is present).

If `DB_REFRESH_FAILED` occurs, it usually indicates `ffprobe` failed on the remote host (or the remote file is missing/corrupt).

```bash
sodo@pop-os:~/videos/projects$ MYSQL_PASSWORD='[password]' python3 ~/scripts/gighive/ansible/tools/upload_media_by_hash.py   --source-root /mnt/scottsfiles/videos/projects   --ssh-target ubuntu@gighive2   --db-host gighive2   --db-user root   --db-name music_db   --limit 500
INFO: loaded extension lists from group_vars: /mnt/scottsfiles/scripts/gighive/ansible/inventories/group_vars/gighive2/gighive2.yml
STATUS	FILE_TYPE	SOURCE_RELPATH	DEST
MISSING_SRC	video	stormpigs20251222_00001_fountain.mp4	/home/ubuntu/video/a5950b11f180203e3d05a5fb7e653a5ec7b194bb8cb17dd5c954bc80e84ef25d.mp4
MISSING_SRC	video	stormpigs20251222_00002_morning-wonder.mp4	/home/ubuntu/video/b4ae4bea68cb645c9b99ef3cd6ba3940996613be02f25a856c5875328fe9bdf0.mp4
COPIED	video	morbiusEyes/my_video-1.mkv	/home/ubuntu/video/08fc8f1bb8981f2e19adbcfea2e34093b61d03f01a362c3e90647d6cb57076f4.mkv
DB_REFRESHED	video	morbiusEyes/my_video-1.mkv	/home/ubuntu/video/08fc8f1bb8981f2e19adbcfea2e34093b61d03f01a362c3e90647d6cb57076f4.mkv
```

## Example: fix a single missing file whose `checksum_sha256` is NULL

This situation can happen if a row exists with the correct `source_relpath`, but hash computation didn’t run (so the uploader will skip the row until `checksum_sha256` is populated).

Step 1: locate the DB row and confirm checksum is missing

```bash
MYSQL_PWD='[password]' mysql -h gighive2 -u root music_db -e "
SELECT file_id, file_type, checksum_sha256, source_relpath
FROM files
WHERE source_relpath LIKE '%electrofunk42%'
LIMIT 50;"
```

Step 2: compute sha256 locally and update the DB row

```bash
sha256sum /mnt/scottsfiles/videos/stormpigs/finals/singles/StormPigs20250724_4_electrofunk42.mp4

MYSQL_PWD='[password]' mysql -h gighive2 -u root music_db -e "
UPDATE files
SET checksum_sha256 = 'e4abd2933e76233ab302e8f598cdb5f5fbafd6f39f4909ac0d509a68a784bf22', file_type = 'video'
WHERE file_id = 632;"

MYSQL_PWD='[password]' mysql -h gighive2 -u root music_db -e "
SELECT file_id,file_type,checksum_sha256,source_relpath
FROM files
WHERE file_id = 632;"
```

Step 3: run the uploader in single-file mode

```bash
MYSQL_PASSWORD='[password]' python3 ~/scripts/gighive/ansible/roles/docker/files/apache/webroot/tools/upload_media_by_hash.py \
  --source-root /mnt/scottsfiles/videos/stormpigs/finals/singles/ \
  --ssh-target ubuntu@gighive2 \
  --db-host gighive2 \
  --db-user root \
  --db-name music_db \
  --single-source-relpath 'StormPigs20250724_4_electrofunk42.mp4'
```
