<?php
$user = $_SERVER['PHP_AUTH_USER']
    ?? $_SERVER['REMOTE_USER']
    ?? $_SERVER['REDIRECT_REMOTE_USER']
    ?? null;

if ($user !== 'admin') {
    http_response_code(403);
    echo "<h1>Forbidden</h1><p>Admin access required.</p>";
    exit;
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
  <title>Admin: System & Recovery</title>
  <style>
    :root { font-family: system-ui, -apple-system, Segoe UI, Roboto, sans-serif; }
    body { margin:0; background:#0b1020; color:#e9eef7; }
    .wrap { max-width: 880px; margin: 3rem auto; padding: 1rem; }
    .card { background:#121a33; border:1px solid #1d2a55; border-radius:16px; padding:1.5rem; position:relative; }
    .row { display:grid; gap:.5rem; margin-bottom:1rem; }
    label { font-weight:600; }
    input[type=text], input[type=number], select { width:100%; padding:.7rem; border-radius:10px; border:1px solid #33427a; background:#0e1530; color:#e9eef7; }
    button { padding:.8rem 1.1rem; border-radius:10px; border:1px solid #3b82f6; background:transparent; color:#e9eef7; cursor:pointer; }
    button:hover { background:#1e40af; color:#fff; }
    button.danger { border-color:#dc2626; }
    button.danger:hover { background:#991b1b; }
    button:disabled { cursor:not-allowed; opacity:1; }
    button.danger:disabled { border-color:#dc2626; color:#a8b3cf; background:transparent; }
    .section-divider { border-top:2px solid #1d2a55; margin:2rem 0; padding-top:2rem; }
    .warning-box { background:#3b1f0d; border:1px solid #b45309; padding:1rem; border-radius:10px; margin-bottom:1rem; }
    .alert-ok { background:#11331a; border:1px solid #1f7a3b; padding:.8rem 1rem; border-radius:10px; margin-bottom:1rem; }
    .alert-err { background:#3b0d14; border:1px solid #b4232a; padding:.8rem 1rem; border-radius:10px; margin-bottom:1rem; }
    .muted { color:#a8b3cf; font-size:.95rem; }
  </style>
</head>
<body>
  <div class="wrap">
    <div class="card">
      <div style="position:absolute;top:1.5rem;right:1.5rem;display:flex;flex-direction:column;gap:.4rem;align-items:flex-end">
        <a href="/admin/admin.php"><button type="button" style="border-color:#3b82f6;font-size:.8rem;padding:.4rem .8rem">Password Reset</button></a>
        <a href="/admin/admin_database_load_import_media_from_folder.php"><button type="button" style="border-color:#3b82f6;font-size:.8rem;padding:.4rem .8rem">Import Media Folder</button></a>
        <a href="/admin/admin_database_load_import.php"><button type="button" style="border-color:#3b82f6;font-size:.8rem;padding:.4rem .8rem">CSV Import</button></a>
      </div>
      <h1 style="padding-right:210px">Admin: System & Recovery</h1>
      <p class="muted">Signed in as <code><?= htmlspecialchars($user) ?></code>.</p>

      <div class="section-divider">
        <h2>Section B: Clear Database</h2>
        <p class="muted">
          Remove all content (sessions, songs, files, musicians) from the database.
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
        <h2>Section C: Delete All Media Files from Disk</h2>
        <p class="muted">
          Permanently deletes all audio, video, and thumbnail files stored on the server.
          The database is <strong>not</strong> affected — run Section B first if you also want to clear database records.
        </p>
        <div class="warning-box">
          <strong>⚠️ Warning:</strong> This deletes actual files from disk. Unlike the database,
          files cannot be recovered from a database backup. Ensure <strong>no uploads are currently in progress</strong>
          before proceeding.
        </div>
        <div id="clearMediaFilesStatus"></div>
        <button type="button" id="clearMediaFilesBtn" class="danger" onclick="confirmClearMediaFiles()">Delete All Media Files</button>
      </div>

      <div class="section-divider">
        <h2>Section D: Write Disk Resize Request (Optional)</h2>
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
        <h2>Section E: Restore Database From Backup (Destructive)</h2>
        <p class="muted">
          A full database backup is created daily by the server. Use this section to restore the entire database if something goes wrong.
        </p>
        <div class="warning-box">
          <strong>⚠️ Warning:</strong> This will overwrite the current database with the selected backup.
        </div>

        <div class="row">
          <label for="restore_backup_file">Select a backup</label>
          <select id="restore_backup_file" name="restore_backup_file">
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
          <input type="text" id="restore_confirm" name="restore_confirm" value="" />
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

  function confirmClearMediaFiles() {
    if (!confirm('Are you sure you want to DELETE ALL MEDIA FILES from disk?\n\nThis will permanently delete:\n- All audio files\n- All video files\n- All thumbnail files\n\nThe database will NOT be affected.\n\nThis action CANNOT be undone and files CANNOT be recovered from a database backup!')) {
      return;
    }
    if (!confirm('Second confirmation required.\n\nEnsure no uploads are currently in progress.\n\nProceed with deleting all media files from disk?')) {
      return;
    }

    const btn = document.getElementById('clearMediaFilesBtn');
    const status = document.getElementById('clearMediaFilesStatus');

    btn.disabled = true;
    btn.textContent = 'Deleting...';
    status.innerHTML = '<div class="muted">Processing request...</div>';

    fetch('clear_media_files.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' }
    })
    .then(response => response.json())
    .then(data => {
      if (data.success) {
        const audio = Number(data.audio_files_deleted) || 0;
        const video = Number(data.video_files_deleted) || 0;
        const thumbs = Number(data.thumbnail_files_deleted) || 0;
        const total = Number(data.total_deleted) || 0;
        status.innerHTML = '<div class="alert-ok">Deleted ' + total + ' file(s) from disk.'
          + ' (audio: ' + audio + ', video: ' + video + ', thumbnails: ' + thumbs + ')</div>';
        btn.textContent = 'Deleted Successfully';
        btn.style.background = '#28a745';
      } else {
        const errs = Array.isArray(data.errors) && data.errors.length ? '<br>' + data.errors.join('<br>') : '';
        status.innerHTML = '<div class="alert-err">Error: ' + (data.message || 'Unknown error occurred') + errs + '</div>';
        btn.disabled = false;
        btn.textContent = 'Delete All Media Files';
      }
    })
    .catch(error => {
      status.innerHTML = '<div class="alert-err">Network error: ' + error.message + '</div>';
      btn.disabled = false;
      btn.textContent = 'Delete All Media Files';
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
  </script>
</body>
</html>
