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
    $data = json_decode($raw ?: '', true);
    if (!is_array($data)) {
        throw new RuntimeException('Invalid JSON body');
    }

    $sourceJobId = isset($data['job_id']) ? trim((string)$data['job_id']) : '';
    if ($sourceJobId === '' || !preg_match('/^[0-9]{8}-[0-9]{6}-[a-f0-9]{12}$/', $sourceJobId)) {
        throw new RuntimeException('Invalid job_id');
    }

    [, $sourceDir] = gighive_manifest_job_paths($sourceJobId);
    $metaPath = $sourceDir . '/meta.json';
    $manifestPath = $sourceDir . '/manifest.json';

    if (!is_file($metaPath) || !is_readable($metaPath) || !is_file($manifestPath) || !is_readable($manifestPath)) {
        http_response_code(404);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'error' => 'Not Found',
            'message' => 'Saved manifest job not found or missing files',
        ]);
        exit;
    }

    $metaRaw = file_get_contents($metaPath);
    $meta = json_decode($metaRaw ?: '', true);
    if (!is_array($meta) || ($meta['job_type'] ?? '') !== 'manifest_import') {
        throw new RuntimeException('Invalid job metadata');
    }

    $mode = (string)($meta['mode'] ?? '');
    if (!in_array($mode, ['add', 'reload'], true)) {
        throw new RuntimeException('Saved job mode is invalid');
    }

    $payloadRaw = file_get_contents($manifestPath);
    $payload = json_decode($payloadRaw ?: '', true);
    if (!is_array($payload)) {
        throw new RuntimeException('Saved manifest.json is invalid');
    }

    $items = $payload['items'] ?? null;
    if (!is_array($items) || !$items) {
        throw new RuntimeException('Saved manifest contains no items');
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
        'mode' => $mode,
        'created_at' => date('c'),
        'item_count' => count($items),
        'source_job_id' => $sourceJobId,
    ];

    gighive_manifest_write_json($jobDir . '/meta.json', $metaOut, 0640);
    gighive_manifest_write_json($jobDir . '/manifest.json', $payload, 0640);

    $steps = gighive_manifest_init_steps($mode);
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
        'source_job_id' => $sourceJobId,
        'mode' => $mode,
        'state' => 'queued',
        'message' => 'Replay started',
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
