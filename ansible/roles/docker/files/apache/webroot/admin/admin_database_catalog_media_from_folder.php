<?php declare(strict_types=1);

$user = $_SERVER['PHP_AUTH_USER']
    ?? $_SERVER['REMOTE_USER']
    ?? $_SERVER['REDIRECT_REMOTE_USER']
    ?? null;

if ($user !== 'admin') {
    http_response_code(403);
    echo '<h1>Forbidden</h1><p>Admin access required.</p>';
    exit;
}

$__json_env_array = static function (string $key): array {
    $raw = getenv($key);
    if (!is_string($raw) || trim($raw) === '') return [];
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) return [];
    $out = [];
    foreach ($decoded as $x) {
        if (is_string($x) && trim($x) !== '') $out[] = strtolower(trim($x));
    }
    return array_values(array_unique($out));
};
$__audio_exts = $__json_env_array('UPLOAD_AUDIO_EXTS_JSON') ?: ['mp3', 'wav', 'flac', 'aac', 'ogg', 'm4a'];
$__video_exts = $__json_env_array('UPLOAD_VIDEO_EXTS_JSON') ?: ['mp4', 'mov', 'mkv', 'avi', 'webm', 'm4v'];
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8"/>
  <meta name="viewport" content="width=device-width,initial-scale=1"/>
  <title>Admin: Catalog Media</title>
  <style>
    :root { font-family: system-ui,-apple-system,Segoe UI,Roboto,sans-serif; }
    body  { margin:0; background:#0b1020; color:#e9eef7; }
    .wrap { max-width:920px; margin:3rem auto; padding:1rem; }
    .card { background:#121a33; border:1px solid #1d2a55; border-radius:16px; padding:1.5rem; position:relative; }
    button { padding:.8rem 1.1rem; border-radius:10px; border:1px solid #3b82f6; background:transparent; color:#e9eef7; cursor:pointer; }
    button:hover:not(:disabled) { background:#1e40af; color:#fff; }
    button:disabled { cursor:not-allowed; opacity:.55; }
    button.danger { border-color:#dc2626; }
    button.danger:hover:not(:disabled) { background:#991b1b; }
    .section-divider { border-top:2px solid #1d2a55; margin:2rem 0; padding-top:2rem; }
    .alert-ok  { background:#11331a; border:1px solid #1f7a3b; padding:.8rem 1rem; border-radius:10px; margin-bottom:.75rem; }
    .alert-err { background:#3b0d14; border:1px solid #b4232a; padding:.8rem 1rem; border-radius:10px; margin-bottom:.75rem; }
    .muted { color:#a8b3cf; font-size:.95rem; }
    label { display:block; font-size:.9rem; color:#a8b3cf; margin-bottom:.25rem; }
    input[type=text], input[type=date], select, textarea {
      width:100%; box-sizing:border-box; padding:.55rem .75rem;
      background:#0e1530; border:1px solid #33427a; border-radius:8px;
      color:#e9eef7; font-size:.9rem; margin-bottom:.6rem;
    }
    textarea { resize:vertical; min-height:60px; }
    select { appearance:none; }
    .field-grid { display:grid; grid-template-columns:1fr 1fr; gap:0 1rem; }
    .field-grid .span2 { grid-column:1/-1; }
    .summary-card { background:#0e1530; border:1px solid #33427a; border-radius:12px; padding:1.25rem; margin-top:1rem; }
    .summary-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(180px,1fr)); gap:.75rem; margin-top:.75rem; }
    .stat-box { background:#121a33; border:1px solid #1d2a55; border-radius:10px; padding:.75rem 1rem; }
    .stat-label { font-size:.78rem; color:#a8b3cf; text-transform:uppercase; letter-spacing:.05em; margin-bottom:.2rem; }
    .stat-value { font-size:1.15rem; font-weight:700; }
    .badge { display:inline-block; padding:.15rem .5rem; border-radius:6px; font-size:.78rem; font-weight:700; }
    .badge-audio { background:#1c3a1c; color:#4ade80; }
    .badge-video { background:#1e3a5f; color:#60a5fa; }
    .badge-unsup { background:#3b2700; color:#fb923c; }
    details summary { cursor:pointer; color:#60a5fa; font-size:.9rem; margin-bottom:.5rem; }
  </style>
</head>
<body>
<div class="wrap"><div class="card">

  <div style="display:flex;gap:1.5rem;justify-content:space-between;align-items:flex-start">
    <div style="flex:1;min-width:0">
      <h1 style="margin:0 0 .25rem">Admin: Catalog Media</h1>
      <p class="muted">Signed in as <code><?= htmlspecialchars((string)$user) ?></code>.</p>
      <p class="muted">Gighive gives you the ability to scan one or more folders for later upload and ingest into the database. This page allows you to select a folder of files to catalog before uploading. After the initial catalog is created based on that folder of files, you will be able to delete files you don't want or edit the fields of information on specific files before uploading. Note this is a precursor to actually uploading your media files from the Catalog into Gighive's database. Alternatively, if you want to upload a folder of files directly into Gighive, utilize the <a href="/admin/admin_database_load_import_media_from_folder.php" style="color:#60a5fa">Import Media</a> function. Results appear immediately in <a href="/db/database_catalog.php" style="color:#a855f7">Catalog Database</a>.</p>
      <p class="muted">Supported audio extensions: <code><?= htmlspecialchars(implode(', ', $__audio_exts)) ?></code><br>
         Supported video extensions: <code><?= htmlspecialchars(implode(', ', $__video_exts)) ?></code></p>
    </div>
    <div style="display:flex;flex-direction:column;gap:.4rem;align-items:flex-end;flex-shrink:0">
      <a href="/admin/admin.php"><button type="button" style="border-color:#3b82f6;font-size:.8rem;padding:.4rem .8rem">Password Reset</button></a>
      <a href="/admin/admin_system.php"><button type="button" style="border-color:#3b82f6;font-size:.8rem;padding:.4rem .8rem">System &amp; Recovery</button></a>
      <a href="/admin/admin_database_load_import_media_from_folder.php"><button type="button" style="border-color:#3b82f6;font-size:.8rem;padding:.4rem .8rem">Import Media</button></a>
      <a href="/admin/admin_database_load_import_csv.php"><button type="button" style="border-color:#3b82f6;font-size:.8rem;padding:.4rem .8rem">Import CSV</button></a>
      <a href="/db/database_catalog.php"><button type="button" style="border-color:#a855f7;font-size:.8rem;padding:.4rem .8rem">Catalog Database</button></a>
    </div>
  </div>

  <!-- ───── Section A ───── -->
  <div class="section-divider" id="secA">
    <h2>Section A: Catalog Media <span class="muted" style="font-size:.8em">(clears existing catalog for this path)</span></h2>
    <p class="muted">Deletes any prior catalog entries for this path, then scans fresh. Use this to rebuild the catalog after files have been renamed or reorganised.</p>
    <div style="background:#3b1f0d;border:1px solid #b45309;padding:.75rem;border-radius:10px;margin-bottom:.75rem">
      <strong>Warning:</strong> All existing catalog entries for this source path will be deleted before scanning.
    </div>

    <label for="a-folder" class="muted" id="a-folder-label">Step 1: Select a folder:</label>
    <div style="margin:.5rem 0;display:flex;align-items:center;gap:.75rem;flex-wrap:wrap">
      <button type="button" onclick="document.getElementById('a-folder').click()">Choose Folder</button>
      <input type="file" id="a-folder" webkitdirectory directory multiple style="display:none"/>
      <span id="a-folder-chosen" class="muted" style="font-size:.9em"></span>
    </div>
    <div id="a-preview"></div>

    <details>
      <summary>Optional metadata (applied as scan-level defaults)</summary>
      <div class="field-grid">
        <div>
          <label for="a-label">Scan label</label>
          <input type="text" id="a-label" placeholder="e.g. Smith wedding NAS scan"/>
        </div>
        <div>
          <label for="a-org">Band / Event name</label>
          <input type="text" id="a-org" placeholder="org_name"/>
        </div>
        <div>
          <label for="a-date">Event date</label>
          <input type="date" id="a-date"/>
        </div>
        <div>
          <label for="a-etype">Event type</label>
          <select id="a-etype">
            <option value="">— none —</option>
            <option value="band">Band / Gig</option>
            <option value="wedding">Wedding</option>
            <option value="other">Other</option>
          </select>
        </div>
        <div>
          <label for="a-location">Location / Venue</label>
          <input type="text" id="a-location" placeholder="location"/>
        </div>
        <div>
          <label for="a-keywords">Keywords</label>
          <input type="text" id="a-keywords" placeholder="keywords"/>
        </div>
        <div class="span2">
          <label for="a-summary">Summary (promotes to events.summary at ingest)</label>
          <input type="text" id="a-summary" placeholder="summary"/>
        </div>
        <div class="span2">
          <label for="a-notes">Notes (operator-only; not promoted)</label>
          <textarea id="a-notes" placeholder="internal notes…"></textarea>
        </div>
      </div>
    </details>

    <label class="muted">Step 2: Run the scan:</label>
    <div style="display:flex;gap:.5rem;flex-wrap:wrap;margin-top:.25rem">
      <button id="a-scan-btn" class="danger" disabled onclick="runScan('a','reload')">Catalog Media (Reload)</button>
    </div>
    <div id="a-status" style="margin:.75rem 0"></div>
    <div id="a-result"></div>
  </div>

  <!-- ───── Section B ───── -->
  <div class="section-divider" id="secB">
    <h2>Section B: Add to Catalog <span class="muted" style="font-size:.8em">(non-destructive)</span></h2>
    <p class="muted">Scans the folder and inserts only new files. Files already in the catalog get their <em>last seen</em> timestamp updated. Files no longer on disk are detectable in Catalog Review by a stale last-seen scan.</p>

    <label for="b-folder" class="muted" id="b-folder-label">Step 1: Select a folder:</label>
    <div style="margin:.5rem 0;display:flex;align-items:center;gap:.75rem;flex-wrap:wrap">
      <button type="button" onclick="document.getElementById('b-folder').click()">Choose Folder</button>
      <input type="file" id="b-folder" webkitdirectory directory multiple style="display:none"/>
      <span id="b-folder-chosen" class="muted" style="font-size:.9em"></span>
    </div>
    <div id="b-preview"></div>

    <details>
      <summary>Optional metadata (applied as scan-level defaults)</summary>
      <div class="field-grid">
        <div>
          <label for="b-label">Scan label</label>
          <input type="text" id="b-label" placeholder="e.g. delta scan after tour"/>
        </div>
        <div>
          <label for="b-org">Band / Event name</label>
          <input type="text" id="b-org" placeholder="org_name"/>
        </div>
        <div>
          <label for="b-date">Event date</label>
          <input type="date" id="b-date"/>
        </div>
        <div>
          <label for="b-etype">Event type</label>
          <select id="b-etype">
            <option value="">— none —</option>
            <option value="band">Band / Gig</option>
            <option value="wedding">Wedding</option>
            <option value="other">Other</option>
          </select>
        </div>
        <div>
          <label for="b-location">Location / Venue</label>
          <input type="text" id="b-location" placeholder="location"/>
        </div>
        <div>
          <label for="b-keywords">Keywords</label>
          <input type="text" id="b-keywords" placeholder="keywords"/>
        </div>
        <div class="span2">
          <label for="b-summary">Summary</label>
          <input type="text" id="b-summary" placeholder="summary"/>
        </div>
        <div class="span2">
          <label for="b-notes">Notes</label>
          <textarea id="b-notes" placeholder="internal notes…"></textarea>
        </div>
      </div>
    </details>

    <label class="muted">Step 2: Run the scan:</label>
    <div style="display:flex;gap:.5rem;flex-wrap:wrap;margin-top:.25rem">
      <button id="b-scan-btn" disabled onclick="runScan('b','add')">Catalog Media (Add)</button>
    </div>
    <div id="b-status" style="margin:.75rem 0"></div>
    <div id="b-result"></div>
    <div id="b-total-result"></div>
  </div>

</div></div>

<script>
'use strict';

// ── PHP-injected constants ────────────────────────────────────────────────────
const AUDIO_EXTS = new Set(<?= json_encode($__audio_exts) ?>);
const VIDEO_EXTS = new Set(<?= json_encode($__video_exts) ?>);
const MEDIA_EXTS = new Set([...AUDIO_EXTS, ...VIDEO_EXTS]);

// ── Per-section state ─────────────────────────────────────────────────────────
const _S = {
  a: { mode: 'reload', folderKey: '', scanState: null },
  b: { mode: 'add',    folderKey: '', scanState: null },
};

// ── Utilities ─────────────────────────────────────────────────────────────────
function el(id) { return document.getElementById(id); }
function html(id, h) { const e = el(id); if (e) e.innerHTML = h; }
function esc(s) { return String(s||'').replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]||c)); }
function formatBytes(b) {
  b = Number(b);
  if (b >= 1e12) return (b/1e12).toFixed(2) + ' TB';
  if (b >= 1e9)  return (b/1e9).toFixed(2)  + ' GB';
  if (b >= 1e6)  return (b/1e6).toFixed(2)  + ' MB';
  if (b >= 1e3)  return (b/1e3).toFixed(1)  + ' KB';
  return b + ' B';
}
function fileExtLower(name) { const n = String(name||''); const d = n.lastIndexOf('.'); return d >= 0 ? n.slice(d+1).toLowerCase() : ''; }
function folderKeyFromFiles(list) { for (const f of list) { const r = f.webkitRelativePath||''; if (r.indexOf('/') >= 0) return r.split('/')[0]; } return ''; }

// ── Scan-state preview (mirrors import page buildScanState / renderScanPreview) ──
function buildScanState(list) {
  let total = 0, audio = 0, video = 0, unsup = 0, supBytes = 0;
  for (const f of list) {
    total++;
    const ext = fileExtLower(f.name);
    if (AUDIO_EXTS.has(ext))      { audio++; supBytes += (Number(f.size)||0); }
    else if (VIDEO_EXTS.has(ext)) { video++; supBytes += (Number(f.size)||0); }
    else                          { unsup++; }
  }
  return { totalCount: total, audioCount: audio, videoCount: video, unsupportedCount: unsup, supportedSizeBytes: supBytes };
}
function renderScanPreview(st) {
  if (!st || st.totalCount === 0) return '';
  let h = '<div class="muted" style="margin:.25rem 0">Files: ' + st.totalCount.toLocaleString() + ' total';
  if (st.audioCount)       h += ' &nbsp;·&nbsp; <span class="badge badge-audio">' + st.audioCount + ' audio</span>';
  if (st.videoCount)       h += ' &nbsp;·&nbsp; <span class="badge badge-video">' + st.videoCount + ' video</span>';
  if (st.unsupportedCount) h += ' &nbsp;·&nbsp; <span class="badge badge-unsup">' + st.unsupportedCount + ' unsupported</span>';
  h += ' &nbsp;·&nbsp; ' + formatBytes(st.supportedSizeBytes) + ' supported</div>';
  if (st.audioCount === 0 && st.videoCount === 0)
    h += '<div class="muted" style="color:#fb923c">No supported audio or video files found — catalog will record unsupported entries only.</div>';
  return h;
}

// ── Folder input change handlers ──────────────────────────────────────────────
['a', 'b'].forEach(function(sec) {
  const inp = el(sec + '-folder');
  if (!inp) return;
  inp.addEventListener('change', function() {
    const list = inp.files ? Array.from(inp.files) : [];
    const s = _S[sec];
    s.folderKey  = folderKeyFromFiles(list);
    s.scanState  = buildScanState(list);
    html(sec + '-preview', renderScanPreview(s.scanState));
    html(sec + '-status', '');
    const chosenSpan = el(sec + '-folder-chosen');
    if (chosenSpan) chosenSpan.textContent = list.length
      ? s.folderKey + ' (' + list.length.toLocaleString() + ' files)'
      : 'No folder selected';
    el(sec + '-scan-btn').disabled = list.length === 0;
  });
});

// ── Scan function ─────────────────────────────────────────────────────────────
async function runScan(sec, mode) {
  const inp = el(sec + '-folder');
  if (!inp || !inp.files || inp.files.length === 0) {
    html(sec + '-status', '<div class="alert-err">Please select a folder first.</div>');
    return;
  }

  if (mode === 'reload' && !confirm(
    'Catalog Media (Reload)\n\n' +
    'This will delete ALL existing catalog entries for "' + (_S[sec].folderKey || 'this folder') + '" and rescan from scratch.\n\n' +
    'Continue?'
  )) return;

  const btn = el(sec + '-scan-btn');
  btn.disabled = true;
  html(sec + '-status', '<div class="muted">Building file list…</div>');
  html(sec + '-result', '');
  if (sec === 'b') html('b-total-result', '');

  const files = Array.from(inp.files).map(function(f) {
    return { relpath: f.webkitRelativePath || f.name, size_bytes: f.size, last_modified_ms: f.lastModified };
  });

  const payload = {
    mode,
    files,
    scan_label : el(sec + '-label')    ? el(sec + '-label').value.trim()    || null : null,
    org_name   : el(sec + '-org')      ? el(sec + '-org').value.trim()      || null : null,
    event_date : el(sec + '-date')     ? el(sec + '-date').value.trim()     || null : null,
    event_type : el(sec + '-etype')    ? el(sec + '-etype').value            || null : null,
    location   : el(sec + '-location') ? el(sec + '-location').value.trim() || null : null,
    keywords   : el(sec + '-keywords') ? el(sec + '-keywords').value.trim() || null : null,
    summary    : el(sec + '-summary')  ? el(sec + '-summary').value.trim()  || null : null,
    notes      : el(sec + '-notes')    ? el(sec + '-notes').value.trim()    || null : null,
  };

  html(sec + '-status', '<div class="muted">Sending ' + files.length.toLocaleString() + ' file entries to server…</div>');

  try {
    const res  = await fetch('/admin/catalog_scan_start.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload),
    });
    const data = await res.json();
    if (!res.ok || !data.success) throw new Error(data.message || data.error || 'Unknown error');
    html(sec + '-status', '<div class="alert-ok">Scan complete in ' + esc(data.duration_ms) + ' ms.</div>');
    html(sec + '-result', renderSummary(data));
    if (sec === 'b') {
      try {
        const statsRes = await fetch('/admin/catalog_stats.php');
        if (!statsRes.ok) throw new Error('HTTP ' + statsRes.status);
        const statsData = await statsRes.json();
        if (statsData.success) html('b-total-result', renderTotalStats(statsData));
      } catch (e) {
        html('b-total-result', '<p class="muted" style="margin:.5rem 0">Could not load total catalog stats.</p>');
      }
    }
  } catch (err) {
    html(sec + '-status', '<div class="alert-err">Error: ' + esc(err.message) + '</div>');
  } finally {
    btn.disabled = !(inp.files && inp.files.length > 0);
  }
}

// ── Result summary card ───────────────────────────────────────────────────────
function renderSummary(data) {
  const s       = data.summary;
  const skipped = data.ignored || { count: 0, paths: [] };
  const scanId  = data.scan_id;
  const modeLabel = data.mode === 'reload' ? 'Full reload' : 'Delta add';
  const aiLine = s.video_count > 0
    ? '<div class="stat-box"><div class="stat-label">Est. AI tagging cost</div><div class="stat-value">$' + esc(s.estimated_ai_cost_usd.toFixed(2)) + '</div><div class="muted" style="font-size:.78rem">proxy: ' + esc(s.video_count) + ' videos \xd7 $0.046</div></div>'
    : '';
  const skippedBox = skipped.count > 0
    ? `<div class="stat-box"><div class="stat-label">Ignored (path collision)</div><div class="stat-value"><span class="badge badge-unsup">${esc(skipped.count)}</span></div><div class="muted" style="font-size:.78rem">dropped \u2014 Unicode-equivalent path already inserted</div></div>`
    : '';
  const skippedDetail = skipped.count > 0
    ? `<div style="background:#3b1f0d;border:1px solid #b45309;padding:.75rem;border-radius:10px;margin-top:.75rem">
        <strong>\u26a0 ${esc(skipped.count)} file(s) ignored \u2014 path collision</strong><br>
        <span class="muted" style="font-size:.82rem">These paths were dropped because a Unicode-equivalent path (e.g. diacritic variant folder) was already inserted in this scan. Use Reload to get a clean inventory after resolving the folder naming conflict.</span>
        <ul style="margin:.4rem 0 0;padding-left:1.2rem;font-size:.82rem;color:#fb923c">
          ${skipped.paths.slice(0, 5).map(p => `<li>${esc(p)}</li>`).join('')}
          ${skipped.paths.length > 5 ? `<li class="muted">\u2026 ${esc(skipped.paths.length - 5)} more</li>` : ''}
        </ul>
      </div>`
    : '';
  return `
    <div class="summary-card">
      <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.5rem">
        <strong>Scan #${esc(scanId)} \u2014 ${esc(modeLabel)}</strong>
        <a href="/db/database_catalog.php" style="color:#a855f7;font-size:.9rem">Open Catalog Database \u2192</a>
      </div>
      <div class="summary-grid">
        <div class="stat-box">
          <div class="stat-label">Total files</div>
          <div class="stat-value">${esc(s.total_files.toLocaleString())}</div>
          <div class="muted" style="font-size:.78rem">${esc(s.supported_files)} supported \xb7 ${esc(s.unsupported_files)} unsupported</div>
        </div>
        <div class="stat-box">
          <div class="stat-label">Total size</div>
          <div class="stat-value">${esc(formatBytes(s.total_size_bytes))}</div>
          <div class="muted" style="font-size:.78rem">audio ${esc(formatBytes(s.audio_size_bytes))} \xb7 video ${esc(formatBytes(s.video_size_bytes))}</div>
        </div>
        <div class="stat-box">
          <div class="stat-label">Audio files</div>
          <div class="stat-value"><span class="badge badge-audio">${esc(s.audio_count)}</span></div>
          <div class="muted" style="font-size:.78rem">~${esc(s.estimated_audio_minutes)} min estimated</div>
        </div>
        <div class="stat-box">
          <div class="stat-label">Video files</div>
          <div class="stat-value"><span class="badge badge-video">${esc(s.video_count)}</span></div>
          <div class="muted" style="font-size:.78rem">~${esc(s.estimated_video_minutes)} min estimated</div>
        </div>
        ${s.unsupported_files > 0 ? `<div class="stat-box"><div class="stat-label">Unsupported</div><div class="stat-value"><span class="badge badge-unsup">${esc(s.unsupported_files)}</span></div><div class="muted" style="font-size:.78rem">visible in Catalog Media with is_supported filter</div></div>` : ''}
        ${skippedBox}
        ${aiLine}
      </div>
      <p class="muted" style="margin:.75rem 0 0;font-size:.85rem">
        Note: for <strong>Add to Catalog</strong> scans, aggregate counts above reflect the full current pick. The entry list in Catalog Media scoped to this scan_id shows only newly added files.
      </p>
      ${skippedDetail}
    </div>`;
}

// ── Total Catalog Stats card (Section B only) ─────────────────────────────────
function renderTotalStats(data) {
  const s = data.summary;
  const aiLine = s.video_count > 0
    ? '<div class="stat-box"><div class="stat-label">Est. AI tagging cost</div><div class="stat-value">$' + esc(s.estimated_ai_cost_usd.toFixed(2)) + '</div><div class="muted" style="font-size:.78rem">proxy: ' + esc(s.video_count) + ' videos \xd7 $0.046</div></div>'
    : '';
  return `
    <div class="summary-card" style="margin-top:.75rem">
      <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.5rem">
        <strong>Total Catalog Stats</strong>
        <a href="/db/database_catalog.php" style="color:#a855f7;font-size:.9rem">Open Catalog Database \u2192</a>
      </div>
      <div class="summary-grid">
        <div class="stat-box">
          <div class="stat-label">Total files</div>
          <div class="stat-value">${esc(s.total_files.toLocaleString())}</div>
          <div class="muted" style="font-size:.78rem">${esc(s.supported_files)} supported \xb7 ${esc(s.unsupported_files)} unsupported</div>
        </div>
        <div class="stat-box">
          <div class="stat-label">Total size</div>
          <div class="stat-value">${esc(formatBytes(s.total_size_bytes))}</div>
          <div class="muted" style="font-size:.78rem">audio ${esc(formatBytes(s.audio_size_bytes))} \xb7 video ${esc(formatBytes(s.video_size_bytes))}</div>
        </div>
        <div class="stat-box">
          <div class="stat-label">Audio files</div>
          <div class="stat-value"><span class="badge badge-audio">${esc(s.audio_count)}</span></div>
          <div class="muted" style="font-size:.78rem">~${esc(s.estimated_audio_minutes)} min estimated</div>
        </div>
        <div class="stat-box">
          <div class="stat-label">Video files</div>
          <div class="stat-value"><span class="badge badge-video">${esc(s.video_count)}</span></div>
          <div class="muted" style="font-size:.78rem">~${esc(s.estimated_video_minutes)} min estimated</div>
        </div>
        ${s.unsupported_files > 0 ? `<div class="stat-box"><div class="stat-label">Unsupported</div><div class="stat-value"><span class="badge badge-unsup">${esc(s.unsupported_files)}</span></div><div class="muted" style="font-size:.78rem">visible in Catalog Media with is_supported filter</div></div>` : ''}
        ${aiLine}
      </div>
    </div>`;
}
</script>
</body>
</html>
