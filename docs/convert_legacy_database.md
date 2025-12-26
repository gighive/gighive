# Convert Legacy database.csv to Normalized CSVs (with SHA-256)

This document describes how to use:

- `ansible/roles/docker/files/apache/webroot/tools/convert_legacy_database_csv_to_normalized.py`

to convert a legacy `database.csv` (where per-session file lists are embedded in `f_singles`) into the normalized inputs used by **Admin → Section 3B**:

- `sessions.csv`
- `session_files.csv`

and (optionally) compute **SHA-256 checksums** for those files so downstream tooling like `upload_media_by_hash.py` works.

## Outputs

The converter writes to `--output-dir` (default: `normalized_csvs/`):

- `sessions.csv`
  - Contains `session_key` plus all legacy session metadata columns **except** `f_singles`.
- `session_files.csv`
  - Contains file references for each session.
  - Columns:
    - `session_key`
    - `source_relpath`
    - `checksum_sha256`
    - `seq`
- `conversion_report.txt`
  - Summary counts and warnings (first 200).

## How `checksum_sha256` is computed

The converter will attempt to compute `checksum_sha256` (SHA-256) **if it can locate the file on disk**.

It resolves each `source_relpath` token in this order:

1. If the token is an absolute path and exists, use it.
2. If `--source-root` is provided, try:
   - `<source-root>/<source_relpath>`
3. If `--media-search-dirs` is provided, search by basename in each dir:
   - `<dir>/<basename(source_relpath)>`

If the file can’t be found (or hashing fails), `checksum_sha256` is left blank and the issue is recorded in `conversion_report.txt`.

## CLI options

- `input_csv`
  - Path to the legacy `database.csv`.
- `--output-dir`
  - Directory to write outputs (default: `normalized_csvs`).
- `--source-root`
  - Optional base directory used to resolve `source_relpath` for hashing.
  - Can also be set via env var `GIGHIVE_SOURCE_ROOT`.
- `--media-search-dirs`
  - Optional colon-separated list of directories to search by basename for hashing.
  - Can also be set via env var `MEDIA_SEARCH_DIRS`.

- `--no-progress`
  - Disable the live progress meter.

- `--progress-every-seconds`
  - Progress meter update interval in seconds (default: `0.5`).
  - Progress is printed to `stderr` and updates on a single line.

- `--manifest-include-missing`
  - By default, `manifest.json` only includes items with a valid `checksum_sha256`.
  - This keeps it compatible with the destructive manifest reload endpoint, which rejects items with missing/invalid checksums.
  - Use this flag only if you explicitly want missing-checksum items included.

## High level steps to converting legacy database

1) Convert legacy CSV → produce 3B inputs

Run `convert_legacy_database_csv_to_normalized.py` on `database.csv` to generate:

- `sessions.csv`
- `session_files.csv`

2) Run Section 3B (`import_normalized.php`) with those CSVs

This is the step that actually populates:
into /var/www/private/normalized_csvs/<dated folder>
- `musicians`
- `session_musicians`
- `songs` (from `d_merged_song_lists`)
- link tables (`session_songs`, `song_files`, etc.)

3) Run `upload_media_by_hash.py`

This copies media and fills:

- `duration_seconds`
- `media_info`
- `media_info_tool`

It won't change song titles or `source_relpath`.

**Important note**

Do not run `import_manifest_reload.php` in this workflow unless you specifically want the "manifest-only" rebuild (it will wipe musicians again).

## Examples

### Example 1: `source_relpath` is relative to a known root

If your legacy `f_singles` values are relative paths under the folder you want to upload from:

```bash
python3 ansible/roles/docker/files/apache/webroot/tools/convert_legacy_database_csv_to_normalized.py \
  /path/to/legacy_database.csv \
  --output-dir normalized_csvs \
  --source-root /home/sodo/videos/stormpigs/finals/singles
```

### Example 2: Use basename searching for messy tokens

If tokens don’t line up as clean relative paths, but the basenames exist in one or more directories:

```bash
MEDIA_SEARCH_DIRS="/home/sodo/videos/stormpigs/finals/singles:/some/other/dir" \
python3 ansible/roles/docker/files/apache/webroot/tools/convert_legacy_database_csv_to_normalized.py \
  /path/to/legacy_database.csv \
  --output-dir normalized_csvs
```

### Example 3: Hash across multiple roots (separate video + audio directories)

If your legacy `source_relpath` tokens are just filenames (or relative paths), but the underlying media files are split across multiple base directories (e.g. video in one folder, audio in another), provide both roots.

