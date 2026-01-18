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

$workerLockDir = '/var/www/private/import_worker.lock';
if (is_dir($workerLockDir)) {
    $jid = is_readable($workerLockDir . '/job_id') ? trim((string)file_get_contents($workerLockDir . '/job_id')) : '';
    http_response_code(409);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'error' => 'Conflict',
        'message' => 'An import is already running. Please wait for it to finish.',
        'job_id' => $jid !== '' ? $jid : null,
    ]);
    exit;
}

if (!@mkdir($workerLockDir, 0775, false)) {
    http_response_code(409);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'error' => 'Conflict',
        'message' => 'An import is already running. Please wait for it to finish.',
    ]);
    exit;
}

try {
    $raw = file_get_contents('php://input');
    if (!is_string($raw) || trim($raw) === '') {
        throw new RuntimeException('Missing request body');
    }
    $payload = json_decode($raw, true);
    if (!is_array($payload)) {
        throw new RuntimeException('Invalid JSON');
    }

    $items = $payload['items'] ?? null;
    if (!is_array($items) || !$items) {
        throw new RuntimeException('Missing or empty items array');
    }

    $jobId = gighive_manifest_job_id();
    [$jobRoot, $jobDir] = gighive_manifest_job_paths($jobId);

    if (!is_dir($jobRoot) && !@mkdir($jobRoot, 0775, true)) {
        throw new RuntimeException('Failed to create import root directory');
    }
    if (!@mkdir($jobDir, 0775, true)) {
        throw new RuntimeException('Failed to create job directory');
    }

    @file_put_contents($workerLockDir . '/job_id', $jobId, LOCK_EX);
    @file_put_contents($workerLockDir . '/started_at', date('c'), LOCK_EX);

    $metaOut = [
        'job_type' => 'manifest_import',
        'mode' => 'reload',
        'created_at' => date('c'),
        'item_count' => count($items),
    ];

    gighive_manifest_write_json($jobDir . '/meta.json', $metaOut, 0640);
    gighive_manifest_write_json($jobDir . '/manifest.json', $payload, 0640);

    $steps = gighive_manifest_init_steps('reload');
    $statusOut = [
        'success' => true,
        'job_id' => $jobId,
        'state' => 'queued',
        'message' => 'Queued',
        'updated_at' => date('c'),
        'steps' => $steps,
    ];
    gighive_manifest_write_json($jobDir . '/status.json', $statusOut, 0640);

    $worker = 'php ' . escapeshellarg(__DIR__ . '/import_manifest_worker.php') . ' ' . escapeshellarg('--job_id=' . $jobId);
    $logPath = $jobDir . '/worker.log';
    $cmd = $worker . ' >> ' . escapeshellarg($logPath) . ' 2>&1 &';
    @exec($cmd);

    http_response_code(202);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'job_id' => $jobId,
        'state' => 'queued',
        'message' => 'Import started',
    ]);

} catch (Throwable $e) {
    @unlink($workerLockDir . '/job_id');
    @unlink($workerLockDir . '/started_at');
    @rmdir($workerLockDir);

    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'error' => 'Bad Request',
        'message' => $e->getMessage(),
    ]);
}
