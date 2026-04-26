# Problem: checksum_sha256 NOT NULL blocks MySQL initialization from legacy CSV data

## Date
2026-04-26

## Summary
MySQL init failed silently during `rebuild_mysql_data` because 9 rows in
`prepped_csvs/full/files.csv` have an empty `checksum_sha256`, and the `assets`
table defined the column as `NOT NULL`. The `LOAD DATA INFILE` SET clause uses
`NULLIF(@checksum_sha256, '')`, which converts the empty string to `NULL`,
triggering an `ERROR 1048 (23000): Column 'checksum_sha256' cannot be null` at
line 104 of `load_and_transform.sql`. MySQL aborted the entire init script,
leaving all tables empty and the UI showing a blank Media Library.

## Affected files

- `ansible/roles/docker/files/mysql/externalConfigs/create_music_db.sql` — schema DDL
- `ansible/roles/docker/files/mysql/externalConfigs/prepped_csvs/full/files.csv` — production CSV data

## Affected rows in files.csv (9 rows, all video)

```
file_id  file_name
158      StormPigs20060406_1_1.mp4
159      StormPigs20060406_2_2.mp4
160      StormPigs20060406_3_3.mp4
162      StormPigs20060406_5_5.mp4
164      StormPigs20060406_7_7.mp4
165      StormPigs20060406_8_8.mp4
166      StormPigs20060406_9_9.mp4
167      StormPigs20060406_10_10.mp4
547      StormPigs20161207_8_25or6-to-4.mp4
```

These are legacy production records where checksums were never computed (no
`checksum_sha256`, `duration_seconds`, `media_info`, or `media_info_tool`).

## Fix applied

Changed `checksum_sha256 CHAR(64) NOT NULL` → `CHAR(64) NULL` in
`create_music_db.sql` (line 10). The `UNIQUE` constraint `uq_assets_checksum`
was kept — MySQL allows multiple `NULL` values in a UNIQUE index, so
deduplication continues to work for any row that does have a checksum.

```sql
-- Before
checksum_sha256 CHAR(64) NOT NULL,

-- After
checksum_sha256 CHAR(64) NULL,
```

## How to detect this in the future

If `rebuild_mysql_data: true` is set and the Media Library is still blank after
a playbook run, check the MySQL container init log:

```bash
docker logs mysqlServer 2>&1 | grep -E "ERROR|error|LOAD"
```

A clean init ends with:
```
[Entrypoint]: /usr/local/bin/docker-entrypoint.sh: running /docker-entrypoint-initdb.d/01-load_and_transform.sql
[Entrypoint]: Temporary server stopped
[Entrypoint]: MySQL init process done. Ready for start up.
```

Any `ERROR` line before "Temporary server stopped" means the LOAD DATA aborted
and the tables are empty.

## Future consideration

The original `NOT NULL` constraint was intentional: `checksum_sha256` is the
primary deduplication key for uploaded assets and should always be populated
for new uploads via the normal ingestion path (`UnifiedIngestionCore`). The
relaxation to `NULL` was made purely to accommodate legacy CSV data.

If the production CSV is ever regenerated with checksums backfilled for those 9
files (e.g. by running `sha256sum` on the originals), consider reverting this
column back to `NOT NULL` to restore strict deduplication enforcement at the
database layer.

## Related files

- `ansible/roles/docker/files/mysql/externalConfigs/load_and_transform.sql` — init data load script
- `ansible/roles/docker/files/apache/webroot/src/Services/UnifiedIngestionCore.php` — normal upload path (always computes checksum before insert)
