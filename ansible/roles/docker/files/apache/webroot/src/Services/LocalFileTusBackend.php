<?php

declare(strict_types=1);

namespace Production\Api\Services;

use Production\Api\Contracts\TusChunkBackendInterface;

/**
 * tus chunk backend for local filesystem storage.
 *
 * Each PATCH body is appended to a staging file at:
 *   {localStagingDir}/{uploadId}
 *
 * On finalize, the staging file is moved to its final destination:
 *   audio/ → {localAudioDir}/{sha256}.{ext}
 *   video/ → {localVideoDir}/{sha256}.{ext}
 *
 * This backend never touches Azure; all I/O is local filesystem.
 */
final class LocalFileTusBackend implements TusChunkBackendInterface
{
    public function __construct(
        private readonly string $localStagingDir,
        private readonly string $localAudioDir,
        private readonly string $localVideoDir,
    ) {}

    /**
     * Append chunk data to the staging file.
     *
     * {@inheritDoc}
     */
    public function writeChunk(
        string $uploadId,
        int    $blockIndex,
        string $data,
        string $mimeType,
        string $fileType,
    ): void {
        $this->ensureDir($this->localStagingDir);
        $stagingPath = $this->stagingPath($uploadId);

        $fh = @fopen($stagingPath, 'ab');
        if ($fh === false) {
            throw new \RuntimeException(
                '[LocalFileTusBackend] Cannot open staging file for append: ' . $stagingPath
            );
        }
        try {
            $written = fwrite($fh, $data);
            if ($written === false || $written !== strlen($data)) {
                throw new \RuntimeException(
                    '[LocalFileTusBackend] Short write to staging file: ' . $stagingPath
                );
            }
        } finally {
            fclose($fh);
        }
    }

    /**
     * Move the staging file to its final media directory path.
     *
     * {@inheritDoc}
     */
    public function finalizeUpload(
        string $uploadId,
        string $checksum,
        string $fileExt,
        string $mimeType,
        string $fileType,
        int    $uploadLength,
    ): string {
        $stagingPath = $this->stagingPath($uploadId);

        if (!is_file($stagingPath)) {
            throw new \RuntimeException(
                '[LocalFileTusBackend] Staging file missing at finalize: ' . $stagingPath
            );
        }

        $actualSize = filesize($stagingPath);
        if ($actualSize !== $uploadLength) {
            throw new \RuntimeException(
                sprintf(
                    '[LocalFileTusBackend] Staging file size mismatch: expected %d bytes, got %d — upload=%s',
                    $uploadLength,
                    $actualSize,
                    $uploadId,
                )
            );
        }

        $destDir  = ($fileType === 'video') ? $this->localVideoDir : $this->localAudioDir;
        $fileName = $checksum . '.' . $fileExt;
        $destPath = rtrim($destDir, '/') . '/' . $fileName;

        $this->ensureDir($destDir);

        if (!rename($stagingPath, $destPath)) {
            throw new \RuntimeException(
                '[LocalFileTusBackend] Failed to move staging file to: ' . $destPath
            );
        }

        // Return the qualified key matching MediaStorageService conventions
        $prefix = ($fileType === 'video') ? 'video' : 'audio';
        return $prefix . '/' . $fileName;
    }

    /**
     * Remove the staging file.
     * Best-effort — does not throw if the file does not exist.
     *
     * {@inheritDoc}
     */
    public function abortUpload(string $uploadId, string $fileType): void
    {
        $stagingPath = $this->stagingPath($uploadId);
        if (is_file($stagingPath)) {
            @unlink($stagingPath);
        }
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function stagingPath(string $uploadId): string
    {
        return rtrim($this->localStagingDir, '/') . '/' . $uploadId;
    }

    private function ensureDir(string $dir): void
    {
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('[LocalFileTusBackend] Cannot create directory: ' . $dir);
        }
    }
}
