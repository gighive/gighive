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

$workerLockDir = '/var/www/private/import_worker.lock';
if (is_dir($workerLockDir)) {
    $jid = is_readable($workerLockDir . '/job_id') ? trim((string)file_get_contents($workerLockDir . '/job_id')) : '';
    http_response_code(409);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'error'   => 'Conflict',
        'message' => 'An import is already running. Please wait for it to finish.',
        'job_id'  => $jid !== '' ? $jid : null,
    ]);
    exit;
}

try {
    $raw = file_get_contents('php://input');
    if (!is_string($raw) || trim($raw) === '') {
        throw new RuntimeException('Missing request body');
    }
    $body = json_decode($raw, true);
    if (!is_array($body)) {
        throw new RuntimeException('Invalid JSON');
    }

    $jobId = trim((string)($body['job_id'] ?? ''));
    if ($jobId === '' || !preg_match('/^[0-9]{8}-[0-9]{6}-[a-f0-9]{12}$/', $jobId)) {
        throw new RuntimeException('Invalid or missing job_id');
    }

    [$jobRoot, $jobDir] = gighive_manifest_job_paths($jobId);
    if (!is_dir($jobDir)) {
        throw new RuntimeException('Job not found: ' . $jobId);
    }

    $meta = gighive_manifest_load_job_meta($jobDir);
    if (($meta['job_type'] ?? '') !== 'manifest_import') {
        throw new RuntimeException('Job ' . $jobId . ' is not a manifest_import job');
    }
    $mode = (string)($meta['mode'] ?? '');
    if (!in_array($mode, ['add', 'reload'], true)) {
        throw new RuntimeException('Invalid mode in job meta');
    }

    // Load current state to confirm job is in a finalizable state.
    $statusPath = $jobDir . '/status.json';
    $statusRaw  = is_file($statusPath) ? (string)@file_get_contents($statusPath) : '';
    $status     = json_decode($statusRaw ?: '', true);
    $state      = is_array($status) ? (string)($status['state'] ?? 'unknown') : 'unknown';

    if (!in_array($state, ['draft_ready', 'awaiting_duplicate_resolution'], true)) {
        throw new RuntimeException(
            'Job ' . $jobId . ' cannot be finalized from state: ' . $state
        );
    }

    // If duplicates were present, apply user resolutions before starting the worker.
    if ($state === 'awaiting_duplicate_resolution') {
        $dupPath = $jobDir . '/duplicates.json';
        if (!is_file($dupPath)) {
            throw new RuntimeException('duplicates.json missing for job ' . $jobId);
        }
        $dupRaw    = (string)@file_get_contents($dupPath);
        $dupData   = json_decode($dupRaw ?: '', true);
        $dupGroups = is_array($dupData) ? (array)($dupData['groups'] ?? []) : [];
        if (!$dupGroups) {
            throw new RuntimeException('duplicates.json is empty or malformed');
        }

        $resolutions = $body['resolutions'] ?? [];
        if (!is_array($resolutions) || !$resolutions) {
            throw new RuntimeException('resolutions required when state is awaiting_duplicate_resolution');
        }

        // Build a map of checksum → chosen_source_relpath from the submitted resolutions.
        $choiceMap = [];
        foreach ($resolutions as $i => $r) {
            if (!is_array($r)) {
                throw new RuntimeException('Invalid resolution at index ' . $i);
            }
            $cs      = strtolower(trim((string)($r['checksum_sha256'] ?? '')));
            $chosen  = trim((string)($r['chosen_source_relpath'] ?? ''));
            if (!preg_match('/^[0-9a-f]{64}$/', $cs)) {
                throw new RuntimeException('Invalid checksum_sha256 in resolution at index ' . $i);
            }
            if ($chosen === '') {
                throw new RuntimeException('chosen_source_relpath is required in resolution at index ' . $i);
            }
            $choiceMap[$cs] = $chosen;
        }

        // Verify a resolution was supplied for every duplicate group.
        foreach ($dupGroups as $g) {
            $cs = strtolower(trim((string)($g['checksum_sha256'] ?? '')));
            if ($cs !== '' && !isset($choiceMap[$cs])) {
                throw new RuntimeException('No resolution provided for checksum ' . $cs);
            }
        }

        // Build a set of checksums that have duplicates.
        $dupChecksums = [];
        foreach ($dupGroups as $g) {
            $cs = strtolower(trim((string)($g['checksum_sha256'] ?? '')));
            if ($cs !== '') {
                $dupChecksums[$cs] = true;
            }
        }

        // Load manifest and filter items: for each duplicate checksum keep only the chosen path.
        $payload = gighive_manifest_load_job_payload($jobDir);
        $items   = is_array($payload['items'] ?? null) ? $payload['items'] : [];

        $seen     = [];
        $filtered = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $cs = strtolower(trim((string)($item['checksum_sha256'] ?? '')));
            if (isset($dupChecksums[$cs])) {
                // Duplicate checksum: keep only the chosen source_relpath.
                $itemRelpath = trim((string)($item['source_relpath'] ?? ''));
                if ($itemRelpath !== ($choiceMap[$cs] ?? '')) {
                    continue; // discard non-chosen candidate
                }
                if (isset($seen[$cs])) {
                    continue; // already kept one for this checksum
                }
                $seen[$cs] = true;
            }
            $filtered[] = $item;
        }

        $payload['items'] = $filtered;
        gighive_manifest_write_json($jobDir . '/manifest.json', $payload, 0640);

        // Update item_count in meta.json to reflect resolved count.
        $meta['item_count'] = count($filtered);
        gighive_manifest_write_json($jobDir . '/meta.json', $meta, 0640);
    }

    // Acquire the global worker lock.
    if (!@mkdir($workerLockDir, 0775, false)) {
        http_response_code(409);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'error'   => 'Conflict',
            'message' => 'An import is already running. Please wait for it to finish.',
        ]);
        exit;
    }

    @file_put_contents($workerLockDir . '/job_id', $jobId, LOCK_EX);
    @file_put_contents($workerLockDir . '/started_at', date('c'), LOCK_EX);

    // Update status to queued so the browser can begin polling.
    $steps     = gighive_manifest_init_steps($mode);
    $statusOut = [
        'success'    => true,
        'job_id'     => $jobId,
        'state'      => 'queued',
        'message'    => 'Queued',
        'updated_at' => date('c'),
        'steps'      => $steps,
    ];
    gighive_manifest_write_json($statusPath, $statusOut, 0640);

    // Start the worker against the existing job directory.
    $worker  = 'php ' . escapeshellarg(__DIR__ . '/import_manifest_worker.php') . ' ' . escapeshellarg('--job_id=' . $jobId);
    $logPath = $jobDir . '/worker.log';
    $cmd     = $worker . ' >> ' . escapeshellarg($logPath) . ' 2>&1 &';
    @exec($cmd);

    http_response_code(202);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'job_id'  => $jobId,
        'state'   => 'queued',
        'message' => 'Import started',
    ]);

} catch (Throwable $e) {
    // Only clean up lock if we created it in this request.
    if (is_dir($workerLockDir)) {
        $lockJob = is_readable($workerLockDir . '/job_id')
            ? trim((string)file_get_contents($workerLockDir . '/job_id'))
            : '';
        if ($lockJob === ($jobId ?? '')) {
            @unlink($workerLockDir . '/job_id');
            @unlink($workerLockDir . '/started_at');
            @rmdir($workerLockDir);
        }
    }

    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'error'   => 'Bad Request',
        'message' => $e->getMessage(),
    ]);
}
