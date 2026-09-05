<?php
declare(strict_types=1);

$user = $_SERVER['PHP_AUTH_USER']
    ?? $_SERVER['REMOTE_USER']
    ?? $_SERVER['REDIRECT_REMOTE_USER']
    ?? null;

if ($user !== 'admin') {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Admin access required']);
    exit;
}

// Preflight space check — lightweight GET before upload starts
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['mode'] ?? '') === 'preflight') {
    header('Content-Type: application/json');
    $fileSize = (int)($_GET['size'] ?? 0);
    if ($fileSize <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid size parameter']);
        exit;
    }
    // /tmp needs ~2× fileSize: one as the PHP upload temp file, one for the worker extraction dir
    $tmpAvail = disk_free_space(sys_get_temp_dir());
    $required = $fileSize * 2;
    if ($tmpAvail === false || $tmpAvail < $required) {
        $avail      = $tmpAvail !== false ? round($tmpAvail / 1073741824, 1) : 0;
        $req        = round($required / 1073741824, 1);
        $archiveGb  = round($fileSize / 1073741824, 1);
        http_response_code(507);
        echo json_encode(['success' => false,
            'error' => 'Insufficient server temp space: ' . $avail . ' GB available, '
                     . $req . ' GB required (copy + untar, 2× ' . $archiveGb . ' GB archive). '
                     . 'Free up /tmp or use rsync.']);
        exit;
    }
    // Media destination needs ~1× fileSize for the final imported files
    $destAvail = disk_free_space('/var/www/html');
    if ($destAvail === false || $destAvail < $fileSize) {
        $avail = $destAvail !== false ? round($destAvail / 1073741824, 1) : 0;
        $req   = round($fileSize / 1073741824, 1);
        http_response_code(507);
        echo json_encode(['success' => false,
            'error' => 'Insufficient media destination space: ' . $avail . ' GB available, '
                     . $req . ' GB required.']);
        exit;
    }
    http_response_code(200);
    echo json_encode(['success' => true]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

// Helper: convert PHP ini size string (e.g. "512M") to bytes
$iniToBytes = static function (string $val): int {
    $val  = trim($val);
    $unit = strtolower($val[strlen($val) - 1] ?? '');
    $num  = (int)$val;
    return match ($unit) {
        'g'     => $num * 1073741824,
        'm'     => $num * 1048576,
        'k'     => $num * 1024,
        default => $num,
    };
};

// Detect post_max_size / upload_max_filesize exceeded (PHP empties $_POST and $_FILES silently)
$contentLength = isset($_SERVER['CONTENT_LENGTH']) ? (int)$_SERVER['CONTENT_LENGTH'] : 0;
$postMaxBytes  = $iniToBytes((string)ini_get('post_max_size'));
if ($contentLength > 0 && $postMaxBytes > 0 && $contentLength > $postMaxBytes) {
    http_response_code(413);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'error'   => 'Upload too large for PHP limits — post_max_size=' . ini_get('post_max_size')
                   . ', upload_max_filesize=' . ini_get('upload_max_filesize')
                   . ', Content-Length=' . $contentLength . ' bytes'
                   . '. Rebuild the container to apply correct limits.',
    ]);
    exit;
}

$mode   = isset($_POST['mode'])   ? trim((string)$_POST['mode'])   : '';
$source = isset($_POST['source']) ? trim((string)$_POST['source']) : 'local';

require_once __DIR__ . '/admin_media_lib.php';
$exts         = loadMediaExtensions();
$audioExtsSet = $exts['audioSet'];
$videoExtsSet = $exts['videoSet'];

