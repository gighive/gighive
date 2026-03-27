<?php declare(strict_types=1);

require_once __DIR__ . '/import_manifest_lib.php';
require_once __DIR__ . '/vendor/autoload.php';

use Production\Api\Infrastructure\Database;
use Production\Api\Services\UploadService;

function gighive_classify_finalize_error(Throwable $e): array {
    $msg = strtolower($e->getMessage());
    if (str_contains($msg, 'no space left on device') || str_contains($msg, 'disk full')) {
        return ['failure_code' => 'disk_full', 'retryable' => true];
    }
    if (str_contains($msg, 'checksum') && (str_contains($msg, 'mismatch') || str_contains($msg, 'does not match'))) {
        return ['failure_code' => 'checksum_mismatch', 'retryable' => false];
    }
    if ($e instanceof \PDOException) {
        $code      = (string)$e->getCode();
        $transient = str_starts_with($code, '40') || str_starts_with($code, '08');
        return ['failure_code' => 'db_error', 'retryable' => $transient];
    }
    if (str_contains($msg, 'thumbnail')) {
        return ['failure_code' => 'thumbnail_error', 'retryable' => true];
    }
    if (str_contains($msg, 'not found in upload_status') || str_contains($msg, 'upload not started')) {
        return ['failure_code' => 'invalid_manifest_state', 'retryable' => false];
    }
    return ['failure_code' => 'finalize_error', 'retryable' => true];
}

function gighive_capture_upload_diagnostics(
    string $webRoot,
    string $checksum,
    string $fileType,
    string $fileName,
    string $uploadId
): array {
    $ext         = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
    $storedName  = $ext !== '' ? ($checksum . '.' . $ext) : $checksum;
    $mediaPath   = $webRoot . '/' . $fileType . '/' . $storedName;
    $thumbPath   = $webRoot . '/video/thumbnails/' . $checksum . '.png';
    $tusDataPath = '/var/www/private/tus-data';
    $hookPath    = '/var/www/private/tus-hooks/uploads/' . $uploadId . '.json';
    $freeBytes   = disk_free_space($tusDataPath);
    $totalBytes  = disk_total_space($tusDataPath);
    return [
        'tus_data_path'        => $tusDataPath,
        'tus_data_free_bytes'  => $freeBytes  !== false ? (int)$freeBytes  : null,
        'tus_data_total_bytes' => $totalBytes !== false ? (int)$totalBytes : null,
        'tus_data_pct_used'    => ($freeBytes !== false && $totalBytes > 0)
            ? round((1 - $freeBytes / $totalBytes) * 100, 1) : null,
        'media_file_exists'    => is_file($mediaPath),
        'media_file_path'      => $fileType . '/' . $storedName,
        'thumbnail_exists'     => ($fileType === 'video') ? is_file($thumbPath) : null,
        'hook_payload_exists'  => ($uploadId !== '') ? is_file($hookPath) : null,
    ];
}

header('Content-Type: application/json');

$user = $_SERVER['PHP_AUTH_USER']
    ?? $_SERVER['REMOTE_USER']
    ?? $_SERVER['REDIRECT_REMOTE_USER']
    ?? null;

