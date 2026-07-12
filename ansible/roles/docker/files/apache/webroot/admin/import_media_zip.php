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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$mode = isset($_POST['mode']) ? trim((string)$_POST['mode']) : '';

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

require_once __DIR__ . '/admin_media_lib.php';
$exts         = loadMediaExtensions();
$audioExtsSet = $exts['audioSet'];
$videoExtsSet = $exts['videoSet'];

// ─────────────────────────────────────────────────────────────────────────────
// mode=prepare — inspect ZIP, no writes to audio/video dirs
// ─────────────────────────────────────────────────────────────────────────────
if ($mode === 'prepare') {
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
        // tar.gz: list entries via runTar -tzvf
        if (!function_exists('proc_open')) {
            http_response_code(500);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'proc_open() is disabled; tar.gz inspection cannot run']);
            exit;
        }
        $result = runTar(['tar', '-tzvf', (string)$_FILES['zip_file']['tmp_name']]);
        if ($result['exit_code'] !== 0) {
            http_response_code(400);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Invalid or corrupt tar.gz archive']);
            exit;
        }
        $lineCount = 0;
        foreach (explode("\n", trim($result['stdout'])) as $line) {
            $line = trim($line);
            if ($line === '') continue;
            $lineCount++;
            if ($lineCount > 50000) {
                http_response_code(400);
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'error' => 'Archive contains too many entries (limit: 50,000)']);
                exit;
            }
            // verbose line: <perms> <user>/<group> <size> <date> <time> <name>
            $parts = preg_split('/\s+/', $line, 6);
            $name  = isset($parts[5]) ? trim($parts[5]) : '';
            $size  = isset($parts[2]) ? (int)$parts[2] : 0;
            $ext   = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if (isValidMediaEntry($name, $audioExtsSet, $videoExtsSet)) {
                $audioCount += (int)isset($audioExtsSet[$ext]);
                $videoCount += (int)isset($videoExtsSet[$ext]);
                $totalBytes += $size;
            } else {
                $unsupportedCount++;
            }
        }
    }

    $prepareToken = bin2hex(random_bytes(8));
    $ext          = $isTarGz ? '.tar.gz' : '.zip';
    $prepPath     = sys_get_temp_dir() . '/gighive_zip_prepare_' . $prepareToken . $ext;
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
