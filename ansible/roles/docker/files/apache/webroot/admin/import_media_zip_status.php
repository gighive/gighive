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

$jobDir   = sys_get_temp_dir() . '/gighive_import_' . $jobId . '/';
$jsonPath = $jobDir . 'status.json';

if (!is_file($jsonPath)) {
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Job not found']);
    exit;
}

$raw  = @file_get_contents($jsonPath);
$data = ($raw !== false) ? json_decode($raw, true) : null;
$state = (is_array($data) && isset($data['state'])) ? (string)$data['state'] : 'running';

// Stale job detection: running for > 3600 s with no updated_at change
if ($state === 'running' && is_array($data) && isset($data['updated_at'])) {
    try {
        $age = (new DateTime())->getTimestamp() - (new DateTime((string)$data['updated_at']))->getTimestamp();
        if ($age > 3600) {
            $files = glob($jobDir . '*');
            if (is_array($files)) { foreach ($files as $f) { @unlink($f); } }
            @rmdir($jobDir);
            header('Content-Type: application/json');
            echo json_encode([
                'success' => true,
                'state'   => 'error',
                'steps'   => [
                    ['name' => 'Import files', 'status' => 'error',
                     'message' => 'Worker timed out or failed to start'],
                ],
            ]);
            exit;
        }
    } catch (Throwable $e) {
        // Unparseable updated_at — ignore stale check
    }
}

// Use steps from status.json if present; synthesise a fallback otherwise
$steps = (is_array($data) && isset($data['steps']) && is_array($data['steps'])) ? $data['steps'] : [];
if (empty($steps)) {
    $processed  = (is_array($data) && isset($data['processed'])) ? (int)$data['processed'] : 0;
    $total      = (is_array($data) && isset($data['total']))     ? (int)$data['total']     : 1;
    $stepStatus = ($state === 'done') ? 'ok' : (($state === 'error') ? 'error' : 'running');
    $msg = ($state === 'error' && isset($data['error_message']))
        ? $data['error_message']
        : ($processed . ' / ' . $total . ' files imported');
    $steps = [
        ['name' => 'Import files', 'status' => $stepStatus,
         'message' => $msg, 'progress' => ['processed' => $processed, 'total' => $total]],
    ];
}

header('Content-Type: application/json');
echo json_encode(['success' => true, 'state' => $state, 'steps' => $steps], JSON_UNESCAPED_SLASHES);
