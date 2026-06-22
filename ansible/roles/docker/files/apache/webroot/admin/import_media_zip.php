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

// Load supported extensions from env (same pattern as catalog_scan_start.php)
$jsonEnvArray = static function (string $key): array {
    $raw = getenv($key);
    if (!is_string($raw) || trim($raw) === '') return [];
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) return [];
    $out = [];
    foreach ($decoded as $x) {
        if (is_string($x) && trim($x) !== '') $out[] = strtolower(trim($x));
    }
    return array_values(array_unique($out));
};
$audioExts    = $jsonEnvArray('UPLOAD_AUDIO_EXTS_JSON') ?: ['mp3', 'wav', 'flac', 'aac', 'ogg', 'm4a'];
$videoExts    = $jsonEnvArray('UPLOAD_VIDEO_EXTS_JSON') ?: ['mp4', 'mov', 'mkv', 'avi', 'webm', 'm4v'];
$audioExtsSet = array_flip($audioExts);
$videoExtsSet = array_flip($videoExts);

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

    $origName = (string)($_FILES['zip_file']['name'] ?? '');
    if (strtolower(pathinfo($origName, PATHINFO_EXTENSION)) !== 'zip') {
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'File must have a .zip extension']);
        exit;
    }

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

    $uploadMaxBytes   = $iniToBytes((string)ini_get('upload_max_filesize'));
    $uncompressedCap  = $uploadMaxBytes * 2;

    $audioCount       = 0;
    $videoCount       = 0;
    $unsupportedCount = 0;
    $totalBytes       = 0;  // audio+video only — for disk space estimation
    $uncompressedTotal = 0; // all entries — for zip bomb check

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
        $hash = pathinfo($name, PATHINFO_FILENAME);

        // Valid GigHive entry: {sha256}.{ext} at root level (no path separator)
        if (strpos($name, '/') === false
            && preg_match('/^[a-f0-9]{64}$/', $hash)
            && (isset($audioExtsSet[$ext]) || isset($videoExtsSet[$ext]))
        ) {
            if (isset($audioExtsSet[$ext])) {
                $audioCount++;
            } else {
                $videoCount++;
            }
            $totalBytes += $entrySize;
        } else {
            $unsupportedCount++;
        }
    }

    $zip->close();

    $prepareToken = bin2hex(random_bytes(8));
    $prepPath     = sys_get_temp_dir() . '/gighive_zip_prepare_' . $prepareToken . '.zip';
    if (!move_uploaded_file((string)$_FILES['zip_file']['tmp_name'], $prepPath)) {
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Failed to save uploaded ZIP']);
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

    $prepPath = sys_get_temp_dir() . '/gighive_zip_prepare_' . basename($prepareToken) . '.zip';
    if (!is_file($prepPath) || filemtime($prepPath) < time() - 1800) {
        http_response_code(410);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Prepare token expired or not found — please re-upload the ZIP']);
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

    if (!rename($prepPath, $jobDir . 'upload.zip')) {
        @rmdir($jobDir);
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Failed to move ZIP into job directory']);
        exit;
    }

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
