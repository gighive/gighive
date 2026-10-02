# MySQL Backups Procedure (Ansible `mysql_backup` role)

GigHive’s MySQL backups are implemented by the Ansible role `mysql_backup`. The role installs two helper scripts on the VM host (`dbDump.sh` and `dbRestore.sh`) and configures cron jobs to create and prune backups.

## What gets backed up

- The backup is a logical dump of the MySQL database using `mysqldump` executed *inside* the running MySQL container.
- The dump includes:
  - the database (`--databases <DB_NAME>`, so it contains `CREATE DATABASE` / `USE` statements)
  - routines, events, and triggers
- The resulting dump is compressed with `gzip`.

## Where backups live (VM host)

The role writes backups on the VM host under:

- `{{ mysql_backups_dir }}`

In the standard VM layout this resolves to:

- `$GIGHIVE_HOME/ansible/roles/docker/files/mysql/dbScripts/backups`

Backups are named:

- `<MYSQL_DATABASE>_YYYY-MM-DD_HHMMSS.sql.gz`

A convenience symlink is maintained:

- `<MYSQL_DATABASE>_latest.sql.gz` (points to the most recent dump)

A cron log file is written to:

- `{{ mysql_backups_dir }}/cron.log`

## How the dump job works

The daily dump is performed by running `dbDump.sh` under cron.

At a high level `dbDump.sh`:

- Reads MySQL connection and credential settings from an env file:
  - `{{ mysql_env_file }}`
  - typically `$GIGHIVE_HOME/ansible/roles/docker/files/mysql/externalConfigs/.env.mysql`
- Uses `DB_HOST` from the env file as the container name (commonly `mysqlServer`).
- Uses `MYSQL_DATABASE` as the database name.
- Uses credentials in this order:
  - `MYSQL_ROOT_PASSWORD` (preferred)
  - otherwise `MYSQL_USER` + `MYSQL_PASSWORD`
- Executes `mysqldump` inside the container and writes a gzip-compressed dump on the host in `{{ mysql_backups_dir }}`.
- Verifies the gzip file integrity (`gzip -t`).

The role also sets cron environment entries for the backup user so cron can find `bash` and `docker`.

## Schedule

By default (as configured in inventory `group_vars/*/*.yml`):

- Daily dump time: `{{ mysql_dump_hour }}:{{ mysql_dump_minute }}`
- Daily prune time: `{{ mysql_prune_hour }}:{{ mysql_prune_minute }}`

You can change these by overriding the `mysql_dump_*` / `mysql_prune_*` variables in the appropriate `group_vars` file.

## Retention / pruning

A second cron job prunes old dumps from `{{ mysql_backups_dir }}`:

- Retention: `{{ mysql_backup_retention_days }}` days
- Files matched: `${MYSQL_DATABASE}_*.sql.gz`
- Mechanism: `find ... -mtime +<days> -delete`

Prune output is appended to the same `cron.log`.

## Restore procedure (manual)

To restore, run the installed script on the VM host:

- `dbRestore.sh` restores from the most recent `*.sql.gz` by default (or a file you specify).
- The script checks the gzip integrity and confirms the MySQL container is running.
- The dump is piped into `mysql` running inside the container.

Important notes:

- Restoring will overwrite existing tables/content in the target database.
- Because dumps are created with `--databases <DB_NAME>`, the restore stream includes database selection statements.

## Permissions (high level)

- The role ensures the backups directory exists and is owned by the configured backup user.
- Backup artifacts are created with restricted permissions (group-readable) so they can be accessed by the web stack group if needed.