```bash
python3 ansible/roles/docker/files/apache/webroot/tools/convert_legacy_database_csv_to_normalized.py \
  /path/to/legacy_database.csv \
  --output-dir normalized_csvs \
  --source-roots "/home/sodo/videos/stormpigs/finals/singles:/home/sodo/scripts/stormpigsCode/production/audio"
```

You can also set:

```bash
export GIGHIVE_SOURCE_ROOTS="/home/sodo/videos/stormpigs/finals/singles:/home/sodo/scripts/stormpigsCode/production/audio"
```

## How this feeds into Admin Section 3B + upload-by-hash

1. Run the converter to generate:
   - `sessions.csv`
   - `session_files.csv` (ideally with `checksum_sha256` populated)
2. In the GigHive admin UI:
   - Use **Section 3B (Normalized)** to upload `sessions.csv` + `session_files.csv`.
3. After import, you can upload media to the server using:
   - `ansible/roles/docker/files/apache/webroot/tools/upload_media_by_hash.py`

Example uploader command (multi-root):

```bash
MYSQL_PASSWORD='musiclibrary' python3 \
  ~/scripts/gighive/ansible/roles/docker/files/apache/webroot/tools/upload_media_by_hash.py \
  --source-root /home/sodo/videos/stormpigs/finals/singles \
  --source-roots "/home/sodo/scripts/stormpigsCode/production/audio" \
  --ssh-target ubuntu@gighive2 \
  --db-host gighive2 --db-user root --db-name music_db \
  --group-vars /home/sodo/scripts/gighive/ansible/inventories/group_vars/gighive2/gighive2.yml
```

Note: the manifest reload endpoint (`/import_manifest_reload.php`) is an alternative import path (Section 4-style). It is not required for the Section 3B workflow.

If `checksum_sha256` is missing/blank in the DB, `upload_media_by_hash.py` will not select any rows (it filters to rows with non-empty `checksum_sha256`).

## Destructive reload via manifest (Section 4-style)

The converter can produce a `manifest.json` compatible with the Section 4/5 "manifest" import format.

To run a destructive reload (truncate + rebuild) using that manifest, POST it to:

- `/import_manifest_reload.php`

Example (HTTPS, skip cert validation):

```bash
curl -k -u admin:'YOUR_ADMIN_PASSWORD' \
  -H 'Content-Type: application/json' \
  --data-binary @normalized_csvs/manifest.json \
  https://gighive2/import_manifest_reload.php
```

If your TLS cert validates, you can omit `-k`.

## End-to-end workflow (convert -> reload DB -> upload media)

This is the recommended flow if you want SHA-256 in the database and want to copy media to the server by hash.

On 12/25/2025, decision made to abandon automated upload via mysql init process as it imposes too many steps. Recommendation is to only use methods 3B/4/5 on the admin page.

1. Convert legacy `database.csv` to normalized CSVs + `manifest.json` and compute hashes (multi-root example):

```bash
sodo@pop-os:~/scripts/gighive/ansible/roles/docker/files/mysql/dbScripts/loadutilities$ python3 /home/sodo/scripts/gighive/ansible/roles/docker/files/apache/webroot/tools/convert_legacy_database_csv_to_normalized.py   databaseSmall.csv   --output-dir normalized_csvsSmall   --source-roots "/home/sodo/videos/stormpigs/finals/singles:/home/sodo/scripts/stormpigsCode/production/audio"
Progress: 2/2 sessions (100%)

✅ Conversion complete
- sessions.csv: normalized_csvsSmall/sessions.csv
- session_files.csv: normalized_csvsSmall/session_files.csv
- manifest.json: normalized_csvsSmall/manifest.json
- report: normalized_csvsSmall/conversion_report.txt
```

2. Load the hashed dataset into MySQL via **destructive manifest reload** (this truncates and rebuilds media tables):

```bash
curl -k -u admin:'YOUR_ADMIN_PASSWORD' \
  -H 'Content-Type: application/json' \
  --data-binary @normalized_csvsSmall/manifest.json \
  https://gighive2/import_manifest_reload.php

{"success":true,"message":"Database reload completed successfully.","inserted_count":15,"duplicate_count":0,"duplicates":[],"steps":[{"name":"Upload received","status":"ok","message":"Request received","index":0},{"name":"Validate request","status":"ok","message":"Validated 15 item(s)","index":1},{"name":"Truncate tables","status":"ok","message":"Tables truncated","index":2},{"name":"Seed genres\/styles","status":"ok","message":"Seeded genres\/styles","index":3},{"name":"Upsert sessions","status":"ok","message":"Sessions ensured: 2","index":4},{"name":"Insert files (dedupe by checksum_sha256)","status":"ok","message":"Inserted: 15, duplicates skipped: 0","index":5},{"name":"Link labels (songs)","status":"ok","message":"Label links created for newly inserted files","index":6}],"table_counts":{"sessions":2,"musicians":0,"songs":15,"files":15,"session_musicians":0,"session_songs":15,"song_files":15}}
```

