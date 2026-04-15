# Problem: One-Shot Bundle Cron Backup — MYSQL_DATABASE Not Set in Env

## Symptom

After the container-side DB backup feature was implemented, the 2 AM backup cron job
produced only a `cron.log` with an error — no dump file was written:

```
sodo@pop-os:/tmp/gighive-one-shot-bundle$ docker exec -it apacheWebServer \
  ls -l /var/www/private/mysql_backups
total 4
-rw-rw-r-- 1 www-data www-data 63 Apr 15 02:00 cron.log

sodo@pop-os:/tmp/gighive-one-shot-bundle$ docker exec -it apacheWebServer \
  cat /var/www/private/mysql_backups/cron.log
2026-04-15T02:00:01-04:00 ERROR: MYSQL_DATABASE not set in env
```

---

## Root Cause

**Linux cron does not inherit the parent process's environment.** When Docker Compose
starts the container, it injects env vars from `env_file: ./apache/externalConfigs/.env`
into the container's process environment. Those vars are visible to `entrypoint.sh`
(which runs as root at container start) and to Apache/PHP worker processes.

However, when `service cron start` launches the cron daemon, it creates a minimal
environment for each spawned job — essentially only `SHELL`, `PATH`, `LOGNAME`, `HOME`,
and `USER`. The credential vars (`MYSQL_DATABASE`, `MYSQL_ROOT_PASSWORD`, `MYSQL_USER`,
`MYSQL_PASSWORD`, `DB_HOST`) are absent.

**Confirmed:** The container env has all the MYSQL vars:

```
docker exec apacheWebServer env | grep -E "^(MYSQL_|DB_HOST)" | sed 's/=.*/=<REDACTED>/'
MYSQL_ROOT_PASSWORD=<REDACTED>
MYSQL_PASSWORD=<REDACTED>
MYSQL_USER=<REDACTED>
DB_HOST=<REDACTED>
MYSQL_DATABASE=<REDACTED>
```

**Confirmed:** `/etc/cron.d/db-backup` as originally written contains zero env var
declarations — only the bare job line:

```
0 2 * * * www-data /usr/local/bin/dbDump.sh >> /var/www/private/mysql_backups/cron.log 2>&1
```

`dbDump.sh` line 14–15:

```bash
DB_NAME="${MYSQL_DATABASE:-}"
[[ -z "$DB_NAME" ]] && echo "$(date -Is) ERROR: MYSQL_DATABASE not set in env" >&2 && exit 1
```

The design doc (`feature_db_backup_one_shot_bundle.md`) stated: *"The container's env
vars…are already loaded from `apache/externalConfigs/.env` via `env_file`…All credentials
are available without reading a separate `.env.mysql` file."* This was correct for
Apache/PHP but did not account for cron child processes.

---

## Why This Is Scoped to the Bundle Only

The full-build `mysql_backup` Ansible role runs on the VM **host** (outside the
container). Its `dbDump.sh` sources `.env.mysql` directly (`set -a; source "$ENV_FILE";
set +a`) — it never relies on inherited process env. The Ansible `cron` module also sets
`SHELL` and `PATH` env entries for the cron user.

The container-side cron block in `entrypoint.sh.j2` is guarded by:

```bash
if [[ "${GIGHIVE_INSTALL_CHANNEL:-full}" == "quickstart" ]]; then
```

The full build never enters this block. The fix below only changes what is inside it.

---

## Fix

Use the standard `/etc/cron.d/` env var declaration syntax to capture the required
credentials at container-start time — when they **are** available in the process env —
and write them as `VAR=value` lines before the job line in the cron.d file.

`printf '%s\n'` expands each value at entrypoint time (shell expansion), writing the
literal credential string to the file. The cron daemon reads cron.d env lines without
further shell interpretation, so special characters (`$`, `!`, `@`, `#`, etc.) in
credential values are passed through safely.

**Edge case:** Debian's vixie-cron env line parser treats a value that starts with `"`
or `'` as a quoted string and strips the outer quotes. If a credential value literally
starts with a quote character, cron will misparse it. For the standard GigHive credential
format (alphanumeric + special chars, not leading with a quote), this is not an issue.

The file mode changes from `0644` → `0640` since the file now contains credential
values.

No changes are needed to `dbDump.sh` — it already reads `${MYSQL_DATABASE:-}` etc.
correctly and will receive the values via the cron.d env injection.

