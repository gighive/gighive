# Problem: Playwright / Upload Tests Timing Race Conditions

**Date discovered:** 2026-07-03  
**Status:** Fixed  
**Affected test:** `tests/admin-pages.spec.ts` — "Admin pages full regression — all 13 steps"  
**Failure symptom:** `expect(locator('#b-upload-panel')).toBeVisible()` timeout at line 119

---

## Summary

The full-regression Playwright test failed at step 9 (Section B: manifest add) with a MySQL
foreign key constraint violation:

```
SQLSTATE[23000]: Integrity constraint violation: 1452 Cannot add or update a child row:
a foreign key constraint fails (`media_db`.`assets`, CONSTRAINT `fk_assets_tenant`
FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`tenant_id`))
```

Root cause: the DB restore (step 6) ran asynchronously and was still in progress when the
manifest add worker (step 9) tried to INSERT INTO `assets`. The restore's `DROP TABLE tenants`
at 15:44:40 UTC left the `tenants` table momentarily empty, causing the FK check to fail.

---

## Playwright Test Step Sequence (full regression)

| Step | Action | PHP endpoint / script |
|------|--------|----------------------|
| 1 | Password reset | `admin.php` |
| 2 | Export media to ZIP | `export_media.php` (async) |
| 3 | Write disk resize request (full channel only) | `write_resize_request.php` |
| 4 | Clear all media data | `clear_media.php` (synchronous) |
| 5 | Create backup | `/db/run_backup.php` (spawns background `mysqldump`) |
| 6 | Restore DB from backup | `/db/restore_database.php` (spawns background `zcat \| mysql`) |
| 7 | Clear all media data again | `clear_media.php` (synchronous) |
| 8 | Delete all media files from disk | `clear_media_files.php` (synchronous) |
| 9 | Import Media — Section B manifest add | `import_manifest_add_async.php` → `import_manifest_worker.php` |

---

## Root Cause: async job-start vs job-complete signals

Both `doCreateBackup()` and `confirmRestoreDatabase()` in `admin_system.php` set
`#...Status .alert-ok` **immediately when the job is submitted** (HTTP 200 from PHP), not
when the background shell process finishes:

```javascript
// BEFORE FIX — fires .alert-ok on job SUBMIT, not on job COMPLETE
status.innerHTML = '<div class="alert-ok">Backup started. Job: <code>' + jobId + '</code></div>';
// ...
status.innerHTML = '<div class="alert-ok">Restore started. Job: <code>' + jobId + '</code></div>';
```

The Playwright test uses:
```typescript
await expect(page.locator('#createBackupStatus .alert-ok')).toBeVisible({ timeout: 60_000 });
await expect(page.locator('#restoreDbStatus .alert-ok')).toBeVisible({ timeout: 120_000 });
```

Because `.alert-ok` appeared immediately on job submit, Playwright advanced through steps
5 → 6 → 7 → 8 → 9 in rapid succession while the restore background process (`zcat | mysql`)
was still running.

---

## How the race played out (confirmed via MySQL general log)

The MySQL general log (`/var/lib/mysql/general.log`) is enabled in
`ansible/roles/docker/files/mysql/externalConfigs/z-custommysqld.cnf`:

```ini
general_log = 1
general_log_file = /var/lib/mysql/general.log
```

Query to retrieve:
```bash
docker exec mysqlServer grep -i 'tenants' /var/lib/mysql/general.log | tail -80
```

Observed sequence (UTC timestamps, playbook run 2026-07-03):

| UTC time | Connection | Event |
|----------|-----------|-------|
| 15:43:53 | 183 | mysqldump reads `tenants` — Section C smoke test backup |
| 15:44:21 | 205 | mysqldump reads `tenants` — step 5 backup (Create Backup Now) |
| 15:44:40 | 206 | `DROP TABLE IF EXISTS \`tenants\`` — restore starts |
| 15:44:41 | 206 | `CREATE TABLE \`tenants\`` |
| 15:44:41 | 206 | `INSERT INTO \`tenants\` VALUES (1,...)` — row restored |

The manifest add job ID `20260703-114439-52873cfaff6a` encodes EDT time 11:44:39 =
**15:44:39 UTC** — submitted 1 second before the restore dropped `tenants`.

The manifest add worker spawned at 15:44:39, connected to the DB, and tried to
`INSERT INTO assets` (which has `tenant_id DEFAULT 1`, FK → `tenants`) at the exact
moment `tenants` was empty (between DROP at 15:44:40 and INSERT at 15:44:41).

---

## Fix Applied

**File:** `ansible/roles/docker/files/apache/webroot/admin/admin_system.php`

Changed the initial "job submitted" status from `.alert-ok` to `.muted` for both backup and
restore, so Playwright waits for the **poller** to report actual completion:

