<?php

declare(strict_types=1);

namespace Production\Api\Services;

use PDO;

/**
 * Processes one queued probe_jobs row per invocation.
 *
 * Called from src/Jobs/run_probe_job.php (cron, ~10s cadence).
 *
 * Responsibilities:
 *  1. Reset stuck jobs (running > 10 minutes) back to queued.
 *  2. Permanently fail jobs that have exceeded the retry cap (attempts >= 3).
 *  3. Claim one queued row via SELECT ... FOR UPDATE.
 *  4. Download the blob/open local file to a temp path.
 *  5. Run ffprobe to extract duration_seconds and media_info_json.
 *  6. For video: run ffmpeg to generate a thumbnail; store it via MediaStorageService.
 *  7. Update the assets row with probe results.
 *  8. Mark the probe_jobs row done (or failed on exception).
 *  9. Clean up temp files.
 *
 * Output: fwrite(STDOUT, ...) for operational lines (captured by cron redirect to probe_job.log).
 *         error_log(...) for unexpected exceptions (captured by PHP engine log and cron stderr).
 */
final class MediaProbeJobService
{
    public function __construct(
        private readonly PDO                 $pdo,
        private readonly MediaStorageService $storage,
        private readonly string              $ffprobeBin  = '/usr/bin/ffprobe',
        private readonly string              $ffmpegBin   = '/usr/bin/ffmpeg',
        private readonly string              $tempDir     = '/tmp',
    ) {}

    /**
     * Run one probe cycle.
     *
     * Returns true if a job was processed, false if no queued jobs exist.
     */
    public function runOnce(): bool
    {
        $this->resetStuckJobs();
        $this->failExhaustedJobs();

        $job = $this->claimNextJob();
        if ($job === null) {
            return false;
        }

        $jobId    = (int)$job['id'];
        $assetId  = (int)$job['asset_id'];
        $blobKey  = (string)$job['blob_key'];
        $fileType = (string)$job['file_type'];

        fwrite(STDOUT, sprintf(
            "[probe] job=%d asset=%d type=%s key=%s\n",
            $jobId, $assetId, $fileType, $blobKey
        ));

        $tempPath    = null;
        $thumbPath   = null;
        try {
            // Download / open local file
            $tempPath = $this->downloadToTemp($blobKey, $assetId);

            // ffprobe
            [$durationSeconds, $mediaInfoJson] = $this->runFfprobe($tempPath);

            // Thumbnail (video only)
            $thumbnailBlobKey = null;
            if ($fileType === 'video') {
                $thumbPath        = $this->generateThumbnail($tempPath, $assetId);
                $thumbnailBlobKey = $this->storeThumbnail($thumbPath, $blobKey);
            }

            // Update assets row
            $this->updateAsset($assetId, $durationSeconds, $mediaInfoJson, $thumbnailBlobKey);

            // Mark done
            $this->pdo->prepare(
                'UPDATE probe_jobs SET status = \'done\' WHERE id = ?'
            )->execute([$jobId]);

            fwrite(STDOUT, sprintf(
                "[probe] done job=%d asset=%d duration=%ss\n",
                $jobId, $assetId, $durationSeconds ?? 'null'
            ));
        } catch (\Throwable $e) {
            error_log(sprintf(
                '[MediaProbeJobService] job=%d asset=%d failed: %s',
                $jobId, $assetId, $e->getMessage()
            ));
            $this->pdo->prepare(
                'UPDATE probe_jobs SET status = \'queued\', attempts = attempts + 1
                 WHERE id = ? AND status = \'running\''
            )->execute([$jobId]);
            fwrite(STDOUT, sprintf("[probe] failed job=%d: %s\n", $jobId, $e->getMessage()));
        } finally {
            if ($tempPath !== null && is_file($tempPath)) {
                @unlink($tempPath);
            }
            if ($thumbPath !== null && is_file($thumbPath)) {
                @unlink($thumbPath);
            }
        }

        return true;
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /** Reset jobs stuck in 'running' for > 10 minutes back to 'queued'. */
    private function resetStuckJobs(): void
    {
        $this->pdo->prepare(
            "UPDATE probe_jobs SET status = 'queued', started_at = NULL
             WHERE status = 'running' AND started_at < NOW() - INTERVAL 10 MINUTE"
        )->execute();
    }

    /** Permanently fail jobs that have hit the retry cap. */
    private function failExhaustedJobs(): void
    {
        $this->pdo->prepare(
            "UPDATE probe_jobs SET status = 'failed'
             WHERE status = 'queued' AND attempts >= 3"
        )->execute();
    }

    /**
     * Claim one queued job row (SELECT ... FOR UPDATE + UPDATE status=running).
     *
     * @return array<string,mixed>|null
     */
    private function claimNextJob(): ?array
    {
        $this->pdo->beginTransaction();
        $stmt = $this->pdo->prepare(
            "SELECT id, asset_id, blob_key, file_type, attempts
             FROM probe_jobs
             WHERE status = 'queued'
             ORDER BY created_at ASC
             LIMIT 1
             FOR UPDATE"
        );
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            $this->pdo->rollBack();
            return null;
        }

        $this->pdo->prepare(
            "UPDATE probe_jobs
             SET status = 'running', started_at = NOW(), attempts = attempts + 1
             WHERE id = ?"
        )->execute([(int)$row['id']]);

        $this->pdo->commit();
        return $row;
    }