// ─────────────────────────────────────────────────────────────────────────────
// mode=prepare — inspect ZIP, no writes to audio/video dirs
// ─────────────────────────────────────────────────────────────────────────────
if ($mode === 'prepare') {
    if ($source === 'azure') {
        $azAccount   = (string)getenv('AZURE_BLOB_ACCOUNT_NAME');
        $azContainer = (string)getenv('AZURE_BLOB_CONTAINER');
        $azSas       = (string)getenv('AZURE_BLOB_SAS_TOKEN');
        if ($azAccount === '' || $azContainer === '' || $azSas === '') {
            http_response_code(400);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Azure credentials not configured']);
            exit;
        }
        $prefix = isset($_POST['prefix']) ? trim((string)$_POST['prefix']) : '';
        if ($prefix === '') {
            http_response_code(400);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Blob prefix is required']);
            exit;
        }
        $prefix = ltrim(rtrim($prefix, '/'), '/') . '/';

        set_time_limit(0);

        try {
            $allBlobs = listAzureBlobs($azAccount, $azContainer, $azSas, $prefix);
        } catch (RuntimeException $e) {
            http_response_code(502);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Azure list failed: ' . $e->getMessage()]);
            exit;
        }

        if ($allBlobs === null) {
            http_response_code(502);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Failed to list blobs from Azure (invalid XML response)']);
            exit;
        }

        $audioCount       = 0;
        $videoCount       = 0;
        $unsupportedCount = 0;
        $totalBytes       = 0;
        $rows             = [];

        foreach ($allBlobs as $blob) {
            $blobName = (string)$blob['blob_name'];
            $size     = (int)$blob['size'];
            if (!str_starts_with($blobName, $prefix)) { $unsupportedCount++; continue; }
            $relative = substr($blobName, strlen($prefix));
            $basename = basename($relative);

            if (str_starts_with($relative, 'video/thumbnails/') && isValidThumbnailEntry('thumbnails/' . $basename)) {
                $totalBytes += $size;
                $rows[]      = $blob;
            } elseif (str_starts_with($relative, 'audio/') && isValidMediaEntry($basename, $audioExtsSet, $videoExtsSet)) {
                $audioCount++;
                $totalBytes += $size;
                $rows[]      = $blob;
            } elseif (str_starts_with($relative, 'video/') && isValidMediaEntry($basename, $audioExtsSet, $videoExtsSet)) {
                $videoCount++;
                $totalBytes += $size;
                $rows[]      = $blob;
            } else {
                $unsupportedCount++;
            }
        }

        if ($audioCount + $videoCount === 0) {
            http_response_code(400);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'No importable blobs found under prefix — check prefix or SAS Read+List permissions']);
            exit;
        }

        $token   = bin2hex(random_bytes(8));
        $tmpPath = sys_get_temp_dir() . '/gighive_azure_import_prepare_' . $token . '.json';
        if (file_put_contents($tmpPath, json_encode($rows, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), LOCK_EX) === false) {
            http_response_code(500);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Failed to store blob list']);
            exit;
        }

        header('Content-Type: application/json');
        echo json_encode([
            'success'           => true,
            'prepare_token'     => $token,
            'audio_count'       => $audioCount,
            'video_count'       => $videoCount,
            'unsupported_count' => $unsupportedCount,
            'total_bytes'       => $totalBytes,
        ]);
        exit;
    }

    if (!isset($_FILES['zip_file'])) {
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'No file uploaded']);
        exit;
    }

    if ($_FILES['zip_file']['error'] !== UPLOAD_ERR_OK) {
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Upload error code: ' . $_FILES['zip_file']['error']]);
        exit;
    }

    $origName  = (string)($_FILES['zip_file']['name'] ?? '');
    $lowerName = strtolower($origName);
    $isTarGz   = str_ends_with($lowerName, '.tar.gz') || str_ends_with($lowerName, '.tgz');
    $isZip     = str_ends_with($lowerName, '.zip');
    if (!$isZip && !$isTarGz) {
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'File must have a .zip, .tar.gz, or .tgz extension']);
        exit;
    }

    $audioCount       = 0;
    $videoCount       = 0;
    $unsupportedCount = 0;
    $totalBytes       = 0;

    if ($isZip) {
        $zip = new ZipArchive();
        $rc  = $zip->open((string)$_FILES['zip_file']['tmp_name'], ZipArchive::RDONLY);
        if ($rc !== true) {
            http_response_code(400);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Invalid or corrupt ZIP file (code ' . $rc . ')']);
            exit;
        }

        if ($zip->numFiles > 50000) {
            $zip->close();
            http_response_code(400);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'ZIP contains too many entries (limit: 50,000)']);
            exit;
        }

        $uploadMaxBytes  = $iniToBytes((string)ini_get('upload_max_filesize'));
        $uncompressedCap = $uploadMaxBytes * 2;
        $uncompressedTotal = 0;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if ($stat === false) { $unsupportedCount++; continue; }

            $entrySize          = (int)($stat['size'] ?? 0);
            $uncompressedTotal += $entrySize;

            if ($uncompressedTotal > $uncompressedCap) {
                $zip->close();
                http_response_code(400);
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'error' => 'Uncompressed ZIP content exceeds safety limit (2× upload_max_filesize)']);
                exit;
            }

            $name = (string)($stat['name'] ?? '');
            $ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION));

            if (isValidMediaEntry($name, $audioExtsSet, $videoExtsSet)) {
                $audioCount += (int)isset($audioExtsSet[$ext]);
                $videoCount += (int)isset($videoExtsSet[$ext]);
                $totalBytes += $entrySize;
            } else {
                $unsupportedCount++;
            }
        }
        $zip->close();
    } else {
        // tar.gz: async scan via /usr/bin/pv — spawn background scan worker, return scan_job_id immediately
        if (!function_exists('exec')) {
            http_response_code(500);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'exec() is disabled; scan worker cannot be spawned']);
            exit;
        }

        $prepareToken = bin2hex(random_bytes(8));
        $prepPath     = sys_get_temp_dir() . '/gighive_zip_prepare_' . $prepareToken . '.tar.gz';
        if (!move_uploaded_file((string)$_FILES['zip_file']['tmp_name'], $prepPath)) {
            http_response_code(500);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Failed to save uploaded archive']);
            exit;
        }

        $scanJobId  = bin2hex(random_bytes(8));
        $scanJobDir = sys_get_temp_dir() . '/gighive_scan_' . $scanJobId . '/';
        if (!mkdir($scanJobDir, 0700, true)) {
            @unlink($prepPath);
            http_response_code(500);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Failed to create scan job directory']);
            exit;
        }

        file_put_contents($scanJobDir . 'status.json', json_encode([
            'success'           => true,
            'scan_job_id'       => $scanJobId,
            'state'             => 'running',
            'updated_at'        => date('c'),
            'scan_pct'          => 0,
            'audio_count'       => 0,
            'video_count'       => 0,
            'unsupported_count' => 0,
            'total_bytes'       => 0,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n", LOCK_EX);

        exec('php ' . escapeshellarg(__DIR__ . '/import_media_zip_scan_worker.php')
            . ' --scan_job_id=' . escapeshellarg($scanJobId)
            . ' --prepare_token=' . escapeshellarg($prepareToken)
            . ' >> ' . escapeshellarg($scanJobDir . 'worker.log') . ' 2>&1 &');

        header('Content-Type: application/json');
        echo json_encode([
            'success'       => true,
            'prepare_token' => $prepareToken,
            'scan_job_id'   => $scanJobId,
        ]);
        exit;
    }

    // ── ZIP path: save file and return counts ─────────────────────────────────
    $prepareToken = bin2hex(random_bytes(8));
    $prepPath     = sys_get_temp_dir() . '/gighive_zip_prepare_' . $prepareToken . '.zip';
    if (!move_uploaded_file((string)$_FILES['zip_file']['tmp_name'], $prepPath)) {
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Failed to save uploaded archive']);
        exit;
    }

    header('Content-Type: application/json');
    echo json_encode([
        'success'           => true,
        'prepare_token'     => $prepareToken,
        'audio_count'       => $audioCount,
        'video_count'       => $videoCount,
        'unsupported_count' => $unsupportedCount,
        'file_count'        => $audioCount + $videoCount + $unsupportedCount,
        'total_bytes'       => $totalBytes,
    ]);
    exit;
}

// ─────────────────────────────────────────────────────────────────────────────
// mode=start — move prep file into job dir, spawn worker
// ─────────────────────────────────────────────────────────────────────────────
if ($mode === 'start') {
    if ($source === 'azure') {
        $prepareToken = isset($_POST['prepare_token']) ? trim((string)$_POST['prepare_token']) : '';
        if ($prepareToken === '' || preg_match('/^[a-f0-9]{16}$/', $prepareToken) !== 1) {
            http_response_code(400);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Missing or invalid prepare_token']);
            exit;
        }

        $prefix = isset($_POST['prefix']) ? trim((string)$_POST['prefix']) : '';
        $prefix = ltrim(rtrim($prefix, '/'), '/') . '/';

        $bloblistTmpPath = sys_get_temp_dir() . '/gighive_azure_import_prepare_' . basename($prepareToken) . '.json';
        if (!is_file($bloblistTmpPath) || filemtime($bloblistTmpPath) < time() - 1800) {
            http_response_code(410);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Prepare token expired or not found — re-run listing and confirm again']);
            exit;
        }

        if (!function_exists('exec')) {
            http_response_code(500);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'exec() is disabled; background worker cannot be spawned']);
            exit;
        }

        $rows = json_decode((string)file_get_contents($bloblistTmpPath), true);
        if (!is_array($rows)) {
            http_response_code(500);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Failed to read blob list from prepare step']);
            exit;
        }

        $jobId  = bin2hex(random_bytes(8));
        $jobDir = sys_get_temp_dir() . '/gighive_import_' . $jobId . '/';

        if (!mkdir($jobDir, 0700, true)) {
            http_response_code(500);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Failed to create job directory']);
            exit;
        }

        if (file_put_contents($jobDir . 'source.txt', 'azure') === false
            || file_put_contents($jobDir . 'prefix.txt', $prefix) === false) {
            @array_map('unlink', glob($jobDir . '*') ?: []);
            @rmdir($jobDir);
            http_response_code(500);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Failed to write job metadata']);
            exit;
        }

        if (!copy($bloblistTmpPath, $jobDir . 'bloblist.json')) {
            @array_map('unlink', glob($jobDir . '*') ?: []);
            @rmdir($jobDir);
            http_response_code(500);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Failed to copy blob list into job directory']);
            exit;
        }
        @unlink($bloblistTmpPath);

        $rowCount      = count($rows);
        $initialStatus = json_encode([
            'success'        => true,
            'job_id'         => $jobId,
            'state'          => 'running',
            'updated_at'     => date('c'),
            'processed'      => 0,
            'total'          => $rowCount,
            'added'          => 0,
            'already_exists' => 0,
            'bytes_added'    => 0,
            'steps'          => [
                ['name' => 'List blobs',   'status' => 'ok',      'message' => $rowCount . ' blobs found', 'progress' => null],
                ['name' => 'Import files', 'status' => 'running', 'message' => 'Starting\u2026',
                 'progress' => ['processed' => 0, 'total' => $rowCount]],
            ],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if (file_put_contents($jobDir . 'status.json', $initialStatus . "\n", LOCK_EX) === false) {
            @array_map('unlink', glob($jobDir . '*') ?: []);
            @rmdir($jobDir);
            http_response_code(500);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Failed to write initial status']);
            exit;
        }

        exec('php ' . escapeshellarg(__DIR__ . '/import_media_zip_worker_azure.php')
            . ' --job_id=' . escapeshellarg($jobId)
            . ' >> ' . escapeshellarg($jobDir . 'worker.log') . ' 2>&1 &');

        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'job_id' => $jobId]);
        exit;
    }

    $prepareToken = isset($_POST['prepare_token']) ? trim((string)$_POST['prepare_token']) : '';
    if ($prepareToken === '' || preg_match('/^[a-f0-9]{16}$/', $prepareToken) !== 1) {
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Missing or invalid prepare_token']);
        exit;
    }

    // Try .tar.gz first, then .zip (token carries no format hint)
    $prepPathTarGz = sys_get_temp_dir() . '/gighive_zip_prepare_' . basename($prepareToken) . '.tar.gz';
    $prepPathZip   = sys_get_temp_dir() . '/gighive_zip_prepare_' . basename($prepareToken) . '.zip';
    if (is_file($prepPathTarGz) && filemtime($prepPathTarGz) >= time() - 1800) {
        $prepPath   = $prepPathTarGz;
        $uploadName = 'upload.tar.gz';
        $format     = 'tar.gz';
    } elseif (is_file($prepPathZip) && filemtime($prepPathZip) >= time() - 1800) {
        $prepPath   = $prepPathZip;
        $uploadName = 'upload.zip';
        $format     = 'zip';
    } else {
        http_response_code(410);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Prepare token expired or not found — please re-upload the archive']);
        exit;
    }

    if (!function_exists('exec')) {
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'exec() is disabled; background worker cannot be spawned']);
        exit;
    }

    $jobId  = bin2hex(random_bytes(8));
    $jobDir = sys_get_temp_dir() . '/gighive_import_' . $jobId . '/';

    if (!mkdir($jobDir, 0700, true)) {
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Failed to create job directory']);
        exit;
    }

    // Write format.txt so worker knows which branch to take
    if (file_put_contents($jobDir . 'format.txt', $format) === false) {
        @rmdir($jobDir);
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Failed to write format.txt']);
        exit;
    }

    // Cross-device safe: copy then unlink (rename() fails across filesystem boundaries)
    if (!copy($prepPath, $jobDir . $uploadName)) {
        @unlink($jobDir . 'format.txt');
        @rmdir($jobDir);
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Failed to move archive into job directory']);
        exit;
    }
    @unlink($prepPath);

    $initialStatus = json_encode([
        'success'       => true,
        'job_id'        => $jobId,
        'state'         => 'running',
        'updated_at'    => date('c'),
        'processed'     => 0,
        'total'         => 0,
        'added'         => 0,
        'already_exists' => 0,
        'bytes_added'   => 0,
        'steps'         => [
            [
                'name'     => 'Import files',
                'status'   => 'running',
                'message'  => 'Scanning archive\u2026',
                'progress' => ['processed' => 0, 'total' => 1],
            ],
        ],
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    if (file_put_contents($jobDir . 'status.json', $initialStatus . "\n", LOCK_EX) === false) {
        @unlink($jobDir . 'upload.zip');
        @rmdir($jobDir);
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Failed to write initial status']);
        exit;
    }

    exec('php ' . escapeshellarg(__DIR__ . '/import_media_zip_worker.php')
        . ' --job_id=' . escapeshellarg($jobId)
        . ' >> ' . escapeshellarg($jobDir . 'worker.log') . ' 2>&1 &');

    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'job_id' => $jobId]);
    exit;
}

