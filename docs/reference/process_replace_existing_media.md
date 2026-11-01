# Process: Replace Existing Media

## Purpose

This document explains the rationale for a dedicated CLI workflow to replace media that already exists in the `files` table, while preserving the existing logical relationships in the database.

The primary use case is:

- existing bandname or GigHive media rows already exist in `files`
- the replacement media should take over those same rows
- `file_id` values and existing `song_files` relationships should remain intact
- only the file bytes on disk and file metadata in `files` should change

This is intended for targeted administrative replacements, not for general ingestion of new media.

## Why a dedicated replacement process is needed

Several existing import and upload paths already exist in the repo, but they do not fit this specific workflow.

- **Bulk SQL import is too destructive**
  - `ansible/roles/docker/files/mysql/externalConfigs/load_and_transform.sql` truncates and reloads large portions of the database.
  - That is appropriate for full import/rebuild workflows, not for replacing a handful of existing files.

- **CSV prep utilities are for database rebuild/import workflows**
  - `ansible/roles/docker/files/mysql/dbScripts/loadutilities/` prepares CSVs for bulk loading.
  - These tools are useful when building or rebuilding datasets, but not when the goal is to keep existing `file_id` rows and just replace the underlying media and metadata.

- **The PHP upload form creates new file rows**
  - `ansible/roles/docker/files/apache/webroot/db/upload_form.php` feeds the normal upload API.
  - That path creates new rows in `files`, computes a new per-session `seq`, and links new uploads into the existing data model.
  - That is not the desired behavior when replacing an existing row in place.

- **`upload_media_by_hash.py` is close, but not enough by itself**
  - `ansible/roles/docker/files/apache/webroot/tools/upload_media_by_hash.py` is good at copying media whose `checksum_sha256` and `source_relpath` are already present in the database.
  - It can also refresh `duration_seconds`, `media_info`, and thumbnails.
  - However, it does not itself replace an existing row's `checksum_sha256`, `file_name`, `source_relpath`, or `size_bytes`.

Because of these limitations, a dedicated CLI replacement tool is the cleanest option.

## Why a CLI Python script is the preferred implementation

For this replacement workflow, a CLI Python script is preferable to a PHP admin page.

- **Lower blast radius**
  - This is an administrative operation that updates existing rows and replaces media bytes on disk.
  - A CLI tool is easier to constrain, dry-run, audit, and rerun safely.

- **Better fit for operational tasks**
  - This workflow involves hashing files, running `ssh`, running `rsync`, probing remote media with `ffprobe`, and optionally cleaning up old checksum-based files.
  - Those tasks are a more natural fit for a CLI tool.

- **Reuse of existing logic**
  - The existing `upload_media_by_hash.py` script already demonstrates the core patterns needed for:
    - MySQL queries and updates
    - remote path checks via `ssh`
    - file transfer via `rsync`
    - remote metadata probing with `ffprobe`
    - thumbnail generation
  - A new replacement CLI can reuse those patterns without exposing this operation as a browser workflow.

- **Safer iteration**
  - If this process needs to be done a few times, the CLI tool can mature first.
  - If it later becomes common enough to justify a browser UI, a PHP page can be added later as a thin wrapper around the CLI rather than duplicating the logic.

## Proposed implementation

## New file

- `ansible/roles/docker/files/apache/webroot/tools/replace_existing_media.py`

Version 1 should be a focused administrative script for replacing existing rows in `files` without altering the higher-level relationships.

## Functional goals

The new script should:

- discover replacement media files in a local directory
- compute each replacement file's SHA-256 and size
- identify the existing `files` row that should be replaced
- copy the replacement media to the server using the checksum-based storage path
- probe the remote media and refresh media metadata
- update the existing `files` row in place
- optionally regenerate thumbnails
- optionally delete the old checksum-named media and old thumbnail after a successful replacement
- write an audit/rollback snapshot before making changes

The script should not:

- create new rows in `files`
- create new `song_files` relationships
- change `session_id`
- renumber or regenerate `seq`
- use the general upload flow intended for new media

## Matching strategy

The script supports two matching modes.

### Canonical match mode

- `org_name`
- `event_date`
- `seq`

This is a good fit for bandname replacement files whose names already encode the track number.

Example filename:

`bandname20050721_4_RammingSpeed.mp4`

The script should parse:

- organization slug: `bandname`
- event date: `2005-07-21`
- sequence number: `4`
- extension: `mp4`

In canonical mode, the script matches the existing row by querying the `files` table joined to the correct event/session context for that date and organization.

This mode is the preferred path for data created through the newer upload flow, where `files.session_id` and `files.seq` are already populated.

### Legacy fallback mode

Some older bandname/admin-managed datasets were loaded through an older import path that created `files` rows with:

- `session_id = NULL`
- `seq = NULL`

For those rows, the script falls back to matching by filename pattern.

In legacy fallback mode, the script:

- scans `files.file_name` and `files.source_relpath`
- parses the same filename pattern used for local replacement files
- extracts organization slug, event date, and sequence number from those stored names
- matches the replacement file to the existing `file_id` by parsed date and parsed `seq`

