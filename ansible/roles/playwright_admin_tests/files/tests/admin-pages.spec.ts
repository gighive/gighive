import { test, expect } from '@playwright/test';
import * as path from 'path';
import * as fs from 'fs';
import * as dotenv from 'dotenv';

dotenv.config({ path: path.join(__dirname, '.env') });

// ── In-repo CSV paths ────────────────────────────────────────────────────────
const REPO = process.env.REPO_ROOT ?? path.resolve(__dirname, '..');
const CSV_LEGACY        = path.join(REPO, 'ansible/fixtures/upload_tests/csv/databaseSmall.csv');
const CSV_SESSIONS      = path.join(REPO, 'ansible/roles/docker/files/mysql/dbScripts/loadutilities/normalized_csvsSmall/sessions.csv');
const CSV_SESSION_FILES = path.join(REPO, 'ansible/roles/docker/files/mysql/dbScripts/loadutilities/normalized_csvsSmall/session_files.csv');

// ── Media folder path (set MEDIA_FOLDER in tests/.env) ─────────────────────
// webkitdirectory inputs require a directory path, not individual file paths
function getMediaDir(): string {
  const dir = process.env.MEDIA_FOLDER ?? '';
  if (!dir) throw new Error('MEDIA_FOLDER not set in tests/.env');
  if (!fs.existsSync(dir)) throw new Error(`MEDIA_FOLDER not found: ${dir}`);
  return dir;
}

test.describe.configure({ mode: 'serial' });

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

