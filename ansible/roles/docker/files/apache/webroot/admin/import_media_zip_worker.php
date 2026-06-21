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
    fwrite(STDERR, "import_media_zip_worker: invalid or missing --job_id\n");
    exit(1);
}

$jobDir   = sys_get_temp_dir() . '/gighive_import_' . $jobId . '/';
$jsonPath = $jobDir . 'status.json';
$zipPath  = $jobDir . 'upload.zip';

if (!is_dir($jobDir)) {
    fwrite(STDERR, "import_media_zip_worker: job directory not found: $jobDir\n");
    exit(1);
}

$writeStatus = function (array $payload) use ($jsonPath): void {
    $payload['updated_at'] = date('c');
    @file_put_contents($jsonPath, json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n", LOCK_EX);
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

try {
    set_time_limit(0);

    if (!is_file($zipPath) || !is_readable($zipPath)) {
        $writeStatus([
            'success'       => true,
            'job_id'        => $jobId,
            'state'         => 'error',
            'error_message' => 'ZIP file not found',
            'steps'         => [
                ['name' => 'Import files', 'status' => 'error', 'message' => 'ZIP file not found'],
            ],
        ]);
        exit(1);
    }

    $zip = new ZipArchive();
    $rc  = $zip->open($zipPath, ZipArchive::RDONLY);
    if ($rc !== true) {
        $writeStatus([
            'success'       => true,
            'job_id'        => $jobId,
            'state'         => 'error',
            'error_message' => "ZipArchive::open failed (code $rc)",
            'steps'         => [
                ['name' => 'Import files', 'status' => 'error', 'message' => "ZipArchive::open failed (code $rc)"],
            ],
        ]);
        exit(1);
    }

    // Initialise all counters
    $processed        = 0;
    $added            = 0;
    $alreadyExists    = 0;
    $bytesAdded       = 0;
    $unsupportedCount = 0;
    $errors           = [];
    $total            = 0;
    $uncompressedTotal = 0;

    // Pre-scan: count valid media entries and total uncompressed bytes
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $stat = $zip->statIndex($i);
        if ($stat === false) continue;
        $uncompressedTotal += (int)($stat['size'] ?? 0);
        $name = (string)($stat['name'] ?? '');
        $ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $hash = pathinfo($name, PATHINFO_FILENAME);
        if (strpos($name, '/') === false
            && preg_match('/^[a-f0-9]{64}$/', $hash)
            && (isset($audioExtsSet[$ext]) || isset($videoExtsSet[$ext]))
        ) {
            $total++;
        }
    }

    // Write status.json with real $total denominator
    $writeStatus([
        'success'        => true,
        'job_id'         => $jobId,
        'state'          => 'running',
        'processed'      => 0,
        'total'          => $total,
        'added'          => 0,
        'already_exists' => 0,
        'bytes_added'    => 0,
        'steps'          => [
            ['name' => 'Import files', 'status' => 'running',
             'message'  => '0 / ' . $total . ' files imported',
             'progress' => ['processed' => 0, 'total' => $total]],
        ],
    ]);

    // Disk space check against destination volume
    $freeBytes = disk_free_space('/var/www/html');
    if ($freeBytes !== false && $freeBytes < $uncompressedTotal * 1.1) {
        $zip->close();
        $writeStatus([
            'success'       => true,
            'job_id'        => $jobId,
            'state'         => 'error',
            'error_message' => 'Insufficient disk space on destination volume',
            'steps'         => [
                ['name' => 'Import files', 'status' => 'error', 'message' => 'Insufficient disk space on destination volume'],
            ],
        ]);
        exit(1);
    }

    // Destination directory check
    if (!is_dir('/var/www/html/audio') || !is_dir('/var/www/html/video')) {
        $zip->close();
        throw new RuntimeException('Destination directory /var/www/html/audio or /var/www/html/video does not exist — volume may not be mounted');
    }

    // Main iteration: stream each valid entry to its destination
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $stat = $zip->statIndex($i);
        if ($stat === false) { $unsupportedCount++; continue; }

        $name = (string)($stat['name'] ?? '');
        $ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $hash = pathinfo($name, PATHINFO_FILENAME);

        // Entry validation — skip non-GigHive entries
        if (strpos($name, '/') !== false
            || !preg_match('/^[a-f0-9]{64}$/', $hash)
            || (!isset($audioExtsSet[$ext]) && !isset($videoExtsSet[$ext]))
        ) {
            $unsupportedCount++;
            continue;
        }

        $processed++;
        $type = isset($audioExtsSet[$ext]) ? 'audio' : 'video';
        $dest = '/var/www/html/' . $type . '/' . $hash . '.' . $ext;

        // Idempotent skip — file already on disk
        if (is_file($dest)) {
            $alreadyExists++;
        } else {
            // Stream entry directly to destination
            $stream = $zip->getStream($name);
            if ($stream === false) {
                $errors[] = 'getStream failed: ' . $name;
                continue;
            }

            $fp = fopen($dest, 'wb');
            if ($fp === false) {
                fclose($stream);
                $errors[] = 'fopen failed: ' . $dest;
                continue;
            }

            $copied = stream_copy_to_stream($stream, $fp);
            if ($copied === false) {
                fclose($fp);
                fclose($stream);
                @unlink($dest);
                $errors[] = 'stream_copy failed: ' . $name;
                continue;
            }

            if (!fclose($fp)) {
                fclose($stream);
                @unlink($dest);
                $errors[] = 'fclose failed (disk full?): ' . $name;
                continue;
            }

            fclose($stream);
            $added++;
            $bytesAdded += (int)($stat['size'] ?? 0);
        }

        if ($processed % 10 === 0) {
            $writeStatus([
                'success'        => true,
                'job_id'         => $jobId,
                'state'          => 'running',
                'processed'      => $processed,
                'total'          => $total,
                'added'          => $added,
                'already_exists' => $alreadyExists,
                'bytes_added'    => $bytesAdded,
                'steps'          => [
                    ['name' => 'Import files', 'status' => 'running',
                     'message'  => $processed . ' / ' . $total . ' files imported',
                     'progress' => ['processed' => $processed, 'total' => $total]],
                ],
            ]);
        }
    }

    $zip->close();
    @unlink($zipPath);

    if ($added === 0 && $alreadyExists === 0) {
        $writeStatus([
            'success'       => true,
            'job_id'        => $jobId,
            'state'         => 'error',
            'error_message' => 'No valid GigHive media entries found in ZIP',
            'steps'         => [
                ['name' => 'Import files', 'status' => 'error',
                 'message' => 'No valid GigHive media entries found in ZIP'],
            ],
        ]);
        exit(1);
    }

    // Build final summary message
    $bytesHuman = $bytesAdded >= 1073741824
        ? round($bytesAdded / 1073741824, 1) . ' GB'
        : round($bytesAdded / 1048576, 1) . ' MB';
    $errNote = count($errors) > 0
        ? ', ' . count($errors) . ' stream error(s) — see worker.log'
        : '';
    $finalMsg = $added . ' added, '
        . $alreadyExists . ' already on disk, '
        . $unsupportedCount . ' skipped (unsupported)'
        . $errNote . ' (' . $bytesHuman . ' added)';

    $writeStatus([
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
            ['name' => 'Import files', 'status' => 'ok',
             'message'  => $finalMsg,
             'progress' => ['processed' => $total, 'total' => $total]],
        ],
    ]);

} catch (Throwable $e) {
    $writeStatus([
        'success'       => true,
        'job_id'        => $jobId,
        'state'         => 'error',
        'error_message' => $e->getMessage(),
        'steps'         => [
            ['name' => 'Import files', 'status' => 'error',
             'message' => $e->getMessage()],
        ],
    ]);
    exit(1);
}
