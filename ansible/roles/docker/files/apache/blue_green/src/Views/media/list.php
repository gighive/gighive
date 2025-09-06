<?php /** @var array $rows */ ?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="../header.css">
  <title>Media Database</title>
  <style>
    body{font-family:system-ui,Arial,sans-serif;margin:0;padding:1rem;}
    h1{margin:0 auto 1rem auto;max-width:1350px;}
    table{width:1350px;table-layout:fixed;border-collapse:collapse;margin:0 auto;}
    th,td{border:1px solid #ddd;padding:8px;vertical-align:top;word-wrap:break-word;}
    th{background:#f6f6f6;text-align:left;cursor:pointer;}
    thead input{width:100%;box-sizing:border-box;margin-top:4px;}
  </style>
</head>
<body>
  <h1>Sessions</h1>
  <table id="searchableTable" data-sort-order="asc">
    <thead>
      <tr>
        <th onclick="sortTable(0)"><h4>#</h4><input type="text" placeholder="Search..."></th>
        <th onclick="sortTable(1)"><h4>Date</h4><input type="text" placeholder="Search..."></th>
        <th onclick="sortTable(2)"><h4>Rating</h4><input type="text" placeholder="Search..."></th>
        <th onclick="sortTable(3)"><h4>Keywords</h4><input type="text" placeholder="Search..."></th>
        <th onclick="sortTable(4)"><h4>Duration</h4><input type="text" placeholder="Search..."></th>
        <th onclick="sortTable(5)"><h4>Location</h4><input type="text" placeholder="Search..."></th>
        <th onclick="sortTable(6)"><h4>Summary</h4><input type="text" placeholder="Search..."></th>
        <th onclick="sortTable(7)"><h4>Crew</h4><input type="text" placeholder="Search..."></th>
        <th onclick="sortTable(8)"><h4>Song Name</h4><input type="text" placeholder="Search..."></th>
        <th onclick="sortTable(9)"><h4>File Type</h4><input type="text" placeholder="Search..."></th>
        <th onclick="sortTable(10)"><h4>File</h4><input type="text" placeholder="Search..."></th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><?= htmlspecialchars((string)$r['idx'], ENT_QUOTES) ?></td>
          <td><?= htmlspecialchars($r['date'] ?? '', ENT_QUOTES) ?></td>
          <td><?= htmlspecialchars($r['rating'] ?? '', ENT_QUOTES) ?></td>
          <td><?= htmlspecialchars($r['keywords'] ?? '', ENT_QUOTES) ?></td>
          <td><?= htmlspecialchars($r['duration'] ?? '', ENT_QUOTES) ?></td>
          <td><?= htmlspecialchars($r['location'] ?? '', ENT_QUOTES) ?></td>
          <td><?= htmlspecialchars($r['summary'] ?? '', ENT_QUOTES) ?></td>
          <td><?= htmlspecialchars($r['crew'] ?? '', ENT_QUOTES) ?></td>
          <td><?= htmlspecialchars($r['songTitle'] ?? '', ENT_QUOTES) ?></td>
          <td><?= htmlspecialchars($r['type'] ?? '', ENT_QUOTES) ?></td>
          <td>
            <?php if (!empty($r['url'])): ?>
              <a href="<?= htmlspecialchars($r['url'], ENT_QUOTES) ?>" target="_blank">Download</a>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <script>
    function filterTable(){
      const inputs=document.querySelectorAll('thead input');
      const table=document.getElementById('searchableTable');
      const rows=table.getElementsByTagName('tr');
      for(let i=1;i<rows.length;i++){
        let visible=true;
        const cells=rows[i].getElementsByTagName('td');
        inputs.forEach((input,colIndex)=>{
          const filter=input.value.toUpperCase();
          if(filter){
            const txt=(cells[colIndex]?.innerText||'').toUpperCase();
            if(txt.indexOf(filter)===-1){visible=false;}
          }
        });
        rows[i].style.display=visible? '': 'none';
      }
    }
    document.querySelectorAll('thead input').forEach((input)=>{input.addEventListener('keyup',filterTable);});
    function sortTable(colIndex){
      const table=document.getElementById('searchableTable');
      const tbody=table.tBodies[0];
      const rows=Array.from(tbody.rows);
      const asc=table.dataset.sortOrder!=='asc';
      rows.sort((a,b)=>{
        let A=a.cells[colIndex].innerText.trim().toUpperCase();
        let B=b.cells[colIndex].innerText.trim().toUpperCase();
        let nA=parseFloat(A), nB=parseFloat(B);
        if(!isNaN(nA)&&!isNaN(nB)) return asc? nA-nB : nB-nA;
        return asc? A.localeCompare(B) : B.localeCompare(A);
      });
      table.dataset.sortOrder=asc? 'asc' : 'desc';
      rows.forEach(r=>tbody.appendChild(r));
    }
  </script>
</body>
</html>
