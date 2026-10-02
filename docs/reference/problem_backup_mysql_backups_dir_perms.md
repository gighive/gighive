---
description: Automated MySQL backups silently stopped on all environments after 2026-07-15 due to a recursive chown in entrypoint.sh clobbering cron.log ownership on the host volume.
---

# Problem: Automated MySQL Backups Silently Stopped — `chown -R` in entrypoint.sh Clobbered Host cron.log Permissions

**Date:** 2026-07-12 (introduced) / 2026-08-16 (diagnosed and fixed)  
**Environments affected:** all (gighive2, labvm, stagingvm, prod)  
**Symptom:** No automated backup files after 2026-07-15 (gighive2), 2026-07-18 (lab), 2026-07-16 (staging/prod) until a manual backup was run on 2026-07-26.

---

## Summary

Commit `1b9c961` (2026-07-12, "Changes: admin_system.php backup/restore to/from folder changes") added `chown -R www-data:www-data /var/www/private/mysql_backups` to `entrypoint.sh.j2`. The `/var/www/private/mysql_backups` path inside the Apache container is a Docker volume mount from the host path `{{ mysql_backups_dir }}` (`…/mysql/dbScripts/backups/`). Every container restart ran `chown -R`, recursively setting all files in the host directory — including `cron.log` — to `www-data:www-data` with mode `755`. The nightly cron job runs as `ubuntu` and its command is:

```
./dbDump.sh >> /path/to/backups/cron.log 2>&1
```

When bash evaluates this, it opens `cron.log` for append **before** invoking `dbDump.sh`. Because `cron.log` was now owned `www-data:www-data 755` (no group-write), the open failed with `Permission denied` and the entire command was silently skipped — no backup, no log entry, no error email.

---

## Impact

- **10 days of missed automated backups** across all environments (2026-07-16 through 2026-07-25).
- Failure was completely silent: no email, no log entry, no observable change in behavior.
- The most recent automated backup before manual intervention was 2026-07-15 on gighive2; earlier on other environments.

---

## Symptoms

- Admin UI backup list showed no new entries after ~7/15–7/18 depending on the environment.
- `cron.log` had no entries after the last successful run (final entry: `2026-07-15T02:10:01`).
- `ls -la backups/` showed all files owned `www-data:www-data` including `cron.log`.
- `test -w backups/cron.log` returned `NOT_WRITABLE` when run as `ubuntu`.
- `sudo journalctl` confirmed cron fired the backup command daily (e.g. 2026-07-19 through 2026-07-25 at 02:10) but no `.sql.gz` files were produced.

---

## Chronology

| Date | Event |
|---|---|
| 2026-07-12 | Commit `1b9c961` adds `chown -R www-data:www-data /var/www/private/mysql_backups` to `entrypoint.sh.j2` |
| 2026-07-12–15 | Ansible deploys rolled out to environments; Apache container restarted; `chown -R` fires, clobbering `cron.log` ownership |
| 2026-07-15 02:10 | **Last successful automated backup on gighive2** (`media_db_2026-07-15_021001.sql.gz`) — ran before the container restart on that day's deploy |
| 2026-07-16 onwards | Nightly cron fires at 02:10 daily; bash cannot open `cron.log` for append; command is aborted before `dbDump.sh` runs; no backup written |
| 2026-07-26 | Manual backup run via admin UI on all environments; normal-sized files confirm DB and script are functional |
| 2026-08-16 | Diagnosed via syslog, cron.log inspection, and live permission tests; fix implemented and verified |

---

## Root Cause

**`chown -R www-data:www-data /var/www/private/mysql_backups` in `entrypoint.sh.j2`, applied recursively to a host-mounted Docker volume, set `cron.log` to `www-data:www-data 755`. The `ubuntu` cron user (not `root`, not `www-data` owner) could not open the file for append, causing bash to abort the entire backup command before `dbDump.sh` was invoked.**

Contributing factor: the failure was completely silent. Cron does not email on redirect failures, `dbDump.sh` was never reached so its own error trapping did not fire, and the absence of log entries was easy to miss.

Secondary finding: `ansible/roles/docker/files/mysql/dbScripts/dbDump.sh` — an old 12-line manual script committed in git — shadowed the Ansible-managed template version at the same path. Every `git pull` on the server restored the stale manual script, overwriting the template deployed by the `mysql_backup` role. This was not the cause of the missed backups but would have caused confusion if not addressed simultaneously.

---

## Resolution

Two changes made in `gighiveinfra` on 2026-08-16:

**1. `ansible/roles/docker/templates/entrypoint.sh.j2`**

Removed `-R` from the `chown` call so it applies to the directory only, leaving existing files (including `cron.log`) owned by `ubuntu`:

```bash
# Before (broken):
chown -R www-data:www-data /var/www/private/mysql_backups || true

# After (fixed):
chown www-data:www-data /var/www/private/mysql_backups || true
```

A comment was added explaining why recursion is prohibited here.

**2. `ansible/roles/docker/files/mysql/dbScripts/dbDump.sh` — deleted via `git rm`**

The old static file was removed from the repository. The `mysql_backup` Ansible role's template (`dbDump.sh.j2`) is now the sole source of truth for the backup script at that path.

**On-server remediation (run once per host after deploy):**

```bash
chmod 664 /home/ubuntu/gighive/ansible/roles/docker/files/mysql/dbScripts/backups/cron.log
```

This restores group-write on `cron.log` so `ubuntu` (member of `www-data` group) can append to it immediately. The next Ansible deploy will restart the container with the fixed entrypoint, preventing recurrence.

---

## Verification

Run on gighive2 after deploy (confirmed 2026-08-16):

```bash
# 1. backups/ dir owned ubuntu:www-data, mode drwxrwx--- (no recursive chown)
ls -la .../mysql/dbScripts/backups/

# 2. cron.log writable by ubuntu
test -w .../backups/cron.log && echo WRITABLE || echo NOT_WRITABLE

# 3. dbDump.sh is the Ansible template version (113 lines)
wc -l .../dbScripts/dbDump.sh

# 4. Manual cron test run
cd .../dbScripts && ./dbDump.sh >> backups/cron.log 2>&1; echo EXIT:$?

# 5. cron.log shows fresh START/OK/INFO entries
tail -10 .../backups/cron.log
```

All five checks passed on gighive2. Results:

- Directory: `drwxrwx--- ubuntu:www-data` — correct, no recursive chown
- `cron.log`: `WRITABLE`
- `dbDump.sh`: 113 lines (Ansible template version)
- Manual run: `EXIT:0`
- `cron.log` tail: `START` / `OK` / `INFO` entries with timestamp format and git SHA

---

## Preventative Actions

1. **Never use `chown -R` on a host-mounted Docker volume directory** unless every file inside is exclusively owned by the container user. Volume mounts share the host filesystem; recursive ownership changes affect host-side processes (cron, Ansible) as well as container processes.

2. **Do not commit generated/deployed files to git** when they share a path with an Ansible-managed template output. The `mysql_backup` role deploys `dbDump.sh.j2` → `dbScripts/dbDump.sh`; committing a static `dbDump.sh` at the same path creates a silent race between `git pull` and `ansible-playbook`.

3. **Add a post-build smoke test** that verifies `cron.log` is writable by `ubuntu` after a deploy. A 10-second check would have caught this on the first deploy after 7/12.
