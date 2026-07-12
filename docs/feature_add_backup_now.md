# Feature: On-Demand Database Backup (Section C)

## Overview

Adds a **Create Backup Now** button to `admin/admin_system.php` (Section C) that lets an admin
trigger an immediate `mysqldump | gzip` backup from the browser, with async progress polling
identical to the existing restore flow.  Also adds a standalone Ansible Playwright test for
the backup creation step so the feature can be verified in isolation before the restore test runs.

---

## Problem

`admin/admin_system.php` Section B allows restoring the database from a backup, but backups
are only created by the daily cron job (`dbDump.sh`).  On a fresh install (including every
OSB deployment) the backup directory is empty, so the restore select renders a single
disabled placeholder option:

```html
<option value="" selected disabled>No backups yet created</option>
```

The Playwright admin regression test (Step 5) does `selectOption('#restore_backup_file', {index: 0})`
and times out after 90 s because the only option at index 0 is disabled.  There is no way to
create a backup on demand — neither from the UI nor from any API endpoint.

## Goal

1. Add a new **Section C: Create Database Backup** to `admin/admin_system.php` that lets an
   admin trigger an immediate backup from the browser.
2. Relabel the existing sections below it (C→D, D→E, E→F).
3. Wire the Playwright test to create a backup before attempting the restore, making the full
   regression suite pass on a fresh install.

---

## Files at a Glance

### New files

| File | Function |
|------|----------|
| `ansible/roles/docker/files/apache/webroot/admin/run_backup.php` | POST endpoint — triggers an async `mysqldump \| gzip` job inside the container; returns `{success, job_id}` |
| `ansible/roles/docker/files/apache/webroot/admin/run_backup_status.php` | GET endpoint — polls log/rc/pid files for a running backup job; returns `{success, state, exit_code, offset, log_chunk, filename, size_bytes}` |

### Existing files changed

| File | Change |
|------|--------|
| `ansible/roles/docker/files/apache/webroot/admin/admin_system.php` | Insert Section C HTML + two new JS functions; relabel C→D, D→E, E→F |
| `ansible/roles/playwright_admin_tests/files/tests/admin-pages.spec.ts` | Add standalone smoke test (`Create backup — Section C smoke test`) before the full regression; insert new Step 5 (create backup) in the full regression; renumber old steps 5–12 to 6–13; update description string to `all 13 steps` |

---

## Design Decisions

| Decision | Choice Made | Rationale |
|---|---|---|
| Env var for job log dir | Reuse `GIGHIVE_MYSQL_RESTORE_LOG_DIR` | Avoids any change to `.env.j2`, group_vars, or the mysql_backup role |
| Job file prefix | `backup-{job_id}.*` vs restore's `restore-{job_id}.*` | Prevents collision in the shared log dir |
| Async vs synchronous | Async with log polling — mirrors restore flow exactly | Keeps UI pattern consistent; allows progress streaming; dump can take several seconds |
| Container connectivity | PHP runs inside Apache container, connects to MySQL over Docker-internal network | Same approach as `restore_database.php` — no `docker exec` needed |
| Dynamic select update | On `state: ok`, JS removes placeholder and prepends new option at index 0 | No page reload required between backup creation and restore steps — Playwright test can proceed immediately |
| Filename in dynamic option | Timestamped name from log (e.g. `music_db_2026-06-21_100000.sql.gz`) | `_latest.sql.gz` symlink isn't created until after the job exits; log gives the concrete filename. On next page load, `_latest.sql.gz` appears at index 0 — both are valid. |
| Bash credential logic | Prefer `MYSQL_ROOT_PASSWORD`, fall back to `MYSQL_USER`/`MYSQL_PASSWORD` | Matches container-side `dbDump.sh.j2` exactly |
| Standalone backup test | New `test()` block in spec before the full regression | Lets the Create Backup feature be verified in isolation without running all 13 steps |

---

## Implementation Plan

### Phase 1 — Feature Implementation

Deploy and manually verify Steps 1–3 before moving to Phase 2.  At the end of Phase 1 an admin
can click **Create Backup Now** in the browser, watch the log panel, and see the
`#restore_backup_file` dropdown populate with the new file.

### Step 1 — `admin/run_backup.php` (new)

Implement as a close mirror of `restore_database.php`:

