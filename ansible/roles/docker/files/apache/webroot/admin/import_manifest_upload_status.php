<?php declare(strict_types=1);

require_once __DIR__ . '/import_manifest_lib.php';

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

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
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

    // Return upload_result.json if the upload batch has finished.
    $uploadResultPath = $jobDir . '/upload_result.json';
    if (is_file($uploadResultPath)) {
        $raw     = (string)@file_get_contents($uploadResultPath);
        $decoded = json_decode($raw ?: '', true);
        if (is_array($decoded)) {
            http_response_code(200);
            echo json_encode(array_merge($decoded, [
                'complete' => true,
                'trace' => gighive_manifest_read_upload_trace($jobDir),
            ]));
            exit;
        }
    }

    // Return upload_status.json for in-progress polling.
    $uploadStatusPath = $jobDir . '/upload_status.json';
    if (is_file($uploadStatusPath)) {
        $raw     = (string)@file_get_contents($uploadStatusPath);
        $decoded = json_decode($raw ?: '', true);
        if (is_array($decoded)) {
            http_response_code(200);
            echo json_encode(array_merge($decoded, [
                'complete' => false,
                'trace' => gighive_manifest_read_upload_trace($jobDir),
            ]));
            exit;
        }
    }

    // Neither file exists: upload has not been started yet.
    http_response_code(200);
    echo json_encode([
        'success'  => true,
        'job_id'   => $jobId,
        'complete' => false,
        'started'  => false,
        'files'    => [],
        'trace'    => gighive_manifest_read_upload_trace($jobDir),
    ]);

} catch (Throwable $e) {
    if (isset($jobDir) && is_string($jobDir) && is_dir($jobDir)) {
        gighive_manifest_append_upload_trace($jobDir, [
            'source'      => 'server',
            'endpoint'    => 'import_manifest_upload_status.php',
            'phase'       => 'status_poll_error',
            'job_id'      => $jobId ?? null,
            'status_code' => 400,
            'error'       => $e->getMessage(),
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