    /**
     * Stream the blob to a local temp file and return the temp path.
     *
     * blob_key format from probe_jobs is the full qualified key e.g. 'audio/abc.mp3'
     * or 'video/abc.mp4'. MediaStorageService::stream() expects ($type, $filename).
     */
    private function downloadToTemp(string $blobKey, int $assetId): string
    {
        // Split 'audio/abc.mp3' → type='audio', key='abc.mp3'
        $slashPos = strpos($blobKey, '/');
        $type     = $slashPos !== false ? substr($blobKey, 0, $slashPos) : 'audio';
        $key      = $slashPos !== false ? substr($blobKey, $slashPos + 1) : $blobKey;

        $ext      = pathinfo($key, PATHINFO_EXTENSION);
        $tempPath = $this->tempDir . '/probe_' . $assetId . '.' . $ext;

        // Use output buffering to capture stream() output into the temp file.
        // MediaStorageService::stream() writes directly to PHP output.
        ob_start();
        $this->storage->stream($type, $key);
        $bytes = ob_get_clean();

        if ($bytes === false || $bytes === '') {
            throw new \RuntimeException(
                '[MediaProbeJobService] Empty stream for blob_key=' . $blobKey
            );
        }

        if (file_put_contents($tempPath, $bytes) === false) {
            throw new \RuntimeException(
                '[MediaProbeJobService] Cannot write temp file: ' . $tempPath
            );
        }

        return $tempPath;
    }

    /**
     * Run ffprobe and return [durationSeconds, mediaInfoJson].
     *
     * @return array{?int, ?string}
     */
    private function runFfprobe(string $filePath): array
    {
        $cmd = sprintf(
            '%s -v quiet -print_format json -show_streams -show_format %s 2>/dev/null',
            escapeshellcmd($this->ffprobeBin),
            escapeshellarg($filePath)
        );
        $output = shell_exec($cmd);
        if ($output === null || $output === '') {
            throw new \RuntimeException('[MediaProbeJobService] ffprobe returned no output for: ' . $filePath);
        }

        $info = json_decode($output, true);
        if (!is_array($info)) {
            throw new \RuntimeException('[MediaProbeJobService] ffprobe returned invalid JSON');
        }

        $duration = null;
        if (isset($info['format']['duration'])) {
            $duration = (int)round((float)$info['format']['duration']);
        }

        return [$duration, $output];
    }

    /**
     * Generate a thumbnail PNG at the 5-second mark (or 0:00 if shorter).
     * Returns the path to the generated thumbnail temp file.
     */
    private function generateThumbnail(string $videoPath, int $assetId): string
    {
        $thumbPath = $this->tempDir . '/probe_thumb_' . $assetId . '.png';
        $cmd = sprintf(
            '%s -y -ss 5 -i %s -frames:v 1 -vf "scale=320:-1" %s 2>/dev/null',
            escapeshellcmd($this->ffmpegBin),
            escapeshellarg($videoPath),
            escapeshellarg($thumbPath)
        );
        exec($cmd, $out, $exitCode);

        if ($exitCode !== 0 || !is_file($thumbPath)) {
            // Retry at 0:00 (some videos are shorter than 5s)
            $cmd = sprintf(
                '%s -y -ss 0 -i %s -frames:v 1 -vf "scale=320:-1" %s 2>/dev/null',
                escapeshellcmd($this->ffmpegBin),
                escapeshellarg($videoPath),
                escapeshellarg($thumbPath)
            );
            exec($cmd, $out, $exitCode);
        }

        if ($exitCode !== 0 || !is_file($thumbPath)) {
            throw new \RuntimeException(
                '[MediaProbeJobService] ffmpeg thumbnail generation failed for: ' . $videoPath
            );
        }

        return $thumbPath;
    }

    /**
     * Store the thumbnail via MediaStorageService::putThumbnail() and return its key.
     * putThumbnail() derives the thumb key from the video blob key's SHA-256 stem.
     */
    private function storeThumbnail(string $thumbPath, string $blobKey): string
    {
        // putThumbnail($videoKey, $localThumbPath) handles key derivation internally.
        return $this->storage->putThumbnail($blobKey, $thumbPath);
    }

    /** Update the assets row with probe results. */
    private function updateAsset(int $assetId, ?int $duration, ?string $mediaInfo, ?string $thumbnailKey): void
    {
        $this->pdo->prepare(
            'UPDATE assets
             SET duration_seconds = ?,
                 media_info       = ?,
                 media_info_tool  = \'ffprobe\',
                 updated_at       = NOW()
             WHERE asset_id = ?'
        )->execute([$duration, $mediaInfo, $assetId]);

        // thumbnail_blob_key column added in Phase 4; skip if not yet present
        if ($thumbnailKey !== null) {
            try {
                $this->pdo->prepare(
                    'UPDATE assets SET thumbnail_blob_key = ? WHERE asset_id = ?'
                )->execute([$thumbnailKey, $assetId]);
            } catch (\PDOException) {
                // Column not yet added — Phase 4 concern; log and continue
                error_log('[MediaProbeJobService] thumbnail_blob_key column not yet present; skipping for asset=' . $assetId);
            }
        }
    }
}