// Invalid mode
http_response_code(400);
header('Content-Type: application/json');
echo json_encode(['success' => false, 'error' => 'Invalid mode; expected "prepare" or "start"']);
exit;

/**
 * List all blobs under $prefix from Azure Blob Storage (paginated).
 * NEVER log or echo $sas — it is a secret.
 * Returns null on XML parse failure; array of {blob_name, size} rows on success.
 * @throws RuntimeException on HTTP error from Azure.
 */
function listAzureBlobs(string $account, string $container, string $sas, string $prefix): ?array
{
    $results = [];
    $marker  = '';
    do {
        $params = ['restype' => 'container', 'comp' => 'list', 'prefix' => $prefix];
        if ($marker !== '') $params['marker'] = $marker;
        $url = 'https://' . $account . '.blob.core.windows.net/' . $container . '?' . http_build_query($params) . '&' . $sas;

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER     => ['x-ms-version: 2020-04-08'],
        ]);
        $body     = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || !is_string($body)) {
            throw new RuntimeException('Azure List failed: HTTP ' . $httpCode);
        }

        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($body);
        libxml_clear_errors();
        if ($xml === false) {
            return null;
        }

        foreach ($xml->Blobs->Blob ?? [] as $blob) {
            $results[] = [
                'blob_name' => (string)$blob->Name,
                'size'      => (int)(string)$blob->Properties->{'Content-Length'},
            ];
        }

        $marker = (string)($xml->NextMarker ?? '');
    } while ($marker !== '');

    return $results;
}
