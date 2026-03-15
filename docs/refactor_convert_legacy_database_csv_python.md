# Refactor Reminder: Align `convert_legacy_database_csv_to_normalized.py` With Runtime Media Config

## Purpose

This document is a reminder to refactor:

- `ansible/roles/docker/files/apache/webroot/tools/convert_legacy_database_csv_to_normalized.py`

so that it uses the same runtime media configuration path as the PHP upload flow and the planned `upload_media_by_hash.py` update.

No code changes are included in this document.

## Current State

The script currently reads upload media extension config directly from Ansible `group_vars` YAML.

It loads:

- `gighive_upload_audio_exts`
- `gighive_upload_video_exts`

It does not currently load runtime env config first.

## Refactor Goal

Make the script resolve media config using the same precedence planned for `upload_media_by_hash.py`:

1. Runtime env JSON config
2. `group_vars` YAML fallback
3. Built-in defaults

## Runtime env vars to support

The script should read:

- `UPLOAD_ALLOWED_MIMES_JSON`
- `UPLOAD_AUDIO_EXTS_JSON`
- `UPLOAD_VIDEO_EXTS_JSON`

Even if the script only needs extension lists for file classification today, it should load the full media config shape for consistency.

## Why this matters

This keeps Python tooling aligned with:

- PHP upload validation
- Ansible-managed deployment config
- the planned `upload_media_by_hash.py` refactor

Without this change, Python scripts may drift from the effective runtime config used by the deployed application.

## Suggested implementation direction

Refactor the script to:

- add a shared-style loader for env JSON arrays
- normalize and deduplicate values
- fall back to `group_vars` only when env config is unavailable
- preserve built-in defaults as the last fallback
- emit a clear startup message showing which config source was used

## Candidate follow-up improvement

If more Python utilities need the same media-config resolution, consider extracting the shared logic into a reusable helper module rather than duplicating it across scripts.

## Related files

- `ansible/roles/docker/files/apache/webroot/tools/convert_legacy_database_csv_to_normalized.py`
- `ansible/roles/docker/files/apache/webroot/tools/upload_media_by_hash.py`
- `ansible/roles/docker/files/apache/webroot/src/Config/MediaTypes.php`
- `ansible/roles/docker/templates/.env.j2`
