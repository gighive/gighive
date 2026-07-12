<?php declare(strict_types=1);

$user = $_SERVER['PHP_AUTH_USER']
     ?? $_SERVER['REMOTE_USER']
     ?? $_SERVER['REDIRECT_REMOTE_USER']
     ?? null;

if ($user !== 'admin') {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Forbidden']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    header('Content-Type: application/json');
    header('Allow: GET');
    echo json_encode(['success' => false, 'error' => 'Method Not Allowed']);
    exit;
}

$filename = $_GET['filename'] ?? '';

if ($filename === '') {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Missing filename parameter']);
    exit;
}

$basename = basename($filename);

if ($basename !== $filename || $basename === '' || !preg_match('/^[\w\-\.]+\.sql\.gz$/', $basename)) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Invalid filename']);
    exit;
}

$backupDir = getenv('GIGHIVE_MYSQL_BACKUPS_DIR') ?: '';
if ($backupDir === '') {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Backup directory not configured']);
    exit;
}

$fullPath = rtrim($backupDir, '/') . '/' . $basename;

if (!file_exists($fullPath) || !is_file($fullPath)) {
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Backup file not found']);
    exit;
}

if (!is_readable($fullPath)) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Backup file not readable']);
    exit;
}

$size = filesize($fullPath);
if ($size === false) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Could not stat backup file']);
    exit;
}

if (ob_get_level()) {
    ob_end_clean();
}

header('Content-Type: application/gzip');
header('Content-Disposition: attachment; filename="' . $basename . '"');
header('Content-Length: ' . $size);
header('Cache-Control: no-store');

readfile($fullPath);
