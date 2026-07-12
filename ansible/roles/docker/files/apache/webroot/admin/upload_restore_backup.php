<?php declare(strict_types=1);

$user = $_SERVER['PHP_AUTH_USER']
     ?? $_SERVER['REMOTE_USER']
     ?? $_SERVER['REDIRECT_REMOTE_USER']
     ?? null;
if ($user !== 'admin') {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Admin required']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

header('Content-Type: application/json');

$backupDir = getenv('GIGHIVE_MYSQL_BACKUPS_DIR') ?: '';
if ($backupDir === '' || !is_dir($backupDir) || !is_writable($backupDir)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Backup directory not configured or not writable']);
    exit;
}

$rawName = $_GET['filename'] ?? '';
$basename = basename($rawName);

if ($basename !== $rawName || $basename === '' || !preg_match('/^[\w\-\.]+\.sql\.gz$/', $basename)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid filename']);
    exit;
}

$safeName = $basename;

$destPath = rtrim($backupDir, '/') . '/' . $safeName;

$in = fopen('php://input', 'rb');
if ($in === false) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Failed to open input stream']);
    exit;
}

$out = fopen($destPath, 'wb');
if ($out === false) {
    fclose($in);
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Failed to open destination file for writing']);
    exit;
}

$bytesWritten = 0;
while (!feof($in)) {
    $chunk = fread($in, 65536);
    if ($chunk === false) { break; }
    if (fwrite($out, $chunk) === false) { break; }
    $bytesWritten += strlen($chunk);
}
fclose($in);
fclose($out);

if ($bytesWritten === 0) {
    unlink($destPath);
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'No data received']);
    exit;
}

$fh = fopen($destPath, 'rb');
$magic = $fh ? fread($fh, 2) : '';
if ($fh) { fclose($fh); }
if ($magic !== "\x1f\x8b") {
    unlink($destPath);
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'File does not appear to be a valid gzip archive']);
    exit;
}

echo json_encode(['success' => true, 'filename' => $safeName, 'bytes' => $bytesWritten]);
