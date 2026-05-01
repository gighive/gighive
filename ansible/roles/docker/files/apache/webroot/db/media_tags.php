<?php declare(strict_types=1);
/**
 * db/media_tags.php — Per-video tag review page.
 *
 * GET /db/media_tags.php?asset_id=N
 *
 * Shows all tags for a single video asset grouped by namespace.
 * Admin can confirm, reject (delete), or edit tags, and add manual tags.
 * Admin can also re-trigger the AI tagger.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Production\Api\Infrastructure\Database;

$user    = $_SERVER['PHP_AUTH_USER']
        ?? $_SERVER['REMOTE_USER']
        ?? $_SERVER['REDIRECT_REMOTE_USER']
        ?? 'Unknown';
$isAdmin = ($user === 'admin');

try {
    $pdo = Database::createFromEnv();
} catch (Throwable $e) {
    http_response_code(500);
    echo '<pre>DB error: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES) . '</pre>';
    exit;
}

$assetId = (int)($_GET['asset_id'] ?? 0);
if ($assetId <= 0) {
    http_response_code(400);
    echo '<p>asset_id required.</p>';
    exit;
}

// Fetch asset metadata
$stmt = $pdo->prepare("SELECT asset_id, source_relpath, file_type, mime_type, checksum_sha256 FROM assets WHERE asset_id=:id");
$stmt->execute([':id' => $assetId]);
$asset = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$asset) {
    http_response_code(404);
    echo '<p>Asset not found.</p>';
    exit;
}

// Fetch taggings joined to tags
$stmt = $pdo->prepare(
    "SELECT tg.id AS tagging_id, t.namespace, t.name, tg.confidence, tg.source,
            tg.start_seconds, tg.end_seconds, tg.run_id, tg.created_at
     FROM taggings tg
     JOIN tags t ON t.id = tg.tag_id
     WHERE tg.target_type='asset' AND tg.target_id=:aid
     ORDER BY t.namespace, tg.confidence DESC, t.name"
);
$stmt->execute([':aid' => $assetId]);
$taggings = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Group by namespace
$byNs = [];
foreach ($taggings as $tg) {
    $byNs[(string)$tg['namespace']][] = $tg;
}

// Fetch latest job for this asset
$stmt = $pdo->prepare(
    "SELECT id, status, created_at, updated_at, error_msg, attempts
     FROM ai_jobs WHERE target_type='asset' AND target_id=:aid AND job_type='categorize_video'
     ORDER BY created_at DESC LIMIT 1"
);
$stmt->execute([':aid' => $assetId]);
$latestJob = $stmt->fetch(PDO::FETCH_ASSOC);

$thumbUrl = ($asset['file_type'] === 'video' && !empty($asset['checksum_sha256']))
    ? '/video/thumbnails/' . rawurlencode((string)$asset['checksum_sha256']) . '.png'
    : '';

$sha = (string)($asset['checksum_sha256'] ?? '');
$ext = strtolower(pathinfo((string)($asset['source_relpath'] ?? ''), PATHINFO_EXTENSION));
$videoUrl = ($asset['file_type'] === 'video' && $sha !== '' && $ext !== '')
    ? '/video/' . rawurlencode($sha . '.' . $ext)
    : '';

$aiEnabled = filter_var(getenv('AI_WORKER_ENABLED'), FILTER_VALIDATE_BOOLEAN);

$namespaceColors = [
    'scene'       => '#1e3a5f',
    'object'      => '#1c3a1c',
    'activity'    => '#3b2700',
    'person_role' => '#3b0d3b',
];
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Tags: <?= htmlspecialchars(basename((string)$asset['source_relpath']), ENT_QUOTES) ?></title>
  <style>
    :root { font-family: system-ui, -apple-system, Segoe UI, Roboto, sans-serif; }
    body { margin:0; background:#0b1020; color:#e9eef7; }
    .wrap { max-width:860px; margin:2.5rem auto; padding:1rem; }
    h1 { margin:0 0 .25rem; font-size:1.35rem; word-break:break-all; }
    h2 { font-size:1rem; color:#a8b3cf; margin:0 0 1.25rem; }
    a { color:#60a5fa; }
    .card { background:#121a33; border:1px solid #1d2a55; border-radius:12px; padding:1.25rem; margin-bottom:1.25rem; }
    .ns-section { margin-bottom:1.5rem; }
    .ns-title { font-size:.8rem; font-weight:700; text-transform:uppercase; letter-spacing:.08em; color:#a8b3cf; margin:0 0 .6rem; }
    .chips { display:flex; flex-wrap:wrap; gap:.5rem; }
    .chip { display:inline-flex; align-items:center; gap:.3rem; padding:4px 10px; border-radius:20px;
            font-size:.82rem; border:1px solid #33427a; background:#0e1530; cursor:default; }
    .chip[data-source="human"] { border-color:#4ade80; }
    .chip .conf { font-size:.72rem; color:#a8b3cf; }
    .chip .del-btn { border:0; background:transparent; color:#f87171; cursor:pointer; font-size:.85rem; padding:0 2px; line-height:1; }
    .chip .del-btn:hover { color:#dc2626; }
    .no-tags { color:#a8b3cf; font-style:italic; }
    .thumb { width:220px; border-radius:8px; display:block; float:right; margin:0 0 1rem 1.5rem; }
    button { padding:.65rem 1.1rem; border-radius:10px; border:1px solid #3b82f6; background:transparent;
             color:#e9eef7; cursor:pointer; font-size:.9rem; }
    button:hover:not(:disabled) { background:#1e40af; }
    button:disabled { opacity:.5; cursor:not-allowed; }
    .btn-sm { padding:.4rem .8rem; font-size:.82rem; }
    .btn-danger { border-color:#dc2626; }
    .btn-danger:hover:not(:disabled) { background:#991b1b; }
    .form-row { display:flex; gap:.5rem; align-items:center; flex-wrap:wrap; margin-top:.75rem; }
    .form-row select, .form-row input[type=text] {
      background:#0e1530; color:#e9eef7; border:1px solid #33427a; border-radius:8px;
      padding:.5rem .75rem; font-size:.88rem; }
    .alert-ok  { background:#11331a; border:1px solid #1f7a3b; padding:.6rem .9rem; border-radius:8px; margin-bottom:.75rem; }
    .alert-err { background:#3b0d14; border:1px solid #b4232a; padding:.6rem .9rem; border-radius:8px; margin-bottom:.75rem; }
    .status-badge { display:inline-block; padding:2px 8px; border-radius:20px; font-size:.78rem; font-weight:600; }
    .sb-queued  { background:#1e3a5f; color:#93c5fd; }
    .sb-running { background:#1c3a1c; color:#86efac; }
    .sb-done    { background:#1a3320; color:#4ade80; }
    .sb-failed  { background:#3b1a1a; color:#f87171; }
    #msg { font-size:.88rem; min-height:1.2rem; }
    #progress-wrap { display:none; margin:.75rem 0 1rem; }
    #progress-bar-track { background:#1d2a55; border-radius:8px; height:8px; overflow:hidden; margin-bottom:.5rem; }
    #progress-bar-fill { height:100%; border-radius:8px; width:0; transition:width .4s ease; }
    #progress-bar-fill.pf-queued  { background:#3b82f6; animation:pulse-bar 1.4s ease-in-out infinite; }
    #progress-bar-fill.pf-running { background:#22c55e; animation:pulse-bar 1s ease-in-out infinite; }
    #progress-bar-fill.pf-done    { background:#4ade80; animation:none; }
    #progress-bar-fill.pf-failed  { background:#ef4444; animation:none; }
    @keyframes pulse-bar { 0%,100%{opacity:1} 50%{opacity:.45} }
    #progress-label { font-size:.82rem; color:#a8b3cf; }
    #progress-label strong { color:#e9eef7; }
  </style>
</head>
<body>
<div class="wrap">
  <p><a href="/db/database.php">← Media Library</a> &nbsp;|&nbsp; <a href="/db/tag_browser.php">Tag Browser</a>
     <?php if ($isAdmin && $aiEnabled): ?> &nbsp;|&nbsp; <a href="/admin/ai_worker.php">AI Worker</a><?php endif; ?></p>

  <h1><?= htmlspecialchars(basename((string)$asset['source_relpath']), ENT_QUOTES) ?></h1>
  <h2>asset #<?= $assetId ?> · <?= htmlspecialchars((string)$asset['file_type'], ENT_QUOTES) ?>
      · <?= htmlspecialchars((string)($asset['mime_type'] ?? ''), ENT_QUOTES) ?></h2>

  <?php if ($thumbUrl): ?>
    <?php if ($videoUrl !== ''): ?>
      <a href="<?= htmlspecialchars($videoUrl, ENT_QUOTES) ?>" target="_blank" title="Play video">
        <img src="<?= htmlspecialchars($thumbUrl, ENT_QUOTES) ?>" alt="thumbnail" class="thumb"
             onerror="this.style.display='none'">
      </a>
    <?php else: ?>
      <img src="<?= htmlspecialchars($thumbUrl, ENT_QUOTES) ?>" alt="thumbnail" class="thumb"
           onerror="this.style.display='none'">
    <?php endif; ?>
  <?php endif; ?>

  <div id="msg"></div>
  <div id="progress-wrap">
    <div id="progress-bar-track"><div id="progress-bar-fill"></div></div>
    <div id="progress-label"></div>
  </div>

  <!-- AI job status -->
  <?php if ($latestJob): ?>
    <p class="muted" style="clear:right; padding:.4rem 0;">
      Latest AI job: <span class="status-badge sb-<?= htmlspecialchars($latestJob['status'], ENT_QUOTES) ?>"><?= htmlspecialchars($latestJob['status'], ENT_QUOTES) ?></span>
      &nbsp;<?= htmlspecialchars((string)$latestJob['updated_at'], ENT_QUOTES) ?>
      <?php if ($latestJob['error_msg']): ?>
        &nbsp;— <span style="color:#f87171"><?= htmlspecialchars((string)$latestJob['error_msg'], ENT_QUOTES) ?></span>
      <?php endif; ?>
    </p>
  <?php endif; ?>

  <?php if ($isAdmin && $aiEnabled && $asset['file_type'] === 'video'): ?>
    <button id="retagBtn">Re-run AI Tagger</button>
    &nbsp;
  <?php endif; ?>

  <!-- Tags grouped by namespace -->
  <div class="card" style="clear:both; margin-top:1rem;">
    <?php if (empty($taggings)): ?>
      <p class="no-tags">No tags yet.</p>
    <?php else: ?>
      <?php foreach ($byNs as $ns => $tags): ?>
        <div class="ns-section">
          <div class="ns-title"><?= htmlspecialchars($ns, ENT_QUOTES) ?></div>
          <div class="chips">
            <?php foreach ($tags as $tg): ?>
              <span class="chip" data-source="<?= htmlspecialchars($tg['source'], ENT_QUOTES) ?>"
                    title="confidence: <?= round((float)($tg['confidence'] ?? 0), 2) ?> · source: <?= htmlspecialchars($tg['source'], ENT_QUOTES) ?>">
                <?= htmlspecialchars($tg['name'], ENT_QUOTES) ?>
                <span class="conf"><?= round((float)($tg['confidence'] ?? 0) * 100) ?>%</span>
                <?php if ($isAdmin): ?>
                  <button class="del-btn" data-tagging-id="<?= (int)$tg['tagging_id'] ?>"
                          title="Remove tag" aria-label="Remove">✕</button>
                <?php endif; ?>
              </span>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>

    <!-- Add manual tag form (admin only) -->
    <?php if ($isAdmin): ?>
      <div style="margin-top:1rem; border-top:1px solid #1d2a55; padding-top:1rem;">
        <div class="ns-title">Add Manual Tag</div>
        <div class="form-row">
          <select id="newNs">
            <option value="scene">scene</option>
            <option value="object">object</option>
            <option value="activity">activity</option>
            <option value="person_role">person_role</option>
            <option value="__other__">other…</option>
          </select>
          <input type="text" id="newNsCustom" placeholder="custom_namespace" style="width:130px;display:none;">
          <input type="text" id="newName" placeholder="tag_name" style="width:160px;" list="tagNameSuggestions" autocomplete="off">
          <datalist id="tagNameSuggestions"></datalist>
          <button class="btn-sm" id="addTagBtn">Add Tag</button>
        </div>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php if ($isAdmin): ?>
<script>
const assetId = <?= $assetId ?>;

function setMsg(text, ok) {
    const el = document.getElementById('msg');
    el.style.color = ok ? '#4ade80' : '#f87171';
    el.textContent = text;
}

const STAGE_META = {
  queued:  { pct: 15, cls: 'pf-queued',  label: 'Queued — waiting for worker to pick up the job…' },
  running: { pct: 60, cls: 'pf-running', label: 'Worker is active — extracting frames and calling AI vision API… (typically 30–90 s)' },
  done:    { pct: 100, cls: 'pf-done',   label: 'Done! Reloading page to show new tags…' },
  failed:  { pct: 100, cls: 'pf-failed', label: 'Job failed — see error below.' },
};

let pollTimer = null;

function showProgress(status, extraLabel) {
    const wrap  = document.getElementById('progress-wrap');
    const fill  = document.getElementById('progress-bar-fill');
    const label = document.getElementById('progress-label');
    const meta  = STAGE_META[status] || { pct: 5, cls: 'pf-queued', label: 'Pending…' };
    wrap.style.display = 'block';
    fill.className = 'pf-' + status;
    fill.style.width = meta.pct + '%';
    label.innerHTML = '<strong>' + status.toUpperCase() + '</strong> — ' + (extraLabel || meta.label);
}

async function pollJob(jobId) {
    try {
        const r = await fetch('/api/ai_jobs.php?id=' + jobId);
        if (!r.ok) { clearInterval(pollTimer); return; }
        const d = await r.json();
        const job = d.job;
        const status = job.status || 'queued';
        const errMsg = job.error_msg ? ' Error: ' + job.error_msg : '';
        showProgress(status, status === 'failed' ? ('Job failed.' + errMsg) : null);
        if (status === 'done') {
            clearInterval(pollTimer);
            setTimeout(() => location.reload(), 1800);
        } else if (status === 'failed') {
            clearInterval(pollTimer);
            setMsg('AI Tagger failed.' + errMsg, false);
        }
    } catch(e) { /* network hiccup — keep polling */ }
}

