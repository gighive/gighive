# Change 2025-03-14: Align `upload_media_by_hash.py` With PHP Upload Runtime Config

## Summary

This document records the agreed direction for `ansible/roles/docker/files/apache/webroot/tools/upload_media_by_hash.py`.

The Python script should align with the same runtime media configuration path already used by the PHP upload flow.

No code changes are included in this document. This is a change note and implementation plan.

## Current State

### PHP upload path

The PHP upload path reads media validation configuration from environment variables via:

- `ansible/roles/docker/files/apache/webroot/src/Config/MediaTypes.php`
- `ansible/roles/docker/files/apache/webroot/src/Validation/UploadValidator.php`

Those env vars are populated by Ansible in:

- `ansible/roles/docker/templates/.env.j2`

The current env vars are:

- `UPLOAD_ALLOWED_MIMES_JSON`
- `UPLOAD_AUDIO_EXTS_JSON`
- `UPLOAD_VIDEO_EXTS_JSON`

### Python upload-media script path

The Python script currently reads extension lists directly from Ansible `group_vars` YAML via:

- `gighive_upload_audio_exts`
- `gighive_upload_video_exts`

It does not currently use:

- `gighive_upload_allowed_mimes`

It also does not currently load the corresponding runtime env vars used by PHP.

## Decision

`upload_media_by_hash.py` should be aligned to the same runtime configuration source as the PHP upload path.

### Desired config precedence

The script should resolve media config in this order:

1. Runtime env JSON config
2. `group_vars` YAML fallback
3. Built-in defaults

### Runtime env keys to use

The script should read:

- `UPLOAD_ALLOWED_MIMES_JSON`
- `UPLOAD_AUDIO_EXTS_JSON`
- `UPLOAD_VIDEO_EXTS_JSON`

These should be parsed as JSON arrays, normalized, deduplicated, and then used as the effective script config.

## Scope of the planned Python change

The change should be narrow.

### In scope

- Add env-first loading of media config
- Preserve `group_vars` fallback for local/manual execution
- Preserve built-in defaults as final fallback
- Load `allowed_mimes`, `audio_exts`, and `video_exts` into a single resolved config path
- Improve startup diagnostics to show which config source was used

### Out of scope for this change

- No change to DB query behavior
- No change to copy/rsync behavior
- No change to thumbnail generation behavior
- No MIME probing or rejection logic unless separately approved

## Why this direction was chosen

The script is expected to operate in the same deployed/runtime environment as the upload stack.

Aligning it to the PHP runtime config path reduces drift between:

- upload validation behavior in PHP
- file classification behavior in Python tooling
- Ansible-managed deployment configuration

This also preserves usability when the script is run outside the deployed environment by falling back to `group_vars` and then built-in defaults.

## Exact implementation intent

The planned refactor for `upload_media_by_hash.py` is:

- add a loader for runtime env JSON arrays
- extend the script config model to include `allowed_mimes`
- keep extension-based audio/video inference unchanged
- switch startup config resolution to env first, then YAML, then defaults
- update CLI/help text to reflect that `group_vars` is now a fallback source

## Related files

- `ansible/roles/docker/files/apache/webroot/tools/upload_media_by_hash.py`
- `ansible/roles/docker/files/apache/webroot/src/Config/MediaTypes.php`
- `ansible/roles/docker/files/apache/webroot/src/Validation/UploadValidator.php`
- `ansible/roles/docker/templates/.env.j2`
- `ansible/inventories/group_vars/gighive2/gighive2.yml`

## Follow-up

A related Python script also loads upload extension config directly from `group_vars`:

- `ansible/roles/docker/files/apache/webroot/tools/convert_legacy_database_csv_to_normalized.py`

That script should be reviewed and refactored separately so Python tooling uses a consistent media config path.
