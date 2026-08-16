<?php

declare(strict_types=1);

namespace Production\Api\Config;

use PDO;
use Production\Api\Infrastructure\Database;
use Production\Api\Services\AzureBlobRestClient;
use Production\Api\Services\AzureIdentityTokenCache;
use Production\Api\Services\MediaBackend;

/**
 * Value object grouping all runtime configuration for TusBlockUploadService.
 *
 * Built once per request via fromEnv(); passed to the service constructor.
 * Reduces the TusBlockUploadService constructor parameter count (RSPEC-107).
 *
 * All backend comparisons use MediaBackend:: constants — never raw string literals.
 */
final readonly class TusUploadConfig
{
    /** Maximum 50,000 Azure Block Blob blocks per blob. */
    public const AZURE_MAX_BLOCKS = 50_000;

    /** Maximum upload size in bytes (4 GB default). */
    public const DEFAULT_MAX_FILE_BYTES = 4 * 1024 * 1024 * 1024;

    public function __construct(
        public readonly PDO    $pdo,
        public readonly string $storageBackend,         // MediaBackend::LOCAL | AZURE_BLOB
        public readonly int    $maxFileSizeBytes,       // from UPLOAD_MAX_BYTES env var
        public readonly int    $chunkSizeBytes,         // from UPLOAD_CHUNK_SIZE_BYTES env var
        public readonly int    $maxPendingUploadsPerToken, // from UPLOAD_MAX_PENDING_PER_TOKEN
        public readonly string $localStagingDir,        // TUS_LOCAL_STAGING_DIR
        public readonly string $localAudioDir,          // MEDIA_LOCAL_AUDIO_DIR
        public readonly string $localVideoDir,          // MEDIA_LOCAL_VIDEO_DIR
        public readonly ?AzureBlobRestClient $azureClient, // null in local mode
    ) {}

    /**
     * Construct from environment variables.
     * This is the sole factory method; no other code should read these env vars.
     */
    public static function fromEnv(): self
    {
        $backend = getenv('GIGHIVE_MEDIA_STORAGE_BACKEND') ?: MediaBackend::LOCAL;

        $azureClient = null;
        if ($backend === MediaBackend::AZURE_BLOB) {
            $azureClient = new AzureBlobRestClient(
                account:            getenv('AZURE_BLOB_ACCOUNT_NAME') ?: '',
                container:          getenv('AZURE_BLOB_CONTAINER')    ?: '',
                tokenCache:         new AzureIdentityTokenCache(
                                        getenv('AZURE_IDENTITY_CLIENT_ID') ?: ''
                                    ),
                curlTimeoutSeconds: 30,
            );
        }

        return new self(
            pdo:                       Database::createFromEnv(),
            storageBackend:            $backend,
            maxFileSizeBytes:          (int)(getenv('UPLOAD_MAX_BYTES') ?: (string)self::DEFAULT_MAX_FILE_BYTES),
            chunkSizeBytes:            (int)(getenv('UPLOAD_CHUNK_SIZE_BYTES') ?: (string)(8 * 1024 * 1024)),
            maxPendingUploadsPerToken: (int)(getenv('UPLOAD_MAX_PENDING_PER_TOKEN') ?: '5'),
            localStagingDir:           getenv('TUS_LOCAL_STAGING_DIR') ?: '/tmp/tus-staging',
            localAudioDir:             getenv('MEDIA_LOCAL_AUDIO_DIR') ?: '/var/www/html/audio',
            localVideoDir:             getenv('MEDIA_LOCAL_VIDEO_DIR') ?: '/var/www/html/video',
            azureClient:               $azureClient,
        );
    }

    public function isAzure(): bool
    {
        return $this->storageBackend === MediaBackend::AZURE_BLOB;
    }

    /**
     * Assert Azure block limit not exceeded for this upload length.
     * Uses configured chunkSizeBytes — NOT maxFileSizeBytes (see Phase 3 doc for rationale).
     *
     * @throws \InvalidArgumentException if the file would exceed Azure's 50,000-block limit.
     */
    public function assertAzureBlockLimit(int $uploadLength): void
    {
        if ($this->chunkSizeBytes > 0
            && (int)ceil($uploadLength / $this->chunkSizeBytes) > self::AZURE_MAX_BLOCKS
        ) {
            throw new \InvalidArgumentException(
                'File too large for Azure Block Blob block limit at configured chunk size.'
            );
        }
    }
}
