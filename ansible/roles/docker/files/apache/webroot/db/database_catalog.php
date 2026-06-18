<?php declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Production\Api\Infrastructure\Database;
use PDO;

$user = $_SERVER['PHP_AUTH_USER']
    ?? $_SERVER['REMOTE_USER']
    ?? $_SERVER['REDIRECT_REMOTE_USER']
    ?? null;

if ($user !== 'admin') {
    http_response_code(403);
    echo '<h1>Forbidden</h1><p>Admin access required.</p>';
    exit;
}

$filterStatus  = trim((string)($_GET['status']       ?? ''));
$filterType    = trim((string)($_GET['file_type']     ?? ''));
$filterSupp    = isset($_GET['is_supported']) ? trim((string)$_GET['is_supported']) : '1';
$page          = max(1, (int)($_GET['page']           ?? 1));
$perPage       = max(1, (int)(getenv('CATALOG_PAGINATION_THRESHOLD') ?: 750));
$offset        = ($page - 1) * $perPage;

$validStatuses = ['cataloged', 'selected', 'skipped', 'imported', 'failed'];
$validTypes    = ['audio', 'video', 'unknown'];

if (!in_array($filterStatus, $validStatuses, true)) $filterStatus = '';
if (!in_array($filterType, $validTypes, true))       $filterType  = '';
if ($filterSupp !== '0' && $filterSupp !== '1')      $filterSupp  = '';

$qFile    = trim((string)($_GET['q_file']    ?? ''));
$qRelpath = trim((string)($_GET['q_relpath'] ?? ''));
$qOrg     = trim((string)($_GET['q_org']     ?? ''));
$qDate    = trim((string)($_GET['q_date']    ?? ''));

function buildCatalogSearch(array $searchMap, array $vals): array {
    $where  = [];
    $params = [];
    $errors = [];
    $max    = 10;

    $badOps = static function (string $r): bool {
        if (str_contains($r, '||') || str_contains($r, '&&'))   return true;
        if (str_starts_with($r, '|') || str_ends_with($r, '|')) return true;
        if (str_starts_with($r, '&') || str_ends_with($r, '&')) return true;
        if (str_contains($r, '|&') || str_contains($r, '&|'))   return true;
        return false;
    };

    foreach ($searchMap as $key => $cfg) {
        $raw = isset($vals[$key]) && is_string($vals[$key]) ? trim($vals[$key]) : '';
        if ($raw === '') continue;

        if ($badOps($raw)) {
            $errors[] = 'Search for "' . $key . '" contains empty terms around "|"/"&". Remove extra operators.';
            continue;
        }

        $orParts = array_values(array_filter(array_map('trim', explode('|', $raw)), static fn($x) => $x !== ''));
        if (empty($orParts)) { $errors[] = 'Search for "' . $key . '" is invalid.'; continue; }

        $total = 0; $orExprs = []; $localP = [];

        foreach ($orParts as $orRaw) {
            $andParts = array_values(array_filter(array_map('trim', explode('&', $orRaw)), static fn($x) => $x !== ''));
            if (empty($andParts)) { $errors[] = 'Search for "' . $key . '" is invalid.'; $orExprs = []; $localP = []; break; }

            $andExprs = [];
            foreach ($andParts as $term) {
                $term = trim($term);
                $neg  = false;
                if ($term === '!' || str_starts_with($term, '!!')) {
                    $errors[] = 'Search for "' . $key . '": invalid NOT term. Use !term (e.g. !foo).';
                    $orExprs  = []; $localP = []; break 2;
                }
                if (str_starts_with($term, '!')) {
                    $neg  = true;
                    $term = trim(substr($term, 1));
                    if ($term === '') {
                        $errors[] = 'Search for "' . $key . '": invalid NOT term. Use !term (e.g. !foo).';
                        $orExprs  = []; $localP = []; break 2;
                    }
                }
                $total++;
                $expr = sprintf((string)$cfg['sql'], '?');
                if ($neg) $expr = str_replace(' LIKE ', ' NOT LIKE ', $expr);
                $andExprs[] = $expr;
                $localP[]   = '%' . $term . '%';
            }
            if (!empty($andExprs)) $orExprs[] = '(' . implode(' AND ', $andExprs) . ')';
        }

        if (!empty($orExprs)) {
            if ($total > $max) {
                $errors[] = 'Search for "' . $key . '" has too many terms (max ' . $max . ').';
                continue;
            }
            $where[]  = '(' . implode(' OR ', $orExprs) . ')';
            foreach ($localP as $lp) $params[] = $lp;
        }
    }

    return [$where, $params, $errors];
}

$searchMap = [
    'q_file'    => ['sql' => 'LOWER(e.file_name) LIKE LOWER(%s)',                'param_base' => 'file'],
    'q_relpath' => ['sql' => 'LOWER(e.source_relpath) LIKE LOWER(%s)',            'param_base' => 'relpath'],
    'q_org'     => ['sql' => 'LOWER(e.org_name) LIKE LOWER(%s)',                  'param_base' => 'org'],
    'q_date'    => ['sql' => 'LOWER(CAST(e.event_date AS CHAR)) LIKE LOWER(%s)',  'param_base' => 'date'],
];
[$searchWhere, $searchParams, $searchErrors] = buildCatalogSearch($searchMap, [
    'q_file'    => $qFile,
    'q_relpath' => $qRelpath,
    'q_org'     => $qOrg,
    'q_date'    => $qDate,
]);

