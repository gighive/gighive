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
        'error'   => 'Forbidden',
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
        'error'   => 'Method Not Allowed',
        'message' => 'Only GET requests are accepted',
    ]);
    exit;
}

try {
    $jobId = trim((string)($_GET['job_id'] ?? ''));
    if ($jobId === '' || !preg_match('/^[0-9]{8}-[0-9]{6}-[a-f0-9]{12}$/', $jobId)) {
        throw new RuntimeException('Invalid or missing job_id');
    }

    [$jobRoot, $jobDir] = gighive_manifest_job_paths($jobId);
    if (!is_dir($jobDir)) {
        http_response_code(404);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'error'   => 'Not Found',
            'message' => 'Job not found: ' . $jobId,
        ]);
        exit;
    }

    $meta = gighive_manifest_load_job_meta($jobDir);
    if (($meta['job_type'] ?? '') !== 'manifest_import') {
        throw new RuntimeException('Job ' . $jobId . ' is not a manifest_import job');
    }

    // Load current state from status.json.
    $statusPath = $jobDir . '/status.json';
    $statusRaw  = is_file($statusPath) ? (string)@file_get_contents($statusPath) : '';
    $status     = json_decode($statusRaw ?: '', true);
    $state      = is_array($status) ? (string)($status['state'] ?? 'unknown') : 'unknown';

    // Load duplicates.json (may not exist if there were no duplicates).
    $dupPath   = $jobDir . '/duplicates.json';
    $dupGroups = [];
    if (is_file($dupPath)) {
        $dupRaw  = (string)@file_get_contents($dupPath);
        $dupData = json_decode($dupRaw ?: '', true);
        if (is_array($dupData) && isset($dupData['groups']) && is_array($dupData['groups'])) {
            $dupGroups = $dupData['groups'];
        }
    }

    // Load manifest items so the browser can show source_relpath for reassurance.
    $payload = gighive_manifest_load_job_payload($jobDir);
    $items   = is_array($payload['items'] ?? null) ? $payload['items'] : [];

    http_response_code(200);
    header('Content-Type: application/json');
    echo json_encode([
        'success'         => true,
        'job_id'          => $jobId,
        'mode'            => (string)($meta['mode'] ?? ''),
        'state'           => $state,
        'has_duplicates'  => count($dupGroups) > 0,
        'duplicate_count' => count($dupGroups),
        'groups'          => $dupGroups,
        'items'           => $items,
    ]);

} catch (Throwable $e) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'error'   => 'Bad Request',
        'message' => $e->getMessage(),
    ]);
}
