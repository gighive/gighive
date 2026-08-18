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

/** ---- Azure Blob mode guard ---- */
// In Azure Blob mode there are no local media directories to clear.
// Full Blob-aware delete UI is a Phase 10 (Tranche 2) concern.
$storageBackend = getenv('GIGHIVE_MEDIA_STORAGE_BACKEND') ?: 'local';
if ($storageBackend === 'azure_blob') {
    http_response_code(200);
    header('Content-Type: application/json');
    echo json_encode([
        'success'       => true,
        'total_deleted' => 0,
        'message'       => 'Azure Blob mode: local media directories are not present; no files to clear. Use the Blob admin tools to manage media in Blob Storage.',
    ]);
    exit;
}

/** ---- Derive target dirs from MEDIA_LOCAL_* env vars (Phase 5: MEDIA_SEARCH_DIRS retired) ---- */
$audioDir = rtrim(getenv('MEDIA_LOCAL_AUDIO_DIR') ?: '/var/www/html/audio', '/');
$videoDir = rtrim(getenv('MEDIA_LOCAL_VIDEO_DIR') ?: '/var/www/html/video', '/');
$thumbDir = rtrim(getenv('MEDIA_LOCAL_THUMB_DIR') ?: '/var/www/html/video/thumbnails', '/');

// Build the explicit list of paths to wipe
$targets = [
    'audio'      => $audioDir,
    'video'      => $videoDir,
    'thumbnails' => $thumbDir,
];

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
