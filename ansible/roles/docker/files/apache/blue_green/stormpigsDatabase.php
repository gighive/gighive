<?php
// Path to the CSV file
$csvFile = 'unified_stormpigs_database.csv';

// Function to read and process the CSV file
function loadCSVData($csvFile) {
    $data = [];
    if (($handle = fopen($csvFile, 'r')) !== false) {
        $headers = fgetcsv($handle); // Read headers
        while (($row = fgetcsv($handle)) !== false) {
            $data[] = array_combine($headers, $row);
        }
        fclose($handle);
    }
    return $data;
}

// Load CSV data
$rows = loadCSVData($csvFile);

// Process data: remove unwanted text from v_rating and v_keywords
foreach ($rows as &$row) {
    $row['v_rating'] = preg_replace('/snuffler rating/i', '', $row['v_rating']);
    $row['v_keywords'] = preg_replace('/stormpigs/i', '', $row['v_keywords']);

    // Adjust v_keywords: no leading commas and ensure commas are followed by a space
    $row['v_keywords'] = ltrim($row['v_keywords'], ',');
    $row['v_keywords'] = preg_replace('/,\s*/', ', ', $row['v_keywords']);

    // Sort f_singles by the number in the filename
    $fSingles = explode(',', $row['f_singles']);
    usort($fSingles, function ($a, $b) {
        // Extract the numeric part between "_" and "_"
        preg_match('/_(\d+)_/', $a, $matchesA);
        preg_match('/_(\d+)_/', $b, $matchesB);
        $numA = isset($matchesA[1]) ? (int)$matchesA[1] : 0;
        $numB = isset($matchesB[1]) ? (int)$matchesB[1] : 0;

        // Compare the numeric parts
        return $numA <=> $numB;
    });
    $row['f_singles'] = implode(',', $fSingles);
}
unset($row);

// Sort data by date (descending)
usort($rows, function ($a, $b) {
    return strcmp($b['d_date'], $a['d_date']);
});
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="header.css">
    <title>StormPigs Database</title>
    <style>
        table {
            width: 1350px;
            table-layout: fixed;
            border-collapse: collapse;
            margin: 0 auto;
        }
        th, td {
            border: 1px solid #ddd;
            padding: 8px;
            text-align: left;
        }
        th {
            background-color: #f2f2f2;
            cursor: pointer;
        }
        input[type="text"] {
            width: 100%;
            box-sizing: border-box;
        }
        th:nth-child(1), td:nth-child(1) { width: 3%; text-align: center; }
        th:nth-child(2), td:nth-child(2) { width: 7%; text-align: center; }
        th:nth-child(3), td:nth-child(3) { width: 5%; text-align: center; }
        th:nth-child(4), td:nth-child(4) { width: 10%; text-align: center; }
        th:nth-child(5), td:nth-child(5) { width: 10%; }
        th:nth-child(6), td:nth-child(6) { width: 30%; }
        th:nth-child(7), td:nth-child(7) { width: 30%; }
    </style>
</head>
<body>
<table border="0" cellpadding="0" cellspacing="0" width="875" class="center">
    <tr>
        <td align="center" valign="top" colspan="7">
            <a href="stormpigsDatabase.php">the database</a>&nbsp;&nbsp;&nbsp;
            <a href="singlesRandomPlayer.php">auto play everything</a>&nbsp;&nbsp;&nbsp;
            <a href="loops.php">loops</a>&nbsp;&nbsp;&nbsp;
            <a href="http://stormpigs.blogspot.com">blog</a>&nbsp;&nbsp;&nbsp;
        </td>
    </tr>
    <tr>
        <td align="left" valign="middle" colspan="7">
            <a href="index.php" style="color:black; font-style:normal;">
                <font class="title">S &nbsp; T &nbsp; <img src="images/o.gif" height="42" width="42" alt="pig-O" align="absmiddle" border="0"> &nbsp; R &nbsp; M &nbsp; P &nbsp; I &nbsp; G &nbsp; S</font>
            </a>
        </td>
    </tr>
