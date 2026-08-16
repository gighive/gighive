<?php declare(strict_types=1);
namespace Production\Api\Services;

use Production\Api\Contracts\MediaStorageBackendInterface;
use Production\Api\Dto\MediaMetaDto;

/**
 * Application-level media storage facade.
 *
 * All code that reads or writes media blobs must go through this class.
 * Do not call AzureBlobMediaBackend or LocalMediaBackend directly from
 * controllers, API endpoints, or admin scripts.
 *
 * Blob key convention (enforced by qualifiedKey()):
 *   audio/{sha256}.{ext}
 *   video/{sha256}.{ext}
 *   video/thumbnails/{sha256}.png
 *
 * $type parameter accepted by all public methods: 'audio' | 'video' | 'video/thumbnails'
 *
 * This is the one place in production code that reads the Azure/local env vars
 * for the media storage path. It is NOT the same config object as TusUploadConfig.
 */
final class MediaStorageService
{
    public function __construct(
        private readonly MediaStorageBackendInterface $backend,
    ) {}

    /**
     * Factory. Reads GIGHIVE_MEDIA_STORAGE_BACKEND from env and wires
     * the correct backend. Call once per request; do not construct in a loop.
     *
     * @throws \RuntimeException if required Azure env vars are absent in azure_blob mode
     */
    public static function make(): self
    {
        $backend = getenv('GIGHIVE_MEDIA_STORAGE_BACKEND') ?: MediaBackend::LOCAL;

        if ($backend === MediaBackend::AZURE_BLOB || $backend === MediaBackend::AZURE_FALLBACK) {
            // Required vars — throw early rather than let a malformed URL fail silently
            // at the first REST call. This surfaces misconfiguration at startup.
            $account   = getenv('AZURE_BLOB_ACCOUNT_NAME')  ?: throw new \RuntimeException('AZURE_BLOB_ACCOUNT_NAME is required in azure_blob mode');
            $container = getenv('AZURE_BLOB_CONTAINER')     ?: throw new \RuntimeException('AZURE_BLOB_CONTAINER is required in azure_blob mode');
            $clientId  = getenv('AZURE_IDENTITY_CLIENT_ID') ?: throw new \RuntimeException('AZURE_IDENTITY_CLIENT_ID is required in azure_blob mode');

            $rest = new AzureBlobRestClient(
                account:            $account,
                container:          $container,
                tokenCache:         new AzureIdentityTokenCache($clientId),
                curlTimeoutSeconds: 30,
            );
            $azureBackend = new AzureBlobMediaBackend($rest);

            if ($backend === MediaBackend::AZURE_FALLBACK) {
                // Phase 11 transition only — tries Blob first, falls back to local
                // for assets not yet backfilled. Remove after Phase 11 step 9 is verified.
                $localBackend = new LocalMediaBackend(
                    audioDir: getenv('MEDIA_LOCAL_AUDIO_DIR') ?: '/var/www/html/audio',
                    videoDir: getenv('MEDIA_LOCAL_VIDEO_DIR') ?: '/var/www/html/video',
                    thumbDir: getenv('MEDIA_LOCAL_THUMB_DIR') ?: '/var/www/html/video/thumbnails',
                );
                return new self(new FallbackMediaBackend($azureBackend, $localBackend));
            }

            return new self($azureBackend);
        }

        return new self(new LocalMediaBackend(
            audioDir: getenv('MEDIA_LOCAL_AUDIO_DIR') ?: '/var/www/html/audio',
            videoDir: getenv('MEDIA_LOCAL_VIDEO_DIR') ?: '/var/www/html/video',
            thumbDir: getenv('MEDIA_LOCAL_THUMB_DIR') ?: '/var/www/html/video/thumbnails',
        ));
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Store a local file as a media blob.
     *
     * @param string $type     'audio' | 'video'
     * @param string $key      filename only — e.g. 'a3f2...c1.mp3' (no prefix)
     * @param string $localPath absolute path to source file
     */
    public function put(string $type, string $key, string $localPath, string $mimeType): void
    {
        $this->backend->put($this->qualifiedKey($type, $key), $localPath, $mimeType);
    }

    /** Pipe the full blob to PHP's output buffer. */
    public function stream(string $type, string $key): void
    {
        $this->backend->stream($this->qualifiedKey($type, $key));
    }

    /** Pipe a byte range to PHP's output buffer ($start/$end inclusive). */
    public function streamRange(string $type, string $key, int $start, int $end): void
    {
        $this->backend->streamRange($this->qualifiedKey($type, $key), $start, $end);
    }

    /** Return metadata or null if the blob does not exist. */
    public function getMeta(string $type, string $key): ?MediaMetaDto
    {
        return $this->backend->getMeta($this->qualifiedKey($type, $key));
    }

    /** Delete blob. No-op if not found. */
    public function delete(string $type, string $key): void
    {
        $this->backend->delete($this->qualifiedKey($type, $key));
    }

    /** Return true if blob exists. */
    public function exists(string $type, string $key): bool
    {
        return $this->backend->exists($this->qualifiedKey($type, $key));
    }

    /**
     * List all blob keys for the given type.
     *
     * @return string[] fully qualified keys e.g. ['audio/a3f2.mp3', ...]
     */
    public function list(string $type): array
    {
        $prefix = match ($type) {
            'audio'            => 'audio/',
            'video'            => 'video/',
            'video/thumbnails' => 'video/thumbnails/',
            default            => throw new \InvalidArgumentException("Unknown media type: '{$type}'"),
        };

        return $this->backend->list($prefix);
    }

    /**
     * Store a thumbnail PNG blob derived from a video.
     * The thumbnail prefix is baked in here — it is not a runtime env var.
     *
     * @param string $videoKey     filename-only key of the source video e.g. 'b9e1...d4.mp4'
     * @param string $localThumbPath absolute path to the generated .png file
     * @return string              the qualified blob key e.g. 'video/thumbnails/b9e1...png'
     */
    public function putThumbnail(string $videoKey, string $localThumbPath): string
    {
        $sha256   = pathinfo($videoKey, PATHINFO_FILENAME);
        $thumbKey = 'video/thumbnails/' . $sha256 . '.png';

        $this->backend->put($thumbKey, $localThumbPath, 'image/png');

        return $thumbKey;
    }

    // ── private ──────────────────────────────────────────────────────────────

    /**
     * Build the full blob key from type and filename.
     *   ('audio',            'a3f2.mp3')  → 'audio/a3f2.mp3'
     *   ('video',            'b9e1.mp4')  → 'video/b9e1.mp4'
     *   ('video/thumbnails', 'b9e1.png')  → 'video/thumbnails/b9e1.png'
     */
    private function qualifiedKey(string $type, string $key): string
    {
        $prefix = match ($type) {
            'audio'            => 'audio/',
            'video'            => 'video/',
            'video/thumbnails' => 'video/thumbnails/',
            default            => throw new \InvalidArgumentException("Unknown media type: '{$type}'"),
        };

        return $prefix . ltrim($key, '/');
    }
}
