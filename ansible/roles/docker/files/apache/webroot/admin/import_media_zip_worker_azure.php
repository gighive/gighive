<?php declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit(1);
}

$jobId = '';
foreach ($argv as $arg) {
    if (str_starts_with((string)$arg, '--job_id=')) {
        $jobId = substr((string)$arg, strlen('--job_id='));
    }
}
if ($jobId === '' || !preg_match('/^[a-f0-9]{16}$/', $jobId)) {
    fwrite(STDERR, "import_media_zip_worker_azure: invalid or missing --job_id\n");
    exit(1);
}

$jobDir   = sys_get_temp_dir() . '/gighive_import_' . $jobId . '/';
$jsonPath = $jobDir . 'status.json';

if (!is_dir($jobDir)) {
    fwrite(STDERR, "import_media_zip_worker_azure: job directory not found: $jobDir\n");
    exit(1);
}

require_once __DIR__ . '/admin_media_lib.php';
$exts         = loadMediaExtensions();
$audioExtsSet = $exts['audioSet'];
$videoExtsSet = $exts['videoSet'];

$azAccount   = (string)getenv('AZURE_BLOB_ACCOUNT_NAME');
$azContainer = (string)getenv('AZURE_BLOB_CONTAINER');
$azSas       = (string)getenv('AZURE_BLOB_SAS_TOKEN');
if ($azAccount === '' || $azContainer === '' || $azSas === '') {
    writeJobStatus($jsonPath, [
        'success'       => true,
        'job_id'        => $jobId,
        'state'         => 'error',
        'error_message' => 'Azure credentials not configured',
        'steps'         => [
            ['name' => 'List blobs',   'status' => 'ok',    'message' => '', 'progress' => null],
            ['name' => 'Import files', 'status' => 'error', 'message' => 'Azure credentials not configured'],
        ],
    ]);
    exit(1);
}

$prefix = is_file($jobDir . 'prefix.txt')
    ? trim((string)file_get_contents($jobDir . 'prefix.txt'))
    : '';

$bloblistPath = $jobDir . 'bloblist.json';
$rows = is_file($bloblistPath) ? json_decode((string)file_get_contents($bloblistPath), true) : null;
if (!is_array($rows)) {
    writeJobStatus($jsonPath, [
        'success'       => true,
        'job_id'        => $jobId,
        'state'         => 'error',
        'error_message' => 'bloblist.json missing or corrupt',
        'steps'         => [
            ['name' => 'List blobs',   'status' => 'ok',    'message' => '', 'progress' => null],
            ['name' => 'Import files', 'status' => 'error', 'message' => 'bloblist.json missing or corrupt'],
        ],
    ]);
    exit(1);
}

$total = count($rows);
if ($total === 0) {
    writeJobStatus($jsonPath, [
        'success'       => true,
        'job_id'        => $jobId,
        'state'         => 'error',
        'error_message' => 'No blobs to import',
        'steps'         => [
            ['name' => 'List blobs',   'status' => 'ok',    'message' => '0 blobs found', 'progress' => null],
            ['name' => 'Import files', 'status' => 'error', 'message' => 'No blobs to import'],
        ],
    ]);
    exit(1);
}

