# Media file location variables

This document explains the various variables that reference media (video/audio) file locations in the GigHive Ansible + Docker deployment, and clarifies whether they are redundant or represent different locations.

## High-level model

There are three distinct "namespaces" where paths exist:

1. **Controller (your workstation)**
   - The machine running Ansible.
   - This is where source media directories exist for sync.

2. **VM host filesystem**
   - The target machine being provisioned (e.g., `gighive2`).
   - This is where media ultimately lives on disk (e.g., `/home/ubuntu/video`).

3. **Container filesystem (Apache/PHP)**
   - The Apache container has a bind mount that maps VM-host media directories into the container.
   - PHP/Apache uses container paths (e.g., `/var/www/html/video`).

Most variables are not redundant: they point at different namespaces.

## Storage backend modes (Phase 5+)

`GIGHIVE_MEDIA_STORAGE_BACKEND` controls how PHP reads and writes media blobs. Three modes exist:

### `local` mode (VirtualBox / baremetal)

- Bind mounts from the VM host (`{{ video_dir }}`, `{{ audio_dir }}`) are present in the compose file and map into the container at `{{ media_local_video_dir }}` and `{{ media_local_audio_dir }}`.
- PHP reads and writes via `LocalMediaBackend` using `fopen`/`fread` against those container paths.
- Upload writes go through `LocalFileTusBackend` → `LocalMediaBackend::put()`.
- `MEDIA_SEARCH_DIRS` is **retired** and must not be present in the container environment (verified by T-68).

### `azure_blob` mode (cloud production — Phase 11+)

- No local media bind mounts are present in the compose file.
- PHP reads and writes via `AzureBlobMediaBackend` over the Azure Blob Storage REST API.
- `MEDIA_LOCAL_*` container paths are unused in this mode.
- Upload writes go through the PHP Block Blob server (Phase 11) → `AzureBlobMediaBackend::put()`.

### `azure_blob_with_local_fallback` mode (Tranche 2 / Phase 11 backfill only)

- Both bind mounts and Blob are configured simultaneously during the backfill window.
- PHP tries Blob first; falls back to local for assets not yet backfilled.
- Remove after Phase 11 step 9 is verified stable.

## Controller-side source directories (inputs to Ansible sync)

These are the *sources* that Ansible's `synchronize` tasks push to the VM.

- **`video_full`**
  - Example: `/home/sodo/videos/stormpigs/finals/singles/`
  - Meaning: full video library on the controller.

- **`video_reduced`**
  - Example: `{{ repo_root }}/assets/video`
  - Meaning: reduced/sample video library in the repo on the controller.

- **`audio_full`**
  - Example: `/home/sodo/scripts/stormpigsCode/production/audio/`
  - Meaning: full audio library on the controller.

- **`audio_reduced`**
  - Example: `{{ repo_root }}/assets/audio`
  - Meaning: reduced/sample audio library in the repo on the controller.

These are similar conceptually, but not redundant: they represent **full vs reduced** datasets.

## VM host destination directories

These are the *destinations* on the VM host filesystem.

- **`video_dir`**
  - Defined in `ansible/playbooks/site.yml` (via `set_fact`):
    - `root_dir: "{{ '/root' if ansible_user == 'root' else '/home/' ~ ansible_user }}"`
    - `video_dir: "{{ root_dir }}/video"`
  - Typically resolves to:
    - `/home/{{ ansible_user }}/video` (e.g., `/home/ubuntu/video`)

- **`audio_dir`**
  - Also defined in `ansible/playbooks/site.yml` as:
    - `audio_dir: "{{ root_dir }}/audio"`
  - Typically resolves to:
    - `/home/{{ ansible_user }}/audio` (e.g., `/home/ubuntu/audio`)

Ansible `synchronize` tasks push to `{{ video_dir }}` and `{{ audio_dir }}`.

## Container paths (where PHP/LocalMediaBackend sees media)

These are paths inside the Apache container. They correspond to the `MEDIA_LOCAL_*` env vars written by `.env.j2` and read by `MediaStorageService::make()` to construct `LocalMediaBackend`.

- **`media_local_audio_dir`** → env var `MEDIA_LOCAL_AUDIO_DIR`
  - Example: `/var/www/html/audio`
  - Meaning: container path where the audio directory is bind-mounted; `LocalMediaBackend` reads from here.

- **`media_local_video_dir`** → env var `MEDIA_LOCAL_VIDEO_DIR`
  - Example: `/var/www/html/video`
  - Meaning: container path where the video directory is bind-mounted.

