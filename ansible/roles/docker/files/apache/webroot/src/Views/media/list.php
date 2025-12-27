<?php /** @var array $rows */ ?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <?php
  $appFlavor = isset($appFlavor) ? (string)$appFlavor : 'stormpigs';
  $isGighive = strtolower(trim($appFlavor)) === 'gighive';
  $maxWidth = $isGighive ? 900 : 1350;
  ?>
  <?php if (!$isGighive): ?>
  <link rel="stylesheet" href="../header.css">
  <?php endif; ?>
  <title>Media Library</title>
  <style>
    :root{
      --page-bg:#ffffff;
      --text:#111111;
      --muted:#666666;
      --border:#dddddd;
      --th-bg:#f6f6f6;
      --th-text:#111111;
      --link:#0366d6;
      --row-highlight:#fff8c2;
      --input-bg:#ffffff;
      --input-text:#111111;
      --input-border:#cccccc;
      --resizer:#d0d0d0;
    }

    body.theme-defaultcodebase{
      --page-bg:#000000;
      --text:#d8cf6a;
      --muted:#cfcfcf;
      --border:#3b3b3b;
      --th-bg:#ffffff;
      --th-text:#111111;
      --link:#9cc7ff;
      --row-highlight:#1a1a1a;
      --input-bg:#ffffff;
      --input-text:#111111;
      --input-border:#cfcfcf;
      --resizer:#7a7a7a;
    }

    body.theme-defaultcodebase #searchableTable th[data-col="keywords"],
    body.theme-defaultcodebase #searchableTable td[data-col="keywords"],
    body.theme-defaultcodebase #searchableTable th[data-col="summary"],
    body.theme-defaultcodebase #searchableTable td[data-col="summary"]{
      max-width:275px;
    }

    body.theme-defaultcodebase #searchableTable th[data-col="keywords"],
    body.theme-defaultcodebase #searchableTable td[data-col="keywords"]{
      max-width:270px;
    }

    body.theme-defaultcodebase #searchableTable th[data-col="location"],
    body.theme-defaultcodebase #searchableTable td[data-col="location"],
    body.theme-defaultcodebase #searchableTable th[data-col="download"],
    body.theme-defaultcodebase #searchableTable td[data-col="download"]{
      width:150px;
      max-width:150px;
    }

    body.theme-defaultcodebase #searchableTable th[data-col="media_info"],
    body.theme-defaultcodebase #searchableTable td[data-col="media_info"]{
      width:430px;
      max-width:430px;
    }

    body.theme-defaultcodebase #searchableTable th[data-col="keywords"] .th-search-row > input,
    body.theme-defaultcodebase #searchableTable th[data-col="summary"] .th-search-row > input,
    body.theme-defaultcodebase #searchableTable th[data-col="location"] .th-search-row > input,
    body.theme-defaultcodebase #searchableTable th[data-col="download"] .th-search-row > input{
      max-width:160px;
    }

    body{font-family:system-ui,Arial,sans-serif;margin:0;padding:1rem;overflow-x:auto;background:var(--page-bg);color:var(--text);}
    h1{margin:0 0 1rem 0;}
    .user-indicator{font-size:12px;color:var(--muted);margin:0 0 0.5rem 0;}
    .pagination{max-width:<?= (int)$maxWidth ?>px;margin:0 auto 0.75rem auto;display:flex;align-items:center;justify-content:space-between;gap:1rem;}
    .pagination .links a,.pagination .links span{display:inline-block;padding:4px 8px;border:1px solid var(--border);border-radius:4px;text-decoration:none;color:var(--link);}
    .pagination .links span{color:var(--muted);border-color:var(--border);}
    .header-block{max-width:<?= (int)$maxWidth ?>px;margin:0 0 0.75rem 0;text-align:left;}
    table{width:max-content;table-layout:auto;border-collapse:collapse;margin:0 auto;}
    th,td{border:1px solid var(--border);padding:8px;vertical-align:top;word-wrap:break-word;}
    th{background:var(--th-bg);color:var(--th-text);text-align:left;cursor:pointer;}
    thead input{width:100%;box-sizing:border-box;margin-top:0;background:var(--input-bg);color:var(--input-text);border:1px solid var(--input-border);}
    .highlighted-jam{background-color:var(--row-highlight);}
    #searchableTable th:first-child,
    #searchableTable td:first-child{width:40px;}
    .media-file-info{white-space:pre;display:inline-block;}

    /* Base column sizing & wrapping (applies to both modes via data-col) */
    #searchableTable th[data-col="date"],
    #searchableTable td[data-col="date"],
    #searchableTable th[data-col="org"],
    #searchableTable td[data-col="org"],
    #searchableTable th[data-col="rating"],
    #searchableTable td[data-col="rating"],
    #searchableTable th[data-col="file_type"],
    #searchableTable td[data-col="file_type"],
    #searchableTable th[data-col="duration"],
    #searchableTable td[data-col="duration"]{
      white-space:nowrap;
      width:1%;
    }

    #searchableTable th[data-col="date"] > input,
    #searchableTable th[data-col="org"] > input,
    #searchableTable th[data-col="rating"] > input,
    #searchableTable th[data-col="file_type"] > input,
    #searchableTable th[data-col="duration"] > input{max-width:120px;}

    #searchableTable th[data-col="download"],
    #searchableTable td[data-col="download"]{
      white-space:nowrap;
      width:1%;
    }

    #searchableTable th[data-col="download"] > .th-search-row > input{max-width:120px;}

    #searchableTable th[data-col="keywords"],
    #searchableTable td[data-col="keywords"],
    #searchableTable th[data-col="location"],
    #searchableTable td[data-col="location"],
    #searchableTable th[data-col="summary"],
    #searchableTable td[data-col="summary"],
    #searchableTable th[data-col="source_relpath"],
    #searchableTable td[data-col="source_relpath"]{
      white-space:normal;
      overflow-wrap:anywhere;
      word-break:break-word;
    }

    #searchableTable th[data-col="song_name"],
    #searchableTable td[data-col="song_name"]{width:175px;white-space:normal;overflow-wrap:anywhere;word-break:break-word;}

    #searchableTable th[data-col="media_info"],
    #searchableTable td[data-col="media_info"]{width:345px;white-space:normal;overflow-wrap:anywhere;word-break:break-word;}

    #searchableTable th[data-col="musicians"],
    #searchableTable td[data-col="musicians"]{width:250px;white-space:normal;overflow-wrap:anywhere;word-break:break-word;}

    #searchableTable td[data-col="media_info"] .media-file-info{white-space:pre-wrap;display:block;max-width:100%;overflow-wrap:anywhere;word-break:break-word;}

    .th-title-row{display:flex;align-items:center;justify-content:space-between;gap:8px;}
    .th-title-row{min-height:32px;align-items:flex-start;}
    .th-title-row h4{line-height:16px;max-height:32px;overflow:hidden;}
    .th-title-row h4{margin:0;}
    .th-title-row .col-collapse-checkbox{margin:0;}
    .th-search-row{margin-top:4px;}

    th.col-collapsed,td.col-collapsed{width:32px;min-width:32px;max-width:32px;padding-left:4px;padding-right:4px;overflow:hidden;white-space:nowrap;}
    #searchableTable th.col-collapsed,
    #searchableTable td.col-collapsed,
    body.theme-defaultcodebase #searchableTable th.col-collapsed,
    body.theme-defaultcodebase #searchableTable td.col-collapsed{width:32px;min-width:32px;max-width:32px;}
    th.col-collapsed .th-title-row{justify-content:center;}
    th.col-collapsed .th-title-row h4{display:none;}
    th.col-collapsed .th-search-row{display:none;}
    th.col-collapsed .col-resizer{display:none;}

    /* Resizable columns */
    #searchableTable th{position:relative;}
    #searchableTable th .col-resizer{position:absolute;top:0;right:-4px;width:8px;height:100%;cursor:col-resize;user-select:none;touch-action:none;}
    #searchableTable th .col-resizer::after{content:'';position:absolute;top:0;left:3px;width:1px;height:100%;background:var(--resizer);}
    body.resizing-col{cursor:col-resize;user-select:none;}
  </style>