3. Verify the DB now has checksums (the uploader requires this):

```bash
mysql -h gighive2 -u root -p music_db -e "
SELECT
  COUNT(*) total,
  SUM(source_relpath IS NOT NULL AND source_relpath <> '') with_relpath,
  SUM(checksum_sha256 IS NOT NULL AND checksum_sha256 <> '') with_sha
FROM files;"
Enter password: 
+-------+--------------+----------+
| total | with_relpath | with_sha |
+-------+--------------+----------+
|    15 |           15 |       15 |
+-------+--------------+----------+
```

4. Copy the actual media files to the server using upload-by-hash. You can provide multiple local roots (video + audio):
-  it won’t touch song names and it won’t change source_relpath

```bash
MYSQL_PASSWORD='YOUR_DB_PASSWORD' python3 \
  /home/sodo/scripts/gighive/ansible/roles/docker/files/apache/webroot/tools/upload_media_by_hash.py \
  --source-root /home/sodo/videos/stormpigs/finals/singles \
  --source-roots "/home/sodo/scripts/stormpigsCode/production/audio" \
  --ssh-target ubuntu@gighive2 \
  --db-host gighive2 --db-user root --db-name music_db \
  --group-vars /home/sodo/scripts/gighive/ansible/inventories/group_vars/gighive2/gighive2.yml
```

## Handling missing files (keep rows in DB even when media is absent)

If your legacy CSV references a file that does not exist on disk, the converter will emit a `session_files.csv` row with an empty `checksum_sha256`.

Important:

- The destructive manifest reload endpoint rejects any manifest item with a missing/invalid `checksum_sha256`.
- If you want the database to include rows for songs that have no corresponding file (so the Download link won’t work and `checksum_sha256` will be empty), use **Section 3B** to import `sessions.csv` + `session_files.csv` instead of using the manifest reload endpoint.

Recommended flow for this case:

1. Run the converter to generate `sessions.csv` and `session_files.csv` (checksums will be present only where files exist).
2. In the admin UI, use **Section 3B** to import these CSVs (destructive for the relevant tables).
3. Run `upload_media_by_hash.py` to copy the files that do have `checksum_sha256`.

Note that the manifest reload endpoint requires non-empty `checksum_sha256` for every item. If you want DB rows for missing files, use Section 3B import of `sessions.csv`/`session_files.csv`, then run `upload_media_by_hash` for rows with checksums.

## Manual database update (set `checksum_sha256` for a single file)

`upload_media_by_hash.py` does not update the database. It reads rows from `files` where both `source_relpath` and `checksum_sha256` are non-empty, then uploads/copies media based on that.

If you have a small number of missing/incorrect checksums (e.g. 1 file), you can update the DB directly instead of re-running the full Section 3B import.

1. Compute the checksum locally:

```bash
sha256sum StormPigs20250724_4_electrofunk42.mp4
```

Example output:

```text
e4abd2933e76233ab302e8f598cdb5f5fbafd6f39f4909ac0d509a68a784bf22  StormPigs20250724_4_electrofunk42.mp4
```

2. Find the row in MySQL:

```bash
MYSQL_PWD='YOUR_DB_PASSWORD' mysql -h gighive2 -u root music_db -e "
SELECT file_id, file_type, source_relpath, checksum_sha256
FROM files
WHERE source_relpath LIKE '%StormPigs20250724_4_electrofunk42.mp4%';
"
```

3. Update the checksum:

Preferred (by `file_id`):

```bash
MYSQL_PWD='YOUR_DB_PASSWORD' mysql -h gighive2 -u root music_db -e "
UPDATE files
SET checksum_sha256 = 'e4abd2933e76233ab302e8f598cdb5f5fbafd6f39f4909ac0d509a68a784bf22'
WHERE file_id = <FILE_ID>;
"
```

4. Re-run the uploader:

```bash
MYSQL_PASSWORD='musiclibrary' python3 \
  ~/scripts/gighive/ansible/roles/docker/files/apache/webroot/tools/upload_media_by_hash.py \
  --source-root /home/sodo/videos/stormpigs/finals/singles \
  --source-roots "/home/sodo/scripts/stormpigsCode/production/audio" \
  --ssh-target ubuntu@gighive2 \
  --db-host gighive2 --db-user root --db-name music_db \
  --group-vars /home/sodo/scripts/gighive/ansible/inventories/group_vars/gighive2/gighive2.yml
```
