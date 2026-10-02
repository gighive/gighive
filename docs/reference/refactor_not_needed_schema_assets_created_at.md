# Refactor: Add `created_at` to `assets` table

## Problem

The `assets` table has no `created_at` column. There is no direct way to determine
when an asset was first inserted into the database.

This gap was encountered during Phase 5 validation (server-authoritative delete
eligibility, 2026-08-30) when investigating whether two assets (ids 26 and 27) were
pre-existing or freshly uploaded. The answer required a sideways join through
`probe_jobs.created_at`, which is fragile — `probe_jobs` records may not exist for
all asset types, and the relationship is indirect.

Standard practice is for every table to carry a `created_at` timestamp.

## Proposed Change

```sql
ALTER TABLE assets
  ADD COLUMN created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    COMMENT 'Row insertion timestamp'
    AFTER asset_id;
```

`DEFAULT CURRENT_TIMESTAMP` means:
- New rows get the timestamp automatically with no application-layer changes.
- Existing rows receive the migration timestamp (the moment `ALTER TABLE` runs),
  not their true creation date, which is permanently unknown.

Update `create_media_db.sql` to include the column in the canonical schema.

## Caveats

- **Historical rows cannot be backfilled** — the true creation date for assets
  inserted before this migration is not recoverable. The migration timestamp will
  be assigned, which may mislead future queries if not clearly documented.
- **No application changes required** — `DEFAULT CURRENT_TIMESTAMP` handles
  insertion automatically. However, any code that performs `INSERT INTO assets ...`
  with an explicit column list should be audited to confirm `created_at` is either
  in the list or omitted (to pick up the default).
- **`probe_jobs.created_at` as a proxy** — prior to this migration, the closest
  approximation of asset creation time is `probe_jobs.created_at` for the
  corresponding `asset_id`. This relationship should be documented as a fallback
  for historical data only.

## Scope

- Schema: one `ALTER TABLE` (nullable-safe, default-provided).
- `create_media_db.sql`: add column definition.
- No PHP or iOS changes required.
- Apply via BABRRR process on each environment.

## Status

- [x] **No action required.** Live database inspection via MCP on 2026-08-30
  (`get_table_ddl assets`) confirmed the column already exists:
  ```
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
  ```
  The `create_media_db.sql` canonical schema also has it (line 44). Schema and
  live database are in sync. The investigation that reached for
  `probe_jobs.created_at` as a proxy was unnecessary — `assets.created_at`
  was available throughout. No ALTER or application changes needed.
  This document is retained for historical context only.
