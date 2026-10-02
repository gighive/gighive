# Admin Pages — Playwright UI Test Plan

## Purpose

Repeatable regression test that exercises every function across all four admin pages and ends with the canonical **sample dataset** DB state (matching the original MySQL initialization for `database_full: false`).

## Tool

**Playwright** — records interactions via `npx playwright codegen <url>`, replays headlessly or headed.

---

## Prerequisites

### Credentials
Store in `tests/.env` (never commit):
```
ADMIN_URL=https://gighive2.yourdomain.com
ADMIN_USER=admin
ADMIN_PASS=<admin htpasswd password>
TEST_ADMIN_PW=<new admin password to set — can differ from ADMIN_PASS>
TEST_VIEWER_PW=<viewer password to set>
TEST_UPLOADER_PW=<uploader password to set>
MEDIA_FOLDER=/tmp/gighive-media
```

### CSV files (from repo)
| Purpose | Repo path |
|---------|-----------|
| Step 11 — Legacy import | `ansible/fixtures/upload_tests/csv/databaseSmall.csv` |
| Step 12 — Normalized sessions | `ansible/roles/docker/files/mysql/dbScripts/loadutilities/normalized_csvsSmall/sessions.csv` |
| Step 12 — Normalized session files | `ansible/roles/docker/files/mysql/dbScripts/loadutilities/normalized_csvsSmall/session_files.csv` |

### Media folder (prepare once)

The test reads from the directory set in `MEDIA_FOLDER`. The sample files already live in the repo — create a flat combined directory once:

```bash
mkdir -p /tmp/gighive-media
cp /home/sodo/gighive/assets/video/*.mp4 /tmp/gighive-media/
cp /home/sodo/gighive/assets/audio/*.mp3 /tmp/gighive-media/
```

This gives a flat directory of **16 files (6 mp4 + 10 mp3)**. Files are identified by SHA-256 content hash so their names don't matter.

> **Critical for final state:** These must be the exact binary files from `assets/video/` and `assets/audio/` whose checksums match `normalized_csvsSmall/session_files.csv`. After step 12 the DB records will link to the files physically on disk.

---

## Execution Sequence

| Step | Page | Section | Action | Inputs |
|------|------|---------|--------|--------|
| 1 | `admin/admin.php` | — | Update Passwords | `#admin_password` = TEST_ADMIN_PW, `#admin_password_confirm` = TEST_ADMIN_PW; `#viewer_password` = TEST_VIEWER_PW, `#viewer_password_confirm` = TEST_VIEWER_PW; `#uploader_password` = TEST_UPLOADER_PW, `#uploader_password_confirm` = TEST_UPLOADER_PW; click **Update Passwords** |
| 2 | `admin/admin_system.php` | D | Export Media to ZIP | `#export_org_name` = _(blank — export all)_; `#export_file_type` = `all`; click **Download ZIP**; verify download starts |
| 3 | `admin/admin_system.php` | E | Write Disk Resize Request | `#resize_inventory_host` = `gighive2`; `#resize_disk_size_gib` = `256`; click **Write Resize Request**; accept `confirm()` dialog; verify success banner _(note: section only visible when `GIGHIVE_INSTALL_CHANNEL=full`)_ |
| 4 | `admin/admin_system.php` | A | Clear All Media Data | Click **Clear All Media Data**; accept `confirm()` dialog; verify success |
| 5 | `admin/admin_system.php` | B | Restore DB from Backup | `#restore_backup_file` = select most recent backup (first option in dropdown); `#restore_confirm` = type `RESTORE` exactly; click **Restore Database**; accept `confirm()` dialog; wait for restore job to complete (poll until log shows success) |
| 6 | `admin/admin_system.php` | A | Clear All Media Data (again) | Same as step 4 — clean slate after the restore |
| 7 | `admin/admin_system.php` | C | Delete All Media Files from Disk | Click **Delete All Media Files**; accept `confirm()` dialog; verify success |
| 8 | `admin/admin_database_load_import_media_from_folder.php` | B | Add to DB from Folder | `#b-folder` = select `MEDIA_FOLDER` (folder picker); wait for file count preview; click **Scan & Submit (Add to DB)**; accept `confirm()` dialog; wait for Step 2 panel to appear; click **Upload Media**; wait for upload completion |
| 9 | `admin/admin_database_load_import_media_from_folder.php` | C | Single File Upload | Click **Upload Utility** button; verify `upload_form.php` opens in new tab |
| 10 | `admin/admin_database_load_import_media_from_folder.php` | A | Reload DB from Folder _(puts media files on disk for steps 11–12)_ | `#a-folder` = select `MEDIA_FOLDER` (folder picker); wait for file count preview; click **Scan & Submit (Reload DB)**; accept `confirm()` dialog; wait for Step 2 panel to appear; click **Upload Media**; wait for upload completion |
| 11 | `admin/admin_database_load_import_csv.php` | A | Legacy single-CSV import | `#database_csv` = select `databaseSmall.csv`; click **Upload CSV and Reload DB**; accept `confirm()` dialog; wait for success banner |
| 12 | `admin/admin_database_load_import_csv.php` | B | **Normalized CSV import — FINAL STATE** | `#normalized_sessions_csv` = select `normalized_csvsSmall/sessions.csv`; `#normalized_session_files_csv` = select `normalized_csvsSmall/session_files.csv`; click **Upload 2 CSVs and Reload DB**; accept `confirm()` dialog; wait for success banner |

