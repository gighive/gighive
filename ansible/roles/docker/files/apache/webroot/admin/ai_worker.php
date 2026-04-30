<?php declare(strict_types=1);
/**
 * admin/ai_worker.php — AI worker status + on-demand job trigger.
 */

$user = $_SERVER['PHP_AUTH_USER']
    ?? $_SERVER['REMOTE_USER']
    ?? $_SERVER['REDIRECT_REMOTE_USER']
    ?? null;

if ($user !== 'admin') {
    http_response_code(403);
    echo '<h1>Forbidden</h1><p>Admin access required.</p>';
    exit;
}

require_once __DIR__ . '/../vendor/autoload.php';

use Production\Api\Infrastructure\Database;

$aiEnabled = filter_var(getenv('AI_WORKER_ENABLED'), FILTER_VALIDATE_BOOLEAN);
$openaiModel = getenv('OPENAI_MODEL') ?: 'gpt-4.1';
$llmProvider = getenv('LLM_PROVIDER') ?: 'openai';

$pdo = null;
$stats = ['total_assets' => 0, 'video_assets' => 0, 'tagged_assets' => 0, 'queued' => 0, 'running' => 0, 'done' => 0, 'failed' => 0];
$recentJobs = [];
$dbError = '';

try {
    $pdo = Database::createFromEnv();

    $row = $pdo->query("SELECT COUNT(*) as n FROM assets")->fetch(PDO::FETCH_ASSOC);
    $stats['total_assets'] = (int)($row['n'] ?? 0);

    $row = $pdo->query("SELECT COUNT(*) as n FROM assets WHERE file_type='video'")->fetch(PDO::FETCH_ASSOC);
    $stats['video_assets'] = (int)($row['n'] ?? 0);

    $row = $pdo->query(
        "SELECT COUNT(DISTINCT tg.target_id) as n FROM taggings tg WHERE tg.target_type='asset'"
    )->fetch(PDO::FETCH_ASSOC);
    $stats['tagged_assets'] = (int)($row['n'] ?? 0);

    foreach (['queued', 'running', 'done', 'failed'] as $st) {
        $r = $pdo->prepare("SELECT COUNT(*) as n FROM ai_jobs WHERE status=:s AND job_type='categorize_video'");
        $r->execute([':s' => $st]);
        $stats[$st] = (int)($r->fetch(PDO::FETCH_ASSOC)['n'] ?? 0);
    }

    $stmt = $pdo->query(
        "SELECT j.id, j.status, j.target_id, j.attempts, j.created_at, j.updated_at, j.error_msg,
                a.source_relpath
         FROM ai_jobs j
         LEFT JOIN assets a ON a.asset_id = j.target_id AND j.target_type='asset'
         WHERE j.job_type='categorize_video'
         ORDER BY j.updated_at DESC LIMIT 50"
    );
    $recentJobs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $activeStmt = $pdo->query(
        "SELECT id FROM ai_jobs WHERE job_type='categorize_video' AND status IN ('queued','running') ORDER BY id"
    );
    $activeJobIds = array_map('intval', array_column($activeStmt->fetchAll(PDO::FETCH_ASSOC), 'id'));
} catch (Throwable $e) {
    $dbError = $e->getMessage();
    $activeJobIds = [];
}

