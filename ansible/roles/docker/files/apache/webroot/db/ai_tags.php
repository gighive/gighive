<?php declare(strict_types=1);
/**
 * db/ai_tags.php — Cross-asset tag browser.
 *
 * GET /db/ai_tags.php                   — list all tags with usage counts
 * GET /db/ai_tags.php?namespace=scene   — filter by namespace
 * GET /db/ai_tags.php?name=outdoor_concert — assets carrying a specific tag
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

$filterNs   = trim($_GET['namespace'] ?? '');
$filterName = trim($_GET['name'] ?? '');
$filterNs   = preg_replace('/[^a-z_]/', '', $filterNs);
$filterName = preg_replace('/[^a-z0-9_ ]/', '', $filterName);

// When a specific tag name is requested, show which assets carry it
$tagAssets = [];
$tagRow    = null;
if ($filterName !== '') {
    $nsParam = $filterNs !== '' ? $filterNs : null;
    $stmt = $pdo->prepare(
        "SELECT t.id, t.namespace, t.name FROM tags t
         WHERE t.name=:nm" . ($nsParam !== null ? " AND t.namespace=:ns" : '') . " LIMIT 1"
    );
    $params = [':nm' => $filterName];
    if ($nsParam !== null) $params[':ns'] = $nsParam;
    $stmt->execute($params);
    $tagRow = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($tagRow) {
        $stmt = $pdo->prepare(
            "SELECT tg.target_id AS asset_id, tg.confidence, tg.source, tg.created_at,
                    tg.start_seconds, tg.end_seconds,
                    a.source_relpath, a.file_type, a.checksum_sha256, a.file_ext
             FROM taggings tg
             LEFT JOIN assets a ON a.asset_id = tg.target_id AND tg.target_type='asset'
             WHERE tg.tag_id=:tid AND tg.target_type='asset'
             ORDER BY tg.confidence DESC, a.source_relpath"
        );
        $stmt->execute([':tid' => $tagRow['id']]);
        $tagAssets = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

// Tag list with usage counts grouped by namespace
$params = [];
$where  = '';
if ($filterNs !== '') {
    $where = 'WHERE t.namespace=:ns';
    $params[':ns'] = $filterNs;
}
$stmt = $pdo->prepare(
    "SELECT t.id, t.namespace, t.name,
            COUNT(tg.id) AS usage_count,
            SUM(CASE WHEN tg.source='human' THEN 1 ELSE 0 END) AS human_count,
            SUM(CASE WHEN tg.source='ai'    THEN 1 ELSE 0 END) AS ai_count
     FROM tags t
     LEFT JOIN taggings tg ON tg.tag_id = t.id AND tg.target_type='asset'
     $where
     GROUP BY t.id, t.namespace, t.name
     ORDER BY t.namespace, usage_count DESC, t.name"
);
$stmt->execute($params);
$allTags = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Group by namespace for the sidebar
$tagsByNs = [];
foreach ($allTags as $t) {
    $tagsByNs[(string)$t['namespace']][] = $t;
}

$namespaces = ['scene', 'object', 'activity', 'person_role'];
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Tag Browser<?= $filterName !== '' ? ' — ' . htmlspecialchars($filterName, ENT_QUOTES) : '' ?></title>
  <style>
    :root { font-family: system-ui, -apple-system, Segoe UI, Roboto, sans-serif; }
    body { margin:0; background:#0b1020; color:#e9eef7; }
    .wrap { max-width:1100px; margin:2.5rem auto; padding:1rem; display:flex; gap:1.5rem; align-items:flex-start; }
    .sidebar { width:220px; flex-shrink:0; }
    .main { flex:1 1 0; min-width:0; }
    h1 { margin:0 0 1rem; font-size:1.3rem; }
    a { color:#60a5fa; text-decoration:none; }
    a:hover { text-decoration:underline; }
    .card { background:#121a33; border:1px solid #1d2a55; border-radius:12px; padding:1rem; margin-bottom:1rem; }
    .ns-title { font-size:.75rem; font-weight:700; text-transform:uppercase; letter-spacing:.08em;
                color:#a8b3cf; margin:0 0 .5rem; }
    .ns-sort-btn { all:unset; cursor:pointer; color:#a8b3cf; display:flex; align-items:center; gap:.3rem;
                   font-size:.75rem; font-weight:700; text-transform:uppercase; letter-spacing:.08em; }
    .ns-sort-btn:hover { color:#e9eef7; }
    .sort-ind { font-style:normal; opacity:.6; font-size:.8em; }
    .tag-link { display:flex; justify-content:space-between; align-items:center;
                padding:4px 8px; border-radius:6px; font-size:.85rem; color:#e9eef7; }
    .tag-link:hover { background:#1e3a5f; text-decoration:none; }
    .tag-link.active { background:#1e40af; font-weight:600; }
    .tag-link .cnt { font-size:.75rem; color:#a8b3cf; }
    .ns-filter { display:flex; gap:.5rem; flex-wrap:wrap; margin-bottom:.75rem; }
    .ns-btn { padding:4px 12px; border-radius:20px; border:1px solid #33427a; background:transparent;
              color:#e9eef7; cursor:pointer; font-size:.82rem; }
    .ns-btn.active { background:#1e40af; border-color:#3b82f6; }
    .ns-btn:hover:not(.active) { background:#1e3a5f; }
    .ns-btn-scene    { border-color:#1e3a5f; color:#93c5fd; }
    .ns-btn-scene.active    { background:#1e3a5f; border-color:#3b82f6; }
    .ns-btn-scene:hover:not(.active) { background:#1e3a5f44; }
    .ns-btn-object   { border-color:#1c3a1c; color:#86efac; }
    .ns-btn-object.active   { background:#1c3a1c; border-color:#4ade80; }
    .ns-btn-object:hover:not(.active) { background:#1c3a1c44; }
    .ns-btn-activity { border-color:#b46000; color:#fbbf24; }
    .ns-btn-activity.active { background:#3b2700; border-color:#f59e0b; }
    .ns-btn-activity:hover:not(.active) { background:#3b270044; }
    .ns-btn-person_role { border-color:#7c3aed; color:#c4b5fd; }
    .ns-btn-person_role.active { background:#2d1b69; border-color:#a78bfa; }
    .ns-btn-person_role:hover:not(.active) { background:#2d1b6944; }
    table { width:100%; border-collapse:collapse; font-size:.88rem; }
    th, td { border:1px solid #1d2a55; padding:7px 10px; text-align:left; vertical-align:top; }
    th { background:#0e1530; }
    .badge { display:inline-block; padding:2px 7px; border-radius:20px; font-size:.75rem; font-weight:600; }
    .badge-ai    { background:#1e3a5f; color:#93c5fd; }
    .badge-human { background:#1a3320; color:#4ade80; }
    .muted { color:#a8b3cf; font-size:.88rem; }
    .thumb-sm { width:80px; border-radius:4px; vertical-align:middle; }
    .trunc { max-width:260px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
    .tag-header { display:flex; align-items:center; gap:.75rem; margin-bottom:1rem; }
    .ns-pill { padding:3px 10px; border-radius:20px; font-size:.78rem; font-weight:700;
               background:#1e3a5f; color:#93c5fd; }
    .ns-pill-scene       { background:#1e3a5f; color:#93c5fd; }
    .ns-pill-object      { background:#1c3a1c; color:#86efac; }
    .ns-pill-activity    { background:#3b2700; color:#fbbf24; }
    .ns-pill-person_role { background:#2d1b69; color:#c4b5fd; }
    .ns-card-scene    .ns-sort-btn { color:#93c5fd; }
    .ns-card-object   .ns-sort-btn { color:#86efac; }
    .ns-card-activity .ns-sort-btn { color:#fbbf24; }
    .ns-card-person_role .ns-sort-btn { color:#c4b5fd; }
    th.sortable { cursor:pointer; user-select:none; white-space:nowrap; }
    th.sortable:hover { background:#1e2a4a; }
    th.sortable .th-ind { opacity:.55; font-size:.8em; margin-left:.3rem; }
    .search-bar { display:flex; gap:.5rem; margin-bottom:1rem; }
    .search-bar input { background:#0e1530; color:#e9eef7; border:1px solid #33427a; border-radius:8px;
                        padding:.5rem .75rem; font-size:.88rem; flex:1; }
    .search-bar button { padding:.5rem 1rem; border-radius:8px; border:1px solid #3b82f6;
                         background:transparent; color:#e9eef7; cursor:pointer; font-size:.88rem; }
    .search-bar button:hover { background:#1e40af; }
  </style>
</head>
<body>
<div class="wrap">
  <!-- Sidebar: namespace + tag list -->
  <nav class="sidebar">
    <div style="margin-bottom:.75rem;">
      <a href="/db/database.php">← Media Library</a><br>
      <?php if ($isAdmin): ?>
        <a href="/admin/ai_worker.php" style="margin-top:.3rem;display:block;">AI Worker</a>
      <?php endif; ?>
    </div>

    <div class="ns-filter">
      <a href="/db/ai_tags.php" class="ns-btn <?= $filterNs === '' ? 'active' : '' ?>">All</a>
      <?php foreach ($namespaces as $ns): ?>
        <a href="/db/ai_tags.php?namespace=<?= urlencode($ns) ?>"
           class="ns-btn ns-btn-<?= htmlspecialchars($ns, ENT_QUOTES) ?> <?= $filterNs === $ns ? 'active' : '' ?>"><?= htmlspecialchars($ns, ENT_QUOTES) ?></a>
      <?php endforeach; ?>
    </div>

    <?php foreach ($namespaces as $ns): ?>
      <?php if (!isset($tagsByNs[$ns]) || ($filterNs !== '' && $filterNs !== $ns)) continue; ?>
      <div class="card ns-card ns-card-<?= htmlspecialchars($ns, ENT_QUOTES) ?>" style="padding:.75rem;" data-sort="freq">
        <div class="ns-title">
          <button type="button" class="ns-sort-btn" title="Sort A–Z · click again to reset">
            <?= htmlspecialchars($ns, ENT_QUOTES) ?> <i class="sort-ind">↕</i>
          </button>
        </div>
        <?php $idx = 0; foreach ($tagsByNs[$ns] as $t): ?>
          <?php $isActive = ($filterName === $t['name'] && ($filterNs === '' || $filterNs === $t['namespace'])); ?>
          <a class="tag-link <?= $isActive ? 'active' : '' ?>" data-orig="<?= $idx++ ?>"
             href="/db/ai_tags.php?namespace=<?= urlencode($t['namespace']) ?>&name=<?= urlencode($t['name']) ?>">
            <?= htmlspecialchars($t['name'], ENT_QUOTES) ?>
            <span class="cnt"><?= (int)$t['usage_count'] ?></span>
          </a>
        <?php endforeach; ?>
      </div>
    <?php endforeach; ?>
  </nav>

  <!-- Main content -->
  <div class="main">
    <h1>Tag Browser</h1>

    <!-- Search by tag name -->
    <form class="search-bar" method="get" action="/db/ai_tags.php">
      <?php if ($filterNs !== ''): ?>
        <input type="hidden" name="namespace" value="<?= htmlspecialchars($filterNs, ENT_QUOTES) ?>">
      <?php endif; ?>
      <input type="text" name="name" placeholder="Search tag name…"
             value="<?= htmlspecialchars($filterName, ENT_QUOTES) ?>">
      <button type="submit">Search</button>
    </form>

    <?php if ($filterName !== '' && $tagRow): ?>
      <!-- Assets for a specific tag -->
      <div class="tag-header">
        <span class="ns-pill"><?= htmlspecialchars($tagRow['namespace'], ENT_QUOTES) ?></span>
        <h2 style="margin:0;font-size:1.2rem;"><?= htmlspecialchars($tagRow['name'], ENT_QUOTES) ?></h2>
        <span class="muted"><?= count($tagAssets) ?> asset(s)</span>
      </div>

      <?php if (empty($tagAssets)): ?>
        <p class="muted">No assets carry this tag.</p>
      <?php else: ?>
        <table>
          <thead>
            <tr><th>Thumb</th><th>File</th><th>Confidence</th><th>Source</th><th>Location</th><th>Tagged</th></tr>
          </thead>
          <tbody>
            <?php foreach ($tagAssets as $a): ?>
              <?php
                $sha = (string)($a['checksum_sha256'] ?? '');
                $thumbUrl = ($a['file_type'] === 'video' && $sha !== '')
                    ? '/video/thumbnails/' . rawurlencode($sha) . '.png'
                    : '';
              ?>
              <?php
                $tStart = $a['start_seconds'] !== null ? (float)$a['start_seconds'] : null;
                $tEnd   = $a['end_seconds']   !== null ? (float)$a['end_seconds']   : null;
                $sha    = (string)($a['checksum_sha256'] ?? '');
                $ext    = ltrim((string)($a['file_ext'] ?? ''), '.');
                if ($ext === '') {
                    $ext = strtolower(pathinfo((string)($a['source_relpath'] ?? ''), PATHINFO_EXTENSION));
                }
                $videoUrl = ($sha !== '' && $ext !== '' && $a['file_type'] === 'video')
                    ? '/video/' . rawurlencode($sha . '.' . $ext)
                    : '';
                $fmtSec = fn(float $s): string => ($s == floor($s)) ? (string)(int)$s : number_format($s, 1);
              ?>
              <tr>
                <td>
                  <?php if ($thumbUrl): ?>
                    <a href="/db/media_tags.php?asset_id=<?= (int)$a['asset_id'] ?>">
                      <img src="<?= htmlspecialchars($thumbUrl, ENT_QUOTES) ?>" class="thumb-sm"
                           alt="" onerror="this.style.display='none'">
                    </a>
                  <?php endif; ?>
                </td>
                <td class="trunc">
                  <a href="/db/media_tags.php?asset_id=<?= (int)$a['asset_id'] ?>">
                    <?= htmlspecialchars(basename((string)$a['source_relpath']), ENT_QUOTES) ?>
                  </a>
                  <div class="muted">asset #<?= (int)$a['asset_id'] ?></div>
                </td>
                <td><?= round((float)$a['confidence'] * 100) ?>%</td>
                <td><span class="badge badge-<?= htmlspecialchars($a['source'], ENT_QUOTES) ?>"><?= htmlspecialchars($a['source'], ENT_QUOTES) ?></span></td>
                <td style="white-space:nowrap;">
                  <?php if ($tStart !== null && $videoUrl !== ''): ?>
                    <a href="<?= htmlspecialchars($videoUrl . '#t=' . (int)$tStart, ENT_QUOTES) ?>" target="_blank"
                       title="Seek to <?= $fmtSec($tStart) ?>s<?= $tEnd !== null && $tEnd !== $tStart ? '–' . $fmtSec($tEnd) . 's' : '' ?>">
                      <?= $fmtSec($tStart) ?>s<?= $tEnd !== null && $tEnd !== $tStart ? '–' . $fmtSec($tEnd) . 's' : '' ?>
                    </a>
                  <?php elseif ($tStart !== null): ?>
                    <span class="muted"><?= $fmtSec($tStart) ?>s<?= $tEnd !== null && $tEnd !== $tStart ? '–' . $fmtSec($tEnd) . 's' : '' ?></span>
                  <?php else: ?>
                    <span class="muted">—</span>
                  <?php endif; ?>
                </td>
                <td class="muted"><?= htmlspecialchars((string)$a['created_at'], ENT_QUOTES) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>

    <?php elseif ($filterName !== '' && !$tagRow): ?>
      <p class="muted">Tag "<?= htmlspecialchars($filterName, ENT_QUOTES) ?>" not found.</p>

    <?php else: ?>
      <!-- Summary table of all tags -->
      <?php if (empty($allTags)): ?>
        <p class="muted">No tags in the database yet. Run the AI worker to generate tags.</p>
      <?php else: ?>
        <table id="tags-summary-table">
          <thead>
            <tr>
              <th class="sortable" data-col="0" data-type="text">Namespace <i class="th-ind">↕</i></th>
              <th class="sortable" data-col="1" data-type="text">Tag <i class="th-ind">↕</i></th>
              <th class="sortable" data-col="2" data-type="num">Assets <i class="th-ind">↕</i></th>
              <th class="sortable" data-col="3" data-type="num">AI <i class="th-ind">↕</i></th>
              <th class="sortable" data-col="4" data-type="num">Human <i class="th-ind">↕</i></th>
            </tr>
          </thead>
          <tbody>
            <?php $rowIdx = 0; foreach ($allTags as $t): ?>
              <tr data-orig="<?= $rowIdx++ ?>">
                <td><span class="ns-pill ns-pill-<?= htmlspecialchars($t['namespace'], ENT_QUOTES) ?>"><?= htmlspecialchars($t['namespace'], ENT_QUOTES) ?></span></td>
                <td>
                  <a href="/db/ai_tags.php?namespace=<?= urlencode($t['namespace']) ?>&name=<?= urlencode($t['name']) ?>">
                    <?= htmlspecialchars($t['name'], ENT_QUOTES) ?>
                  </a>
                </td>
                <td><?= (int)$t['usage_count'] ?></td>
                <td><?= (int)$t['ai_count'] ?></td>
                <td><?= (int)$t['human_count'] ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</div>
<script>
// ── Summary table column sort ────────────────────────────────────────────────
(function(){
    const tbl = document.getElementById('tags-summary-table');
    if (!tbl) return;
    const tbody = tbl.querySelector('tbody');
    tbl.querySelectorAll('th.sortable').forEach(th => {
        let state = 0; // 0=orig, 1=asc, 2=desc
        const ind = th.querySelector('.th-ind');
        const col = parseInt(th.dataset.col, 10);
        const isNum = th.dataset.type === 'num';
        th.addEventListener('click', () => {
            tbl.querySelectorAll('th.sortable').forEach(h => {
                h.querySelector('.th-ind').textContent = '\u2195';
                if (h !== th) h._state = 0;
            });
            state = (state + 1) % 3;
            const rows = Array.from(tbody.querySelectorAll('tr'));
            if (state === 0) {
                rows.sort((a, b) => parseInt(a.dataset.orig,10) - parseInt(b.dataset.orig,10));
                ind.textContent = '\u2195';
            } else {
                const dir = state === 1 ? 1 : -1;
                rows.sort((a, b) => {
                    const av = a.cells[col].textContent.trim();
                    const bv = b.cells[col].textContent.trim();
                    return isNum ? dir * (parseFloat(av) - parseFloat(bv))
                                 : dir * av.localeCompare(bv);
                });
                ind.textContent = state === 1 ? '\u2191' : '\u2193';
            }
            rows.forEach(r => tbody.appendChild(r));
        });
    });
})();

document.querySelectorAll('.ns-card').forEach(card => {
    const btn = card.querySelector('.ns-sort-btn');
    const ind = btn.querySelector('.sort-ind');
    btn.addEventListener('click', () => {
        const links = Array.from(card.querySelectorAll('.tag-link'));
        if (card.dataset.sort === 'freq') {
            links.sort((a, b) => {
                const an = a.childNodes[0].textContent.trim();
                const bn = b.childNodes[0].textContent.trim();
                return an.localeCompare(bn);
            });
            card.dataset.sort = 'alpha';
            ind.textContent = '↑';
            btn.title = 'Reset to frequency order';
        } else {
            links.sort((a, b) => parseInt(a.dataset.orig, 10) - parseInt(b.dataset.orig, 10));
            card.dataset.sort = 'freq';
            ind.textContent = '↕';
            btn.title = 'Sort A–Z · click again to reset';
        }
        links.forEach(l => card.appendChild(l));
    });
});
</script>
</body>
</html>