- POST only; 403 if `$user !== 'admin'`
- Validate env vars: `GIGHIVE_MYSQL_BACKUPS_DIR` is set, is a directory, is readable, **and is writable** (backup writes there — restore only reads, so restore_database.php only checks readable; this endpoint must also check writable or the job will start and then fail silently in bash)
- Validate `GIGHIVE_MYSQL_RESTORE_LOG_DIR` is set, is a directory, and is writable
- Generate `$jobId = date('Ymd-His') . '-' . bin2hex(random_bytes(6))`
- Create `$logFile = "{logDir}/backup-{jobId}.log"` and write a header (timestamp, target DB, host)
- Build an inline bash script using the **same preamble and terminal as `restore_database.php`**:
  - First statement: `set -Eeuo pipefail` — without this, a failed `mysqldump` in the pipeline is masked by `gzip`'s 0 exit code and the script continues as if the dump succeeded
  - Second statement: `umask 027` — matches restore pattern
  - Sets `STAMP=$(date +'%F_%H%M%S')` and `OUTFILE="${BACKUPS_DIR}/${DB_NAME}_${STAMP}.sql.gz"`
  - Runs `MYSQL_PWD=... mysqldump -h{host} -u{user} --single-transaction --quick --lock-tables=0 --routines --events --triggers --default-character-set=utf8mb4 --databases {db} | gzip > ${OUTFILE}`
  - Runs `gzip -t ${OUTFILE}` integrity check
  - Echoes `OK: wrote $(stat -c%s ${OUTFILE}) bytes to ${OUTFILE}` (this exact format is parsed by the status endpoint)
  - Creates `ln -sfn $(basename ${OUTFILE}) ${BACKUPS_DIR}/${DB_NAME}_latest.sql.gz`
  - Terminal (mirrors restore exactly): `rc=$?; echo "EXIT_CODE=${rc}"; echo "$rc" > {rcFile}; exit 0` — the rc file is only written when the script reaches this line (i.e. on success); on any earlier failure `set -e` kills the script, rc file is never written, and the status endpoint infers `state: error` from the dead pid with no rc file
- Run via `proc_open` background (stdout/stderr → logFile); write pid to `backup-{jobId}.pid`
- Return `{success: true, job_id: $jobId, message: "Backup started."}`

### Step 2 — `admin/run_backup_status.php` (new)

Implement as a close mirror of `restore_database_status.php`:

- GET only; 403 if not admin
- Validate `job_id` param matches `[0-9]{8}-[0-9]{6}-[a-f0-9]{12}`
- Resolve `backup-{job_id}.log`, `.rc`, `.pid` from `GIGHIVE_MYSQL_RESTORE_LOG_DIR`
- Same running/ok/error state logic as the restore status endpoint
- Return `{success, state, exit_code, offset, log_chunk}`; additionally when `state === 'ok'`,
  scan the full log for the line matching `/OK: wrote (\d+) bytes to (.+\.sql\.gz)/` and include
  both `filename` (PHP `basename()` of capture group 2) and `size_bytes` (integer of capture group 1)
  in the response — the JS needs `size_bytes` to render `{filename} ({size})` matching the
  PHP-rendered option format

### Step 3 — `admin/admin_system.php` (modify)

**3a. Relabel existing sections (heading text only — no JS or logic changes):**

| Find | Replace |
|------|---------|
| `Section C: Delete All Media Files from Disk` | `Section D: Delete All Media Files from Disk` |
| `Section D: Export Media to ZIP` | `Section E: Export Media to ZIP` |
| `Section E: Write Disk Resize Request` | `Section F: Write Disk Resize Request` |

> **Note:** The `<?php if ($__show_disk_resize): ?>` conditional wrapper around Section F (was E)
> is **unchanged** — only the `<h2>` heading text changes.  The JS functions
> (`confirmClearMedia`, `confirmClearMediaFiles`, `confirmRestoreDatabase`, etc.) reference
> element IDs, not section labels, so no JS changes are needed for the relabeling.

**3b. Insert new Section C HTML block** between Section B closing `</div>` and the (now) Section D:

```
Section C: Create Database Backup
- Description paragraph
- Warning box: "This overwrites the _latest.sql.gz symlink."
- <div id="createBackupStatus"></div>
- <div id="createBackupLog" style="display:none; …log panel styles…"></div>
- <button id="createBackupBtn" onclick="doCreateBackup()">Create Backup Now</button>
```

**3c. Add JS alongside the existing restore JS:**

- Declare `let __backupPollTimer = null;` alongside the existing `let __restorePollTimer = null;` — without its own variable, `pollBackupLog()` would clobber the restore timer if both were in flight
- `doCreateBackup()` — entry sequence (mirrors `confirmRestoreDatabase()`):
  1. `if (!window.confirm('Create a new database backup now?')) return;` — safety gate; keeps the smoke test's `page.on('dialog', dialog => dialog.accept())` handler necessary and consistent
  2. `document.getElementById('createBackupBtn').disabled = true;` — prevents double-submission, same pattern as every other action button on the page
  3. POSTs to `/admin/run_backup.php`; on success calls `pollBackupLog(jobId)`