---

## Final State After Step 12

| Layer | State |
|-------|-------|
| DB metadata | Matches original MySQL init: 2 events (StormPigs Oct 2002, StormPigs Mar 2005), songs, assets from `normalized_csvsSmall/` |
| Physical media files on disk | 16 files uploaded in step 10 remain on disk (CSV imports in steps 11–12 are DB-only, they do not touch disk) |
| Media–DB linkage | Checksums in `session_files.csv` match checksums of files uploaded in step 10 |

---

## Confirm Dialog Summary

| Step | Dialog type | Required input |
|------|------------|----------------|
| 3 | `window.confirm()` | Accept |
| 4 | `window.confirm()` | Accept |
| 5 | Text field + `window.confirm()` | Type `RESTORE` in `#restore_confirm`, then accept |
| 6 | `window.confirm()` | Accept |
| 7 | `window.confirm()` | Accept |
| 8 | `window.confirm()` | Accept |
| 10 | `window.confirm()` | Accept |
| 11 | `window.confirm()` | Accept |
| 12 | `window.confirm()` | Accept |

---

## Playwright Setup (one-time)

```bash
# From repo root
npm init playwright@latest   # creates tests/ dir, installs Chromium
```

Record a test interactively (opens browser + live code panel):
```bash
npx playwright codegen --browser chromium http://admin:<ADMIN_PASS>@gighive2.yourdomain.com/admin/admin.php
```

Replay:
```bash
npx playwright test              # headless
npx playwright test --headed     # watch it run
npx playwright test --debug      # step through with inspector
```

---

## How Playwright Works

A Playwright test is a **TypeScript file** that drives a real browser programmatically. Write the steps once; run them any time.

```
npx playwright test  →  opens Chromium  →  executes every step  →  reports pass/fail
```

### Two ways to create a test

**Option A — Record it** (you click, Playwright writes the code live):
```bash
npx playwright codegen https://gighive2.yourdomain.com/admin/admin.php
```
A browser opens next to a live code panel. Every click, fill, and file pick you perform appears as TypeScript in real time. Save when done.

**Option B — Written from spec (used here)** — since every step, element ID, input value, and confirm dialog was already defined in the table above, `tests/admin-pages.spec.ts` was written directly without recording.

### What each step looks like in code

```typescript
// Step 4 — Clear All Media Data
await page.click('#clearMediaBtn');          // click the button (confirm auto-accepted)
await expect(page.locator('#clearMediaStatus .alert-ok')).toBeVisible(); // assert success
```

### Running the tests

```bash
# One-time setup (from repo root)
npm install                   # installs @playwright/test, dotenv, @types/node
npx playwright install        # downloads Chromium browser binary

# Copy and fill in credentials
cp tests/.env.example tests/.env

# Run
npx playwright test           # headless — fast, for CI
npx playwright test --headed  # watch it run in a real browser
npx playwright test --debug   # step-through with Playwright Inspector
```
