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

$scanJobDir = sys_get_temp_dir() . '/gighive_scan_' . basename($jobId) . '/';
$jsonPath   = $scanJobDir . 'status.json';

if (!is_file($jsonPath)) {
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Scan job not found']);
    exit;
}

$raw  = @file_get_contents($jsonPath);
// If status.json can't be read (e.g. LOCK_EX write in progress), return running fallback
if ($raw === false) {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'state' => 'running', 'scan_pct' => 0,
                      'audio_count' => 0, 'video_count' => 0,
                      'unsupported_count' => 0, 'total_bytes' => 0]);
    exit;
}

$data  = json_decode($raw, true);
// Partial write guard
if (!is_array($data)) {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'state' => 'running', 'scan_pct' => 0,
                      'audio_count' => 0, 'video_count' => 0,
                      'unsupported_count' => 0, 'total_bytes' => 0]);
    exit;
}

$state = isset($data['state']) ? (string)$data['state'] : 'running';

// Stale job detection: worker running > 3600 s with no updated_at change
if ($state === 'running' && isset($data['updated_at'])) {
    try {
        $age = (new DateTime())->getTimestamp() - (new DateTime((string)$data['updated_at']))->getTimestamp();
        if ($age > 3600) {
            $files = glob($scanJobDir . '*');
            if (is_array($files)) { foreach ($files as $f) { @unlink($f); } }
            @rmdir($scanJobDir);
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
            header('Pragma: no-cache');
            header('Expires: 0');
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'state' => 'error',
                              'error_message' => 'Scan worker timed out or failed to start']);
            exit;
        }
    } catch (Throwable $e) {
        // Unparseable updated_at — skip stale check
    }
}

// Done cleanup: remove scan job directory after 30 minutes
if ($state === 'done' && isset($data['updated_at'])) {
    try {
        $age = (new DateTime())->getTimestamp() - (new DateTime((string)$data['updated_at']))->getTimestamp();
        if ($age > 1800) {
            $files = glob($scanJobDir . '*');
            if (is_array($files)) { foreach ($files as $f) { @unlink($f); } }
            @rmdir($scanJobDir);
        }
    } catch (Throwable $e) {
        // Ignore cleanup errors
    }
}

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
header('Content-Type: application/json');
// Return the status.json payload directly — already in the correct shape
echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