```javascript
// AFTER FIX — .muted on job submit; poller sets .alert-ok only on success
status.innerHTML = '<div class="muted">Backup started. Job: <code>' + jobId + '</code></div>';
// ...
status.innerHTML = '<div class="muted">Restore started. Job: <code>' + jobId + '</code></div>';
```

`pollBackupLog` and `pollRestoreLog` already set `.alert-ok` only when `state === 'ok'`:
- Backup: `'<div class="alert-ok">Backup completed successfully.</div>'`
- Restore: `renderOkBannerWithDbLink('Restore completed successfully.', 'See Restored Database')`

With this fix:
- Step 5 waits up to 60 s for the `mysqldump | gzip` process to complete and update `_latest.sql.gz`
- Step 6 waits up to 120 s for the `zcat | mysql` process to fully restore the database
- Step 9 only runs after both are complete, eliminating the race

---

## Other findings from investigation

### clear_media.php correctly excludes `tenants`

`admin/clear_media.php` dynamically TRUNCATEs all tables returned by `SHOW TABLES` except
`users` and `tenants`:

```php
$excluded  = ['users', 'tenants'];
$tables    = array_values(array_filter($allTables, fn($t) => !in_array($t, $excluded, true)));
```

This was not the cause, but was confirmed safe.

### import scripts do not touch `tenants`

`import_database.php`, `import_normalized.php`, and `import_manifest_lib.php` (reload mode)
all TRUNCATE only: `event_participants`, `event_items`, `events`, `assets`. None touch `tenants`.

### backup dropdown sort order

`admin_system.php` PHP sorts backup files: `_latest.sql.gz` symlink first, then dated files
newest-first. Playwright `selectOption({ index: 0 })` correctly selects `_latest.sql.gz`.

The `_latest.sql.gz` symlink is updated by `run_backup.php` only after the dump completes
and gzip integrity is verified (`ln -sfn` at the end of the shell script). If the restore
runs before the new backup finishes, it restores from the previous `_latest.sql.gz`.

### Diagnostic capabilities

Three tools are available for diagnosing test and runtime issues:

#### 1. Docker commands (SSH to VM first)

SSH: `ssh ubuntu@gighive2.gighive.internal` (dev VM; see instance list in memory).

```bash
# List running containers
docker ps --format '{{.Names}}\t{{.Image}}'
# Containers: apacheWebServer, mysqlServer, apacheWebServer_tusd, ai-worker

# Tail Apache/PHP-FPM logs
docker logs apacheWebServer --tail 100 -f

# Tail MySQL error log
docker exec mysqlServer tail -f /var/lib/mysql/error.log

# Run an ad-hoc query
docker exec mysqlServer sh -c 'mysql -h 127.0.0.1 -u root -p"$MYSQL_ROOT_PASSWORD" media_db -e "SELECT * FROM tenants;"'

# Check container environment
docker exec apacheWebServer env | grep GIGHIVE
```

#### 2. MySQL general log

Enabled in `ansible/roles/docker/files/mysql/externalConfigs/z-custommysqld.cnf`:
```ini
general_log = 1
general_log_file = /var/lib/mysql/general.log
```

Every query from every session is recorded with a UTC timestamp and connection ID.

```bash
# All operations touching a specific table
docker exec mysqlServer grep -i 'tenants' /var/lib/mysql/general.log | tail -100

# TRUNCATE or DROP operations (race condition debugging)
docker exec mysqlServer grep -iE 'TRUNCATE|DROP TABLE' /var/lib/mysql/general.log | tail -100

# Recent activity across all sessions
docker exec mysqlServer tail -200 /var/lib/mysql/general.log

# Rotate/clear after a debugging session (log grows unbounded)
docker exec mysqlServer sh -c '> /var/lib/mysql/general.log'
```

Connection IDs in the log let you trace all queries from a single session (e.g., a backup
process vs. a restore process vs. a PHP worker) across overlapping time windows.

#### 3. MCP server (Windsurf IDE — when available)

MCP servers expose read-only DB query tools directly in the IDE without SSH. Available
environments:

| MCP server | Environment | VM |
|-----------|-------------|-----|
| `dev` (mcp0) | dev | devvm.gighive.internal (gighive2) |
| `lab` (mcp1) | lab | labvm.gighive.internal (gighive) |
| `prod` (mcp2) | prod | prod.gighive.internal |
| `staging` (mcp3) | staging | stagingvm.gighive.internal |

Useful MCP tools: `execute_select`, `get_ai_queue_stats`, `get_assets_untagged`,
`get_jobs_failed`, `get_jobs_stale`, `get_tag_namespace_summary`.