This fallback is intended specifically for older imported media rows on admin-managed servers. It allows replacements without requiring a one-time schema/data normalization step first.

### Why `seq` matching is still preferred

- it is deterministic for this use case
- it avoids title-based ambiguity
- the schema already enforces uniqueness of `(session_id, seq)`
- it preserves the existing row rather than creating a replacement row

### Manifest mode remains a future option

If needed later, the script can support a manifest-based mode where the user explicitly maps local files to `file_id` values. That is not required for the initial version.

## Proposed behavior

## Inputs

The first version should accept inputs such as:

- `--source-dir`
- `--group-vars`
- `--no-group-vars`
- `--org-name`
- `--event-date`
- `--file-type`
- `--ssh-target`
- `--dest-audio`
- `--dest-video`
- `--db-host`
- `--db-port`
- `--db-user`
- `--db-name`
- `--dry-run`
- `--no-thumbs`
- `--force-thumb`
- `--delete-old-remote-blobs`
- `--backup-csv`

## Media extension config resolution

The script resolves media extension config in the same order as `upload_media_by_hash.py`.

- environment variables first
- Ansible `group_vars` YAML as a fallback source
- built-in defaults if neither source provides a valid config

The relevant environment variables are:

- `UPLOAD_ALLOWED_MIMES_JSON`
- `UPLOAD_AUDIO_EXTS_JSON`
- `UPLOAD_VIDEO_EXTS_JSON`

The relevant YAML keys are:

- `gighive_upload_allowed_mimes`
- `gighive_upload_audio_exts`
- `gighive_upload_video_exts`

The replacement script uses the resolved `audio_exts` and `video_exts` sets when inferring media type from file extensions.

`--group-vars` lets the operator point to a specific YAML file to use as the fallback source.

`--no-group-vars` skips YAML fallback and uses only env-provided config or the built-in defaults.

If `--db-password` is omitted, the script prompts for the MySQL password interactively.

## Preflight behavior

Before any write occurs, the script should:

- scan the local directory for media files
- parse filenames to derive the `seq`
- compute local SHA-256 values
- compute local file sizes
- query candidate `files` rows for the requested `org_name` and `event_date`
- try canonical matching first using linked `session_id` + `seq`
- if no canonical rows are found, try legacy filename-based matching against existing `file_name` / `source_relpath`
- verify that each local file maps to exactly one existing row
- verify that no replacement SHA already belongs to some other `file_id`
- verify that file types and extensions are acceptable
- write a dry-run plan or backup snapshot showing old and new values

If any ambiguity or conflict is detected, the script should stop before making changes.

By default, the script applies changes when run normally. Passing `--dry-run` switches it into preview mode.
When run without `--dry-run`, the script automatically uploads/copies the replacement media to the server, updates the matching `files` rows, and generates or refreshes thumbnails for videos unless `--no-thumbs` is used.

## Apply behavior

When the script is run without `--dry-run`, it should process each file as follows:

- locate the existing target row in `files`
- capture the current row values for audit/rollback
- copy the replacement media to the remote checksum-based destination path
  - for videos: `/home/ubuntu/video/<checksum>.<ext>`
  - for audio: `/home/ubuntu/audio/<checksum>.<ext>`
- run remote `ffprobe` against the copied file
- update the target `files` row with the new:
  - `file_name`
  - `source_relpath`
  - `checksum_sha256`
  - `size_bytes`
  - `duration_seconds`
  - `media_info`
  - `media_info_tool`
- generate or refresh the thumbnail for videos
- optionally remove the old checksum-based remote media file and old thumbnail after success

## Safety rules

The script should be conservative.

- **Dry-run remains the recommended first step**
  - The operator should confirm the mapping and planned changes before running the same command again without `--dry-run`.

- **Hard failure conditions should stop execution**
  - duplicate local `seq`
  - missing target DB row
  - ambiguous match
  - SHA collision with a different row
  - failed copy
  - failed DB update

- **Audit/rollback information should always be written before changes are applied**
  - The script should save a machine-readable record of old and new values for every row it intends to change.

## Expected outputs

The script should print concise per-file status lines and a final summary.

Examples of statuses:

- `PLAN`
- `COPIED`
- `UPDATED`
- `THUMBNAIL_CREATED`
- `THUMBNAIL_EXISTS`
- `OLD_REMOTE_DELETED`
- `FAILED`

The final summary should include counts for:

- matched files
- copied files
- updated rows
- thumbnails created
- cleanup actions performed
- failures

The dry-run plan and backup CSV also record the matching mode used for each row:

- `canonical`
- `legacy`

When the script is run without `--dry-run`, it writes a backup CSV automatically if `--backup-csv` was not supplied.

## Why this approach preserves data integrity

This design deliberately keeps the authoritative database relationships stable.

- `file_id` remains the same
- `song_files` rows remain valid
- session/event associations remain unchanged
- only the media payload and directly related file metadata are updated

That makes this process much safer than a delete-and-reinsert flow.

## Relationship to existing tools

This new script is not meant to replace the other existing workflows.

