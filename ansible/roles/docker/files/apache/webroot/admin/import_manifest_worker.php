<?php declare(strict_types=1);

require_once __DIR__ . '/import_manifest_lib.php';

$jobId = '';
foreach ($argv as $a) {
    if (str_starts_with($a, '--job_id=')) {
        $jobId = substr($a, strlen('--job_id='));
        break;
    }
}

if ($jobId === '' || !preg_match('/^[0-9]{8}-[0-9]{6}-[a-f0-9]{12}$/', $jobId)) {
    fwrite(STDERR, "Invalid or missing --job_id\n");
    exit(2);
}

[$jobRoot, $jobDir] = gighive_manifest_job_paths($jobId);
if (!is_dir($jobDir)) {
    fwrite(STDERR, "Job directory not found: {$jobDir}\n");
    exit(3);
}

$workerLockDir = '/var/www/private/import_worker.lock';
$lockJobPath = $workerLockDir . '/job_id';

// Best-effort: ensure the lock dir exists and matches our job.
if (!is_dir($workerLockDir)) {
    fwrite(STDERR, "Worker lock not present. Refusing to run without lock dir.\n");
    exit(4);
}
if (is_file($lockJobPath) && is_readable($lockJobPath)) {
    $lockJob = trim((string)file_get_contents($lockJobPath));
    if ($lockJob !== '' && $lockJob !== $jobId) {
        fwrite(STDERR, "Worker lock belongs to different job: {$lockJob}\n");
        exit(5);
    }
}

$statusPath = $jobDir . '/status.json';
$writeStatus = function(string $state, string $message, array $steps) use ($statusPath, $jobId): void {
    $out = [
        'success' => true,
        'job_id' => $jobId,
        'state' => $state,
        'message' => $message,
        'updated_at' => date('c'),
        'steps' => $steps,
    ];
    @file_put_contents($statusPath, json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
    @chmod($statusPath, 0640);
};

$dbLockPath = '/var/www/private/import_database.lock';
$dbLockFp = @fopen($dbLockPath, 'c');
if (!$dbLockFp) {
    $writeStatus('error', 'Failed to create database lock file', gighive_manifest_init_steps('add'));
    exit(6);
}

try {
    // Block until we can safely touch DB (worker is async, so blocking is fine).
    if (!flock($dbLockFp, LOCK_EX)) {
        throw new RuntimeException('Failed to acquire database lock');
    }

    $meta = gighive_manifest_load_job_meta($jobDir);
    if (($meta['job_type'] ?? '') !== 'manifest_import') {
        throw new RuntimeException('Invalid job_type');
    }
    $mode = (string)($meta['mode'] ?? '');
    if (!in_array($mode, ['add', 'reload'], true)) {
        throw new RuntimeException('Invalid job mode');
    }

    $payload = gighive_manifest_load_job_payload($jobDir);
    $sourceJobId = isset($meta['source_job_id']) ? (string)$meta['source_job_id'] : null;

    $res = gighive_manifest_import_run($jobDir, $jobId, $mode, $payload, $sourceJobId, $writeStatus);

    // Final result
    $writeStatus('ok', (string)($res['message'] ?? 'Completed'), (array)($res['steps'] ?? []));
    gighive_manifest_write_json($jobDir . '/result.json', $res, 0640);

} catch (Throwable $e) {
    $isCanceled = stripos($e->getMessage(), 'canceled by user') !== false;

    $modeFallback = 'add';
    try {
        $meta2 = gighive_manifest_load_job_meta($jobDir);
        $m2 = (string)($meta2['mode'] ?? 'add');
        if (in_array($m2, ['add', 'reload'], true)) $modeFallback = $m2;
    } catch (Throwable $ignored) {}

    $steps = gighive_manifest_init_steps($modeFallback);
    gighive_manifest_fail_first_pending($steps, $e->getMessage());

    if ($isCanceled) {
        $out = [
            'success' => false,
            'job_id' => $jobId,
            'mode' => $modeFallback,
            'error' => 'Canceled',
            'message' => 'Canceled by user',
            'steps' => $steps,
        ];
        $writeStatus('canceled', 'Canceled by user', $steps);
        @file_put_contents($jobDir . '/result.json', json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
        @chmod($jobDir . '/result.json', 0640);
    } else {
        $err = [
            'success' => false,
            'job_id' => $jobId,
            'mode' => $modeFallback,
            'error' => 'Import failed',
            'message' => $e->getMessage(),
            'steps' => $steps,
        ];

        $writeStatus('error', $e->getMessage(), $steps);
        @file_put_contents($jobDir . '/result.json', json_encode($err, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
        @chmod($jobDir . '/result.json', 0640);
    }

} finally {
    try { flock($dbLockFp, LOCK_UN); } catch (Throwable $e) {}
    try { fclose($dbLockFp); } catch (Throwable $e) {}

    // Release the global worker lock dir.
    try {
        if (is_dir($workerLockDir)) {
            @unlink($workerLockDir . '/job_id');
            @unlink($workerLockDir . '/started_at');
            @rmdir($workerLockDir);
        }
    } catch (Throwable $e) {}
}
