<?php
/**
 * admin.php — Admin page for GigHive
 * Section 1: Update passwords for 'admin', 'viewer', and 'uploader' in Apache .htpasswd
 * Section 2: Clear all media data from the database
 * Point to the target file with env var GIGHIVE_HTPASSWD_PATH (recommended).
 * Default matches your vhost variable path for Option 1:
 *   /var/www/private/gighive.htpasswd
 */

$HTPASSWD_FILE = getenv('GIGHIVE_HTPASSWD_PATH') ?: '/var/www/private/gighive.htpasswd';

/** ---- Access Gate: allow only Basic-Auth user 'admin' ----
 * Different PHP setups surface the authenticated user differently.
 */
$user = $_SERVER['PHP_AUTH_USER']
     ?? $_SERVER['REMOTE_USER']
     ?? $_SERVER['REDIRECT_REMOTE_USER']
     ?? null;

if ($user !== 'admin') {
    http_response_code(403);
    echo "<h1>Forbidden</h1><p>Admin access required.</p>";
    exit;
}

$__json_env_array = function (string $key): array {
    $raw = getenv($key);
    if (!is_string($raw) || trim($raw) === '') {
        return [];
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return [];
    }
    $out = [];
    foreach ($decoded as $x) {
        if (!is_string($x)) {
            continue;
        }
        $x = strtolower(trim($x));
        if ($x === '') {
            continue;
        }
        $out[$x] = true;
    }
    return array_keys($out);
};

$__upload_audio_exts = $__json_env_array('UPLOAD_AUDIO_EXTS_JSON');
$__upload_video_exts = $__json_env_array('UPLOAD_VIDEO_EXTS_JSON');

/** ---- Helpers ---- */
function load_htpasswd(string $path): array {
    if (!is_readable($path)) {
        throw new RuntimeException("Cannot read .htpasswd at $path");
    }
    $map = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if ($line === '' || ltrim($line)[0] === '#') continue;
        [$u, $h] = array_pad(explode(':', $line, 2), 2, '');
        if ($u !== '') $map[$u] = $h;
    }
    return $map;
}

/**
 * Write the htpasswd file safely.
 * - Creates a timestamped backup first.
 * - Uses true atomic replace when possible.
 * - Falls back to in-place write when the target is a bind-mount (EXDEV) or dir not writable.
 */
function write_htpasswd_atomic(string $path, array $map): void {
    $dir      = dirname($path);
    $can_dir  = is_writable($dir);
    $can_file = file_exists($path) ? is_writable($path) : $can_dir;

    if (!$can_dir && !$can_file) {
        throw new RuntimeException("Target not writable by web user (need dir or file writable): $path");
    }

    // Always make a backup if the file exists
    if (file_exists($path)) {
        $backup = $path . '.bak.' . date('Ymd-His');
        if (!@copy($path, $backup)) {
            throw new RuntimeException("Backup failed: $backup");
        }
        @chmod($backup, 0600);
    }

    // Detect cross-filesystem (bind mount) which breaks atomic rename()
    $dirStat  = @stat($dir);
    $fileStat = @stat($path);
    $crossFs  = ($dirStat !== false && $fileStat !== false && $dirStat['dev'] !== $fileStat['dev']);

    // If directory isn't writable or we're crossing FS boundary -> in-place write
    if (!$can_dir || $crossFs) {
        $fh = @fopen($path, 'wb');
        if (!$fh) {
            throw new RuntimeException("Open for write failed (bind-mount or perms?): $path");
        }
        foreach ($map as $u => $hash) {
            if (preg_match('/[:\r\n]/', $u)) { fclose($fh); throw new RuntimeException("Illegal username."); }
            fwrite($fh, $u . ':' . $hash . "\n");
        }
        fclose($fh);
        @chmod($path, 0640);
        return;
    }

    // Happy path: same FS and dir writable → atomic replace
    $tmp = tempnam($dir, 'htp_');
    if ($tmp === false) {
        throw new RuntimeException("Failed to create temp file in $dir");
    }
    $fh = fopen($tmp, 'wb');
    if (!$fh) {
        @unlink($tmp);
        throw new RuntimeException("Failed to open temp file for write.");
    }
    foreach ($map as $u => $hash) {
        if (preg_match('/[:\r\n]/', $u)) { fclose($fh); @unlink($tmp); throw new RuntimeException("Illegal username."); }
        fwrite($fh, $u . ':' . $hash . "\n");
    }
    fclose($fh);

    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        throw new RuntimeException("Atomic replace failed.");
    }

    @chmod($path, 0640);
}

function validate_password(string $label, string $pw, string $confirm): array {
    $e = [];
    if ($pw !== $confirm) $e[] = "$label passwords do not match.";
    if (strlen($pw) < 8)   $e[] = "$label password must be at least 8 characters.";
    return $e;
}

/** ---- Handle POST ---- */
$messages = [];
$errors   = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $admin_pw     = $_POST['admin_password']           ?? '';
    $admin_cfm    = $_POST['admin_password_confirm']   ?? '';
    $viewer_pw    = $_POST['viewer_password']          ?? '';
    $viewer_cfm   = $_POST['viewer_password_confirm']  ?? '';
    $uploader_pw  = $_POST['uploader_password']        ?? '';
    $uploader_cfm = $_POST['uploader_password_confirm']?? '';

    $errors = array_merge(
        validate_password('Admin',    $admin_pw,    $admin_cfm),
        validate_password('Viewer',   $viewer_pw,   $viewer_cfm),
        validate_password('Uploader', $uploader_pw, $uploader_cfm)
    );

    if (!$errors) {
        try {
            if (file_exists($HTPASSWD_FILE)) {
                if (!is_writable($HTPASSWD_FILE) && !is_writable(dirname($HTPASSWD_FILE))) {
                    throw new RuntimeException("The .htpasswd path is not writable by the web server user. Adjust perms or mount RW: $HTPASSWD_FILE");
                }
            } else {
                if (!is_writable(dirname($HTPASSWD_FILE))) {
                    throw new RuntimeException("Target directory is not writable to create .htpasswd: " . dirname($HTPASSWD_FILE));
                }
            }

            $map = file_exists($HTPASSWD_FILE) ? load_htpasswd($HTPASSWD_FILE) : [];

            if (!array_key_exists('admin',    $map)) $map['admin']    = '';
            if (!array_key_exists('viewer',   $map)) $map['viewer']   = '';
            if (!array_key_exists('uploader', $map)) $map['uploader'] = '';

            $map['admin']    = password_hash($admin_pw,    PASSWORD_BCRYPT);
            $map['viewer']   = password_hash($viewer_pw,   PASSWORD_BCRYPT);
            $map['uploader'] = password_hash($uploader_pw, PASSWORD_BCRYPT);
            if ($map['admin'] === false || $map['viewer'] === false || $map['uploader'] === false) {
                throw new RuntimeException("Failed to generate bcrypt hashes.");
            }

            write_htpasswd_atomic($HTPASSWD_FILE, $map);

            // ✅ Redirect to home page with success notification
            header("Location: /?passwords_changed=1", true, 302);
            exit;

        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
        }
    }
}

$__restore_backup_dir = getenv('GIGHIVE_MYSQL_BACKUPS_DIR') ?: '';
$__restore_db_name = getenv('MYSQL_DATABASE') ?: '';
$__restore_backup_files = [];

