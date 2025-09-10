<?php
// Load the CSV file
$filename = 'unified_stormpigs_database.csv';

if (!file_exists($filename)) {
    die('Error: CSV file not found.');
}

$csvFile = fopen($filename, 'r');
if (!$csvFile) {
    die('Error: Unable to open CSV file.');
}

// Read the headers
$headers = fgetcsv($csvFile);
if (!is_array($headers)) {
    die('Error: Unable to read headers.');
}

$headers = array_map('trim', array_map('strtolower', $headers));

// Identify relevant columns
$f_singles_index = array_search('f_singles', $headers);
$crew_merged_index = array_search('d_crew_merged', $headers);
$d_date_only_index = array_search('d_date_only', $headers);

if ($f_singles_index === false || $crew_merged_index === false || $d_date_only_index === false ) {
    die('Error: Required columns not found in the CSV file.');
}

// Collect all files and their associated crew information
$allMedia = [];
while (($row = fgetcsv($csvFile)) !== false) {
    $f_singles = $row[$f_singles_index];
    $crew_merged = $row[$crew_merged_index];
    $d_date_only = $row[$d_date_only_index];

    $files = array_map('trim', explode(',', $f_singles));
    foreach ($files as $file) {
        $media = [
            'file' => str_ends_with($file, '.mp3') ? "/audio/$file" : (str_ends_with($file, '.mp4') ? "/video/$file" : $file),
            'crew_merged' => $crew_merged,
            'd_date_only' => $d_date_only,
        ];
        $allMedia[] = $media;
    }
}

// Close the file
fclose($csvFile);

// Save the cache
file_put_contents('/tmp/stormpigs_cache.json', json_encode($allMedia));
echo 'Cache rebuilt successfully.';
?>
