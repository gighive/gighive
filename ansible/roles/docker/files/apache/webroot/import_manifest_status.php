<?php declare(strict_types=1);

require_once __DIR__ . '/import_manifest_lib.php';

$user = $_SERVER['PHP_AUTH_USER']
    ?? $_SERVER['REMOTE_USER']
    ?? $_SERVER['REDIRECT_REMOTE_USER']
    ?? null;

if ($user !== 'admin') {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'error' => 'Forbidden',
        'message' => 'Admin access required',
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    header('Content-Type: application/json');
    header('Allow: GET');
    echo json_encode([
        'success' => false,
        'error' => 'Method Not Allowed',
        'message' => 'Only GET requests are accepted',
    ]);
    exit;
}

$jobId = isset($_GET['job_id']) ? trim((string)$_GET['job_id']) : '';
if ($jobId === '' || !preg_match('/^[0-9]{8}-[0-9]{6}-[a-f0-9]{12}$/', $jobId)) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'error' => 'Bad Request',
        'message' => 'Invalid job_id',
    ]);
    exit;
}

[, $jobDir] = gighive_manifest_job_paths($jobId);
if (!is_dir($jobDir)) {
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'error' => 'Not Found',
        'message' => 'Job not found',
    ]);
    exit;
}

$resultPath = $jobDir . '/result.json';
$statusPath = $jobDir . '/status.json';

if (is_file($resultPath) && is_readable($resultPath)) {
    $raw = file_get_contents($resultPath);
    $data = json_decode($raw ?: '', true);
    if (!is_array($data)) {
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'error' => 'Server Error',
            'message' => 'Invalid result.json',
        ]);
        exit;
    }

    $msg = strtolower(trim((string)($data['message'] ?? '')));
    $err = strtolower(trim((string)($data['error'] ?? '')));
    $isCanceled = ($err === 'canceled') || (str_contains($msg, 'canceled by user'));
    $state = $isCanceled ? 'canceled' : (($data['success'] ?? false) ? 'ok' : 'error');
    http_response_code(200);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'job_id' => $jobId,
        'state' => $state,
        'mode' => $data['mode'] ?? null,
        'message' => $data['message'] ?? null,
        'steps' => $data['steps'] ?? null,
        'result' => $data,
    ]);
    exit;
}

if (is_file($statusPath) && is_readable($statusPath)) {
    $raw = file_get_contents($statusPath);
    $data = json_decode($raw ?: '', true);
    if (is_array($data)) {
        http_response_code(200);
        header('Content-Type: application/json');
        echo json_encode($data);
        exit;
    }
}

http_response_code(200);
header('Content-Type: application/json');
echo json_encode([
    'success' => true,
    'job_id' => $jobId,
    'state' => 'queued',
    'message' => 'Queued',
    'updated_at' => date('c'),
]);
