<?php /** @var array $rows */ ?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <?php
  $appFlavor = isset($appFlavor) ? (string)$appFlavor : 'defaultcodebase';
  $isGighive = strtolower(trim($appFlavor)) === 'gighive';
  $maxWidth = $isGighive ? 900 : 1350;
  ?>
  <?php if (!$isGighive): ?>
  <link rel="stylesheet" href="../header.css">
  <?php endif; ?>
  <?php include __DIR__ . '/../../../includes/ga_tag.php'; ?>
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
    .header-top{margin:0 0 0.5rem 0;display:flex;align-items:flex-start;justify-content:space-between;gap:1rem;width:100%;max-width:none;}
    .header-top .home-link a{text-decoration:none;color:var(--link);}
    .pagination{max-width:<?= (int)$maxWidth ?>px;margin:0 auto 0.75rem auto;display:flex;align-items:center;justify-content:space-between;gap:1rem;}
    .pagination .links a,.pagination .links span{display:inline-block;padding:4px 8px;border:1px solid var(--border);border-radius:4px;text-decoration:none;color:var(--link);}
    .pagination .links span{color:var(--muted);border-color:var(--border);}
    .header-block{max-width:<?= (int)$maxWidth ?>px;margin:0 0 0.75rem 0;text-align:left;}
    .header-top.header-block{max-width:none;width:100%;}
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

    #searchableTable th[data-col="thumbnail"],
    #searchableTable td[data-col="thumbnail"]{
      white-space:nowrap;
      width:240px;
      min-width:240px;
      text-align:center;
    }

    #searchableTable td[data-col="thumbnail"] img{margin:0 auto;}

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
    .th-title-row h4{line-height:16px;max-height:32px;overflow:hidden;flex:1 1 auto;min-width:0;}
    .th-title-row h4{margin:0;}
    .th-title-row .col-collapse-checkbox{margin:0;}
    .th-title-row .th-controls{display:flex;align-items:flex-start;gap:4px;flex:0 0 auto;}
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

    .col-hide-btn{width:16px;height:16px;display:inline-block;vertical-align:middle;padding:0;border:0;background-color:transparent;background-repeat:no-repeat;background-position:center;background-size:16px 16px;cursor:pointer;}
    .col-hide-btn{background-image:url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16'><rect x='0.75' y='0.75' width='14.5' height='14.5' fill='none' stroke='%23d10000' stroke-width='1.5'/><path d='M4 4 L12 12 M12 4 L4 12' stroke='%23d10000' stroke-width='2' stroke-linecap='square'/></svg>");}
    .col-hide-btn:focus{outline:2px solid var(--link);outline-offset:2px;}
    th.col-hidden,td.col-hidden{display:none;}

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
   $isAdmin = ($user === 'admin');
   ?>
   <div class="header-top header-block">
     <div class="user-indicator">User is logged in as <?= htmlspecialchars($user, ENT_QUOTES) ?>. v1.0 view</div>
     <div class="home-link">
       <a href="/index.php">Return to Home Page</a><br>
       <a href="database.php" id="resetViewLink">Reset to Default View</a>
     </div>
   </div>
  <h1 id="all" class="header-block">Media Library</h1>

  <?php
  $pagination = $pagination ?? ['enabled' => false];
  $query = $query ?? [];

  $columns = $isGighive
      ? [
          ['key' => 'idx', 'label' => '#', 'title' => '#', 'search' => null],
          ['key' => 'date', 'label' => 'Date', 'title' => 'Date', 'search' => 'date'],
          ['key' => 'org', 'label' => 'Band or Event', 'title' => 'Band or Event', 'search' => 'org_name'],
          ['key' => 'file_type', 'label' => 'File Type', 'title' => 'File Type', 'search' => 'file_type'],
          ['key' => 'song_name', 'label' => 'Song Name', 'title' => 'Song Name', 'search' => 'item_label'],
          ['key' => 'source_relpath', 'label' => 'File Path', 'title' => 'File Path', 'search' => 'source_relpath'],
          ['key' => 'thumbnail', 'label' => 'Thumbnail', 'title' => 'Thumbnail', 'search' => null, 'h4Style' => 'white-space:nowrap;'],
          ['key' => 'download', 'label' => 'Download / View', 'title' => 'Download / View', 'search' => null],
          ['key' => 'duration', 'label' => 'Duration', 'title' => 'Duration', 'search' => 'duration_seconds'],
          ['key' => 'media_info', 'label' => 'Media File Info', 'title' => 'Media File Info', 'search' => 'media_info'],
          ['key' => 'musicians', 'label' => 'Musicians', 'title' => 'Musicians', 'search' => 'crew'],
          ['key' => 'checksum_sha256', 'label' => 'SHA256', 'title' => 'SHA256', 'search' => null],
          ['key' => 'media_created_at', 'label' => 'Media Create Date', 'title' => 'Media Create Date', 'search' => null],
      ]
      : [
          ['key' => 'idx', 'label' => '#', 'title' => '#', 'search' => null],
          ['key' => 'date', 'label' => 'Date', 'title' => 'Date', 'search' => 'date'],
          ['key' => 'org', 'label' => 'Org', 'title' => 'Org', 'search' => 'org_name'],
          ['key' => 'rating', 'label' => 'Rating', 'title' => 'Rating', 'search' => 'rating'],
          ['key' => 'keywords', 'label' => 'Keywords', 'title' => 'Keywords', 'search' => 'keywords'],
          ['key' => 'location', 'label' => 'Location', 'title' => 'Location', 'search' => 'location'],
          ['key' => 'summary', 'label' => 'Summary', 'title' => 'Summary', 'search' => 'summary'],
          ['key' => 'file_type', 'label' => 'File Type', 'title' => 'File Type', 'search' => 'file_type'],
          ['key' => 'song_name', 'label' => 'Song Name', 'title' => 'Song Name', 'search' => 'item_label'],
          ['key' => 'thumbnail', 'label' => 'Thumbnail', 'title' => 'Thumbnail', 'search' => null, 'h4Style' => 'white-space:nowrap;'],
          ['key' => 'download', 'label' => 'Download / View', 'title' => 'Download / View', 'search' => null],
          ['key' => 'duration', 'label' => 'Duration', 'title' => 'Duration', 'search' => 'duration_seconds'],
          ['key' => 'media_info', 'label' => 'Media File Info', 'title' => 'Media File Info', 'search' => 'media_info'],
          ['key' => 'musicians', 'label' => 'Musicians', 'title' => 'Musicians', 'search' => 'crew'],
          ['key' => 'checksum_sha256', 'label' => 'SHA256', 'title' => 'SHA256', 'search' => null],
          ['key' => 'media_created_at', 'label' => 'Media Create Date', 'title' => 'Media Create Date', 'search' => null],
      ];

  if ($isAdmin) {
      array_unshift($columns, ['key' => 'edit', 'label' => 'Edit', 'title' => 'Edit', 'search' => null]);
      array_unshift($columns, ['key' => 'delete', 'label' => 'Delete', 'title' => 'Delete', 'search' => null]);
  }

  $searchKeys = [];
  foreach ($columns as $c) {
      if (!empty($c['search'])) {
          $searchKeys[] = (string)$c['search'];
      }
  }
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

  <div class="header-block" style="margin-bottom:0.5rem;white-space:nowrap;max-width:none;">
    Search will only take place after you fill in one or more fields and hit Enter.  Column widths adjustable.  X removes column.  Checkbox minimizes column width.  Clicking on label in header sorts column.  Use Reset to Default View to clear layout and filters.
    <br>
    | or & or ! allowed in search textboxes.  Pipe symbol means OR, ampersand means AND, ! means NOT.  You can combine these, but the ! takes precedence.  Precedence rule is ! > & > |.  Example: ".mp4&water&!ultra&!source"
  </div>
  <?php if ($isAdmin): ?>
    <div class="header-block" style="margin-bottom:0.5rem;max-width:none;">
      <button type="button" id="deleteSelectedBtn" style="padding:6px 10px;border:1px solid #dc2626;border-radius:6px;background:transparent;color:var(--text);cursor:pointer;" disabled>Delete Media File(s)</button>
      <span id="deleteSelectedStatus" class="user-indicator" style="margin-left:0.5rem;"></span>
      <button type="button" id="editSaveBtn" style="margin-left:0.75rem;padding:6px 10px;border:1px solid #0366d6;border-radius:6px;background:transparent;color:var(--text);cursor:pointer;" disabled>Save Edit</button>
      <span id="editSaveStatus" class="user-indicator" style="margin-left:0.5rem;"></span>
      <span id="editMusiciansCallout" class="user-indicator" style="margin-left:0.5rem;"></span>
    </div>
  <?php endif; ?>
  <?php if ($hasSearch): ?>
    <div id="searchStatus" class="header-block">
      <?= htmlspecialchars((string)($pagination['total'] ?? 0), ENT_QUOTES) ?> rows found
    </div>
  <?php else: ?>
    <div id="searchStatus" class="header-block"></div>
  <?php endif; ?>

  <?php if (!empty($searchErrors) && is_array($searchErrors)): ?>
    <div class="header-block" style="border:1px solid #dc2626;">
      <?php foreach ($searchErrors as $msg): ?>
        <div><?= htmlspecialchars((string)$msg, ENT_QUOTES) ?></div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
  <?php if (!empty($searchWarnings) && is_array($searchWarnings)): ?>
    <div class="header-block" style="border:1px solid #eab308;">
      <?php foreach ($searchWarnings as $msg): ?>
        <div><?= htmlspecialchars((string)$msg, ENT_QUOTES) ?></div>
      <?php endforeach; ?>
    </div>
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

  <form id="searchForm" method="get" action="database.php">
  <div style="margin:0 auto;">
  <table id="searchableTable" class="<?= $isGighive ? 'table-gighive' : 'table-defaultcodebase' ?>" data-sort-order="asc">
    <thead>
      <tr>
        <?php foreach ($columns as $col): ?>
          <?php $colKey = (string)$col['key']; ?>
          <th data-col="<?= htmlspecialchars($colKey, ENT_QUOTES) ?>">
            <div class="th-title-row">
              <h4
                title="<?= htmlspecialchars((string)$col['title'], ENT_QUOTES) ?>"
                <?= !empty($col['h4Style']) ? ('style="' . htmlspecialchars((string)$col['h4Style'], ENT_QUOTES) . '"') : '' ?>
              >
                <?php if ($colKey === 'delete'): ?>
                  <input type="checkbox" id="deleteSelectAll" aria-label="Select all" />
                <?php elseif ($colKey === 'edit'): ?>
                  <?= htmlspecialchars((string)$col['label'], ENT_QUOTES) ?>
                <?php else: ?>
                  <?= htmlspecialchars((string)$col['label'], ENT_QUOTES) ?>
                <?php endif; ?>
              </h4>
              <div class="th-controls">
                <input class="col-collapse-checkbox" type="checkbox" aria-label="Collapse column">
                <button type="button" class="col-hide-btn" aria-label="Hide column"></button>
              </div>
            </div>
            <?php if (!empty($col['search'])): ?>
              <div class="th-search-row">
                <input
                  name="<?= htmlspecialchars((string)$col['search'], ENT_QUOTES) ?>"
                  type="text"
                  placeholder="Search..."
                  value="<?= htmlspecialchars((string)($query[(string)$col['search']] ?? ''), ENT_QUOTES) ?>"
                >
              </div>
            <?php endif; ?>
          </th>
        <?php endforeach; ?>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($rows as $r): ?>
        <tr
          id="media-<?= htmlspecialchars((string)($r['id'] ?? ''), ENT_QUOTES) ?>"
          class="media-row"
          data-date="<?= htmlspecialchars($r['date'] ?? '', ENT_QUOTES) ?>"
          data-org="<?= htmlspecialchars($r['org_name'] ?? '', ENT_QUOTES) ?>"
          data-event-id="<?= htmlspecialchars((string)($r['event_id'] ?? ''), ENT_QUOTES) ?>"
          data-event-item-id="<?= htmlspecialchars((string)($r['event_item_id'] ?? ''), ENT_QUOTES) ?>"
        >
          <?php foreach ($columns as $col): ?>
            <?php $key = (string)$col['key']; ?>
            <?php if ($key === 'delete'): ?>
              <td data-col="delete">
                <?php $deleteId = (int)($r['asset_id'] ?? $r['id'] ?? 0); ?>
                <input
                  class="delete-checkbox"
                  type="checkbox"
                  value="<?= htmlspecialchars((string)($deleteId > 0 ? $deleteId : ''), ENT_QUOTES) ?>"
                  aria-label="Select file for deletion"
                  <?= $deleteId > 0 ? '' : 'disabled' ?>
                />
              </td>
            <?php elseif ($key === 'edit'): ?>
              <td data-col="edit">
                <?php $editId = (int)($r['id'] ?? 0); ?>
                <input
                  class="edit-checkbox"
                  type="checkbox"
                  value="<?= htmlspecialchars((string)($editId > 0 ? $editId : ''), ENT_QUOTES) ?>"
                  aria-label="Select row for editing"
                  <?= $editId > 0 ? '' : 'disabled' ?>
                />
              </td>
            <?php elseif ($key === 'duration'): ?>
              <td data-col="duration" data-num="<?= htmlspecialchars((string)($r['durationSec'] ?? ''), ENT_QUOTES) ?>">
                <?= htmlspecialchars($r['duration'] ?? '', ENT_QUOTES) ?>
              </td>
            <?php elseif ($key === 'media_created_at'): ?>
              <td data-col="media_created_at"><?= htmlspecialchars($r['mediaCreatedAt'] ?? '', ENT_QUOTES) ?></td>
            <?php elseif ($key === 'media_info'): ?>
              <td data-col="media_info"><div class="media-file-info"><?= htmlspecialchars($r['mediaSummary'] ?? '', ENT_QUOTES) ?></div></td>
            <?php elseif ($key === 'download'): ?>
              <td data-col="download">
                <?php if (!empty($r['url'])): ?>
                  <a
                    href="<?= htmlspecialchars($r['url'], ENT_QUOTES) ?>"
                    class="media-download-link"
                    data-media-id="<?= htmlspecialchars((string)($r['id'] ?? ''), ENT_QUOTES) ?>"
                    data-file-type="<?= htmlspecialchars((string)($r['type'] ?? ''), ENT_QUOTES) ?>"
                    data-item-label="<?= htmlspecialchars((string)($r['itemLabel'] ?? ''), ENT_QUOTES) ?>"
                    data-org-name="<?= htmlspecialchars((string)($r['org_name'] ?? ''), ENT_QUOTES) ?>"
                    data-date="<?= htmlspecialchars((string)($r['date'] ?? ''), ENT_QUOTES) ?>"
                    data-checksum-sha256="<?= htmlspecialchars((string)($r['checksumSha256'] ?? ''), ENT_QUOTES) ?>"
                    data-source-relpath="<?= htmlspecialchars((string)($r['sourceRelpath'] ?? ''), ENT_QUOTES) ?>"
                    data-download-source="download_link"
                    target="_blank"
                  >Download / View</a>
                <?php endif; ?>
              </td>
            <?php elseif ($key === 'thumbnail'): ?>
              <td data-col="thumbnail">
                <?php
                  $sha = (string)($r['checksumSha256'] ?? '');
                  $isVideo = ((string)($r['type'] ?? '')) === 'video';
                  $isAudio = ((string)($r['type'] ?? '')) === 'audio';
                  $thumbUrl = ($isVideo && $sha !== '') ? ('/video/thumbnails/' . rawurlencode($sha) . '.png') : ($isAudio ? '/images/audiofile.png' : '');
                ?>
                <?php if ($thumbUrl !== ''): ?>
                  <?php $thumbWidth = $isVideo ? 240 : 96; ?>
                  <?php if (!empty($r['url'])): ?>
                    <a
                      href="<?= htmlspecialchars((string)$r['url'], ENT_QUOTES) ?>"
                      class="media-download-link"
                      data-media-id="<?= htmlspecialchars((string)($r['id'] ?? ''), ENT_QUOTES) ?>"
                      data-file-type="<?= htmlspecialchars((string)($r['type'] ?? ''), ENT_QUOTES) ?>"
                      data-item-label="<?= htmlspecialchars((string)($r['itemLabel'] ?? ''), ENT_QUOTES) ?>"
                      data-org-name="<?= htmlspecialchars((string)($r['org_name'] ?? ''), ENT_QUOTES) ?>"
                      data-date="<?= htmlspecialchars((string)($r['date'] ?? ''), ENT_QUOTES) ?>"
                      data-checksum-sha256="<?= htmlspecialchars((string)($r['checksumSha256'] ?? ''), ENT_QUOTES) ?>"
                      data-source-relpath="<?= htmlspecialchars((string)($r['sourceRelpath'] ?? ''), ENT_QUOTES) ?>"
                      data-download-source="thumbnail"
                      target="_blank"
                    >
                      <img
                        src="<?= htmlspecialchars($thumbUrl, ENT_QUOTES) ?>"
                        alt=""
                        loading="lazy"
                        style="width:<?= (int)$thumbWidth ?>px; height:auto; display:block;"
                        onerror="this.style.display='none';"
                      />
                    </a>
                  <?php else: ?>
                    <img
                      src="<?= htmlspecialchars($thumbUrl, ENT_QUOTES) ?>"
                      alt=""
                      loading="lazy"
                      style="width:<?= (int)$thumbWidth ?>px; height:auto; display:block;"
                      onerror="this.style.display='none';"
                    />
                  <?php endif; ?>
                <?php endif; ?>
              </td>
            <?php else: ?>
              <?php
                $value = '';
                if ($key === 'idx') {
                    $value = (string)($r['idx'] ?? '');
                } elseif ($key === 'date') {
                    $value = (string)($r['date'] ?? '');
                } elseif ($key === 'org') {
                    $value = (string)($r['org_name'] ?? '');
                } elseif ($key === 'rating') {
                    $value = (string)($r['rating'] ?? '');
                } elseif ($key === 'keywords') {
                    $value = (string)($r['keywords'] ?? '');
                } elseif ($key === 'location') {
                    $value = (string)($r['location'] ?? '');
                } elseif ($key === 'summary') {
                    $value = (string)($r['summary'] ?? '');
                } elseif ($key === 'file_type') {
                    $value = (string)($r['type'] ?? '');
                } elseif ($key === 'song_name') {
                    $value = (string)($r['itemLabel'] ?? '');
                } elseif ($key === 'source_relpath') {
                    $value = (string)($r['sourceRelpath'] ?? '');
                } elseif ($key === 'musicians') {
                    $value = (string)($r['crew'] ?? '');
                } elseif ($key === 'checksum_sha256') {
                    $value = (string)($r['checksumSha256'] ?? '');
                }
              ?>
              <?php if ($isAdmin && ($key === 'org' || (!$isGighive && ($key === 'rating' || $key === 'keywords' || $key === 'location' || $key === 'summary' || $key === 'musicians')) || $key === 'song_name')): ?>
                <?php
                  $inputName = '';
                  if ($key === 'org') {
                      $inputName = 'org_name';
                  } elseif ($key === 'rating') {
                      $inputName = 'rating';
                  } elseif ($key === 'keywords') {
                      $inputName = 'keywords';
                  } elseif ($key === 'location') {
                      $inputName = 'location';
                  } elseif ($key === 'summary') {
                      $inputName = 'summary';
                  } elseif ($key === 'song_name') {
                      $inputName = 'item_label';
                  } elseif ($key === 'musicians') {
                      $inputName = 'participants_csv';
                  }
                ?>
                <td data-col="<?= htmlspecialchars($key, ENT_QUOTES) ?>">
                  <span class="edit-cell-text"><?= htmlspecialchars($value, ENT_QUOTES) ?></span>
                  <input class="edit-cell-input" type="text" data-field="<?= htmlspecialchars($inputName, ENT_QUOTES) ?>" value="<?= htmlspecialchars($value, ENT_QUOTES) ?>" style="display:none;width:100%;box-sizing:border-box;" />
                </td>
              <?php else: ?>
                <td data-col="<?= htmlspecialchars($key, ENT_QUOTES) ?>"><?= htmlspecialchars($value, ENT_QUOTES) ?></td>
              <?php endif; ?>
            <?php endif; ?>
          <?php endforeach; ?>
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
    const isAdmin = <?= $isAdmin ? 'true' : 'false' ?>;
    const basicUser = <?= json_encode($user) ?>;
    const viewMode = <?= $isGighive ? json_encode('gighive') : json_encode('defaultcodebase') ?>;
    const supportsExtendedSessionMetadata = viewMode === 'defaultcodebase';
    const supportsParticipantsEdit = viewMode === 'defaultcodebase';

    function debounce(fn, ms){
      let t = null;
      return function(){
        const args = arguments;
        if(t){ clearTimeout(t); }
        t = setTimeout(()=>fn.apply(null, args), ms);
      };
    }

    function setEditStatus(text){
      const el = document.getElementById('editSaveStatus');
      if(!el){ return; }
      el.textContent = String(text || '');
    }

    function setMusiciansCallout(existing, added){
      const el = document.getElementById('editMusiciansCallout');
      if(!el){ return; }
      const ex = Array.isArray(existing) ? existing : [];
      const nw = Array.isArray(added) ? added : [];
      let parts = [];
      if(ex.length){ parts.push('Existing: ' + ex.join(', ')); }
      if(nw.length){ parts.push('New: ' + nw.join(', ')); }
      el.textContent = parts.join(' | ');
    }

    function setMusiciansCalloutMessage(text){
      const el = document.getElementById('editMusiciansCallout');
      if(!el){ return; }
      el.textContent = String(text || '');
    }

    function clearMusiciansCallout(){
      const el = document.getElementById('editMusiciansCallout');
      if(el){ el.textContent = ''; }
    }

    function rowGetEventId(row){
      const v = row && row.dataset ? String(row.dataset.eventId || '') : '';
      return v;
    }

    function rowGetEventItemId(row){
      const v = row && row.dataset ? String(row.dataset.eventItemId || '') : '';
      return v;
    }

    function setRowEditMode(row, active){
      if(!row){ return; }
      row.classList.toggle('row-editing', !!active);
      const inputs = row.querySelectorAll('input.edit-cell-input');
      const spans = row.querySelectorAll('span.edit-cell-text');
      spans.forEach((s)=>{ s.style.display = active ? 'none' : ''; });
      inputs.forEach((i)=>{ i.style.display = active ? '' : 'none'; });
    }

    function getActiveEditRow(){
      const table = document.getElementById('searchableTable');
      if(!table){ return null; }
      const rows = Array.from(table.querySelectorAll('tbody tr.media-row'));
      for(const r of rows){
        const cb = r.querySelector('input.edit-checkbox');
        if(cb && cb instanceof HTMLInputElement && cb.checked){
          return r;
        }
      }
      return null;
    }

    function clearOtherEditChecks(keepRow){
      const table = document.getElementById('searchableTable');
      if(!table){ return; }
      const rows = Array.from(table.querySelectorAll('tbody tr.media-row'));
      rows.forEach((r)=>{
        const cb = r.querySelector('input.edit-checkbox');
        if(!cb || !(cb instanceof HTMLInputElement)){ return; }
        if(keepRow && r === keepRow){ return; }
        cb.checked = false;
        setRowEditMode(r, false);
      });
    }

    function updateAllVisibleRowsForEvent(eventId, fields){
      if(!eventId){ return; }
      const table = document.getElementById('searchableTable');
      if(!table){ return; }
      const rows = Array.from(table.querySelectorAll('tbody tr.media-row'));
      rows.forEach((r)=>{
        if(String(r.dataset.eventId || '') !== String(eventId)){ return; }
        const mappings = [
          ['org', 'org_name'],
        ];
        if(supportsExtendedSessionMetadata){
          mappings.push(
            ['rating', 'rating'],
            ['keywords', 'keywords'],
            ['location', 'location'],
            ['summary', 'summary']
          );
        }
        if(supportsParticipantsEdit){
          mappings.push(['musicians', 'participants']);
        }
        mappings.forEach(([col, field])=>{
          if(typeof fields[field] !== 'string'){ return; }
          const td = r.querySelector('td[data-col="' + col + '"]');
          const span = td ? td.querySelector('span.edit-cell-text') : null;
          const inp = td ? td.querySelector('input.edit-cell-input') : null;
          if(span){ span.textContent = fields[field]; }
          if(inp && inp instanceof HTMLInputElement){ inp.value = fields[field]; }
        });
        if(typeof fields.org_name === 'string'){
          r.dataset.org = fields.org_name;
        }
      });
    }


    function updateAllVisibleRowsForEventItem(eventItemId, itemLabel){
      if(!eventItemId){ return; }
      const table = document.getElementById('searchableTable');
      if(!table){ return; }
      const rows = Array.from(table.querySelectorAll('tbody tr.media-row'));
      rows.forEach((r)=>{
        if(String(r.dataset.eventItemId || '') !== String(eventItemId)){ return; }
        const tdSong = r.querySelector('td[data-col="song_name"]');
        const span = tdSong ? tdSong.querySelector('span.edit-cell-text') : null;
        const inp = tdSong ? tdSong.querySelector('input.edit-cell-input') : null;
        if(span){ span.textContent = itemLabel; }
        if(inp && inp instanceof HTMLInputElement){ inp.value = itemLabel; }
      });
    }

    async function previewMusicians(musiciansCsv){
      if(!isAdmin){ return; }
      const raw = String(musiciansCsv || '').trim();
      if(raw === ''){
        clearMusiciansCallout();
        return;
      }
      try{
        const resp = await fetch('/db/database_edit_musicians_preview.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ participants_csv: raw }),
          credentials: 'same-origin'
        });
        const data = await resp.json().catch(()=>null);
        if(!resp.ok || !data || !data.success){
          const errs = data && Array.isArray(data.errors) ? data.errors : [];
          const msg = errs.length ? errs.join(' | ') : (data && (data.message || data.error) ? String(data.message || data.error) : '');
          if(msg){
            setMusiciansCalloutMessage(msg);
          } else {
            clearMusiciansCallout();
          }
          return;
        }
        setMusiciansCallout(data.existing || [], data.new || []);
      }catch(e){
        clearMusiciansCallout();
      }
    }

    function enableAdminInlineEdit(){
      if(!isAdmin){ return; }
      const table = document.getElementById('searchableTable');
      const saveBtn = document.getElementById('editSaveBtn');
      if(!table){ return; }

      const rows = Array.from(table.querySelectorAll('tbody tr.media-row'));
      const setSaveEnabled = function(enabled){
        if(!saveBtn || !(saveBtn instanceof HTMLButtonElement)){
          return;
        }
        saveBtn.disabled = !enabled;
      };

      rows.forEach((row)=>{
        const cb = row.querySelector('input.edit-checkbox');
        if(!cb || !(cb instanceof HTMLInputElement)){
          return;
        }

        const editInputs = Array.from(row.querySelectorAll('input.edit-cell-input'));
        editInputs.forEach((input) => {
          if(!(input instanceof HTMLInputElement)){ return; }
          input.addEventListener('keydown', function(e){
            if(e.key !== 'Enter'){ return; }
            if(!cb.checked){ return; }
            e.preventDefault();
            saveActiveEdit();
          });
        });

        cb.addEventListener('change', function(){
          setEditStatus('');
          clearMusiciansCallout();

          if(this.checked){
            clearOtherEditChecks(row);
            setRowEditMode(row, true);
            setSaveEnabled(true);

            if(supportsParticipantsEdit){
              const musInput = row.querySelector('td[data-col="musicians"] input.edit-cell-input');
              if(musInput && musInput instanceof HTMLInputElement){
                previewMusiciansDebounced(musInput.value);
              }
            }
          } else {
            setRowEditMode(row, false);
            const still = getActiveEditRow();
            setSaveEnabled(!!still);
          }
        });

        if(supportsParticipantsEdit){
          const musInput = row.querySelector('td[data-col="musicians"] input.edit-cell-input');
          if(musInput && musInput instanceof HTMLInputElement){
            musInput.addEventListener('input', function(){
              if(!cb.checked){ return; }
              previewMusiciansDebounced(this.value);
            });
          }
        }
      });

      if(saveBtn && saveBtn instanceof HTMLButtonElement){
        saveBtn.addEventListener('click', function(){
          saveActiveEdit();
        });
      }
    }

    const previewMusiciansDebounced = debounce(previewMusicians, 450);

    async function saveActiveEdit(){
      if(!isAdmin){ return; }
      const row = getActiveEditRow();
      if(!row){ return; }
      const eid = rowGetEventId(row);
      const eiid = rowGetEventItemId(row);
      const orgInput = row.querySelector('td[data-col="org"] input.edit-cell-input');
      const itemInput = row.querySelector('td[data-col="song_name"] input.edit-cell-input');
      const orgName = orgInput && orgInput instanceof HTMLInputElement ? orgInput.value : '';
      const itemLabel = itemInput && itemInput instanceof HTMLInputElement ? itemInput.value : '';
      const payload = {
        event_id: Number(eid),
        event_item_id: Number(eiid),
        org_name: orgName,
        item_label: itemLabel,
      };
      if(supportsExtendedSessionMetadata){
        const ratingInput = row.querySelector('td[data-col="rating"] input.edit-cell-input');
        const keywordsInput = row.querySelector('td[data-col="keywords"] input.edit-cell-input');
        const locationInput = row.querySelector('td[data-col="location"] input.edit-cell-input');
        const summaryInput = row.querySelector('td[data-col="summary"] input.edit-cell-input');
        payload.rating = ratingInput && ratingInput instanceof HTMLInputElement ? ratingInput.value : '';
        payload.keywords = keywordsInput && keywordsInput instanceof HTMLInputElement ? keywordsInput.value : '';
        payload.location = locationInput && locationInput instanceof HTMLInputElement ? locationInput.value : '';
        payload.summary = summaryInput && summaryInput instanceof HTMLInputElement ? summaryInput.value : '';
      }
      if(supportsParticipantsEdit){
        const musInput = row.querySelector('td[data-col="musicians"] input.edit-cell-input');
        payload.participants_csv = musInput && musInput instanceof HTMLInputElement ? musInput.value : '';
      }

      setEditStatus('Saving...');
      const btn = document.getElementById('editSaveBtn');
      if(btn && btn instanceof HTMLButtonElement){ btn.disabled = true; }

      try{
        const resp = await fetch('/db/database_edit_save.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(payload),
          credentials: 'same-origin'
        });
        const data = await resp.json().catch(()=>null);
        if(!resp.ok || !data || !data.success){
          const errs = data && Array.isArray(data.errors) ? data.errors : [];
          const msg = errs.length ? errs.join(' | ') : (data && (data.message || data.error) ? String(data.message || data.error) : 'Save failed');
          setEditStatus(msg);
          if(btn && btn instanceof HTMLButtonElement){ btn.disabled = false; }
          return;
        }

        const savedOrg = String(data.org_name || '');
        const savedItemLabel = String(data.item_label || '');
        const eventFields = {
          org_name: savedOrg,
        };
        if(supportsExtendedSessionMetadata){
          eventFields.rating = String(data.rating || '');
          eventFields.keywords = String(data.keywords || '');
          eventFields.location = String(data.location || '');
          eventFields.summary = String(data.summary || '');
        }
        if(supportsParticipantsEdit){
          eventFields.participants = Array.isArray(data.participants) ? data.participants.join(', ') : '';
        }
        updateAllVisibleRowsForEvent(eid, eventFields);
        updateAllVisibleRowsForEventItem(eiid, savedItemLabel);

        const cb = row.querySelector('input.edit-checkbox');
        if(cb && cb instanceof HTMLInputElement){ cb.checked = false; }
        setRowEditMode(row, false);
        clearMusiciansCallout();

        setEditStatus('Saved. Reloading...');
        if(btn && btn instanceof HTMLButtonElement){ btn.disabled = true; }
        setTimeout(() => { window.location.reload(); }, 150);
        return;
      } catch(e){
        setEditStatus('Save failed');
        if(btn && btn instanceof HTMLButtonElement){ btn.disabled = false; }
      }
    }

    function getOrCreateClientId(){
      const key = 'gighive_client_id';
      try{
        let v = localStorage.getItem(key);
        if(v && String(v).trim() !== ''){return String(v);}
        if(typeof crypto !== 'undefined' && crypto && typeof crypto.randomUUID === 'function'){
          v = crypto.randomUUID();
        }else{
          v = 'c_' + Math.random().toString(16).slice(2) + '_' + Date.now().toString(16);
        }
        localStorage.setItem(key, v);
        return v;
      }catch(e){
        if(typeof crypto !== 'undefined' && crypto && typeof crypto.randomUUID === 'function'){
          return crypto.randomUUID();
        }
        return 'c_' + Math.random().toString(16).slice(2) + '_' + Date.now().toString(16);
      }
    }

    function getViewStateKey(){
      const clientId = getOrCreateClientId();
      return 'gighive_db_view_state:v1:' + String(viewMode) + ':' + String(basicUser) + ':' + String(clientId);
    }

    function readViewState(){
      try{
        const raw = localStorage.getItem(getViewStateKey());
        if(!raw){return null;}
        const parsed = JSON.parse(raw);
        return (parsed && typeof parsed === 'object') ? parsed : null;
      }catch(e){
        return null;
      }
    }

    function writeViewState(next){
      try{
        localStorage.setItem(getViewStateKey(), JSON.stringify(next));
      }catch(e){
      }
    }

    function updateViewState(mutator){
      const current = readViewState() ?? {};
      const next = mutator(current) ?? current;
      writeViewState(next);
      return next;
    }

    function getColKeyByIndex(table, colIndex){
      const th = table?.tHead?.rows?.[0]?.cells?.[colIndex];
      const key = th?.dataset?.col;
      return (typeof key === 'string' && key !== '') ? key : '';
    }

    function getIndexByColKey(table, colKey){
      const headRow = table?.tHead?.rows?.[0];
      if(!headRow){return -1;}
      const ths = Array.from(headRow.cells);
      return ths.findIndex(th => (th?.dataset?.col ?? '') === colKey);
    }

    function snapshotWidths(table){
      const out = {};
      const headRow = table?.tHead?.rows?.[0];
      if(!headRow){return out;}
      Array.from(headRow.cells).forEach((th)=>{
        const colKey = th?.dataset?.col ?? '';
        if(!colKey){return;}
        if(th.classList.contains('col-collapsed') && th.dataset && th.dataset.prevWidth){
          out[colKey] = String(th.dataset.prevWidth);
        }else{
          const w = (th.style && th.style.width) ? String(th.style.width) : '';
          if(w !== ''){out[colKey] = w;}
        }
      });
      return out;
    }

    function persistWidths(table){
      updateViewState((s)=>{
        s.widthsByColKey = snapshotWidths(table);
        return s;
      });
    }

    function persistHidden(table){
      const headRow = table?.tHead?.rows?.[0];
      if(!headRow){return;}
      const hidden = [];
      Array.from(headRow.cells).forEach((th)=>{
        const colKey = th?.dataset?.col ?? '';
        if(!colKey){return;}
        if(th.classList.contains('col-hidden')){hidden.push(colKey);}
      });
      updateViewState((s)=>{ s.hiddenColKeys = hidden; return s; });
    }

    function persistCollapsed(table){
      const headRow = table?.tHead?.rows?.[0];
      if(!headRow){return;}
      const collapsed = [];
      Array.from(headRow.cells).forEach((th)=>{
        const colKey = th?.dataset?.col ?? '';
        if(!colKey){return;}
        if(th.classList.contains('col-collapsed')){collapsed.push(colKey);}
      });
      updateViewState((s)=>{ s.collapsedColKeys = collapsed; return s; });
    }

    function applyViewState(table){
      const state = readViewState();
      if(!state || !table){return;}

      const widths = (state.widthsByColKey && typeof state.widthsByColKey === 'object') ? state.widthsByColKey : {};
      const hidden = Array.isArray(state.hiddenColKeys) ? state.hiddenColKeys : [];
      const collapsed = Array.isArray(state.collapsedColKeys) ? state.collapsedColKeys : [];

      Object.keys(widths).forEach((colKey)=>{
        const idx = getIndexByColKey(table, colKey);
        if(idx < 0){return;}
        const th = table?.tHead?.rows?.[0]?.cells?.[idx];
        if(!th){return;}
        const w = String(widths[colKey] ?? '');
        if(w !== ''){
          th.style.width = w;
        }
      });

      collapsed.forEach((colKey)=>{
        const idx = getIndexByColKey(table, colKey);
        if(idx < 0){return;}
        const th = table?.tHead?.rows?.[0]?.cells?.[idx];
        if(!th){return;}
        const cb = th.querySelector('.col-collapse-checkbox');
        if(cb && cb instanceof HTMLInputElement){
          cb.checked = true;
        }
        const w = String(widths[colKey] ?? '');
        if(w !== ''){
          th.dataset.prevWidth = w;
        }
        setColumnCollapsed(table, idx, true);
      });

      hidden.forEach((colKey)=>{
        const idx = getIndexByColKey(table, colKey);
        if(idx < 0){return;}
        setColumnHidden(table, idx, true);
      });

      if(state.sort && typeof state.sort === 'object'){
        const colKey = typeof state.sort.colKey === 'string' ? state.sort.colKey : '';
        const dir = (state.sort.dir === 'asc' || state.sort.dir === 'desc') ? state.sort.dir : '';
        const idx = colKey ? getIndexByColKey(table, colKey) : -1;
        if(idx >= 0 && dir !== ''){
          table.dataset.sortOrder = (dir === 'asc') ? 'desc' : 'asc';
          sortTable(idx);
        }
      }
    }

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
        persistWidths(table);
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

    function setColumnHidden(table, colIndex, hidden){
      if(!table){return;}
      const headRow = table.tHead && table.tHead.rows && table.tHead.rows[0] ? table.tHead.rows[0] : null;
      if(!headRow){return;}
      const th = headRow.cells[colIndex];
      if(!th){return;}

      if(hidden){
        th.classList.add('col-hidden');
      }else{
        th.classList.remove('col-hidden');
      }

      const bodyRows = Array.from(table.tBodies[0]?.rows ?? []);
      bodyRows.forEach((row)=>{
        const cell = row.cells[colIndex];
        if(!cell){return;}
        if(hidden){
          cell.classList.add('col-hidden');
        }else{
          cell.classList.remove('col-hidden');
        }
      });
    }

    document.querySelectorAll('#searchForm thead .th-search-row').forEach((row)=>{
      row.addEventListener('click',(e)=>e.stopPropagation());
    });

    document.querySelectorAll('#searchForm thead .th-search-row input[type="text"]').forEach((input)=>{
      input.addEventListener('click',(e)=>e.stopPropagation());
      input.addEventListener('keydown',(e)=>{
        if(e.key !== 'Enter'){return;}
        e.preventDefault();
        const form = document.getElementById('searchForm');
        if(!form){return;}
        if(typeof form.requestSubmit === 'function'){
          form.requestSubmit();
        }else{
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

      enableAdminInlineEdit();

      const table = document.getElementById('searchableTable');

      applyViewState(table);

      const resetLink = document.getElementById('resetViewLink');
      if(resetLink){
        resetLink.addEventListener('click',(e)=>{
          try{
            localStorage.removeItem(getViewStateKey());
          }catch(err){
          }
        });
      }

      const headRow = table && table.tHead && table.tHead.rows && table.tHead.rows[0] ? table.tHead.rows[0] : null;
      if(headRow){
        Array.from(headRow.cells).forEach((th)=>{
          th.onclick = null;
          th.addEventListener('click',(e)=>{
            const target = e.target;
            if(!target){return;}
            if(target.tagName === 'INPUT' || target.tagName === 'BUTTON'){return;}
            if(target.closest && (target.closest('.th-search-row') || target.closest('.col-resizer'))){return;}
            const colIndex = Array.from(th.parentElement?.children ?? []).indexOf(th);
            if(colIndex < 0){return;}
            sortTable(colIndex);
            const colKey = getColKeyByIndex(table, colIndex);
            const dir = table && table.dataset && (table.dataset.sortOrder === 'asc' || table.dataset.sortOrder === 'desc') ? table.dataset.sortOrder : '';
            if(colKey !== '' && dir !== ''){
              updateViewState((s)=>{ s.sort = { colKey, dir }; return s; });
            }
          });
        });
      }

      const checkboxes = Array.from(document.querySelectorAll('#searchableTable thead .col-collapse-checkbox'));
      checkboxes.forEach((cb)=>{
        cb.addEventListener('click',(e)=>e.stopPropagation());
        cb.addEventListener('change',(e)=>{
          const th = cb.closest('th');
          if(!th || !table){return;}
          const colIndex = Array.from(th.parentElement?.children ?? []).indexOf(th);
          if(colIndex < 0){return;}
          setColumnCollapsed(table, colIndex, cb.checked);
          persistCollapsed(table);
          persistWidths(table);
        });
      });

      const hideButtons = Array.from(document.querySelectorAll('#searchableTable thead .col-hide-btn'));
      hideButtons.forEach((btn)=>{
        btn.addEventListener('click',(e)=>{
          e.preventDefault();
          e.stopPropagation();
          const th = btn.closest('th');
          if(!th || !table){return;}
          const colIndex = Array.from(th.parentElement?.children ?? []).indexOf(th);
          if(colIndex < 0){return;}
          setColumnHidden(table, colIndex, true);
          persistHidden(table);
        });
      });

      if(!targetDate || !targetOrg){return;}
      const selector=`.media-row[data-date="${targetDate}"][data-org="${targetOrg}"]`;
      const row=document.querySelector(selector);
      if(!row){return;}
      row.scrollIntoView({behavior:'smooth',block:'center'});
      row.classList.add('highlighted-jam');
    });

    function updateDeleteUi(){
      if(!isAdmin){return;}
      const btn = document.getElementById('deleteSelectedBtn');
      const status = document.getElementById('deleteSelectedStatus');
      const boxes = Array.from(document.querySelectorAll('.delete-checkbox'));
      const selectedIds = boxes
        .filter(cb => cb && cb.checked)
        .map(cb => parseInt(String(cb.value), 10))
        .filter(n => Number.isFinite(n) && n > 0);
      if(btn){
        btn.disabled = selectedIds.length === 0;
      }
      if(status){
        status.textContent = selectedIds.length ? (String(selectedIds.length) + ' selected') : '';
      }
    }

    async function deleteSelected(){
      const btn = document.getElementById('deleteSelectedBtn');
      const status = document.getElementById('deleteSelectedStatus');
      const boxes = Array.from(document.querySelectorAll('.delete-checkbox'));
      const ids = boxes.filter(cb => cb && cb.checked).map(cb => parseInt(String(cb.value), 10)).filter(n => Number.isFinite(n) && n > 0);
      if(!ids.length){
        return;
      }
      if(!confirm('Delete ' + String(ids.length) + ' media file(s)?\n\nThis will delete the database record, the file on disk, and (for video) the thumbnail.')){
        return;
      }
      if(btn){
        btn.disabled = true;
        btn.textContent = 'Deleting…';
      }
      if(status){
        status.textContent = 'Deleting…';
      }
      try{
        const resp = await fetch('/db/delete_media_files.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ asset_ids: ids })
        });
        const data = await resp.json().catch(() => null);
        if(status){
          status.textContent = (data !== null) ? JSON.stringify(data) : ('HTTP ' + String(resp.status));
        }
        if(!(resp.ok && data && data.success)){
          if(btn){
            btn.disabled = false;
            btn.textContent = 'Delete Media File(s)';
          }
          return;
        }
        window.location.reload();
      }catch(e){
        if(status){
          status.textContent = 'Network error: ' + String(e && e.message ? e.message : e);
        }
        if(btn){
          btn.disabled = false;
          btn.textContent = 'Delete Media File(s)';
        }
      }
    }

    document.addEventListener('DOMContentLoaded',()=>{
      if(!isAdmin){return;}
      const selectAll = document.getElementById('deleteSelectAll');
      const btn = document.getElementById('deleteSelectedBtn');
      const boxes = Array.from(document.querySelectorAll('.delete-checkbox'));
      if(selectAll){
        selectAll.addEventListener('click',(e)=>e.stopPropagation());
        selectAll.addEventListener('change',()=>{
          boxes.forEach(cb => { if(cb){ cb.checked = !!selectAll.checked; } });
          updateDeleteUi();
        });
      }
      boxes.forEach(cb => {
        cb.addEventListener('click',(e)=>e.stopPropagation());
        cb.addEventListener('change', updateDeleteUi);
      });
      if(btn){
        btn.addEventListener('click', deleteSelected);
      }
      updateDeleteUi();
    });

    document.addEventListener('click', function(e){
      const link = e.target && e.target.closest ? e.target.closest('a.media-download-link') : null;
      if(!link){
        return;
      }
      if(typeof gtag !== 'function'){
        return;
      }

      const href = String(link.getAttribute('href') || '');
      const fileName = (function(){
        try{
          const u = new URL(href, window.location.href);
          const parts = (u.pathname || '').split('/').filter(Boolean);
          return parts.length ? parts[parts.length - 1] : '';
        }catch(_){
          const parts = href.split('?')[0].split('#')[0].split('/').filter(Boolean);
          return parts.length ? parts[parts.length - 1] : '';
        }
      })();

      const fileType = String(link.dataset.fileType || '');
      if(fileType !== 'audio' && fileType !== 'video'){
        return;
      }

      gtag('event', 'file_download', {
        file_url: href,
        file_name: fileName,
        file_type: fileType,
        media_id: String(link.dataset.mediaId || ''),
        org_name: String(link.dataset.orgName || ''),
        date: String(link.dataset.date || ''),
        item_label: String(link.dataset.itemLabel || ''),
        checksum_sha256: String(link.dataset.checksumSha256 || ''),
        source_relpath: String(link.dataset.sourceRelpath || ''),
        download_source: String(link.dataset.downloadSource || ''),
      });
    }, { capture: true });
  </script>
</body>
</html>
