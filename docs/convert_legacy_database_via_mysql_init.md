# Convert Legacy database.csv via MySQL init (CSV seed) + upload-by-hash

This document describes the workflow for taking a legacy `database.csv`, generating the fully-normalized CSV set used by the **MySQL init** seeding process, and then copying/inspecting media on the web server using `upload_media_by_hash.py`.

This workflow is an alternative to the Admin UI “Section 3B” import. Instead of uploading CSVs through the admin, you pre-place the normalized CSVs into:

- `ansible/roles/docker/files/mysql/externalConfigs/prepped_csvs/full/`

…and then rebuild MySQL from scratch so the container init script loads them.

## When to use this

Use this if you want:

- a **fresh MySQL container** to come up already populated from CSVs
- to avoid the interactive Admin import step

Do **not** use this if you want to preserve an existing DB volume or do incremental imports.

## High-level overview

1. Generate the normalized CSV set (sessions, musicians, songs, files, and link tables)
2. Place those CSVs under `externalConfigs/prepped_csvs/full/`
3. Ensure MySQL is initialized from a **fresh** datadir/volume so init scripts run
4. After the DB exists, run `upload_media_by_hash.py` to copy media + fill media metadata

## 1) Prepare the normalized CSV set

You should generate the full normalized CSV set (not just `sessions.csv` + `session_files.csv`) so MySQL init can load all tables.

At minimum you need:

- `sessions.csv`
- `musicians.csv`
- `session_musicians.csv`
- `songs.csv`
- `session_songs.csv`
- `files.csv`
- `song_files.csv`

## 2) Place CSVs in the MySQL init ingest directory as specified in convert_legacy_database.md

Copy the normalized CSVs into:

- `ansible/roles/docker/files/mysql/externalConfigs/prepped_csvs/full/`

This is the directory that is mounted into the MySQL container at:

- `/var/lib/mysql-files/`

(When `database_full: true` is configured in your Ansible group vars / compose template.)

## 3) Ensure MySQL init scripts actually run (fresh volume)

The MySQL init import (`/docker-entrypoint-initdb.d/*.sql`) only runs when MySQL starts with an **empty** datadir.

So for this workflow to do anything, you must ensure you are starting MySQL with a **fresh** persistent volume/datadir.

If you restart without wiping the datadir/volume, the init scripts will be skipped and your new CSVs won’t be imported.

## 4) Confirmed CSV column expectations (`load_and_transform.sql`)

The authoritative column ordering comes from:

- `ansible/roles/docker/files/mysql/externalConfigs/load_and_transform.sql`

Below are the relevant `LOAD DATA INFILE` expectations and how they align with the CSVs under `prepped_csvs/full/`.

### `sessions.csv`

`load_and_transform.sql` loads sessions with this positional order:

- `session_id`
- `@name` → mapped to `sessions.title`
- `date`
- `@org_name` → mapped to `sessions.org_name`
- `@event_type` → mapped to `sessions.event_type`
- `description`
- `@image_path` → mapped to `sessions.cover_image_url`
- `@crew` → ignored (musicians are normalized via `session_musicians`)
- `location`
- `@rating_raw` → mapped to `sessions.rating`
- `summary`
- `@pub_date` → mapped to `sessions.published_at`
- `explicit`
- `@duration` → mapped to `sessions.duration_seconds` (parsed)
- `keywords`

Your `prepped_csvs/full/sessions.csv` header format like:

- `session_id,title,date,org_name,event_type,description,cover_image_url,crew,location,rating,summary,published_at,explicit,duration,keywords`

is compatible because `LOAD DATA` uses column position (the header names don’t need to match the SQL variable names).

### `songs.csv`

SQL expects:

- `song_id,title,type,duration,genre_id,style_id`

This matches `prepped_csvs/full/songs.csv`.

### `files.csv`

SQL expects this positional order:

- `file_id`
- `file_name`
- `source_relpath`
- `checksum_sha256`
- `file_type`
- `duration_seconds`
- `media_info`
- `media_info_tool`

This matches `prepped_csvs/full/files.csv`.

### Link tables

SQL expects:

- `session_musicians.csv`: `session_id,musician_id`
- `session_songs.csv`: `session_id,song_id`
- `song_files.csv`: `song_id,file_id`

These match the standard normalized link-table formats.

## 5) After MySQL init, run upload-by-hash, again specified in convert_legacy_database.md

Once the DB is seeded (and especially if `files.checksum_sha256` is present), run:

- `ansible/roles/docker/files/apache/webroot/tools/upload_media_by_hash.py`

This step:

- copies media files to the Apache host (via ssh/rsync)
- probes each file to populate:
  - `duration_seconds`
  - `media_info`
  - `media_info_tool`

It does **not**:

- overwrite song titles
- change `source_relpath`

## Notes / common pitfalls

- MySQL init seeding is **all-or-nothing** per datadir: if you don’t start from a fresh volume, the import will not re-run.
- If `checksum_sha256` is blank in the DB, `upload_media_by_hash.py` will skip those rows (it filters for non-empty checksums).
