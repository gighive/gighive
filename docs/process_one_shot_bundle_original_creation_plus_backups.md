# Process: one-shot bundle + automated MySQL backups (host cron)

This document extends `docs/process_one_shot_bundle_original_creation.md` with the additional work to support **daily, automated MySQL backups** for the one-shot bundle.

## Goal
Add MySQL backups to the one-shot bundle in a way that:
- Matches the behavior of the Ansible `mysql_backup` role (dump + prune schedules, retention defaults).
- Does **not** hardcode credentials in scripts.
- Works for a portable bundle by using **bundle-relative paths**.

## Model (Option A)
Backups are scheduled by **cron on the host VM** (the machine running Docker), not inside containers.

The cron jobs run host-side scripts that:
- Read MySQL settings from the same env file used by `docker compose`.
- Use `docker exec` into the running `mysqlServer` container to run `mysqldump` / `mysql`.
- Write `.sql.gz` dumps to the host filesystem.

## Inputs / configuration source
The one-shot bundle `install.sh` prompts for MySQL credentials and writes:
- `./mysql/externalConfigs/.env.mysql`

Both backup scripts read this file to get:
- `DB_HOST` (container name, typically `mysqlServer`)
- `MYSQL_DATABASE`
- credentials (`MYSQL_ROOT_PASSWORD` preferred, else `MYSQL_USER` + `MYSQL_PASSWORD`)

## Canonical backups directory
The canonical host directory for backups inside the bundle is:
- `./mysql/dbScripts/backups`

This directory is also mounted into the apache container so the web stack can access backups at the existing in-container path:
- host: `./mysql/dbScripts/backups`
- container: `/var/www/private/mysql_backups`

## Backup/restore scripts added to the bundle
Two non-template scripts were created in:
- `./mysql/dbScripts/`

### `dbDump.sh`
- Reads `.env.mysql`
- Creates dumps named `&lt;DB&gt;_YYYY-MM-DD_HHMMSS.sql.gz`
- Runs `gzip -t` integrity checks
- Maintains a `&lt;DB&gt;_latest.sql.gz` symlink

### `dbRestore.sh`
- Reads `.env.mysql`
- Restores from the newest matching dump by default (or a specific file)
- Verifies gzip integrity before restoring
- Prompts for confirmation unless `--yes` is supplied

## Cron scheduling (Ansible-parity defaults)
Cron jobs are optionally installed by the bundle `install.sh`.

- Dump schedule: **02:10 daily**
- Prune schedule: **03:05 daily**
- Retention: **90 days**
- Cron env:
  - `SHELL=/bin/bash`
  - `PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin`

The cron jobs:
- `cd` into the extracted bundle directory (absolute path) so relative paths work.
- Append output to `./mysql/dbScripts/backups/cron.log`.

## Installer behavior
`install.sh` now prompts:
- “Install daily MySQL backup cron jobs on this host VM?”

If the user answers **no**, cron installation is skipped.

If the user answers **yes**, `install.sh` writes an idempotent, marked block into the current user’s crontab and installs:
- A daily dump job that runs `./mysql/dbScripts/dbDump.sh`
- A daily prune job that removes `&lt;DB&gt;_*.sql.gz` older than 90 days

## What we learned (durability + permissions)
- The canonical host backups directory in the bundle is `./mysql/dbScripts/backups`.
- The apache container should keep using `/var/www/private/mysql_backups` as its in-container path, but the host mount must point at `./mysql/dbScripts/backups`.
- Restore logs are written by PHP as `www-data` inside the apache container (see `GIGHIVE_MYSQL_RESTORE_LOG_DIR=/var/www/private/restorelogs`).
- For portable bundles (no ACL assumptions, no UID/GID mapping assumptions), the most reliable approach is:
  - Make `./apache/externalConfigs/restorelogs` world-writable (`chmod 0777`) so restores can always write logs.
  - Make backup dumps world-readable (`chmod 0644`) so the restore UI can always read dumps.
- These permissions should be enforced during bundle install (not manually after deploy) to avoid regressions after re-tarring/re-extracting.
- If/when we want to harden restore log permissions beyond `0777`, track the ACL-based refactor notes in `docs/refactor_acls_on_restore_logs.md`.
- Admin media deletes currently remove the underlying file from disk; track the soft-delete refactor plan in `docs/refactor_db_database_admin_soft_deletes.md`.

## Notes
- This design assumes the cron user can run Docker commands (e.g., is in the `docker` group).
- Because cron runs on the host, backups persist as long as the bundle directory persists.
