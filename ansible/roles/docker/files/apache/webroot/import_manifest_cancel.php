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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Content-Type: application/json');
    header('Allow: POST');
    echo json_encode([
        'success' => false,
        'error' => 'Method Not Allowed',
        'message' => 'Only POST requests are accepted',
    ]);
    exit;
}

try {
    $raw = file_get_contents('php://input');
    $payload = json_decode($raw ?: '', true);
    if (!is_array($payload)) {
        throw new RuntimeException('Invalid JSON');
    }

    $jobId = isset($payload['job_id']) ? trim((string)$payload['job_id']) : '';
    if ($jobId === '' || !preg_match('/^[0-9]{8}-[0-9]{6}-[a-f0-9]{12}$/', $jobId)) {
        throw new RuntimeException('Invalid job_id');
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

    $cancelPath = $jobDir . '/cancel.json';
    $out = [
        'job_id' => $jobId,
        'requested_at' => date('c'),
    ];

    @file_put_contents($cancelPath, json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
    @chmod($cancelPath, 0640);

    http_response_code(200);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'job_id' => $jobId,
        'message' => 'Cancel requested',
    ]);

} catch (Throwable $e) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'error' => 'Bad Request',
        'message' => $e->getMessage(),
    ]);
}
