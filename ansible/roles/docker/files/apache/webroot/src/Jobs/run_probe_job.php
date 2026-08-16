#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Probe job runner — invoked by cron every ~10 seconds.
 *
 * Processes one queued probe_jobs row per invocation:
 *  - Resets stuck jobs (running > 10 min) back to queued
 *  - Permanently fails jobs that hit the retry cap (attempts >= 3)
 *  - Claims one queued row and runs ffprobe + optional ffmpeg thumbnail
 *  - Updates assets row; marks probe_jobs row done or re-queues on failure
 *
 * Output to stdout is captured by cron: >> /var/log/probe_job.log 2>&1
 * Use fwrite(STDOUT, ...) for operational output.
 * Use error_log(...) for unexpected errors (goes to PHP engine log AND cron stderr).
 *
 * Cron schedule (gighive-probe.cron):
 *   * * * * * www-data php /var/www/html/src/Jobs/run_probe_job.php >> /var/log/probe_job.log 2>&1
 *   * * * * * www-data sleep 10 && php ...   (repeated every 10 seconds via sleep offsets)
 *   (etc.)
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use Production\Api\Infrastructure\Database;
use Production\Api\Services\MediaProbeJobService;
use Production\Api\Services\MediaStorageService;

$pdo     = Database::createFromEnv();
$storage = MediaStorageService::make();

$service = new MediaProbeJobService(
    pdo:        $pdo,
    storage:    $storage,
    ffprobeBin: getenv('FFPROBE_BIN') ?: '/usr/bin/ffprobe',
    ffmpegBin:  getenv('FFMPEG_BIN')  ?: '/usr/bin/ffmpeg',
    tempDir:    '/tmp',
);

$processed = $service->runOnce();

if (!$processed) {
    // No queued jobs — exit silently (avoid filling probe_job.log with no-op lines)
    exit(0);
}

exit(0);