- Use the normal upload form for adding new media
- Use the CSV/SQL import tools for dataset import or rebuild workflows
- Use the replacement CLI only when an existing media row should keep its identity but receive new bytes and updated file metadata

## Example: replacing the bandname 2005-07-21 videos

The following replacement files exist locally.

In this example, the script is run from the development machine that already has the replacement video files on disk. The script reads those local files from `--source-dir`, then connects to the bandname server over SSH and to MySQL using the supplied database credentials.

```bash
cd /mnt/scottsfiles/videos/bandname/20050721
ll bandname20050721_*.mp4
```

```text
-rw-rw-r-- 1 sodo sodo  95183925 Mar 13 17:37 bandname20050721_10_SympathyforelSpaceDiablo.mp4
-rw-rw-r-- 1 sodo sodo 223094286 Mar 13 17:39 bandname20050721_11_TheJokerDrunkenBoxingremix-MyScrotum.mp4
-rw-rw-r-- 1 sodo sodo 327778311 Mar 13 17:42 bandname20050721_12_DidntKnowWhatIDidntKnow.mp4
-rw-rw-r-- 1 sodo sodo 321203523 Mar 13 17:45 bandname20050721_13_NoTurningBack.mp4
-rw-rw-r-- 1 sodo sodo 294008643 Mar 13 17:25 bandname20050721_1_Exposed.mp4
-rw-rw-r-- 1 sodo sodo 554401634 Mar 13 17:07 bandname20050721_2_DeathValley.mp4
-rw-rw-r-- 1 sodo sodo 407042381 Mar 13 17:26 bandname20050721_3_PumpkinSongRockAgainstTerror.mp4
-rw-rw-r-- 1 sodo sodo 225552107 Mar 13 17:13 bandname20050721_4_RammingSpeed.mp4
-rw-rw-r-- 1 sodo sodo  31987370 Mar 13 17:27 bandname20050721_5_filthyinterlude.mp4
-rw-rw-r-- 1 sodo sodo 324270065 Mar 13 17:29 bandname20050721_6_NothingbutBadNoose.mp4
-rw-rw-r-- 1 sodo sodo 398190939 Mar 13 17:31 bandname20050721_7_IsThistheCaribbean.mp4
-rw-rw-r-- 1 sodo sodo  62232050 Mar 13 17:33 bandname20050721_8_TownCalledMalice.mp4
-rw-rw-r-- 1 sodo sodo 267312136 Mar 13 17:35 bandname20050721_9_Sparks.mp4
```

These filenames are suitable for the proposed v1 matching logic because they encode:

- the organization: `bandname`
- the event date: `2005-07-21`
- the per-row sequence number: `1` through `13`

On newer data loaded through the current upload flow, the script should resolve these files through canonical mode.

On older bandname/admin-managed datasets where `files.session_id` and `files.seq` are still `NULL`, the script should automatically fall back to legacy filename-based matching and still produce a valid replacement plan.

### Example dry-run

```bash
python3 ansible/roles/docker/files/apache/webroot/tools/replace_existing_media.py \
  --source-dir /mnt/scottsfiles/videos/bandname/20050721 \
  --org-name bandname \
  --event-date 2005-07-21 \
  --file-type video \
  --ssh-target ubuntu@gighive2 \
  --db-host gighive2 \
  --db-user root \
  --db-name music_db \
  --backup-csv /tmp/bandname-20050721-replacements.csv \
  --dry-run
```

This dry-run should:

- parse the 13 filenames
- match them to existing bandname rows for `2005-07-21`
- compute new SHA-256 values and file sizes
- verify there are no checksum collisions
- log which media config source was used and the entry counts
- print the old and new values that would be applied
- write the backup CSV snapshot

### Example apply

```bash
python3 ansible/roles/docker/files/apache/webroot/tools/replace_existing_media.py \
  --source-dir /mnt/scottsfiles/videos/bandname/20050721 \
  --org-name bandname \
  --event-date 2005-07-21 \
  --file-type video \
  --ssh-target ubuntu@gighive2 \
  --db-host gighive2 \
  --db-user root \
  --db-name music_db \
  --backup-csv /tmp/bandname-20050721-replacements.csv \
  --force-thumb
```

If desired, an additional cleanup mode can be enabled later:

```bash
python3 ansible/roles/docker/files/apache/webroot/tools/replace_existing_media.py \
  --source-dir /mnt/scottsfiles/videos/bandname/20050721 \
  --org-name bandname \
  --event-date 2005-07-21 \
  --file-type video \
  --ssh-target ubuntu@gighive2 \
  --db-host gighive2 \
  --db-user root \
  --db-name music_db \
  --backup-csv /tmp/bandname-20050721-replacements.csv \
  --force-thumb \
  --delete-old-remote-blobs
```

## Summary

The dedicated replacement CLI is the right tool for the case where existing `files` rows should keep their identity while their media payload and file metadata are updated. It avoids the risks of the bulk import path, avoids creating new rows through the normal upload flow, and keeps all existing relationships intact while still allowing remote storage, metadata refresh, and thumbnail generation to remain consistent.