if ($user !== 'admin') {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'error'   => 'Forbidden',
        'message' => 'Admin access required',
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
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
    $body = json_decode($raw, true);
    if (!is_array($body)) {
        throw new RuntimeException('Invalid JSON');
    }

    $jobId    = trim((string)($body['job_id']          ?? ''));
    $uploadId = trim((string)($body['upload_id']        ?? ''));
    $checksum = strtolower(trim((string)($body['checksum_sha256'] ?? '')));

    if ($jobId === '' || !preg_match('/^[0-9]{8}-[0-9]{6}-[a-f0-9]{12}$/', $jobId)) {
        throw new RuntimeException('Invalid or missing job_id');
    }
    if ($uploadId === '' || preg_match('/^[A-Za-z0-9_-]+$/', $uploadId) !== 1) {
        throw new RuntimeException('Invalid or missing upload_id');
    }
    if (!preg_match('/^[0-9a-f]{64}$/', $checksum)) {
        throw new RuntimeException('Invalid or missing checksum_sha256');
    }

    [$jobRoot, $jobDir] = gighive_manifest_job_paths($jobId);
    if (!is_dir($jobDir)) {
        http_response_code(404);
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

    // upload_status.json must exist (written by upload_start).
    $uploadStatusPath = $jobDir . '/upload_status.json';
    if (!is_file($uploadStatusPath)) {
        throw new RuntimeException('Upload not started for job ' . $jobId . '; call upload_start first');
    }
    $statusRaw  = (string)@file_get_contents($uploadStatusPath);
    $statusData = json_decode($statusRaw ?: '', true);
    if (!is_array($statusData) || !isset($statusData['files']) || !is_array($statusData['files'])) {
        throw new RuntimeException('Malformed upload_status.json for job ' . $jobId);
    }

    // Find the target file entry by checksum.
    $targetIndex = null;
    foreach ($statusData['files'] as $i => $f) {
        if (is_array($f) && strtolower((string)($f['checksum_sha256'] ?? '')) === $checksum) {
            $targetIndex = $i;
            break;
        }
    }
    if ($targetIndex === null) {
        throw new RuntimeException('Checksum ' . $checksum . ' not found in upload_status.json for job ' . $jobId);
    }

    $traceContext = [
        'source' => 'server',
        'endpoint' => 'import_manifest_upload_finalize.php',
        'job_id' => $jobId,
        'checksum_sha256' => $checksum,
        'upload_id' => $uploadId,
        'source_relpath' => (string)($statusData['files'][$targetIndex]['source_relpath'] ?? ''),
        'file_name' => (string)($statusData['files'][$targetIndex]['file_name'] ?? ''),
        'file_type' => (string)($statusData['files'][$targetIndex]['file_type'] ?? ''),
        'size_bytes_manifest' => $statusData['files'][$targetIndex]['size_bytes'] ?? null,
    ];
    gighive_manifest_append_upload_trace($jobDir, array_merge($traceContext, [
        'phase' => 'finalize_request_received',
    ]));

    // Finalize via the manifest-aware upload path.
    $pdo     = Database::createFromEnv();
    $service = new UploadService($pdo);
    $result  = $service->finalizeManifestTusUpload($uploadId, $checksum);

    // Update the per-file entry in upload_status.json.
    $summaryState = 'db_done';
    if (($result['file_type'] ?? '') === 'video') {
        $summaryState = ($result['thumbnail_done'] ?? false) ? 'thumbnail_done' : 'uploaded';
    }

    $statusData['files'][$targetIndex] = array_merge(
        $statusData['files'][$targetIndex],
        [
            'state'           => $summaryState,
            'media_state'     => 'done',
            'thumbnail_state' => ($result['file_type'] ?? '') === 'video'
                ? (($result['thumbnail_done'] ?? false) ? 'done' : 'pending')
                : 'n_a',
            'db_state'        => 'done',
            'size_bytes'      => $result['size_bytes']      ?? $statusData['files'][$targetIndex]['size_bytes'],
            'mime_type'       => $result['mime_type']       ?? null,
            'duration_seconds'=> $result['duration_seconds'] ?? null,
            'error'           => null,
        ]
    );
    $statusData['updated_at'] = date('c');
    gighive_manifest_write_json($uploadStatusPath, $statusData, 0640);

    // Check if all files have reached a terminal success state.
    $terminalStates = ['db_done', 'thumbnail_done', 'uploaded', 'already_present'];
    $allDone        = true;
    $failCount      = 0;
    $retryableCount = 0;
    foreach ($statusData['files'] as $f) {
        $st = (string)($f['state'] ?? '');
        if ($st === 'failed') {
            $failCount++;
            if ((bool)($f['retryable'] ?? false)) {
                $retryableCount++;
                $allDone = false;
            }
        } elseif (!in_array($st, $terminalStates, true)) {
            $allDone = false;
        }
    }

    if ($allDone) {
        $uploadResultPath = $jobDir . '/upload_result.json';
        $uploadResult = [
            'success'      => $failCount === 0,
            'job_id'       => $jobId,
            'completed_at' => date('c'),
            'total'        => count($statusData['files']),
            'failed'       => $failCount,
            'files'        => $statusData['files'],
        ];
        gighive_manifest_write_json($uploadResultPath, $uploadResult, 0640);
    }

    gighive_manifest_append_upload_trace($jobDir, array_merge($traceContext, [
        'phase' => 'finalize_success',
        'status_code' => 200,
        'state' => $summaryState,
        'thumbnail_done' => (bool)($result['thumbnail_done'] ?? false),
        'db_done' => (bool)($result['db_done'] ?? false),
        'stored_file_name' => $result['file_name'] ?? null,
        'size_bytes_actual' => $result['size_bytes'] ?? null,
        'mime_type' => $result['mime_type'] ?? null,
        'duration_seconds' => $result['duration_seconds'] ?? null,
        'all_done' => $allDone,
        'failed_count' => $failCount,
    ]));

    http_response_code(200);
    echo json_encode([
        'success'         => true,
        'job_id'          => $jobId,
        'checksum_sha256' => $checksum,
        'state'           => $summaryState,
        'file_name'       => $result['file_name']        ?? null,
        'duration_seconds'=> $result['duration_seconds'] ?? null,
        'thumbnail_done'  => $result['thumbnail_done']   ?? false,
        'db_done'         => $result['db_done']          ?? false,
        'all_done'        => $allDone,
        'trace'           => gighive_manifest_read_upload_trace($jobDir),
    ]);

} catch (Throwable $e) {
    $MAX_RETRIES    = 3;
    $classification = gighive_classify_finalize_error($e);
    $diags          = null;
    $retryCount     = null;
    $retryable      = null;

    if (
        isset($uploadStatusPath, $statusData, $targetIndex) &&
        is_string($uploadStatusPath) &&
        is_array($statusData) &&
        is_int($targetIndex)
    ) {
        $currentFile = $statusData['files'][$targetIndex];
        $retryCount  = (int)($currentFile['retry_count'] ?? 0) + 1;
        $retryable   = $classification['retryable'] && $retryCount < $MAX_RETRIES;
        $diags = gighive_capture_upload_diagnostics(
            __DIR__,
            $checksum  ?? '',
            (string)($currentFile['file_type'] ?? ''),
            (string)($currentFile['file_name'] ?? ''),
            $uploadId  ?? ''
        );
        $statusData['files'][$targetIndex] = array_merge($currentFile, [
            'state'          => 'failed',
            'error'          => $e->getMessage(),
            'retryable'      => $retryable,
            'failure_code'   => $classification['failure_code'],
            'last_error'     => $e->getMessage(),
            'last_failed_at' => date('c'),
            'retry_count'    => $retryCount,
            'diagnostics'    => $diags,
        ]);
        $statusData['updated_at'] = date('c');
        @file_put_contents(
            $uploadStatusPath,
            json_encode($statusData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            LOCK_EX
        );
    }

    if (isset($jobDir) && is_string($jobDir) && is_dir($jobDir)) {
        $errorBase = isset($traceContext) ? $traceContext : [
            'source'          => 'server',
            'endpoint'        => 'import_manifest_upload_finalize.php',
            'job_id'          => $jobId    ?? null,
            'checksum_sha256' => $checksum  ?? null,
            'upload_id'       => $uploadId  ?? null,
        ];
        gighive_manifest_append_upload_trace($jobDir, array_merge($errorBase, [
            'phase'        => 'finalize_error',
            'status_code'  => 400,
            'error'        => $e->getMessage(),
            'failure_code' => $classification['failure_code'],
            'retryable'    => $retryable,
            'retry_count'  => $retryCount,
            'diagnostics'  => $diags,
        ]));
    }

    http_response_code(400);
    echo json_encode([
        'success'      => false,
        'error'        => 'Bad Request',
        'message'      => $e->getMessage(),
        'failure_code' => $classification['failure_code'],
        'retryable'    => $retryable,
        'diagnostics'  => $diags,
        'trace'        => (isset($jobDir) && is_string($jobDir) && is_dir($jobDir))
            ? gighive_manifest_read_upload_trace($jobDir)
            : [],
    ]);
}
