# MySQL initialization process (docker-compose)

This document describes how the MySQL container is initialized in this repo, how the schema and seed data are loaded, and where media metadata columns (e.g. `duration_seconds`, `media_info`) come from during initialization.

## High-level overview

The MySQL container used by GigHive is the upstream image `mysql:8.4` (see `ansible/roles/docker/templates/docker-compose.yml.j2`). Initialization is handled by the stock MySQL Docker entrypoint.

On a **first boot** (i.e. when the persistent volume at `/var/lib/mysql` is empty), the MySQL entrypoint will:

- Create the database/user based on `MYSQL_*` env vars.
- Execute any `*.sql` files mounted into `/docker-entrypoint-initdb.d/` in lexical order.

On **subsequent boots** (when `/var/lib/mysql` already contains a data directory), the entrypoint **does not re-run** the init scripts in `/docker-entrypoint-initdb.d/`.

## Where the initialization inputs are defined

### docker-compose template

MySQL service definition is generated from:

- `ansible/roles/docker/templates/docker-compose.yml.j2`

Key parts:

- **Image**: `mysql:8.4`
- **env_file**: `./mysql/externalConfigs/.env.mysql`
- **Volumes**:
  - Persistent data: `mysql_data:/var/lib/mysql`
  - CSV input directory: `{{ docker_dir }}/mysql/externalConfigs/prepped_csvs/{{ 'full' if database_full | bool else 'sample' }}:/var/lib/mysql-files/`
  - Init SQL scripts:
    - `create_music_db.sql` mounted as `/docker-entrypoint-initdb.d/00-create_music_db.sql`
    - `load_and_transform.sql` mounted as `/docker-entrypoint-initdb.d/01-load_and_transform.sql`
  - MySQL config override:
    - `z-custommysqld.cnf` mounted as `/etc/mysql/conf.d/z-custommysqld.cnf`

### MySQL environment

The env file is rendered from:

- `ansible/roles/docker/templates/.env.mysql.j2`

It provides:

- `MYSQL_ROOT_PASSWORD`
- `MYSQL_DATABASE`
- `MYSQL_USER`
- `MYSQL_PASSWORD`
- `MYSQL_ROOT_HOST=%`

## MySQL init SQL scripts

### 00-create_music_db.sql

Source file:

- `ansible/roles/docker/files/mysql/externalConfigs/create_music_db.sql`

Responsibilities:

- Drops and recreates the database (`music_db`).
- Creates all schema tables (`sessions`, `songs`, `files`, etc.).

Important for upload testing context:

- The `files` table includes:
  - `duration_seconds INT NULL`
  - `media_info JSON NULL`
  - `media_info_tool VARCHAR(255) NULL`

So the schema supports storing media-probe metadata, but this script does not populate it.

### 01-load_and_transform.sql

Source file:

- `ansible/roles/docker/files/mysql/externalConfigs/load_and_transform.sql`

Responsibilities:

- Truncates tables (with foreign key checks disabled/enabled).
- Seeds reference tables (`genres`, `styles`).
- Loads CSVs into tables using `LOAD DATA INFILE ...`.

The files are expected to exist inside the container at:

- `/var/lib/mysql-files/*.csv`

That path is populated by the volume mount:

- `.../externalConfigs/prepped_csvs/(sample|full) -> /var/lib/mysql-files/`

#### Where `duration_seconds` / `media_info` come from at init time

At init time, **MySQL does not run `ffprobe`**.

Instead, `load_and_transform.sql` loads `duration_seconds`, `media_info`, and `media_info_tool` directly from the CSV columns in `/var/lib/mysql-files/files.csv`:

- `LOAD DATA INFILE '/var/lib/mysql-files/files.csv' INTO TABLE files ...`
- It sets:
  - `duration_seconds = NULLIF(@duration_seconds, '')`
  - `media_info = NULLIF(@media_info, '')`
  - `media_info_tool = NULLIF(@media_info_tool, '')`

Therefore:

- If `/var/lib/mysql-files/files.csv` contains populated values, the DB will contain populated values.
- If the CSV columns are empty, the DB will contain NULL/empty values.

##### Example: `sample/files.csv` includes populated media metadata

Example command and output (from the repo checkout):

```bash
head -2 ansible/roles/docker/files/mysql/externalConfigs/prepped_csvs/sample/files.csv
```