test('Admin pages full regression — all 13 steps', async ({ page }) => {
  // Auto-accept every window.confirm() dialog throughout the test
  page.on('dialog', dialog => dialog.accept());

  // Pipe browser console errors and uncaught exceptions to terminal output
  page.on('console', msg => { if (msg.type() === 'error') console.log('[browser error]', msg.text()); });
  page.on('pageerror', err => console.log('[page error]', err.message));

  const mediaDir = getMediaDir();

  // ── Step 1: admin.php — Password Reset ──────────────────────────────────────
  await page.goto('/admin/admin.php');
  await page.fill('#admin_password',            process.env.TEST_ADMIN_PW!);
  await page.fill('#admin_password_confirm',    process.env.TEST_ADMIN_PW!);
  await page.fill('#viewer_password',           process.env.TEST_VIEWER_PW!);
  await page.fill('#viewer_password_confirm',   process.env.TEST_VIEWER_PW!);
  await page.fill('#uploader_password',         process.env.TEST_UPLOADER_PW!);
  await page.fill('#uploader_password_confirm', process.env.TEST_UPLOADER_PW!);
  // On success PHP redirects to /?passwords_changed=1 — wait for that URL
  await Promise.all([
    page.waitForURL('**/?passwords_changed=1', { timeout: 15_000 }),
    page.click('button[type="submit"]'),
  ]);
  // Admin password just changed — switch Authorization header for steps 2–12
  const newB64 = Buffer.from(
    `${process.env.ADMIN_USER ?? 'admin'}:${process.env.TEST_ADMIN_PW!}`
  ).toString('base64');
  await page.setExtraHTTPHeaders({ 'Authorization': `Basic ${newB64}` });

  // ── Step 2: admin_system.php — Section E: Export Media Archive ──────────────
  await page.goto('/admin/admin_system.php');
  await page.fill('#export_org_name', '');           // blank = export all
  await page.selectOption('#export_file_type', 'all');
  await page.click('#exportMediaBtn');
  await expect(page.locator('#exportMediaStatus')).not.toBeEmpty({ timeout: 30_000 });

  // ── Step 3: admin_system.php — Section F: Write Disk Resize Request ─────────
  // Only rendered when GIGHIVE_INSTALL_CHANNEL=full; skipped otherwise
  if (await page.locator('#writeResizeRequestBtn').isVisible()) {
    await page.fill('#resize_inventory_host', 'gighive2');
    await page.fill('#resize_disk_size_gib', '256');
    await page.click('#writeResizeRequestBtn');
    await expect(page.locator('#resizeRequestStatus .alert-ok')).toBeVisible({ timeout: 30_000 });
  }

  // ── Step 4: admin_system.php — Section A: Clear All Media Data ──────────────
  await page.click('#clearMediaBtn');
  await expect(page.locator('#clearMediaStatus .alert-ok')).toBeVisible({ timeout: 60_000 });

  // ── Step 5: admin_system.php — Section C: Create Backup Now ─────────────────
  await page.click('#createBackupBtn');
  await expect(page.locator('#createBackupStatus .alert-ok')).toBeVisible({ timeout: 60_000 });

  // ── Step 6: admin_system.php — Section B: Restore DB from Backup ────────────
  await page.selectOption('#restore_backup_file', { index: 0 });
  await page.fill('#restore_confirm', 'RESTORE');
  await page.click('#restoreDbBtn');
  await expect(page.locator('#restoreDbStatus .alert-ok')).toBeVisible({ timeout: 120_000 });

  // ── Step 7: admin_system.php — Section A: Clear All Media Data (again) ───────
  // Reload page to reset state after restore job
  await page.goto('/admin/admin_system.php');
  await page.click('#clearMediaBtn');
  await expect(page.locator('#clearMediaStatus .alert-ok')).toBeVisible({ timeout: 60_000 });

  // ── Step 8: admin_system.php — Section D: Delete All Media Files from Disk ───
  await page.click('#clearMediaFilesBtn');
  await expect(page.locator('#clearMediaFilesStatus .alert-ok')).toBeVisible({ timeout: 30_000 });

  // ── Step 9: Import Media — Section B: Add to DB from Folder (non-destructive)
  await page.goto('/admin/admin_database_load_import_media_from_folder.php');
  await page.locator('#b-folder').setInputFiles(mediaDir);
  await page.waitForFunction(
    () => !(document.getElementById('b-scan-btn') as HTMLButtonElement)?.disabled,
    { timeout: 15_000 }
  );
  await page.click('#b-scan-btn');
  await expect(page.locator('#b-upload-panel')).toBeVisible({ timeout: 60_000 });
  await page.click('#b-upload-btn');
  await page.waitForSelector('#b-upload-panel .upload-row', { timeout: 30_000 });
  await page.waitForFunction(
    () => document.querySelectorAll(
            '#b-upload-panel .badge-uploading, #b-upload-panel .badge-pending'
          ).length === 0,
    { timeout: 300_000 }
  );

  // ── Step 10: Import Media — Section C: Single File Upload (new tab) ──────────
  const [uploadTab] = await Promise.all([
    page.context().waitForEvent('page'),
    page.click('button:has-text("Upload Utility")'),
  ]);
  await expect(uploadTab).toHaveURL(/upload_form\.php/);
  await uploadTab.close();

  // ── Step 11: Import Media — Section A: Reload DB from Folder (destructive) ───
  // Uploads the media files whose checksums steps 11–12 will reference
  await page.locator('#a-folder').setInputFiles(mediaDir);
  await page.waitForFunction(
    () => !(document.getElementById('a-scan-btn') as HTMLButtonElement)?.disabled,
    { timeout: 15_000 }
  );
  await page.click('#a-scan-btn');
  await expect(page.locator('#a-upload-panel')).toBeVisible({ timeout: 60_000 });
  await page.click('#a-upload-btn');
  await page.waitForSelector('#a-upload-panel .upload-row', { timeout: 30_000 });
  await page.waitForFunction(
    () => document.querySelectorAll(
            '#a-upload-panel .badge-uploading, #a-upload-panel .badge-pending'
          ).length === 0,
    { timeout: 300_000 }
  );

  // ── Step 12: CSV Import — Section A: Legacy single-CSV ───────────────────────
  await page.goto('/admin/admin_database_load_import_csv.php');
  await page.setInputFiles('#database_csv', CSV_LEGACY);
  await page.click('#importDbBtn');
  await expect(page.locator('#importDbStatus .alert-ok')).toBeVisible({ timeout: 60_000 });

  // ── Step 13: CSV Import — Section B: Normalized CSVs (FINAL STATE) ──────────
  // DB ends up matching original MySQL init: 2 events, songs, asset checksums
  await page.setInputFiles('#normalized_sessions_csv',      CSV_SESSIONS);
  await page.setInputFiles('#normalized_session_files_csv', CSV_SESSION_FILES);
  await page.click('#importNormalizedBtn');
  await expect(page.locator('#importNormalizedStatus .alert-ok')).toBeVisible({ timeout: 60_000 });
});
