<?php
declare(strict_types=1);

/**
 * singlesRandomPlayerJsonCacheClear.php
 * - Reads database.csv in the same directory
 * - Builds a media list
 * - Writes JSON cache atomically under ../var/cache
 * - Safe for both CLI and HTTP contexts
 */

$isCli = (PHP_SAPI === 'cli');
if (!$isCli) {
    header('Content-Type: text/plain; charset=utf-8');
}

/* -------- CSV location (next to this script) -------- */
$csvPath = __DIR__ . '/database.csv';

/* -------- Cache location (outside web paths if vhost denies /var) -------- */
$cacheDir  = getenv('GIGHIVE_CACHE_DIR') ?: __DIR__ . '/../var/cache';
$cacheFile = $cacheDir . '/stormpigs_cache.json';

/* -------- Ensure cache directory exists and is writable -------- */
if (!is_dir($cacheDir)) {
    if (!@mkdir($cacheDir, 0775, true) && !is_dir($cacheDir)) {
        $msg = "Error: Failed to create cache dir: $cacheDir";
        if ($isCli) { fwrite(STDERR, $msg . PHP_EOL); exit(1); }
        http_response_code(500); echo $msg; exit;
    }
    @chmod($cacheDir, 0775);
}

/* -------- Load CSV -------- */
if (!is_file($csvPath)) {
    $msg = "Error: CSV file not found at $csvPath";
    if ($isCli) { echo $msg . PHP_EOL; exit(0); }  // non-fatal for warm-up
    http_response_code(204); echo $msg; exit;      // no-content style response
}

$csvFile = @fopen($csvPath, 'r');
if ($csvFile === false) {
    $msg = "Error: Unable to open CSV file at $csvPath";
    if ($isCli) { fwrite(STDERR, $msg . PHP_EOL); exit(1); }
    http_response_code(500); echo $msg; exit;
}

/* -------- Read headers -------- */
$headers = fgetcsv($csvFile);
if (!is_array($headers)) {
    fclose($csvFile);
    $msg = "Error: Unable to read CSV headers.";
    if ($isCli) { fwrite(STDERR, $msg . PHP_EOL); exit(1); }
    http_response_code(500); echo $msg; exit;
}
$headers = array_map('trim', array_map('strtolower', $headers));

/* -------- Column indexes -------- */
$f_singles_index    = array_search('f_singles', $headers, true);
$crew_merged_index  = array_search('d_crew_merged', $headers, true);
$d_date_only_index  = array_search('d_date_only', $headers, true);

if ($f_singles_index === false || $crew_merged_index === false || $d_date_only_index === false) {
    fclose($csvFile);
    $msg = "Error: Required columns not found in the CSV file.";
    if ($isCli) { fwrite(STDERR, $msg . PHP_EOL); exit(1); }
    http_response_code(500); echo $msg; exit;
}

/* -------- Build media list -------- */
$allMedia = [];
while (($row = fgetcsv($csvFile)) !== false) {
    // Skip short rows defensively
    if (!isset($row[$f_singles_index], $row[$crew_merged_index], $row[$d_date_only_index])) {
        continue;
    }

    $f_singles   = (string)$row[$f_singles_index];
    $crew_merged = (string)$row[$crew_merged_index];
    $d_date_only = (string)$row[$d_date_only_index];

    // Split on commas into individual files
    $files = array_filter(array_map('trim', explode(',', $f_singles)), static fn($v) => $v !== '');

    foreach ($files as $file) {
        // Map extension to public path
        $path = $file;
        if (str_ends_with($file, '.mp3')) {
            $path = "/audio/$file";
        } elseif (str_ends_with($file, '.mp4')) {
            $path = "/video/$file";
        }

        $allMedia[] = [
            'file'         => $path,
            'crew_merged'  => $crew_merged,
            'd_date_only'  => $d_date_only,
        ];
    }
}

fclose($csvFile);

/* -------- Encode JSON -------- */
$json = json_encode($allMedia, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
if ($json === false) {
    $msg = "Error: JSON encoding failed: " . json_last_error_msg();
    if ($isCli) { fwrite(STDERR, $msg . PHP_EOL); exit(1); }
    http_response_code(500); echo $msg; exit;
}

/* -------- Atomic write to cache -------- */
$tmp = @tempnam($cacheDir, 'cache_');
if ($tmp === false) {
    $msg = "Error: Unable to create temp file in $cacheDir";
    if ($isCli) { fwrite(STDERR, $msg . PHP_EOL); exit(1); }
    http_response_code(500); echo $msg; exit;
}

$bytes = @file_put_contents($tmp, $json, LOCK_EX);
if ($bytes === false) {
    @unlink($tmp);
    $msg = "Error: Failed writing to temp file: $tmp";
    if ($isCli) { fwrite(STDERR, $msg . PHP_EOL); exit(1); }
    http_response_code(500); echo $msg; exit;
}

if (!@rename($tmp, $cacheFile)) {
    @unlink($tmp);
    $msg = "Error: Failed to move cache into place: $cacheFile";
    if ($isCli) { fwrite(STDERR, $msg . PHP_EOL); exit(1); }
    http_response_code(500); echo $msg; exit;
}
@chmod($cacheFile, 0664);

/* -------- Done -------- */
echo 'Cache rebuilt successfully.' . ($isCli ? PHP_EOL : '');

