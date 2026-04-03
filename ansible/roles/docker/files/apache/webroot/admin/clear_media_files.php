<?php declare(strict_types=1);
/**
 * clear_media_files.php — Deletes all media files from the audio, video, and thumbnail dirs
 * Admin-only endpoint for wiping files from disk (does NOT touch the database)
 */

/** ---- Access Gate: allow only Basic-Auth user 'admin' ---- */
$user = $_SERVER['PHP_AUTH_USER']
     ?? $_SERVER['REMOTE_USER']
     ?? $_SERVER['REDIRECT_REMOTE_USER']
     ?? null;

if ($user !== 'admin') {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Forbidden', 'message' => 'Admin access required']);
    exit;
}

/** ---- Only accept POST requests ---- */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Content-Type: application/json');
    header('Allow: POST');
    echo json_encode(['success' => false, 'error' => 'Method Not Allowed', 'message' => 'Only POST requests are accepted']);
    exit;
}

/** ---- Derive target dirs from MEDIA_SEARCH_DIRS ---- */
$mediaDirsEnv = getenv('MEDIA_SEARCH_DIRS');
if ($mediaDirsEnv === false || trim($mediaDirsEnv) === '') {
    error_log('clear_media_files.php: MEDIA_SEARCH_DIRS env var is absent or empty — aborting');
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'error'   => 'Configuration Error',
        'message' => 'MEDIA_SEARCH_DIRS is not configured; cannot determine media directories',
    ]);
    exit;
}

// MEDIA_SEARCH_DIRS is colon-separated: /var/www/html/audio:/var/www/html/video
$mediaDirs = array_filter(array_map('trim', explode(':', $mediaDirsEnv)));

// Locate audio and video dirs by convention (audio first, video second)
$audioDir = null;
$videoDir = null;
foreach ($mediaDirs as $dir) {
    if ($audioDir === null && str_ends_with(rtrim($dir, '/'), '/audio')) {
        $audioDir = rtrim($dir, '/');
    } elseif ($videoDir === null && str_ends_with(rtrim($dir, '/'), '/video')) {
        $videoDir = rtrim($dir, '/');
    }
}

// Build the explicit list of paths to wipe (thumbnails is a subdir of video)
$targets = [];
if ($audioDir !== null) {
    $targets['audio'] = $audioDir;
}
if ($videoDir !== null) {
    $targets['video']      = $videoDir;
    $targets['thumbnails'] = $videoDir . '/thumbnails';
}

if (empty($targets)) {
    error_log('clear_media_files.php: Could not identify audio or video dirs from MEDIA_SEARCH_DIRS="' . $mediaDirsEnv . '"');
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'error'   => 'Configuration Error',
        'message' => 'Could not identify audio or video directories from MEDIA_SEARCH_DIRS',
    ]);
    exit;
}

/** ---- Delete files ---- */
error_log('clear_media_files.php: Starting file deletion across ' . count($targets) . ' path(s)');

$counts = [];
$errors = [];

foreach ($targets as $label => $path) {
    $counts[$label . '_files_deleted'] = 0;

    if (!is_dir($path)) {
        error_log('clear_media_files.php: ' . $label . ' dir not found, skipping: ' . $path);
        continue;
    }

    $files = glob($path . '/*') ?: [];
    foreach ($files as $file) {
        if (!is_file($file)) {
            continue; // skip subdirs (e.g. thumbnails/ when iterating video/)
        }
        if (@unlink($file)) {
            $counts[$label . '_files_deleted']++;
        } else {
            $errors[] = 'Failed to delete: ' . basename($file) . ' (' . $label . ')';
            error_log('clear_media_files.php: unlink failed for ' . $file);
        }
    }

    error_log('clear_media_files.php: ' . $label . ' — deleted ' . $counts[$label . '_files_deleted'] . ' file(s)');
}

$total = array_sum($counts);
error_log('clear_media_files.php: Complete — ' . $total . ' total file(s) deleted, ' . count($errors) . ' error(s)');

/** ---- Send response ---- */
http_response_code(200);
header('Content-Type: application/json');
echo json_encode(array_merge(
    ['success' => empty($errors), 'total_deleted' => $total],
    $counts,
    ['errors' => $errors]
));
