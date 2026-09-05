<?php declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit(1);
}

$scanJobId    = '';
$prepareToken = '';
foreach ($argv as $arg) {
    if (str_starts_with((string)$arg, '--scan_job_id=')) {
        $scanJobId = substr((string)$arg, strlen('--scan_job_id='));
    }
    if (str_starts_with((string)$arg, '--prepare_token=')) {
        $prepareToken = substr((string)$arg, strlen('--prepare_token='));
    }
}

if ($scanJobId === '' || !preg_match('/^[a-f0-9]{16}$/', $scanJobId)) {
    fwrite(STDERR, "import_media_zip_scan_worker: invalid or missing --scan_job_id\n");
    exit(1);
}
if ($prepareToken === '' || !preg_match('/^[a-f0-9]{16}$/', $prepareToken)) {
    fwrite(STDERR, "import_media_zip_scan_worker: invalid or missing --prepare_token\n");
    exit(1);
}

$scanJobDir  = sys_get_temp_dir() . '/gighive_scan_' . $scanJobId . '/';
$jsonPath    = $scanJobDir . 'status.json';
$pvFile      = $scanJobDir . 'pv_progress.txt';
$archivePath = sys_get_temp_dir() . '/gighive_zip_prepare_' . $prepareToken . '.tar.gz';

if (!is_dir($scanJobDir)) {
    fwrite(STDERR, "import_media_zip_scan_worker: scan job directory not found: $scanJobDir\n");
    exit(1);
}

require_once __DIR__ . '/admin_media_lib.php';
$exts         = loadMediaExtensions();
$audioExtsSet = $exts['audioSet'];
$videoExtsSet = $exts['videoSet'];

$writeStatus = function (array $payload) use ($jsonPath, $scanJobId): void {
    $payload['success']    = true;
    $payload['scan_job_id'] = $scanJobId;
    writeJobStatus($jsonPath, $payload);
};

try {
    set_time_limit(0);

    if (!is_file($archivePath) || !is_readable($archivePath)) {
        $writeStatus(['state' => 'error', 'error_message' => 'Archive not found or not readable: ' . basename($archivePath)]);
        exit(1);
    }

    $fileSize = (int)filesize($archivePath);
    if ($fileSize <= 0) {
        $writeStatus(['state' => 'error', 'error_message' => 'Archive has zero size or filesize() failed']);
        exit(1);
    }

    $audioCount       = 0;
    $videoCount       = 0;
    $unsupportedCount = 0;
    $totalBytes       = 0;
    $lineCount        = 0;

    $onStdoutLine = function (string $line) use (
        &$audioCount, &$videoCount, &$unsupportedCount, &$totalBytes, &$lineCount,
        $audioExtsSet, $videoExtsSet, $pvFile, $writeStatus, $scanJobId
    ): void {
        if ($line === '') return;
        $lineCount++;

        // verbose tar line: <perms> <user>/<group> <size> <date> <time> <name>
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

        // Write progress every 100 entries
        if ($lineCount % 100 === 0) {
            $pvLines = @file($pvFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            $scanPct = ($pvLines && count($pvLines)) ? min(100, max(0, (int)end($pvLines))) : 0;
            $writeStatus([
                'state'             => 'running',
                'scan_pct'          => $scanPct,
                'audio_count'       => $audioCount,
                'video_count'       => $videoCount,
                'unsupported_count' => $unsupportedCount,
                'total_bytes'       => $totalBytes,
            ]);
        }
    };

    runTarWithPv($archivePath, $fileSize, $pvFile, $onStdoutLine);

    // pv progress file no longer needed
    @unlink($pvFile);

    // Write final status — scan_pct hardcoded to 100 so the progress bar always completes
    $writeStatus([
        'state'             => 'done',
        'scan_pct'          => 100,
        'audio_count'       => $audioCount,
        'video_count'       => $videoCount,
        'unsupported_count' => $unsupportedCount,
        'total_bytes'       => $totalBytes,
    ]);

} catch (Throwable $e) {
    $writeStatus(['state' => 'error', 'error_message' => $e->getMessage()]);
    exit(1);
}
