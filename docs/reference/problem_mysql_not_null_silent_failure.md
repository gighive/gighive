# Problem: MySQL silently drops rows on NOT NULL constraint violation

## Symptom

After adding a `NOT NULL` column with no default to an existing table, bulk-write
operations produce no visible error but the table ends up empty (or partially
populated). Downstream pages (e.g. Media Library) are blank. No PHP exception,
no MySQL error in logs.

## Root cause

MySQL's `LOAD DATA INFILE` and `INSERT ... SELECT` do **not** throw a fatal error
when a row violates a `NOT NULL` constraint — they silently skip or zero-fill the
offending rows (depending on `sql_mode`). With strict mode off (or not enforced for
the session), the statement completes with `0 rows inserted` and reports warnings
that are easy to miss.

This affected three separate write paths after `event_key CHAR(36) NOT NULL` was
added to the `events` table:

| File | Statement type | Result |
|------|---------------|--------|
| `admin/import_database.php` | `INSERT INTO events ... SELECT` | 0 rows inserted silently |
| `admin/import_normalized.php` | `INSERT INTO events ... SELECT` | 0 rows inserted silently |
| `mysql/externalConfigs/load_and_transform.sql` | `LOAD DATA INFILE ... INTO events` | 0 rows loaded silently |

All three left `events` empty, which cascaded to empty `event_items` and a blank
Media Library — with no error surfaced anywhere.

## Fix pattern

### `INSERT ... SELECT` (PHP scripts)

```sql
INSERT INTO events (event_key, event_date, org_name, event_type)
SELECT UUID(), date, COALESCE(NULLIF(org_name,''), 'default'), ...
FROM staging_table;
```

### `LOAD DATA INFILE` (SQL scripts)

Add the new column to the `SET` clause, not the column list:

```sql
LOAD DATA INFILE '/var/lib/mysql-files/sessions.csv'
INTO TABLE events
...
(event_id, event_date, @org_name, ...)
SET
  event_key = UUID(),
  org_name  = COALESCE(NULLIF(@org_name, ''), 'default'),
  ...;
```

`UUID()` is a function and cannot appear in the column list — it must go in `SET`.

## Prevention checklist

When adding a `NOT NULL` column with no default to any table:

1. Search all PHP files for `INSERT INTO <table>` — confirm every path supplies the new column.
2. Search all `.sql` files for `LOAD DATA INFILE ... INTO TABLE <table>` and `INSERT INTO <table> ... SELECT` — confirm the new column is in the column list or `SET` clause.
3. After the next deploy, run `SELECT COUNT(*) FROM <table>` immediately to confirm rows were written.
4. Enable MySQL warnings output during import scripts (`SHOW WARNINGS;` after each statement) to catch silent skips before they cascade.

## Related

- `docs/refactor_ai_jobs_upload_jobs_event_key_db_schema.md` — the schema change that introduced `event_key`
- `docs/refactor_ensure_event_add_event_key.md` — PHP-level fix in `EventRepository::ensureEvent()`