- `pollBackupLog(jobId)` — `setInterval` polling `/admin/run_backup_status.php?job_id=…&offset=…`;
  on `state: 'ok'`:
  - Renders `.alert-ok` banner in `#createBackupStatus`
  - **Guard**: only proceed with DOM update if `data.filename` is present in the response (if the OK-line regex failed to parse, `filename` is absent — skip the select update rather than inserting `<option value="undefined">`)
  - DOM update sequence for `#restore_backup_file` (order matters):
    1. Remove the disabled placeholder `<option value="" disabled>` (if present)
    2. Create a new `<option value="{filename}">{filename} ({size_bytes formatted})</option>` and insert it at `selectEl.insertBefore(newOpt, selectEl.firstChild)` so it lands at index 0
  - Format `size_bytes` using a JS helper that mirrors `__format_backup_size()` (B / KB / MB / GB)
  - Removes `disabled` attribute from `#restoreDbBtn`
  - Clears `__backupPollTimer`
  on `state: 'error'` (mirrors `pollRestoreLog()` error branch):
  - Clears `__backupPollTimer`
  - Renders `.alert-error` banner in `#createBackupStatus`
  - Re-enables `#createBackupBtn` (so the admin can retry without a page reload)

### Phase 2 — Test Update

Implement only after Phase 1 is deployed and the backup button works end-to-end.  Steps 4–6
update the Playwright spec; the playbook itself needs no change.

### Step 4 — `tests/admin-pages.spec.ts` (modify)

Insert a new step between current Step 4 (Clear Media) and Step 5 (Restore):

```ts
// ── Step 5: admin_system.php — Section C: Create Database Backup ─────────────
await page.click('#createBackupBtn');
await expect(page.locator('#createBackupStatus .alert-ok')).toBeVisible({ timeout: 60_000 });
```

In the **same editing pass**, make all of the following changes to the full regression test:

1. **Renumber** step comments old 5→6, 6→7, … 12→13 (total becomes 13).
2. **Update section letter labels** in existing step comments that are stale after the PHP rename:

| Line (current) | Current text | Must become |
|---|---|---|
| 54 | `Section D: Export Media to ZIP` | `Section E: Export Media to ZIP` |
| 61 | `Section E: Write Disk Resize Request` | `Section F: Write Disk Resize Request` |
| 86 (becomes Step 8) | `Section C: Delete All Media Files from Disk` | `Section D: Delete All Media Files from Disk` |

3. **Update the test description string** at line 25:
   `test('Admin pages full regression — all 12 steps', ...`
   → `test('Admin pages full regression — all 13 steps', ...`

### Step 5 — Add standalone backup test to `tests/admin-pages.spec.ts` (new `test()` block)

Add a new focused `test()` block **before** the full regression test.  Because
`test.describe.configure({ mode: 'serial' })` is already set at the file level, this block
runs first and provides isolated validation of the Create Backup Now feature without
executing all 13 steps.

```ts
test('Create backup — Section C smoke test', async ({ page }) => {
  page.on('dialog', dialog => dialog.accept());

  await page.goto('/admin/admin_system.php');
  await page.click('#createBackupBtn');
  await expect(page.locator('#createBackupStatus .alert-ok')).toBeVisible({ timeout: 60_000 });

  // Backup file should now appear as at least one selectable (enabled) option.
  // Use not.toHaveCount(0) rather than toHaveCount(1): on a non-fresh VM or re-run,
  // PHP already rendered prior backup files as enabled options, so count may be > 1.
  const sel = page.locator('#restore_backup_file');
  await expect(sel.locator('option:not([disabled])')).not.toHaveCount(0);

  // Restore button should be enabled
  await expect(page.locator('#restoreDbBtn')).toBeEnabled();
});
```

This test also needs to be reflected in `ansible/playbooks/test_admin_pages.yml` — see Step 6.

### Step 6 — `ansible/playbooks/test_admin_pages.yml` (no change needed)

The playbook runs the `playwright_admin_tests` role which executes the full spec file.  Both the
new smoke test and the full regression are in the same spec and run automatically in serial order.
No change to the playbook YAML is required — the new test is picked up by the existing
`npx playwright test` invocation.

---

## Verification

After implementation, re-run the playbook:

```bash
ansible-playbook -i ansible/inventories/inventory_osb.yml \
  ansible/playbooks/test_admin_pages.yml \
  -e "allow_destructive=true" \
  -e "playwright_work_dir=/tmp/gighive-playwright" \
  -e "playwright_media_folder=/tmp/gighive-media" \
  -e "gighive_admin_password=<pw>" \
  -e "gighive_viewer_password=<pw>" \
  -e "gighive_uploader_password=<pw>"
```

Expected: all 13 steps pass, no timeout on `#restore_backup_file`.
