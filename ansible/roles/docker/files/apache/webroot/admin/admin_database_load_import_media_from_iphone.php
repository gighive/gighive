<?php declare(strict_types=1);
$user = $_SERVER['PHP_AUTH_USER'] ?? $_SERVER['REMOTE_USER'] ?? $_SERVER['REDIRECT_REMOTE_USER'] ?? null;
if ($user !== 'admin') { http_response_code(403); echo '<h1>Forbidden</h1>'; exit; }
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8"/>
  <meta name="viewport" content="width=device-width,initial-scale=1"/>
  <title>Admin: iPhone Import</title>
  <style>
    :root { font-family: system-ui,-apple-system,Segoe UI,Roboto,sans-serif; }
    body { margin:0; background:#0b1020; color:#e9eef7; }
    .wrap { max-width:920px; margin:3rem auto; padding:1rem; }
    .card { background:#121a33; border:1px solid #1d2a55; border-radius:16px; padding:1.5rem; position:relative; }
    button { padding:.8rem 1.1rem; border-radius:10px; border:1px solid #3b82f6; background:transparent; color:#e9eef7; cursor:pointer; }
    button:hover { background:#1e40af; color:#fff; }
    button.danger { border-color:#dc2626; }
    button.danger:hover { background:#991b1b; }
    button:disabled { cursor:not-allowed; opacity:.55; }
    .btn-purple { border-color:#a855f7; }
    .btn-purple:hover { background:#7e22ce; }
    .btn-green { border-color:#22c55e; }
    .btn-green:hover { background:#15803d; }
    .section-divider { border-top:2px solid #1d2a55; margin:2rem 0; padding-top:2rem; }
    .step-locked { opacity:.45; pointer-events:none; }
    .alert-ok  { background:#11331a; border:1px solid #1f7a3b; padding:.8rem 1rem; border-radius:10px; margin:.5rem 0; }
    .alert-err { background:#3b0d14; border:1px solid #b4232a; padding:.8rem 1rem; border-radius:10px; margin:.5rem 0; }
    .alert-warn { background:#2d2000; border:1px solid #b45309; padding:.8rem 1rem; border-radius:10px; margin:.5rem 0; }
    .muted { color:#a8b3cf; font-size:.95rem; }
    .check-row { display:flex; align-items:center; gap:.5rem; margin:.3rem 0; font-size:.95rem; }
    .check-ok   { color:#22c55e; font-weight:700; }
    .check-fail { color:#ef4444; font-weight:700; }
    .check-pend { color:#a8b3cf; }
    .debug-log { margin-top:.9rem; background:#0e1530; border:1px solid #33427a; border-radius:10px; padding:.75rem; max-height:320px; overflow:auto; }
    .debug-log-row { padding:.45rem 0; border-top:1px solid #1d2a55; font-size:.88rem; }
    .debug-log-row:first-child { border-top:none; padding-top:0; }
    input.dark, select.dark { padding:.55rem .8rem; border-radius:8px; border:1px solid #33427a; background:#0e1530; color:#e9eef7; font-size:.95rem; width:100%; max-width:360px; }
    details summary { cursor:pointer; color:#a8b3cf; }
    details summary:hover { color:#e9eef7; }
    code { background:#0e1530; padding:.15rem .4rem; border-radius:4px; font-size:.88rem; }
    pre.cmd { background:#0e1530; border:1px solid #33427a; border-radius:8px; padding:.75rem 1rem; white-space:pre-wrap; word-break:break-word; font-size:.85rem; color:#cfd8ee; margin:.5rem 0; }
  </style>
  <link rel="stylesheet" href="/admin/assets/import_progress.css" />
  <script src="/admin/assets/import_progress.js"></script>
</head>
<body>
<div class="wrap"><div class="card">
  <div style="position:absolute;top:1.5rem;right:1.5rem;display:flex;flex-direction:column;gap:.4rem;align-items:flex-end">
    <a href="/admin/admin_database_load_import_media_from_folder.php"><button type="button" style="font-size:.8rem;padding:.4rem .8rem">← Import Media</button></a>
    <a href="/admin/admin.php"><button type="button" style="font-size:.8rem;padding:.4rem .8rem">Password Reset</button></a>
  </div>
  <h1 style="padding-right:200px">Admin: iPhone USB Bulk Import</h1>
  <p class="muted">Import video and audio files directly from an iPhone connected via USB to the GigHive host. Files are hashed and copied server-side — no browser upload needed.</p>
  <p class="muted">For known limitations and iCloud caveats, see <a href="https://gighive.app/feature_iphone_upload_catalog_caveats" target="_blank" rel="noopener noreferrer" style="color:#818cf8">iPhone Import Caveats</a>.</p>

  <!-- ── Step 1 ── -->
  <div class="section-divider" id="step1-section">
    <h2>Step 1 — Host Prerequisites <span class="muted" style="font-size:.75em">(one-time setup)</span></h2>
    <p class="muted">Run these commands once on the GigHive host machine. The <code>_host_iphone</code> staging folder is created automatically by the installer inside your <code>gighive-one-shot-bundle</code> directory.</p>

    <details style="margin:.75rem 0">
      <summary>▶ Linux setup commands</summary>
      <p class="muted" style="margin:.5rem 0"><strong>Step A — install tools (run from anywhere):</strong></p>
      <pre class="cmd">sudo apt-get install -y libimobiledevice-utils ifuse usbutils</pre>
      <p class="muted" style="margin:.5rem 0"><strong>Step B — signal GigHive (run from <code>gighive-one-shot-bundle/</code>):</strong></p>
      <pre class="cmd">cd ~/gighive-one-shot-bundle
[ -d _host_iphone ] || { echo "ERROR: run this from your gighive-one-shot-bundle/ directory"; exit 1; }
command -v ideviceinfo >/dev/null 2>&1 && command -v ifuse >/dev/null 2>&1 \
  && touch _host_iphone/.prerequisites_ok \
  && echo "Prerequisites confirmed" || echo "ERROR: one or more tools not found"</pre>
    </details>

    <details style="margin:.75rem 0">
      <summary>▶ macOS setup commands</summary>
      <p class="muted" style="margin:.5rem 0"><strong>Step A — install tools (run from anywhere):</strong></p>
      <pre class="cmd">brew install pipx
pipx install pymobiledevice3</pre>
      <p class="muted" style="font-size:.85rem;margin:.3rem 0">No kernel extension or system restart required.</p>
      <p class="muted" style="margin:.5rem 0"><strong>Step B — pair device and signal GigHive (run from <code>gighive-one-shot-bundle/</code>):</strong></p>
      <pre class="cmd">pymobiledevice3 lockdown pair
cd ~/gighive-one-shot-bundle
[ -d _host_iphone ] || { echo "ERROR: run this from your gighive-one-shot-bundle/ directory"; exit 1; }
command -v pymobiledevice3 >/dev/null 2>&1 \
  && touch _host_iphone/.prerequisites_ok \
  && echo "Prerequisites confirmed" || echo "ERROR: pymobiledevice3 not found — run: brew install pipx &amp;&amp; pipx install pymobiledevice3"</pre>
      <p class="muted" style="font-size:.85rem">After a macOS major version upgrade, re-run <code>pipx install pymobiledevice3</code> if pairing stops working.</p>
    </details>

    <details style="margin:.75rem 0">
      <summary>▶ Windows setup commands</summary>
      <p class="muted" style="margin:.5rem 0">iTunes must be installed — download from <a href="https://www.apple.com/itunes/" target="_blank" rel="noopener noreferrer" style="color:#818cf8">apple.com/itunes</a> or the Microsoft Store.</p>
      <p class="muted" style="margin:.5rem 0"><strong>Signal GigHive (run from <code>gighive-one-shot-bundle/</code>):</strong></p>
      <pre class="cmd">cd $env:USERPROFILE\gighive-one-shot-bundle
if (-not (Test-Path "_host_iphone")) { Write-Error "Run this from your gighive-one-shot-bundle directory"; exit 1 }
New-Item -ItemType File -Force -Path "_host_iphone\.prerequisites_ok" | Out-Null
Write-Host "Prerequisites confirmed"</pre>
    </details>

    <div id="step1-checks" style="margin:.75rem 0"></div>
    <div style="display:flex;gap:.5rem;flex-wrap:wrap;margin-top:.75rem">
      <button type="button" id="step1-check-btn" class="btn-purple" onclick="checkReady()">Check Ready</button>
    </div>
  </div>

  <!-- ── Step 2 ── -->
  <div class="section-divider step-locked" id="step2-section">
    <h2>Step 2 — Connect &amp; Stage Files</h2>
    <p class="muted">Connect your iPhone via USB, trust the computer on your iPhone, then run the commands below to copy files to the staging folder.</p>

    <details open style="margin:.75rem 0">
      <summary>▶ Linux staging commands</summary>
      <pre class="cmd">idevicepair pair
mkdir -p /mnt/iphone-dcim
fusermount -u /mnt/iphone-dcim 2>/dev/null || true
ifuse /mnt/iphone-dcim
rsync -av --include="*/" --include="*.mp4" --include="*.mov" --include="*.mp3" \
  --include="*.m4v" --include="*.m4a" --exclude="*" \
  /mnt/iphone-dcim/DCIM/ ~/gighive-one-shot-bundle/_host_iphone/
fusermount -u /mnt/iphone-dcim</pre>
    </details>

    <details style="margin:.75rem 0">
      <summary>▶ macOS staging commands</summary>
      <p class="muted" style="font-size:.85rem;margin:.3rem 0">Use <code>pymobiledevice3 afc ls DCIM</code> to list available DCIM subdirectories on the iPhone.</p>
      <p class="muted" style="margin:.5rem 0"><strong>Phase 1 — sample one DCIM folder first (recommended for proxy detection):</strong></p>
      <pre class="cmd">mkdir -p ~/gighive-one-shot-bundle/_host_iphone/DCIM
pymobiledevice3 afc pull -i DCIM/100APPLE ~/gighive-one-shot-bundle/_host_iphone/DCIM/</pre>
      <p class="muted" style="font-size:.85rem;margin:.3rem 0">→ Click <strong>Detect Staged Files</strong> below. If no proxy warning appears, proceed to Phase 2.</p>
      <p class="muted" style="margin:.5rem 0"><strong>Phase 2 — full pull:</strong></p>
      <pre class="cmd">pymobiledevice3 afc pull -i DCIM ~/gighive-one-shot-bundle/_host_iphone/</pre>
      <p class="muted" style="font-size:.85rem;margin:.3rem 0">→ Click <strong>Detect Staged Files</strong> again for the full count before proceeding to Step 3.</p>
    </details>

    <details style="margin:.75rem 0">
      <summary>▶ Windows staging commands</summary>
      <p class="muted" style="font-size:.88rem">If robocopy can't find the source, open File Explorer under 'This PC' to confirm your iPhone's exact device name.</p>
      <pre class="cmd">robocopy "\\Apple iPhone\Internal Storage\DCIM" `
  "$env:USERPROFILE\gighive-one-shot-bundle\_host_iphone" `
  *.mp4 *.mov *.mp3 *.m4v *.m4a /S /NFL /NDL</pre>
    </details>

    <div id="step2-counts" style="margin:.75rem 0"></div>
    <div id="step2-proxy-warn" style="margin:.5rem 0"></div>
    <div style="display:flex;gap:.5rem;flex-wrap:wrap;margin-top:.75rem">
      <button type="button" id="step2-detect-btn" class="btn-purple" onclick="detectFiles()">Detect Staged Files</button>
    </div>
  </div>

  <!-- ── Step 3 ── -->
  <div class="section-divider step-locked" id="step3-section">
    <h2>Step 3 — Review &amp; Start</h2>
    <div id="step3-summary" style="margin:.75rem 0"></div>
    <div id="step3-proxy-banner" style="margin:.5rem 0"></div>
    <div style="margin:.75rem 0">
      <label class="muted" for="org-name-input">Organisation / band name <span style="font-size:.85em">(used to group files by date)</span></label><br/>
      <input type="text" id="org-name-input" class="dark" value="iPhone" style="margin-top:.4rem" placeholder="e.g. My Band"/>
    </div>
    <div style="margin:.5rem 0">
      <label class="muted" for="event-type-select">Event type</label><br/>
      <select id="event-type-select" class="dark" style="margin-top:.4rem">
        <option value="band">Band / Gig</option>
        <option value="wedding">Wedding</option>
      </select>
    </div>
    <p class="muted" style="font-size:.88rem;margin-top:.75rem">Non-destructive add mode: duplicate checksums are automatically skipped.</p>
    <p class="muted" style="font-size:.88rem;margin-top:.4rem">Note: MOV and HEVC files import correctly but may not preview in the browser — use the Download link in the database view to play them locally.</p>
    <div style="margin-top:.75rem">
      <button type="button" id="step3-start-btn" class="btn-green" onclick="startImport()">Start iPhone Import</button>
    </div>
  </div>

  <!-- ── Step 4 ── -->
  <div class="section-divider step-locked" id="step4-section">
    <h2>Step 4 — Progress</h2>
    <div id="step4-status" style="margin:.75rem 0"></div>
    <div style="display:flex;gap:.5rem;flex-wrap:wrap;margin-top:.5rem" id="step4-buttons">
      <button type="button" id="step4-stop-btn" class="danger" disabled onclick="stopImport()">Stop</button>
      <button type="button" id="step4-refresh-btn" onclick="refreshProgress()">Refresh Log</button>
    </div>
    <div id="step4-complete-buttons" style="display:none;margin-top:1rem;display:flex;flex-wrap:wrap;gap:.5rem">
      <a href="/db/database.php?view=librarian" target="_blank" rel="noopener noreferrer">
        <button type="button" class="btn-green">View Database →</button>
      </a>
      <button type="button" id="step4-clear-btn" onclick="clearStaging()">Clear Staging Folder</button>
    </div>
    <div id="step4-log" class="debug-log" style="display:none;margin-top:1rem"></div>
  </div>

</div></div>

<script>
'use strict';

// ── State ────────────────────────────────────────────────────────────────────
let _step = 1;
let _statusData = null;
let _proxyDismissed = false;
let _jobId = null;
let _pollTimer = null;
let _cancelRequested = false;

function el(id) { return document.getElementById(id); }
function html(id, h) { const e = el(id); if (e) e.innerHTML = h; }
function escH(s) {
  return String(s || '').replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c] || c));
}
function fmtBytes(n) {
  const v = Number(n) || 0;
  if (v >= 1073741824) return (v / 1073741824).toFixed(2) + ' GB';
  if (v >= 1048576)    return (v / 1048576).toFixed(2) + ' MB';
  if (v >= 1024)       return (v / 1024).toFixed(2) + ' KB';
  return v + ' B';
}
function fmtElapsed(ms) {
  const s = Math.max(0, Math.floor((Number(ms) || 0) / 1000));
  return Math.floor(s / 60) + 'm ' + String(s % 60).padStart(2, '0') + 's';
}

// ── Section unlock ────────────────────────────────────────────────────────────
function unlockStep(n) {
  const sec = el('step' + n + '-section');
  if (sec) sec.classList.remove('step-locked');
}

// ── Step 1: Check Ready ───────────────────────────────────────────────────────
async function checkReady() {
  const btn = el('step1-check-btn');
  btn.disabled = true;
  btn.textContent = 'Checking…';
  html('step1-checks', '<div class="muted">Contacting server…</div>');

  try {
    const r = await fetch('iphone_import_status.php?_t=' + Date.now(), { cache: 'no-store' });
    const d = await r.json().catch(() => null);

    if (!d || !d.success) {
      html('step1-checks', '<div class="alert-err">Server error: ' + escH(d && d.error ? d.error : 'Unknown') + '</div>');
      btn.disabled = false;
      btn.textContent = 'Check Ready';
      return;
    }

    _statusData = d;
    const dirOk  = d.dir_accessible === true;
    const prereqOk = d.prerequisites_ok === true;

    let h = '';
    h += '<div class="check-row"><span class="' + (dirOk ? 'check-ok' : 'check-fail') + '">' + (dirOk ? '✓' : '✗') + '</span> '
       + (dirOk ? 'Staging directory accessible' : 'Staging directory not found — is Docker running and was the installer run?') + '</div>';
    h += '<div class="check-row"><span class="' + (prereqOk ? 'check-ok' : 'check-fail') + '">' + (prereqOk ? '✓' : '✗') + '</span> '
       + (prereqOk ? 'Host prerequisites confirmed' : 'Prerequisites not confirmed — run Step B commands above, then check again') + '</div>';

    html('step1-checks', h);

    if (dirOk && prereqOk) {
      unlockStep(2);
      _step = 2;
    }

  } catch (e) {
    html('step1-checks', '<div class="alert-err">Request failed: ' + escH(e && e.message ? e.message : String(e)) + '</div>');
  }

  btn.disabled = false;
  btn.textContent = 'Check Ready';
}

// ── Step 2: Detect Staged Files ───────────────────────────────────────────────
async function detectFiles() {
  const btn = el('step2-detect-btn');
  btn.disabled = true;
  btn.textContent = 'Detecting…';
  html('step2-counts', '<div class="muted">Scanning staging directory…</div>');
  html('step2-proxy-warn', '');

  try {
    const r = await fetch('iphone_import_status.php?_t=' + Date.now(), { cache: 'no-store' });
    const d = await r.json().catch(() => null);

    if (!d || !d.success) {
      html('step2-counts', '<div class="alert-err">Server error: ' + escH(d && d.error ? d.error : 'Unknown') + '</div>');
      btn.disabled = false;
      btn.textContent = 'Detect Staged Files';
      return;
    }

    _statusData = d;
    const total = (d.video_count || 0) + (d.audio_count || 0);

    if (total === 0) {
      html('step2-counts', '<div class="alert-warn">No media files found in staging directory. Stage files from your iPhone first, then click Detect again.</div>');
      btn.disabled = false;
      btn.textContent = 'Detect Staged Files';
      return;
    }

    const sz = fmtBytes(d.total_bytes || 0);
    html('step2-counts', '<div class="alert-ok">'
      + '<strong>' + (d.video_count || 0) + ' video</strong> and <strong>' + (d.audio_count || 0) + ' audio</strong> file(s) detected &mdash; ' + escH(sz) + ' total.'
      + '</div>');

    if (d.proxy_warning && d.proxy_detail) {
      const pd = d.proxy_detail;
      html('step2-proxy-warn',
        '<div class="alert-warn">'
        + '⚠️ <strong>Possible proxy files detected:</strong> '
        + escH(pd.flagged) + ' of ' + escH(pd.sampled) + ' sampled video(s) appear low-resolution'
        + (pd.min_height ? ' (min height: ' + escH(pd.min_height) + 'px)' : '') + '. '
        + 'Your iPhone may have iCloud Storage Optimization enabled. '
        + 'Go to iPhone → Settings → Photos → <strong>Download and Keep Originals</strong> before importing. '
        + '<a href="https://gighive.app/feature_iphone_upload_catalog_caveats#icloud-storage-optimization-most-common-issue" '
        + 'target="_blank" rel="noopener noreferrer" style="color:#f59e0b">Learn more</a>'
        + '</div>'
      );
    }

    // Populate Step 3 summary
    renderStep3Summary(d);
    unlockStep(3);
    _step = 3;

  } catch (e) {
    html('step2-counts', '<div class="alert-err">Request failed: ' + escH(e && e.message ? e.message : String(e)) + '</div>');
  }

  btn.disabled = false;
  btn.textContent = 'Detect Staged Files';
}

// ── Step 3 summary ────────────────────────────────────────────────────────────
function renderStep3Summary(d) {
  if (!d) return;
  const total = (d.video_count || 0) + (d.audio_count || 0);
  const sz = fmtBytes(d.total_bytes || 0);
  html('step3-summary',
    '<div class="muted"><strong>' + total + ' file(s) to import</strong> — '
    + (d.video_count || 0) + ' video, ' + (d.audio_count || 0) + ' audio &mdash; ' + escH(sz) + ' total.</div>'
  );

  if (d.proxy_warning) {
    html('step3-proxy-banner',
      '<div class="alert-warn">'
      + '⚠️ Low-resolution proxy files may be present. Import will proceed but files may be small placeholders. '
      + '<a href="https://gighive.app/feature_iphone_upload_catalog_caveats#icloud-storage-optimization-most-common-issue" '
      + 'target="_blank" rel="noopener noreferrer" style="color:#f59e0b">Learn more</a>'
      + '</div>'
    );
  } else {
    html('step3-proxy-banner', '');
  }
}

// ── Step 4: Start Import ──────────────────────────────────────────────────────
async function startImport() {
  const orgName   = (el('org-name-input').value || '').trim() || 'iPhone';
  const eventType = el('event-type-select').value || 'band';

  el('step3-start-btn').disabled = true;
  unlockStep(4);
  _step = 4;

  el('step4-log').style.display = 'block';
  html('step4-status', '<div class="muted">Starting import…</div>');
  el('step4-stop-btn').disabled = false;
  _cancelRequested = false;

  try {
    const r = await fetch('iphone_import_server_scan.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ org_name: orgName, event_type: eventType }),
    });
    const d = await r.json().catch(() => null);

    if (!d || !d.success) {
      const msg = (d && d.message) ? d.message : 'Unknown error';
      html('step4-status', '<div class="alert-err">Failed to start import: ' + escH(msg) + '</div>');
      el('step3-start-btn').disabled = false;
      return;
    }

    _jobId = d.job_id;
    startPolling();

  } catch (e) {
    html('step4-status', '<div class="alert-err">Request failed: ' + escH(e && e.message ? e.message : String(e)) + '</div>');
    el('step3-start-btn').disabled = false;
  }
}

// ── Polling ───────────────────────────────────────────────────────────────────
const _pollStart = Date.now();

function startPolling() {
  if (_pollTimer) clearInterval(_pollTimer);
  pollProgress();
  _pollTimer = setInterval(pollProgress, 2500);
}

async function pollProgress() {
  if (!_jobId) return;
  try {
    const r = await fetch('import_manifest_status.php?job_id=' + encodeURIComponent(_jobId) + '&_t=' + Date.now(), { cache: 'no-store' });
    const d = await r.json().catch(() => null);

    const state   = (d && d.state) ? String(d.state) : 'queued';
    const elapsed = fmtElapsed(Date.now() - _pollStart);
    const msg     = (d && d.message) ? String(d.message) : state;

    let h = '<div class="muted">Job <code>' + escH(_jobId) + '</code>: '
          + escH(state) + ' (elapsed: ' + elapsed + ')</div>'
          + (d && d.steps ? renderImportStepsShared(d.steps, { showProgressBar: false, label: 'Steps:', statusIndentPx: 70 }) : '');

    html('step4-status', h);
    renderLogEntries(d);

    if (state === 'ok' || state === 'error' || state === 'canceled') {
      if (_pollTimer) { clearInterval(_pollTimer); _pollTimer = null; }
      el('step4-stop-btn').disabled = true;
      showCompleteButtons(state, d);
    }
  } catch (e) {
    html('step4-status', '<div class="alert-err">Polling error: ' + escH(e && e.message ? e.message : String(e)) + '</div>');
  }
}

async function refreshProgress() {
  await pollProgress();
}

function renderLogEntries(d) {
  if (!d || !d.steps) return;
  const box = el('step4-log');
  if (!box) return;
  let h = '';
  const steps = Array.isArray(d.steps) ? d.steps : [];
  for (const s of steps) {
    const status = String(s.status || 'pending');
    const color  = status === 'ok' ? '#22c55e' : status === 'error' ? '#ef4444' : status === 'running' ? '#60a5fa' : '#a8b3cf';
    const prog   = s.progress ? ' (' + s.progress.processed + '/' + s.progress.total + ')' : '';
    h += '<div class="debug-log-row"><span style="color:' + color + ';font-weight:700">[' + escH(status.toUpperCase()) + ']</span> '
       + escH(s.name || '') + (s.message ? ' — ' + escH(s.message) : '') + escH(prog) + '</div>';
  }
  box.innerHTML = h || '<div class="muted">No log entries yet.</div>';
}

function showCompleteButtons(state, d) {
  const resultMsg = (d && d.result && d.result.message) ? d.result.message
                  : (d && d.message) ? d.message : '';
  const isOk = (state === 'ok');
  const banner = isOk
    ? '<div class="alert-ok">✓ ' + escH(resultMsg || 'Import completed successfully.') + '</div>'
    : '<div class="alert-' + (state === 'canceled' ? 'warn' : 'err') + '">'
      + escH(state === 'canceled' ? 'Import canceled.' : ('Import finished with errors. ' + resultMsg)) + '</div>';
  html('step4-status', el('step4-status').innerHTML + banner);

  const cmpDiv = el('step4-complete-buttons');
  if (cmpDiv) cmpDiv.style.display = 'flex';
}

// ── Stop ──────────────────────────────────────────────────────────────────────
async function stopImport() {
  if (!_jobId || _cancelRequested) return;
  _cancelRequested = true;
  el('step4-stop-btn').disabled = true;
  el('step4-stop-btn').textContent = 'Cancellation requested…';
  try {
    await fetch('import_manifest_cancel.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ job_id: _jobId }),
    });
  } catch (e) { /* best-effort */ }
}

// ── Clear Staging ─────────────────────────────────────────────────────────────
async function clearStaging() {
  if (!confirm('Delete all staged files from _host_iphone/?\n\nThe .prerequisites_ok sentinel will be preserved so you do not need to re-run Step 1.')) return;
  const btn = el('step4-clear-btn');
  btn.disabled = true;
  btn.textContent = 'Clearing…';
  try {
    const r = await fetch('iphone_import_clear_staging.php', { method: 'POST', cache: 'no-store' });
    const d = await r.json().catch(() => null);
    if (d && d.success) {
      btn.textContent = '✓ Cleared (' + (d.deleted_count || 0) + ' files deleted)';
    } else {
      btn.textContent = 'Clear failed';
      btn.disabled = false;
    }
  } catch (e) {
    btn.textContent = 'Clear failed';
    btn.disabled = false;
  }
}
</script>
</body>
</html>
