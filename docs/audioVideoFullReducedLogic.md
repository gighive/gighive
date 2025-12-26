# Audio/Video Full vs Reduced Logic (Ansible + Docker)

## Goal

This document explains how GigHive/StormPigs audio/video media directories are handled by Ansible and mounted into the `apacheWebServer` container.

It also explains the root cause behind a common import issue:

- Method 3A (`admin.php` -> `import_database.php` -> `mysqlPrep_full.py`) produces `files.duration_seconds` / `files.media_info` as `NULL` even though `ffprobe` is installed.

## Key takeaway

- **Full vs reduced selection changes only the *source* that Ansible syncs from.**
- **The destination directories on the VM are stable** (`/home/{{ ansible_user }}/audio` and `/home/{{ ansible_user }}/video`).
- `docker-compose.yml.j2` mounts those stable VM destinations into stable container destinations (`/var/www/html/audio` and `/var/www/html/video`).
- For Method 3A to probe media successfully, the Apache container must both:
  - contain the relevant media files at the mounted paths, and
  - export `MEDIA_SEARCH_DIRS` so `mysqlPrep_full.py` knows to search those container paths.

## Where the destination paths are defined

In `ansible/playbooks/site.yml`, the VM destinations are set as facts:

- `video_dir: "{{ root_dir }}/video"`
- `audio_dir: "{{ root_dir }}/audio"`

Where `root_dir` is:

- `/home/{{ ansible_user }}` for non-root users

So the effective destinations are:

- `/home/{{ ansible_user }}/video`
- `/home/{{ ansible_user }}/audio`

These destinations **do not change** based on full vs reduced selection.

## Where the source paths are defined

In inventory group vars (example shown from `group_vars/gighive2.yml`):

- `video_full: "/home/sodo/videos/stormpigs/finals/singles/"`
- `audio_full: "/home/sodo/scripts/stormpigsCode/production/audio/"`
- `video_reduced: "{{ repo_root }}/assets/video"`
- `audio_reduced: "{{ repo_root }}/assets/audio"`

These variables represent **controller-side source locations** used by rsync/synchronize.

## Which sources are used (full vs reduced)

In `ansible/roles/base/tasks/main.yml`, Ansible chooses which source to sync using booleans:

- `sync_video` + `reduced_video`
- `sync_audio` + `reduced_audio`

The logic is:

- If `sync_video` and **NOT** `reduced_video`, then sync:
  - `src: "{{ video_full }}/"` -> `dest: "{{ video_dir }}"`
- If `sync_video` and `reduced_video`, then sync:
  - `src: "{{ video_reduced }}/"` -> `dest: "{{ video_dir }}"`

Similarly for audio:

- If `sync_audio` and **NOT** `reduced_audio`, then sync:
  - `src: "{{ audio_full }}/"` -> `dest: "{{ audio_dir }}"`
- If `sync_audio` and `reduced_audio`, then sync:
  - `src: "{{ audio_reduced }}/"` -> `dest: "{{ audio_dir }}"`

So:

- **Sources vary** (full vs reduced)
- **Destinations do not vary** (always `/home/{{ ansible_user }}/audio|video`)

## How Docker mounts media into the Apache container

In `ansible/roles/docker/templates/docker-compose.yml.j2`, the `apacheWebServer` service mounts the stable VM destinations into stable container paths:

- `"/home/{{ ansible_user }}/audio:/var/www/html/audio"`
- `"/home/{{ ansible_user }}/video:/var/www/html/video"`

This means:

- Whatever Ansible syncs into `/home/{{ ansible_user }}/audio|video` becomes visible in the container at:
  - `/var/www/html/audio`
  - `/var/www/html/video`

## Why Method 3A ffprobe probing can fail

Method 3A runs:

- `admin.php` UI upload
- `import_database.php`
- which invokes `tools/mysqlPrep_full.py`

This code runs **inside the Apache container**.

`mysqlPrep_full.py` uses `ffprobe` to compute:

- `duration_seconds`
- `media_info`
- `media_info_tool`

However, `ffprobe` can only succeed if the file path resolves to a real file inside the container.

### Common failure mode

- The Apache container has only a *reduced subset* mounted (because `reduced_audio` / `reduced_video` are true).
- The uploaded `database.csv` references basenames that are *not present* in that reduced subset.
- Additionally, the container may not export `MEDIA_SEARCH_DIRS` to tell `mysqlPrep_full.py` where to search.

In that case:

- the resolver in `mysqlPrep_full.py` cannot find the file
- `ffprobe` effectively runs against a non-existent path
- the probe fields remain empty
- MySQL import loads `NULL` for `duration_seconds`, `media_info`, and `media_info_tool`

## What should be configured for probing to work

### 1) The container must contain the correct files

Ensure the VM destinations (`/home/{{ ansible_user }}/audio|video`) contain the relevant files:

- Either sync full sets (`reduced_audio: false`, `reduced_video: false`)
- Or ensure the reduced sets include all basenames referenced by the imported CSV

### 2) The Apache container must set `MEDIA_SEARCH_DIRS`

`mysqlPrep_full.py` reads an env var:

- `MEDIA_SEARCH_DIRS` (colon-separated directory list)

Recommended container paths:

- `/var/www/html/audio:/var/www/html/video`

This should be emitted into `./apache/externalConfigs/.env` (templated from `.env.j2`).

## Variable usage audit (Ansible)

A search under `ansible/` found `audio_full`, `audio_reduced`, `video_full`, `video_reduced` used in:

- inventory group_vars files (definitions)
- `ansible/roles/base/tasks/main.yml` (as synchronize sources)

No other Ansible YAML/Jinja templates were using these variables at the time of writing.
