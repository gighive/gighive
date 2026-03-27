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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Content-Type: application/json');
    header('Allow: POST');
    echo json_encode([
        'success' => false,
        'error'   => 'Method Not Allowed',
        'message' => 'Only POST requests are accepted',
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

    $mode = strtolower(trim((string)($payload['mode'] ?? '')));
    if (!in_array($mode, ['add', 'reload'], true)) {
        throw new RuntimeException('Invalid mode (expected add or reload)');
    }

    // Validate items (checksum, file_type, file_name, event_date required per item).
    gighive_manifest_validate_payload($payload);

    // Extract and validate duplicate groups (browser-detected before submission).
    $duplicateGroups = $payload['duplicates'] ?? [];
    if (!is_array($duplicateGroups)) {
        $duplicateGroups = [];
    }

    $hasDuplicates    = false;
    $validatedGroups  = [];
    foreach ($duplicateGroups as $i => $group) {
        if (!is_array($group)) {
            continue;
        }
        $checksum = strtolower(trim((string)($group['checksum_sha256'] ?? '')));
        if (!preg_match('/^[0-9a-f]{64}$/', $checksum)) {
            throw new RuntimeException('Invalid checksum_sha256 in duplicates group at index ' . $i);
        }
        $candidates = $group['candidates'] ?? [];
        if (!is_array($candidates) || count($candidates) < 2) {
            throw new RuntimeException('Duplicate group at index ' . $i . ' must have at least 2 candidates');
        }
        $validatedGroups[] = [
            'checksum_sha256' => $checksum,
            'candidates'      => $candidates,
        ];
        $hasDuplicates = true;
    }

    $jobId = gighive_manifest_job_id();
    [$jobRoot, $jobDir] = gighive_manifest_job_paths($jobId);

    if (!is_dir($jobRoot) && !@mkdir($jobRoot, 0775, true)) {
        throw new RuntimeException('Failed to create import root directory');
    }
    if (!@mkdir($jobDir, 0775, true)) {
        throw new RuntimeException('Failed to create job directory');
    }

    $items  = $payload['items'] ?? [];
    $metaOut = [
        'job_type'   => 'manifest_import',
        'mode'       => $mode,
        'created_at' => date('c'),
        'item_count' => count($items),
        'source'     => 'browser',
    ];
    gighive_manifest_write_json($jobDir . '/meta.json', $metaOut, 0640);

    // Save full payload as manifest.json (org_name, event_type, items).
    // The 'duplicates' key is stripped: it is not consumed by the worker and
    // is stored separately in duplicates.json for the resolution UI.
    $manifestPayload = $payload;
    unset($manifestPayload['duplicates']);
    gighive_manifest_write_json($jobDir . '/manifest.json', $manifestPayload, 0640);

    if ($hasDuplicates) {
        gighive_manifest_write_json($jobDir . '/duplicates.json', [
            'job_id' => $jobId,
            'groups' => $validatedGroups,
        ], 0640);
    }

    $state   = $hasDuplicates ? 'awaiting_duplicate_resolution' : 'draft_ready';
    $message = $hasDuplicates
        ? 'Waiting for duplicate resolution (' . count($validatedGroups) . ' group(s))'
        : 'Draft ready to submit';

    $statusOut = [
        'success'    => true,
        'job_id'     => $jobId,
        'state'      => $state,
        'message'    => $message,
        'updated_at' => date('c'),
        'steps'      => [],
    ];
    gighive_manifest_write_json($jobDir . '/status.json', $statusOut, 0640);

    http_response_code(200);
    header('Content-Type: application/json');
    echo json_encode([
        'success'         => true,
        'job_id'          => $jobId,
        'state'           => $state,
        'has_duplicates'  => $hasDuplicates,
        'duplicate_count' => count($validatedGroups),
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
