# load_and_transform.sql — MySQL Initialization (Bootstrap) Loading

This document explains what files must exist (and where) so that `load_and_transform.sql` automatically loads them when the MySQL container is rebuilt/initialized.

## How automatic loading works

GigHive uses the official `mysql:8.0` image initialization behavior:

- Any `*.sql` mounted into `/docker-entrypoint-initdb.d/` will be executed **only when the MySQL data directory is empty** (first initialization of the DB volume).
- If the MySQL container restarts and the `/var/lib/mysql` data directory already contains an initialized database, the init scripts are **not** automatically re-run.

In this repo, Docker Compose mounts the initialization SQL scripts into the container:

- `ansible/roles/docker/files/mysql/externalConfigs/create_music_db.sql`
  -> `/docker-entrypoint-initdb.d/00-create_music_db.sql`
- `ansible/roles/docker/files/mysql/externalConfigs/load_and_transform.sql`
  -> `/docker-entrypoint-initdb.d/01-load_and_transform.sql`

Therefore, `01-load_and_transform.sql` runs automatically only on a fresh MySQL initialization (new/empty MySQL volume).

## Where MySQL reads the CSVs from

`load_and_transform.sql` uses MySQL server-side imports:

- `LOAD DATA INFILE '/var/lib/mysql-files/<name>.csv' ...`

Docker Compose mounts a host directory into the container at:

- Host (VM): `{{ docker_dir }}/mysql/externalConfigs/prepped_csvs/{{ 'full' if database_full else 'sample' }}`
- Container: `/var/lib/mysql-files/`

So: to be auto-loaded on initialization, the CSVs must exist in the host directory:

- `ansible/roles/docker/files/mysql/externalConfigs/prepped_csvs/full/`
  or
- `ansible/roles/docker/files/mysql/externalConfigs/prepped_csvs/sample/`

depending on the `database_full` setting used when rendering `docker-compose.yml`.

## Required CSV files (exact filenames)

`load_and_transform.sql` currently loads the following CSVs from `/var/lib/mysql-files/`:

- `sessions.csv`
- `musicians.csv`
- `session_musicians.csv`
- `songs.csv`
- `session_songs.csv`
- `files.csv`
- `song_files.csv`

These must exist **with those exact names** inside:

- `ansible/roles/docker/files/mysql/externalConfigs/prepped_csvs/full/`
  or
- `ansible/roles/docker/files/mysql/externalConfigs/prepped_csvs/sample/`

## CSV line ending requirement

`load_and_transform.sql` specifies:

- `LINES TERMINATED BY '\r\n'`

This means the CSV files must use CRLF line endings (`\r\n`) for clean imports.

## “Rebuild” vs “re-run”

Automatic execution of `/docker-entrypoint-initdb.d/*.sql` happens only on first init of the MySQL datadir.

For re-running the SQL against an already-initialized container, the repo also contains:

- `ansible/roles/docker/files/mysql/dbScripts/reloadMyDatabase.sh`

which executes:

- `/docker-entrypoint-initdb.d/00-create_music_db.sql`
- `/docker-entrypoint-initdb.d/01-load_and_transform.sql`

against the running MySQL container.

## Which preprocessing script produces the correct `prepped_csvs/` format?

Both scripts below produce the normalized 7-CSV set listed above (and therefore both can generate inputs that `load_and_transform.sql` can load):

- `ansible/roles/docker/files/apache/webroot/tools/mysqlPrep_full.py`
- `ansible/roles/docker/files/apache/webroot/tools/mysqlPrep_normalized.py`

The difference is the input they expect:

### mysqlPrep_full.py (legacy single-CSV input)

Use when starting from a legacy combined export:

- Input: `database.csv`
- Output: `prepped_csvs/{sessions,musicians,session_musicians,songs,session_songs,files,song_files}.csv`

### mysqlPrep_normalized.py (normalized split inputs)

Use when starting from “already normalized-ish” inputs (Admin UI Section 3B style):

- Inputs:
  - `sessions.csv` (expects at least: `session_key`, `t_title`, `d_date`)
  - `session_files.csv` (expects at least: `session_key`, `source_relpath`)
- Output: `prepped_csvs/{sessions,musicians,session_musicians,songs,session_songs,files,song_files}.csv`

Note: the MySQL bootstrap loader imports `files.csv` (not `session_files.csv`); `session_files.csv` is an input used to generate `files.csv` + `song_files.csv`.

## Relation to Admin UI (manual imports)

The changes to `load_and_transform.sql` align with the newer normalized import workflows referenced in `admin.php` sections 3B, 4, and 5. The MySQL bootstrap path expects the final normalized per-table CSVs listed above.