$dbError  = '';
$entries  = [];
$total    = 0;
$totals   = ['selected' => 0, 'total' => 0, 'selected_bytes' => 0];
$latestScans = [];

try {
    $pdo = Database::createFromEnv();

    $where  = [];
    $params = [];
    if ($filterStatus !== '') { $where[] = 'e.status = ?';       $params[] = $filterStatus; }
    if ($filterType   !== '') { $where[] = 'e.file_type = ?';    $params[] = $filterType; }
    if ($filterSupp   !== '') { $where[] = 'e.is_supported = ?'; $params[] = (int)$filterSupp; }
    if (empty($searchErrors)) {
        foreach ($searchWhere  as $sw) $where[]  = $sw;
        foreach ($searchParams as $sp) $params[] = $sp;
    }
    $wc = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM catalog_entries e $wc");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    $listParams = array_merge($params, [$perPage, $offset]);
    $listStmt   = $pdo->prepare(
        "SELECT e.*, s.source_root, s.org_name AS scan_org_name
         FROM catalog_entries e
         JOIN catalog_scans s ON s.scan_id = e.scan_id
         $wc
         ORDER BY e.source_relpath ASC
         LIMIT ? OFFSET ?"
    );
    $listStmt->execute($listParams);
    $entries = $listStmt->fetchAll(PDO::FETCH_ASSOC);

    // Footer totals (across all entries, not just current page)
    $tRow = $pdo->query(
        "SELECT COUNT(*) as total,
                SUM(CASE WHEN status='selected' THEN 1 ELSE 0 END) as selected,
                SUM(CASE WHEN status='selected' THEN COALESCE(size_bytes,0) ELSE 0 END) as sel_bytes
         FROM catalog_entries"
    )->fetch(PDO::FETCH_ASSOC);
    $totals = [
        'total'          => (int)($tRow['total']    ?? 0),
        'selected'       => (int)($tRow['selected'] ?? 0),
        'selected_bytes' => (int)($tRow['sel_bytes'] ?? 0),
    ];

    // Latest scan_id per source_root (for orphan highlighting)
    $lsRows = $pdo->query(
        "SELECT source_root, MAX(scan_id) as latest_scan_id FROM catalog_scans GROUP BY source_root"
    )->fetchAll(PDO::FETCH_ASSOC);
    foreach ($lsRows as $lr) {
        $latestScans[$lr['source_root']] = (int)$lr['latest_scan_id'];
    }

} catch (Throwable $e) {
    $dbError = $e->getMessage();
}

$pages = max(1, (int)ceil($total / $perPage));

function fmtBytes(int $b): string {
    if ($b >= 1_073_741_824) return round($b / 1_073_741_824, 2) . ' GB';
    if ($b >= 1_048_576)     return round($b / 1_048_576, 1)     . ' MB';
    if ($b >= 1_024)         return round($b / 1_024, 1)         . ' KB';
    return $b . ' B';
}

function qp(array $overrides = []): string {
    global $filterStatus, $filterType, $filterSupp, $page, $qFile, $qRelpath, $qOrg, $qDate;
    $base = [
        'status'       => $filterStatus,
        'file_type'    => $filterType,
        'is_supported' => $filterSupp,
        'q_file'       => $qFile,
        'q_relpath'    => $qRelpath,
        'q_org'        => $qOrg,
        'q_date'       => $qDate,
        'page'         => $page,
    ];
    $merged = array_merge($base, $overrides);
    $parts  = [];
    foreach ($merged as $k => $v) { if ($v !== '' && $v !== null) $parts[] = urlencode($k) . '=' . urlencode((string)$v); }
    return $parts ? '?' . implode('&', $parts) : '?';
}