// Re-run tagger
const retagBtn = document.getElementById('retagBtn');
if (retagBtn) {
    retagBtn.addEventListener('click', async () => {
        retagBtn.disabled = true;
        setMsg('');
        showProgress('queued', 'Sending request to server…');
        try {
            const r = await fetch('/api/ai_jobs.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({job_type:'categorize_video', target_type:'asset', target_id: assetId}),
            });
            const d = await r.json();
            if (!r.ok) {
                setMsg('Error: ' + (d.error || r.status), false);
                document.getElementById('progress-wrap').style.display = 'none';
                retagBtn.disabled = false;
                return;
            }
            const jobId = d.job_id || null;
            showProgress('queued');
            if (jobId) {
                clearInterval(pollTimer);
                pollTimer = setInterval(() => pollJob(jobId), 3000);
                pollJob(jobId);
            } else {
                setMsg('Job already active — check AI Worker for status.', true);
            }
        } catch(e) {
            setMsg('Network error: ' + e.message, false);
            document.getElementById('progress-wrap').style.display = 'none';
            retagBtn.disabled = false;
        }
    });
}

// Delete tag chip
document.querySelectorAll('.del-btn').forEach(btn => {
    btn.addEventListener('click', async (e) => {
        e.stopPropagation();
        const id = btn.dataset.taggingId;
        if (!confirm('Remove this tag?')) return;
        try {
            const r = await fetch('/api/taggings.php?id=' + id, {method: 'DELETE'});
            const d = await r.json();
            if (!r.ok) { setMsg('Error: ' + (d.error || r.status), false); return; }
            btn.closest('.chip').remove();
            setMsg('Tag removed.', true);
        } catch(e) { setMsg('Network error: ' + e.message, false); }
    });
});

