<?php declare(strict_types=1);
namespace Production\Api\Services;

use Production\Api\Contracts\MediaStorageBackendInterface;
use Production\Api\Dto\MediaMetaDto;
use RuntimeException;

/**
 * Local filesystem backend for MediaStorageService.
 *
 * Used in VirtualBox and bare-metal environments. Reads from
 * bind-mounted host directories. The bind mounts must remain present
 * in docker-compose; see Phase 5 of the design doc.
 *
 * Path mapping (key is fully qualified, including prefix):
 *   'audio/{key}'            → $audioDir/{key}
 *   'video/{key}'            → $videoDir/{key}
 *   'video/thumbnails/{key}' → $thumbDir/{key}
 */
final class LocalMediaBackend implements MediaStorageBackendInterface
{
    public function __construct(
        private readonly string $audioDir,   // e.g. /var/www/html/audio
        private readonly string $videoDir,   // e.g. /var/www/html/video
        private readonly string $thumbDir,   // e.g. /var/www/html/video/thumbnails
    ) {}

    /**
     * Copy a local file to the media directory under the given key.
     * Creates the destination directory if it does not exist.
     */
    public function put(string $key, string $localPath, string $mimeType): void
    {
        $dest    = $this->resolvePath($key);
        $destDir = dirname($dest);

        if (!is_dir($destDir) && !mkdir($destDir, 0755, true) && !is_dir($destDir)) {
            throw new RuntimeException("Cannot create media directory: {$destDir}");
        }

        if (!copy($localPath, $dest)) {
            throw new RuntimeException("Cannot copy '{$localPath}' to '{$dest}'");
        }
    }

    /**
     * Stream the full file to PHP's output buffer in 64 KB chunks.
     */
    public function stream(string $key): void
    {
        $path = $this->resolvePath($key);

        if (!is_file($path)) {
            throw new RuntimeException("Local media file not found: {$path}");
        }

        $fh = fopen($path, 'rb');
        if ($fh === false) {
            throw new RuntimeException("Cannot open local media file: {$path}");
        }

        while (!feof($fh)) {
            echo fread($fh, 65536);
        }

        fclose($fh);
    }

    /**
     * Stream a byte range of the file to PHP's output buffer.
     * $start and $end are both inclusive (HTTP Range semantics).
     */
    public function streamRange(string $key, int $start, int $end): void
    {
        $path = $this->resolvePath($key);

        if (!is_file($path)) {
            throw new RuntimeException("Local media file not found: {$path}");
        }

        $fh = fopen($path, 'rb');
        if ($fh === false) {
            throw new RuntimeException("Cannot open local media file: {$path}");
        }

        if ($start > 0) {
            fseek($fh, $start);
        }

        $remaining = $end - $start + 1;
        while ($remaining > 0 && !feof($fh)) {
            $chunk     = fread($fh, min(65536, $remaining));
            echo $chunk;
            $remaining -= strlen($chunk);
        }

        fclose($fh);
    }

    /**
     * Return metadata for the local file, or null if it does not exist.
     */
    public function getMeta(string $key): ?MediaMetaDto
    {
        $path = $this->resolvePath($key);

        if (!is_file($path)) {
            return null;
        }

        $size = filesize($path);
        if ($size === false) {
            throw new RuntimeException("Cannot stat local media file: {$path}");
        }

        // ETag: sha256 of file path + mtime — cheap, stable, unique enough for HTTP caching
        $mtime = (string)filemtime($path);
        $etag  = hash('sha256', $path . ':' . $mtime);

        $contentType = $this->mimeFromKey($key);

        return new MediaMetaDto(
            size:        $size,
            etag:        $etag,
            contentType: $contentType,
        );
    }

    /**
     * Delete a local media file. No-op if the file does not exist.
     */
    public function delete(string $key): void
    {
        $path = $this->resolvePath($key);

        if (!is_file($path)) {
            return; // idempotent
        }

        if (!unlink($path)) {
            throw new RuntimeException("Cannot delete local media file: {$path}");
        }
    }

    /**
     * Return true if the local media file exists.
     */
    public function exists(string $key): bool
    {
        return is_file($this->resolvePath($key));
    }

    /**
     * List all files in the directory matching the given key prefix.
     * Returns fully-qualified keys (e.g. 'audio/a3f2.mp3').
     *
     * @return string[]
     */
    public function list(string $prefix): array
    {
        $dir = $this->resolvePath(rtrim($prefix, '/') . '/.list-root');
        $dir = dirname($dir); // resolve to the directory itself

        if (!is_dir($dir)) {
            return [];
        }

        $keys  = [];
        $files = glob($dir . '/*') ?: [];
        foreach ($files as $file) {
            if (is_file($file)) {
                $keys[] = rtrim($prefix, '/') . '/' . basename($file);
            }
        }

        return $keys;
    }

    // ── private ──────────────────────────────────────────────────────────────

    /**
     * Map a fully-qualified blob key to an absolute filesystem path.
     *
     * @throws \InvalidArgumentException for unrecognised key prefix
     */
    private function resolvePath(string $key): string
    {
        if (str_starts_with($key, 'video/thumbnails/')) {
            return rtrim($this->thumbDir, '/') . '/' . substr($key, strlen('video/thumbnails/'));
        }

        if (str_starts_with($key, 'audio/')) {
            return rtrim($this->audioDir, '/') . '/' . substr($key, strlen('audio/'));
        }

        if (str_starts_with($key, 'video/')) {
            return rtrim($this->videoDir, '/') . '/' . substr($key, strlen('video/'));
        }

        throw new \InvalidArgumentException("Unrecognised blob key prefix: '{$key}'");
    }

    /**
     * Infer Content-Type from the key extension.
     * Best-effort — callers can override via getMeta() if MIME matters.
     */
    private function mimeFromKey(string $key): string
    {
        $ext = strtolower(pathinfo($key, PATHINFO_EXTENSION));
        return match ($ext) {
            'mp3'              => 'audio/mpeg',
            'wav'              => 'audio/wav',
            'aac'              => 'audio/aac',
            'flac'             => 'audio/flac',
            'm4a'              => 'audio/mp4',
            'mp4'              => 'video/mp4',
            'mov'              => 'video/quicktime',
            'mkv'              => 'video/x-matroska',
            'webm'             => 'video/webm',
            'png'              => 'image/png',
            default            => 'application/octet-stream',
        };
    }
}