</head>
<body class="<?= $isGighive ? 'theme-gighive' : 'theme-defaultcodebase' ?>">
  <?php
  $user = $_SERVER['REMOTE_USER']
      ?? $_SERVER['PHP_AUTH_USER']
      ?? $_SERVER['REDIRECT_REMOTE_USER']
      ?? 'Unknown';
  ?>
  <div class="user-indicator header-block">User is logged in as <?= htmlspecialchars($user, ENT_QUOTES) ?>. v1.0 view</div>
  <h1 id="all" class="header-block">Media Library</h1>

  <?php
  $pagination = $pagination ?? ['enabled' => false];
  $query = $query ?? [];
  $searchKeys = $isGighive
      ? ['date', 'org_name', 'song_title', 'file_type', 'file_name', 'source_relpath', 'crew']
      : ['date', 'org_name', 'rating', 'keywords', 'location', 'summary', 'crew', 'song_title', 'file_type', 'file_name'];
  $hasSearch = false;
  foreach ($searchKeys as $k) {
      if (!empty($query[$k])) {
          $hasSearch = true;
          break;
      }
  }
  $buildUrl = function (int $page) use ($query): string {
      $params = $query;
      $params['page'] = $page;
      $qs = http_build_query($params);
      return $qs === '' ? 'database.php' : ('database.php?' . $qs);
  };
  ?>

  <div class="header-block" style="margin-bottom:0.5rem;">
    Search will only take place after you fill in one or more fields and hit Enter.  Checkbox toggles column width.
  </div>
  <?php if ($hasSearch): ?>
    <div id="searchStatus" class="header-block">
      <?= htmlspecialchars((string)($pagination['total'] ?? 0), ENT_QUOTES) ?> rows found
    </div>
  <?php else: ?>
    <div id="searchStatus" class="header-block"></div>
  <?php endif; ?>

  <?php if (!empty($pagination['enabled'])): ?>
    <div class="pagination">
      <div class="summary">
        Showing <?= htmlspecialchars((string)($pagination['start'] ?? 0), ENT_QUOTES) ?>
        – <?= htmlspecialchars((string)($pagination['end'] ?? 0), ENT_QUOTES) ?>
        of <?= htmlspecialchars((string)($pagination['total'] ?? 0), ENT_QUOTES) ?>
      </div>
      <div class="links">
        <?php if (($pagination['page'] ?? 1) > 1): ?>
          <a href="<?= htmlspecialchars($buildUrl((int)$pagination['page'] - 1), ENT_QUOTES) ?>">Prev</a>
        <?php else: ?>
          <span>Prev</span>
        <?php endif; ?>

        <span>
          Page <?= htmlspecialchars((string)($pagination['page'] ?? 1), ENT_QUOTES) ?>
          of <?= htmlspecialchars((string)($pagination['pageCount'] ?? 1), ENT_QUOTES) ?>
        </span>

        <?php if (($pagination['page'] ?? 1) < ($pagination['pageCount'] ?? 1)): ?>
          <a href="<?= htmlspecialchars($buildUrl((int)$pagination['page'] + 1), ENT_QUOTES) ?>">Next</a>
        <?php else: ?>
          <span>Next</span>
        <?php endif; ?>
      </div>
    </div>
  <?php endif; ?>

  <form id="searchForm" method="get" action="database.php" onsubmit="alert('Enter pressed..searching');">
  <div style="margin:0 auto;">
  <table id="searchableTable" class="<?= $isGighive ? 'table-gighive' : 'table-defaultcodebase' ?>" data-sort-order="asc">
    <thead>
      <tr>
        <?php if ($isGighive): ?>
          <th data-col="idx" onclick="if(event.target.tagName!=='INPUT'){sortTable(0)}"><div class="th-title-row"><h4 title="#">#</h4><input class="col-collapse-checkbox" type="checkbox" aria-label="Collapse column"></div></th>
          <th data-col="date" onclick="if(event.target.tagName!=='INPUT'){sortTable(1)}"><div class="th-title-row"><h4 title="Date">Date</h4><input class="col-collapse-checkbox" type="checkbox" aria-label="Collapse column"></div><div class="th-search-row"><input name="date" type="text" placeholder="Search..." value="<?= htmlspecialchars((string)($query['date'] ?? ''), ENT_QUOTES) ?>"></div></th>
          <th data-col="org" onclick="if(event.target.tagName!=='INPUT'){sortTable(2)}"><div class="th-title-row"><h4 title="Band or Event">Band or Event</h4><input class="col-collapse-checkbox" type="checkbox" aria-label="Collapse column"></div><div class="th-search-row"><input name="org_name" type="text" placeholder="Search..." value="<?= htmlspecialchars((string)($query['org_name'] ?? ''), ENT_QUOTES) ?>"></div></th>
          <th data-col="file_type" onclick="if(event.target.tagName!=='INPUT'){sortTable(3)}"><div class="th-title-row"><h4 title="File Type">File Type</h4><input class="col-collapse-checkbox" type="checkbox" aria-label="Collapse column"></div><div class="th-search-row"><input name="file_type" type="text" placeholder="Search..." value="<?= htmlspecialchars((string)($query['file_type'] ?? ''), ENT_QUOTES) ?>"></div></th>
          <th data-col="song_name" onclick="if(event.target.tagName!=='INPUT'){sortTable(4)}"><div class="th-title-row"><h4 title="Song Name">Song Name</h4><input class="col-collapse-checkbox" type="checkbox" aria-label="Collapse column"></div><div class="th-search-row"><input name="song_title" type="text" placeholder="Search..." value="<?= htmlspecialchars((string)($query['song_title'] ?? ''), ENT_QUOTES) ?>"></div></th>
          <th data-col="source_relpath" onclick="if(event.target.tagName!=='INPUT'){sortTable(5)}"><div class="th-title-row"><h4 title="Source Relpath">Source Relpath</h4><input class="col-collapse-checkbox" type="checkbox" aria-label="Collapse column"></div><div class="th-search-row"><input name="source_relpath" type="text" placeholder="Search..." value="<?= htmlspecialchars((string)($query['source_relpath'] ?? ''), ENT_QUOTES) ?>"></div></th>
          <th data-col="download" onclick="if(event.target.tagName!=='INPUT'){sortTable(6)}"><div class="th-title-row"><h4 title="Download / View">Download / View</h4><input class="col-collapse-checkbox" type="checkbox" aria-label="Collapse column"></div><div class="th-search-row"><input name="file_name" type="text" placeholder="Search..." value="<?= htmlspecialchars((string)($query['file_name'] ?? ''), ENT_QUOTES) ?>"></div></th>
          <th data-col="duration" onclick="if(event.target.tagName!=='INPUT'){sortTable(7)}"><div class="th-title-row"><h4 title="Duration">Duration</h4><input class="col-collapse-checkbox" type="checkbox" aria-label="Collapse column"></div><div class="th-search-row"><input name="duration_seconds" type="text" placeholder="Search..." value="<?= htmlspecialchars((string)($query['duration_seconds'] ?? ''), ENT_QUOTES) ?>"></div></th>
          <th data-col="media_info" onclick="if(event.target.tagName!=='INPUT'){sortTable(8)}"><div class="th-title-row"><h4 title="Media File Info">Media File Info</h4><input class="col-collapse-checkbox" type="checkbox" aria-label="Collapse column"></div><div class="th-search-row"><input name="media_info" type="text" placeholder="Search..." value="<?= htmlspecialchars((string)($query['media_info'] ?? ''), ENT_QUOTES) ?>"></div></th>
          <th data-col="musicians" onclick="if(event.target.tagName!=='INPUT'){sortTable(9)}"><div class="th-title-row"><h4 title="Musicians">Musicians</h4><input class="col-collapse-checkbox" type="checkbox" aria-label="Collapse column"></div><div class="th-search-row"><input name="crew" type="text" placeholder="Search..." value="<?= htmlspecialchars((string)($query['crew'] ?? ''), ENT_QUOTES) ?>"></div></th>
        <?php else: ?>
          <th data-col="idx" onclick="if(event.target.tagName!=='INPUT'){sortTable(0)}"><div class="th-title-row"><h4 title="#">#</h4><input class="col-collapse-checkbox" type="checkbox" aria-label="Collapse column"></div></th>
          <th data-col="date" onclick="if(event.target.tagName!=='INPUT'){sortTable(1)}"><div class="th-title-row"><h4 title="Date">Date</h4><input class="col-collapse-checkbox" type="checkbox" aria-label="Collapse column"></div><div class="th-search-row"><input name="date" type="text" placeholder="Search..." value="<?= htmlspecialchars((string)($query['date'] ?? ''), ENT_QUOTES) ?>"></div></th>
          <th data-col="org" onclick="if(event.target.tagName!=='INPUT'){sortTable(2)}"><div class="th-title-row"><h4 title="Org">Org</h4><input class="col-collapse-checkbox" type="checkbox" aria-label="Collapse column"></div><div class="th-search-row"><input name="org_name" type="text" placeholder="Search..." value="<?= htmlspecialchars((string)($query['org_name'] ?? ''), ENT_QUOTES) ?>"></div></th>
          <th data-col="rating" onclick="if(event.target.tagName!=='INPUT'){sortTable(3)}"><div class="th-title-row"><h4 title="Rating">Rating</h4><input class="col-collapse-checkbox" type="checkbox" aria-label="Collapse column"></div><div class="th-search-row"><input name="rating" type="text" placeholder="Search..." value="<?= htmlspecialchars((string)($query['rating'] ?? ''), ENT_QUOTES) ?>"></div></th>
          <th data-col="keywords" onclick="if(event.target.tagName!=='INPUT'){sortTable(4)}"><div class="th-title-row"><h4 title="Keywords">Keywords</h4><input class="col-collapse-checkbox" type="checkbox" aria-label="Collapse column"></div><div class="th-search-row"><input name="keywords" type="text" placeholder="Search..." value="<?= htmlspecialchars((string)($query['keywords'] ?? ''), ENT_QUOTES) ?>"></div></th>
          <th data-col="location" onclick="if(event.target.tagName!=='INPUT'){sortTable(5)}"><div class="th-title-row"><h4 title="Location">Location</h4><input class="col-collapse-checkbox" type="checkbox" aria-label="Collapse column"></div><div class="th-search-row"><input name="location" type="text" placeholder="Search..." value="<?= htmlspecialchars((string)($query['location'] ?? ''), ENT_QUOTES) ?>"></div></th>
          <th data-col="summary" onclick="if(event.target.tagName!=='INPUT'){sortTable(6)}"><div class="th-title-row"><h4 title="Summary">Summary</h4><input class="col-collapse-checkbox" type="checkbox" aria-label="Collapse column"></div><div class="th-search-row"><input name="summary" type="text" placeholder="Search..." value="<?= htmlspecialchars((string)($query['summary'] ?? ''), ENT_QUOTES) ?>"></div></th>
          <th data-col="file_type" onclick="if(event.target.tagName!=='INPUT'){sortTable(7)}"><div class="th-title-row"><h4 title="File Type">File Type</h4><input class="col-collapse-checkbox" type="checkbox" aria-label="Collapse column"></div><div class="th-search-row"><input name="file_type" type="text" placeholder="Search..." value="<?= htmlspecialchars((string)($query['file_type'] ?? ''), ENT_QUOTES) ?>"></div></th>
          <th data-col="song_name" onclick="if(event.target.tagName!=='INPUT'){sortTable(8)}"><div class="th-title-row"><h4 title="Song Name">Song Name</h4><input class="col-collapse-checkbox" type="checkbox" aria-label="Collapse column"></div><div class="th-search-row"><input name="song_title" type="text" placeholder="Search..." value="<?= htmlspecialchars((string)($query['song_title'] ?? ''), ENT_QUOTES) ?>"></div></th>
          <th data-col="download" onclick="if(event.target.tagName!=='INPUT'){sortTable(9)}"><div class="th-title-row"><h4 title="Download / View">Download / View</h4><input class="col-collapse-checkbox" type="checkbox" aria-label="Collapse column"></div><div class="th-search-row"><input name="file_name" type="text" placeholder="Search..." value="<?= htmlspecialchars((string)($query['file_name'] ?? ''), ENT_QUOTES) ?>"></div></th>
          <th data-col="duration" onclick="if(event.target.tagName!=='INPUT'){sortTable(10)}"><div class="th-title-row"><h4 title="Duration">Duration</h4><input class="col-collapse-checkbox" type="checkbox" aria-label="Collapse column"></div><div class="th-search-row"><input name="duration_seconds" type="text" placeholder="Search..." value="<?= htmlspecialchars((string)($query['duration_seconds'] ?? ''), ENT_QUOTES) ?>"></div></th>
          <th data-col="media_info" onclick="if(event.target.tagName!=='INPUT'){sortTable(11)}"><div class="th-title-row"><h4 title="Media File Info">Media File Info</h4><input class="col-collapse-checkbox" type="checkbox" aria-label="Collapse column"></div><div class="th-search-row"><input name="media_info" type="text" placeholder="Search..." value="<?= htmlspecialchars((string)($query['media_info'] ?? ''), ENT_QUOTES) ?>"></div></th>
          <th data-col="musicians" onclick="if(event.target.tagName!=='INPUT'){sortTable(12)}"><div class="th-title-row"><h4 title="Musicians">Musicians</h4><input class="col-collapse-checkbox" type="checkbox" aria-label="Collapse column"></div><div class="th-search-row"><input name="crew" type="text" placeholder="Search..." value="<?= htmlspecialchars((string)($query['crew'] ?? ''), ENT_QUOTES) ?>"></div></th>
        <?php endif; ?>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($rows as $r): ?>
        <tr
          id="media-<?= htmlspecialchars((string)($r['id'] ?? ''), ENT_QUOTES) ?>"
          class="media-row"
          data-date="<?= htmlspecialchars($r['date'] ?? '', ENT_QUOTES) ?>"
          data-org="<?= htmlspecialchars($r['org_name'] ?? '', ENT_QUOTES) ?>"
        >
          <?php if ($isGighive): ?>
            <td data-col="idx"><?= htmlspecialchars((string)$r['idx'], ENT_QUOTES) ?></td>
            <td data-col="date"><?= htmlspecialchars($r['date'] ?? '', ENT_QUOTES) ?></td>
            <td data-col="org"><?= htmlspecialchars($r['org_name'] ?? '', ENT_QUOTES) ?></td>
            <td data-col="file_type"><?= htmlspecialchars($r['type'] ?? '', ENT_QUOTES) ?></td>
            <td data-col="song_name"><?= htmlspecialchars($r['songTitle'] ?? '', ENT_QUOTES) ?></td>
            <td data-col="source_relpath"><?= htmlspecialchars($r['sourceRelpath'] ?? '', ENT_QUOTES) ?></td>
            <td data-col="download">
              <?php if (!empty($r['url'])): ?>
                <a href="<?= htmlspecialchars($r['url'], ENT_QUOTES) ?>" target="_blank">Download / View</a>
              <?php endif; ?>
            </td>
            <td data-col="duration" data-num="<?= htmlspecialchars((string)($r['durationSec'] ?? ''), ENT_QUOTES) ?>">
              <?= htmlspecialchars($r['duration'] ?? '', ENT_QUOTES) ?>
            </td>
            <td data-col="media_info"><div class="media-file-info"><?= htmlspecialchars($r['mediaSummary'] ?? '', ENT_QUOTES) ?></div></td>
            <td data-col="musicians"><?= htmlspecialchars($r['crew'] ?? '', ENT_QUOTES) ?></td>
          <?php else: ?>
            <td data-col="idx"><?= htmlspecialchars((string)$r['idx'], ENT_QUOTES) ?></td>
            <td data-col="date"><?= htmlspecialchars($r['date'] ?? '', ENT_QUOTES) ?></td>
            <td data-col="org"><?= htmlspecialchars($r['org_name'] ?? '', ENT_QUOTES) ?></td>
            <td data-col="rating"><?= htmlspecialchars($r['rating'] ?? '', ENT_QUOTES) ?></td>
            <td data-col="keywords"><?= htmlspecialchars($r['keywords'] ?? '', ENT_QUOTES) ?></td>
            <td data-col="location"><?= htmlspecialchars($r['location'] ?? '', ENT_QUOTES) ?></td>
            <td data-col="summary"><?= htmlspecialchars($r['summary'] ?? '', ENT_QUOTES) ?></td>
            <td data-col="file_type"><?= htmlspecialchars($r['type'] ?? '', ENT_QUOTES) ?></td>
            <td data-col="song_name"><?= htmlspecialchars($r['songTitle'] ?? '', ENT_QUOTES) ?></td>
            <td data-col="download">
              <?php if (!empty($r['url'])): ?>
                <a href="<?= htmlspecialchars($r['url'], ENT_QUOTES) ?>" target="_blank">Download / View</a>
              <?php endif; ?>
            </td>
            <td data-col="duration" data-num="<?= htmlspecialchars((string)($r['durationSec'] ?? ''), ENT_QUOTES) ?>">
              <?= htmlspecialchars($r['duration'] ?? '', ENT_QUOTES) ?>
            </td>
            <td data-col="media_info"><div class="media-file-info"><?= htmlspecialchars($r['mediaSummary'] ?? '', ENT_QUOTES) ?></div></td>
            <td data-col="musicians"><?= htmlspecialchars($r['crew'] ?? '', ENT_QUOTES) ?></td>
          <?php endif; ?>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  </form>

  <?php if (!empty($pagination['enabled'])): ?>
    <div class="pagination">
      <div class="summary">
        Showing <?= htmlspecialchars((string)($pagination['start'] ?? 0), ENT_QUOTES) ?>
        – <?= htmlspecialchars((string)($pagination['end'] ?? 0), ENT_QUOTES) ?>
        of <?= htmlspecialchars((string)($pagination['total'] ?? 0), ENT_QUOTES) ?>
      </div>
      <div class="links">
        <?php if (($pagination['page'] ?? 1) > 1): ?>
          <a href="<?= htmlspecialchars($buildUrl((int)$pagination['page'] - 1), ENT_QUOTES) ?>">Prev</a>
        <?php else: ?>
          <span>Prev</span>
        <?php endif; ?>

        <span>
          Page <?= htmlspecialchars((string)($pagination['page'] ?? 1), ENT_QUOTES) ?>
          of <?= htmlspecialchars((string)($pagination['pageCount'] ?? 1), ENT_QUOTES) ?>
        </span>

        <?php if (($pagination['page'] ?? 1) < ($pagination['pageCount'] ?? 1)): ?>
          <a href="<?= htmlspecialchars($buildUrl((int)$pagination['page'] + 1), ENT_QUOTES) ?>">Next</a>
        <?php else: ?>
          <span>Next</span>
        <?php endif; ?>
      </div>
    </div>
  <?php endif; ?>

  <script>
    const targetDate = <?= isset($targetDate) && $targetDate !== null ? json_encode($targetDate) : 'null' ?>;
    const targetOrg  = <?= isset($targetOrg)  && $targetOrg  !== null ? json_encode($targetOrg)  : 'null' ?>;

    function enableResizableColumns(tableId){
      const table=document.getElementById(tableId);
      if(!table){return;}
      const ths=Array.from(table.querySelectorAll('thead th'));
      if(!ths.length){return;}

      // Initialize widths so drag resizing doesn't collapse columns
      ths.forEach((th)=>{
        const w=Math.max(40, th.getBoundingClientRect().width);
        th.style.width=w+'px';
      });

      let active=null;
      let startX=0;
      let startW=0;

      const onMove=(e)=>{
        if(!active){return;}
        const clientX=(e.touches && e.touches[0]) ? e.touches[0].clientX : e.clientX;
        const dx=clientX-startX;
        const newW=Math.max(40, startW+dx);
        active.th.style.width=newW+'px';
        e.preventDefault();
      };
      const onUp=()=>{
        if(!active){return;}
        active=null;
        document.body.classList.remove('resizing-col');
        document.removeEventListener('mousemove', onMove);
        document.removeEventListener('mouseup', onUp);
        document.removeEventListener('touchmove', onMove, {passive:false});
        document.removeEventListener('touchend', onUp);
      };

      ths.forEach((th)=>{
        const handle=document.createElement('div');
        handle.className='col-resizer';
        handle.addEventListener('mousedown',(e)=>{
          e.stopPropagation();
          active={th};
          startX=e.clientX;
          startW=th.getBoundingClientRect().width;
          document.body.classList.add('resizing-col');
          document.addEventListener('mousemove', onMove);
          document.addEventListener('mouseup', onUp);
        });
        handle.addEventListener('touchstart',(e)=>{
          e.stopPropagation();
          active={th};
          startX=(e.touches && e.touches[0]) ? e.touches[0].clientX : 0;
          startW=th.getBoundingClientRect().width;
          document.body.classList.add('resizing-col');
          document.addEventListener('touchmove', onMove, {passive:false});
          document.addEventListener('touchend', onUp);
        }, {passive:true});
        th.appendChild(handle);
      });
    }

    document.querySelectorAll('#searchForm thead .th-search-row').forEach((row)=>{
      row.addEventListener('click',(e)=>e.stopPropagation());
    });

    document.querySelectorAll('#searchForm thead input').forEach((input)=>{
      input.addEventListener('click',(e)=>e.stopPropagation());
      input.addEventListener('keydown',(e)=>{
        if(e.key !== 'Enter'){return;}
        e.preventDefault();
        const form = document.getElementById('searchForm');
        if(!form){return;}
        if(typeof form.requestSubmit === 'function'){
          form.requestSubmit();
        }else{
          alert('Enter pressed..searching');
          form.submit();
        }
      });
    });

    function setColumnCollapsed(table, colIndex, collapsed){
      if(!table){return;}
      const headRow = table.tHead && table.tHead.rows && table.tHead.rows[0] ? table.tHead.rows[0] : null;
      if(!headRow){return;}
      const th = headRow.cells[colIndex];
      if(!th){return;}

      if(collapsed){
        if(!th.dataset.prevWidth){
          const currentStyleWidth = (th.style && th.style.width) ? th.style.width : '';
          th.dataset.prevWidth = currentStyleWidth !== '' ? currentStyleWidth : (Math.max(40, th.getBoundingClientRect().width) + 'px');
        }
        th.classList.add('col-collapsed');
        th.style.width = '32px';
      }else{
        th.classList.remove('col-collapsed');
        if(th.dataset.prevWidth){
          th.style.width = th.dataset.prevWidth;
        }
      }

      const bodyRows = Array.from(table.tBodies[0]?.rows ?? []);
      bodyRows.forEach((row)=>{
        const cell = row.cells[colIndex];
        if(!cell){return;}
        if(collapsed){
          cell.classList.add('col-collapsed');
        }else{
          cell.classList.remove('col-collapsed');
        }
      });
    }

    function sortTable(colIndex){
      const table=document.getElementById('searchableTable');
      const tbody=table.tBodies[0];
      const rows=Array.from(tbody.rows);
      const asc=table.dataset.sortOrder!=='asc';
      rows.sort((a,b)=>{
        const cellA=a.cells[colIndex];
        const cellB=b.cells[colIndex];
        const dA=cellA?.dataset?.num ?? '';
        const dB=cellB?.dataset?.num ?? '';
        if(dA!==''||dB!==''){
          const nA=parseFloat(dA||'0');
          const nB=parseFloat(dB||'0');
          return asc? nA-nB : nB-nA;
        }
        let A=cellA.innerText.trim().toUpperCase();
        let B=cellB.innerText.trim().toUpperCase();
        let nA=parseFloat(A), nB=parseFloat(B);
        if(!isNaN(nA)&&!isNaN(nB)) return asc? nA-nB : nB-nA;
        return asc? A.localeCompare(B) : B.localeCompare(A);
      });
      table.dataset.sortOrder=asc? 'asc' : 'desc';
      rows.forEach(r=>tbody.appendChild(r));
    }

    document.addEventListener('DOMContentLoaded',()=>{
      enableResizableColumns('searchableTable');

      const table = document.getElementById('searchableTable');
      const checkboxes = Array.from(document.querySelectorAll('#searchableTable thead .col-collapse-checkbox'));
      checkboxes.forEach((cb)=>{
        cb.addEventListener('click',(e)=>e.stopPropagation());
        cb.addEventListener('change',(e)=>{
          const th = cb.closest('th');
          if(!th || !table){return;}
          const colIndex = Array.from(th.parentElement?.children ?? []).indexOf(th);
          if(colIndex < 0){return;}
          setColumnCollapsed(table, colIndex, cb.checked);
        });
      });

      if(!targetDate || !targetOrg){return;}
      const selector=`.media-row[data-date="${targetDate}"][data-org="${targetOrg}"]`;
      const row=document.querySelector(selector);
      if(!row){return;}
      row.scrollIntoView({behavior:'smooth',block:'center'});
      row.classList.add('highlighted-jam');
    });
  </script>
</body>
</html>
