---
description: Plan to add container-side DB backup for one-shot bundle installs
---
{% raw %}
# Feature Plan: DB Backup for One-Shot Bundle

## Goal

Make `admin_system.php` Section C (Restore Database From Backup) functional for
one-shot bundle installs by creating daily MySQL dumps that land in the bind-mounted
backup directory the admin page already reads from.

---

## Why It Doesn't Work Today

The `mysql_backup` Ansible role installs a host-level cron job and `dbDump.sh` script
on the VM. The one-shot bundle installer (`install.sh`) runs Docker Compose directly —
no Ansible is involved — so the `mysql_backup` role is never invoked.

Result: `./mysql/dbScripts/backups/` on the host is always empty.
`admin_system.php` reads `GIGHIVE_MYSQL_BACKUPS_DIR` → `/var/www/private/mysql_backups`
(container path, bound to `./mysql/dbScripts/backups/`) and shows
**"No backups yet created"** with the Restore button permanently disabled.

---

## Approach: Container-Side Backup via a New Template

Rather than patching `install.sh` to set up a host cron job (fragile, non-portable,
requires the installer to know host OS details), the backup runs inside the existing
`apacheWebServer` container.

**Why the container is the right place:**
- `default-mysql-client` (and therefore `mysqldump`) is **already installed** in the
  Dockerfile (line 22). No new image dependencies.
- The backup volume `./mysql/dbScripts/backups:/var/www/private/mysql_backups:rw` is
  already mounted in the `apacheWebServer` service in `docker-compose.yml`. The
  container already has write access to the right destination.
- MySQL is reachable at hostname `mysqlServer` over Docker Compose's internal bridge
  network. No `docker exec` needed — `mysqldump --host=mysqlServer` works directly.
- The container's env vars (`MYSQL_DATABASE`, `MYSQL_USER`, `MYSQL_PASSWORD`,
  `MYSQL_ROOT_PASSWORD`, `DB_HOST`) are already loaded from
  `apache/externalConfigs/.env` via `env_file` in `docker-compose.yml`. All
  credentials are available without reading a separate `.env.mysql` file.

**Why render through the existing pipeline:**
Adding a `dbDump.sh.j2` template to `ansible/roles/docker/templates/` means the
rendered script flows through `output_bundle.yml`'s "Render template files" task
automatically (that directory is already in `one_shot_bundle_input_paths`). Drift
detection in `monitor.yml` tracks it alongside all other templates. No separate copy
step, no out-of-band script distribution.

**Guard for full-build installs:**
`entrypoint.sh.j2` is shared between the one-shot bundle and the full Ansible build.
The full build uses host-side cron from the `mysql_backup` role and should not also
run a container cron. The cron setup block in the entrypoint is wrapped with:
```bash
if [[ "${GIGHIVE_INSTALL_CHANNEL:-full}" == "quickstart" ]]; then
```
`GIGHIVE_INSTALL_CHANNEL` is already present in both `.env` contexts:
- Full build: `.env.j2` renders it as `full`
- Quickstart: `install.sh` patches it to `quickstart` via `_patch_env_key`

---

## Summary of Files That Change

| File | Change type | What |
|------|-------------|------|
| `ansible/roles/docker/templates/dbDump.sh.j2` | **NEW** | Container-native dump script: net-based `mysqldump`, reads credentials from container env vars, writes to `/var/www/private/mysql_backups/` |
| `ansible/roles/docker/files/apache/Dockerfile` | Edit | Add `cron` to the existing `apt-get install` block |
| `ansible/roles/docker/templates/entrypoint.sh.j2` | Edit | `mkdir` backup dir + write `/etc/cron.d/db-backup` entry + `service cron start`, guarded by `GIGHIVE_INSTALL_CHANNEL=quickstart` |
| `ansible/roles/docker/files/one_shot_bundle/docker-compose.yml` | Edit | Add `./mysql/dbScripts/dbDump.sh:/usr/local/bin/dbDump.sh:ro` volume mount to `apacheWebServer` |
| `ansible/roles/one_shot_bundle/tasks/output_bundle.yml` | Edit (2 places) | Add `dbDump.sh.j2` → `mysql/dbScripts/dbDump.sh` dest mapping + `0755` mode |
| `ansible/roles/one_shot_bundle/tasks/monitor.yml` | Edit (1 place) | Add same dest mapping for drift detection |

