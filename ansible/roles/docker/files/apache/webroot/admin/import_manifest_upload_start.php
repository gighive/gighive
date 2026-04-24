<?php declare(strict_types=1);

require_once __DIR__ . '/import_manifest_lib.php';
require_once dirname(__DIR__) . '/vendor/autoload.php';

use Production\Api\Infrastructure\Database;

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

    $jobId = trim((string)($body['job_id'] ?? ''));
    if ($jobId === '' || !preg_match('/^[0-9]{8}-[0-9]{6}-[a-f0-9]{12}$/', $jobId)) {
        throw new RuntimeException('Invalid or missing job_id');
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

    // Step 2 upload can only start after Step 1 (manifest import worker) has succeeded.
    $resultPath = $jobDir . '/result.json';
    if (!is_file($resultPath)) {
        throw new RuntimeException('Manifest import has not completed yet for job ' . $jobId);
    }
    $resRaw = (string)@file_get_contents($resultPath);
    $res    = json_decode($resRaw ?: '', true);
    if (!is_array($res) || ($res['success'] ?? false) !== true) {
        throw new RuntimeException('Manifest import did not succeed for job ' . $jobId);
    }

    // If upload_status.json already exists this job was previously started.
    // Return the existing status so the browser can resume without overwriting progress.
    $uploadStatusPath = $jobDir . '/upload_status.json';
    if (is_file($uploadStatusPath)) {
        $existingRaw = (string)@file_get_contents($uploadStatusPath);
        $existing    = json_decode($existingRaw ?: '', true);
        if (is_array($existing) && isset($existing['files'])) {
            // Normalize any stale 'uploading' entries back to 'pending'.
            // No active upload tracking is maintained across browser reloads.
            $normalized = 0;
            foreach ($existing['files'] as &$f) {
                if (is_array($f) && (string)($f['state'] ?? '') === 'uploading') {
                    $f['state']      = 'pending';
                    $f['last_error'] = null;
                    $normalized++;
                }
            }
            unset($f);
            if ($normalized > 0) {
                $existing['updated_at'] = date('c');
                gighive_manifest_write_json($uploadStatusPath, $existing, 0640);
            }
            gighive_manifest_append_upload_trace($jobDir, [
                'source' => 'server',
                'endpoint' => 'import_manifest_upload_start.php',
                'phase' => 'upload_start_resume',
                'job_id' => $jobId,
                'status_code' => 200,
                'resumed' => true,
                'file_count' => count((array)$existing['files']),
                'stale_uploading_normalized' => $normalized,
            ]);
            http_response_code(200);
            echo json_encode([
                'success'  => true,
                'job_id'   => $jobId,
                'resumed'  => true,
                'files'    => $existing['files'],
                'trace'    => gighive_manifest_read_upload_trace($jobDir),
            ]);
            exit;
        }
    }

    $payload = gighive_manifest_load_job_payload($jobDir);
    $items   = is_array($payload['items'] ?? null) ? $payload['items'] : [];

    $baseDir = dirname(__DIR__);
    $pdo     = Database::createFromEnv();

    $uploadFiles = [];
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $checksum    = strtolower(trim((string)($item['checksum_sha256'] ?? '')));
        $fileType    = (string)($item['file_type'] ?? '');
        $fileName    = (string)($item['file_name'] ?? '');
        $sourceRelpath = (string)($item['source_relpath'] ?? '');
        $sizeBytes   = isset($item['size_bytes']) && $item['size_bytes'] !== null
            ? (int)$item['size_bytes']
            : null;

        $validCs   = preg_match('/^[0-9a-f]{64}$/', $checksum) === 1;
        $validType = in_array($fileType, ['audio', 'video'], true);
        if (!$validCs || !$validType) {
            gighive_manifest_append_upload_trace($jobDir, [
                'source'          => 'server',
                'endpoint'        => 'import_manifest_upload_start.php',
                'phase'           => 'upload_start_item_skipped',
                'job_id'          => $jobId,
                'checksum_sha256' => $checksum,
                'file_name'       => $fileName,
                'source_relpath'  => $sourceRelpath,
                'file_type'       => $fileType,
                'reason'          => !$validCs ? 'invalid_checksum' : 'invalid_file_type',
            ]);
            continue;
        }

        $ext        = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        $storedName = $ext !== '' ? ($checksum . '.' . $ext) : $checksum;
        $targetPath = $baseDir . '/' . $fileType . '/' . $storedName;

        $mediaPresent = is_file($targetPath);

        // Thumbnail check: only applicable for video.
        $thumbState = 'n_a';
        if ($fileType === 'video') {
            $thumbPath  = $baseDir . '/video/thumbnails/' . $checksum . '.png';
            $thumbState = is_file($thumbPath) ? 'done' : 'pending';
        }

        // DB check: asset row exists iff ingestion (Step 2) has completed for this checksum.
        $dbDone = false;
        $stmt   = $pdo->prepare('SELECT asset_id FROM assets WHERE checksum_sha256 = :cs LIMIT 1');
        $stmt->execute([':cs' => $checksum]);
        $dbDone = ($stmt->fetch(PDO::FETCH_ASSOC) !== false);

        $mediaState = $mediaPresent ? 'done' : 'pending';
        $dbState    = $dbDone ? 'done' : 'pending';

        // Compute user-facing summary state.
        $thumbComplete = ($fileType !== 'video') || ($thumbState === 'done');
        if ($mediaPresent && $thumbComplete && $dbDone) {
            $summaryState = 'already_present';
        } else {
            $summaryState = 'pending';
        }

        $uploadFiles[] = [
            'checksum_sha256' => $checksum,
            'file_name'       => $fileName,
            'source_relpath'  => $sourceRelpath,
            'file_type'       => $fileType,
            'size_bytes'      => $sizeBytes,
            'state'           => $summaryState,
            'media_state'     => $mediaState,
            'thumbnail_state' => $thumbState,
            'db_state'        => $dbState,
            'error'           => null,
            'retryable'       => null,
            'failure_code'    => null,
            'last_error'      => null,
            'last_failed_at'  => null,
            'retry_count'     => 0,
            'diagnostics'     => null,
        ];
    }

    gighive_manifest_write_json($uploadStatusPath, [
        'job_id'     => $jobId,
        'started_at' => date('c'),
        'files'      => $uploadFiles,
    ], 0640);

    $stateCounts = [];
    foreach ($uploadFiles as $uploadFile) {
        $stateKey = (string)($uploadFile['state'] ?? 'unknown');
        $stateCounts[$stateKey] = (int)($stateCounts[$stateKey] ?? 0) + 1;
    }
    gighive_manifest_append_upload_trace($jobDir, [
        'source' => 'server',
        'endpoint' => 'import_manifest_upload_start.php',
        'phase' => 'upload_start_created',
        'job_id' => $jobId,
        'status_code' => 200,
        'resumed' => false,
        'file_count' => count($uploadFiles),
        'state_counts' => $stateCounts,
    ]);

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'job_id'  => $jobId,
        'resumed' => false,
        'files'   => $uploadFiles,
        'trace'   => gighive_manifest_read_upload_trace($jobDir),
    ]);

} catch (Throwable $e) {
    if (isset($jobDir) && is_string($jobDir) && is_dir($jobDir)) {
        gighive_manifest_append_upload_trace($jobDir, [
            'source' => 'server',
            'endpoint' => 'import_manifest_upload_start.php',
            'phase' => 'upload_start_error',
            'job_id' => $jobId ?? null,
            'status_code' => 400,
            'error' => $e->getMessage(),
        ]);
    }
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error'   => 'Bad Request',
        'message' => $e->getMessage(),
        'trace'   => (isset($jobDir) && is_string($jobDir) && is_dir($jobDir))
            ? gighive_manifest_read_upload_trace($jobDir)
            : [],
    ]);
}
