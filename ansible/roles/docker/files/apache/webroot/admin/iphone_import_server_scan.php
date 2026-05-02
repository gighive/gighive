<?php declare(strict_types=1);

require_once __DIR__ . '/import_manifest_lib.php';

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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Content-Type: application/json');
    header('Allow: POST');
    echo json_encode(['success' => false, 'error' => 'Method Not Allowed']);
    exit;
}

header('Content-Type: application/json');

$lockDir = '/var/www/private/iphone_import_worker.lock';

if (is_dir($lockDir)) {
    $jid = is_readable($lockDir . '/job_id') ? trim((string)file_get_contents($lockDir . '/job_id')) : '';
    http_response_code(409);
    echo json_encode([
        'success' => false,
        'error'   => 'Conflict',
        'message' => 'An iPhone import is already running. Please wait for it to finish.',
        'job_id'  => $jid !== '' ? $jid : null,
    ]);
    exit;
}

if (!@mkdir($lockDir, 0775, false)) {
    http_response_code(409);
    echo json_encode([
        'success' => false,
        'error'   => 'Conflict',
        'message' => 'An iPhone import is already running. Please wait for it to finish.',
    ]);
    exit;
}

try {
    $raw     = file_get_contents('php://input');
    $payload = json_decode($raw ?: '', true);
    if (!is_array($payload)) {
        $payload = [];
    }

    $orgName   = trim((string)($payload['org_name'] ?? 'iPhone'));
    $eventType = trim((string)($payload['event_type'] ?? 'band'));
    if ($orgName === '') {
        $orgName = 'iPhone';
    }
    if (!in_array($eventType, ['band', 'wedding'], true)) {
        $eventType = 'band';
    }

    $jobId = gighive_manifest_job_id();
    [$jobRoot, $jobDir] = gighive_manifest_job_paths($jobId);

    if (!is_dir($jobRoot) && !@mkdir($jobRoot, 0775, true)) {
        throw new RuntimeException('Failed to create import root directory');
    }
    if (!@mkdir($jobDir, 0775, true)) {
        throw new RuntimeException('Failed to create job directory');
    }

    @file_put_contents($lockDir . '/job_id', $jobId, LOCK_EX);
    @file_put_contents($lockDir . '/started_at', date('c'), LOCK_EX);

    $meta = [
        'job_type'   => 'iphone_import',
        'org_name'   => $orgName,
        'event_type' => $eventType,
        'created_at' => date('c'),
    ];
    gighive_manifest_write_json($jobDir . '/meta.json', $meta, 0640);

    $steps = [
        ['name' => 'Scan staging directory', 'status' => 'pending', 'message' => '', 'index' => 0],
        ['name' => 'Hash and ingest files',  'status' => 'pending', 'message' => '', 'index' => 1],
        ['name' => 'Copy files to asset store', 'status' => 'pending', 'message' => '', 'index' => 2],
    ];
    gighive_manifest_write_json($jobDir . '/status.json', [
        'success'    => true,
        'job_id'     => $jobId,
        'state'      => 'queued',
        'message'    => 'Queued',
        'updated_at' => date('c'),
        'steps'      => $steps,
    ], 0640);

    $worker  = 'php ' . escapeshellarg(__DIR__ . '/iphone_import_worker.php') . ' ' . escapeshellarg('--job_id=' . $jobId);
    $logPath = $jobDir . '/worker.log';
    @exec($worker . ' >> ' . escapeshellarg($logPath) . ' 2>&1 &');

    http_response_code(202);
    echo json_encode([
        'success' => true,
        'job_id'  => $jobId,
        'state'   => 'queued',
        'message' => 'iPhone import started',
    ]);

} catch (Throwable $e) {
    @unlink($lockDir . '/job_id');
    @unlink($lockDir . '/started_at');
    @rmdir($lockDir);

    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error'   => 'Bad Request',
        'message' => $e->getMessage(),
    ]);
}