---

## Implementation Details

---

### 1. NEW `ansible/roles/docker/templates/dbDump.sh.j2`

New file. Intentionally separate from `ansible/roles/mysql_backup/templates/dbDump.sh.j2`
(which is the host-side script used by the full Ansible build and must stay unchanged).

Key differences vs. the host-side version:
- No `.env.mysql` file read — credentials come from container env vars
- No `docker exec` — uses `mysqldump --host=$DB_HOST` over the internal Docker network
- No `docker` CLI required — no daemon check
- Backup destination is the container-internal path `/var/www/private/mysql_backups/`
  (matches `GIGHIVE_MYSQL_BACKUPS_DIR`)
- `chgrp` call is omitted (irrelevant inside container; ownership set by entrypoint)
- `_latest.sql.gz` symlink logic is identical to the host-side script

```bash
#!/usr/bin/env bash
# dbDump.sh — container-side MySQL dump for one-shot bundle
# Runs inside apacheWebServer; connects to mysqlServer over Docker internal network.
# Credentials are read from container env vars (set via apache/externalConfigs/.env).

set -Eeuo pipefail
umask 027
trap 'rc=$?; echo "$(date -Is) ERROR: unexpected failure (exit $rc)"; exit $rc' ERR

BACKUPS_DIR="/var/www/private/mysql_backups"

DB_HOST="${DB_HOST:-mysqlServer}"
DB_NAME="${MYSQL_DATABASE:-}"
[[ -z "$DB_NAME" ]] && echo "$(date -Is) ERROR: MYSQL_DATABASE not set" >&2 && exit 1

if [[ -n "${MYSQL_ROOT_PASSWORD:-}" ]]; then
  DB_USER="root"
  DB_PASSWORD="$MYSQL_ROOT_PASSWORD"
elif [[ -n "${MYSQL_USER:-}" && -n "${MYSQL_PASSWORD:-}" ]]; then
  DB_USER="$MYSQL_USER"
  DB_PASSWORD="$MYSQL_PASSWORD"
else
  echo "$(date -Is) ERROR: no usable credentials in env" >&2; exit 1
fi

mkdir -p "$BACKUPS_DIR"

STAMP="$(date +'%F_%H%M%S')"
OUTFILE="${BACKUPS_DIR}/${DB_NAME}_${STAMP}.sql.gz"

echo "$(date -Is) START: dumping ${DB_NAME} from ${DB_HOST} to ${OUTFILE}"

if MYSQL_PWD="$DB_PASSWORD" mysqldump \
    -h "$DB_HOST" -u "$DB_USER" \
    --single-transaction --quick --lock-tables=0 \
    --routines --events --triggers --default-character-set=utf8mb4 \
    --databases "$DB_NAME" \
  | gzip > "$OUTFILE"
then
  chmod 0640 "$OUTFILE"
  if gzip -t "$OUTFILE"; then
    BYTES=$(stat -c%s "$OUTFILE" 2>/dev/null || echo "?")
    echo "$(date -Is) OK: wrote $BYTES bytes to $OUTFILE"
    ln -sfn "$(basename "$OUTFILE")" "${BACKUPS_DIR}/${DB_NAME}_latest.sql.gz"
    echo "$(date -Is) INFO: updated latest symlink -> ${DB_NAME}_latest.sql.gz"
  else
    echo "$(date -Is) ERROR: gzip integrity check failed for $OUTFILE"
    exit 1
  fi
else
  rc=$?
  echo "$(date -Is) ERROR: dump failed (exit $rc)"
  exit $rc
fi
```

No Jinja template variables are needed. The cron schedule is set in the entrypoint
(see Change 3), not in this script.