// Namespace selector: show/hide custom input and refresh autocomplete hints
document.getElementById('newNs').addEventListener('change', async function() {
    const customInput = document.getElementById('newNsCustom');
    const dl = document.getElementById('tagNameSuggestions');
    const isOther = this.value === '__other__';
    customInput.style.display = isOther ? '' : 'none';
    if (isOther) { customInput.focus(); return; }
    dl.innerHTML = '';
    try {
        const r = await fetch('/api/tags.php?namespace=' + encodeURIComponent(this.value));
        if (r.ok) {
            const data = await r.json();
            (data.tags || []).forEach(t => {
                const opt = document.createElement('option');
                opt.value = t.name;
                document.getElementById('tagNameSuggestions').appendChild(opt);
            });
        }
    } catch(e) { /* ignore — hints are optional */ }
});

// Add manual tag
const addTagBtn = document.getElementById('addTagBtn');
if (addTagBtn) {
    addTagBtn.addEventListener('click', async () => {
        const nsSel = document.getElementById('newNs');
        const ns = nsSel.value === '__other__'
            ? document.getElementById('newNsCustom').value.trim()
            : nsSel.value;
        const name = document.getElementById('newName').value.trim();
        if (!ns)   { setMsg('Enter a namespace.', false); return; }
        if (!name) { setMsg('Enter a tag name.', false); return; }
        addTagBtn.disabled = true;
        try {
            const r = await fetch('/api/taggings.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({target_type:'asset', target_id:assetId, namespace:ns, name:name, source:'human', confidence:1.0}),
            });
            const d = await r.json();
            if (!r.ok) { setMsg('Error: ' + (d.error || r.status), false); }
            else { setMsg('Tag added. Reload to see.', true); document.getElementById('newName').value = ''; }
        } catch(e) { setMsg('Network error: ' + e.message, false); }
        finally { addTagBtn.disabled = false; }
    });
}
</script>
<?php endif; ?>
</body>
</html>