```csv
file_id,file_name,source_relpath,checksum_sha256,file_type,duration_seconds,media_info,media_info_tool
1,StormPigs20021024_1_fleshmachine.mp4,StormPigs20021024_1_fleshmachine.mp4,3ed8bbc43ec35bb4662ac8b75843eb89fbd50557eccb3aa960cbc2f6e0601e4d,video,503,"{""programs"":[],""streams"":[{""index"":0,""codec_name"":""h264"",...}],""format"":{...}}",ffprobe 6.1.1-3ubuntu5
```

This means a MySQL init using the `sample` seed set will load `duration_seconds`, `media_info`, and `media_info_tool` directly from the CSV.

##### Example: `full/files.csv` may have empty media metadata

Example command and output:

```bash
head -2 ansible/roles/docker/files/mysql/externalConfigs/prepped_csvs/full/files.csv
```

```csv
file_id,file_name,source_relpath,checksum_sha256,file_type,duration_seconds,media_info,media_info_tool
1,19970723_2.mp3,19970723_2.mp3,ae24b2bb331b1cd59fd26828f5e4c7ad2130fc110bccbbcaa47db8e260a86b48,audio,,,
```

In this case, a MySQL init using the `full` seed set will load empty/NULL values for those media metadata columns.

## MySQL configuration relevant to CSV loading

Config file:

- `ansible/roles/docker/files/mysql/externalConfigs/z-custommysqld.cnf`

Notable settings:

- `secure_file_priv=/var/lib/mysql-files`
  - Restricts `LOAD DATA INFILE` to read only from this directory.
- `local-infile=1`
  - Enables local infile support (though the init script uses server-side `LOAD DATA INFILE`).

## How the `/var/lib/mysql-files/*.csv` seed data is produced

The repo includes pre-generated seed CSV sets:

- `ansible/roles/docker/files/mysql/externalConfigs/prepped_csvs/sample/*.csv`
- `ansible/roles/docker/files/mysql/externalConfigs/prepped_csvs/full/*.csv`

These are generated by helper scripts in:

- `ansible/roles/docker/files/mysql/dbScripts/loadutilities/`

Example wrapper scripts:

- `doAllSample.sh`
- `doAllFull.sh`

These wrappers run a Python preprocessor (for example `mysqlPrep_sample.py` or `mysqlPrep_full.py` in the same directory), then copy the produced `prepped_csvs/*.csv` into the appropriate `externalConfigs/prepped_csvs/(sample|full)` directory.

### How ffprobe is used when generating seed CSVs

The loadutility Python scripts (example: `ansible/roles/docker/files/mysql/dbScripts/loadutilities/mysqlPrep_full.py`) include media-probing helpers that call `ffprobe` to fill:

- `duration_seconds`
- `media_info`
- `media_info_tool`

This probing happens **at CSV generation time**, not inside MySQL.

So, in the “seed DB” path:

1. A Python preprocessing script probes media files (via `ffprobe`) and emits `files.csv` with metadata.
2. MySQL init loads that `files.csv` directly.

## Relationship to upload/import restore code paths

The upload/import endpoints (e.g. `import_normalized.php`) run different Python scripts (e.g. `tools/mysqlPrep_normalized.py`) to produce job-local `prepped_csvs/*.csv` before importing them.

Those import preprocessors also attempt to use `ffprobe` to populate `duration_seconds` and `media_info` during preprocessing.

### Example: import job `prepped_csvs/` directory (apache container)

Each import creates a job directory under `/var/www/private/import_jobs/<job_id>/` and writes preprocessed CSVs to `prepped_csvs/`.

Example directory listing (from an import job on a running system):

```bash
docker exec apacheWebServer ls /var/www/private/import_jobs/20260214-171009-9f92d1eb3762/prepped_csvs/
```

```text
files.csv
musicians.csv
session_musicians.csv
session_songs.csv
sessions.csv
song_files.csv
songs.csv
```

This means:

- **MySQL init** expects metadata to already be present in the CSV.
- **Restore/import** also expects to create that metadata during preprocessing.

If restore/import results in blank `duration_seconds`/`media_info`, it indicates the preprocessing step could not successfully probe the media files (commonly due to path/name resolution issues), even if `ffprobe` is installed.