- **`media_local_thumb_dir`** → env var `MEDIA_LOCAL_THUMB_DIR`
  - Example: `/var/www/html/video/thumbnails`
  - Meaning: container path for thumbnail PNG files (subdirectory of video).

These are not redundant with `video_dir`/`audio_dir`:

- `video_dir`/`audio_dir` are **VM host** paths.
- `media_local_*` are **container** paths.

They refer to the same underlying files only because Docker bind-mounts connect them.

## Phase 1 env vars (container-side, set by `.env.j2`)

| Env var | Group var | Default | Purpose |
|---|---|---|---|
| `GIGHIVE_MEDIA_STORAGE_BACKEND` | `gighive_media_storage_backend` | `local` | Selects the storage backend (`local` / `azure_blob` / `azure_blob_with_local_fallback`) |
| `MEDIA_LOCAL_AUDIO_DIR` | `media_local_audio_dir` | `/var/www/html/audio` | Container path to audio files; read by `LocalMediaBackend` |
| `MEDIA_LOCAL_VIDEO_DIR` | `media_local_video_dir` | `/var/www/html/video` | Container path to video files; read by `LocalMediaBackend` |
| `MEDIA_LOCAL_THUMB_DIR` | `media_local_thumb_dir` | `/var/www/html/video/thumbnails` | Container path to thumbnail PNGs; read by `LocalMediaBackend` |
| `TUS_LOCAL_STAGING_DIR` | `tus_local_staging_dir` | `/tmp/tus-staging` | Container path for in-progress TUS upload chunks |

## Retired variable

| Env var | Status | Replacement |
|---|---|---|
| `MEDIA_SEARCH_DIRS` | **Retired in Phase 5** | `MEDIA_LOCAL_AUDIO_DIR` + `MEDIA_LOCAL_VIDEO_DIR` + `MEDIA_LOCAL_THUMB_DIR` |

`MEDIA_SEARCH_DIRS` was a colon-separated string used by the old admin PHP scripts to discover media directories. It is no longer emitted by `.env.j2` and must not appear in the container environment. T-68 in `post_build_checks` verifies its absence on every deploy.

The group vars `media_search_dir_audio`, `media_search_dir_video`, and `media_search_dirs` remain in group_vars files for historical reference but are no longer consumed by any template or role.

## Docker bind mounts (host ↔ container mapping)

Docker Compose binds VM host media directories into the Apache container (local mode only):

- Host: `{{ audio_dir }}` → Container: `{{ media_local_audio_dir }}` (e.g., `/var/www/html/audio`)
- Host: `{{ video_dir }}` → Container: `{{ media_local_video_dir }}` (e.g., `/var/www/html/video`)
- Host: `{{ video_dir }}/thumbnails` → Container: `{{ media_local_thumb_dir }}` (e.g., `/var/www/html/video/thumbnails`)

So `video_dir` and `media_local_video_dir` ultimately point at the same files, but from different environments.

## Script-specific destinations (non-web upload flow)

- **`upload_media_by_hash.py --dest-video`**
  - Default: `/home/ubuntu/video`
  - Meaning: VM-host destination directory used by that script when copying videos.

This is intended to match `video_dir`. Python DB load tools are host-side and are unaffected by the `MEDIA_SEARCH_DIRS` retirement — they read the env var directly from the host, not from the container.

## Potential redundancy / drift risk

While most variables represent different locations, there are two important "drift risks":

- **`video_dir` (Ansible) vs `--dest-video` default (script)**
  - These should refer to the same VM-host directory.
  - If one changes and the other does not, media/thumbnail generation and serving can become inconsistent.

- **`media_local_video_dir` (group var) vs the bind mount target in `docker-compose.yml.j2`**
  - These must agree. If the compose bind mount target changes, `MEDIA_LOCAL_VIDEO_DIR` must change to match or `LocalMediaBackend` will not find files.

## Practical takeaway

- **Different-by-design**
  - `video_full` / `video_reduced` (controller sources)
  - `video_dir` (VM host destination)
  - `media_local_video_dir` / `MEDIA_LOCAL_VIDEO_DIR` (container path, local mode)

- **Same files via bind mount (local mode only)**
  - `video_dir` (host) ↔ `media_local_video_dir` (container)

- **Keep aligned**
  - `video_dir` ↔ `upload_media_by_hash.py --dest-video`
  - `media_local_video_dir` ↔ compose bind mount target
