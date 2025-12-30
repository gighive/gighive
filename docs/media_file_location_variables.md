# Media file location variables

This document explains the various variables that reference media (video/audio) file locations in the GigHive Ansible + Docker deployment, and clarifies whether they are redundant or represent different locations.

## High-level model

There are three distinct “namespaces” where paths exist:

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

## Controller-side source directories (inputs to Ansible sync)

These are the *sources* that Ansible’s `synchronize` tasks push to the VM.

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

## Container paths (where Apache/PHP sees media)

These are paths inside the Apache container.

- **`media_search_dir_video`**
  - Example: `/var/www/html/video`
  - Meaning: container path where the video directory is mounted.

- **`media_search_dir_audio`**
  - Example: `/var/www/html/audio`
  - Meaning: container path where the audio directory is mounted.

- **`media_search_dirs`**
  - Example: `{{ media_search_dir_audio }}:{{ media_search_dir_video }}`
  - Meaning: convenience aggregation for code/config that searches across both.

These are not redundant with `video_dir`/`audio_dir`:

- `video_dir`/`audio_dir` are **VM host** paths.
- `media_search_dir_*` are **container** paths.

They refer to the same underlying files only because Docker bind-mounts connect them.

## Docker bind mounts (host ↔ container mapping)

Docker Compose binds VM host media directories into the Apache container:

- Host: `/home/{{ ansible_user }}/video` → Container: `{{ media_search_dir_video }}` (e.g., `/var/www/html/video`)
- Host: `/home/{{ ansible_user }}/audio` → Container: `{{ media_search_dir_audio }}` (e.g., `/var/www/html/audio`)

So `video_dir` and `media_search_dir_video` ultimately point at the same files, but from different environments.

## Script-specific destinations (non-web upload flow)

- **`upload_media_by_hash.py --dest-video`**
  - Default: `/home/ubuntu/video`
  - Meaning: VM-host destination directory used by that script when copying videos.

This is intended to match `video_dir`.

## Potential redundancy / drift risk

While most variables represent different locations, there is one important “drift risk”:

- **`video_dir` (Ansible) vs `--dest-video` default (script)**
  - These should refer to the same VM-host directory.
  - If one changes and the other does not, media/thumbnail generation and serving can become inconsistent.

Similarly:

- **If `media_search_dir_video` changes**, you must ensure Docker binds and any URLs/serving assumptions update accordingly.

## Practical takeaway

- **Different-by-design**
  - `video_full` / `video_reduced` (controller sources)
  - `video_dir` (VM host destination)
  - `media_search_dir_video` (container path)

- **Same files via bind mount**
  - `video_dir` (host) ↔ `media_search_dir_video` (container)

- **Keep aligned**
  - `video_dir` ↔ `upload_media_by_hash.py --dest-video`
