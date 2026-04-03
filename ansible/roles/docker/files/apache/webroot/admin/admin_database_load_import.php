<?php declare(strict_types=1);
/**
 * admin_database_load_import.php — Database Load, CSV Import Admin page for GigHive
 * Section A: Upload Single CSV and Reload Database (Legacy)
 * Section B: Upload Sessions + Session Files and Reload Database (Normalized)
 * Section C: Upload Files Individually
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
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>Admin: Database Load, CSV Import</title>
  <style>
    :root { font-family: system-ui, -apple-system, Segoe UI, Roboto, sans-serif; }
    body { margin:0; background:#0b1020; color:#e9eef7; }
    .wrap { max-width: 880px; margin: 3rem auto; padding: 1rem; }
    .card { background:#121a33; border:1px solid #1d2a55; border-radius:16px; padding:1.5rem; position:relative; }
    .row { display:grid; gap:.5rem; margin-bottom:1rem; }
    label { font-weight:600; }
    input[type=file] { color:#e9eef7; }
    button { padding:.8rem 1.1rem; border-radius:10px; border:1px solid #3b82f6; background:transparent; color:#e9eef7; cursor:pointer; }
    button:hover { background:#1e40af; color:#fff; }
    button.danger { border-color:#dc2626; }
    button.danger:hover { background:#991b1b; }
    button:disabled { cursor:not-allowed; opacity:1; }
    button.danger:disabled { border-color:#dc2626; color:#a8b3cf; background:transparent; }
    button.nav { border-color:#3b82f6; }
    .section-divider { border-top:2px solid #1d2a55; margin:2rem 0; padding-top:2rem; }
    .warning-box { background:#3b1f0d; border:1px solid #b45309; padding:1rem; border-radius:10px; margin-bottom:1rem; }
    .alert-ok { background:#11331a; border:1px solid #1f7a3b; padding:.8rem 1rem; border-radius:10px; margin-bottom:1rem;}
    .alert-err{ background:#3b0d14; border:1px solid #b4232a; padding:.8rem 1rem; border-radius:10px; margin-bottom:1rem;}
    .muted { color:#a8b3cf; font-size:.95rem; }
    .nav-bar { display:flex; gap:.75rem; flex-wrap:wrap; margin-bottom:1.5rem; }
  </style>
</head>
<body>
  <div class="wrap">
    <div class="card">
      <div style="position:absolute;top:1.5rem;right:1.5rem;display:flex;flex-direction:column;gap:.4rem;align-items:flex-end">
        <a href="/admin/admin.php"><button type="button" style="border-color:#3b82f6;font-size:.8rem;padding:.4rem .8rem">Password Reset</button></a>
        <a href="/admin/admin_database_load_import_media_from_folder.php"><button type="button" style="border-color:#3b82f6;font-size:.8rem;padding:.4rem .8rem">Import Media Folder</button></a>
        <a href="/admin/admin_system.php"><button type="button" style="border-color:#3b82f6;font-size:.8rem;padding:.4rem .8rem">System &amp; Recovery</button></a>
      </div>
      <h1 style="padding-right:210px">Admin: Database Load, CSV Import</h1>
      <p class="muted">Signed in as <code><?= htmlspecialchars($user) ?></code>.</p>

      <div class="section-divider">
        <h2>Section A (Legacy): Upload Single CSV and Reload Database (destructive)</h2>
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
        <h2>Section B (Normalized): Upload Sessions + Session Files and Reload Database (destructive)</h2>
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
        <h2>Section C: Upload Files Individually</h2>
        <button type="button" class="danger" onclick="window.open('/db/upload_form.php', '_blank', 'noopener,noreferrer')">Upload Utility</button>
      </div>
    </div>
  </div>

  <script>
  function escapeHtml(s) {
    return String(s || '').replace(/[&<>\"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','\"':'&quot;'}[c] || c));
  }

  const __dbLinkStyle = 'display:inline-block;margin-left:10px;padding:8px 16px;background:#28a745;color:white;text-decoration:none;border-radius:4px;font-weight:bold;';

  function renderDbLinkButton(label) {
    return ' <a href="/db/database.php" target="_blank" rel="noopener noreferrer" style="' + __dbLinkStyle + '">' + String(label) + '</a>';
  }

  function renderOkBannerWithDbLink(message, linkLabel) {
    return '<div class="alert-ok">' + String(message) + renderDbLinkButton(linkLabel) + '</div>';
  }

  function renderImportSteps(steps, tableCounts) {
    if (!Array.isArray(steps)) return '';
    const counts = (tableCounts && typeof tableCounts === 'object') ? tableCounts : null;
    const jobId = arguments.length >= 3 ? arguments[2] : null;
    const now = Date.now();
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

      const progress = (s && typeof s === 'object' && s.progress && typeof s.progress === 'object') ? s.progress : null;
      const processed = progress ? Number(progress.processed) : NaN;
      const total = progress ? Number(progress.total) : NaN;
      const hasProgress = Number.isFinite(processed) && Number.isFinite(total) && total > 0;
      const pct = hasProgress ? Math.max(0, Math.min(100, Math.round((processed / total) * 100))) : 0;

      const progressHtml = hasProgress
        ? (
          '<div style="margin-left:72px;margin-top:.35rem">'
          + '<div class="muted" style="margin-bottom:.2rem">' + processed + ' / ' + total + ' (' + pct + '%)</div>'
          + '<div style="height:10px;border:1px solid #1d2a55;border-radius:999px;overflow:hidden;background:#0e1530">'
          + '<div style="height:10px;width:' + pct + '%;background:#22c55e"></div>'
          + '</div>'
          + '</div>'
        )
        : '';
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
        + progressHtml
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
  </script>
</body>
</html>