$untaggedCount = $stats['video_assets'] - $stats['tagged_assets'];
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Admin: AI Video Tagger</title>
  <style>
    :root { font-family: system-ui, -apple-system, Segoe UI, Roboto, sans-serif; }
    body { margin:0; background:#0b1020; color:#e9eef7; }
    .wrap { max-width:960px; margin:3rem auto; padding:1rem; }
    h1 { margin:0 0 1.5rem; }
    a { color:#60a5fa; }
    .card { background:#121a33; border:1px solid #1d2a55; border-radius:12px; padding:1.25rem; margin-bottom:1.25rem; }
    .row { display:flex; gap:1rem; flex-wrap:wrap; margin-bottom:1rem; }
    .stat { background:#0e1530; border:1px solid #33427a; border-radius:10px; padding:.75rem 1.25rem; flex:1 1 130px; }
    .stat .num { font-size:1.8rem; font-weight:700; }
    .stat .num a { color:inherit; text-decoration:none; }
    .stat .num a:hover { text-decoration:underline; }
    .stat .lbl { font-size:.8rem; color:#a8b3cf; }
    label { font-weight:600; display:block; margin-bottom:.3rem; }
    button { padding:.7rem 1.2rem; border-radius:10px; border:1px solid #3b82f6; background:transparent; color:#e9eef7; cursor:pointer; font-size:.95rem; }
    button:hover:not(:disabled) { background:#1e40af; }
    button:disabled { opacity:.5; cursor:not-allowed; }
    .btn-danger { border-color:#dc2626; }
    .btn-danger:hover:not(:disabled) { background:#991b1b; }
    .alert-ok  { background:#11331a; border:1px solid #1f7a3b; padding:.75rem 1rem; border-radius:8px; margin-bottom:.75rem; }
    .alert-err { background:#3b0d14; border:1px solid #b4232a; padding:.75rem 1rem; border-radius:8px; margin-bottom:.75rem; }
    .alert-warn { background:#3b2700; border:1px solid #b46000; padding:.75rem 1rem; border-radius:8px; margin-bottom:.75rem; }
    .muted { color:#a8b3cf; font-size:.9rem; }
    .table-scroll { overflow-x:auto; -webkit-overflow-scrolling:touch; }
    table { width:100%; border-collapse:collapse; font-size:.88rem; min-width:600px; }
    th,td { border:1px solid #1d2a55; padding:7px 10px; text-align:left; vertical-align:top; white-space:nowrap; }
    td.trunc, th:nth-child(3), th:nth-child(7) { white-space:normal; }
    th { background:#0e1530; }
    .badge { display:inline-block; padding:2px 8px; border-radius:20px; font-size:.78rem; font-weight:600; }
    .badge-queued  { background:#1e3a5f; color:#93c5fd; }
    .badge-running { background:#1c3a1c; color:#86efac; }
    .badge-done    { background:#1a3320; color:#4ade80; }
    .badge-failed  { background:#3b1a1a; color:#f87171; }
    .trunc { max-width:280px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
    #statusMsg  { font-size:.9rem; min-height:1.4rem; }
    #retagStatusMsg { font-size:.9rem; min-height:1.4rem; }
    #retag-progress-wrap { display:none; margin:.75rem 0 0; }
    #retag-bar-track { background:#1d2a55; border-radius:8px; height:8px; overflow:hidden; margin-bottom:.5rem; }
    #retag-bar-fill { height:100%; border-radius:8px; width:0; transition:width .5s ease; }
    #retag-bar-fill.pf-active { background:#f59e0b; animation:pulse-bar 1s ease-in-out infinite; }
    #retag-bar-fill.pf-done   { background:#4ade80; animation:none; }
    #retag-bar-fill.pf-failed { background:#ef4444; animation:none; }
    #retag-progress-label { font-size:.82rem; color:#a8b3cf; }
    #retag-progress-label strong { color:#e9eef7; }
    #bulk-progress-wrap { display:none; margin:.75rem 0 0; }
    #bulk-bar-track { background:#1d2a55; border-radius:8px; height:8px; overflow:hidden; margin-bottom:.5rem; }
    #bulk-bar-fill { height:100%; border-radius:8px; width:0; transition:width .5s ease; }
    #bulk-bar-fill.pf-active { background:#22c55e; animation:pulse-bar 1s ease-in-out infinite; }
    #bulk-bar-fill.pf-done   { background:#4ade80; animation:none; }
    #bulk-bar-fill.pf-failed { background:#ef4444; animation:none; }
    @keyframes pulse-bar { 0%,100%{opacity:1} 50%{opacity:.45} }
    #bulk-progress-label { font-size:.82rem; color:#a8b3cf; }
    #bulk-progress-label strong { color:#e9eef7; }
    .failed-list-wrap { margin:.5rem 0 0; }
    .failed-list-wrap details summary { font-size:.82rem; color:#f87171; cursor:pointer; user-select:none; }
    .failed-list-wrap details summary:hover { color:#fca5a5; }
    .failed-list-wrap details[open] summary { margin-bottom:.35rem; }
    .failed-list-items { background:#1a0f0f; border-radius:6px; padding:.5rem .75rem; list-style:none; margin:0; }
    .failed-list-items li { color:#fca5a5; padding:3px 0; font-size:.8rem; }
    .failed-list-items li a { color:#fca5a5; }
    .failed-list-items li .ferr { color:#6b7280; font-size:.75rem; margin-left:.5rem; }
    .stop-wrap { display:none; margin:.4rem 0 0; }
    .btn-stop { background:transparent; border:1px solid #7f1d1d; color:#f87171; padding:3px 14px; border-radius:6px; font-size:.78rem; cursor:pointer; }
    .btn-stop:hover { background:#7f1d1d33; }
  </style>
</head>
<body>
<div class="wrap">
  <h1>AI Video Tagger</h1>
  <p><a href="/admin/admin_system.php">← System</a> &nbsp;|&nbsp; <a href="/db/ai_tags.php">Browse All Tags</a></p>

  <?php if ($dbError): ?>
    <div class="alert-err">DB error: <?= htmlspecialchars($dbError, ENT_QUOTES) ?></div>
  <?php endif; ?>

  <?php if (!$aiEnabled): ?>
    <div class="alert-warn">
      <strong>AI worker is disabled.</strong>
      Set <code>ai_worker_enabled: true</code> in group_vars and re-run Ansible to enable.
    </div>
  <?php endif; ?>

  <!-- Stats -->
  <div class="card">
    <div class="row">
      <div class="stat"><div class="num"><a href="/db/database.php"><?= $stats['video_assets'] ?></a></div><div class="lbl">Video assets</div></div>
      <div class="stat"><div class="num"><a href="/db/ai_tags.php"><?= $stats['tagged_assets'] ?></a></div><div class="lbl">Assets with tags</div></div>
      <div class="stat"><div class="num"><a href="/db/database.php"><?= max(0, $untaggedCount) ?></a></div><div class="lbl">Untagged videos</div></div>
      <div class="stat"><div class="num"><a href="#recent-jobs?status=queued"><?= $stats['queued'] ?></a></div><div class="lbl">Queued jobs</div></div>
      <div class="stat"><div class="num"><a href="#recent-jobs?status=running"><?= $stats['running'] ?></a></div><div class="lbl">Running</div></div>
      <div class="stat"><div class="num"><a href="#recent-jobs?status=done"><?= $stats['done'] ?></a></div><div class="lbl">Done</div></div>
      <div class="stat"><div class="num"><a href="#recent-jobs?status=failed"><?= $stats['failed'] ?></a></div><div class="lbl">Failed</div></div>
    </div>
    <div class="muted">Provider: <strong><?= htmlspecialchars($llmProvider, ENT_QUOTES) ?></strong> · Model: <strong><?= htmlspecialchars($openaiModel, ENT_QUOTES) ?></strong></div>
  </div>

  <!-- Actions -->
  <div class="card">
    <label>Bulk Enqueue</label>
    <p class="muted">
      Enqueue <strong>all untagged video assets</strong> (<?= max(0, $untaggedCount) ?>) for AI tagging.
      Jobs are idempotent — assets already queued/running/done are skipped.
    </p>
    <button id="enqueueAllBtn" <?= !$aiEnabled ? 'disabled' : '' ?>>
      Tag <?= max(0, $untaggedCount) ?> Untagged Assets
    </button>
    <div id="statusMsg"></div>
    <div id="bulk-progress-wrap">
      <div id="bulk-bar-track"><div id="bulk-bar-fill"></div></div>
      <div id="bulk-progress-label"></div>
    </div>
    <div id="bulk-stop-wrap" class="stop-wrap"><button class="btn-stop" id="bulk-stop-btn">&#9632; Stop remaining jobs</button></div>
    <div id="bulk-failed-list" class="failed-list-wrap"></div>

    <hr style="border:none;border-top:1px solid #1d2a55;margin:1.25rem 0;">
    <p class="muted" style="margin:.25rem 0 .75rem;">
      Re-tag <strong>all <?= $stats['video_assets'] ?> video assets</strong> — including already-tagged ones.
      Use this after a model or configuration change. Active (queued/running) jobs are not duplicated.
    </p>
    <button id="retagAllBtn" class="btn-danger" <?= !$aiEnabled ? 'disabled' : '' ?>>Force Re-tag All</button>
    <div id="retagStatusMsg"></div>
    <div id="retag-progress-wrap">
      <div id="retag-bar-track"><div id="retag-bar-fill"></div></div>
      <div id="retag-progress-label"></div>
    </div>
    <div id="retag-stop-wrap" class="stop-wrap"><button class="btn-stop" id="retag-stop-btn">&#9632; Stop remaining jobs</button></div>
    <div id="retag-failed-list" class="failed-list-wrap"></div>
  </div>

  <!-- Recent jobs -->
  <div class="card" id="recent-jobs">
    <label>Recent Jobs (last 50)</label>
    <?php if (empty($recentJobs)): ?>
      <p class="muted">No jobs yet.</p>
    <?php else: ?>
      <div class="table-scroll">
      <table>
        <thead>
          <tr>
            <th>ID</th><th>Status</th><th>Asset</th><th>Attempts</th>
            <th>Created</th><th>Updated</th><th>Error</th><th>Tags</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($recentJobs as $j): ?>
            <?php $assetId = (int)$j['target_id']; ?>
            <tr>
              <td><?= (int)$j['id'] ?></td>
              <td><span class="badge badge-<?= htmlspecialchars($j['status'], ENT_QUOTES) ?>"><?= htmlspecialchars($j['status'], ENT_QUOTES) ?></span></td>
              <td class="trunc">
                <a href="/db/media_tags.php?asset_id=<?= $assetId ?>">
                  <?= htmlspecialchars($j['source_relpath'] ?? "asset $assetId", ENT_QUOTES) ?>
                </a>
              </td>
              <td><?= (int)$j['attempts'] ?></td>
              <td><?= htmlspecialchars((string)($j['created_at'] ?? ''), ENT_QUOTES) ?></td>
              <td><?= htmlspecialchars((string)($j['updated_at'] ?? ''), ENT_QUOTES) ?></td>
              <td class="trunc"><?= htmlspecialchars((string)($j['error_msg'] ?? ''), ENT_QUOTES) ?></td>
              <td><a href="/db/media_tags.php?asset_id=<?= $assetId ?>">view</a></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      </div>
    <?php endif; ?>
  </div>
</div>

<script>
// ── Shared helpers ────────────────────────────────────────────────────────────
function setMsg(el, text, ok) {
    if (!el) return;
    el.style.color = ok === null ? '' : (ok ? '#4ade80' : '#f87171');
    el.textContent = text;
}

function makeProgressController(prefix) {
    let total = 0;
    let timer = null;
    let jobIdSet = null;

    function show(pct, cls, labelHtml) {
        const wrap  = document.getElementById(prefix + '-progress-wrap');
        const fill  = document.getElementById(prefix + '-bar-fill');
        const label = document.getElementById(prefix + '-progress-label');
        if (!wrap) return;
        wrap.style.display = 'block';
        fill.className = cls;
        fill.style.width = Math.min(100, pct) + '%';
        label.innerHTML = labelHtml;
    }

    function setJobIds(ids) {
        jobIdSet = ids && ids.length ? new Set(ids) : null;
    }

    function showStopBtn(visible) {
        const sw = document.getElementById(prefix + '-stop-wrap');
        if (sw) sw.style.display = visible ? 'block' : 'none';
    }

    function hide() {
        const wrap = document.getElementById(prefix + '-progress-wrap');
        if (wrap) wrap.style.display = 'none';
        const fl = document.getElementById(prefix + '-failed-list');
        if (fl) fl.innerHTML = '';
        showStopBtn(false);
        jobIdSet = null;
    }

    async function stop() {
        if (!jobIdSet || jobIdSet.size === 0) return;
        if (!confirm('Stop remaining queued jobs?\nAlready-running jobs will finish. Completed tags are kept.')) return;
        const ids = Array.from(jobIdSet);
        clearInterval(timer);
        showStopBtn(false);
        try {
            const r = await fetch('/api/ai_jobs.php?action=cancel_jobs', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({job_ids: ids}),
            });
            const d = await r.json();
            if (r.ok) {
                const n = d.cancelled || 0;
                show(100, 'pf-failed', `<strong>STOPPED</strong> — ${n} queued job(s) removed. Reloading…`);
                setTimeout(() => location.reload(), 2000);
            } else {
                show(0, 'pf-failed', `<strong>STOP FAILED</strong> — ${d.error || 'unknown error'}`);
                showStopBtn(true);
            }
        } catch(e) {
            show(0, 'pf-failed', `<strong>STOP FAILED</strong> — network error`);
            showStopBtn(true);
        }
    }

    function showFailed(failedJobs) {
        const el = document.getElementById(prefix + '-failed-list');
        if (!el) return;
        if (failedJobs.length === 0) { el.innerHTML = ''; return; }
        const items = failedJobs.map(j => {
            const err = (j.error_msg || 'no error recorded').substring(0, 160);
            return `<li><a href="/db/media_tags.php?asset_id=${j.target_id}">Asset #${j.target_id}</a><span class="ferr">${err}</span></li>`;
        }).join('');
        el.innerHTML = `<details open><summary>${failedJobs.length} failed job(s) — expand to see details</summary><ul class="failed-list-items">${items}</ul></details>`;
    }

    async function poll() {
        try {
            const r = await fetch('/api/ai_jobs.php?limit=500');
            if (!r.ok) return;
            const d = await r.json();
            const allJobs = d.jobs || [];
            const jobs = jobIdSet
                ? allJobs.filter(j => jobIdSet.has(Number(j.id)))
                : allJobs.filter(j => j.job_type === 'categorize_video');
            let queued = 0, running = 0, done = 0, failed = 0;
            jobs.forEach(j => {
                if (j.status === 'queued')       queued++;
                else if (j.status === 'running') running++;
                else if (j.status === 'done')    done++;
                else if (j.status === 'failed')  failed++;
            });
            const failedJobs = jobs.filter(j => j.status === 'failed');
            const active   = queued + running;
            const finished = done + failed;
            const t        = Math.max(total, active + finished);
            const pct      = t > 0 ? (finished / t * 100) : 0;
            const failNote = failed > 0 ? ` · <span style="color:#f87171">${failed} failed</span>` : '';
            showFailed(failedJobs);
            if (active === 0) {
                clearInterval(timer);
                showStopBtn(false);
                show(100, 'pf-done', `<strong>ALL DONE</strong> — ${done} tagged, ${failed} failed. Reloading…`);
                setTimeout(() => location.reload(), 2000);
            } else {
                show(pct, 'pf-active',
                    `<strong>${running > 0 ? 'RUNNING' : 'QUEUED'}</strong> — ${done} of ${t} complete · ${running} running · ${queued} queued${failNote}`);
            }
        } catch(e) { /* network hiccup — keep polling */ }
    }

    function start(enqueued) {
        total = enqueued;
        clearInterval(timer);
        showStopBtn(true);
        const btn = document.getElementById(prefix + '-stop-btn');
        if (btn) {
            const fresh = btn.cloneNode(true);
            btn.parentNode.replaceChild(fresh, btn);
            fresh.addEventListener('click', stop);
        }
        timer = setInterval(poll, 5000);
        poll();
    }

    return { show, hide, start, setJobIds };
}

// ── Enqueue All Untagged ──────────────────────────────────────────────────────
const enqueueBtn = document.getElementById('enqueueAllBtn');
const statusMsg  = document.getElementById('statusMsg');
const bulkCtrl   = makeProgressController('bulk');

if (enqueueBtn) {
    enqueueBtn.addEventListener('click', async () => {
        enqueueBtn.disabled = true;
        setMsg(statusMsg, '', null);
        bulkCtrl.show(5, 'pf-active', '<strong>ENQUEUEING</strong> — sending jobs to queue…');
        try {
            const resp = await fetch('/api/ai_jobs.php?action=enqueue_all_untagged', {method: 'POST'});
            const data = await resp.json();
            if (!resp.ok) {
                setMsg(statusMsg, 'Error: ' + (data.error || resp.status), false);
                bulkCtrl.hide();
                enqueueBtn.disabled = false;
                return;
            }
            const enqueued = data.enqueued || 0;
            if (enqueued === 0) {
                setMsg(statusMsg, 'No untagged videos found — nothing enqueued.', true);
                bulkCtrl.hide();
                enqueueBtn.disabled = false;
                return;
            }
            bulkCtrl.show(5, 'pf-active',
                `<strong>QUEUED</strong> — ${enqueued} job(s) enqueued · waiting for worker to pick them up…`);
            bulkCtrl.setJobIds(data.job_ids || []);
            bulkCtrl.start(enqueued);
        } catch (e) {
            setMsg(statusMsg, 'Network error: ' + e.message, false);
            bulkCtrl.hide();
            enqueueBtn.disabled = false;
        }
    });
}

// ── Force Re-tag All ─────────────────────────────────────────────────────────
const retagAllBtn = document.getElementById('retagAllBtn');
const retagMsgEl  = document.getElementById('retagStatusMsg');
const retagCtrl   = makeProgressController('retag');

if (retagAllBtn) {
    retagAllBtn.addEventListener('click', async () => {
        if (!confirm(`Re-tag all ${retagAllBtn.closest('.card').querySelectorAll('strong')[1]?.textContent || 'all'} videos? This will overwrite existing tags.`)) return;
        retagAllBtn.disabled = true;
        setMsg(retagMsgEl, '', null);
        retagCtrl.show(5, 'pf-active', '<strong>ENQUEUEING</strong> — sending jobs to queue…');
        try {
            const resp = await fetch('/api/ai_jobs.php?action=retag_all', {method: 'POST'});
            const data = await resp.json();
            if (!resp.ok) {
                setMsg(retagMsgEl, 'Error: ' + (data.error || resp.status), false);
                retagCtrl.hide();
                retagAllBtn.disabled = false;
                return;
            }
            const enqueued = data.enqueued || 0;
            if (enqueued === 0) {
                setMsg(retagMsgEl, 'Nothing to enqueue — all videos already have active jobs.', true);
                retagCtrl.hide();
                retagAllBtn.disabled = false;
                return;
            }
            retagCtrl.show(5, 'pf-active',
                `<strong>QUEUED</strong> — ${enqueued} job(s) enqueued · waiting for worker…`);
            retagCtrl.setJobIds(data.job_ids || []);
            retagCtrl.start(enqueued);
        } catch (e) {
            setMsg(retagMsgEl, 'Network error: ' + e.message, false);
            retagCtrl.hide();
            retagAllBtn.disabled = false;
        }
    });
}

// ── Auto-recover in-progress run on page load ─────────────────────────────────
(function() {
    const activeIds = <?= json_encode(array_values($activeJobIds)) ?>;
    if (activeIds.length === 0) return;
    setMsg(retagMsgEl,
        `⚠ A previous Force Re-tag job is still running (${activeIds.length} job(s) active). ` +
        `This was started before the last page load or container restart.`,
        null);
    retagMsgEl.style.color = '#f59e0b';
    retagCtrl.setJobIds(activeIds);
    retagCtrl.show(5, 'pf-active',
        `<strong>RESUMING</strong> — ${activeIds.length} active job(s) detected · polling for progress…`);
    retagCtrl.start(activeIds.length);
})();

// Status filter: highlight rows matching ?status=X in the hash
(function() {
    const hash = window.location.hash; // e.g. #recent-jobs?status=failed
    const m = hash.match(/[?&]status=([a-z]+)/);
    if (!m) return;
    const target = m[1];
    const card = document.getElementById('recent-jobs');
    if (!card) return;
    card.scrollIntoView({ behavior: 'smooth', block: 'start' });
    card.querySelectorAll('tbody tr').forEach(row => {
        const badge = row.querySelector('.badge');
        if (!badge) return;
        if (badge.textContent.trim() !== target) {
            row.style.opacity = '0.3';
        } else {
            row.style.fontWeight = '600';
        }
    });
    const label = card.querySelector('label');
    if (label) {
        const pill = document.createElement('span');
        pill.style.cssText = 'margin-left:.6rem;padding:2px 10px;border-radius:20px;font-size:.75rem;font-weight:600;background:#1e3a5f;color:#93c5fd;vertical-align:middle;';
        pill.textContent = 'filtered: ' + target;
        const clear = document.createElement('a');
        clear.href = '#recent-jobs';
        clear.style.cssText = 'margin-left:.4rem;font-size:.75rem;color:#60a5fa;';
        clear.textContent = '✕ clear';
        clear.addEventListener('click', () => {
            card.querySelectorAll('tbody tr').forEach(r => { r.style.opacity=''; r.style.fontWeight=''; });
            pill.remove();
        });
        pill.appendChild(clear);
        label.appendChild(pill);
    }
})();

</script>
</body>
</html>