---

### 2. `ansible/roles/docker/files/apache/Dockerfile`

Add `cron` to the existing `apt-get install` block. `default-mysql-client` is already
present (line 22), so `mysqldump` requires no change.

```diff
     default-mysql-client \
+    cron \
     net-tools nfs-common \
```

(Exact line placement: add `cron \` after `default-mysql-client` on its own line.
Alphabetically `cron` sorts after `composer` and before `default-mysql-client`, but
adjacency to `default-mysql-client` is the most readable grouping. Either position
inside the same `apt-get install -y` block is functionally correct.)

---

### 3. `ansible/roles/docker/templates/entrypoint.sh.j2`

Add a block **after** the existing `mkdir -p /var/www/private/import_jobs` block and
**before** the `apache2ctl -t` validation line. The block is guarded by
`GIGHIVE_INSTALL_CHANNEL` so it only activates for bundle installs.

```bash
# Container-side DB backup (one-shot bundle only)
if [[ "${GIGHIVE_INSTALL_CHANNEL:-full}" == "quickstart" ]]; then
  mkdir -p /var/www/private/mysql_backups
  chown www-data:www-data /var/www/private/mysql_backups
  chmod 0775 /var/www/private/mysql_backups

  cat > /etc/cron.d/db-backup <<'CRONEOF'
0 2 * * * www-data /usr/local/bin/dbDump.sh >> /var/www/private/mysql_backups/cron.log 2>&1
CRONEOF
  chmod 0644 /etc/cron.d/db-backup

  service cron start || true
fi
```

Notes:
- `mkdir -p` is safe: the directory already exists as a bind mount, but the call is
  idempotent and also ensures it is present if Docker Compose hasn't created it yet.
- The cron schedule `0 2 * * *` (2:00 AM daily) matches `mysql_dump_hour: "2"` from
  group_vars. It is hard-coded here (no Jinja var) because the entrypoint runs at
  container start — not at Ansible render time — and the one-shot bundle has no
  group_vars to pull from. If schedule configurability is needed later, it can be
  parameterized via an env var.
- The cron entry runs as `www-data`, not `root`. With `umask 027`, new files are
  created `0640` owned `www-data:www-data`. PHP (also running as `www-data`) can
  read them as owner. If the entry ran as `root`, files would be `root:root 0640`
  and `www-data` would have no read access — `admin_system.php` would silently show
  `0 B` and the restore operation would fail.
- `service cron start || true` — the `|| true` prevents `set -e` from aborting if
  `cron` is already running (unlikely on fresh container start, but defensive).

---

### 4. `ansible/roles/docker/files/one_shot_bundle/docker-compose.yml`

Add one line to the `apacheWebServer` `volumes:` list, below the existing
`./apache/externalConfigs/gighive.htpasswd` bind mount:

```diff
       - "./mysql/dbScripts/backups:/var/www/private/mysql_backups:rw"
+      - "./mysql/dbScripts/dbDump.sh:/usr/local/bin/dbDump.sh:ro"
```

This makes the rendered script available inside the container at
`/usr/local/bin/dbDump.sh`, which is the path the crontab entry calls.

**Important:** Docker Compose requires the source file of a file bind mount to exist
before `up`. The rendered `dbDump.sh` will be present in the bundle output at
`mysql/dbScripts/dbDump.sh` because `output_bundle.yml` renders it there (Change 5).

---

### 5. `ansible/roles/one_shot_bundle/tasks/output_bundle.yml` — 2 places

#### 5a. "Ensure destination directories exist" task (~line 46)

In the `_one_shot_bundle_dest_file` variable, add a branch for `dbDump.sh.j2` before
the final `{% else %}` catch-all. This ensures `mysql/dbScripts/` is created in the
bundle output:

```jinja
{% elif _p == (_one_shot_bundle_templates_prefix ~ 'dbDump.sh.j2') %}
mysql/dbScripts/dbDump.sh
```

#### 5b. "Render template files into fresh one-shot bundle output" task (~line 100)

In `_one_shot_bundle_dest_file`, add the same branch before the `{% else %}` catch-all:

```jinja
{% elif _p == (_one_shot_bundle_templates_prefix ~ 'dbDump.sh.j2') %}
mysql/dbScripts/dbDump.sh
```

Also extend `_one_shot_bundle_output_mode` to set `0755` for the script (same pattern
as `install.sh.j2` and `entrypoint.sh.j2`). The full block replacement is:

```jinja
    _one_shot_bundle_output_mode: >-
      {% if _p == (_one_shot_bundle_templates_prefix ~ 'entrypoint.sh.j2')
         or _p == (_one_shot_bundle_templates_prefix ~ 'install.sh.j2')
         or _p == (_one_shot_bundle_templates_prefix ~ 'dbDump.sh.j2') %}
      0755
      {% else %}
      {{ item.mode }}
      {% endif %}
```

The `{% else %} {{ item.mode }} {% endif %}` must be preserved — without it all other
templates render with an undefined mode. Without the `0755` branch the script renders
as the source file's mode (`0644`) — not executable — causing the cron job to fail
with `Permission denied`.

---

### 6. `ansible/roles/one_shot_bundle/tasks/monitor.yml` — 1 place

In the **"Add directory file entries to source manifest"** task (~line 57), add the
same branch before the `{% else %}` catch-all in `_one_shot_bundle_dest_file`:

```jinja
{% elif _p == (_one_shot_bundle_templates_prefix ~ 'dbDump.sh.j2') %}
mysql/dbScripts/dbDump.sh
```

This ensures the drift-detection manifest tracks `dbDump.sh.j2` → `mysql/dbScripts/dbDump.sh`
correctly (missing/changed/extra reporting in the one-shot bundle summary).

The "Add individual file entries to source manifest" task (~line 115) does **not** need
a change — `dbDump.sh.j2` is discovered via the `ansible/roles/docker/templates`
directory scan, not as an individually-stated file path.

---

## UI Flow: End-to-End Restore

Once at least one backup exists, the full restore flow at
`https://<site>/admin/admin_system.php` works as follows:

1. **Section C dropdown** — populated from the backup dir; each entry shows filename
   and size. Files are listed with the `_latest.sql.gz` symlink at the top, then
   individual timestamped dumps sorted newest-first.

2. **Type `RESTORE`** — validated in both JS (before the request is sent) and PHP
   (server-side in `restore_database.php`). Mismatches are caught at both layers.

3. **Click "Restore Database"** — the browser shows a second native `confirm()` dialog
   ("This will OVERWRITE the current database. Continue?"), then POSTs
   `{ filename, confirm: "RESTORE" }` to `/db/restore_database.php`.

4. **Restore executes** — PHP shells out to `zcat file | mysql -h mysqlServer`, a
   direct network call inside the container. No `docker exec` required. Runs
   asynchronously in the background; PHP returns a `job_id` immediately.

5. **Live log panel** — appears below the button and polls
   `/db/restore_database_status.php?job_id=...` every 1.5 seconds, streaming the
   restore log output in real time.

6. **Completion** — on success the button turns green ("Database Restored!") with a
   "See Restored Database" link. On failure the exit code is shown in a red banner.

All env vars the restore needs (`DB_HOST`, `MYSQL_DATABASE`, `MYSQL_ROOT_PASSWORD`,
`GIGHIVE_MYSQL_BACKUPS_DIR`, `GIGHIVE_MYSQL_RESTORE_LOG_DIR`) are already present in
the container. The `restorelogs` bind mount is already in `docker-compose.yml`.

### Important: First Backup Timing

The "Restore Database" button is **disabled** (`disabled` attribute) until at least
one backup file exists in the backup dir. The cron fires at **2 AM daily**, so on day
one — before 2 AM — the dropdown shows "No backups yet created" and the button is
greyed out. The first backup will be available the morning after install.

---

## Status

Implemented.
{% endraw %}
