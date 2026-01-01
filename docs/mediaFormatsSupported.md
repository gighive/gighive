# Supported Media Formats

This document describes the media formats GigHive supports for ingestion, and how the Ansible `group_vars` configuration is intended to drive the PHP upload validation and media-type inference.

## Summary

GigHive ingestion support is defined in two ways:

- **MIME type allowlist (preferred):** Server-side MIME sniffing is used during upload validation.
- **File-extension fallback:** If MIME is missing/empty/unreliable, the system falls back to file extensions to classify media as `audio` vs `video`.

## Policy (how we decide what is “supported”)

- GigHive uses a **curated allowlist** of extensions and MIME types (configured in Ansible `group_vars`).
- This list is intentionally limited to formats we expect real users to have and that we can reasonably support.
- Even for allowlisted formats, **`ffprobe` on the server is the ultimate truth** for whether a file is usable media.

## Supported Video Formats

### Video containers / extensions

- `avi`
- `flv`
- `bup`
- `ifo`
- `m2t`
- `m2v`
- `m2ts`
- `m4v`
- `mkv`
- `mov`
- `mp4`
- `mpeg`
- `mpg`
- `mxf`
- `ogv`
- `rm`
- `rmvb`
- `rv`
- `ts`
- `vob`
- `webm`
- `wmv`

### Common MIME types (explicit allowlist)

- `video/mp4`
- `video/quicktime`
- `video/x-matroska`
- `video/webm`
- `video/x-msvideo`
- `video/mpeg`
- `video/mp2t`
- `video/MP2T`
- `video/ogg`
- `video/x-flv`
- `application/mxf`
- `video/mxf`
- `application/vnd.rn-realmedia`
- `audio/vnd.rn-realaudio`
- `audio/x-pn-realaudio`
- `video/vnd.rn-realvideo`
- `video/x-ms-wmv`
- `video/x-ms-asf`
- `application/vnd.ms-asf`

## Supported Audio Formats

### Audio containers / extensions

- `aac`
- `aif`
- `aifc`
- `aiff`
- `au`
- `flac`
- `m4a`
- `m2a`
- `mp3`
- `oga`
- `ogg`
- `wav`

### Common MIME types (explicit allowlist)

- `audio/mpeg`
- `audio/mp3`
- `audio/wav`
- `audio/x-wav`
- `audio/aac`
- `audio/flac`
- `audio/mp4`
- `audio/aiff`
- `audio/x-aiff`
- `audio/ogg`
- `application/ogg`
- `audio/vorbis`
- `audio/basic`
- `audio/x-au`
- `audio/au`
- `audio/mp2`
- `audio/mpeg2`

## How `group_vars` is used (Ansible -> Docker env -> PHP)

When centralized configuration is enabled, the ingestion allowlists are intended to be defined in the dev inventory here:

- `ansible/inventories/group_vars/gighive2/gighive2.yml`

Suggested variables (names may vary slightly, but the intent is the same):

- `gighive_upload_allowed_mimes` (list)
- `gighive_upload_audio_exts` (list)
- `gighive_upload_video_exts` (list)

Those values are then rendered by Ansible into the Apache container’s `.env` file:

- Template: `ansible/roles/docker/templates/.env.j2`
- Rendered output: `{{ docker_dir }}/apache/externalConfigs/.env`

Docker Compose loads that `.env` into the Apache/PHP-FPM container environment:

- `ansible/roles/docker/templates/docker-compose.yml.j2`
- `env_file: ./apache/externalConfigs/.env`

Inside the container:

- PHP loads `.env` using `vlucas/phpdotenv` (see `ansible/roles/docker/files/apache/webroot/config.php`).
- PHP-FPM is configured with `clear_env = no` (see `ansible/roles/docker/templates/www.conf.j2`), so container env vars remain visible to PHP.

PHP then uses the allowlists for:

- **Upload validation:** Prefer the explicit MIME allowlist, with a permissive fallback for `audio/*` and `video/*` types.
- **Type inference fallback:** Map file extensions to `audio` vs `video` when MIME is missing or unusable.

## Caveats

### AV1

AV1 is a **codec**, not a file extension or container format. You typically encounter AV1 in containers like `.mp4`, `.mkv`, or `.webm`. Since those containers are supported, AV1 content should work without adding a special `.av1` extension.

### RED / RED One (`.r3d`)

RED `.r3d` is a proprietary RAW camera format. Even if it is allowlisted by extension, ingestion may still fail downstream (MIME sniffing may report `application/octet-stream`, and tooling like `ffprobe`/`ffmpeg` may not reliably parse it in the current build). For now, `.r3d` is intentionally not included in the supported lists.