if (is_string($__restore_backup_dir) && $__restore_backup_dir !== '' && is_dir($__restore_backup_dir) && is_readable($__restore_backup_dir) && is_string($__restore_db_name) && $__restore_db_name !== '') {
    $latest = $__restore_db_name . '_latest.sql.gz';
    $latestPath = rtrim($__restore_backup_dir, '/') . '/' . $latest;
    if (is_file($latestPath)) {
        $__restore_backup_files[] = [
            'name' => $latest,
            'mtime' => @filemtime($latestPath) ?: 0,
            'size' => @filesize($latestPath) ?: 0,
            'is_latest' => true,
        ];
    }

    $pattern = rtrim($__restore_backup_dir, '/') . '/' . $__restore_db_name . '_*.sql.gz';
    foreach (glob($pattern) ?: [] as $p) {
        if (!is_string($p) || !is_file($p)) {
            continue;
        }
        $bn = basename($p);
        if ($bn === $latest) {
            continue;
        }
        if (!preg_match('/^' . preg_quote($__restore_db_name, '/') . '_\\d{4}-\\d{2}-\\d{2}_\\d{6}\\.sql\\.gz$/', $bn)) {
            continue;
        }
        $__restore_backup_files[] = [
            'name' => $bn,
            'mtime' => @filemtime($p) ?: 0,
            'size' => @filesize($p) ?: 0,
            'is_latest' => false,
        ];
    }

    usort($__restore_backup_files, function ($a, $b) {
        $aLatest = !empty($a['is_latest']);
        $bLatest = !empty($b['is_latest']);
        if ($aLatest !== $bLatest) {
            return $aLatest ? -1 : 1;
        }
        return ($b['mtime'] ?? 0) <=> ($a['mtime'] ?? 0);
    });
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>GigHive Admin</title>
  <style>
    :root { font-family: system-ui, -apple-system, Segoe UI, Roboto, sans-serif; }
    body { margin:0; background:#0b1020; color:#e9eef7; }
    .wrap { max-width: 880px; margin: 3rem auto; padding: 1rem; }
    .card { background:#121a33; border:1px solid #1d2a55; border-radius:16px; padding:1.5rem; }
    .row { display:grid; gap:.5rem; margin-bottom:1rem; }
    label { font-weight:600; }
    input[type=password] { width:100%; padding:.7rem; border-radius:10px; border:1px solid #33427a; background:#0e1530; color:#e9eef7; }
    button { padding:.8rem 1.1rem; border-radius:10px; border:1px solid #3b82f6; background:transparent; color:#e9eef7; cursor:pointer; }
    button:hover { background:#1e40af; color:#fff; }
    button.danger { border-color:#dc2626; }
    button.danger:hover { background:#991b1b; }
    button:disabled { cursor:not-allowed; opacity:1; }
    button.danger:disabled { border-color:#dc2626; color:#a8b3cf; background:transparent; }
    .section-divider { border-top:2px solid #1d2a55; margin:2rem 0; padding-top:2rem; }
    .warning-box { background:#3b1f0d; border:1px solid #b45309; padding:1rem; border-radius:10px; margin-bottom:1rem; }
    .alert-ok { background:#11331a; border:1px solid #1f7a3b; padding:.8rem 1rem; border-radius:10px; margin-bottom:1rem;}
    .alert-err{ background:#3b0d14; border:1px solid #b4232a; padding:.8rem 1rem; border-radius:10px; margin-bottom:1rem;}
    .muted { color:#a8b3cf; font-size:.95rem; }
    code.path { word-break: break-all; }
    pre.cmdline { margin:.5rem 0 0 0; white-space:pre-wrap; font-size:.85rem; }
  </style>
</head>
<body>
  <div class="wrap">
    <div class="card">
      <h1>GigHive Admin</h1>
      <p class="muted">
        Signed in as <code><?= htmlspecialchars($user) ?></code>. 
        Updating file: <code class="path"><?= htmlspecialchars($HTPASSWD_FILE) ?></code>
      </p>
      <p class="muted">
        Best practice requires you to change the passwords as soon as you install Gighive.  Please do so.
      </p>

      <?php foreach ($messages as $m): ?>
        <div class="alert-ok"><?= htmlspecialchars($m) ?></div>
      <?php endforeach; ?>
      <?php foreach ($errors as $e): ?>
        <div class="alert-err"><?= htmlspecialchars($e) ?></div>
      <?php endforeach; ?>

      <form method="post" autocomplete="off">
        <div class="row">
          <h2>Section 1: Change default passwords</h2>
          <h2>Admin</h2>
          <label for="admin_password">New admin password</label>
          <input type="password" id="admin_password" name="admin_password" required minlength="8" />
          <label for="admin_password_confirm">Confirm admin password</label>
          <input type="password" id="admin_password_confirm" name="admin_password_confirm" required minlength="8" />
        </div>

        <div class="row">
          <h2>Viewer</h2>
          <label for="viewer_password">New viewer password</label>
          <input type="password" id="viewer_password" name="viewer_password" required minlength="8" />
          <label for="viewer_password_confirm">Confirm viewer password</label>
          <input type="password" id="viewer_password_confirm" name="viewer_password_confirm" required minlength="8" />
        </div>

        <div class="row">
          <h2>Uploader</h2>
          <label for="uploader_password">New uploader password</label>
          <input type="password" id="uploader_password" name="uploader_password" required minlength="8" />
          <label for="uploader_password_confirm">Confirm uploader password</label>
          <input type="password" id="uploader_password_confirm" name="uploader_password_confirm" required minlength="8" />
        </div>

        <p class="muted">A timestamped backup of the current file will be created before updating.</p>
        <button type="submit">Update Passwords</button>
      </form>

      <!-- Section 2: Clear Media Data -->
      <div class="section-divider">
        <h2>Section 2: Clear Sample Media Data (Optional)</h2>
        <p class="muted">
          Remove all demo content (sessions, songs, files, musicians) to make room for your own media.
          This action is <strong>irreversible</strong> and will clear all media tables.
        </p>
        <div class="warning-box">
          <strong>⚠️ Warning:</strong> This will permanently delete all media data from the database.
          The users table will be preserved. 
        </div>
        <div id="clearMediaStatus"></div>
        <button type="button" id="clearMediaBtn" class="danger" onclick="confirmClearMedia()">Clear All Media Data</button>
      </div>

      <div class="section-divider">
        <h2>Section 3A (Legacy): Upload Single CSV and Reload Database (destructive)</h2>
        <p class="muted">
          Upload a CSV export and rebuild the media database tables.
          This action is <strong>irreversible</strong> and will truncate all media tables before loading.
          The users table will be preserved. More detail about converting legacy database <a href="https://gighive.app/convert_legacy_database.html" target="_blank" rel="noopener noreferrer">here</a>.
        </p>
        <div class="warning-box">
          <strong>⚠️ Warning:</strong> This will permanently delete and replace all media data from the database.
        </div>
        <div class="row">
          <label for="database_csv">Select CSV file</label>
          <input type="file" id="database_csv" name="database_csv" accept=".csv" />
        </div>
        <div id="importDbStatus"></div>
        <button type="button" id="importDbBtn" class="danger" onclick="confirmImportDatabase()">Upload CSV and Reload DB</button>
      </div>

      <div class="section-divider">
        <h2>Section 3B (Normalized): Upload Sessions + Session Files and Reload Database (destructive)</h2>
        <p class="muted">
          Upload <strong>sessions.csv</strong> and <strong>session_files.csv</strong> and rebuild the media database tables.
          This action is <strong>irreversible</strong> and will truncate all media tables before loading.
          The users table will be preserved.
        </p>
        <div class="warning-box">
          <strong>⚠️ Warning:</strong> This will permanently delete and replace all media data from the database.
        </div>
        <div class="row">
          <label for="normalized_sessions_csv">Select sessions.csv</label>
          <input type="file" id="normalized_sessions_csv" name="normalized_sessions_csv" accept=".csv" />
        </div>
        <div class="row">
          <label for="normalized_session_files_csv">Select session_files.csv</label>
          <input type="file" id="normalized_session_files_csv" name="normalized_session_files_csv" accept=".csv" />
        </div>
        <div id="importNormalizedStatus"></div>
        <button type="button" id="importNormalizedBtn" class="danger" onclick="confirmImportNormalized()">Upload 2 CSVs and Reload DB</button>
      </div>

      <div class="section-divider">
        <h2>Section 4: Choose a Folder to Scan &amp; Refresh the Database (destructive)</h2>
        <p class="muted">
          STEP 1: Select a folder on this computer to scan for media files and generate an import-ready CSV.
          This action is <strong>irreversible</strong> and will truncate all media tables before loading.
          The users table will be preserved.
        </p>
        <p class="muted">
          Notes:
        </p>
        <div class="muted" style="margin-top:-.5rem">
          <div>- Hashing (SHA-256) is mandatory for an idempotent “add to database” workflow and long-term media library viability.</div>
          <div>- Hashing may take time for large folders (especially video). The UI will show progress while processing.</div>
          <div>- Chrome/Chromium is the recommended browser for best folder scanning support.</div>
          <div style="margin-top:.5rem">STEP 2: After you create the hashes for the files, you will need to upload them to the Gighive server.<br>Use the following command from the source folder you selected in Step 1 (mine was "~/videos/projects"). Note that you'll need mysql-client and PyYAML installed to run this script.:<br><pre class="cmdline">sodo@pop-os:~/videos/projects$ MYSQL_PASSWORD='[password]' python3 ~/scripts/gighive/ansible/roles/docker/files/apache/webroot/tools/upload_media_by_hash.py  --source-root /videos/projects   --ssh-target ubuntu@gighive   --db-host gighive   --db-user appuser   --db-name music_db</pre></div>
          <div>- <a href="https://gighive.app/uploadMediaByHash.html" target="_blank" rel="noopener noreferrer">More info here</a></div>
        </div>
        <div class="warning-box">
          <strong>⚠️ Warning:</strong> This will truncate and rebuild the media tables. Make sure you have a database backup if you want to keep existing data.
        </div>
        <div class="row">
          <label for="media_folder">Select folder</label>
          <input type="file" id="media_folder" name="media_folder" webkitdirectory directory multiple />
        </div>
        <div id="scanFolderPreview"></div>
        <div id="scanFolderStatus"></div>
        <button type="button" id="scanFolderBtn" class="danger" onclick="confirmScanFolderImport()" disabled>Scan Folder and Update DB</button>
        <button type="button" id="stopScanFolderBtn" class="danger" onclick="stopScanFolderImport()" disabled>Stop hashing and reload DB with hashed files</button>
        <button type="button" id="clearScanFolderCacheBtn" class="danger" onclick="clearScanFolderImportCache()" disabled>Clear cached hashes for this folder</button>
      </div>

      <div class="section-divider">
        <h2>Section 5: Choose a Folder to Scan &amp; Add to the Database (non-destructive)</h2>
        <p class="muted">
          STEP 1: Select a folder on this computer to scan for media files and <strong>add</strong> them to the existing database.
          This action does <strong>not</strong> truncate media tables.
        </p>
        <p class="muted">
          Notes:
        </p>
        <div class="muted" style="margin-top:-.5rem">
          <div>- Hashing (SHA-256) is mandatory for idempotency and long-term media library viability.</div>
          <div>- Hashing may take time for large folders (especially video). The UI will show progress while processing.</div>
          <div>- Chrome/Chromium is the recommended browser for best folder scanning support.</div>
          <div style="margin-top:.5rem">STEP 2: After you create the hashes for the files, you will need to upload them to the Gighive server.<br>Use the following command from the source folder you selected in Step 1 (mine was "~/videos/projects"). Note that you'll need mysql-client and PyYAML installed to run this script.:<br><pre class="cmdline">sodo@pop-os:~/videos/projects$ MYSQL_PASSWORD='[password]' python3 ~/scripts/gighive/ansible/roles/docker/files/apache/webroot/tools/upload_media_by_hash.py  --source-root /videos/projects   --ssh-target ubuntu@gighive   --db-host gighive   --db-user appuser   --db-name music_db</pre></div>
          <div>- <a href="https://gighive.app/uploadMediaByHash.html" target="_blank" rel="noopener noreferrer">More info here</a></div>
        </div>
        <div id="scanFolderAddPreview"></div>
        <div id="scanFolderAddStatus"></div>
        <div class="row" style="margin-top:.75rem">
          <label class="muted" style="display:block;margin-bottom:.35rem">Source root (chosen folder):</label>
          <input type="file" id="media_folder_add" name="media_folder_add" webkitdirectory directory multiple />
        </div>
        <button type="button" id="scanFolderAddBtn" class="danger" onclick="confirmScanFolderAdd()" disabled>Scan Folder and Add to DB</button>
        <button type="button" id="stopScanFolderAddBtn" class="danger" onclick="stopScanFolderAdd()" disabled>Stop hashing and import hashed</button>
        <button type="button" id="clearScanFolderAddCacheBtn" class="danger" onclick="clearScanFolderAddCache()" disabled>Clear cached hashes for this folder</button>
      </div>

      <div class="section-divider">
        <h2>Section 6: Write Disk Resize Request (Optional)</h2>
        <p class="muted">
          This creates a resize request file on the server. It does not resize the VM immediately. <a href="https://gighive.app/resizeRequestInstructions.html" target="_blank" rel="noopener noreferrer">Instructions here</a>
        </p>
        <div class="warning-box">
          <strong>⚠️ Warning:</strong> Gighive builds a VM with a default virtual disk size of 64GB.  This command with provide a method to increase the size of the disk.  You will first request a disk resize operation. Then you will run an Ansible script to enlarge the disk. 
        </div>
        <div class="row">
          <label for="resize_inventory_host">Inventory host</label>
          <input type="text" id="resize_inventory_host" name="resize_inventory_host" value="gighive2" />
        </div>
        <div class="row">
          <label for="resize_disk_size_gib">Target disk size (GiB)</label>
          <input type="number" id="resize_disk_size_gib" name="resize_disk_size_gib" min="16" step="1" value="256" />
        </div>
        <div id="resizeRequestStatus"></div>
        <button type="button" id="writeResizeRequestBtn" class="danger" onclick="confirmWriteResizeRequest()">Write Resize Request</button>
      </div>

      <div class="section-divider">
        <h2>Section 7: Restore Database From Backup (Destructive)</h2>
        <p class="muted">
          A full database backup is created daily by the server. Use this section to restore the entire database if something goes wrong.
        </p>
        <div class="warning-box">
          <strong>⚠️ Warning:</strong> This will overwrite the current database with the selected backup.
        </div>

        <div class="row">
          <label for="restore_backup_file">Select a backup</label>
          <select id="restore_backup_file" name="restore_backup_file" style="width:100%;padding:.7rem;border-radius:10px;border:1px solid #33427a;background:#0e1530;color:#e9eef7;">
            <?php if (!count($__restore_backup_files)): ?>
              <option value="" selected disabled>No backups yet created</option>
            <?php else: ?>
              <?php foreach ($__restore_backup_files as $__b): ?>
                <option value="<?= htmlspecialchars((string)$__b['name']) ?>">
                  <?= htmlspecialchars((string)$__b['name']) ?>
                </option>
              <?php endforeach; ?>
            <?php endif; ?>
          </select>
        </div>

        <div class="row">
          <label for="restore_confirm">Type <code>RESTORE</code> to confirm</label>
          <input type="text" id="restore_confirm" name="restore_confirm" value="" style="width:100%;padding:.7rem;border-radius:10px;border:1px solid #33427a;background:#0e1530;color:#e9eef7;" />
        </div>

        <div id="restoreDbStatus"></div>
        <div id="restoreDbLog" style="display:none;margin-top:.75rem;background:#0e1530;border:1px solid #33427a;border-radius:10px;padding:.75rem;max-height:280px;overflow:auto;white-space:pre-wrap;font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,'Liberation Mono','Courier New',monospace;font-size:.85rem;"></div>

        <button type="button" id="restoreDbBtn" class="danger" onclick="confirmRestoreDatabase()" <?php if (!count($__restore_backup_files)): ?>disabled<?php endif; ?>>Restore Database</button>
      </div>
    </div>
  </div>

  <script>
  function confirmWriteResizeRequest() {
    const hostEl = document.getElementById('resize_inventory_host');
    const sizeEl = document.getElementById('resize_disk_size_gib');
    const btn = document.getElementById('writeResizeRequestBtn');
    const status = document.getElementById('resizeRequestStatus');

    const inventoryHost = (hostEl && hostEl.value) ? String(hostEl.value).trim() : '';
    const diskSizeGiB = Number(sizeEl && sizeEl.value ? sizeEl.value : 0);

    if (!inventoryHost) {
      status.innerHTML = '<div class="alert-err">Please enter an inventory host.</div>';
      return;
    }
    if (!Number.isFinite(diskSizeGiB) || diskSizeGiB < 16) {
      status.innerHTML = '<div class="alert-err">Please enter a valid target size (GiB).</div>';
      return;
    }

    const diskSizeMb = Math.floor(diskSizeGiB * 1024);

    if (!confirm('Write disk resize request?\n\nHost: ' + inventoryHost + '\nTarget size: ' + diskSizeGiB + ' GiB (' + diskSizeMb + ' MB)\n\nThis does NOT resize immediately.')) {
      return;
    }

    btn.disabled = true;
    btn.textContent = 'Writing…';
    status.innerHTML = '<div class="muted">Processing request...</div>';

    fetch('write_resize_request.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        inventory_host: inventoryHost,
        disk_size_mb: diskSizeMb
      })
    })
    .then(async response => {
      const data = await response.json().catch(() => null);
      return { ok: response.ok, status: response.status, data };
    })
    .then(({ ok, data }) => {
      if (ok && data && data.success) {
        const msg = (data.message || 'Resize request written successfully.');
        const file = (data.request_file || '');
        status.innerHTML = '<div class="alert-ok">' + msg + (file ? ('<div class="muted" style="margin-top:.25rem">Request file: ' + String(file).replace(/[&<>]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;'}[c])) + '</div>') : '') + '</div>';
        btn.textContent = 'Write Resize Request';
        btn.disabled = false;
      } else {
        const msg = (data && (data.message || data.error)) ? (data.message || data.error) : 'Unknown error occurred';
        status.innerHTML = '<div class="alert-err">Error: ' + msg + '</div>';
        btn.textContent = 'Write Resize Request';
        btn.disabled = false;
      }
    })
    .catch(error => {
      status.innerHTML = '<div class="alert-err">Network error: ' + error.message + '</div>';
      btn.textContent = 'Write Resize Request';
      btn.disabled = false;
    });
  }

  function confirmClearMedia() {
    if (!confirm('Are you sure you want to clear ALL media data?\n\nThis will permanently delete:\n- All sessions\n- All songs\n- All files\n- All musicians\n- All genres and styles\n\nThis action CANNOT be undone!')) {
      return;
    }
    
    const btn = document.getElementById('clearMediaBtn');
    const status = document.getElementById('clearMediaStatus');
    
    btn.disabled = true;
    btn.textContent = 'Clearing...';
    status.innerHTML = '<div class="muted">Processing request...</div>';
    
    fetch('clear_media.php', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json'
      }
    })
    .then(response => response.json())
    .then(data => {
      if (data.success) {
        status.innerHTML = renderOkBannerWithDbLink((data.message || 'Media tables cleared successfully!'), 'See Cleared Database');
        btn.textContent = 'Cleared Successfully';
        btn.style.background = '#28a745';
      } else {
        status.innerHTML = '<div class="alert-err">Error: ' + (data.message || 'Unknown error occurred') + '</div>';
        btn.disabled = false;
        btn.textContent = 'Clear All Media Data';
      }
    })
    .catch(error => {
      status.innerHTML = '<div class="alert-err">Network error: ' + error.message + '</div>';
      btn.disabled = false;
      btn.textContent = 'Clear All Media Data';
    });
  }

  let __restorePollTimer = null;

  function confirmRestoreDatabase() {
    const sel = document.getElementById('restore_backup_file');
    const confirmEl = document.getElementById('restore_confirm');
    const btn = document.getElementById('restoreDbBtn');
    const status = document.getElementById('restoreDbStatus');
    const logEl = document.getElementById('restoreDbLog');

    const filename = sel && sel.value ? String(sel.value).trim() : '';
    const confirmText = confirmEl && typeof confirmEl.value === 'string' ? String(confirmEl.value).trim() : '';

    if (!filename) {
      status.innerHTML = '<div class="alert-err">Please select a backup file.</div>';
      return;
    }

    if (confirmText !== 'RESTORE') {
      status.innerHTML = '<div class="alert-err">Please type RESTORE to confirm.</div>';
      return;
    }

    if (!confirm('Restore the database from:\n\n' + filename + '\n\nThis will OVERWRITE the current database. Continue?')) {
      return;
    }

    if (__restorePollTimer) {
      clearInterval(__restorePollTimer);
      __restorePollTimer = null;
    }

    btn.disabled = true;
    btn.style.background = '';
    btn.style.borderColor = '';
    btn.style.color = '';
    btn.textContent = 'Starting restore…';
    status.innerHTML = '<div class="muted">Starting restore job...</div>';
    logEl.style.display = 'block';
    logEl.textContent = '';

    fetch('/db/restore_database.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ filename, confirm: confirmText })
    })
    .then(async response => {
      const data = await response.json().catch(() => null);
      return { ok: response.ok, data };
    })
    .then(({ ok, data }) => {
      if (!(ok && data && data.success && data.job_id)) {
        const msg = (data && (data.message || data.error)) ? (data.message || data.error) : 'Unknown error occurred';
        status.innerHTML = '<div class="alert-err">Error: ' + String(msg).replace(/[&<>]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;'}[c])) + '</div>';
        btn.disabled = false;
        btn.style.background = '';
        btn.style.borderColor = '';
        btn.style.color = '';
        btn.textContent = 'Restore Database';
        return;
      }

      const jobId = String(data.job_id);
      status.innerHTML = '<div class="alert-ok">Restore started. Job: <code>' + jobId.replace(/[&<>]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;'}[c])) + '</code></div>';
      btn.textContent = 'Restore Running…';
      pollRestoreLog(jobId);
    })
    .catch(error => {
      status.innerHTML = '<div class="alert-err">Network error: ' + error.message + '</div>';
      btn.disabled = false;
      btn.style.background = '';
      btn.style.borderColor = '';
      btn.style.color = '';
      btn.textContent = 'Restore Database';
    });
  }

  function pollRestoreLog(jobId) {
    const btn = document.getElementById('restoreDbBtn');
    const status = document.getElementById('restoreDbStatus');
    const logEl = document.getElementById('restoreDbLog');

    let offset = 0;

    const tick = () => {
      fetch('/db/restore_database_status.php?job_id=' + encodeURIComponent(jobId) + '&offset=' + String(offset), {
        method: 'GET'
      })
      .then(async response => {
        const data = await response.json().catch(() => null);
        return { ok: response.ok, data };
      })
      .then(({ ok, data }) => {
        if (!(ok && data && data.success)) {
          const msg = (data && (data.message || data.error)) ? (data.message || data.error) : 'Unknown error occurred';
          status.innerHTML = '<div class="alert-err">Error reading restore status: ' + String(msg).replace(/[&<>]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;'}[c])) + '</div>';
          if (__restorePollTimer) {
            clearInterval(__restorePollTimer);
            __restorePollTimer = null;
          }
          btn.disabled = false;
          btn.textContent = 'Restore Database';
          return;
        }

        if (typeof data.log_chunk === 'string' && data.log_chunk.length) {
          logEl.textContent += data.log_chunk;
          logEl.scrollTop = logEl.scrollHeight;
        }
        if (typeof data.offset === 'number') {
          offset = data.offset;
        }

        const st = String(data.state || 'running');
        if (st === 'ok') {
          if (__restorePollTimer) {
            clearInterval(__restorePollTimer);
            __restorePollTimer = null;
          }
          status.innerHTML = renderOkBannerWithDbLink('Restore completed successfully.', 'See Restored Database');
          btn.disabled = true;
          btn.textContent = 'Database Restored!';
          btn.style.background = '#28a745';
          btn.style.borderColor = '#28a745';
          btn.style.color = 'white';
        } else if (st === 'error') {
          if (__restorePollTimer) {
            clearInterval(__restorePollTimer);
            __restorePollTimer = null;
          }
          const code = (data.exit_code !== null && data.exit_code !== undefined) ? String(data.exit_code) : '?';
          status.innerHTML = '<div class="alert-err">Restore failed. Exit code: ' + code.replace(/[&<>]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;'}[c])) + '</div>';
          btn.disabled = false;
          btn.style.background = '';
          btn.style.borderColor = '';
          btn.style.color = '';
          btn.textContent = 'Restore Database';
        }
      })
      .catch(err => {
        status.innerHTML = '<div class="alert-err">Network error: ' + err.message + '</div>';
        if (__restorePollTimer) {
          clearInterval(__restorePollTimer);
          __restorePollTimer = null;
        }
        btn.disabled = false;
        btn.style.background = '';
        btn.style.borderColor = '';
        btn.style.color = '';
        btn.textContent = 'Restore Database';
      });
    };

    tick();
    __restorePollTimer = setInterval(tick, 1500);
  }

  const __dbLinkStyle = 'display:inline-block;margin-left:10px;padding:8px 16px;background:#28a745;color:white;text-decoration:none;border-radius:4px;font-weight:bold;';

  function renderDbLinkButton(label) {
    return ' <a href="/db/database.php" target="_blank" rel="noopener noreferrer" style="' + __dbLinkStyle + '">' + String(label) + '</a>';
  }

  function renderOkBannerWithDbLink(message, linkLabel) {
    return '<div class="alert-ok">' + String(message) + renderDbLinkButton(linkLabel) + '</div>';
  }

  function confirmImportNormalized() {
    if (!confirm('Are you sure you want to upload sessions.csv + session_files.csv and reload the database?\n\nThis will permanently delete and replace ALL media data (sessions/songs/files/musicians/genres/styles).\n\nThis action CANNOT be undone!')) {
      return;
    }

    const sessionsInput = document.getElementById('normalized_sessions_csv');
    const sessionFilesInput = document.getElementById('normalized_session_files_csv');
    const btn = document.getElementById('importNormalizedBtn');
    const status = document.getElementById('importNormalizedStatus');

    if (!sessionsInput || !sessionsInput.files || !sessionsInput.files[0]) {
      status.innerHTML = '<div class="alert-err">Please select sessions.csv first.</div>';
      return;
    }
    if (!sessionFilesInput || !sessionFilesInput.files || !sessionFilesInput.files[0]) {
      status.innerHTML = '<div class="alert-err">Please select session_files.csv first.</div>';
      return;
    }

    Promise.all([
      validateNormalizedSessionsCsvHeaders(sessionsInput.files[0]),
      validateNormalizedSessionFilesCsvHeaders(sessionFilesInput.files[0])
    ]).then(([sRes, fRes]) => {
      if (!sRes.ok) {
        status.innerHTML = '<div class="alert-err">' + sRes.message.replace(/[&<>]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;'}[c])).replace(/\n/g, '<br>') + '</div>';
        return;
      }
      if (!fRes.ok) {
        status.innerHTML = '<div class="alert-err">' + fRes.message.replace(/[&<>]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;'}[c])).replace(/\n/g, '<br>') + '</div>';
        return;
      }

      const formData = new FormData();
      formData.append('sessions_csv', sessionsInput.files[0]);
      formData.append('session_files_csv', sessionFilesInput.files[0]);

      btn.disabled = true;
      btn.textContent = 'Uploading and Importing...';
      status.innerHTML = '<div class="muted">Processing request...</div>';

      fetch('import_normalized.php', {
        method: 'POST',
        body: formData
      })
      .then(async response => {
        const data = await response.json().catch(() => null);
        return { ok: response.ok, status: response.status, data };
      })
      .then(({ ok, data }) => {
        if (ok && data && data.success) {
          status.innerHTML = renderOkBannerWithDbLink((data.message || 'Database import completed successfully.'), 'See Updated Database')
            + renderImportSteps(data.steps, data.table_counts);
          btn.textContent = 'Import Completed';
          btn.disabled = false;
          btn.removeAttribute('onclick');
          btn.classList.remove('danger');
          btn.style.background = '#28a745';
          btn.style.borderColor = '#28a745';
          btn.style.color = '#ffffff';
          btn.style.pointerEvents = 'none';
          btn.style.cursor = 'default';
        } else {
          const msg = (data && (data.message || data.error)) ? (data.message || data.error) : 'Unknown error occurred';
          status.innerHTML = '<div class="alert-err">Error: ' + msg + '</div>'
            + (data && data.steps ? renderImportSteps(data.steps, data.table_counts) : '');
          btn.disabled = false;
          btn.textContent = 'Upload 2 CSVs and Reload DB';
        }
      })
      .catch(error => {
        status.innerHTML = '<div class="alert-err">Network error: ' + error.message + '</div>';
        btn.disabled = false;
        btn.textContent = 'Upload 2 CSVs and Reload DB';
      });
    });
  }

  function renderImportSteps(steps, tableCounts) {
    if (!Array.isArray(steps)) return '';
    const counts = (tableCounts && typeof tableCounts === 'object') ? tableCounts : null;
    const stepToTable = {
      'Load sessions': 'sessions',
      'Load musicians': 'musicians',
      'Load songs': 'songs',
      'Load files': 'files',
      'Load session_musicians': 'session_musicians',
      'Load session_songs': 'session_songs',
      'Load song_files': 'song_files'
    };
    let html = '<div class="muted">Progress:</div><div style="margin-top:.5rem">';
    for (const s of steps) {
      const status = s.status || 'pending';
      const name = s.name || '';
      let msg = s.message || '';
      const tableKey = counts && Object.prototype.hasOwnProperty.call(stepToTable, name) ? stepToTable[name] : null;
      if (tableKey && counts && Object.prototype.hasOwnProperty.call(counts, tableKey)) {
        const v = Number(counts[tableKey]);
        if (Number.isFinite(v) && msg) {
          msg = msg.replace(/\s*$/, '') + ': ' + v;
        }
      }
      const color = status === 'ok' ? '#22c55e' : (status === 'error' ? '#ef4444' : '#a8b3cf');
      html += '<div style="margin:.25rem 0">'
        + '<span style="display:inline-block;min-width:72px;color:' + color + '">' + status.toUpperCase() + '</span>'
        + '<span>' + name + '</span>'
        + (msg ? '<div class="muted" style="margin-left:72px;white-space:pre-wrap">' + msg.replace(/[&<>]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;'}[c])) + '</div>' : '')
        + '</div>';
    }
    html += '</div>';
    return html;
  }

  function renderTableCounts(tableCounts) {
    if (!tableCounts || typeof tableCounts !== 'object') return '';
    const order = ['sessions','musicians','songs','files','session_musicians','session_songs','song_files'];
    const rows = [];
    for (const k of order) {
      if (Object.prototype.hasOwnProperty.call(tableCounts, k)) {
        const v = tableCounts[k];
        if (Number.isFinite(Number(v))) {
          rows.push({ k, v: Number(v) });
        }
      }
    }
    if (!rows.length) return '';
    let html = '<div class="muted" style="margin-top:.75rem">Table counts:</div>';
    html += '<div style="margin-top:.25rem">';
    for (const r of rows) {
      html += '<div class="muted">' + r.k + ': ' + r.v + '</div>';
    }
    html += '</div>';
    return html;
  }

  function parseCsvHeaderLine(line) {
    const out = [];
    let cur = '';
    let inQuotes = false;
    for (let i = 0; i < line.length; i++) {
      const ch = line[i];
      if (inQuotes) {
        if (ch === '"') {
          if (i + 1 < line.length && line[i + 1] === '"') {
            cur += '"';
            i++;
          } else {
            inQuotes = false;
          }
        } else {
          cur += ch;
        }
      } else {
        if (ch === '"') {
          inQuotes = true;
        } else if (ch === ',') {
          out.push(cur.trim());
          cur = '';
        } else {
          cur += ch;
        }
      }
    }
    out.push(cur.trim());
    return out;
  }

  async function validateDatabaseCsvHeaders(file) {
    const required = [
      't_title',
      'd_date',
      'd_merged_song_lists',
      'f_singles'
    ];

    const chunk = file.slice(0, 65536);
    const text = await chunk.text();
    const firstLine = (text.split(/\r\n|\n|\r/)[0] || '').trim();
    if (!firstLine) {
      return { ok: false, message: 'CSV appears to be empty.' };
    }

    const headers = parseCsvHeaderLine(firstLine);
    const normalized = new Set(headers.map(h => String(h || '').trim()));
    const missing = required.filter(r => !normalized.has(r));

    if (missing.length) {
      return {
        ok: false,
        message: 'Missing required CSV headers: ' + missing.join(', ') + '\n\nThe uploaded CSV must include a header row.'
      };
    }

    return { ok: true };
  }

  async function validateNormalizedSessionsCsvHeaders(file) {
    const required = [
      'session_key',
      't_title',
      'd_date'
    ];

    const chunk = file.slice(0, 65536);
    const text = await chunk.text();
    const firstLine = (text.split(/\r\n|\n|\r/)[0] || '').trim();
    if (!firstLine) {
      return { ok: false, message: 'sessions.csv appears to be empty.' };
    }

    const headers = parseCsvHeaderLine(firstLine);
    const normalized = new Set(headers.map(h => String(h || '').trim()));
    const missing = required.filter(r => !normalized.has(r));

    if (missing.length) {
      return {
        ok: false,
        message: 'Missing required sessions.csv headers: ' + missing.join(', ') + '\n\nThe uploaded CSV must include a header row.'
      };
    }

    return { ok: true };
  }

  async function validateNormalizedSessionFilesCsvHeaders(file) {
    const required = [
      'session_key',
      'source_relpath'
    ];

    const chunk = file.slice(0, 65536);
    const text = await chunk.text();
    const firstLine = (text.split(/\r\n|\n|\r/)[0] || '').trim();
    if (!firstLine) {
      return { ok: false, message: 'session_files.csv appears to be empty.' };
    }

    const headers = parseCsvHeaderLine(firstLine);
    const normalized = new Set(headers.map(h => String(h || '').trim()));
    const missing = required.filter(r => !normalized.has(r));

    if (missing.length) {
      return {
        ok: false,
        message: 'Missing required session_files.csv headers: ' + missing.join(', ') + '\n\nThe uploaded CSV must include a header row.'
      };
    }

    return { ok: true };
  }

  function confirmImportDatabase() {
    if (!confirm('Are you sure you want to upload a CSV and reload the database?\n\nThis will permanently delete and replace ALL media data (sessions/songs/files/musicians/genres/styles).\n\nThis action CANNOT be undone!')) {
      return;
    }

    const fileInput = document.getElementById('database_csv');
    const btn = document.getElementById('importDbBtn');
    const status = document.getElementById('importDbStatus');

    if (!fileInput || !fileInput.files || !fileInput.files[0]) {
      status.innerHTML = '<div class="alert-err">Please select a CSV file first.</div>';
      return;
    }

    validateDatabaseCsvHeaders(fileInput.files[0]).then(result => {
      if (!result.ok) {
        status.innerHTML = '<div class="alert-err">' + result.message.replace(/[&<>]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;'}[c])).replace(/\n/g, '<br>') + '</div>';
        return;
      }

      const formData = new FormData();
      formData.append('database_csv', fileInput.files[0]);

      btn.disabled = true;
      btn.textContent = 'Uploading and Importing...';
      status.innerHTML = '<div class="muted">Processing request...</div>';

      fetch('import_database.php', {
        method: 'POST',
        body: formData
      })
      .then(async response => {
        const data = await response.json().catch(() => null);
        return { ok: response.ok, status: response.status, data };
      })
      .then(({ ok, data }) => {
        if (ok && data && data.success) {
          status.innerHTML = renderOkBannerWithDbLink((data.message || 'Database import completed successfully.'), 'See Updated Database')
            + renderImportSteps(data.steps, data.table_counts);
          btn.textContent = 'Import Completed';
          btn.disabled = false;
          btn.removeAttribute('onclick');
          btn.classList.remove('danger');
          btn.style.background = '#28a745';
          btn.style.borderColor = '#28a745';
          btn.style.color = '#ffffff';
          btn.style.pointerEvents = 'none';
          btn.style.cursor = 'default';
        } else {
          const msg = (data && (data.message || data.error)) ? (data.message || data.error) : 'Unknown error occurred';
          status.innerHTML = '<div class="alert-err">Error: ' + msg + '</div>'
            + (data && data.steps ? renderImportSteps(data.steps, data.table_counts) : '');
          btn.disabled = false;
          btn.textContent = 'Upload CSV and Reload DB';
        }
      })
      .catch(error => {
        status.innerHTML = '<div class="alert-err">Network error: ' + error.message + '</div>';
        btn.disabled = false;
        btn.textContent = 'Upload CSV and Reload DB';
      });
    });
  }

  const AUDIO_EXTS = new Set(<?php echo json_encode($__upload_audio_exts, JSON_UNESCAPED_SLASHES); ?>);
  const VIDEO_EXTS = new Set(<?php echo json_encode($__upload_video_exts, JSON_UNESCAPED_SLASHES); ?>);
  const MEDIA_EXTS = new Set([...AUDIO_EXTS, ...VIDEO_EXTS]);

  function formatDateYmd(d) {
    const y = d.getFullYear();
    const m = String(d.getMonth() + 1).padStart(2, '0');
    const day = String(d.getDate()).padStart(2, '0');
    return `${y}-${m}-${day}`;
  }

  function parseDateFromFilename(name) {
    const base = String(name || '');
    const m1 = base.match(/\b(\d{4})-(\d{2})-(\d{2})\b/);
    if (m1) {
      const d = new Date(`${m1[1]}-${m1[2]}-${m1[3]}T00:00:00`);
      if (!Number.isNaN(d.getTime())) return formatDateYmd(d);
    }
    const m2 = base.match(/\b(\d{4})(\d{2})(\d{2})\b/);
    if (m2) {
      const d = new Date(`${m2[1]}-${m2[2]}-${m2[3]}T00:00:00`);
      if (!Number.isNaN(d.getTime())) return formatDateYmd(d);
    }
    return '';
  }

  function formatBytes(n) {
    const v = Number(n) || 0;
    if (v >= 1024 * 1024 * 1024) return (v / (1024 * 1024 * 1024)).toFixed(2) + ' GB';
    if (v >= 1024 * 1024) return (v / (1024 * 1024)).toFixed(2) + ' MB';
    if (v >= 1024) return (v / 1024).toFixed(2) + ' KB';
    return v + ' B';
  }

  function formatElapsed(ms) {
    const s = Math.max(0, Math.floor((Number(ms) || 0) / 1000));
    const m = Math.floor(s / 60);
    const r = s % 60;
    return m + 'm ' + String(r).padStart(2, '0') + 's';
  }

  function setScanFolderImportUiState(state, statusHtml) {
    _scanFolderImportRunState = state;
    if (typeof statusHtml === 'string' && scanFolderStatus) {
      scanFolderStatus.innerHTML = statusHtml;
    }

    const hasFolder = !!(mediaFolderInput && mediaFolderInput.files && mediaFolderInput.files.length);
    const canRun = !!(_scanFolderState && _scanFolderState.supportedCount > 0 && _scanFolderState.sessions && _scanFolderState.sessions.length > 0);

    if (scanFolderBtn) {
      if (state === 'idle') {
        scanFolderBtn.disabled = !(hasFolder && canRun);
        scanFolderBtn.textContent = 'Scan Folder and Update DB';
        scanFolderBtn.style.pointerEvents = '';
        scanFolderBtn.style.cursor = '';
      } else {
        scanFolderBtn.disabled = true;
        scanFolderBtn.textContent = (state === 'uploading') ? 'Uploading…' : 'Hashing and Reloading...';
      }
    }

    if (stopScanFolderBtn) {
      if (state === 'hashing' || state === 'stopping') {
        stopScanFolderBtn.disabled = (state === 'stopping');
        stopScanFolderBtn.textContent = (state === 'stopping') ? 'Stopping…' : 'Stop hashing and reload DB with hashed files';
      } else {
        stopScanFolderBtn.disabled = true;
        stopScanFolderBtn.textContent = 'Stop hashing and reload DB with hashed files';
      }
    }

    if (clearScanFolderCacheBtn) {
      clearScanFolderCacheBtn.disabled = !(state === 'idle' && _scanFolderImportFolderKey && canRun);
      clearScanFolderCacheBtn.textContent = 'Clear cached hashes for this folder';
    }
  }

  function resetScanFolderImportUiIfNoSelection() {
    if (!(mediaFolderInput && mediaFolderInput.files && mediaFolderInput.files.length)) {
      _scanFolderImportCancelRequested = false;
      _scanFolderImportAbortController = null;
      _scanFolderImportRunStartedAt = 0;
      _scanFolderImportFolderKey = '';
      setScanFolderImportUiState('idle', '');
    }
  }

  window.addEventListener('pageshow', (e) => {
    if (e && e.persisted) {
      resetScanFolderImportUiIfNoSelection();
    }
  });
  window.addEventListener('load', () => {
    resetScanFolderImportUiIfNoSelection();
  });

  function setScanFolderAddUiState(state, statusHtml) {
    _scanFolderAddRunState = state;
    if (typeof statusHtml === 'string' && scanFolderAddStatus) {
      scanFolderAddStatus.innerHTML = statusHtml;
    }

    const hasFolder = !!(mediaFolderAddInput && mediaFolderAddInput.files && mediaFolderAddInput.files.length);
    const canRun = !!(_scanFolderAddState && _scanFolderAddState.supportedCount > 0);

    if (scanFolderAddBtn) {
      if (state === 'idle') {
        scanFolderAddBtn.disabled = !(hasFolder && canRun);
        scanFolderAddBtn.textContent = 'Scan Folder and Add to DB';
        scanFolderAddBtn.style.pointerEvents = '';
        scanFolderAddBtn.style.cursor = '';
      } else {
        scanFolderAddBtn.disabled = true;
        scanFolderAddBtn.textContent = (state === 'uploading') ? 'Uploading…' : 'Hashing and Adding...';
      }
    }

    if (stopScanFolderAddBtn) {
      if (state === 'hashing' || state === 'stopping') {
        stopScanFolderAddBtn.disabled = (state === 'stopping');
        stopScanFolderAddBtn.textContent = (state === 'stopping') ? 'Stopping…' : 'Stop hashing and import hashed';
      } else {
        stopScanFolderAddBtn.disabled = true;
        stopScanFolderAddBtn.textContent = 'Stop hashing and import hashed';
      }
    }

    if (clearScanFolderAddCacheBtn) {
      clearScanFolderAddCacheBtn.disabled = !(state === 'idle' && _scanFolderAddFolderKey && canRun);
      clearScanFolderAddCacheBtn.textContent = 'Clear cached hashes for this folder';
    }
  }

  function resetScanFolderAddUiIfNoSelection() {
    if (!(mediaFolderAddInput && mediaFolderAddInput.files && mediaFolderAddInput.files.length)) {
      _scanFolderAddCancelRequested = false;
      _scanFolderAddAbortController = null;
      _scanFolderAddRunStartedAt = 0;
      setScanFolderAddUiState('idle', '');
    }
  }

  window.addEventListener('pageshow', (e) => {
    if (e && e.persisted) {
      resetScanFolderAddUiIfNoSelection();
    }
  });
  window.addEventListener('load', () => {
    resetScanFolderAddUiIfNoSelection();
  });

  function parseYearFromText(name) {
    const base = String(name || '');
    const m = base.match(/\b(19\d{2}|20\d{2})\b/);
    return m ? m[1] : '';
  }

  function fileBasenameNoExt(filename) {
    const name = String(filename || '');
    const dot = name.lastIndexOf('.');
    return dot > 0 ? name.slice(0, dot) : name;
  }

  function filePathForImport(fileObj) {
    if (fileObj && typeof fileObj.webkitRelativePath === 'string' && fileObj.webkitRelativePath.trim() !== '') {
      return fileObj.webkitRelativePath;
    }
    if (fileObj && typeof fileObj.name === 'string' && fileObj.name.trim() !== '') {
      return fileObj.name;
    }
    return '';
  }

  function fileExtLower(filename) {
    const name = String(filename || '');
    const dot = name.lastIndexOf('.');
    return dot >= 0 ? name.slice(dot + 1).toLowerCase() : '';
  }

  const _relpathCollator = new Intl.Collator(undefined, { numeric: false, sensitivity: 'variant' });

  function titleFromPath(path) {
    const p = String(path || '');
    const lastSlash = p.lastIndexOf('/');
    const fname = lastSlash >= 0 ? p.slice(lastSlash + 1) : p;
    const dot = fname.lastIndexOf('.');
    return dot > 0 ? fname.slice(0, dot) : fname;
  }

  function escapeCsvField(v) {
    const s = String(v ?? '');
    if (/[\r\n",]/.test(s)) return '"' + s.replace(/"/g, '""') + '"';
    return s;
  }

  function buildSessionsFromFolderFiles(files) {
    const sessions = new Map();
    let supportedCount = 0;
    let ignoredCount = 0;
    const ignoredExtCounts = new Map();
    const supportedExtCounts = new Map();
    let totalSizeBytes = 0;
    let supportedSizeBytes = 0;
    let fallbackTimestampCount = 0;
    let epochFallbackCount = 0;
    let yearFallbackCount = 0;

    for (const f of files) {
      totalSizeBytes += (Number(f && f.size) || 0);
      const sizeBytes = (Number(f && f.size) || 0);
      if (sizeBytes === 0) {
        ignoredCount++;
        ignoredExtCounts.set('(0 bytes)', (ignoredExtCounts.get('(0 bytes)') || 0) + 1);
        continue;
      }
      const ext = fileExtLower(f.name);
      if (!MEDIA_EXTS.has(ext)) {
        ignoredCount++;
        const label = ext ? ('.' + ext) : '(noext)';
        ignoredExtCounts.set(label, (ignoredExtCounts.get(label) || 0) + 1);
        continue;
      }
      supportedCount++;
      supportedSizeBytes += sizeBytes;
      const sLabel = ext ? ('.' + ext) : '(noext)';
      supportedExtCounts.set(sLabel, (supportedExtCounts.get(sLabel) || 0) + 1);

      const pathForDate = filePathForImport(f);
      let dDate = parseDateFromFilename(pathForDate);
      if (!dDate) {
        const y = parseYearFromText(pathForDate);
        if (y) {
          dDate = `${y}-01-01`;
          yearFallbackCount++;
        }
      }
      if (!dDate) {
        const lm = Number(f.lastModified);
        if (!Number.isNaN(lm) && lm > 0) {
          dDate = formatDateYmd(new Date(lm));
          fallbackTimestampCount++;
        } else {
          dDate = '1970-01-01';
          epochFallbackCount++;
        }
      }

      const tTitle = dDate;
      const fSingles = filePathForImport(f);
      const key = dDate;
      if (!sessions.has(key)) {
        sessions.set(key, {
          t_title: tTitle,
          d_date: dDate,
          d_merged_song_lists: '',
          files: [],
          songs: [],
          items: []
        });
      }
      const s = sessions.get(key);
      s.items.push({ path: fSingles, title: titleFromPath(fSingles) });
    }

    const sorted = Array.from(sessions.values()).sort((a, b) => a.d_date.localeCompare(b.d_date));
    for (const s of sorted) {
      const byPath = new Map();
      for (const it of (s.items || [])) {
        if (!it || !it.path) continue;
        if (!byPath.has(it.path)) byPath.set(it.path, it.title || titleFromPath(it.path));
      }
      const paths = Array.from(byPath.keys()).sort();
      s.files = paths;
      s.songs = paths.map(p => byPath.get(p) || titleFromPath(p));
      s.d_merged_song_lists = s.songs.length ? s.songs.join(',') : 'local-folder-no-songlist';
      delete s.items;
    }

    return {
      sessions: sorted,
      supportedCount,
      ignoredCount,
      ignoredExtCounts: Array.from(ignoredExtCounts.entries()).map(([ext, count]) => ({ ext, count })),
      supportedExtCounts: Array.from(supportedExtCounts.entries()).map(([ext, count]) => ({ ext, count })),
      totalSizeBytes,
      supportedSizeBytes,
      fallbackTimestampCount,
      epochFallbackCount,
      yearFallbackCount
    };
  }

  function sessionsToCsv(sessions) {
    const header = ['t_title','d_date','d_merged_song_lists','f_singles'];
    const lines = [header.join(',')];
    for (const s of sessions) {
      const row = [
        escapeCsvField(s.t_title),
        escapeCsvField(s.d_date),
        escapeCsvField(s.d_merged_song_lists),
        escapeCsvField((s.files || []).join(','))
      ];
      lines.push(row.join(','));
    }
    return lines.join('\r\n') + '\r\n';
  }

  let _scanPreviewSeq = 0;

  function toggleExtMore(id, linkId) {
    const el = document.getElementById(String(id || ''));
    const link = document.getElementById(String(linkId || ''));
    if (!el || !link) return false;
    const isHidden = (el.style.display === 'none' || !el.style.display);
    el.style.display = isHidden ? 'inline' : 'none';
    link.textContent = isHidden ? 'show less' : String(link.getAttribute('data-more-label') || 'show more');
    return false;
  }

  function extCountsDetailsHtml(list, count) {
    const extList = Array.isArray(list) ? list.slice() : [];
    if ((count ?? 0) <= 0 || !extList.length) return '';
    extList.sort((a, b) => {
      const ac = Number(a && a.count) || 0;
      const bc = Number(b && b.count) || 0;
      if (bc !== ac) return bc - ac;
      return String((a && a.ext) || '').localeCompare(String((b && b.ext) || ''));
    });

    const show = extList.slice(0, 10);
    const rest = extList.slice(10);
    const parts = show.map(x => {
      const ext = String((x && x.ext) || '');
      const c = Number(x && x.count) || 0;
      const noun = (c === 1) ? 'file' : 'files';
      return ext + ': ' + c + ' ' + noun;
    });

    let html = ' (' + parts.join(', ');
    if (rest.length) {
      const more = rest.length;
      const moreLabel = '… +' + more + ' more';
      const seq = (++_scanPreviewSeq);
      const moreId = 'extMore_' + seq;
      const linkId = 'extMoreLink_' + seq;
      const restParts = rest.map(x => {
        const ext = String((x && x.ext) || '');
        const c = Number(x && x.count) || 0;
        const noun = (c === 1) ? 'file' : 'files';
        return ext + ': ' + c + ' ' + noun;
      });
      html += ', <a href="#" id="' + linkId + '" data-more-label="' + moreLabel.replace(/[&<>\"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','\"':'&quot;'}[c] || c)) + '" onclick="return toggleExtMore(\'' + moreId + '\',\'' + linkId + '\')">' + moreLabel + '</a>';
      html += '<span id="' + moreId + '" style="display:none">, ' + restParts.join(', ') + '</span>';
    }
    html += ')';
    return html;
  }

  function renderScanPreview(info) {
    const sessions = info.sessions || [];
    let html = '';
    html += '<div class="muted">Files selected: ' + (info.totalCount ?? 0) + '</div>';
    const supportedDetails = extCountsDetailsHtml(info.supportedExtCounts, info.supportedCount);
    if ((info.supportedCount ?? 0) === 0) {
      html += '<div class="muted"><strong>Supported media files: 0 <span style="margin-left:.5rem">&lt; NO FILES WILL BE UPLOADED</span></strong></div>';
    } else {
      html += '<div class="muted">Supported media files: ' + (info.supportedCount ?? 0) + supportedDetails + '</div>';
    }
    const ignoredDetails = extCountsDetailsHtml(info.ignoredExtCounts, info.ignoredCount);
    html += '<div class="muted">Ignored media files: ' + (info.ignoredCount ?? 0) + ignoredDetails + '</div>';
    html += '<div class="muted">Supported storage required: ' + formatBytes(info.supportedSizeBytes ?? 0) + '</div>';
    html += '<div class="muted" style="height:.5rem"></div>';
    if ((info.fallbackTimestampCount ?? 0) > 0) {
      html += '<div class="muted">Used file timestamp for date: ' + info.fallbackTimestampCount + '</div>';
    }
    if ((info.yearFallbackCount ?? 0) > 0) {
      html += '<div class="muted">Used year-in-path for date: ' + info.yearFallbackCount + '</div>';
    }
    if ((info.epochFallbackCount ?? 0) > 0) {
      html += '<div class="muted">Used 1970-01-01 for date: ' + info.epochFallbackCount + '</div>';
    }
    html += '<div class="muted">Sessions detected: ' + sessions.length + '</div>';

    if (sessions.length) {
      html += '<div style="margin-top:.75rem;max-height:240px;overflow:auto;border:1px solid #1d2a55;border-radius:10px;padding:.75rem;background:#0e1530">';
      html += '<div class="muted" style="margin-bottom:.5rem">Preview (first 25 sessions)</div>';
      const show = sessions.slice(0, 25);
      for (const s of show) {
        const samples = (s.files || []).slice(0, 3);
        const sampleHtml = samples.length
          ? '<div class="muted" style="margin-left:.25rem">' + samples.map(x => String(x).replace(/[&<>]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;'}[c]))).join('<br>') + '</div>'
          : '';
        html += '<div style="margin:.35rem 0">'
          + '<strong>' + String(s.d_date).replace(/[&<>]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;'}[c])) + '</strong>'
          + '<span class="muted"> — files: ' + (s.files ? s.files.length : 0) + '</span>'
          + sampleHtml
          + '</div>';
      }
      html += '</div>';
    }

    return html;
  }

  let _scanFolderState = null;
  let _scanFolderImportCancelRequested = false;
  let _scanFolderImportFolderKey = '';
  let _scanFolderImportRunState = 'idle';
  let _scanFolderImportAbortController = null;
  let _scanFolderImportRunStartedAt = 0;

  let _scanFolderAddState = null;
  let _scanFolderAddCancelRequested = false;
  let _scanFolderAddFolderKey = '';
  let _scanFolderAddRunState = 'idle';
  let _scanFolderAddAbortController = null;
  let _scanFolderAddRunStartedAt = 0;

  const mediaFolderInput = document.getElementById('media_folder');
  const scanFolderPreview = document.getElementById('scanFolderPreview');
  const scanFolderStatus = document.getElementById('scanFolderStatus');
  const scanFolderBtn = document.getElementById('scanFolderBtn');
  const stopScanFolderBtn = document.getElementById('stopScanFolderBtn');
  const clearScanFolderCacheBtn = document.getElementById('clearScanFolderCacheBtn');

  const mediaFolderAddInput = document.getElementById('media_folder_add');
  const scanFolderAddPreview = document.getElementById('scanFolderAddPreview');
  const scanFolderAddStatus = document.getElementById('scanFolderAddStatus');
  const scanFolderAddBtn = document.getElementById('scanFolderAddBtn');
  const stopScanFolderAddBtn = document.getElementById('stopScanFolderAddBtn');
  const clearScanFolderAddCacheBtn = document.getElementById('clearScanFolderAddCacheBtn');

  const HASH_CACHE_DB_NAME = 'gighive_hash_cache_v1';
  const HASH_CACHE_STORE = 'hashes';

  function openHashCacheDb() {
    return new Promise((resolve, reject) => {
      const req = indexedDB.open(HASH_CACHE_DB_NAME, 1);
      req.onupgradeneeded = () => {
        const db = req.result;
        if (!db.objectStoreNames.contains(HASH_CACHE_STORE)) {
          db.createObjectStore(HASH_CACHE_STORE, { keyPath: 'k' });
        }
      };
      req.onsuccess = () => resolve(req.result);
      req.onerror = () => reject(req.error || new Error('IndexedDB open failed'));
    });
  }

  function hashCacheKey(folderKey, relpath, sizeBytes, lastModifiedMs) {
    return String(folderKey || '') + '::' + String(relpath || '') + '::' + String(Number(sizeBytes) || 0) + '::' + String(Number(lastModifiedMs) || 0);
  }

  async function getCachedSha256(folderKey, relpath, sizeBytes, lastModifiedMs) {
    if (!('indexedDB' in window)) return null;
    const db = await openHashCacheDb();
    try {
      const k = hashCacheKey(folderKey, relpath, sizeBytes, lastModifiedMs);
      return await new Promise((resolve, reject) => {
        const tx = db.transaction(HASH_CACHE_STORE, 'readonly');
        const store = tx.objectStore(HASH_CACHE_STORE);
        const req = store.get(k);
        req.onsuccess = () => resolve(req.result ? (req.result.sha256 || null) : null);
        req.onerror = () => reject(req.error || new Error('IndexedDB get failed'));
      });
    } finally {
      db.close();
    }
  }

  async function putCachedSha256(folderKey, relpath, sizeBytes, lastModifiedMs, sha256) {
    if (!('indexedDB' in window)) return;
    const db = await openHashCacheDb();
    try {
      const k = hashCacheKey(folderKey, relpath, sizeBytes, lastModifiedMs);
      await new Promise((resolve, reject) => {
        const tx = db.transaction(HASH_CACHE_STORE, 'readwrite');
        const store = tx.objectStore(HASH_CACHE_STORE);
        const req = store.put({ k, folderKey: String(folderKey || ''), relpath: String(relpath || ''), sizeBytes: Number(sizeBytes) || 0, lastModifiedMs: Number(lastModifiedMs) || 0, sha256: String(sha256 || '') });
        req.onsuccess = () => resolve(true);
        req.onerror = () => reject(req.error || new Error('IndexedDB put failed'));
      });
    } finally {
      db.close();
    }
  }

  async function clearCachedSha256ForFolder(folderKey) {
    if (!('indexedDB' in window)) return 0;
    const db = await openHashCacheDb();
    try {
      return await new Promise((resolve, reject) => {
        let deleted = 0;
        const tx = db.transaction(HASH_CACHE_STORE, 'readwrite');
        const store = tx.objectStore(HASH_CACHE_STORE);
        const cursorReq = store.openCursor();
        cursorReq.onsuccess = (e) => {
          const cursor = e.target.result;
          if (!cursor) {
            resolve(deleted);
            return;
          }
          const v = cursor.value;
          if (v && v.folderKey === folderKey) {
            const delReq = cursor.delete();
            delReq.onsuccess = () => {
              deleted++;
              cursor.continue();
            };
            delReq.onerror = () => reject(delReq.error || new Error('IndexedDB delete failed'));
          } else {
            cursor.continue();
          }
        };
        cursorReq.onerror = () => reject(cursorReq.error || new Error('IndexedDB cursor failed'));
      });
    } finally {
      db.close();
    }
  }

  function folderKeyFromFiles(list) {
    if (!Array.isArray(list) || !list.length) return '';
    for (const f of list) {
      const rel = filePathForImport(f);
      if (rel && rel.indexOf('/') >= 0) {
        return rel.split('/')[0] || '';
      }
    }
    return '';
  }

  if (mediaFolderInput) {
    mediaFolderInput.addEventListener('change', () => {
      scanFolderStatus.innerHTML = '';
      const list = mediaFolderInput.files ? Array.from(mediaFolderInput.files) : [];
      const built = buildSessionsFromFolderFiles(list);
      _scanFolderState = {
        totalCount: list.length,
        ...built
      };
      scanFolderPreview.innerHTML = renderScanPreview(_scanFolderState);
      _scanFolderImportFolderKey = folderKeyFromFiles(list);
      setScanFolderImportUiState('idle', '');
    });
  }

  function inferFileTypeFromName(name) {
    const ext = fileExtLower(name);
    if (AUDIO_EXTS.has(ext)) return 'audio';
    if (VIDEO_EXTS.has(ext)) return 'video';
    // fallback to existing media set
    if (MEDIA_EXTS.has(ext)) return 'video';
    return '';
  }

  function deriveEventDateForFile(f) {
    const pathForDate = filePathForImport(f);
    let dDate = parseDateFromFilename(pathForDate);
    if (!dDate) {
      const y = parseYearFromText(pathForDate);
      if (y) {
        dDate = `${y}-01-01`;
      }
    }
    if (!dDate) {
      const lm = Number(f && f.lastModified);
      if (!Number.isNaN(lm) && lm > 0) {
        dDate = formatDateYmd(new Date(lm));
      } else {
        dDate = '1970-01-01';
      }
    }
    return dDate;
  }

  function bytesToHex(u8) {
    const hex = [];
    for (let i = 0; i < u8.length; i++) {
      hex.push(u8[i].toString(16).padStart(2, '0'));
    }
    return hex.join('');
  }

  async function sha256HexForFile(file) {
    const buf = await file.arrayBuffer();
    const digest = await crypto.subtle.digest('SHA-256', buf);
    return bytesToHex(new Uint8Array(digest));
  }

  function createSha256WorkerUrl() {
    const src = `
      function rotr(n, x) { return (x >>> n) | (x << (32 - n)); }
      function bytesToHex(u8) {
        const hex = [];
        for (let i = 0; i < u8.length; i++) hex.push(u8[i].toString(16).padStart(2, '0'));
        return hex.join('');
      }
      function Sha256() {
        this._h = new Uint32Array(8);
        this._h[0] = 0x6a09e667;
        this._h[1] = 0xbb67ae85;
        this._h[2] = 0x3c6ef372;
        this._h[3] = 0xa54ff53a;
        this._h[4] = 0x510e527f;
        this._h[5] = 0x9b05688c;
        this._h[6] = 0x1f83d9ab;
        this._h[7] = 0x5be0cd19;
        this._buf = new Uint8Array(64);
        this._bufLen = 0;
        this._bytesHashed = 0;
        this._w = new Uint32Array(64);
      }
      Sha256.prototype._k = new Uint32Array([
        0x428a2f98,0x71374491,0xb5c0fbcf,0xe9b5dba5,0x3956c25b,0x59f111f1,0x923f82a4,0xab1c5ed5,
        0xd807aa98,0x12835b01,0x243185be,0x550c7dc3,0x72be5d74,0x80deb1fe,0x9bdc06a7,0xc19bf174,
        0xe49b69c1,0xefbe4786,0x0fc19dc6,0x240ca1cc,0x2de92c6f,0x4a7484aa,0x5cb0a9dc,0x76f988da,
        0x983e5152,0xa831c66d,0xb00327c8,0xbf597fc7,0xc6e00bf3,0xd5a79147,0x06ca6351,0x14292967,
        0x27b70a85,0x2e1b2138,0x4d2c6dfc,0x53380d13,0x650a7354,0x766a0abb,0x81c2c92e,0x92722c85,
        0xa2bfe8a1,0xa81a664b,0xc24b8b70,0xc76c51a3,0xd192e819,0xd6990624,0xf40e3585,0x106aa070,
        0x19a4c116,0x1e376c08,0x2748774c,0x34b0bcb5,0x391c0cb3,0x4ed8aa4a,0x5b9cca4f,0x682e6ff3,
        0x748f82ee,0x78a5636f,0x84c87814,0x8cc70208,0x90befffa,0xa4506ceb,0xbef9a3f7,0xc67178f2
      ]);
      Sha256.prototype._compress = function (chunk) {
        const w = this._w;
        for (let i = 0; i < 16; i++) {
          const j = i * 4;
          w[i] = ((chunk[j] << 24) | (chunk[j + 1] << 16) | (chunk[j + 2] << 8) | (chunk[j + 3])) >>> 0;
        }
        for (let i = 16; i < 64; i++) {
          const s0 = (rotr(7, w[i - 15]) ^ rotr(18, w[i - 15]) ^ (w[i - 15] >>> 3)) >>> 0;
          const s1 = (rotr(17, w[i - 2]) ^ rotr(19, w[i - 2]) ^ (w[i - 2] >>> 10)) >>> 0;
          w[i] = (w[i - 16] + s0 + w[i - 7] + s1) >>> 0;
        }

        let a = this._h[0], b = this._h[1], c = this._h[2], d = this._h[3];
        let e = this._h[4], f = this._h[5], g = this._h[6], h = this._h[7];
        const k = this._k;

        for (let i = 0; i < 64; i++) {
          const S1 = (rotr(6, e) ^ rotr(11, e) ^ rotr(25, e)) >>> 0;
          const ch = ((e & f) ^ (~e & g)) >>> 0;
          const t1 = (h + S1 + ch + k[i] + w[i]) >>> 0;
          const S0 = (rotr(2, a) ^ rotr(13, a) ^ rotr(22, a)) >>> 0;
          const maj = ((a & b) ^ (a & c) ^ (b & c)) >>> 0;
          const t2 = (S0 + maj) >>> 0;

          h = g;
          g = f;
          f = e;
          e = (d + t1) >>> 0;
          d = c;
          c = b;
          b = a;
          a = (t1 + t2) >>> 0;
        }

        this._h[0] = (this._h[0] + a) >>> 0;
        this._h[1] = (this._h[1] + b) >>> 0;
        this._h[2] = (this._h[2] + c) >>> 0;
        this._h[3] = (this._h[3] + d) >>> 0;
        this._h[4] = (this._h[4] + e) >>> 0;
        this._h[5] = (this._h[5] + f) >>> 0;
        this._h[6] = (this._h[6] + g) >>> 0;
        this._h[7] = (this._h[7] + h) >>> 0;
      };
      Sha256.prototype.update = function (data) {
        let pos = 0;
        const len = data.length;
        this._bytesHashed += len;
        while (pos < len) {
          const take = Math.min(64 - this._bufLen, len - pos);
          this._buf.set(data.subarray(pos, pos + take), this._bufLen);
          this._bufLen += take;
          pos += take;
          if (this._bufLen === 64) {
            this._compress(this._buf);
            this._bufLen = 0;
          }
        }
      };
      Sha256.prototype.digest = function () {
        const totalBitsHi = Math.floor((this._bytesHashed / 0x20000000)) >>> 0;
        const totalBitsLo = ((this._bytesHashed << 3) >>> 0);

        this._buf[this._bufLen++] = 0x80;
        if (this._bufLen > 56) {
          while (this._bufLen < 64) this._buf[this._bufLen++] = 0;
          this._compress(this._buf);
          this._bufLen = 0;
        }
        while (this._bufLen < 56) this._buf[this._bufLen++] = 0;
        this._buf[56] = (totalBitsHi >>> 24) & 0xff;
        this._buf[57] = (totalBitsHi >>> 16) & 0xff;
        this._buf[58] = (totalBitsHi >>> 8) & 0xff;
        this._buf[59] = (totalBitsHi >>> 0) & 0xff;
        this._buf[60] = (totalBitsLo >>> 24) & 0xff;
        this._buf[61] = (totalBitsLo >>> 16) & 0xff;
        this._buf[62] = (totalBitsLo >>> 8) & 0xff;
        this._buf[63] = (totalBitsLo >>> 0) & 0xff;
        this._compress(this._buf);

        const out = new Uint8Array(32);
        for (let i = 0; i < 8; i++) {
          const v = this._h[i];
          out[i * 4 + 0] = (v >>> 24) & 0xff;
          out[i * 4 + 1] = (v >>> 16) & 0xff;
          out[i * 4 + 2] = (v >>> 8) & 0xff;
          out[i * 4 + 3] = (v >>> 0) & 0xff;
        }
        return out;
      };

      self.onmessage = async (e) => {
        try {
          const file = e.data && e.data.file;
          const chunkSize = Number(e.data && e.data.chunkSize) || (16 * 1024 * 1024);
          if (!file) throw new Error('No file provided');

          const total = Number(file.size) || 0;
          const hasher = new Sha256();
          let offset = 0;

          while (offset < total) {
            const end = Math.min(total, offset + chunkSize);
            const buf = await file.slice(offset, end).arrayBuffer();
            hasher.update(new Uint8Array(buf));
            offset = end;
            self.postMessage({ ok: true, progress: { bytes: offset, total } });
          }

          const digestBytes = hasher.digest();
          self.postMessage({ ok: true, sha256: bytesToHex(digestBytes), done: true });
        } catch (err) {
          self.postMessage({ ok: false, error: (err && err.message) ? err.message : String(err) });
        }
      };
    `;
    const blob = new Blob([src], { type: 'application/javascript' });
    return URL.createObjectURL(blob);
  }

  async function sha256HexForFileAbortable(file, signal, onProgress) {
    const workerUrl = createSha256WorkerUrl();
    const worker = new Worker(workerUrl);
    let settled = false;

    const cleanup = () => {
      if (settled) return;
      settled = true;
      try { worker.terminate(); } catch (e) {}
      try { URL.revokeObjectURL(workerUrl); } catch (e) {}
    };

    return await new Promise((resolve, reject) => {
      const onAbort = () => {
        cleanup();
        const err = new Error('Aborted');
        err.name = 'AbortError';
        reject(err);
      };

      if (signal) {
        if (signal.aborted) return onAbort();
        signal.addEventListener('abort', onAbort, { once: true });
      }

      worker.onmessage = (e) => {
        const data = e.data || {};
        if (data.ok && data.progress && data.progress.total) {
          if (typeof onProgress === 'function') {
            try { onProgress(Number(data.progress.bytes) || 0, Number(data.progress.total) || 0); } catch (err) {}
          }
          return;
        }
        cleanup();
        if (data.ok && data.sha256) resolve(String(data.sha256));
        else reject(new Error(data.error || 'Hash worker failed'));
      };
      worker.onerror = (e) => {
        cleanup();
        reject(new Error('Hash worker error'));
      };

      const size = Number(file && file.size) || 0;
      const chunkSize = size >= (512 * 1024 * 1024) ? (8 * 1024 * 1024) : (16 * 1024 * 1024);
      worker.postMessage({ file, chunkSize });
    });
  }

  if (mediaFolderAddInput) {
    mediaFolderAddInput.addEventListener('change', () => {
      scanFolderAddStatus.innerHTML = '';
      const list = mediaFolderAddInput.files ? Array.from(mediaFolderAddInput.files) : [];
      const built = buildSessionsFromFolderFiles(list);
      _scanFolderAddState = {
        totalCount: list.length,
        ...built,
        fileList: list
      };
      _scanFolderAddFolderKey = folderKeyFromFiles(list);
      scanFolderAddPreview.innerHTML = renderScanPreview(_scanFolderAddState);
      setScanFolderAddUiState('idle', '');
    });
  }

  async function clearScanFolderAddCache() {
    if (!_scanFolderAddFolderKey) {
      scanFolderAddStatus.innerHTML = '<div class="alert-err">Select a folder first.</div>';
      return;
    }
    if (!confirm('Clear cached hashes for this folder?\n\nThis will force re-hashing next time you run Section 5 for this folder.')) {
      return;
    }
    if (clearScanFolderAddCacheBtn) {
      clearScanFolderAddCacheBtn.disabled = true;
      clearScanFolderAddCacheBtn.textContent = 'Clearing…';
    }
    try {
      const deleted = await clearCachedSha256ForFolder(_scanFolderAddFolderKey);
      scanFolderAddStatus.innerHTML = '<div class="alert-ok">Cleared cached hashes for this folder. Entries removed: ' + deleted + '</div>';
    } catch (e) {
      scanFolderAddStatus.innerHTML = '<div class="alert-err">Error clearing cache: ' + String((e && e.message) ? e.message : e) + '</div>';
    } finally {
      if (clearScanFolderAddCacheBtn) {
        clearScanFolderAddCacheBtn.textContent = 'Clear cached hashes for this folder';
        clearScanFolderAddCacheBtn.disabled = !(_scanFolderAddFolderKey && _scanFolderAddState && _scanFolderAddState.supportedCount > 0);
      }
    }
  }

  function stopScanFolderAdd() {
    _scanFolderAddCancelRequested = true;
    if (_scanFolderAddAbortController) {
      try { _scanFolderAddAbortController.abort(); } catch (e) {}
    }
    setScanFolderAddUiState('stopping', '<div class="muted">Stop requested. Importing whatever has been hashed so far…</div>');
  }

  async function clearScanFolderImportCache() {
    if (!_scanFolderImportFolderKey) {
      scanFolderStatus.innerHTML = '<div class="alert-err">Select a folder first.</div>';
      return;
    }
    if (!confirm('Clear cached hashes for this folder?\n\nThis will force re-hashing next time you run Section 4 for this folder.')) {
      return;
    }
    if (clearScanFolderCacheBtn) {
      clearScanFolderCacheBtn.disabled = true;
      clearScanFolderCacheBtn.textContent = 'Clearing…';
    }
    try {
      const deleted = await clearCachedSha256ForFolder(_scanFolderImportFolderKey);
      scanFolderStatus.innerHTML = '<div class="alert-ok">Cleared ' + String(deleted) + ' cached hash entr' + (deleted === 1 ? 'y' : 'ies') + ' for this folder.</div>';
    } catch (e) {
      scanFolderStatus.innerHTML = '<div class="alert-err">Error clearing cache: ' + String((e && e.message) ? e.message : e) + '</div>';
    } finally {
      if (clearScanFolderCacheBtn) {
        clearScanFolderCacheBtn.textContent = 'Clear cached hashes for this folder';
        clearScanFolderCacheBtn.disabled = !(_scanFolderImportFolderKey && _scanFolderState && _scanFolderState.supportedCount > 0);
      }
    }
  }

  function stopScanFolderImport() {
    _scanFolderImportCancelRequested = true;
    if (_scanFolderImportAbortController) {
      try { _scanFolderImportAbortController.abort(); } catch (e) {}
    }
    setScanFolderImportUiState('stopping', '<div class="muted">Stop requested. Reloading DB with whatever has been hashed so far…</div>');
  }

  async function confirmScanFolderImport() {
    if (!(mediaFolderInput && mediaFolderInput.files && mediaFolderInput.files.length)) {
      scanFolderStatus.innerHTML = '<div class="alert-err">Please select a folder with supported media files first.</div>';
      return;
    }

    if (!confirm('Are you sure you want to scan this folder and reload the database?\n\nThis will permanently delete and replace ALL media data (sessions/songs/files/musicians/genres/styles).\n\nHashing (SHA-256) is required for a safe reload and may take time for large folders.\n\nThis action CANNOT be undone!')) {
      return;
    }

    const files = mediaFolderInput.files ? Array.from(mediaFolderInput.files) : [];
    const supported = [];
    for (const f of files) {
      const sizeBytes = Number(f && f.size) || 0;
      if (sizeBytes === 0) continue;
      const ext = fileExtLower(f.name);
      if (!MEDIA_EXTS.has(ext)) continue;
      const fileType = inferFileTypeFromName(f.name);
      if (!fileType) continue;
      supported.push({ file: f, fileType, relpath: filePathForImport(f) });
    }

    supported.sort((a, b) => _relpathCollator.compare(String(a.relpath || ''), String(b.relpath || '')));

    if (!supported.length) {
      scanFolderStatus.innerHTML = '<div class="alert-err">No supported media files found in selected folder.</div>';
      return;
    }

    _scanFolderImportCancelRequested = false;
    _scanFolderImportAbortController = new AbortController();
    _scanFolderImportRunStartedAt = Date.now();

    const folderKey = folderKeyFromFiles(files);
    _scanFolderImportFolderKey = folderKey;
    setScanFolderImportUiState('hashing', '<div class="muted">Starting hashing…</div>');

    const items = [];
    let cachedCount = 0;
    let hashedCount = 0;
    let hashedBytes = 0;
    let lastUiProgressAt = 0;
    const totalBytesAll = supported.reduce((sum, x) => sum + (Number(x && x.file && x.file.size) || 0), 0);
    let bytesDonePrevFiles = 0;
    let currentFileBytesDone = 0;
    const runStartedAt = _scanFolderImportRunStartedAt;
    let _etaSmoothedBytesPerMs = 0;

    for (let i = 0; i < supported.length; i++) {
      const { file, fileType, relpath } = supported[i];
      if (_scanFolderImportCancelRequested) {
        break;
      }
      const eventDate = deriveEventDateForFile(file);
      const sizeBytes = Number(file.size) || 0;
      const lastMod = Number(file.lastModified) || 0;
      currentFileBytesDone = 0;

      let checksum = null;
      if (folderKey) {
        checksum = await getCachedSha256(folderKey, relpath, sizeBytes, lastMod);
      }
      if (checksum) {
        cachedCount++;
        bytesDonePrevFiles += sizeBytes;
      } else {
        const elapsed = formatElapsed(Date.now() - runStartedAt);
        scanFolderStatus.innerHTML = '<div class="muted">Hashing ' + (i + 1) + ' / ' + supported.length + '… (cached: ' + cachedCount + ', hashed: ' + hashedCount + ', size of hashed: ' + formatBytes(hashedBytes) + ', elapsed: ' + elapsed + ')</div>'
          + '<div class="muted" style="margin-top:.25rem">Current file: ' + String(relpath || file.name).replace(/[&<>]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;'}[c])) + ' (' + formatBytes(sizeBytes) + ')</div>';

        try {
          checksum = await sha256HexForFileAbortable(file, _scanFolderImportAbortController.signal, (bytesDone, bytesTotal) => {
          const now = Date.now();
          if (now - lastUiProgressAt < 250) return;
          lastUiProgressAt = now;

          const pct = (bytesTotal > 0) ? Math.min(100, Math.floor((bytesDone / bytesTotal) * 100)) : 0;
          currentFileBytesDone = Number(bytesDone) || 0;
          const elapsedMs = now - runStartedAt;
          const elapsed2 = formatElapsed(elapsedMs);

          const bytesDoneSoFar = bytesDonePrevFiles + currentFileBytesDone;
          let etaText = 'ETA: …';
          if (elapsedMs > 10000 && bytesDoneSoFar > (64 * 1024 * 1024) && totalBytesAll > 0) {
            const instantBytesPerMs = bytesDoneSoFar / Math.max(1, elapsedMs);
            _etaSmoothedBytesPerMs = _etaSmoothedBytesPerMs > 0
              ? (_etaSmoothedBytesPerMs * 0.85 + instantBytesPerMs * 0.15)
              : instantBytesPerMs;
            const remainingBytes = Math.max(0, totalBytesAll - bytesDoneSoFar);
            const etaMs = _etaSmoothedBytesPerMs > 0 ? (remainingBytes / _etaSmoothedBytesPerMs) : 0;
            etaText = 'ETA: ' + formatElapsed(etaMs);
          }

          scanFolderStatus.innerHTML = '<div class="muted">Hashing ' + (i + 1) + ' / ' + supported.length + '… (cached: ' + cachedCount + ', hashed: ' + hashedCount + ', size of hashed: ' + formatBytes(hashedBytes) + ', elapsed: ' + elapsed2 + ', ' + etaText + ')</div>'
            + '<div class="muted" style="margin-top:.25rem">Current file: ' + String(relpath || file.name).replace(/[&<>]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;'}[c])) + ' (' + formatBytes(sizeBytes) + ')</div>'
            + '<div class="muted" style="margin-top:.25rem">File progress: ' + pct + '% (' + formatBytes(bytesDone) + ' / ' + formatBytes(bytesTotal) + ')</div>';
          });
        } catch (e) {
          if (_scanFolderImportCancelRequested) {
            break;
          }
          throw e;
        }

        hashedCount++;
        hashedBytes += sizeBytes;
        bytesDonePrevFiles += sizeBytes;
        if (folderKey && checksum) {
          try { await putCachedSha256(folderKey, relpath, sizeBytes, lastMod, checksum); } catch (e) { /* ignore cache write failures */ }
        }
      }

      items.push({
        file_name: file.name,
        source_relpath: relpath,
        file_type: fileType,
        event_date: eventDate,
        size_bytes: Number(file.size) || 0,
        checksum_sha256: checksum
      });
    }

    if (!items.length) {
      setScanFolderImportUiState('idle', '<div class="alert-err">No files were hashed. Nothing to reload.</div>');
      return;
    }

    setScanFolderImportUiState('uploading', '<div class="muted">Uploading manifest to server…</div>');

    fetch('import_manifest_reload.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        org_name: 'default',
        event_type: 'band',
        items
      })
    })
    .then(async response => {
      const data = await response.json().catch(() => null);
      return { ok: response.ok, status: response.status, data };
    })
    .then(({ ok, data }) => {
      if (ok && data && data.success) {
        scanFolderStatus.innerHTML = renderOkBannerWithDbLink((data.message || 'Database reload completed successfully.'), 'See Updated Database')
          + (data.steps ? renderImportSteps(data.steps, data.table_counts) : '');
        scanFolderBtn.textContent = 'Import Completed';
        scanFolderBtn.disabled = false;
        scanFolderBtn.removeAttribute('onclick');
        scanFolderBtn.classList.remove('danger');
        scanFolderBtn.style.background = '#28a745';
        scanFolderBtn.style.borderColor = '#28a745';
        scanFolderBtn.style.color = '#ffffff';
        scanFolderBtn.style.pointerEvents = 'none';
        scanFolderBtn.style.cursor = 'default';

        if (stopScanFolderBtn) {
          stopScanFolderBtn.disabled = true;
          stopScanFolderBtn.textContent = 'Stop hashing and reload DB with hashed files';
        }
        if (clearScanFolderCacheBtn) {
          clearScanFolderCacheBtn.disabled = false;
          clearScanFolderCacheBtn.textContent = 'Clear cached hashes for this folder';
        }
      } else {
        const msg = (data && (data.message || data.error)) ? (data.message || data.error) : 'Unknown error occurred';
        scanFolderStatus.innerHTML = '<div class="alert-err">Error: ' + msg + '</div>'
          + (data && data.steps ? renderImportSteps(data.steps, data.table_counts) : '');
        setScanFolderImportUiState('idle', scanFolderStatus.innerHTML);
      }
    })
    .catch(error => {
      scanFolderStatus.innerHTML = '<div class="alert-err">Network error: ' + error.message + '</div>';
      setScanFolderImportUiState('idle', scanFolderStatus.innerHTML);
    });
  }

  async function confirmScanFolderAdd() {
    if (!(mediaFolderAddInput && mediaFolderAddInput.files && mediaFolderAddInput.files.length)) {
      scanFolderAddStatus.innerHTML = '<div class="alert-err">Please select a folder with supported media files first.</div>';
      return;
    }

    if (!confirm('Are you sure you want to scan this folder and add items to the database?\n\nThis will NOT truncate existing media tables.\n\nHashing (SHA-256) is required for idempotency and may take time for large folders.')) {
      return;
    }

    const files = mediaFolderAddInput.files ? Array.from(mediaFolderAddInput.files) : [];
    const supported = [];
    for (const f of files) {
      const sizeBytes = Number(f && f.size) || 0;
      if (sizeBytes === 0) continue;
      const ext = fileExtLower(f.name);
      if (!MEDIA_EXTS.has(ext)) continue;
      const fileType = inferFileTypeFromName(f.name);
      if (!fileType) continue;
      supported.push({ file: f, fileType, relpath: filePathForImport(f) });
    }

    supported.sort((a, b) => _relpathCollator.compare(String(a.relpath || ''), String(b.relpath || '')));

    if (!supported.length) {
      scanFolderAddStatus.innerHTML = '<div class="alert-err">No supported media files found in selected folder.</div>';
      return;
    }

    _scanFolderAddCancelRequested = false;
    _scanFolderAddAbortController = new AbortController();
    _scanFolderAddRunStartedAt = Date.now();
    setScanFolderAddUiState('hashing', '<div class="muted">Starting hashing…</div>');

    const items = [];
    let cachedCount = 0;
    let hashedCount = 0;
    let hashedBytes = 0;
    let lastUiProgressAt = 0;
    const totalBytesAll = supported.reduce((sum, x) => sum + (Number(x && x.file && x.file.size) || 0), 0);
    let bytesDonePrevFiles = 0;
    let currentFileBytesDone = 0;
    let _etaSmoothedBytesPerMs = 0;
    for (let i = 0; i < supported.length; i++) {
      const { file, fileType, relpath } = supported[i];
      if (_scanFolderAddCancelRequested) {
        break;
      }
      const eventDate = deriveEventDateForFile(file);
      const sizeBytes = Number(file.size) || 0;
      const lastMod = Number(file.lastModified) || 0;
      currentFileBytesDone = 0;

      let checksum = null;
      if (_scanFolderAddFolderKey) {
        checksum = await getCachedSha256(_scanFolderAddFolderKey, relpath, sizeBytes, lastMod);
      }
      if (checksum) {
        cachedCount++;
        bytesDonePrevFiles += sizeBytes;
      } else {
        const elapsed = formatElapsed(Date.now() - _scanFolderAddRunStartedAt);
        scanFolderAddStatus.innerHTML = '<div class="muted">Hashing ' + (i + 1) + ' / ' + supported.length + '… (cached: ' + cachedCount + ', hashed: ' + hashedCount + ', size of hashed: ' + formatBytes(hashedBytes) + ', elapsed: ' + elapsed + ')</div>'
          + '<div class="muted" style="margin-top:.25rem">Current file: ' + String(relpath || file.name).replace(/[&<>]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;'}[c])) + ' (' + formatBytes(sizeBytes) + ')</div>';
        try {
          checksum = await sha256HexForFileAbortable(file, _scanFolderAddAbortController.signal, (bytesDone, bytesTotal) => {
            const now = Date.now();
            if (now - lastUiProgressAt < 250) return;
            lastUiProgressAt = now;
            const pct = (bytesTotal > 0) ? Math.min(100, Math.floor((bytesDone / bytesTotal) * 100)) : 0;
            currentFileBytesDone = Number(bytesDone) || 0;
            const elapsedMs = now - _scanFolderAddRunStartedAt;
            const elapsed2 = formatElapsed(elapsedMs);

            const bytesDoneSoFar = bytesDonePrevFiles + currentFileBytesDone;
            let etaText = 'ETA: …';
            if (elapsedMs > 10000 && bytesDoneSoFar > (64 * 1024 * 1024) && totalBytesAll > 0) {
              const instantBytesPerMs = bytesDoneSoFar / Math.max(1, elapsedMs);
              _etaSmoothedBytesPerMs = _etaSmoothedBytesPerMs > 0
                ? (_etaSmoothedBytesPerMs * 0.85 + instantBytesPerMs * 0.15)
                : instantBytesPerMs;
              const remainingBytes = Math.max(0, totalBytesAll - bytesDoneSoFar);
              const etaMs = _etaSmoothedBytesPerMs > 0 ? (remainingBytes / _etaSmoothedBytesPerMs) : 0;
              etaText = 'ETA: ' + formatElapsed(etaMs);
            }

            scanFolderAddStatus.innerHTML = '<div class="muted">Hashing ' + (i + 1) + ' / ' + supported.length + '… (cached: ' + cachedCount + ', hashed: ' + hashedCount + ', size of hashed: ' + formatBytes(hashedBytes) + ', elapsed: ' + elapsed2 + ', ' + etaText + ')</div>'
              + '<div class="muted" style="margin-top:.25rem">Current file: ' + String(relpath || file.name).replace(/[&<>]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;'}[c])) + ' (' + formatBytes(sizeBytes) + ')</div>'
              + '<div class="muted" style="margin-top:.25rem">File progress: ' + pct + '% (' + formatBytes(bytesDone) + ' / ' + formatBytes(bytesTotal) + ')</div>';
          });
          hashedCount++;
          hashedBytes += sizeBytes;
        } catch (e) {
          if (e && e.name === 'AbortError') {
            _scanFolderAddCancelRequested = true;
            break;
          }
          throw e;
        }
        bytesDonePrevFiles += sizeBytes;
        if (_scanFolderAddFolderKey && checksum) {
          try { await putCachedSha256(_scanFolderAddFolderKey, relpath, sizeBytes, lastMod, checksum); } catch (e) { /* ignore cache write failures */ }
        }
      }
      items.push({
        file_name: file.name,
        source_relpath: relpath,
        file_type: fileType,
        event_date: eventDate,
        size_bytes: Number(file.size) || 0,
        checksum_sha256: checksum
      });
    }

    if (_scanFolderAddCancelRequested) {
      setScanFolderAddUiState('uploading', '<div class="muted">Stopped. Uploading ' + items.length + ' hashed item(s) to server…</div>');
    } else {
      setScanFolderAddUiState('uploading', '<div class="muted">Uploading manifest to server…</div>');
    }

    if (!items.length) {
      scanFolderAddStatus.innerHTML = '<div class="alert-err">Stopped before any files finished hashing. Nothing to import.</div>';
      setScanFolderAddUiState('idle', scanFolderAddStatus.innerHTML);
      return;
    }

    fetch('import_manifest_add.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        org_name: 'default',
        event_type: 'band',
        items
      })
    })
    .then(async response => {
      const data = await response.json().catch(() => null);
      return { ok: response.ok, status: response.status, data };
    })
    .then(({ ok, data }) => {
      if (ok && data && data.success) {
        scanFolderAddStatus.innerHTML = renderOkBannerWithDbLink((data.message || 'Add-to-database completed successfully.'), 'See Updated Database')
          + renderAddReport(data)
          + (data.steps ? renderImportSteps(data.steps, data.table_counts) : '');

        _scanFolderAddRunState = 'idle';
        _scanFolderAddCancelRequested = false;
        _scanFolderAddAbortController = null;

        scanFolderAddBtn.textContent = 'Add Completed';
        scanFolderAddBtn.classList.remove('danger');
        scanFolderAddBtn.style.background = '#28a745';
        scanFolderAddBtn.style.borderColor = '#28a745';
        scanFolderAddBtn.style.color = '#ffffff';
        scanFolderAddBtn.style.pointerEvents = 'none';
        scanFolderAddBtn.style.cursor = 'default';

        if (stopScanFolderAddBtn) {
          stopScanFolderAddBtn.disabled = true;
          stopScanFolderAddBtn.textContent = 'Stop hashing and import hashed';
        }
        if (clearScanFolderAddCacheBtn) {
          clearScanFolderAddCacheBtn.disabled = false;
          clearScanFolderAddCacheBtn.textContent = 'Clear cached hashes for this folder';
        }
      } else {
        const msg = (data && (data.message || data.error)) ? (data.message || data.error) : 'Unknown error occurred';
        scanFolderAddStatus.innerHTML = '<div class="alert-err">Error: ' + msg + '</div>'
          + (data && data.steps ? renderImportSteps(data.steps, data.table_counts) : '');
        setScanFolderAddUiState('idle', scanFolderAddStatus.innerHTML);
      }
    })
    .catch(error => {
      scanFolderAddStatus.innerHTML = '<div class="alert-err">Network error: ' + error.message + '</div>';
      setScanFolderAddUiState('idle', scanFolderAddStatus.innerHTML);
    });
  }

  function renderAddReport(data) {
    const inserted = Number(data && data.inserted_count);
    const dupes = Number(data && data.duplicate_count);
    const okInserted = Number.isFinite(inserted) ? inserted : 0;
    const okDupes = Number.isFinite(dupes) ? dupes : 0;
    let html = '<div class="muted" style="margin-top:.75rem">Add-to-DB summary:</div>';
    html += '<div class="muted">Inserted: ' + okInserted + '</div>';
    html += '<div class="muted">Duplicates skipped: ' + okDupes + '</div>';
    if (Array.isArray(data && data.duplicates) && data.duplicates.length) {
      html += '<div class="muted" style="margin-top:.5rem">Sample duplicates (first ' + data.duplicates.length + '):</div>';
      html += '<div style="margin-top:.25rem;max-height:180px;overflow:auto;border:1px solid #1d2a55;border-radius:10px;padding:.75rem;background:#0e1530">';
      for (const d of data.duplicates) {
        const rel = (d && d.source_relpath) ? String(d.source_relpath) : '';
        const nm = (d && d.file_name) ? String(d.file_name) : '';
        html += '<div class="muted">' + (rel || nm).replace(/[&<>]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;'}[c])) + '</div>';
      }
      html += '</div>';
    }
    return html;
  }

  async function confirmScanFolderAdd() {
    if (!_scanFolderAddState || !_scanFolderAddState.fileList || !_scanFolderAddState.fileList.length) {
      scanFolderAddStatus.innerHTML = '<div class="alert-err">Please select a folder with supported media files first.</div>';
      return;
    }

    if (!confirm('Are you sure you want to scan this folder and add items to the database?\n\nThis will NOT truncate existing media tables.\n\nHashing (SHA-256) is required for idempotency and may take time for large folders.')) {
      return;
    }

    const files = _scanFolderAddState.fileList;
    const supported = [];
    for (const f of files) {
      const sizeBytes = Number(f && f.size) || 0;
      if (sizeBytes === 0) continue;
      const ext = fileExtLower(f.name);
      if (!MEDIA_EXTS.has(ext)) continue;
      const fileType = inferFileTypeFromName(f.name);
      if (!fileType) continue;
      supported.push({ file: f, fileType, relpath: filePathForImport(f) });
    }

    supported.sort((a, b) => _relpathCollator.compare(String(a.relpath || ''), String(b.relpath || '')));

    if (!supported.length) {
      scanFolderAddStatus.innerHTML = '<div class="alert-err">No supported media files found in selected folder.</div>';
      return;
    }

    _scanFolderAddCancelRequested = false;
    _scanFolderAddAbortController = new AbortController();
    _scanFolderAddRunStartedAt = Date.now();
    setScanFolderAddUiState('hashing', '<div class="muted">Starting hashing…</div>');

    const items = [];
    let cachedCount = 0;
    let hashedCount = 0;
    let hashedBytes = 0;
    let lastUiProgressAt = 0;
    const totalBytesAll = supported.reduce((sum, x) => sum + (Number(x && x.file && x.file.size) || 0), 0);
    let bytesDonePrevFiles = 0;
    let currentFileBytesDone = 0;
    let _etaSmoothedBytesPerMs = 0;
    for (let i = 0; i < supported.length; i++) {
      const { file, fileType, relpath } = supported[i];
      if (_scanFolderAddCancelRequested) {
        break;
      }
      const eventDate = deriveEventDateForFile(file);
      const sizeBytes = Number(file.size) || 0;
      const lastMod = Number(file.lastModified) || 0;
      currentFileBytesDone = 0;

      let checksum = null;
      if (_scanFolderAddFolderKey) {
        checksum = await getCachedSha256(_scanFolderAddFolderKey, relpath, sizeBytes, lastMod);
      }
      if (checksum) {
        cachedCount++;
        bytesDonePrevFiles += sizeBytes;
      } else {
        const elapsed = formatElapsed(Date.now() - _scanFolderAddRunStartedAt);
        scanFolderAddStatus.innerHTML = '<div class="muted">Hashing ' + (i + 1) + ' / ' + supported.length + '… (cached: ' + cachedCount + ', hashed: ' + hashedCount + ', size of hashed: ' + formatBytes(hashedBytes) + ', elapsed: ' + elapsed + ')</div>'
          + '<div class="muted" style="margin-top:.25rem">Current file: ' + String(relpath || file.name).replace(/[&<>]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;'}[c])) + ' (' + formatBytes(sizeBytes) + ')</div>';
        try {
          checksum = await sha256HexForFileAbortable(file, _scanFolderAddAbortController.signal, (bytesDone, bytesTotal) => {
            const now = Date.now();
            if (now - lastUiProgressAt < 250) return;
            lastUiProgressAt = now;
            const pct = (bytesTotal > 0) ? Math.min(100, Math.floor((bytesDone / bytesTotal) * 100)) : 0;
            currentFileBytesDone = Number(bytesDone) || 0;
            const elapsedMs = now - _scanFolderAddRunStartedAt;
            const elapsed2 = formatElapsed(elapsedMs);

            const bytesDoneSoFar = bytesDonePrevFiles + currentFileBytesDone;
            let etaText = 'ETA: …';
            if (elapsedMs > 10000 && bytesDoneSoFar > (64 * 1024 * 1024) && totalBytesAll > 0) {
              const instantBytesPerMs = bytesDoneSoFar / Math.max(1, elapsedMs);
              _etaSmoothedBytesPerMs = _etaSmoothedBytesPerMs > 0
                ? (_etaSmoothedBytesPerMs * 0.85 + instantBytesPerMs * 0.15)
                : instantBytesPerMs;
              const remainingBytes = Math.max(0, totalBytesAll - bytesDoneSoFar);
              const etaMs = _etaSmoothedBytesPerMs > 0 ? (remainingBytes / _etaSmoothedBytesPerMs) : 0;
              etaText = 'ETA: ' + formatElapsed(etaMs);
            }

            scanFolderAddStatus.innerHTML = '<div class="muted">Hashing ' + (i + 1) + ' / ' + supported.length + '… (cached: ' + cachedCount + ', hashed: ' + hashedCount + ', size of hashed: ' + formatBytes(hashedBytes) + ', elapsed: ' + elapsed2 + ', ' + etaText + ')</div>'
              + '<div class="muted" style="margin-top:.25rem">Current file: ' + String(relpath || file.name).replace(/[&<>]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;'}[c])) + ' (' + formatBytes(sizeBytes) + ')</div>'
              + '<div class="muted" style="margin-top:.25rem">File progress: ' + pct + '% (' + formatBytes(bytesDone) + ' / ' + formatBytes(bytesTotal) + ')</div>';
          });
          hashedCount++;
          hashedBytes += sizeBytes;
        } catch (e) {
          if (e && e.name === 'AbortError') {
            _scanFolderAddCancelRequested = true;
            break;
          }
          throw e;
        }
        bytesDonePrevFiles += sizeBytes;
        if (_scanFolderAddFolderKey && checksum) {
          try { await putCachedSha256(_scanFolderAddFolderKey, relpath, sizeBytes, lastMod, checksum); } catch (e) { /* ignore cache write failures */ }
        }
      }
      items.push({
        file_name: file.name,
        source_relpath: relpath,
        file_type: fileType,
        event_date: eventDate,
        size_bytes: Number(file.size) || 0,
        checksum_sha256: checksum
      });
    }

    if (_scanFolderAddCancelRequested) {
      setScanFolderAddUiState('uploading', '<div class="muted">Stopped. Uploading ' + items.length + ' hashed item(s) to server…</div>');
    } else {
      setScanFolderAddUiState('uploading', '<div class="muted">Uploading manifest to server…</div>');
    }

    if (!items.length) {
      scanFolderAddStatus.innerHTML = '<div class="alert-err">Stopped before any files finished hashing. Nothing to import.</div>';
      setScanFolderAddUiState('idle', scanFolderAddStatus.innerHTML);
      return;
    }

    fetch('import_manifest_add.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        org_name: 'default',
        event_type: 'band',
        items
      })
    })
    .then(async response => {
      const data = await response.json().catch(() => null);
      return { ok: response.ok, status: response.status, data };
    })
    .then(({ ok, data }) => {
      if (ok && data && data.success) {
        scanFolderAddStatus.innerHTML = renderOkBannerWithDbLink((data.message || 'Add-to-database completed successfully.'), 'See Updated Database')
          + renderAddReport(data)
          + (data.steps ? renderImportSteps(data.steps, data.table_counts) : '');

        _scanFolderAddRunState = 'idle';
        _scanFolderAddCancelRequested = false;
        _scanFolderAddAbortController = null;

        scanFolderAddBtn.textContent = 'Add Completed';
        scanFolderAddBtn.classList.remove('danger');
        scanFolderAddBtn.style.background = '#28a745';
        scanFolderAddBtn.style.borderColor = '#28a745';
        scanFolderAddBtn.style.color = '#ffffff';
        scanFolderAddBtn.style.pointerEvents = 'none';
        scanFolderAddBtn.style.cursor = 'default';

        if (stopScanFolderAddBtn) {
          stopScanFolderAddBtn.disabled = true;
          stopScanFolderAddBtn.textContent = 'Stop hashing and import hashed';
        }
        if (clearScanFolderAddCacheBtn) {
          clearScanFolderAddCacheBtn.disabled = false;
          clearScanFolderAddCacheBtn.textContent = 'Clear cached hashes for this folder';
        }
      } else {
        const msg = (data && (data.message || data.error)) ? (data.message || data.error) : 'Unknown error occurred';
        scanFolderAddStatus.innerHTML = '<div class="alert-err">Error: ' + msg + '</div>'
          + (data && data.steps ? renderImportSteps(data.steps, data.table_counts) : '');
        setScanFolderAddUiState('idle', scanFolderAddStatus.innerHTML);
      }
    })
    .catch(error => {
      scanFolderAddStatus.innerHTML = '<div class="alert-err">Network error: ' + error.message + '</div>';
      setScanFolderAddUiState('idle', scanFolderAddStatus.innerHTML);
    });
  }
  </script>
</body>
</html>

