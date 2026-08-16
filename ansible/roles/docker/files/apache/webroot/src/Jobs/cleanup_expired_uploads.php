#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Expired tus upload cleanup — invoked by cron daily.
 *
 * Removes:
 *  1. tus_uploads rows with expires_at < NOW() and status != 'complete'
 *     + corresponding local staging files (if any; no-op in Azure mode)
 *  2. Permanently-failed probe_jobs rows older than 30 days
 *
 * Safe to run in Azure mode: staging file deletion is a no-op when the
 * staging directory is empty.
 *
 * LIMIT guards prevent long-running deletes from blocking PATCH transactions.
 *
 * Cron entry (gighive-probe.cron):
 *   0 3 * * * www-data php /var/www/html/src/Jobs/cleanup_expired_uploads.php >> /var/log/probe_job.log 2>&1
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use Production\Api\Infrastructure\Database;

$pdo        = Database::createFromEnv();
$stagingDir = getenv('TUS_LOCAL_STAGING_DIR') ?: '/tmp/tus-staging';

// Fetch expired, non-complete upload IDs (LIMIT prevents long lock windows)
$stmt = $pdo->query(
    "SELECT upload_id FROM tus_uploads
     WHERE expires_at < NOW() AND status != 'complete'
     LIMIT 500"
);
$rows = $stmt->fetchAll(PDO::FETCH_COLUMN);

$deleted  = 0;
$unlinked = 0;

if (!empty($rows)) {
    foreach ($rows as $uploadId) {
        $stagingPath = rtrim($stagingDir, '/') . '/' . $uploadId;
        if (is_file($stagingPath)) {
            @unlink($stagingPath);
            $unlinked++;
        }
    }

    $placeholders = implode(',', array_fill(0, count($rows), '?'));
    $del = $pdo->prepare("DELETE FROM tus_uploads WHERE upload_id IN ($placeholders)");
    $del->execute($rows);
    $deleted = $del->rowCount();
}

fwrite(STDOUT, sprintf(
    "[cleanup_expired_uploads] deleted=%d staging_files_removed=%d\n",
    $deleted,
    $unlinked
));

// Prune permanently-failed probe_jobs rows older than 30 days
$pdo->exec(
    "DELETE FROM probe_jobs
     WHERE status = 'failed' AND created_at < NOW() - INTERVAL 30 DAY
     LIMIT 200"
);

exit(0);
