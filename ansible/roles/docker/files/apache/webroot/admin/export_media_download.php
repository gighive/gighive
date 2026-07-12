<?php
$user = $_SERVER['PHP_AUTH_USER']
    ?? $_SERVER['REMOTE_USER']
    ?? $_SERVER['REDIRECT_REMOTE_USER']
    ?? null;

if ($user !== 'admin') {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Admin access required']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$jobId = isset($_GET['job_id']) ? trim((string)$_GET['job_id']) : '';
if ($jobId === '' || !preg_match('/^[a-f0-9]{16}$/', $jobId)) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Invalid job_id']);
    exit;
}

$jobDir   = sys_get_temp_dir() . '/gighive_export_' . basename($jobId) . '/';
$jsonPath = $jobDir . 'status.json';
$archivePath = $jobDir . 'archive.tar.gz';

if (!is_file($jsonPath)) {
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Job not found']);
    exit;
}

$raw   = @file_get_contents($jsonPath);
$data  = ($raw !== false) ? json_decode($raw, true) : null;
$state = (is_array($data) && isset($data['state'])) ? (string)$data['state'] : 'unknown';

if ($state !== 'done') {
    http_response_code(202);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Job not complete']);
    exit;
}

if (!is_file($archivePath)) {
    http_response_code(410);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Archive no longer available']);
    exit;
}

$filename = (is_array($data) && !empty($data['filename']))
    ? (string)$data['filename']
    : ('gighive_export_' . $jobId . '.tar.gz');
$size = (int)filesize($archivePath);

header('Content-Type: application/gzip');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . $size);
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

@ini_set('zlib.output_compression', 'off');
while (ob_get_level() > 0) { ob_end_clean(); }

$handle = @fopen($archivePath, 'rb');
if ($handle !== false) {
    while (!feof($handle) && !connection_aborted()) {
        $chunk = fread($handle, 262144);
        if ($chunk === false || $chunk === '') break;
        echo $chunk;
        if (ob_get_level() > 0) ob_flush();
        flush();
    }
    fclose($handle);
}

// Clean up job directory after streaming
$files = glob($jobDir . '*');
if (is_array($files)) { foreach ($files as $f) { @unlink($f); } }
@rmdir($jobDir);
exit;