---

## Exact Changes

### 1. `ansible/roles/docker/templates/entrypoint.sh.j2`

**Before (lines 45–48):**

```bash
  cat > /etc/cron.d/db-backup <<'CRONEOF'
0 2 * * * www-data /usr/local/bin/dbDump.sh >> /var/www/private/mysql_backups/cron.log 2>&1
CRONEOF
  chmod 0644 /etc/cron.d/db-backup
```

**After:**

```bash
  {
    printf 'MYSQL_DATABASE=%s\n'      "${MYSQL_DATABASE:-}"
    printf 'MYSQL_ROOT_PASSWORD=%s\n' "${MYSQL_ROOT_PASSWORD:-}"
    printf 'MYSQL_USER=%s\n'          "${MYSQL_USER:-}"
    printf 'MYSQL_PASSWORD=%s\n'      "${MYSQL_PASSWORD:-}"
    printf 'DB_HOST=%s\n'             "${DB_HOST:-mysqlServer}"
    printf '0 2 * * * www-data /usr/local/bin/dbDump.sh >> /var/www/private/mysql_backups/cron.log 2>&1\n'
  } > /etc/cron.d/db-backup
  chmod 0640 /etc/cron.d/db-backup
```

### 2. `apache/externalConfigs/entrypoint.sh` (deployed bundle)

Same change as above, applied to the rendered file in the extracted bundle directory
(e.g., `/tmp/gighive-one-shot-bundle/apache/externalConfigs/entrypoint.sh` lines 45–48).

The bundle's `entrypoint.sh` is a rendered copy of `entrypoint.sh.j2`. Patching it
only takes effect on the **next container restart** — `entrypoint.sh` already ran when
the container started and wrote the now-stale `/etc/cron.d/db-backup`. To apply the fix:

- **With a restart (preferred):** patch `entrypoint.sh`, then `docker compose restart
  apacheWebServer` — the entrypoint re-runs and writes the corrected cron.d file.
- **Without a restart:** patch `/etc/cron.d/db-backup` directly inside the running
  container (`docker exec` as root), then `docker exec apacheWebServer service cron
  reload || true`.

The template change (`entrypoint.sh.j2`) ensures all future bundles are correct from
generation.

---

## Rationale for Approach

| Option | Notes |
|--------|-------|
| **Write env vars into `/etc/cron.d/db-backup`** ✓ | Standard cron.d format; no new files; fix is co-located with cron setup; no changes to `dbDump.sh` |
| Bind-mount `.env` inside container and source it in `dbDump.sh` | Requires docker-compose.yml change + `dbDump.sh` change; exposes the full `.env` (more vars than needed) |
| Write a separate `/etc/dbdump.env` snapshot file and source in `dbDump.sh` | Two-file solution; requires `dbDump.sh` change; adds a new artifact to track |

The cron.d env var approach is the minimal upstream fix: one block change in one file,
no downstream workarounds.

---

## Verification

After applying the fix and restarting the container (or waiting for the next 2 AM run),
confirm the cron.d file contains the env lines:

```bash
docker exec apacheWebServer cat /etc/cron.d/db-backup
```

Expected output:

```
MYSQL_DATABASE=music_db
MYSQL_ROOT_PASSWORD=<value>
MYSQL_USER=appuser
MYSQL_PASSWORD=<value>
DB_HOST=mysqlServer
0 2 * * * www-data /usr/local/bin/dbDump.sh >> /var/www/private/mysql_backups/cron.log 2>&1
```

To trigger a test run immediately (without waiting for 2 AM):

```bash
docker exec apacheWebServer bash -c '/usr/local/bin/dbDump.sh >> /var/www/private/mysql_backups/cron.log 2>&1'
docker exec apacheWebServer cat /var/www/private/mysql_backups/cron.log
docker exec apacheWebServer ls -lh /var/www/private/mysql_backups/
```

A successful run produces a timestamped `.sql.gz` file and a `_latest.sql.gz` symlink.

---

## Status

Implemented. Changes applied to:
- `ansible/roles/docker/templates/entrypoint.sh.j2` (lines 45–53)
- `/tmp/gighive-one-shot-bundle/apache/externalConfigs/entrypoint.sh` (lines 45–53)

Restart `apacheWebServer` to pick up the fix in the running container.