</table>
<table id="searchableTable">
    <thead>
        <tr>
            <th onclick="sortTable(0)">#<br><input type="text" onkeyup="searchTable(0)" placeholder="Search..."></th>
            <th onclick="sortTable(1)">Date<br><input type="text" onkeyup="searchTable(1)" placeholder="Search..."></th>
            <th onclick="sortTable(2)">Rating<br><input type="text" onkeyup="searchTable(2)" placeholder="Search..."></th>
            <th onclick="sortTable(3)">Keywords<br><input type="text" onkeyup="searchTable(3)" placeholder="Search..."></th>
            <th onclick="sortTable(4)">Crew<br><input type="text" onkeyup="searchTable(4)" placeholder="Search..."></th>
            <th onclick="sortTable(5)">Singles<br><input type="text" onkeyup="searchTable(5)" placeholder="Search..."></th>
            <th onclick="sortTable(6)">Songs<br><input type="text" onkeyup="searchTable(6)" placeholder="Search..."></th>
        </tr>
    </thead>
    <tbody>
        <?php
        $sequence = 1;
        foreach ($rows as $row) {
            echo '<tr>';
            echo '<td>' . $sequence++ . '</td>';
            echo '<td>' . htmlspecialchars($row['d_date']) . '</td>';
            echo '<td>' . htmlspecialchars($row['v_rating']) . '</td>';
            echo '<td>' . htmlspecialchars($row['v_keywords']) . '</td>';
            echo '<td>' . htmlspecialchars($row['d_crew_merged']) . '</td>';

            // Process f_singles
            $fSingles = explode(',', $row['f_singles']);
            echo '<td>';
            foreach ($fSingles as $single) {
                $single = trim($single);
                if (str_ends_with($single, '.mp3')) {
                    echo '<a href="/audio/' . htmlspecialchars($single) . '">' . htmlspecialchars($single) . '</a><br>';
                } elseif (str_ends_with($single, '.mp4')) {
                    echo '<a href="/video/' . htmlspecialchars($single) . '">' . htmlspecialchars($single) . '</a><br>';
                } else {
                    echo htmlspecialchars($single) . '<br>';
                }
            }
            echo '</td>';

            // Process merged_song_lists
            $mergedSongs = explode(',', $row['d_merged_song_lists']);
            echo '<td>';
            foreach ($mergedSongs as $song) {
                echo htmlspecialchars(trim($song)) . '<br>';
            }
            echo '</td>';

            echo '</tr>';
        }
        ?>
    </tbody>
</table>
<p>&nbsp;* - superlative jam</p>
<script>
function searchTable(colIndex) {
    const input = document.querySelectorAll('thead input')[colIndex];
    const filter = input.value.toUpperCase();
    const table = document.getElementById('searchableTable'); // Target the correct table
    const rows = table.getElementsByTagName('tr');

    for (let i = 1; i < rows.length; i++) {
        const cells = rows[i].getElementsByTagName('td');
        if (cells[colIndex]) {
            const textValue = cells[colIndex].textContent || cells[colIndex].innerText;
            rows[i].style.display = textValue.toUpperCase().indexOf(filter) > -1 ? '' : 'none';
        }
    }
}

function sortTable(colIndex) {
    const table = document.getElementById('searchableTable');
    const rows = Array.from(table.rows).slice(1); // Exclude header row
    const isAscending = table.dataset.sortOrder === 'asc';

    rows.sort((rowA, rowB) => {
        const cellA = rowA.cells[colIndex].innerText.trim().toUpperCase();
        const cellB = rowB.cells[colIndex].innerText.trim().toUpperCase();

        if (!isNaN(cellA) && !isNaN(cellB)) {
            return isAscending ? cellA - cellB : cellB - cellA;
        }
        return isAscending ? cellA.localeCompare(cellB) : cellB.localeCompare(cellA);
    });

    // Toggle sort order
    table.dataset.sortOrder = isAscending ? 'desc' : 'asc';

    rows.forEach(row => table.appendChild(row)); // Re-append rows in sorted order
}
</script>

</body>
</html>