**Note:** MCP transport can close after a stale SSH session. If MCP tool calls return
`transport closed`, kill the stale SSH process on the VM and reload Windsurf.
See `docs/feature_completed_mcp_server.md` for full troubleshooting steps.

---

## Bug 2: Overall test timeout too tight after adding proper async waits

**Discovered:** 2026-07-03 (second test run, same session)  
**Symptom:** `expect(locator('#importDbStatus .alert-ok')).toBeVisible()` failed — element not found.  
Page snapshot showed "Processing request..." still visible, button still disabled.

### Root cause

The global `timeout: 90_000` in `playwright.config.ts` was set before the backup/restore fix
added ~12 seconds of legitimate waiting. With that extra wait, steps 1–11 consumed 87 seconds,
leaving only 3 seconds for step 12. The `import_database.php` endpoint takes 3–4 seconds
(matching upload_tests benchmarks), so the overall timeout fired while the fetch was still
in-flight.

### Confirmed by MySQL general log timing chart

Test started: **16:26:09 UTC** (12:26:09 EDT). Overall timeout = 90s → fires at **16:27:39**.

| Elapsed | UTC | Connection | Event |
|---------|-----|-----------|-------|
| +5–17s | 16:26:14–26 | 276 | Step 4: clear_media (14-table TRUNCATE) |
| +18s | 16:26:27 | 286 | Step 5: backup mysqldump reads `tenants` |
| +20–29s | 16:26:29–38 | 287 | Step 6: restore — DROP+CREATE+INSERT `tenants` at +26s |
| +31–44s | 16:26:40–53 | 294 | Step 7: second clear_media (backup/restore fix working) |
| +44–76s | 16:26:53–27:25 | — | Steps 8–10: delete files, manifest add, TUS uploads |
| **+77s** | **16:27:26–28** | **336** | **Step 11: manifest reload TRUNCATEs** |
| **+87s** | **16:27:36–37** | **340** | **Step 12: CSV import TRUNCATEs** |
| **+90s** | **16:27:39** | — | **Overall timeout fires** |

The TRUNCATEs for step 12 appear at +87s. The import needs ~3s more to insert rows and return
JSON. The timeout fires at +90s — 0–2 seconds before the fetch would have resolved.

The `"element(s) not found"` error (not "element not visible") confirms the `.alert-ok` div was
**never created** — the page was killed mid-fetch, leaving only the `.muted` "Processing
request..." div.

### How to diagnose future timeout issues

1. Note the elapsed times from the MySQL log: `first_event_UTC - test_start_UTC = elapsed_seconds`
2. If the last DB operation appears within a few seconds of the configured timeout, it's a timing
   squeeze, not a functional bug.
3. The `"element(s) not found"` vs `"element not visible"` distinction matters:
   - **not found** → the JS callback never ran (fetch killed mid-flight, or page navigated away)
   - **not visible** → the element exists in DOM but is hidden (logic bug in JS status rendering)

### Fix applied

`ansible/roles/playwright_admin_tests/files/playwright.config.ts` — raised global timeout:

```typescript
// Before
timeout: 90_000,
// After
timeout: 300_000,   // 5 minutes — matches per-step upload badge wait ceiling
```

The individual per-step timeouts (`toBeVisible({ timeout: 60_000 })`, `300_000` for upload
badges) already cover the actual operation durations. The global timeout only needs to be a
safe ceiling above the realistic sum of all steps.

### Deployment note for this fix

`playwright.config.ts` is synced to the VM by the `playwright_admin_tests` Ansible role at the
start of every test run ("Sync role files to playwright work dir" task). **No redeploy
needed** — running just the test playbook picks up the change.

Contrast with PHP/Ansible file changes (e.g., `admin_system.php`), which require a redeploy
first because they are synced by the `base` role and need the Apache container to be rebuilt
or the volume-mounted file to be refreshed.

---

## Test execution order

Full regression is always run as two sequential Ansible playbook commands:

1. **Redeploy** (pushes code, rebuilds containers, skips tests):
   ```
   --skip-tags vbox_provision,db_migrations,installation_tracking,one_shot_bundle,
               one_shot_bundle_archive,upload_tests,playwright_admin_tests
   ```

2. **Test run** (upload_tests then playwright_admin_tests):
   ```
   --tags set_targets,test_admin_pages.yml,upload_tests,playwright_admin_tests
   ```

Upload tests run before Playwright. The upload_tests role includes variants:
`3a_legacy_import_gighive`, `3a_legacy_import_defaultcodebase`,
`3b_normalized_import_gighive`, `3b_normalized_import_defaultcodebase`,
`4_manifest_reload`, `6_direct_upload_api`, `7_tus_finalize`, `5_manifest_add`.

Each manifest job variant polls `import_manifest_status.php` and asserts `state == 'ok'`
before proceeding, so upload_tests failures are surfaced immediately.
