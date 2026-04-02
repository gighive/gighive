---
# refactor_add_db_migrations_backup_before

## Goal
Add a new `db_migrations` Ansible role to apply incremental, idempotent schema migrations to existing MySQL volumes without requiring `rebuild_mysql_data: true`.

This preserves non-CSV data (for example, manually inserted rows and manually uploaded media that are not present in the CSV import pipeline).

## Background / problem statement
GigHive currently has two different database lifecycles:

1. **Fresh install / data rebuild**
   - MySQL entrypoint executes `create_music_db.sql` and `load_and_transform.sql` only when the MySQL data directory is empty.
   - `load_and_transform.sql` truncates multiple tables (including `files`) and reloads from `prepped_csvs`.

2. **Ongoing deployments to an existing DB**
   - Once the MySQL volume exists, changes to `create_music_db.sql` do not automatically apply.
   - If schema changes are "picked up" by running `rebuild_mysql_data: true`, all data is rebuilt from CSV and any custom/non-CSV rows are lost.

## Decision
Use an Ansible-managed, idempotent schema migration step for SDLC promotion instead of relying on full rebuilds.

- **Baseline schema** remains in `create_music_db.sql` for new installs.
- **Incremental migrations** are applied via Ansible for existing DBs.

## What was implemented
- A new role: `ansible/roles/db_migrations`
- The role currently includes one migration:
  - Ensure `files.delete_token_hash` exists, adding it if missing.
- The role is wired into `ansible/playbooks/site.yml` after `post_build_checks` and before `validate_app`.

## Why backups remain manual (for now)
Backups are intentionally kept on an operator-controlled schedule rather than being triggered implicitly by migrations.

Rationale:
- Backups can be large and time-consuming.
- Operators may want explicit timing/coordination with maintenance windows.
- The existing `mysql_backup` role already establishes a scheduled backup mechanism.

## Future improvement: optional "backup-before-migrate"
If desired later, add a toggle to trigger a backup immediately before applying any schema change.

### Proposed interface
- `db_migrations_backup_before: true|false` (default: false)
- `db_migrations_backup_tag: "pre_migration"` (optional filename suffix)

### Proposed implementation steps
1. **Detect if any migration would run**
   - Example: `files.delete_token_hash` missing.
2. **If at least one migration would run and `db_migrations_backup_before` is true**
   - Run an on-demand backup using the existing backup mechanism.
   - Options:
     - Call the installed `dbDump.sh` script created by `mysql_backup`.
     - Or run `mysqldump` via `docker exec` inside the MySQL container.
3. **Run the migrations**.

### Verification checklist
- Schema migration is idempotent (second run is a no-op).
- Backup file is created only when a migration is actually applied.
- Restore test (optional): restore the backup into a scratch environment.

## Notes on avoiding schema drift
To reduce drift between baseline schema (`create_music_db.sql`) and upgrade path (migrations):
- For every schema change, update **both**:
  - baseline DDL (`create_music_db.sql`)
  - a migration in `db_migrations`
- Add an assertion step (already present for `delete_token_hash`) to fail fast if schema requirements are missing.
