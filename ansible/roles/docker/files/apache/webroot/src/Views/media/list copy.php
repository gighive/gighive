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
    body{font-family:system-ui,Arial,sans-serif;margin:0;padding:1rem;overflow-x:auto;}
    h1{margin:0 0 1rem 0;}
    .user-indicator{font-size:12px;color:#666;margin:0 0 0.5rem 0;}
    .pagination{max-width:<?= (int)$maxWidth ?>px;margin:0 auto 0.75rem auto;display:flex;align-items:center;justify-content:space-between;gap:1rem;}
    .pagination .links a,.pagination .links span{display:inline-block;padding:4px 8px;border:1px solid #ddd;border-radius:4px;text-decoration:none;color:#0366d6;}
    .pagination .links span{color:#999;border-color:#eee;}
    .header-block{max-width:<?= (int)$maxWidth ?>px;margin:0 0 0.75rem 0;text-align:left;}
    table{width:max-content;table-layout:fixed;border-collapse:collapse;margin:0 auto;}
    th,td{border:1px solid #ddd;padding:8px;vertical-align:top;word-wrap:break-word;}
    th{background:#f6f6f6;text-align:left;cursor:pointer;}
    thead input{width:100%;box-sizing:border-box;margin-top:4px;}
    .highlighted-jam{background-color:#fff8c2;}
    #searchableTable th:first-child,
    #searchableTable td:first-child{width:40px;}
    .media-file-info{white-space:pre;display:inline-block;}

    .th-title-row{display:flex;align-items:center;justify-content:space-between;gap:8px;}
    .th-title-row h4{margin:0;}
    .th-title-row .col-collapse-checkbox{margin:0;}

    th.col-collapsed,td.col-collapsed{width:32px;min-width:32px;max-width:32px;padding-left:4px;padding-right:4px;overflow:hidden;white-space:nowrap;}
    th.col-collapsed .th-title-row{justify-content:center;}
    th.col-collapsed .th-title-row h4{display:none;}
    th.col-collapsed > input{display:none;}
    th.col-collapsed .col-resizer{display:none;}

    /* Resizable columns */
    #searchableTable th{position:relative;}
    #searchableTable th .col-resizer{position:absolute;top:0;right:-4px;width:8px;height:100%;cursor:col-resize;user-select:none;touch-action:none;}
    #searchableTable th .col-resizer::after{content:'';position:absolute;top:0;left:3px;width:1px;height:100%;background:#d0d0d0;}
    body.resizing-col{cursor:col-resize;user-select:none;}
  </style>
</head>
<body>
  <?php
  $user = $_SERVER['PHP_AUTH_USER']
      ?? $_SERVER['REMOTE_USER']
      ?? $_SERVER['REDIRECT_REMOTE_USER']
      ?? 'Unknown';
  ?>
  <div class="user-indicator header-block">User is logged in as <?= htmlspecialchars($user, ENT_QUOTES) ?></div>
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
  <table id="searchableTable" data-sort-order="asc">
    <thead>
      <tr>
        <?php if ($isGighive): ?>
          <th onclick="if(event.target.tagName!=='INPUT'){sortTable(0)}"><div class="th-title-row"><h4>#</h4><input class="col-collapse-checkbox" type="checkbox" aria-label="Collapse column"></div></th>
          <th onclick="if(event.target.tagName!=='INPUT'){sortTable(1)}"><div class="th-title-row"><h4>Date</h4><input class="col-collapse-checkbox" type="checkbox" aria-label="Collapse column"></div><input name="date" type="text" placeholder="Search..." value="<?= htmlspecialchars((string)($query['date'] ?? ''), ENT_QUOTES) ?>"></th>
          <th onclick="if(event.target.tagName!=='INPUT'){sortTable(2)}"><div class="th-title-row"><h4>Band or Event</h4><input class="col-collapse-checkbox" type="checkbox" aria-label="Collapse column"></div><input name="org_name" type="text" placeholder="Search..." value="<?= htmlspecialchars((string)($query['org_name'] ?? ''), ENT_QUOTES) ?>"></th>
          <th onclick="if(event.target.tagName!=='INPUT'){sortTable(3)}"><div class="th-title-row"><h4>File Type</h4><input class="col-collapse-checkbox" type="checkbox" aria-label="Collapse column"></div><input name="file_type" type="text" placeholder="Search..." value="<?= htmlspecialchars((string)($query['file_type'] ?? ''), ENT_QUOTES) ?>"></th>
          <th onclick="if(event.target.tagName!=='INPUT'){sortTable(4)}"><div class="th-title-row"><h4>Song Name</h4><input class="col-collapse-checkbox" type="checkbox" aria-label="Collapse column"></div><input name="song_title" type="text" placeholder="Search..." value="<?= htmlspecialchars((string)($query['song_title'] ?? ''), ENT_QUOTES) ?>"></th>
          <th onclick="if(event.target.tagName!=='INPUT'){sortTable(5)}"><div class="th-title-row"><h4>Source Relpath</h4><input class="col-collapse-checkbox" type="checkbox" aria-label="Collapse column"></div><input name="source_relpath" type="text" placeholder="Search..." value="<?= htmlspecialchars((string)($query['source_relpath'] ?? ''), ENT_QUOTES) ?>"></th>
          <th onclick="if(event.target.tagName!=='INPUT'){sortTable(6)}"><div class="th-title-row"><h4>Download / View</h4><input class="col-collapse-checkbox" type="checkbox" aria-label="Collapse column"></div><input name="file_name" type="text" placeholder="Search..." value="<?= htmlspecialchars((string)($query['file_name'] ?? ''), ENT_QUOTES) ?>"></th>
          <th onclick="if(event.target.tagName!=='INPUT'){sortTable(7)}"><div class="th-title-row"><h4>Duration</h4><input class="col-collapse-checkbox" type="checkbox" aria-label="Collapse column"></div><input name="duration_seconds" type="text" placeholder="Search..." value="<?= htmlspecialchars((string)($query['duration_seconds'] ?? ''), ENT_QUOTES) ?>"></th>
          <th onclick="if(event.target.tagName!=='INPUT'){sortTable(8)}"><div class="th-title-row"><h4>Media File Info</h4><input class="col-collapse-checkbox" type="checkbox" aria-label="Collapse column"></div><input name="media_info" type="text" placeholder="Search..." value="<?= htmlspecialchars((string)($query['media_info'] ?? ''), ENT_QUOTES) ?>"></th>
          <th onclick="if(event.target.tagName!=='INPUT'){sortTable(9)}"><div class="th-title-row"><h4>Musicians</h4><input class="col-collapse-checkbox" type="checkbox" aria-label="Collapse column"></div><input name="crew" type="text" placeholder="Search..." value="<?= htmlspecialchars((string)($query['crew'] ?? ''), ENT_QUOTES) ?>"></th>
        <?php else: ?>
          <th onclick="if(event.target.tagName!=='INPUT'){sortTable(0)}"><div class="th-title-row"><h4>#</h4><input class="col-collapse-checkbox" type="checkbox" aria-label="Collapse column"></div></th>
          <th onclick="if(event.target.tagName!=='INPUT'){sortTable(1)}"><div class="th-title-row"><h4>Date</h4><input class="col-collapse-checkbox" type="checkbox" aria-label="Collapse column"></div><input name="date" type="text" placeholder="Search..." value="<?= htmlspecialchars((string)($query['date'] ?? ''), ENT_QUOTES) ?>"></th>
          <th onclick="if(event.target.tagName!=='INPUT'){sortTable(2)}"><div class="th-title-row"><h4>Org</h4><input class="col-collapse-checkbox" type="checkbox" aria-label="Collapse column"></div><input name="org_name" type="text" placeholder="Search..." value="<?= htmlspecialchars((string)($query['org_name'] ?? ''), ENT_QUOTES) ?>"></th>
          <th onclick="if(event.target.tagName!=='INPUT'){sortTable(3)}"><div class="th-title-row"><h4>Rating</h4><input class="col-collapse-checkbox" type="checkbox" aria-label="Collapse column"></div><input name="rating" type="text" placeholder="Search..." value="<?= htmlspecialchars((string)($query['rating'] ?? ''), ENT_QUOTES) ?>"></th>
          <th onclick="if(event.target.tagName!=='INPUT'){sortTable(4)}"><div class="th-title-row"><h4>Keywords</h4><input class="col-collapse-checkbox" type="checkbox" aria-label="Collapse column"></div><input name="keywords" type="text" placeholder="Search..." value="<?= htmlspecialchars((string)($query['keywords'] ?? ''), ENT_QUOTES) ?>"></th>
          <th onclick="if(event.target.tagName!=='INPUT'){sortTable(5)}"><div class="th-title-row"><h4>Location</h4><input class="col-collapse-checkbox" type="checkbox" aria-label="Collapse column"></div><input name="location" type="text" placeholder="Search..." value="<?= htmlspecialchars((string)($query['location'] ?? ''), ENT_QUOTES) ?>"></th>
          <th onclick="if(event.target.tagName!=='INPUT'){sortTable(6)}"><div class="th-title-row"><h4>Summary</h4><input class="col-collapse-checkbox" type="checkbox" aria-label="Collapse column"></div><input name="summary" type="text" placeholder="Search..." value="<?= htmlspecialchars((string)($query['summary'] ?? ''), ENT_QUOTES) ?>"></th>
          <th onclick="if(event.target.tagName!=='INPUT'){sortTable(7)}"><div class="th-title-row"><h4>File Type</h4><input class="col-collapse-checkbox" type="checkbox" aria-label="Collapse column"></div><input name="file_type" type="text" placeholder="Search..." value="<?= htmlspecialchars((string)($query['file_type'] ?? ''), ENT_QUOTES) ?>"></th>
          <th onclick="if(event.target.tagName!=='INPUT'){sortTable(8)}"><div class="th-title-row"><h4>Song Name</h4><input class="col-collapse-checkbox" type="checkbox" aria-label="Collapse column"></div><input name="song_title" type="text" placeholder="Search..." value="<?= htmlspecialchars((string)($query['song_title'] ?? ''), ENT_QUOTES) ?>"></th>
          <th onclick="if(event.target.tagName!=='INPUT'){sortTable(9)}"><div class="th-title-row"><h4>Download / View</h4><input class="col-collapse-checkbox" type="checkbox" aria-label="Collapse column"></div><input name="file_name" type="text" placeholder="Search..." value="<?= htmlspecialchars((string)($query['file_name'] ?? ''), ENT_QUOTES) ?>"></th>
          <th onclick="if(event.target.tagName!=='INPUT'){sortTable(10)}"><div class="th-title-row"><h4>Duration</h4><input class="col-collapse-checkbox" type="checkbox" aria-label="Collapse column"></div><input name="duration_seconds" type="text" placeholder="Search..." value="<?= htmlspecialchars((string)($query['duration_seconds'] ?? ''), ENT_QUOTES) ?>"></th>
          <th onclick="if(event.target.tagName!=='INPUT'){sortTable(11)}"><div class="th-title-row"><h4>Media File Info</h4><input class="col-collapse-checkbox" type="checkbox" aria-label="Collapse column"></div><input name="media_info" type="text" placeholder="Search..." value="<?= htmlspecialchars((string)($query['media_info'] ?? ''), ENT_QUOTES) ?>"></th>
          <th onclick="if(event.target.tagName!=='INPUT'){sortTable(12)}"><div class="th-title-row"><h4>Musicians</h4><input class="col-collapse-checkbox" type="checkbox" aria-label="Collapse column"></div><input name="crew" type="text" placeholder="Search..." value="<?= htmlspecialchars((string)($query['crew'] ?? ''), ENT_QUOTES) ?>"></th>
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
            <td><?= htmlspecialchars((string)$r['idx'], ENT_QUOTES) ?></td>
            <td><?= htmlspecialchars($r['date'] ?? '', ENT_QUOTES) ?></td>
            <td><?= htmlspecialchars($r['org_name'] ?? '', ENT_QUOTES) ?></td>
            <td><?= htmlspecialchars($r['type'] ?? '', ENT_QUOTES) ?></td>
            <td><?= htmlspecialchars($r['songTitle'] ?? '', ENT_QUOTES) ?></td>
            <td><?= htmlspecialchars($r['sourceRelpath'] ?? '', ENT_QUOTES) ?></td>
            <td>
              <?php if (!empty($r['url'])): ?>
                <a href="<?= htmlspecialchars($r['url'], ENT_QUOTES) ?>" target="_blank">Download</a>
              <?php endif; ?>
            </td>
            <td data-num="<?= htmlspecialchars((string)($r['durationSec'] ?? ''), ENT_QUOTES) ?>">
              <?= htmlspecialchars($r['duration'] ?? '', ENT_QUOTES) ?>
            </td>
            <td><div class="media-file-info"><?= htmlspecialchars($r['mediaSummary'] ?? '', ENT_QUOTES) ?></div></td>
            <td><?= htmlspecialchars($r['crew'] ?? '', ENT_QUOTES) ?></td>
          <?php else: ?>
            <td><?= htmlspecialchars((string)$r['idx'], ENT_QUOTES) ?></td>
            <td><?= htmlspecialchars($r['date'] ?? '', ENT_QUOTES) ?></td>
            <td><?= htmlspecialchars($r['org_name'] ?? '', ENT_QUOTES) ?></td>
            <td><?= htmlspecialchars($r['rating'] ?? '', ENT_QUOTES) ?></td>
            <td><?= htmlspecialchars($r['keywords'] ?? '', ENT_QUOTES) ?></td>
            <td><?= htmlspecialchars($r['location'] ?? '', ENT_QUOTES) ?></td>
            <td><?= htmlspecialchars($r['summary'] ?? '', ENT_QUOTES) ?></td>
            <td><?= htmlspecialchars($r['type'] ?? '', ENT_QUOTES) ?></td>
            <td><?= htmlspecialchars($r['songTitle'] ?? '', ENT_QUOTES) ?></td>
            <td>
              <?php if (!empty($r['url'])): ?>
                <a href="<?= htmlspecialchars($r['url'], ENT_QUOTES) ?>" target="_blank">Download</a>
              <?php endif; ?>
            </td>
            <td data-num="<?= htmlspecialchars((string)($r['durationSec'] ?? ''), ENT_QUOTES) ?>">
              <?= htmlspecialchars($r['duration'] ?? '', ENT_QUOTES) ?>
            </td>
            <td><div class="media-file-info"><?= htmlspecialchars($r['mediaSummary'] ?? '', ENT_QUOTES) ?></div></td>
            <td><?= htmlspecialchars($r['crew'] ?? '', ENT_QUOTES) ?></td>
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