try {
    set_time_limit(0);

    if (!is_dir('/var/www/html/audio') || !is_dir('/var/www/html/video')) {
        throw new RuntimeException('Destination directory /var/www/html/audio or /var/www/html/video does not exist — volume may not be mounted');
    }

    $processed     = 0;
    $added         = 0;
    $alreadyExists = 0;
    $bytesAdded    = 0;
    $errors        = [];

    foreach ($rows as $blob) {
        $blobName = (string)($blob['blob_name'] ?? '');
        $size     = (int)($blob['size'] ?? 0);

        $relative = str_starts_with($blobName, $prefix) ? substr($blobName, strlen($prefix)) : '';
        $basename = basename($relative);

        if (str_starts_with($relative, 'video/thumbnails/') && isValidThumbnailEntry('thumbnails/' . $basename)) {
            $destDir = '/var/www/html/video/thumbnails';
            @mkdir($destDir, 0775, true);
            $dest = $destDir . '/' . $basename;
        } elseif (str_starts_with($relative, 'audio/') && isValidMediaEntry($basename, $audioExtsSet, $videoExtsSet)) {
            $dest = '/var/www/html/audio/' . $basename;
        } elseif (str_starts_with($relative, 'video/') && isValidMediaEntry($basename, $audioExtsSet, $videoExtsSet)) {
            $dest = '/var/www/html/video/' . $basename;
        } else {
            continue;
        }

        $processed++;

        if (is_file($dest)) {
            $alreadyExists++;
        } else {
            try {
                downloadBlobToFile($blobName, $dest, $azAccount, $azContainer, $azSas);
                $added++;
                $bytesAdded += $size;
            } catch (RuntimeException $e) {
                $errors[] = $e->getMessage();
            }
        }

        writeJobStatus($jsonPath, [
            'success'        => true,
            'job_id'         => $jobId,
            'state'          => 'running',
            'updated_at'     => date('c'),
            'processed'      => $processed,
            'total'          => $total,
            'added'          => $added,
            'already_exists' => $alreadyExists,
            'bytes_added'    => $bytesAdded,
            'steps'          => [
                ['name' => 'List blobs', 'status' => 'ok', 'message' => $total . ' blobs found', 'progress' => null],
                ['name' => 'Import files', 'status' => 'running',
                 'message'  => $processed . ' / ' . $total . ' files imported',
                 'progress' => ['processed' => $processed, 'total' => $total]],
            ],
        ]);
    }

    if ($processed === 0) {
        writeJobStatus($jsonPath, [
            'success'       => true,
            'job_id'        => $jobId,
            'state'         => 'error',
            'error_message' => 'No valid GigHive media entries found in blob list',
            'steps'         => [
                ['name' => 'List blobs',   'status' => 'ok',    'message' => $total . ' blobs found', 'progress' => null],
                ['name' => 'Import files', 'status' => 'error', 'message' => 'No valid GigHive media entries found in blob list'],
            ],
        ]);
        exit(1);
    }

    $bytesHuman = $bytesAdded >= 1073741824
        ? round($bytesAdded / 1073741824, 1) . ' GB'
        : round($bytesAdded / 1048576, 1) . ' MB';
    $errNote  = count($errors) > 0 ? ', ' . count($errors) . ' error(s) — see worker.log' : '';
    $finalMsg = $added . ' added, '
        . $alreadyExists . ' already on disk'
        . $errNote
        . ' (' . $bytesHuman . ' added)';

    writeJobStatus($jsonPath, [
        'success'        => true,
        'job_id'         => $jobId,
        'state'          => 'done',
        'processed'      => $processed,
        'total'          => $total,
        'added'          => $added,
        'already_exists' => $alreadyExists,
        'bytes_added'    => $bytesAdded,
        'completed_at'   => date('c'),
        'errors'         => $errors,
        'steps'          => [
            ['name' => 'List blobs', 'status' => 'ok', 'message' => $total . ' blobs found', 'progress' => null],
            ['name' => 'Import files', 'status' => 'ok',
             'message'  => $finalMsg,
             'progress' => ['processed' => $total, 'total' => $total]],
        ],
    ]);

} catch (Throwable $e) {
    writeJobStatus($jsonPath, [
        'success'       => true,
        'job_id'        => $jobId,
        'state'         => 'error',
        'error_message' => $e->getMessage(),
        'steps'         => [
            ['name' => 'List blobs',   'status' => 'ok',    'message' => '', 'progress' => null],
            ['name' => 'Import files', 'status' => 'error', 'message' => $e->getMessage()],
        ],
    ]);
    exit(1);
}

/**
 * Download a single blob from Azure Blob Storage to a local file atomically.
 * NEVER log or echo $sas — it is a secret.
 * Writes to a .tmp file then renames to the final destination.
 * @throws RuntimeException on network error or non-200 HTTP response.
 */
function downloadBlobToFile(
    string $blobPath,
    string $localDest,
    string $account,
    string $container,
    string $sas
): void {
    $encodedPath = implode('/', array_map('rawurlencode', explode('/', $blobPath)));
    $url         = 'https://' . $account . '.blob.core.windows.net/' . $container . '/' . $encodedPath . '?' . $sas;

    $tmpPath = $localDest . '.tmp';
    $fh = fopen($tmpPath, 'wb');
    if ($fh === false) {
        throw new RuntimeException('Cannot open tmp file for writing: ' . basename($localDest));
    }

    $ch = curl_init();
    try {
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_FILE           => $fh,
            CURLOPT_TIMEOUT        => 0,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER     => ['x-ms-version: 2020-04-08'],
        ]);
        curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    } finally {
        curl_close($ch);
        fclose($fh);
    }

    if ($httpCode !== 200) {
        @unlink($tmpPath);
        throw new RuntimeException(
            'Azure GET failed for ' . basename($blobPath) . ': HTTP ' . $httpCode
        );
    }

    if (!rename($tmpPath, $localDest)) {
        @unlink($tmpPath);
        throw new RuntimeException('rename() failed for ' . basename($localDest));
    }
}
