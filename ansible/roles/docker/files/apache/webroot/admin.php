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
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>GigHive Admin - First Time Setup</title>
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
    button:disabled { opacity:0.5; cursor:not-allowed; }
    .section-divider { border-top:2px solid #1d2a55; margin:2rem 0; padding-top:2rem; }
    .warning-box { background:#3b1f0d; border:1px solid #b45309; padding:1rem; border-radius:10px; margin-bottom:1rem; }
    .alert-ok { background:#11331a; border:1px solid #1f7a3b; padding:.8rem 1rem; border-radius:10px; margin-bottom:1rem;}
    .alert-err{ background:#3b0d14; border:1px solid #b4232a; padding:.8rem 1rem; border-radius:10px; margin-bottom:1rem;}
    .muted { color:#a8b3cf; font-size:.95rem; }
    code.path { word-break: break-all; }
  </style>
</head>
<body>
  <div class="wrap">
    <div class="card">
      <h1>GigHive Admin - First Time Setup</h1>
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
        <h2>Section 3: Upload CSV and Reload Database</h2>
        <p class="muted">
          Upload a CSV export and rebuild the media database tables.
          This action is <strong>irreversible</strong> and will truncate all media tables before loading.
          The users table will be preserved.
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
        <h2>Choose a Folder to Scan &amp; Update the Database</h2>
        <p class="muted">
          Select a folder on this computer to scan for media files and generate an import-ready CSV.
          This action is <strong>irreversible</strong> and will truncate all media tables before loading.
          The users table will be preserved.
        </p>
        <div class="warning-box">
          <strong>⚠️ Warning:</strong> This will permanently delete and replace all media data from the database.
        </div>
        <div class="row">
          <label for="media_folder">Select folder</label>
          <input type="file" id="media_folder" name="media_folder" webkitdirectory directory multiple />
        </div>
        <div id="scanFolderPreview"></div>
        <div id="scanFolderStatus"></div>
        <button type="button" id="scanFolderBtn" class="danger" onclick="confirmScanFolderImport()" disabled>Scan Folder and Update DB</button>
      </div>
    </div>
  </div>

  <script>
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
        status.innerHTML = '<div class="alert-ok">' + (data.message || 'Media tables cleared successfully!') + 
          ' <a href="/db/database.php" style="display:inline-block;margin-left:10px;padding:8px 16px;background:#28a745;color:white;text-decoration:none;border-radius:4px;font-weight:bold;">See Cleared Database</a></div>';
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

  function renderImportSteps(steps) {
    if (!Array.isArray(steps)) return '';
    let html = '<div class="muted">Progress:</div><div style="margin-top:.5rem">';
    for (const s of steps) {
      const status = s.status || 'pending';
      const name = s.name || '';
      const msg = s.message || '';
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
          status.innerHTML = '<div class="alert-ok">' + (data.message || 'Database import completed successfully.') +
            ' <a href="/db/database.php" style="display:inline-block;margin-left:10px;padding:8px 16px;background:#28a745;color:white;text-decoration:none;border-radius:4px;font-weight:bold;">See Updated Database</a></div>'
            + renderImportSteps(data.steps);
          btn.textContent = 'Import Completed';
          btn.style.background = '#28a745';
        } else {
          const msg = (data && (data.message || data.error)) ? (data.message || data.error) : 'Unknown error occurred';
          status.innerHTML = '<div class="alert-err">Error: ' + msg + '</div>'
            + (data && data.steps ? renderImportSteps(data.steps) : '');
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

  const MEDIA_EXTS = new Set(['mp3','wav','aac','flac','m4a','mp4','mov','mkv','webm','avi']);

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

  function fileBasenameNoExt(filename) {
    const name = String(filename || '');
    const dot = name.lastIndexOf('.');
    return dot > 0 ? name.slice(0, dot) : name;
  }

  function fileExtLower(filename) {
    const name = String(filename || '');
    const dot = name.lastIndexOf('.');
    return dot >= 0 ? name.slice(dot + 1).toLowerCase() : '';
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
    let fallbackTimestampCount = 0;
    let epochFallbackCount = 0;

    for (const f of files) {
      const ext = fileExtLower(f.name);
      if (!MEDIA_EXTS.has(ext)) {
        ignoredCount++;
        continue;
      }
      supportedCount++;

      let dDate = parseDateFromFilename(f.name);
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
      const fSingles = fileBasenameNoExt(f.name);
      const key = dDate;
      if (!sessions.has(key)) {
        sessions.set(key, {
          t_title: tTitle,
          d_date: dDate,
          d_merged_song_lists: 'local-folder-no-songlist',
          files: []
        });
      }
      sessions.get(key).files.push(fSingles);
    }

    const sorted = Array.from(sessions.values()).sort((a, b) => a.d_date.localeCompare(b.d_date));
    for (const s of sorted) {
      s.files = Array.from(new Set(s.files)).sort();
    }

    return {
      sessions: sorted,
      supportedCount,
      ignoredCount,
      fallbackTimestampCount,
      epochFallbackCount
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

  function renderScanPreview(info) {
    const sessions = info.sessions || [];
    let html = '';
    html += '<div class="muted">Files selected: ' + (info.totalCount ?? 0) + '</div>';
    html += '<div class="muted">Supported media files: ' + (info.supportedCount ?? 0) + '</div>';
    html += '<div class="muted">Ignored files: ' + (info.ignoredCount ?? 0) + '</div>';
    if ((info.fallbackTimestampCount ?? 0) > 0) {
      html += '<div class="muted">Used file timestamp for date: ' + info.fallbackTimestampCount + '</div>';
    }
    if ((info.epochFallbackCount ?? 0) > 0) {
      html += '<div class="muted">Used 1970-01-01 for date: ' + info.epochFallbackCount + '</div>';
    }
    html += '<div class="muted" style="margin-top:.75rem">Sessions detected: ' + sessions.length + '</div>';

    if (sessions.length) {
      html += '<div style="margin-top:.75rem;max-height:240px;overflow:auto;border:1px solid #1d2a55;border-radius:10px;padding:.75rem;background:#0e1530">';
      html += '<div class="muted" style="margin-bottom:.5rem">Preview (first 25 sessions)</div>';
      const show = sessions.slice(0, 25);
      for (const s of show) {
        html += '<div style="margin:.35rem 0">'
          + '<strong>' + String(s.d_date).replace(/[&<>]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;'}[c])) + '</strong>'
          + '<span class="muted"> — files: ' + (s.files ? s.files.length : 0) + '</span>'
          + '</div>';
      }
      html += '</div>';
    }

    return html;
  }

  let _scanFolderState = null;

  const mediaFolderInput = document.getElementById('media_folder');
  const scanFolderPreview = document.getElementById('scanFolderPreview');
  const scanFolderStatus = document.getElementById('scanFolderStatus');
  const scanFolderBtn = document.getElementById('scanFolderBtn');

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
      scanFolderBtn.disabled = !(_scanFolderState.supportedCount > 0 && _scanFolderState.sessions.length > 0);
    });
  }

  function confirmScanFolderImport() {
    if (!_scanFolderState || !_scanFolderState.sessions || !_scanFolderState.sessions.length) {
      scanFolderStatus.innerHTML = '<div class="alert-err">Please select a folder with supported media files first.</div>';
      return;
    }

    if (!confirm('Are you sure you want to scan this folder and reload the database?\n\nThis will permanently delete and replace ALL media data (sessions/songs/files/musicians/genres/styles).\n\nThis action CANNOT be undone!')) {
      return;
    }

    const csvText = sessionsToCsv(_scanFolderState.sessions);
    const blob = new Blob([csvText], { type: 'text/csv' });
    const file = new File([blob], 'database.csv', { type: 'text/csv' });

    validateDatabaseCsvHeaders(file).then(result => {
      if (!result.ok) {
        scanFolderStatus.innerHTML = '<div class="alert-err">' + result.message.replace(/[&<>]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;'}[c])).replace(/\n/g, '<br>') + '</div>';
        return;
      }

      const formData = new FormData();
      formData.append('database_csv', file);

      scanFolderBtn.disabled = true;
      scanFolderBtn.textContent = 'Scanning and Importing...';
      scanFolderStatus.innerHTML = '<div class="muted">Processing request...</div>';

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
          scanFolderStatus.innerHTML = '<div class="alert-ok">' + (data.message || 'Database import completed successfully.') +
            ' <a href="/db/database.php" style="display:inline-block;margin-left:10px;padding:8px 16px;background:#28a745;color:white;text-decoration:none;border-radius:4px;font-weight:bold;">See Updated Database</a></div>'
            + renderImportSteps(data.steps);
          scanFolderBtn.textContent = 'Import Completed';
          scanFolderBtn.style.background = '#28a745';
        } else {
          const msg = (data && (data.message || data.error)) ? (data.message || data.error) : 'Unknown error occurred';
          scanFolderStatus.innerHTML = '<div class="alert-err">Error: ' + msg + '</div>'
            + (data && data.steps ? renderImportSteps(data.steps) : '');
          scanFolderBtn.disabled = false;
          scanFolderBtn.textContent = 'Scan Folder and Update DB';
        }
      })
      .catch(error => {
        scanFolderStatus.innerHTML = '<div class="alert-err">Network error: ' + error.message + '</div>';
        scanFolderBtn.disabled = false;
        scanFolderBtn.textContent = 'Scan Folder and Update DB';
      });
    });
  }
  </script>
</body>
</html>