$hasNonDefaultFilters = ($filterStatus !== '' || $filterType !== '' || $filterSupp !== '1');
$hasActiveSearch      = ($qFile !== '' || $qRelpath !== '' || $qOrg !== '' || $qDate !== '');
$clearFiltersHref     = qp(['status' => '', 'file_type' => '', 'is_supported' => '', 'page' => 1]);
$clearSearchHref      = qp(['q_file' => '', 'q_relpath' => '', 'q_org' => '', 'q_date' => '', 'page' => 1]);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8"/>
  <meta name="viewport" content="width=device-width,initial-scale=1"/>
  <title>Catalog Editing Tool (edit the list of files to be uploaded)</title>
  <style>
    :root { font-family: system-ui,-apple-system,Segoe UI,Roboto,sans-serif; }
    body  { margin:0; background:#0b1020; color:#e9eef7; }
    .wrap { max-width:1200px; margin:2rem auto; padding:1rem; }
    .card { background:#121a33; border:1px solid #1d2a55; border-radius:16px; padding:1.5rem; }
    button { padding:.55rem .9rem; border-radius:8px; border:1px solid #3b82f6; background:transparent; color:#e9eef7; cursor:pointer; font-size:.85rem; }
    button:hover:not(:disabled) { background:#1e40af; }
    button:disabled { opacity:.5; cursor:not-allowed; }
    button.danger { border-color:#dc2626; }
    button.danger:hover:not(:disabled) { background:#991b1b; }
    .muted  { color:#a8b3cf; font-size:.9rem; }
    a       { color:#60a5fa; }
    select, input[type=text], input[type=date], textarea {
      background:#0e1530; border:1px solid #33427a; border-radius:6px;
      color:#e9eef7; font-size:.85rem; padding:.35rem .55rem;
    }
    .filter-bar { display:flex; flex-wrap:wrap; gap:.5rem; align-items:center; margin-bottom:1rem; }
    .filter-bar select { min-width:130px; }
    table { width:100%; border-collapse:collapse; font-size:.85rem; }
    th { text-align:left; padding:.45rem .6rem; border-bottom:2px solid #1d2a55; color:#a8b3cf; font-size:.78rem; text-transform:uppercase; letter-spacing:.05em; white-space:nowrap; }
    td { padding:.4rem .6rem; border-bottom:1px solid #1d2a55; vertical-align:top; }
    tr:hover td { background:#0e1530; }
    tr.orphan td { background:#3b2700; }
    .badge { display:inline-block; padding:.1rem .45rem; border-radius:5px; font-size:.75rem; font-weight:700; white-space:nowrap; }
    .badge-audio  { background:#1c3a1c; color:#4ade80; }
    .badge-video  { background:#1e3a5f; color:#60a5fa; }
    .badge-unsup  { background:#3b2700; color:#fb923c; }
    .badge-cat    { background:#1d2a55; color:#a8b3cf; }
    .badge-sel    { background:#134e2e; color:#4ade80; }
    .badge-skip   { background:#2d1d1d; color:#f87171; }
    .badge-imp    { background:#0d2d40; color:#38bdf8; }
    .badge-fail   { background:#3b0d14; color:#f87171; }
    .meta-panel { display:none; background:#0e1530; border:1px solid #1d2a55; border-radius:8px;
                  padding:.75rem; margin-top:.4rem; }
    .meta-panel.open { display:block; }
    .meta-grid { display:grid; grid-template-columns:1fr 1fr; gap:.4rem .75rem; }
    .meta-grid label { font-size:.78rem; color:#a8b3cf; margin-bottom:.1rem; display:block; }
    .meta-grid input, .meta-grid select, .meta-grid textarea { width:100%; box-sizing:border-box; }
    .meta-grid .span2 { grid-column:1/-1; }
    .meta-grid textarea { min-height:50px; resize:vertical; }
    .row-actions { display:flex; gap:.35rem; flex-wrap:wrap; align-items:center; }
    .save-ok   { color:#4ade80; font-size:.78rem; }
    .save-err  { color:#f87171; font-size:.78rem; }
    .save-dirty { border-color:#f59e0b !important; }
    .pagination { display:flex; gap:.35rem; align-items:center; flex-wrap:wrap; margin-top:1rem; }
    .footer-bar { background:#0e1530; border:1px solid #1d2a55; border-radius:10px; padding:.75rem 1rem;
                  display:flex; gap:1.5rem; flex-wrap:wrap; align-items:center; margin-top:1.25rem; font-size:.9rem; }
    .bulk-toolbar { display:flex; gap:.4rem; flex-wrap:wrap; align-items:center; margin-bottom:.75rem; }
    .th-search-row input[type=text] { width:100%; box-sizing:border-box; margin-top:.3rem; font-size:.78rem; }
  </style>
</head>
<body>
<div class="wrap">
  <div class="card">

    <div style="position:absolute;top:1.5rem;right:1.5rem;display:flex;flex-direction:column;gap:.4rem;align-items:flex-end">
      <a href="/admin/admin_database_catalog_media_from_folder.php"><button type="button" style="border-color:#a855f7;font-size:.8rem;padding:.4rem .8rem">Catalog Media</button></a>
    </div>

    <h1 style="padding-right:160px;margin:0 0 .25rem">Catalog Editing Tool (edit the list of files to be uploaded)</h1>
    <p class="muted" style="margin:0 0 .5rem">Browse, annotate, and select files for import. Signed in as <code><?= htmlspecialchars((string)$user) ?></code>.</p>
    <p class="muted" style="margin:0 0 .5rem">Gighive gives you the option to scan multiple folders for later upload and ingest into the database. This page allows you to edit that list of files. You can delete files you don't want or edit the fields of information on specific files before uploading. Note this is a precursor to actually uploading your media files from the Catalog into Gighive's database.</p>
    <ul class="muted" style="margin:0 0 1rem;padding-left:1.4rem">
      <li>This page defaults to showing supported file types only &mdash; use the filter bar to show all files including unsupported types.</li>
      <li>File statuses control what gets uploaded: <strong>Cataloged</strong> means the entry has not yet been reviewed for upload; <strong>Selected</strong> entries will be included in the next upload; <strong>Skipped</strong> entries are excluded.</li>
      <li>To mark many files at once, use the checkboxes to select rows (or the header checkbox to check all on this page), then use the Bulk Set Status Toolbar to apply a status to all checked entries.</li>
    </ul>

    <?php if ($dbError !== ''): ?>
      <div style="background:#3b0d14;border:1px solid #b4232a;padding:.8rem 1rem;border-radius:10px;margin-bottom:1rem">
        DB error: <?= htmlspecialchars($dbError) ?>
      </div>
    <?php endif; ?>

    <form id="searchForm" method="get" action="">

    <!-- Filter bar -->
    <div class="filter-bar">
      <select name="status" onchange="this.form.submit()">
        <option value="">All statuses</option>
        <?php foreach (['cataloged','selected','skipped','imported','failed'] as $s): ?>
          <option value="<?= $s ?>" <?= $filterStatus === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
        <?php endforeach; ?>
      </select>
      <select name="file_type" onchange="this.form.submit()">
        <option value="">All types</option>
        <?php foreach (['audio','video','unknown'] as $t): ?>
          <option value="<?= $t ?>" <?= $filterType === $t ? 'selected' : '' ?>><?= ucfirst($t) ?></option>
        <?php endforeach; ?>
      </select>
      <select name="is_supported" onchange="this.form.submit()">
        <option value="">All</option>
        <option value="1" <?= $filterSupp === '1' ? 'selected' : '' ?>>Supported only</option>
        <option value="0" <?= $filterSupp === '0' ? 'selected' : '' ?>>Unsupported only</option>
      </select>
      <input type="hidden" name="page" value="1"/>
      <span class="muted"><?= number_format($total) ?> entries</span>
      <?php if ($hasNonDefaultFilters): ?>
        <a href="<?= htmlspecialchars($clearFiltersHref, ENT_QUOTES) ?>" style="font-size:.85rem">Clear filters</a>
      <?php endif; ?>
      <?php if ($hasActiveSearch): ?>
        <a href="<?= htmlspecialchars($clearSearchHref, ENT_QUOTES) ?>" style="font-size:.85rem">Clear search</a>
      <?php endif; ?>
    </div>

    <!-- Bulk toolbar -->
    <div class="bulk-toolbar">
      <span class="muted" style="font-size:.82rem;font-weight:600">Bulk Set Status Toolbar:</span>
      <button type="button" id="bulk-delete-btn" disabled>Delete checked</button>
      <span id="bulk-checked-status" class="muted" style="font-size:.82rem"></span>
      <select id="bulk-status-sel">
        <option value="cataloged">Cataloged</option>
        <option value="selected">Selected</option>
        <option value="skipped">Skipped</option>
      </select>
      <button type="button" id="bulk-apply-btn" disabled>Apply to checked</button>
    </div>

    <!-- Instructional text -->
    <div class="muted" style="font-size:.82rem;margin-bottom:.75rem">
      <strong>For the Search fields below:</strong><br>
      Search will only take place after you fill in one or more fields and hit Enter.<br>
      | or &amp; or ! allowed in search textboxes.&nbsp; Pipe symbol means OR, ampersand means AND, ! means NOT.&nbsp; You can combine these, but the ! takes precedence.&nbsp; Precedence rule is ! &gt; &amp; &gt; |.&nbsp; Example: &quot;.mp4&amp;water&amp;!ultra&amp;!source&quot;
    </div>

    <?php if (!empty($searchErrors)): ?>
      <div style="background:#3b0d14;border:1px solid #b4232a;padding:.8rem 1rem;border-radius:10px;margin-bottom:1rem">
        <?php foreach ($searchErrors as $se): ?>
          <div><?= htmlspecialchars($se) ?></div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <?php if (!$entries && $dbError === ''): ?>
      <p class="muted">No catalog entries found<?= $filterStatus || $filterType || $filterSupp ? ' matching the current filters' : '' ?>. Run a scan from <a href="/admin/admin_database_catalog_media_from_folder.php" style="color:#a855f7">Catalog Media</a> first.</p>
    <?php else: ?>

    <div style="overflow-x:auto">
    <table id="catalog-table">
      <thead>
        <tr>
          <th style="width:28px"><input type="checkbox" id="chk-all" title="Toggle all on this page"/></th>
          <th style="min-width:200px">
            <div>File</div>
            <div class="th-search-row"><input type="text" name="q_file" placeholder="File name…" value="<?= htmlspecialchars($qFile, ENT_QUOTES) ?>"/></div>
            <div class="th-search-row"><input type="text" name="q_relpath" placeholder="Path…" value="<?= htmlspecialchars($qRelpath, ENT_QUOTES) ?>"/></div>
          </th>
          <th>Type</th>
          <th>Size</th>
          <th>Modified</th>
          <th>Status</th>
          <th style="min-width:120px">
            <div>Org</div>
            <div class="th-search-row"><input type="text" name="q_org" placeholder="Org…" value="<?= htmlspecialchars($qOrg, ENT_QUOTES) ?>"/></div>
          </th>
          <th style="min-width:90px">
            <div>Event Date</div>
            <div class="th-search-row"><input type="text" name="q_date" placeholder="Date…" value="<?= htmlspecialchars($qDate, ENT_QUOTES) ?>"/></div>
          </th>
          <th>Scan</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($entries as $e):
            $eid       = (int)$e['catalog_entry_id'];
            $isOrphan  = isset($latestScans[$e['source_root']]) && (int)$e['last_seen_scan_id'] < $latestScans[$e['source_root']];
            $typeBadge = match($e['file_type']) {
                'audio'   => '<span class="badge badge-audio">audio</span>',
                'video'   => '<span class="badge badge-video">video</span>',
                default   => '<span class="badge badge-unsup">unknown</span>',
            };
            if (!(int)$e['is_supported']) $typeBadge = '<span class="badge badge-unsup">unsupported</span>';
            $statusBadgeClass = match($e['status']) {
                'selected' => 'badge-sel',
                'skipped'  => 'badge-skip',
                'imported' => 'badge-imp',
                'failed'   => 'badge-fail',
                default    => 'badge-cat',
            };
        ?>
        <tr id="row-<?= $eid ?>" data-id="<?= $eid ?>" data-supported="<?= (int)$e['is_supported'] ?>" data-status="<?= htmlspecialchars($e['status'], ENT_QUOTES) ?>" data-size-bytes="<?= (int)($e['size_bytes'] ?? 0) ?>"
            class="<?= $isOrphan ? 'orphan' : '' ?>">
          <td><input type="checkbox" class="row-chk" data-id="<?= $eid ?>"/></td>
          <td style="max-width:300px">
            <div style="word-break:break-all;font-weight:600"><?= htmlspecialchars((string)$e['file_name']) ?></div>
            <div class="muted" style="font-size:.75rem;word-break:break-all"><?= htmlspecialchars((string)$e['source_relpath']) ?></div>
          </td>
          <td><?= $typeBadge ?></td>
          <td class="muted" style="white-space:nowrap"><?= $e['size_bytes'] !== null ? htmlspecialchars(fmtBytes((int)$e['size_bytes'])) : '—' ?></td>
          <td class="muted" style="white-space:nowrap;font-size:.8rem"><?= htmlspecialchars((string)($e['file_mtime'] ?? '—')) ?></td>
          <td>
            <select class="status-sel" data-id="<?= $eid ?>" data-supported="<?= (int)$e['is_supported'] ?>">
              <option value="cataloged" <?= $e['status']==='cataloged'?'selected':'' ?>>Cataloged</option>
              <option value="selected"  <?= $e['status']==='selected' ?'selected':'' ?>>Selected</option>
              <option value="skipped"   <?= $e['status']==='skipped'  ?'selected':'' ?>>Skipped</option>
              <?php if ($e['status']==='imported'): ?><option value="imported" selected disabled>Imported</option><?php endif; ?>
              <?php if ($e['status']==='failed'):   ?><option value="failed"   selected disabled>Failed</option>  <?php endif; ?>
            </select>
          </td>
          <?php
            $orgVal  = (string)($e['org_name'] ?? $e['scan_org_name'] ?? '');
            $dateVal = (string)($e['event_date'] ?? '');
            $orgNeedsReview  = ($orgVal  === '' || $orgVal  === 'Default');
            $dateNeedsReview = ($dateVal === '');
          ?>
          <td style="font-size:.8rem;white-space:nowrap">
            <span style="<?= $orgNeedsReview  ? 'color:#fb923c' : 'color:#e9eef7' ?>"><?= htmlspecialchars($orgVal  !== '' ? $orgVal  : '—') ?></span>
          </td>
          <td style="font-size:.8rem;white-space:nowrap">
            <span style="<?= $dateNeedsReview ? 'color:#fb923c' : 'color:#a8b3cf' ?>"><?= htmlspecialchars($dateVal !== '' ? $dateVal : '—') ?></span>
          </td>
          <td class="muted" style="font-size:.78rem;white-space:nowrap">
            <?= $isOrphan ? '<span style="color:#fb923c" title="File not seen in the most recent scan of this source root — may have been moved or deleted">⚠ orphan</span><br>' : '' ?>
            first: <?= (int)$e['first_seen_scan_id'] ?><br>
            last: <?= (int)$e['last_seen_scan_id'] ?>
          </td>
          <td>
            <div class="row-actions">
              <button type="button" id="save-btn-<?= $eid ?>" onclick="saveRow(<?= $eid ?>)">Save</button>
              <button type="button" onclick="toggleMeta(<?= $eid ?>)" title="Edit metadata">Edit</button>
              <button type="button" class="danger" onclick="deleteRow(<?= $eid ?>)">Delete</button>
            </div>
            <span id="msg-<?= $eid ?>" class="muted" style="font-size:.78rem"></span>
            <!-- Expandable metadata panel -->
            <div id="meta-<?= $eid ?>" class="meta-panel">
              <div class="meta-grid">
                <div>
                  <label>Org / Band name</label>
                  <input type="text" id="org-<?= $eid ?>" value="<?= htmlspecialchars((string)($e['org_name'] ?? '')) ?>"/>
                </div>
                <div>
                  <label>Event date</label>
                  <input type="date" id="edate-<?= $eid ?>" value="<?= htmlspecialchars((string)($e['event_date'] ?? '')) ?>"/>
                </div>
                <div>
                  <label>Event type</label>
                  <select id="etype-<?= $eid ?>">
                    <option value="">— none —</option>
                    <?php foreach (['band','wedding','other'] as $et): ?>
                      <option value="<?= $et ?>" <?= $e['event_type']===$et?'selected':'' ?>><?= ucfirst($et) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div>
                  <label>Location</label>
                  <input type="text" id="loc-<?= $eid ?>" value="<?= htmlspecialchars((string)($e['location'] ?? '')) ?>"/>
                </div>
                <div>
                  <label>Label</label>
                  <input type="text" id="label-<?= $eid ?>" value="<?= htmlspecialchars((string)($e['label'] ?? '')) ?>"/>
                </div>
                <div>
                  <label>Item type</label>
                  <select id="itype-<?= $eid ?>">
                    <option value="">— auto —</option>
                    <?php foreach (['song','loop','clip','highlight'] as $it): ?>
                      <option value="<?= $it ?>" <?= $e['item_type']===$it?'selected':'' ?>><?= ucfirst($it) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="span2">
                  <label>Keywords</label>
                  <input type="text" id="kw-<?= $eid ?>" value="<?= htmlspecialchars((string)($e['keywords'] ?? '')) ?>"/>
                </div>
                <div class="span2">
                  <label>Summary (promotes to events.summary at ingest)</label>
                  <input type="text" id="summ-<?= $eid ?>" value="<?= htmlspecialchars((string)($e['summary'] ?? '')) ?>"/>
                </div>
                <div class="span2">
                  <label>Participants (comma-separated)</label>
                  <input type="text" id="part-<?= $eid ?>" value="<?= htmlspecialchars((string)($e['participants'] ?? '')) ?>"/>
                </div>
                <div class="span2">
                  <label>Notes (operator-only; not promoted)</label>
                  <textarea id="notes-<?= $eid ?>"><?= htmlspecialchars((string)($e['notes'] ?? '')) ?></textarea>
                </div>
              </div>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>

    <!-- Pagination -->
    <?php if ($pages > 1): ?>
    <div class="pagination">
      <?php if ($page > 1): ?>
        <a href="<?= qp(['page' => $page - 1]) ?>"><button>← Prev</button></a>
      <?php endif; ?>
      <span class="muted">Page <?= $page ?> of <?= $pages ?> &nbsp;&middot;&nbsp; <?= number_format($perPage) ?> rows per page</span>
      <?php if ($page < $pages): ?>
        <a href="<?= qp(['page' => $page + 1]) ?>"><button>Next →</button></a>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php endif; ?>
    </form>

    <!-- Footer summary -->
    <div class="footer-bar">
      <span><strong id="footer-total-count"><?= number_format($totals['total']) ?></strong> <span class="muted">total catalog entries</span></span>
      <span><strong id="footer-sel-count" style="color:#4ade80"><?= number_format($totals['selected']) ?></strong> <span class="muted">selected</span></span>
      <span><strong id="footer-sel-bytes"><?= htmlspecialchars(fmtBytes($totals['selected_bytes'])) ?></strong> <span class="muted">selected size</span></span>
      <a id="footer-promote-link" href="/admin/admin_database_catalog_promote.php" style="margin-left:auto;<?= $totals['selected'] === 0 ? 'display:none' : '' ?>">
        <button type="button" style="border-color:#22c55e;padding:.45rem .9rem;font-size:.85rem">
          Promote <span id="footer-promote-n"><?= number_format($totals['selected']) ?></span> selected file<span id="footer-promote-s"><?= $totals['selected'] === 1 ? '' : 's' ?></span> to Upload &rarr;
        </button>
      </a>
    </div>

  </div>
</div>

<script>
function el(id) { return document.getElementById(id); }

function esc(s) {
  return String(s)
    .replace(/&/g,'&amp;').replace(/</g,'&lt;')
    .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function toggleMeta(id) {
  const p = el('meta-' + id);
  p.classList.toggle('open');
}

function getRowPayload(id) {
  const statusSel = document.querySelector('.status-sel[data-id="' + id + '"]');
  return {
    catalog_entry_id : id,
    action           : 'save',
    status           : statusSel ? statusSel.value : undefined,
    org_name         : el('org-'   + id) ? el('org-'   + id).value.trim() || null : undefined,
    event_date       : el('edate-' + id) ? el('edate-' + id).value.trim() || null : undefined,
    event_type       : el('etype-' + id) ? el('etype-' + id).value        || null : undefined,
    location         : el('loc-'   + id) ? el('loc-'   + id).value.trim() || null : undefined,
    label            : el('label-' + id) ? el('label-' + id).value.trim() || null : undefined,
    item_type        : el('itype-' + id) ? el('itype-' + id).value        || null : undefined,
    keywords         : el('kw-'    + id) ? el('kw-'    + id).value.trim() || null : undefined,
    summary          : el('summ-'  + id) ? el('summ-'  + id).value.trim() || null : undefined,
    participants     : el('part-'  + id) ? el('part-'  + id).value.trim() || null : undefined,
    notes            : el('notes-' + id) ? el('notes-' + id).value.trim() || null : undefined,
  };
}

function fmtBytesJs(b) {
  b = parseInt(b, 10) || 0;
  if (b >= 1073741824) return (b / 1073741824).toFixed(2) + ' GB';
  if (b >= 1048576)    return (b / 1048576).toFixed(1)   + ' MB';
  if (b >= 1024)       return (b / 1024).toFixed(1)      + ' KB';
  return b + ' B';
}

function refreshFooterCounts() {
  let sel = 0, selBytes = 0;
  document.querySelectorAll('tr[data-id]').forEach(row => {
    if (row.dataset.status === 'selected') {
      sel++;
      selBytes += parseInt(row.dataset.sizeBytes || '0', 10);
    }
  });
  const selEl    = el('footer-sel-count');
  const bytesEl  = el('footer-sel-bytes');
  const link     = el('footer-promote-link');
  const promoteN = el('footer-promote-n');
  const promoteS = el('footer-promote-s');
  if (selEl)    selEl.textContent    = sel.toLocaleString();
  if (bytesEl)  bytesEl.textContent  = fmtBytesJs(selBytes);
  if (link)     link.style.display   = sel > 0 ? '' : 'none';
  if (promoteN) promoteN.textContent = sel.toLocaleString();
  if (promoteS) promoteS.textContent = sel === 1 ? '' : 's';
}

function markDirty(id) {
  const btn = el('save-btn-' + id);
  if (btn) btn.classList.add('save-dirty');
}

function markClean(id) {
  const btn = el('save-btn-' + id);
  if (btn) btn.classList.remove('save-dirty');
}

// Cascade non-empty meta fields to a list of other row IDs, updating their inputs if open
async function cascadeSave(ids, fields) {
  const fieldPrefixMap = {
    org_name:'org-', event_date:'edate-', event_type:'etype-', location:'loc-',
    label:'label-', item_type:'itype-', keywords:'kw-', summary:'summ-',
    participants:'part-', notes:'notes-',
  };
  for (const id of ids) {
    const msg = el('msg-' + id);
    if (msg) { msg.textContent = 'Saving…'; msg.className = 'muted'; }
    try {
      const body = Object.assign({catalog_entry_id: id, action: 'save'}, fields);
      const res  = await fetch('/db/catalog_entry_save.php', {
        method : 'POST',
        headers: {'Content-Type': 'application/json'},
        body   : JSON.stringify(body),
      });
      const data = await res.json();
      if (!res.ok || !data.success) throw new Error(data.errors ? data.errors.join('; ') : (data.error || 'Error'));
      if (msg) {
        msg.textContent = '✓ Saved';
        msg.className   = 'save-ok';
        setTimeout(() => { msg.textContent = ''; }, 5000);
      }
      markClean(id);
      // Reflect new values in the meta panel inputs if already open
      for (const [key, val] of Object.entries(fields)) {
        const inp = el((fieldPrefixMap[key] || '') + id);
        if (inp) inp.value = val;
      }
    } catch (err) {
      if (msg) { msg.textContent = '✗ ' + err.message; msg.className = 'save-err'; }
    }
  }
}

async function saveRow(id) {
  const msg = el('msg-' + id);
  msg.textContent = 'Saving…';
  msg.className   = 'muted';
  try {
    const payload = getRowPayload(id);
    const res  = await fetch('/db/catalog_entry_save.php', {
      method : 'POST',
      headers: {'Content-Type': 'application/json'},
      body   : JSON.stringify(payload),
    });
    const data = await res.json();
    if (!res.ok || !data.success) throw new Error(data.errors ? data.errors.join('; ') : (data.error || 'Error'));
    msg.textContent = '✓ Saved';
    msg.className   = 'save-ok';
    setTimeout(() => { msg.textContent = ''; }, 5000);
    const row = el('row-' + id);
    if (row && payload.status !== undefined) row.dataset.status = payload.status;
    markClean(id);
    refreshFooterCounts();
    // Cascade non-empty meta fields to other checked rows
    const metaKeys = ['org_name','event_date','event_type','location','label','item_type','keywords','summary','participants','notes'];
    const cascadeFields = {};
    for (const k of metaKeys) {
      if (payload[k] !== null && payload[k] !== undefined) cascadeFields[k] = payload[k];
    }
    const otherChecked = getCheckedIds().filter(cid => {
      if (cid === id) return false;
      const r = el('row-' + cid);
      const st = r ? r.dataset.status : '';
      return st !== 'imported' && st !== 'failed';
    });
    if (otherChecked.length > 0 && Object.keys(cascadeFields).length > 0) {
      const n = otherChecked.length;
      if (confirm('Apply these changes to ' + n + ' other checked row' + (n === 1 ? '' : 's') + '?')) {
        await cascadeSave(otherChecked, cascadeFields);
      }
    }
  } catch (err) {
    msg.textContent = '✗ ' + err.message;
    msg.className   = 'save-err';
  }
}

async function deleteRow(id) {
  if (!confirm('Delete this catalog entry?')) return;
  const msg = el('msg-' + id);
  msg.textContent = 'Deleting…';
  try {
    const res  = await fetch('/db/catalog_entry_save.php', {
      method : 'POST',
      headers: {'Content-Type': 'application/json'},
      body   : JSON.stringify({catalog_entry_id: id, action: 'delete'}),
    });
    const data = await res.json();
    if (!res.ok || !data.success) throw new Error(data.error || 'Error');
    const row = el('row-' + id);
    if (row) row.remove();
  } catch (err) {
    msg.textContent = '✗ ' + err.message;
    msg.className   = 'save-err';
  }
}

function getCheckedIds() {
  return Array.from(document.querySelectorAll('.row-chk:checked'))
    .map(cb => parseInt(cb.dataset.id, 10))
    .filter(n => Number.isFinite(n) && n > 0);
}

function updateBulkUi() {
  const n          = getCheckedIds().length;
  const delBtn     = el('bulk-delete-btn');
  const applyBtn   = el('bulk-apply-btn');
  const statusSpan = el('bulk-checked-status');
  if (delBtn)     delBtn.disabled   = n === 0;
  if (applyBtn)   applyBtn.disabled = n === 0;
  if (statusSpan) statusSpan.textContent = n > 0 ? n + ' checked' : '';
}

function bulkProgressHtml(done, total) {
  const pct  = total > 0 ? Math.round((done / total) * 100) : 0;
  return done + '\u202f/\u202f' + total
    + '\u00a0\u00a0<span style="display:inline-block;width:100px;height:6px;background:#1d2a55;'
    + 'border-radius:3px;vertical-align:middle"><span style="display:block;height:100%;background:#3b82f6;'
    + 'border-radius:3px;width:' + pct + '%"></span></span>';
}

async function deleteChecked() {
  const ids = getCheckedIds();
  if (!ids.length) return;
  if (!confirm('Delete ' + ids.length + ' catalog ' + (ids.length === 1 ? 'entry' : 'entries') + '?')) return;
  const btn        = el('bulk-delete-btn');
  const applyBtn   = el('bulk-apply-btn');
  const statusSpan = el('bulk-checked-status');
  if (btn)      { btn.disabled = true; btn.textContent = 'Deleting\u2026'; }
  if (applyBtn) applyBtn.disabled = true;
  const total = ids.length;
  let done = 0;
  if (statusSpan) statusSpan.innerHTML = 'Deleting\u2026 ' + bulkProgressHtml(done, total);
  for (const id of ids) {
    try {
      const res = await fetch('/db/catalog_entry_save.php', {
        method : 'POST',
        headers: {'Content-Type': 'application/json'},
        body   : JSON.stringify({catalog_entry_id: id, action: 'delete'}),
      });
      await res.json();
    } catch (_) {}
    done++;
    if (statusSpan) statusSpan.innerHTML = 'Deleting\u2026 ' + bulkProgressHtml(done, total);
  }
  if (statusSpan) statusSpan.textContent = 'Done. Reloading\u2026';
  window.location.reload();
}

async function applyStatusChecked() {
  const statusSel = el('bulk-status-sel');
  const newStatus = statusSel ? statusSel.value : '';
  if (!newStatus) return;
  const rows = Array.from(document.querySelectorAll('.row-chk:checked'))
    .map(cb => cb.closest('tr[data-id]'))
    .filter(row => {
      if (!row) return false;
      const cur = row.dataset.status || '';
      return cur !== 'imported' && cur !== 'failed';
    });
  if (!rows.length) return;
  const btn = el('bulk-apply-btn');
  if (btn) { btn.disabled = true; btn.textContent = 'Applying\u2026'; }
  for (const row of rows) {
    const id = parseInt(row.dataset.id, 10);
    if (!Number.isFinite(id) || id <= 0) continue;
    try {
      const res = await fetch('/db/catalog_entry_save.php', {
        method : 'POST',
        headers: {'Content-Type': 'application/json'},
        body   : JSON.stringify({catalog_entry_id: id, action: 'save', status: newStatus}),
      });
      await res.json();
    } catch (_) {}
  }
  window.location.reload();
}

document.addEventListener('DOMContentLoaded', () => {
  const chkAll = el('chk-all');
  if (chkAll) {
    chkAll.addEventListener('click', e => e.stopPropagation());
    chkAll.addEventListener('change', () => {
      document.querySelectorAll('.row-chk').forEach(c => { c.checked = chkAll.checked; });
      updateBulkUi();
    });
  }

  document.querySelectorAll('.row-chk').forEach(cb => {
    cb.addEventListener('click', e => e.stopPropagation());
    cb.addEventListener('change', updateBulkUi);
  });

  const delBtn   = el('bulk-delete-btn');
  const applyBtn = el('bulk-apply-btn');
  if (delBtn)   delBtn.addEventListener('click', deleteChecked);
  if (applyBtn) applyBtn.addEventListener('click', applyStatusChecked);

  document.querySelectorAll('#searchForm .th-search-row input[type="text"]').forEach(input => {
    input.addEventListener('click', e => e.stopPropagation());
    input.addEventListener('keydown', e => {
      if (e.key !== 'Enter') return;
      e.preventDefault();
      const form = el('searchForm');
      if (!form) return;
      if (typeof form.requestSubmit === 'function') { form.requestSubmit(); } else { form.submit(); }
    });
  });

  updateBulkUi();

  // Item 2: status dropdown auto-saves on change
  document.querySelectorAll('.status-sel').forEach(sel => {
    sel.addEventListener('change', () => saveRow(parseInt(sel.dataset.id, 10)));
  });

  // Item 1: meta-panel field changes mark Save button dirty (amber)
  document.querySelectorAll('.meta-panel').forEach(panel => {
    const id = parseInt(panel.id.replace('meta-', ''), 10);
    panel.querySelectorAll('input, select, textarea').forEach(field => {
      field.addEventListener('input',  () => markDirty(id));
      field.addEventListener('change', () => markDirty(id));
    });
  });
});
</script>
</body>
</html>
