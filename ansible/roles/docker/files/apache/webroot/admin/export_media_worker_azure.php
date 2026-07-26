<?php declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit(1);
}

$jobId    = '';
$orgLabel = 'all';
foreach ($argv as $arg) {
    if (str_starts_with((string)$arg, '--job_id=')) {
        $jobId = substr((string)$arg, strlen('--job_id='));
    }
    if (str_starts_with((string)$arg, '--org=')) {
        $raw      = substr((string)$arg, strlen('--org='));
        $orgLabel = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $raw) ?: 'all';
    }
}
if ($jobId === '' || !preg_match('/^[a-f0-9]{16}$/', $jobId)) {
    fwrite(STDERR, "export_media_worker_azure: invalid or missing --job_id\n");
    exit(1);
}

$jobDir       = sys_get_temp_dir() . '/gighive_export_' . $jobId . '/';
$filelistPath = $jobDir . 'filelist.json';
$jsonPath     = $jobDir . 'status.json';

if (!is_dir($jobDir)) {
    fwrite(STDERR, "export_media_worker_azure: job directory not found: $jobDir\n");
    exit(1);
}

require_once __DIR__ . '/admin_media_lib.php';

$audioDir = '/var/www/html/audio';
$videoDir = '/var/www/html/video';

try {
    set_time_limit(0);

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
                ['name' => 'Upload to Azure', 'status' => 'error',
                 'message' => 'Azure credentials missing from environment'],
            ],
        ]);
        exit(1);
    }

    if (!is_file($filelistPath)) {
        throw new RuntimeException('filelist.json not found');
    }
    $rows = json_decode((string)file_get_contents($filelistPath), true);
    @unlink($filelistPath);
    if (!is_array($rows)) {
        throw new RuntimeException('Invalid filelist.json content');
    }

    $rowCount     = count($rows);
    $batchPrefix  = date('Ymd-His') . '/' . $orgLabel;
    $fileUploaded = 0;
    $allUploaded  = 0;
    $skipped      = 0;

    foreach ($rows as $row) {
        $type = (string)($row['file_type']         ?? '');
        $sha  = trim((string)($row['checksum_sha256'] ?? ''));
        $ext  = strtolower(trim((string)($row['file_ext'] ?? '')));

        if ($sha === '' || preg_match('/^[a-f0-9]{64}$/i', $sha) !== 1) {
            $skipped++;
            continue;
        }
        $dir = match ($type) {
            'audio' => $audioDir,
            'video' => $videoDir,
            default => null,
        };
        if ($dir === null) {
            $skipped++;
            continue;
        }

        $baseFilename = $ext !== '' ? ($sha . '.' . $ext) : $sha;
        $filePath     = $dir . '/' . $baseFilename;

        if (!is_file($filePath)) {
            $skipped++;
            continue;
        }

        $blobPath = 'gighive-export/' . $batchPrefix . '/' . $type . '/' . $baseFilename;
        uploadBlobFromFile($filePath, $blobPath, $azAccount, $azContainer, $azSas);
        $fileUploaded++;
        $allUploaded++;

        if ($type === 'video') {
            $thumbPath     = $videoDir . '/thumbnails/' . $sha . '.png';
            $thumbFilename = $sha . '.png';
            if (is_file($thumbPath)) {
                $thumbBlobPath = 'gighive-export/' . $batchPrefix . '/video/thumbnails/' . $thumbFilename;
                uploadBlobFromFile($thumbPath, $thumbBlobPath, $azAccount, $azContainer, $azSas);
                $allUploaded++;
            }
        }

        writeJobStatus($jsonPath, [
            'success'     => true,
            'job_id'      => $jobId,
            'state'       => 'running',
            'updated_at'  => date('c'),
            'processed'   => $fileUploaded,
            'total'       => $rowCount,
            'added'       => $fileUploaded,
            'skipped'     => $skipped,
            'steps'       => [
                ['name' => 'Upload to Azure', 'status' => 'running',
                 'message'  => $fileUploaded . ' / ' . $rowCount . ' uploaded',
                 'progress' => ['processed' => $fileUploaded, 'total' => $rowCount]],
            ],
        ]);
    }

    if ($allUploaded === 0) {
        writeJobStatus($jsonPath, [
            'success'       => true,
            'job_id'        => $jobId,
            'state'         => 'error',
            'error_message' => 'No exportable files found on disk',
            'steps'         => [
                ['name' => 'Upload to Azure', 'status' => 'error',
                 'message' => 'No exportable files found on disk (skipped: ' . $skipped . ')'],
            ],
        ]);
        exit(1);
    }

    $blobPrefixKey = 'gighive-export/' . $batchPrefix . '/';
    writeJobStatus($jsonPath, [
        'success'      => true,
        'job_id'       => $jobId,
        'state'        => 'done',
        'processed'    => $fileUploaded,
        'total'        => $rowCount,
        'added'        => $allUploaded,
        'skipped'      => $skipped,
        'blob_prefix'  => $blobPrefixKey,
        'completed_at' => date('c'),
        'steps'        => [
            ['name' => 'Upload to Azure', 'status' => 'ok',
             'message'  => $allUploaded . ' file(s) uploaded (' . $skipped . ' skipped)',
             'progress' => ['processed' => $fileUploaded, 'total' => $rowCount]],
        ],
    ]);

} catch (Throwable $e) {
    writeJobStatus($jsonPath, [
        'success'       => true,
        'job_id'        => $jobId,
        'state'         => 'error',
        'error_message' => $e->getMessage(),
        'steps'         => [
            ['name' => 'Upload to Azure', 'status' => 'error',
             'message' => 'Worker error: ' . $e->getMessage()],
        ],
    ]);
    exit(1);
}

/**
 * Upload a local file to Azure Blob Storage via HTTP PUT (Blob REST API, SAS auth).
 * NEVER log or echo $url — it contains the SAS token.
 *
 * @throws RuntimeException on non-201 HTTP response or curl error
 */
function uploadBlobFromFile(
    string $localPath,
    string $blobPath,
    string $account,
    string $container,
    string $sas
): void {
    $encodedPath = implode('/', array_map('rawurlencode', explode('/', $blobPath)));
    $url         = 'https://' . $account . '.blob.core.windows.net/' . $container . '/' . $encodedPath . '?' . $sas;

    $fh = fopen($localPath, 'rb');
    if ($fh === false) {
        throw new RuntimeException('Cannot open file: ' . basename($localPath));
    }

    $ch = curl_init();
    try {
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_PUT            => true,
            CURLOPT_INFILE         => $fh,
            CURLOPT_INFILESIZE     => (int)filesize($localPath),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'x-ms-blob-type: BlockBlob',
                'x-ms-version: 2020-04-08',
                'Content-Type: application/octet-stream',
            ],
            CURLOPT_TIMEOUT        => 0,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        $body     = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    } finally {
        curl_close($ch);
        fclose($fh);
    }

    if ($httpCode !== 201) {
        $excerpt = substr((string)$body, 0, 500);
        throw new RuntimeException(
            'Azure PUT failed for ' . basename($localPath) . ': HTTP ' . $httpCode . ' — ' . $excerpt
        );
    }
}
