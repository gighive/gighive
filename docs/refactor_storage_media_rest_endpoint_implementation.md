# Storage Media REST Endpoint — Implementation Reference

> **Companion documents:**
> - [`refactor_storage_media_rest_endpoint.md`](refactor_storage_media_rest_endpoint.md) — architecture, rationale, decisions, execution traces, risks
> - [`refactor_storage_media_rest_endpoint_azurite.md`](refactor_storage_media_rest_endpoint_azurite.md) — local Azurite testing setup
>
> This document contains PHP scaffolding, phase-by-phase deployment guide, and
> a file-by-file build order with acceptance criteria.

---

## Index

### PHP Reference
- [Prerequisites and One-Time Changes](#prerequisites-and-one-time-changes)
  - [1. `composer.json` — PHP version constraint](#1-composerjson--php-version-constraint-must-be-raised-to-82)
  - [2. New subdirectories under `src/`](#2-new-subdirectories-under-src-all-covered-by-existing-psr-4-mapping)
- [New Directory Layout](#new-directory-layout)
- [Interfaces](#interfaces)
  - [`MediaStorageBackendInterface`](#srccontractsmediasitoragebackendinterfacephp)
  - [`TusChunkBackendInterface`](#srccontractstuscchunkbackendinterfacephp)
- [Value Objects and DTOs](#value-objects-and-dtos)
  - [`MediaMetaDto`](#srcdtomediametadtophp)
  - [`CurlResult`](#srcdtocurlresultphp)
  - [`TusUploadState`](#srcdtotusuploadstatephp)
- [Configuration Value Object](#configuration-value-object)
  - [`TusUploadConfig`](#srcconfigtusuploadconfigphp)
- [Class Skeletons](#class-skeletons)
  - [`AzureIdentityTokenCache`](#srcservicesazureidentitytokencachephp)
  - [`MediaStorageService`](#srcservicesmediastorageservicephp)
  - [`AzureBlobRestClient`](#srcservicesazureblobrestclientphp)
  - [`AzureBlobMediaBackend`](#srcservicesazureblobmediabackendphp)
  - [`LocalMediaBackend`](#srcserviceslocalmediabackendphp)
  - [`FallbackMediaBackend`](#srcservicesfallbackmediabackendphp)
  - [`TusBlockUploadService`](#srcservicestusblockuploadservicephp)
  - [`AzureBlobTusBackend`](#srcservicesazureblobtusbackendphp)
  - [`LocalFileTusBackend`](#srcserviceslocalfiletusbackendphp)
  - [`MediaProbeJobService`](#srcservicesmediaprobejobservicephp)
- [Entry Points](#entry-points)
  - [`api/tus-upload.php`](#apitus-uploadphp)
  - [`api/media-stream.php`](#apimedia-streamphp)
  - [`src/Jobs/run_probe_job.php`](#srcjobsrun_probe_jobphp)
- [Dependency Wiring](#dependency-wiring)
- [Build Order and Acceptance Criteria](#build-order-and-acceptance-criteria)
  - [Phase 2 — MediaStorageService](#phase-2--mediastorageservice)
  - [Phase 3 — TusBlockUploadService](#phase-3--tusblockuploadservice)
  - [Phase 4 — media-stream.php](#phase-4--media-streamphp)
- [Notes for Implementors](#notes-for-implementors)

### Deployment Phases
- [Implementation Phases (Phase 1 – Phase 11)](#implementation-phases-phase-1--phase-11)
  - [Phase 6 — Terraform: private endpoint](#phase-6--terraform-private-endpoint-and-disable-public-network-access)
  - [Phase 1 — Runtime config and IMDS access](#phase-1--runtime-config-storage-backend-switch-and-imds-access)
  - [Phase 2 — PHP storage abstraction layer](#phase-2--php-storage-abstraction-layer)
  - [Phase 3 — Upload ingress: PHP Block Blob streaming](#phase-3--upload-ingress-php-block-blob-streaming-no-vm-disk-writes)
  - [Phase 4 — Application-mediated media streaming](#phase-4--application-mediated-media-streaming)
  - [Phase 5 — Local / VirtualBox / Baremetal](#phase-5--local--virtualbox--baremetal-tranche-1-final-step)
  - [Phase 7 — Thumbnails and derived media into Blob Storage](#phase-7--thumbnails-and-derived-media-into-blob-storage)
  - [Phase 8 — Runtime auth: Managed Identity replaces SAS](#phase-8--runtime-auth-managed-identity-replaces-sas-for-media-path)
  - [Phase 9 — `2bootstrap.sh` and Ansible wiring](#phase-9--2bootstrapsh-and-ansible-wiring)
  - [Phase 10 — Admin tooling updates](#phase-10--admin-tooling-updates)
  - [Phase 11 — Azure migration and rollout](#phase-11--azure-migration-and-rollout)

---

## Prerequisites and One-Time Changes

### 1. `composer.json` — PHP version constraint must be raised to 8.2

The existing `composer.json` declares `"php": ">=7.4"`. Three language features used in
the new classes set the floor at PHP 8.2:

| Feature | Minimum PHP |
|---------|-------------|
| `HashContext` serialization (`hash_init` + `serialize`) | 8.0 |
| `readonly` promoted constructor properties | 8.1 |
| `readonly class` modifier (`final readonly class ...`) | **8.2** |

Update before implementing Phase 2:

```json
"require": {
    "php": ">=8.2",
    ...
}
```

Run `composer update --ignore-platform-reqs` locally to verify no installed packages
break under 8.2. The Azure container PHP version must be verified per the Ansible
assertion task documented in Phase 3 of the design doc — update that assertion to
check for `>= 8.2.0`, not `>= 8.0.0`.

### 2. New subdirectories under `src/` (all covered by existing PSR-4 mapping)

No `composer.json` autoload changes are needed. The existing `"Production\\Api\\": "src/"`
entry covers all new sub-namespaces.

```
src/
  Contracts/          ← new (interfaces)
  Dto/                ← new (value objects)
  Config/             ← existing; TusUploadConfig added here
  Services/           ← existing; 6 new classes added here
  Jobs/               ← new (CLI-only entry point; not autoloaded via HTTP)
```

---

## New Directory Layout

```
src/Contracts/
  MediaStorageBackendInterface.php    Phase 2
  TusChunkBackendInterface.php        Phase 3

src/Dto/
  MediaMetaDto.php                    Phase 2
  CurlResult.php                      Phase 2
  TusUploadState.php                  Phase 3

src/Config/
  TusUploadConfig.php                 Phase 3

src/Services/
  AzureIdentityTokenCache.php         Phase 2
  AzureBlobRestClient.php             Phase 2  (shared cURL + auth helper; used by both Azure backends)
  MediaStorageService.php             Phase 2
  AzureBlobMediaBackend.php           Phase 2
  LocalMediaBackend.php               Phase 2
  TusBlockUploadService.php           Phase 3
  AzureBlobTusBackend.php             Phase 3
  LocalFileTusBackend.php             Phase 3
  MediaProbeJobService.php            Phase 3

src/Jobs/
  run_probe_job.php                   Phase 3  (CLI entry point, not a web endpoint)

api/
  tus-upload.php                      Phase 3  (web entry point)
  media-stream.php                    Phase 4  (web entry point)
```

---

## Interfaces

### `src/Contracts/MediaStorageBackendInterface.php`

```php
<?php declare(strict_types=1);
namespace Production\Api\Contracts;

use Production\Api\Dto\MediaMetaDto;

interface MediaStorageBackendInterface
{
    /**
     * Store a local file under the given blob key.
     * Replaces any existing blob at that key.
     *
     * @throws \RuntimeException on storage failure
     */
    public function put(string $key, string $localPath, string $mimeType): void;

    /**
     * Pipe the full blob body to PHP's output buffer.
     * Caller must set Content-Type and Content-Length headers before calling.
     *
     * @throws \RuntimeException if the key does not exist or download fails
     */
    public function stream(string $key): void;

    /**
     * Pipe a byte range of the blob to PHP's output buffer.
     * $start and $end are both inclusive (HTTP Range semantics).
     *
     * @throws \RuntimeException if the key does not exist or range is invalid
     */
    public function streamRange(string $key, int $start, int $end): void;

    /**
     * Return size, ETag, and Content-Type for the blob, or null if not found (404).
     *
     * @throws \RuntimeException on network/auth failure (non-404 errors)
     */
    public function getMeta(string $key): ?MediaMetaDto;

    /**
     * Delete the blob. No-op if the key does not exist.
     *
     * @throws \RuntimeException on network/auth failure
     */
    public function delete(string $key): void;

    /**
     * Return true if the blob exists, false for 404.
     *
     * @throws \RuntimeException on network/auth failure
     */
    public function exists(string $key): bool;

    /**
     * List all blob keys with the given prefix. Returns [] if none match.
     *
     * @return string[]
     * @throws \RuntimeException on network/auth failure
     */
    public function list(string $prefix): array;
}
```

### `src/Contracts/TusChunkBackendInterface.php`

```php
<?php declare(strict_types=1);
namespace Production\Api\Contracts;

interface TusChunkBackendInterface
{
    /**
     * Write one chunk during a PATCH request.
     *
     * Azure:  issues PUT Block REST call with $blockId and $blockBytes as body.
     * Local:  appends $blockBytes to /tmp/tus-staging/{uploadId}; $blockId ignored.
     *
     * @throws \RuntimeException on write failure
     */
    public function writeChunk(string $uploadId, string $blockId, string $blockBytes): void;

    /**
     * Finalize the upload after all chunks have been successfully written.
     *
     * Azure:  issues PUT Block List to commit the blob; sets x-ms-meta-sha256.
     * Local:  renames the staging file to the media directory destination path.
     *
     * @param array  $upload   Row from tus_uploads (id, upload_id, file_type, mime_type,
     *                         upload_length, block_count)
     * @param string $blobKey  Destination key e.g. 'audio/a3f2...c1.mp3'
     * @param string $sha256   Final SHA-256 hex string
     * @throws \RuntimeException on finalization failure
     */
    public function finalizeUpload(array $upload, string $blobKey, string $sha256): void;
}
```

---

## Value Objects and DTOs

### `src/Dto/MediaMetaDto.php`

```php
<?php declare(strict_types=1);
namespace Production\Api\Dto;

final readonly class MediaMetaDto
{
    public function __construct(
        public int    $size,         // total blob size in bytes
        public string $etag,         // ETag value without surrounding quotes
        public string $contentType,  // e.g. 'video/mp4'
    ) {}
}
```

### `src/Dto/CurlResult.php`

```php
<?php declare(strict_types=1);
namespace Production\Api\Dto;

/**
 * Return value from AzureBlobRestClient::curl().
 * A non-2xx status is NOT an exception — callers check isSuccess() and branch accordingly.
 */
final readonly class CurlResult
{
    public function __construct(
        public int    $status,   // HTTP response status code
        public string $body,     // response body (may be empty for HEAD/DELETE)
        public array  $headers,  // associative; keys lowercased
    ) {}

    public function isSuccess(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }
}
```

### `src/Dto/TusUploadState.php`

```php
<?php declare(strict_types=1);
namespace Production\Api\Dto;

/**
 * Typed snapshot of a tus_uploads row fetched via SELECT FOR UPDATE.
 * Passed between TusBlockUploadService and its private helpers.
 * All int casts are done here so the rest of the service works with typed values.
 */
final readonly class TusUploadState
{
    public function __construct(
        public int     $id,
        public string  $uploadId,
        public int     $userId,
        public string  $status,        // 'pending' | 'complete' | 'failed'
        public int     $uploadLength,
        public int     $blockCount,
        public int     $blockSize,     // 0 until first PATCH writes it
        public string  $fileType,      // 'audio' | 'video'
        public string  $mimeType,
        public ?string $sha256Ctx,     // serialized HashContext; null before first PATCH
        public ?int    $assetId,       // null until blob committed and asset row inserted
    ) {}

    public static function fromRow(array $row): self
    {
        return new self(
            id:           (int)$row['id'],
            uploadId:     (string)$row['upload_id'],
            userId:       (int)$row['user_id'],
            status:       (string)$row['status'],
            uploadLength: (int)$row['upload_length'],
            blockCount:   (int)$row['block_count'],
            blockSize:    (int)$row['block_size'],
            fileType:     (string)$row['file_type'],
            mimeType:     (string)$row['mime_type'],
            sha256Ctx:    isset($row['sha256_ctx']) ? (string)$row['sha256_ctx'] : null,
            assetId:      isset($row['asset_id'])   ? (int)$row['asset_id']      : null,
        );
    }
}
```

---

## Configuration Value Object

### `src/Config/TusUploadConfig.php`

```php
<?php declare(strict_types=1);
namespace Production\Api\Config;

use PDO;
use Production\Api\Services\AzureBlobRestClient;
use Production\Api\Services\AzureIdentityTokenCache;

/**
 * Immutable configuration bundle for TusBlockUploadService.
 * Construct via fromEnv() in production; inject directly in tests.
 *
 * New env vars required (see Phase 1 addendum table below for all four):
 *   TUS_LOCAL_STAGING_DIR, MEDIA_LOCAL_AUDIO_DIR, MEDIA_LOCAL_VIDEO_DIR,
 *   MEDIA_LOCAL_THUMB_DIR (the last is used only by MediaStorageService, not
 *   this config, but must be deployed at the same time).
 */
final readonly class TusUploadConfig
{
    public function __construct(
        public PDO                 $pdo,
        public string              $backend,          // 'azure_blob' | 'local'
        public ?AzureBlobRestClient $restClient,      // null in local mode; non-null in azure_blob mode
        public string              $blobPrefixAudio,  // AZURE_BLOB_PREFIX_AUDIO e.g. 'audio/'
        public string              $blobPrefixVideo,  // AZURE_BLOB_PREFIX_VIDEO e.g. 'video/'
        public string              $localStagingDir,  // /tmp/tus-staging
        public string              $localAudioDir,    // /var/www/html/audio
        public string              $localVideoDir,    // /var/www/html/video
        public int                 $maxFileSizeBytes, // e.g. 4 GB
        public array               $allowedMimes,     // from MediaTypes::allowedMimes()
    ) {}

    public static function fromEnv(PDO $pdo): self
    {
        $backend = getenv('GIGHIVE_MEDIA_STORAGE_BACKEND') ?: 'local';

        // Only create AzureBlobRestClient in azure_blob mode — avoids unnecessary
        // object construction (and the misleading appearance of an IMDS dependency)
        // when running in local/VirtualBox environments.
        $restClient = $backend === 'azure_blob'
            ? new AzureBlobRestClient(
                account:            getenv('AZURE_BLOB_ACCOUNT_NAME') ?: '',
                container:          getenv('AZURE_BLOB_CONTAINER')    ?: '',
                tokenCache:         new AzureIdentityTokenCache(
                                        getenv('AZURE_IDENTITY_CLIENT_ID') ?: ''
                                    ),
                curlTimeoutSeconds: 120,   // large blocks on slow uplinks
              )
            : null;

        return new self(
            pdo:              $pdo,
            backend:          $backend,
            restClient:       $restClient,
            blobPrefixAudio:  getenv('AZURE_BLOB_PREFIX_AUDIO') ?: 'audio/',
            blobPrefixVideo:  getenv('AZURE_BLOB_PREFIX_VIDEO') ?: 'video/',
            localStagingDir:  getenv('TUS_LOCAL_STAGING_DIR')   ?: '/tmp/tus-staging',
            localAudioDir:    getenv('MEDIA_LOCAL_AUDIO_DIR')    ?: '/var/www/html/audio',
            localVideoDir:    getenv('MEDIA_LOCAL_VIDEO_DIR')    ?: '/var/www/html/video',
            maxFileSizeBytes: (int)(getenv('UPLOAD_MAX_FILE_BYTES') ?: (string)(4 * 1024 * 1024 * 1024)),
            allowedMimes:     MediaTypes::allowedMimes(),
        );
    }
}
```

> **Phase 1 addendum:** The following new env vars must be added to `.env.j2` and
> the corresponding group_vars. Add them to the Phase 1 env var block in the design
> doc before implementing Phase 2/4:
>
> | Variable | Used by | Default |
> |---|---|---|
> | `TUS_LOCAL_STAGING_DIR` | `TusUploadConfig` | `/tmp/tus-staging` |
> | `MEDIA_LOCAL_AUDIO_DIR` | `TusUploadConfig`, `MediaStorageService` | `/var/www/html/audio` |
> | `MEDIA_LOCAL_VIDEO_DIR` | `TusUploadConfig`, `MediaStorageService` | `/var/www/html/video` |
> | `MEDIA_LOCAL_THUMB_DIR` | `MediaStorageService` (LocalMediaBackend only) | `/var/www/html/video/thumbnails` |

---

## Class Skeletons

### `src/Services/AzureIdentityTokenCache.php`

```php
<?php declare(strict_types=1);
namespace Production\Api\Services;

use RuntimeException;

/**
 * APCu-backed Azure Managed Identity token cache.
 *
 * Fetches a Bearer token from IMDS when the cache is empty or within
 * EXPIRY_BUFFER_SECONDS of expiry. Shared by AzureBlobMediaBackend and
 * AzureBlobTusBackend to eliminate redundant IMDS calls under concurrent load.
 *
 * Requirements:
 *   - APCu extension enabled in the PHP container
 *   - Container must have extra_hosts: host.docker.internal:host-gateway
 *     so that 169.254.169.254 is reachable via the host network stack
 *   - apcu.enable_cli=1 required if run from cron (run_probe_job.php)
 */
final class AzureIdentityTokenCache
{
    private const EXPIRY_BUFFER_SECONDS = 300;   // refresh 5 min before expiry
    private const CACHE_PREFIX          = 'azure_token:';
    private const IMDS_BASE_URL         = 'http://169.254.169.254';
    private const CURL_TIMEOUT_SECONDS  = 5;     // IMDS must be fast; fail quickly if unreachable

    public function __construct(
        private readonly string $clientId,
    ) {}

    /**
     * Return a valid Bearer token string.
     *
     * @throws RuntimeException if IMDS is unreachable or returns a non-200 response
     */
    public function getToken(): string { /* ... */ }

    // ── private ──────────────────────────────────────────────────────────────

    /**
     * Fetch a fresh token from IMDS, cache it in APCu, and return it.
     * TTL = expires_in - EXPIRY_BUFFER_SECONDS.
     */
    private function fetchAndCache(): string { /* ... */ }

    /**
     * cURL call to IMDS token endpoint.
     * Returns decoded JSON array on HTTP 200.
     *
     * @throws RuntimeException on cURL error or non-200 response
     */
    private function imdsRequest(): array { /* ... */ }

    private function cacheKey(): string
    {
        return self::CACHE_PREFIX . $this->clientId;
    }
}
```

### `src/Services/MediaStorageService.php`

```php
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
     * This is the one place in production code that reads the Azure/local env vars
     * for MediaStorageService. It is NOT the same config object as TusUploadConfig.
     */
    public static function make(): self
    {
        $backend = getenv('GIGHIVE_MEDIA_STORAGE_BACKEND') ?: 'local';

        if ($backend === 'azure_blob' || $backend === 'azure_blob_with_local_fallback') {
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

            if ($backend === 'azure_blob_with_local_fallback') {
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
    public function put(string $type, string $key, string $localPath, string $mimeType): void { /* ... */ }

    /** Pipe the full blob to PHP's output buffer. */
    public function stream(string $type, string $key): void { /* ... */ }

    /** Pipe a byte range to PHP's output buffer ($start/$end inclusive). */
    public function streamRange(string $type, string $key, int $start, int $end): void { /* ... */ }

    /** Return metadata or null if the blob does not exist. */
    public function getMeta(string $type, string $key): ?MediaMetaDto { /* ... */ }

    /** Delete blob. No-op if not found. */
    public function delete(string $type, string $key): void { /* ... */ }

    /** Return true if blob exists. */
    public function exists(string $type, string $key): bool { /* ... */ }

    /**
     * List all blob keys for the given type.
     *
     * @return string[] fully qualified keys e.g. ['audio/a3f2.mp3', ...]
     */
    public function list(string $type): array { /* ... */ }

    /**
     * Store a thumbnail PNG blob derived from a video.
     *
     * @param string $videoKey     filename-only key of the source video e.g. 'b9e1...d4.mp4'
     * @param string $localThumbPath absolute path to the generated .png file
     * @return string              the blob key of the stored thumbnail e.g. 'video/thumbnails/b9e1...png'
     */
    public function putThumbnail(string $videoKey, string $localThumbPath): string { /* ... */ }

    // ── private ──────────────────────────────────────────────────────────────

    /**
     * Build the full blob key from type and filename.
     *   ('audio',            'a3f2.mp3')  → 'audio/a3f2.mp3'
     *   ('video',            'b9e1.mp4')  → 'video/b9e1.mp4'
     *   ('video/thumbnails', 'b9e1.png')  → 'video/thumbnails/b9e1.png'
     */
    private function qualifiedKey(string $type, string $key): string { /* ... */ }
}
```

### `src/Services/AzureBlobRestClient.php`

```php
<?php declare(strict_types=1);
namespace Production\Api\Services;

use Production\Api\Dto\CurlResult;
use RuntimeException;

/**
 * Shared Azure Blob Storage REST helper.
 *
 * Centralises the two pieces of logic that are otherwise duplicated between
 * AzureBlobMediaBackend and AzureBlobTusBackend:
 *   1. Building authenticated request headers (Bearer token + x-ms-version + x-ms-date)
 *   2. Executing cURL requests and returning a typed CurlResult
 *   3. Building canonical blob URLs
 *
 * Both backends hold a reference to one instance of this class.
 * AzureIdentityTokenCache (and therefore APCu) is shared automatically.
 */
final class AzureBlobRestClient
{
    public const API_VERSION = '2020-04-08';

    public function __construct(
        private readonly string                  $account,
        private readonly string                  $container,
        private readonly AzureIdentityTokenCache $tokenCache,
        private readonly int                     $curlTimeoutSeconds = 30,
    ) {}

    /**
     * Build the canonical blob URL.
     * https://{account}.blob.core.windows.net/{container}/{key}
     * Appends $queryString verbatim if provided (caller must include leading '?').
     */
    public function blobUrl(string $key, string $queryString = ''): string { /* ... */ }

    /**
     * Return auth + version headers for CURLOPT_HTTPHEADER:
     *   Authorization: Bearer {token}
     *   x-ms-version: 2020-04-08
     *   x-ms-date: {RFC1123}
     *
     * @return string[]
     */
    public function authHeaders(): array { /* ... */ }

    /**
     * Execute a cURL request and return a CurlResult.
     * Throws RuntimeException on cURL transport error (not on non-2xx HTTP).
     * Callers must check CurlResult::isSuccess() and handle HTTP errors themselves.
     *
     * @param mixed $body  string body, file resource for CURLOPT_INFILE, or null
     */
    public function curl(string $method, string $url, array $extraHeaders = [], mixed $body = null): CurlResult { /* ... */ }
}
```

`AzureBlobMediaBackend` and `AzureBlobTusBackend` both accept an injected
`AzureBlobRestClient`. In production they receive one created by `MediaStorageService::make()`
or `TusUploadConfig::fromEnv()` respectively; in tests a test double can be injected.

---

### `src/Services/AzureBlobMediaBackend.php`

```php
<?php declare(strict_types=1);
namespace Production\Api\Services;

use Production\Api\Contracts\MediaStorageBackendInterface;
use Production\Api\Dto\MediaMetaDto;
use RuntimeException;

/**
 * Azure Blob Storage backend for MediaStorageService.
 *
 * All REST calls delegate to AzureBlobRestClient (auth headers, URL building,
 * cURL execution). This class contains only blob-level logic: PUT blob,
 * GET blob, HEAD blob, DELETE blob, List blobs with prefix.
 *
 * See Phase 8 of the design doc for the token flow and IMDS routing.
 */
final class AzureBlobMediaBackend implements MediaStorageBackendInterface
{
    public function __construct(
        private readonly AzureBlobRestClient $rest,
    ) {}

    public function put(string $key, string $localPath, string $mimeType): void { /* ... */ }
    public function stream(string $key): void { /* ... */ }
    public function streamRange(string $key, int $start, int $end): void { /* ... */ }
    public function getMeta(string $key): ?MediaMetaDto { /* ... */ }
    public function delete(string $key): void { /* ... */ }
    public function exists(string $key): bool { /* ... */ }
    public function list(string $prefix): array { /* ... */ }
}
```

### `src/Services/LocalMediaBackend.php`

```php
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
 * Path mapping:
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

    public function put(string $key, string $localPath, string $mimeType): void { /* ... */ }
    public function stream(string $key): void { /* ... */ }
    public function streamRange(string $key, int $start, int $end): void { /* ... */ }
    public function getMeta(string $key): ?MediaMetaDto { /* ... */ }
    public function delete(string $key): void { /* ... */ }
    public function exists(string $key): bool { /* ... */ }
    public function list(string $prefix): array { /* ... */ }

    // ── private ──────────────────────────────────────────────────────────────

    /**
     * Map a qualified blob key to an absolute filesystem path.
     *
     * @throws \InvalidArgumentException for unrecognised key prefix
     */
    private function resolvePath(string $key): string { /* ... */ }
}
```

### `src/Services/FallbackMediaBackend.php`

```php
<?php declare(strict_types=1);
namespace Production\Api\Services;

use Production\Api\Contracts\MediaStorageBackendInterface;
use Production\Api\Dto\MediaMetaDto;

/**
 * Phase 11 ONLY — temporary split-read backend.
 *
 * Activated via GIGHIVE_MEDIA_STORAGE_BACKEND=azure_blob_with_local_fallback.
 * Tries the Azure Blob backend first for every read operation; falls back to
 * the local filesystem backend for assets not yet backfilled.
 *
 * All write operations (put, delete) go to Azure only — no writes ever go to
 * the local backend through this class.
 *
 * REMOVAL: Delete this file and the azure_blob_with_local_fallback branch from
 * MediaStorageService::make() after Phase 11 step 9 (backfill verified complete).
 * The file must not be present in production after the fallback window closes.
 */
final class FallbackMediaBackend implements MediaStorageBackendInterface
{
    public function __construct(
        private readonly MediaStorageBackendInterface $primary,   // AzureBlobMediaBackend
        private readonly MediaStorageBackendInterface $fallback,  // LocalMediaBackend
    ) {}

    /**
     * Write to primary (Azure) only. Never writes to local.
     */
    public function put(string $key, string $localPath, string $mimeType): void { /* ... */ }

    /**
     * Try primary; on 404 fall back to local.
     * Any non-404 error from primary propagates — do not mask Azure errors.
     */
    public function stream(string $key): void { /* ... */ }

    /** Try primary; on 404 fall back to local. */
    public function streamRange(string $key, int $start, int $end): void { /* ... */ }

    /**
     * Try primary; return null if primary returns null (404).
     * If primary returns null, try fallback and return its result.
     * Non-404 errors from primary propagate.
     */
    public function getMeta(string $key): ?MediaMetaDto { /* ... */ }

    /** Delete from primary only. */
    public function delete(string $key): void { /* ... */ }

    /**
     * True if primary exists OR fallback exists.
     * Used by backfill_media_to_blob.php to skip already-present blobs.
     */
    public function exists(string $key): bool { /* ... */ }

    /**
     * Returns primary list. Fallback list is not merged — after backfill,
     * primary is the source of truth.
     */
    public function list(string $prefix): array { /* ... */ }
}
```

### `src/Services/TusBlockUploadService.php`

```php
<?php declare(strict_types=1);
namespace Production\Api\Services;

use Production\Api\Config\TusUploadConfig;
use Production\Api\Contracts\TusChunkBackendInterface;
use Production\Api\Dto\TusUploadState;
use RuntimeException;

/**
 * PHP tus 1.0 server (creation extension).
 *
 * Handles three HTTP methods:
 *   POST  /files/      — create upload (handlePost)
 *   PATCH /files/{id}  — stream chunk  (handlePatch)
 *   HEAD  /files/{id}  — resume query  (handleHead)
 *
 * Delegates per-chunk I/O to TusChunkBackendInterface.
 * Auth must be checked by the caller (tus-upload.php) before any of
 * these methods are invoked.
 *
 * Design doc cross-references:
 *   SHA256 accumulation + block_size first-PATCH rule  → Phase 3 (design doc)
 *   Concurrent PATCH protection + lock window          → Phase 3 (design doc)
 *   File type validation at POST                       → Phase 3 (design doc)
 *   Retry on PUT Block failure                         → Execution traces (design doc)
 */
final class TusBlockUploadService
{
    private TusChunkBackendInterface $chunkBackend;

    /**
     * @param TusChunkBackendInterface|null $chunkBackend
     *   Inject a test double in unit tests. In production leave null and the
     *   correct backend is selected from $config->backend automatically.
     */
    public function __construct(
        private readonly TusUploadConfig    $config,
        ?TusChunkBackendInterface           $chunkBackend = null,
    ) {
        $this->chunkBackend = $chunkBackend ?? (
            $this->config->backend === 'azure_blob'
                ? new AzureBlobTusBackend($config->restClient
                      ?? throw new \LogicException('restClient must be set for azure_blob backend'))
                : new LocalFileTusBackend($config)
        );
    }

    // ── public tus handlers ───────────────────────────────────────────────────

    /**
     * Handle POST /files/
     *
     * Validates Upload-Metadata (file type, MIME, extension match),
     * enforces Upload-Length max, checks per-token pending upload count,
     * creates tus_uploads row, returns 201 with Location header.
     *
     * @param int $userId  Caller-resolved user identifier written to tus_uploads.user_id.
     *                     In practice the caller passes $tokenResult->tokenId as a proxy
     *                     (one token == one uploader session). This value is used only for
     *                     the concurrent-upload rate check; it does not need to be a users.id FK.
     *                     IMPORTANT: this is per-token rate limiting, not per-user. A single
     *                     real user who holds multiple valid tokens gets a separate budget per
     *                     token. This is acceptable at GigHive's current scale but should be
     *                     revisited if per-user (cross-token) enforcement is ever required.
     *
     * Exits with:
     *   400 — missing/invalid tus headers or Upload-Metadata validation failure
     *   413 — Upload-Length exceeds maxFileSizeBytes
     *   429 — per-user pending upload limit reached
     *   500 — DB insert failure
     */
    public function handlePost(int $userId): void { /* ... */ }

    /**
     * Handle PATCH /files/{uploadId}
     *
     * Acquires FOR UPDATE lock, verifies Upload-Offset, reads PATCH body into
     * memory, updates SHA256 hash context, delegates to chunkBackend::writeChunk(),
     * advances block_count (sets block_size on first PATCH), commits blob on final
     * chunk, inserts asset row, enqueues probe job.
     *
     * Exits with:
     *   404 — upload_id not found
     *   409 — Upload-Offset mismatch (concurrent PATCH collision)
     *   410 — upload already complete or failed
     *   500 — I/O or DB failure
     *   204 — success; Upload-Offset header set to new offset
     */
    public function handlePatch(string $uploadId): void { /* ... */ }

    /**
     * Handle HEAD /files/{uploadId}
     *
     * Queries DB (no lock). Returns Upload-Offset and Upload-Length.
     * For complete uploads returns Upload-Offset = upload_length.
     * For pending uploads returns Upload-Offset = block_count * block_size.
     *
     * Exits with:
     *   404 — upload_id not found
     *   200 — Upload-Offset and Upload-Length headers set
     */
    public function handleHead(string $uploadId): void { /* ... */ }

    // ── private helpers ───────────────────────────────────────────────────────

    /**
     * Parse and validate the Upload-Metadata tus header.
     *
     * Expected format: base64-key base64-value pairs separated by commas.
     * Required keys: 'filename', 'filetype'
     *
     * @return array{file_type: string, mime_type: string, filename: string}
     * @throws \InvalidArgumentException with a user-safe message on validation failure
     */
    private function validateMetadata(string $header): array { /* ... */ }

    /**
     * SELECT … FOR UPDATE on tus_uploads WHERE upload_id = ?.
     * The caller must have begun a transaction before calling this method.
     *
     * @return TusUploadState|null  null → upload not found (return 404)
     */
    private function acquireUploadLock(string $uploadId): ?TusUploadState { /* ... */ }

    /**
     * UPDATE tus_uploads with new hash context and incremented block_count.
     * On first PATCH (isFirstBlock = true), also writes block_size.
     * Must be called inside an open transaction; does NOT commit.
     */
    private function persistBlockProgress(
        string      $uploadId,
        \HashContext $ctx,
        int         $newBlockCount,
        int         $blockSize,
        bool        $isFirstBlock,
    ): void { /* ... */ }

    /**
     * INSERT into assets and UPDATE tus_uploads SET status='complete', asset_id=?
     * Called only after chunkBackend::finalizeUpload() succeeds.
     *
     * @return int  the new asset_id
     */
    private function commitDbAsset(TusUploadState $upload, string $sha256, string $blobKey): int { /* ... */ }

    /**
     * INSERT INTO probe_jobs after successful blob + asset commit.
     */
    private function enqueueProbeJob(int $assetId, string $blobKey, string $fileType): void { /* ... */ }

    /**
     * Generate upload_id as UUID v4 using random_bytes().
     * Server-generated only — never accept client-provided IDs.
     */
    private function generateUploadId(): string { /* ... */ }

    /**
     * Build the destination blob key from upload state and final SHA256.
     * e.g. 'audio/' + $sha256 + '.' + extension extracted from mime_type
     * The key is not known until hash_final() is called on the last PATCH.
     */
    private function buildBlobKey(TusUploadState $upload, string $sha256): string { /* ... */ }

    /**
     * Query pending upload count for rate-limit enforcement.
     * Returns the number of in-flight (pending, not yet committed) uploads for this token.
     * Note: $userId is tokenId — this is per-token rate limiting, not per-user.
     * SELECT COUNT(*) FROM tus_uploads WHERE user_id = ? AND status = 'pending'
     *   AND expires_at > NOW()
     */
    private function pendingCountForUser(int $userId): int { /* ... */ }
}
```

### `src/Services/AzureBlobTusBackend.php`

```php
<?php declare(strict_types=1);
namespace Production\Api\Services;

use Production\Api\Contracts\TusChunkBackendInterface;
use RuntimeException;

/**
 * Azure Blob Storage chunk backend.
 * Each PATCH body → PUT Block; final PATCH → PUT Block List.
 *
 * All REST calls delegate to AzureBlobRestClient (auth, URL building, cURL).
 * This class contains only block-level logic: block ID generation, PUT Block,
 * PUT Block List XML.
 *
 * Block ID format: base64_encode(str_pad((string)$blockIndex, 6, '0', STR_PAD_LEFT))
 * All block IDs within a blob must have the same byte-length.
 *
 * curl_timeout on the injected AzureBlobRestClient should be raised to 120s
 * for large blocks on slow uplinks (pass curlTimeoutSeconds: 120 to the constructor).
 */
final class AzureBlobTusBackend implements TusChunkBackendInterface
{
    public function __construct(
        private readonly AzureBlobRestClient $rest,
    ) {}

    public function writeChunk(string $uploadId, string $blockId, string $blockBytes): void { /* ... */ }

    public function finalizeUpload(array $upload, string $blobKey, string $sha256): void { /* ... */ }

    // ── private ──────────────────────────────────────────────────────────────

    /**
     * Generate the PUT Block List XML body.
     * All block IDs are wrapped in <Latest> elements.
     *
     * @param string[] $blockIds
     */
    private function blockListXml(array $blockIds): string { /* ... */ }

    /**
     * Build block IDs for all committed blocks from a block_count.
     * Uses the same format as writeChunk() — must stay in sync.
     *
     * @return string[]
     */
    private function allBlockIds(int $blockCount): array { /* ... */ }
}
```

### `src/Services/LocalFileTusBackend.php`

```php
<?php declare(strict_types=1);
namespace Production\Api\Services;

use Production\Api\Config\TusUploadConfig;
use Production\Api\Contracts\TusChunkBackendInterface;
use RuntimeException;

/**
 * Local filesystem chunk backend.
 * Appends PATCH bodies to /tmp/tus-staging/{uploadId}.
 * On finalizeUpload(), renames the staging file to the media directory.
 *
 * Used in VirtualBox and bare-metal deployments.
 * $blockId is accepted but ignored — local writes are sequential.
 */
final class LocalFileTusBackend implements TusChunkBackendInterface
{
    public function __construct(
        private readonly TusUploadConfig $config,
    ) {}

    /**
     * Append $blockBytes to the staging file.
     * Creates $localStagingDir and the file if they do not exist.
     * $blockId is ignored.
     */
    public function writeChunk(string $uploadId, string $blockId, string $blockBytes): void { /* ... */ }

    /**
     * Rename the staging file to its final destination in the media directory.
     * Destination is derived from $blobKey:
     *   'audio/{key}' → $localAudioDir/{key}
     *   'video/{key}' → $localVideoDir/{key}
     */
    public function finalizeUpload(array $upload, string $blobKey, string $sha256): void { /* ... */ }

    // ── private ──────────────────────────────────────────────────────────────

    private function stagingPath(string $uploadId): string
    {
        return rtrim($this->config->localStagingDir, '/') . '/' . $uploadId;
    }

    /**
     * Resolve blob key to absolute destination path.
     * @throws \InvalidArgumentException for unrecognised prefix
     */
    private function destPath(string $blobKey): string { /* ... */ }
}
```

### `src/Services/MediaProbeJobService.php`

```php
<?php declare(strict_types=1);
namespace Production\Api\Services;

use PDO;
use RuntimeException;

/**
 * Async media post-processing: ffprobe + optional thumbnail generation.
 * Invoked from src/Jobs/run_probe_job.php (cron); not a web request.
 *
 * Job lifecycle:
 *   queued → running (started_at = NOW()) → done | failed
 *
 * Design doc cross-references:
 *   Retry cap + stuck-job reset SQL  → Phase 3 (design doc)
 *   started_at vs created_at rationale → Phase 3 (design doc), probe_jobs DDL section
 *   Temp file cleanup                → Phase 3 failure handling table
 */
final class MediaProbeJobService
{
    public function __construct(
        private readonly PDO                 $pdo,
        private readonly MediaStorageService $storage,
        private readonly string              $ffprobeBin = '/usr/bin/ffprobe',
        private readonly string              $ffmpegBin  = '/usr/bin/ffmpeg',
        private readonly string              $tmpDir     = '/tmp',
    ) {}

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Advance the retry state machine for all non-terminal jobs. Two passes:
     *
     * Pass 1 — re-queue retryable stuck jobs:
     *   UPDATE probe_jobs SET status='queued'
     *   WHERE status='running' AND started_at < NOW() - INTERVAL 10 MINUTE
     *     AND attempts < 3
     *
     * Pass 2 — permanently fail exhausted jobs:
     *   UPDATE probe_jobs SET status='failed'
     *   WHERE status='running' AND started_at < NOW() - INTERVAL 10 MINUTE
     *     AND attempts >= 3
     *
     * Note: jobs that fail fast (markJobFailed sets status='failed') are also
     * re-queued here if attempts < 3 — add a third pass covering status='failed'
     * with attempts < 3 if fast-failure retry is required. Current design relies
     * on stuck-job reset only (i.e. a fast-failing job is not automatically retried
     * unless it was stuck in 'running'). Clarify with the team before implementing.
     *
     * Call once per cron invocation, before runOneJob().
     */
    public function resetStuckJobs(): void { /* ... */ }

    /**
     * Claim one queued job and process it end-to-end.
     * Returns true if a job was processed, false if queue was empty.
     */
    public function runOneJob(): bool { /* ... */ }

    // ── private ──────────────────────────────────────────────────────────────

    /**
     * SELECT id, asset_id, blob_key, file_type FROM probe_jobs
     *   WHERE status='queued' ORDER BY created_at LIMIT 1 FOR UPDATE
     * followed by UPDATE status='running', started_at=NOW(), attempts=attempts+1.
     * Returns the row, or null if queue is empty.
     */
    private function claimJob(): ?array { /* ... */ }

    /**
     * Download blob to /tmp/{assetId}.{ext}.
     * Returns the local temp path.
     *
     * @throws RuntimeException on download failure
     */
    private function downloadToTmp(int $assetId, string $blobKey): string { /* ... */ }

    /**
     * Run ffprobe on $localPath.
     *
     * @return array{duration_seconds: float, media_info_json: string}
     * @throws RuntimeException if ffprobe exits non-zero
     */
    private function probeMedia(string $localPath): array { /* ... */ }

    /**
     * Generate a thumbnail PNG from the video using ffmpeg (single-frame seek).
     * Returns the absolute temp path to the PNG, or null for audio.
     */
    private function generateThumbnail(string $localPath, int $assetId, string $fileType): ?string { /* ... */ }

    /**
     * PUT the thumbnail to blob storage.
     * Returns the resulting blob key e.g. 'video/thumbnails/{sha256}.png'
     */
    private function storeThumbnail(string $thumbPath, string $videoKey): string { /* ... */ }

    /**
     * UPDATE assets SET duration_seconds=?, media_info_json=?, thumbnail_blob_key=?
     * WHERE id=?
     */
    private function updateAsset(int $assetId, array $probeResult, ?string $thumbBlobKey): void { /* ... */ }

    /** UPDATE probe_jobs SET status='done' WHERE id=? */
    private function markJobDone(int $jobId): void { /* ... */ }

    /**
     * Mark a job as failed. Two behaviours depending on attempt count:
     *   attempts < 3:  UPDATE status='failed'  (resetStuckJobs will NOT automatically
     *                  re-queue fast-failing jobs — see resetStuckJobs() note above;
     *                  extend resetStuckJobs() with a third pass if retry is required)
     *   attempts >= 3: UPDATE status='failed'  (permanent; never re-queued)
     *
     * Logs $error via error_log() for the PHP error log. For cron-visible output,
     * callers should also echo/fwrite to STDOUT before calling this method
     * (see Notes for Implementors — error_log vs probe_job.log).
     *
     * Does NOT increment attempts — that is done in claimJob() via attempts=attempts+1.
     */
    private function markJobFailed(int $jobId, string $error): void { /* ... */ }

    /** Delete temp files silently. Missing files are not an error. */
    private function cleanup(string ...$paths): void { /* ... */ }
}
```

---

## Entry Points

### `api/tus-upload.php`

```php
<?php declare(strict_types=1);
set_time_limit(0);
ignore_user_abort(true);

require_once __DIR__ . '/../vendor/autoload.php';

use Production\Api\Config\TusUploadConfig;
use Production\Api\Infrastructure\Database;
use Production\Api\Services\TusBlockUploadService;
use Production\Api\Services\UploadTokenValidator;

// ── Auth ─────────────────────────────────────────────────────────────────────
// Same token pattern as /api/uploads.php. Token is per-event and validated
// against event_upload_tokens. user_id for tus_uploads is resolved from the
// token's associated event/user context.
$rawToken = $_SERVER['HTTP_X_UPLOAD_TOKEN'] ?? null;
if ($rawToken === null) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Missing X-Upload-Token header']);
    exit;
}

try {
    $pdo = Database::createFromEnv();
} catch (\Throwable) {
    http_response_code(500);
    exit;
}

$tokenResult = (new UploadTokenValidator($pdo))->validate($rawToken);
if ($tokenResult === null) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Invalid or expired upload token']);
    exit;
}

// TokenValidationResult has no userId field — use tokenId as a per-uploader proxy.
// This satisfies tus_uploads.user_id NOT NULL and enables per-token rate limiting.
$userId = $tokenResult->tokenId;

// ── Route ─────────────────────────────────────────────────────────────────────
// Apache RewriteRule sets PATH_INFO to the path component after /files:
//   POST  /files/        → PATH_INFO = '/'   → $uploadId = ''
//   PATCH /files/{id}    → PATH_INFO = '/{id}' → $uploadId = '{id}'
//   HEAD  /files/{id}    → PATH_INFO = '/{id}' → $uploadId = '{id}'
$method   = $_SERVER['REQUEST_METHOD'] ?? '';
$pathInfo = $_SERVER['PATH_INFO'] ?? '';
$uploadId = trim($pathInfo, '/');

$config  = TusUploadConfig::fromEnv($pdo);
$service = new TusBlockUploadService($config);

match ($method) {
    'POST'  => $service->handlePost($userId),
    'PATCH' => $service->handlePatch($uploadId),
    'HEAD'  => $service->handleHead($uploadId),
    default => (static function (): never {
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Method not supported by tus endpoint']);
        exit;
    })(),
};
```

### `api/media-stream.php`

```php
<?php declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Production\Api\Infrastructure\Database;
use Production\Api\Services\MediaStorageService;
use Production\Api\Services\UploadTokenValidator;

// ── Auth ─────────────────────────────────────────────────────────────────────
// See design doc Phase 4: thumbnails use same session-cookie / token auth as
// audio and video. Browser <img> tags rely on session cookie being sent
// automatically for same-origin requests.
$rawToken = $_SERVER['HTTP_X_UPLOAD_TOKEN'] ?? null;
if ($rawToken === null) {
    http_response_code(401);
    exit;
}

try {
    $pdo = Database::createFromEnv();
} catch (\Throwable) {
    http_response_code(500);
    exit;
}

if ((new UploadTokenValidator($pdo))->validate($rawToken) === null) {
    http_response_code(401);
    exit;
}

// ── Resolve media type and key ────────────────────────────────────────────────
// Apache sets REDIRECT_MEDIA_TYPE and REDIRECT_MEDIA_KEY via E= flags on the
// RewriteRule. Fall back to URI parsing if vars are absent (direct PHP invocation).
$type = $_SERVER['REDIRECT_MEDIA_TYPE'] ?? '';
$key  = $_SERVER['REDIRECT_MEDIA_KEY']  ?? '';

if ($type === '' || $key === '') {
    // Parse from REQUEST_URI: /media/{type}/{key} or /{type}/{key}
    // ... URI parsing logic (see design doc backward-compat routing section) ...
}

// ── Type + key validation (before any blob access) ────────────────────────────
// Validate $type against an explicit allowlist. If URI parsing returns an
// unexpected value (or Apache sets REDIRECT_MEDIA_TYPE to something unexpected),
// we must reject rather than pass the value into qualifiedKey() where a traversal
// like '../../etc' could reach the filesystem backend.
if (!in_array($type, ['audio', 'video', 'video/thumbnails'], true)) {
    http_response_code(400);
    exit;
}

// No i flag — SHA-256 from PHP hash_final() is always lowercase hex; uppercase
// would indicate a client-constructed key and should be rejected, not normalised.
if (!preg_match('/^[a-f0-9]{64}\.[a-z0-9]{2,5}$/', $key)) {
    http_response_code(400);
    exit;
}

// ── Fetch metadata ────────────────────────────────────────────────────────────
$storage = MediaStorageService::make();

try {
    $meta = $storage->getMeta($type, $key);
} catch (\RuntimeException) {
    http_response_code(503);
    exit;
}

if ($meta === null) {
    http_response_code(404);
    exit;
}

// ── Range handling (see design doc Phase 4 for full snippet) ──────────────────
// ... parse Range header, set $start / $end / $isRange ...

// ── Stream response ───────────────────────────────────────────────────────────
header('Content-Type: '   . $meta->contentType);
header('Content-Length: ' . ($end - $start + 1));
header('Accept-Ranges: bytes');
header('ETag: "'          . $meta->etag . '"');
header('Cache-Control: private, max-age=3600');

if ($isRange) {
    http_response_code(206);
    header('Content-Range: bytes ' . $start . '-' . $end . '/' . $meta->size);
} else {
    http_response_code(200);
}

try {
    $storage->streamRange($type, $key, $start, $end);
} catch (\RuntimeException) {
    // Headers already sent; log and abort — client will see truncated body
    error_log('[media-stream] Stream failed for ' . $type . '/' . $key);
}
exit;
```

### `src/Jobs/run_probe_job.php`

```php
<?php declare(strict_types=1);
/**
 * Cron entry point — not a web request. Invoked by /etc/cron.d/gighive-probe
 * approximately every 10 seconds (via two staggered crontab lines).
 * Not autoloaded via PSR-4 HTTP routing.
 *
 * Usage: php /var/www/html/src/Jobs/run_probe_job.php
 * Output: appended to /var/log/probe_job.log (see logrotate config)
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use Production\Api\Infrastructure\Database;
use Production\Api\Services\MediaProbeJobService;
use Production\Api\Services\MediaStorageService;

try {
    $pdo     = Database::createFromEnv();
    $storage = MediaStorageService::make();
    $service = new MediaProbeJobService($pdo, $storage);

    $service->resetStuckJobs();
    $processed = $service->runOneJob();

    // Exit 0 regardless — empty queue is normal, not an error
    exit(0);
} catch (\Throwable $e) {
    error_log('[probe_job] Fatal: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    exit(1);
}
```

---

## Dependency Wiring

```
tus-upload.php
  └─ TusBlockUploadService(TusUploadConfig, ?TusChunkBackendInterface)
       ├─ AzureBlobTusBackend(AzureBlobRestClient)      ← azure_blob mode
       │    └─ AzureBlobRestClient(account, container, AzureIdentityTokenCache)
       │         └─ AzureIdentityTokenCache(clientId)  ← APCu-cached IMDS token
       └─ LocalFileTusBackend(TusUploadConfig)          ← local mode

media-stream.php
  └─ MediaStorageService::make()
       ├─ AzureBlobMediaBackend(AzureBlobRestClient)    ← azure_blob mode
       │    └─ AzureBlobRestClient(account, container, AzureIdentityTokenCache)
       └─ LocalMediaBackend(audioDir, videoDir,         ← local mode
            thumbDir)

run_probe_job.php
  └─ MediaProbeJobService(PDO, MediaStorageService)
       └─ MediaStorageService::make()                   ← same wiring as above
```

`AzureBlobRestClient` is the single owner of `AzureIdentityTokenCache`. Two separate
`AzureBlobRestClient` instances exist per request (one in `TusUploadConfig::fromEnv()`
for tus, one in `MediaStorageService::make()` for streaming), each with their own
`AzureIdentityTokenCache` instance. However, both read from and write to the same
APCu cache key (`azure_token:{clientId}`), so the underlying IMDS HTTP call is made
at most once per `EXPIRY_BUFFER_SECONDS` window across all PHP workers regardless of
which instance triggers the refresh.

---

## Build Order and Acceptance Criteria

Build in this sequence. Each item is independently testable before the next begins.

### Phase 2 — MediaStorageService

| # | File | Acceptance criteria |
|---|------|---------------------|
| 1 | `AzureIdentityTokenCache` | `getToken()` from inside the container returns a non-empty Bearer string; second call within the same minute hits APCu and makes no IMDS HTTP call (verify with `strace` or by temporarily logging) |
| 2 | `MediaStorageBackendInterface`, `MediaMetaDto`, `CurlResult` | PHP syntax valid; `composer dump-autoload` without errors |
| 3 | `AzureBlobRestClient` | `blobUrl('audio/test.mp3')` returns correctly formed URL; `authHeaders()` returns an array with `Authorization`, `x-ms-version`, and `x-ms-date` keys; `curl('HEAD', ...)` against a real blob returns `CurlResult` with `status=200` |
| 4 | `LocalMediaBackend` | `put()` copies a test file to the audio dir; `getMeta()` returns correct size; `stream()` pipes correct bytes to stdout; `exists()` returns true/false correctly |
| 5 | `AzureBlobMediaBackend` | `getMeta()` returns correct size and ETag for a known blob in Azure; `stream()` pipes correct bytes; `put()` creates a blob visible in the Azure portal |
| 6 | `MediaStorageService::make()` factory | Wires `AzureBlobMediaBackend` when `=azure_blob`; wires `FallbackMediaBackend(azure, local)` when `=azure_blob_with_local_fallback`; wires `LocalMediaBackend` when `=local`; throws `RuntimeException` if `AZURE_BLOB_ACCOUNT_NAME`, `AZURE_BLOB_CONTAINER`, or `AZURE_IDENTITY_CLIENT_ID` is unset in Azure modes; `getMeta()` delegation reaches the correct backend in each mode |

### Phase 3 — TusBlockUploadService

| # | File | Acceptance criteria |
|---|------|---------------------|
| 7 | `TusUploadConfig`, `TusUploadState`, `TusChunkBackendInterface` | `TusUploadConfig::fromEnv()` constructs without error; `restClient` is null in local mode, non-null in azure_blob mode; `TusUploadState::fromRow()` correctly casts all fields including nullable `sha256Ctx` and `assetId` |
| 8 | `LocalFileTusBackend` | `POST + PATCH × N + HEAD` flow through `TusBlockUploadService` creates a complete file at the correct path in `localAudioDir`/`localVideoDir`; `sha256sum` of the output file matches the SHA-256 stored in `tus_uploads.asset` chain |
| 9 | `AzureBlobTusBackend` | Single `writeChunk()` issues one `PUT Block`; blob appears in Azure portal's uncommitted block list; `finalizeUpload()` commits it; blob is readable via `AzureBlobMediaBackend::getMeta()` |
| 10 | `TusBlockUploadService` | Full tus flow with `tus-js-client` and iOS `TUSKit`; `tus_uploads` row transitions `pending → complete`; `probe_jobs` row inserted with `status=queued`; SHA-256 in `assets` row matches `sha256sum` of original file; `block_size` in `tus_uploads` is non-zero after first PATCH |
| 11 | `MediaProbeJobService` + `run_probe_job.php` | One `queued` job claimed, `status → running`, then `→ done`; `assets.duration_seconds` updated; video thumbnail blob exists in storage (Azure or local thumb dir) |
| 12 | `tus-upload.php` entry point | Smoke test: `GET /files/` → 400; unauthenticated `POST /files/` → 401; authenticated `POST /files/` with valid `Upload-Length` and `Upload-Metadata` → 201 with `Location: /files/{uuid}` |

### Phase 4 — media-stream.php

| # | File | Acceptance criteria |
|---|------|---------------------|
| 13 | `media-stream.php` | Full-file `GET /media/audio/{key}` → 200 with correct `Content-Type` and correct byte count; range request → 206 with correct `Content-Range` header; `GET /audio/{key}` (old path) returns same bytes as `GET /media/audio/{key}` (backward-compat); unauthenticated request → 401; invalid `$type` value → 400 |

---

## Notes for Implementors

- **`/* ... */` method bodies** are intentionally left empty in this document.
  Each method body corresponds to one or more concrete snippets already documented
  in the design doc (cross-referenced in the class docblock). Implement the
  skeleton first — confirm it compiles — then fill in bodies using the design doc
  snippets as the specification.

- **Error logging format:** Use `error_log('[ClassName] event: ' . $detail)` for
  consistency with existing PHP services (`UploadService`, `UploadTokenValidator`).
  Do not log Bearer tokens, SHA256 contexts, or raw upload body bytes.

- **`error_log()` vs `/var/log/probe_job.log`:** PHP's `error_log()` writes to the
  PHP error log (typically the container stderr or `/var/log/php*`), NOT to the file
  the cron redirect appends to. Operational output in `run_probe_job.php` that must
  appear in `probe_job.log` should use `echo` or `fwrite(STDOUT, ...)`. Reserve
  `error_log()` for unexpected exceptions and fatal errors that belong in the PHP
  engine log. The cron line `>> /var/log/probe_job.log 2>&1` captures stdout and
  stderr — use `fwrite(STDERR, ...)` for error-level messages you want visible in
  both log destinations.

- **Backend string constant:** The string `'azure_blob'` appears identically in
  `TusUploadConfig::fromEnv()`, `MediaStorageService::make()`, and the Ansible
  `docker-compose.yml.j2` template conditional. Consider introducing a class constant
  (e.g., `MediaBackend::AZURE_BLOB = 'azure_blob'`, `MediaBackend::LOCAL = 'local'`,
  `MediaBackend::AZURE_FALLBACK = 'azure_blob_with_local_fallback'`) shared by both
  factory methods to prevent silent drift if the string ever changes.

- **`TusUploadConfig` parameter count (RSPEC-107):** The constructor has 9 parameters,
  exceeding the SonarQube RSPEC-107 threshold of 7. A `StoragePathConfig` value object
  wrapping the four local directory vars (`localStagingDir`, `localAudioDir`,
  `localVideoDir`, and the corresponding media dir) would reduce this to 6 parameters
  and group the local-mode concerns cleanly.

- **Test isolation:** `TusUploadConfig` accepts an injected `PDO` instance — use an
  in-memory SQLite PDO or a test DB for unit tests of `TusBlockUploadService`
  private helpers. `AzureIdentityTokenCache` can be replaced with a stub in
  `AzureBlobTusBackend` and `AzureBlobMediaBackend` tests by passing a test double.

---

## Implementation Phases (Phase 1 – Phase 11)

> Phase-by-phase deployment guide covering Terraform, Ansible, PHP, and
> migration steps for all environments. Moved here from the design doc to keep
> the architecture document focused on rationale and structure.
> Cross-references to PHP class skeletons earlier in this document are noted
> inline per phase.

### Phase 6 — Terraform: private endpoint and disable public network access

**Goal:** Ensure the Azure VM can reach Blob Storage via a private network path, and that no public client can access the storage account directly.

**Change:** Update `terraform/main.tf` to add:

```hcl
resource "azurerm_private_endpoint" "blob" {
  name                = "${var.resource_group_name}-blob-pe"
  location            = azurerm_resource_group.rg.location
  resource_group_name = azurerm_resource_group.rg.name
  subnet_id           = azurerm_subnet.subnet.id

  private_service_connection {
    name                           = "blob-psc"
    private_connection_resource_id = azurerm_storage_account.media.id
    subresource_names              = ["blob"]
    is_manual_connection           = false
  }

  private_dns_zone_group {
    name                 = "blob-dns-zone-group"
    private_dns_zone_ids = [azurerm_private_dns_zone.blob.id]
  }
}

resource "azurerm_private_dns_zone" "blob" {
  name                = "privatelink.blob.core.windows.net"
  resource_group_name = azurerm_resource_group.rg.name
}

resource "azurerm_private_dns_zone_virtual_network_link" "blob" {
  name                  = "blob-dns-vnet-link"
  resource_group_name   = azurerm_resource_group.rg.name
  private_dns_zone_name = azurerm_private_dns_zone.blob.name
  virtual_network_id    = azurerm_virtual_network.vnet.id
  registration_enabled  = false
}
```

And modify `azurerm_storage_account.media`:

```hcl
public_network_access_enabled = false    # was true

network_rules {
  default_action = "Deny"
  bypass         = ["AzureServices"]
  # Remove virtual_network_subnet_ids — replaced by Private Link
}
```

**Deployment order constraint:** This phase must not be applied until Phase 2 (the PHP storage service) is complete and verified to work with Blob. Applying private-only access before the app can reach Blob results in a fully broken media layer with no fallback. Apply Terraform Phase 6 changes only after verifying blob access from within the app container on a test run.

**Timing note:** `2bootstrap.sh` runs Terraform from the developer's local machine. After `public_network_access_enabled = false`, the Terraform state backend (a different storage account) is unaffected. Subsequent Terraform runs continue to work. However, any direct `az storage blob` CLI commands against the media account from a local machine will begin to fail — use the VM or Azure Cloud Shell for those.

#### Validation Checklist — Phase 6

*2 of 7 automated (`post_build_checks`); 5 manual. Terraform runs from developer machine — Ansible cannot introspect `terraform plan` output or the Azure portal UI.*

---

**T-1 [Manual]** — `terraform plan` shows only the expected new resources and one modification to the storage account; no unexpected destroys.
> Terraform is invoked from the developer's local machine via `2bootstrap.sh`, outside Ansible's scope. Inspect the plan output before confirming apply.

**T-2 [Manual]** — `terraform apply` completes without error; private endpoint appears in the Azure portal networking blade.
> Requires Azure portal UI access; not automatable from Ansible on the VM.

**T-3 [post_build_checks]** — Blob DNS resolves to a private IP from inside the Apache container.

```yaml
# Add to post_build_checks/tasks/main.yml — gated on azure_blob mode
- name: "[T-3] Blob DNS resolves to private IP inside Apache container"
  ansible.builtin.command: >
    docker exec -i "{{ apache_container_name }}"
      nslookup {{ azure_blob_account_name }}.blob.core.windows.net
  register: blob_dns
  changed_when: false
  when: gighive_media_storage_backend == 'azure_blob'
  tags: [smoke, media_storage]

- name: "[T-3] Assert Blob DNS resolved to a private (10.x.x.x) address"
  ansible.builtin.assert:
    that:
      - blob_dns.stdout | regex_search('Address:\s+10\.') is not none
    fail_msg: >
      Blob DNS did not resolve to a private IP — private endpoint may not be active.
      nslookup output: {{ blob_dns.stdout }}
  when: gighive_media_storage_backend == 'azure_blob'
  tags: [smoke, media_storage]
```

**T-4 [Manual]** — `validate_app` Azure connectivity probe passes using the updated private-endpoint probe (not the legacy SAS-over-public-endpoint probe).
> This is itself a `validate_app` run — it passes automatically once the probe is updated in the role. Cross-reference: Ansible Role Interactions section in the design doc.

**T-5 [Manual]** — Direct `curl https://{account}.blob.core.windows.net/...` from the developer's laptop returns 403 or times out.
> Must be run from outside the VM — Ansible runs on the VM and would succeed via the private endpoint, masking a misconfigured public-access block.

**T-6 [post_build_checks]** — Existing media endpoint responds normally post-apply (no 403 regression from inside the container).

```yaml
# Add to post_build_checks/tasks/main.yml
- name: "[T-6] Media endpoint returns 401 (not 403/500) after Phase 6 apply"
  ansible.builtin.uri:
    url: "{{ gighive_base_url }}/media/audio/"
    method: GET
    validate_certs: "{{ gighive_validate_certs }}"
    status_code: [400, 401, 404]
    headers: "{{ {'Host': gighive_hostname_for_host_header} if (gighive_hostname_for_host_header | length) > 0 else omit }}"
  changed_when: false
  when: gighive_media_storage_backend == 'azure_blob'
  tags: [smoke, media_storage]
```

**T-7 [Manual]** — `terraform state list` runs without error from the developer machine after apply (confirms state backend storage account unaffected).
> Must run from developer machine; Terraform is not installed on the VM.

---

### Phase 1 — Runtime config: storage backend switch and IMDS access

**Goal:** Introduce the env vars and compose changes needed to switch media access mode per environment.

**New group_vars variables** (must appear in `gighive2.yml`, `gighive.yml`, `prod.yml`, and a new `azure.yml` group vars file):

```yaml
gighive_media_storage_backend: "local"          # override to "azure_blob" in Azure group vars
azure_blob_account_name:        ""              # set from Terraform output in Azure group vars
azure_blob_container:           ""              # set from Terraform output in Azure group vars
azure_blob_prefix_audio:        "audio/"
azure_blob_prefix_video:        "video/"
azure_blob_prefix_thumbnails:   "video/thumbnails/"
azure_identity_client_id:       ""              # set from Terraform output in Azure group vars
```

**`.env.j2` additions:**

```
GIGHIVE_MEDIA_STORAGE_BACKEND={{ gighive_media_storage_backend | default('local') }}
AZURE_BLOB_ACCOUNT_NAME={{ azure_blob_account_name | default('') }}
AZURE_BLOB_CONTAINER={{ azure_blob_container | default('') }}
AZURE_BLOB_PREFIX_AUDIO={{ azure_blob_prefix_audio | default('audio/') }}
AZURE_BLOB_PREFIX_VIDEO={{ azure_blob_prefix_video | default('video/') }}
# Note: thumbnail prefix 'video/thumbnails/' is baked into MediaStorageService::putThumbnail()
#       and is not a configurable env var.
AZURE_IDENTITY_CLIENT_ID={{ azure_identity_client_id | default('') }}
MEDIA_LOCAL_AUDIO_DIR={{ media_local_audio_dir | default('/var/www/html/audio') }}
MEDIA_LOCAL_VIDEO_DIR={{ media_local_video_dir | default('/var/www/html/video') }}
MEDIA_LOCAL_THUMB_DIR={{ media_local_thumb_dir | default('/var/www/html/video/thumbnails') }}
TUS_LOCAL_STAGING_DIR={{ tus_local_staging_dir | default('/tmp/tus-staging') }}
```

**`docker-compose.yml.j2` change — conditional bind mounts:**

```yaml
{% if gighive_media_storage_backend | default('local') != 'azure_blob' %}
      - "/home/{{ ansible_user }}/audio:{{ media_search_dir_audio }}"
      - "/home/{{ ansible_user }}/video:{{ media_search_dir_video }}"
{% endif %}
```

**`docker-compose.yml.j2` change — IMDS access (add unconditionally):**

```yaml
    extra_hosts:
      - "host.docker.internal:host-gateway"
```

This is required for Managed Identity token acquisition from inside the Docker bridge network. The Azure IMDS endpoint (`169.254.169.254`) is only reachable from the host. The `host-gateway` alias lets the container reach the VM host's network stack, through which IMDS is accessible. Without this, every call to acquire an Azure AD token silently times out and all Blob operations return 403. The compose already uses this pattern for the telemetry proxy when `gighive_enable_telemetry_proxy` is true; this makes it unconditional for all Azure-mode deployments.

**`MEDIA_SEARCH_DIRS` handling:** When `GIGHIVE_MEDIA_STORAGE_BACKEND=azure_blob`, the local paths do not exist inside the container. `MEDIA_SEARCH_DIRS` must either be set to a staging temp path or left empty with code updated to tolerate it. The hard-fail in `clear_media_files.php` must be gated on storage backend before this phase is complete.

#### Validation Checklist — Phase 1

*6 of 6 automated (`post_build_checks`). All checks are container-introspection or HTTP and run fully from Ansible.*

---

**T-8 [post_build_checks]** — `GIGHIVE_MEDIA_STORAGE_BACKEND` env var is set in the container and matches group vars; Azure-specific vars are non-empty in Azure mode.

```yaml
# Add to post_build_checks/tasks/main.yml
- name: "[T-8] GIGHIVE_MEDIA_STORAGE_BACKEND matches expected value"
  ansible.builtin.command: >
    docker exec -i "{{ apache_container_name }}" printenv GIGHIVE_MEDIA_STORAGE_BACKEND
  register: media_backend_env
  changed_when: false
  tags: [smoke, media_storage]

- name: "[T-8] Assert GIGHIVE_MEDIA_STORAGE_BACKEND equals gighive_media_storage_backend"
  ansible.builtin.assert:
    that:
      - media_backend_env.stdout | trim == gighive_media_storage_backend
    fail_msg: >
      Container GIGHIVE_MEDIA_STORAGE_BACKEND={{ media_backend_env.stdout | trim }},
      expected {{ gighive_media_storage_backend }}
  tags: [smoke, media_storage]

- name: "[T-8] Azure Blob env vars are non-empty (Azure mode only)"
  ansible.builtin.command: >
    docker exec -i "{{ apache_container_name }}"
      sh -lc 'test -n "$(printenv {{ item }})"'
  loop:
    - AZURE_BLOB_ACCOUNT_NAME
    - AZURE_BLOB_CONTAINER
    - AZURE_IDENTITY_CLIENT_ID
  changed_when: false
  when: gighive_media_storage_backend == 'azure_blob'
  tags: [smoke, media_storage]
```

**T-9 [post_build_checks]** — Apache container has `host.docker.internal:host-gateway` in `ExtraHosts`.

```yaml
# Add to post_build_checks/tasks/main.yml
- name: "[T-9] Read Apache container ExtraHosts"
  ansible.builtin.command: >
    docker inspect
      -f '{% raw %}{{range .HostConfig.ExtraHosts}}{{.}}{{"\n"}}{{end}}{% endraw %}'
      "{{ apache_container_name }}"
  register: container_extra_hosts
  changed_when: false
  tags: [smoke, media_storage]

- name: "[T-9] Assert host.docker.internal extra host is present"
  ansible.builtin.assert:
    that:
      - "'host.docker.internal' in container_extra_hosts.stdout"
    fail_msg: >
      host.docker.internal not found in ExtraHosts.
      Current ExtraHosts: {{ container_extra_hosts.stdout }}
  tags: [smoke, media_storage]
```

**T-10 [post_build_checks]** — IMDS instance endpoint is reachable from inside the Apache container (Azure mode only).

```yaml
# Add to post_build_checks/tasks/main.yml
- name: "[T-10] IMDS instance endpoint reachable from Apache container"
  ansible.builtin.command: >
    docker exec -i "{{ apache_container_name }}"
      curl -sf -o /dev/null -w "%{http_code}"
        "http://169.254.169.254/metadata/instance?api-version=2021-02-01"
        -H "Metadata: true" --connect-timeout 5
  register: imds_instance_code
  changed_when: false
  when: gighive_media_storage_backend == 'azure_blob'
  tags: [smoke, media_storage]

- name: "[T-10] Assert IMDS returns 200"
  ansible.builtin.assert:
    that:
      - imds_instance_code.stdout | trim == "200"
    fail_msg: >
      IMDS returned {{ imds_instance_code.stdout | trim }} (expected 200).
      Check extra_hosts host-gateway config and Azure VM identity assignment.
  when: gighive_media_storage_backend == 'azure_blob'
  tags: [smoke, media_storage]
```

**T-11 [post_build_checks]** — Apache container has no local media bind mounts in Azure mode.

```yaml
# Add to post_build_checks/tasks/main.yml
- name: "[T-11] Read Apache container mount sources"
  ansible.builtin.command: >
    docker inspect
      -f '{% raw %}{{range .Mounts}}{{.Source}}{{"\n"}}{{end}}{% endraw %}'
      "{{ apache_container_name }}"
  register: container_mounts
  changed_when: false
  tags: [smoke, media_storage]

- name: "[T-11] Assert no local audio/video bind mounts present (Azure mode)"
  ansible.builtin.assert:
    that:
      - container_mounts.stdout | regex_search('/audio') is none
      - container_mounts.stdout | regex_search('/video') is none
    fail_msg: >
      Local media bind mounts found in Azure mode.
      Mounts: {{ container_mounts.stdout }}
  when: gighive_media_storage_backend == 'azure_blob'
  tags: [smoke, media_storage]
```

**T-12 [post_build_checks]** — Apache container has audio and video bind mounts present in local/VirtualBox mode.

```yaml
# Add to post_build_checks/tasks/main.yml
- name: "[T-12] Assert audio and video host dirs are mounted (local mode)"
  ansible.builtin.assert:
    that:
      - container_mounts.stdout | regex_search('/audio') is not none
      - container_mounts.stdout | regex_search('/video') is not none
    fail_msg: >
      Expected audio/video bind mounts not found in local mode.
      Mounts: {{ container_mounts.stdout }}
  when: gighive_media_storage_backend != 'azure_blob'
  tags: [smoke, media_storage]
  # Depends on T-11 register: container_mounts — place after T-11 block
```

**T-13 [post_build_checks]** — `clear_media_files.php` admin page returns non-500 in Azure mode.

```yaml
# Add to post_build_checks/tasks/main.yml
- name: "[T-13] clear_media_files.php does not 500 without local media dirs (Azure mode)"
  ansible.builtin.uri:
    url: "{{ gighive_base_url }}/src/clear_media_files.php"
    method: GET
    url_username: "{{ uploader_user }}"
    url_password: "{{ gighive_uploader_password }}"
    force_basic_auth: yes
    validate_certs: "{{ gighive_validate_certs }}"
    status_code: [200, 302, 401, 403]
    headers: "{{ {'Host': gighive_hostname_for_host_header} if (gighive_hostname_for_host_header | length) > 0 else omit }}"
  changed_when: false
  when: gighive_media_storage_backend == 'azure_blob'
  tags: [smoke, media_storage]
```

---

### Phase 2 — PHP storage abstraction layer

**Goal:** Centralize all blob operations in one service so no other code cares whether storage is local or Blob.

**New file:** `src/Services/MediaStorageService.php`

The service implements a backend-agnostic interface:

```php
interface MediaStorageBackend {
    public function put(string $type, string $key, string $localPath): void;
    public function getStream(string $type, string $key): StreamResult;
    public function getRangeStream(string $type, string $key, int $start, int $end): StreamResult;
    public function delete(string $type, string $key): void;
    public function exists(string $type, string $key): bool;
    public function list(string $type): array;
    public function getMeta(string $type, string $key): BlobMeta;
}
```

Two concrete implementations:
- `LocalMediaBackend` — reads/writes from `/var/www/html/audio` and `/var/www/html/video`
- `AzureBlobMediaBackend` — uses REST, acquires identity token via IMDS, caches token until 5 minutes before expiry

**Token acquisition and caching in `AzureBlobMediaBackend`:**

```php
private function getAccessToken(): string {
    // Token cached in APCu or a static property with expiry check
    if ($this->cachedToken !== null && time() < $this->tokenExpiresAt - 300) {
        return $this->cachedToken;
    }
    $imdsUrl = 'http://169.254.169.254/metadata/identity/oauth2/token'
             . '?api-version=2018-02-01'
             . '&resource=https%3A%2F%2Fstorage.azure.com%2F'
             . '&client_id=' . urlencode($this->identityClientId);
    // curl GET with Metadata: true header
    // parse access_token and expires_in
    // cache result
}
```

Token is never logged. SAS tokens remain valid only for admin import/export tooling as a transitional measure; the runtime media path uses identity-based tokens only.

**`AzureBlobMediaBackend` wraps the existing functions from admin helpers, extracted into the service:**

- `uploadBlobFromFile()` from `export_media_worker_azure.php` becomes the `put()` backend — replace the SAS query param with a `Authorization: Bearer <token>` header
- `downloadBlobToFile()` from `import_media_zip_worker_azure.php` becomes the `getStream()` backend — same auth swap
- `listAzureBlobs()` from `admin_media_lib.php` becomes the `list()` backend

Do not reinvent these. Extract, refactor auth, wrap.

**`MediaStorageService` is instantiated once per request via a factory that reads `GIGHIVE_MEDIA_STORAGE_BACKEND` from the environment.**

`AzureBlobMediaBackend` and `AzureBlobTusBackend` both take an injected `AzureBlobRestClient` (see `AzureBlobRestClient.php` in the implementation reference doc). `AzureBlobRestClient` owns `AzureIdentityTokenCache` and all cURL execution, eliminating duplication between the two Azure backends.

```php
// MediaStorageService::make() — see implementation reference doc for full body
final class MediaStorageService {
    public static function make(): self {
        $backend = getenv('GIGHIVE_MEDIA_STORAGE_BACKEND') ?: 'local';
        if ($backend === 'azure_blob') {
            $rest = new AzureBlobRestClient(
                account:            getenv('AZURE_BLOB_ACCOUNT_NAME') ?: '',
                container:          getenv('AZURE_BLOB_CONTAINER')    ?: '',
                tokenCache:         new AzureIdentityTokenCache(
                                        getenv('AZURE_IDENTITY_CLIENT_ID') ?: ''
                                    ),
                curlTimeoutSeconds: 30,
            );
            return new self(new AzureBlobMediaBackend($rest));
        }
        return new self(new LocalMediaBackend(
            audioDir: getenv('MEDIA_LOCAL_AUDIO_DIR') ?: '/var/www/html/audio',
            videoDir: getenv('MEDIA_LOCAL_VIDEO_DIR') ?: '/var/www/html/video',
            thumbDir: getenv('MEDIA_LOCAL_THUMB_DIR') ?: '/var/www/html/video/thumbnails',
        ));
    }
}
```

Blob key convention — prefix is baked into the key, not an env var:
```
audio/<sha256>.<ext>              (AZURE_BLOB_PREFIX_AUDIO sets the 'audio/' portion in TusUploadConfig)
video/<sha256>.<ext>              (AZURE_BLOB_PREFIX_VIDEO sets the 'video/' portion)
video/thumbnails/<sha256>.png     (hardcoded in MediaStorageService::putThumbnail())
```

#### SonarQube / Best-Practice Notes

- **RSPEC-3776 (cognitive complexity):** `AzureBlobMediaBackend` delegates all HTTP to `AzureBlobRestClient`; method bodies are now thin dispatchers and should stay well within the complexity threshold.
- **RSPEC-107 (too many parameters):** All Azure parameters are grouped inside `AzureBlobRestClient`; `AzureBlobMediaBackend` and `AzureBlobTusBackend` each take one constructor argument.
- **RSPEC-6426 (null dereference):** All `getenv()` calls return `string|false`; use `?: ''` default before passing as string. Never pass `false` as a URL component.
- **No tokens in logs:** `AzureBlobRestClient` must never log the `Authorization` header value or any URL that contains one. Log the method + URL path only.
- **Input validation on blob keys:** Key validation belongs in `media-stream.php` (entry point) — reject non-matching keys with 400 before calling any backend. `AzureBlobMediaBackend` assumes a pre-validated key.
- **try/catch around all external calls:** Every cURL call in `AzureBlobRestClient::curl()` must catch transport errors and throw `\RuntimeException`. Callers (backends) catch and convert to appropriate HTTP responses. Pattern: `503` for infrastructure failures, `404` for missing blobs.
- **Shared token cache:** `AzureIdentityTokenCache` uses APCu keyed on `"azure_token:{$clientId}"`. Two `AzureBlobRestClient` instances (one in `MediaStorageService::make()`, one in `TusUploadConfig::fromEnv()`) share the same APCu key — IMDS is called at most once per expiry window across all concurrent PHP workers.

---

### Phase 3 — Upload ingress: PHP Block Blob streaming (no VM disk writes)

**Goal:** Make uploads land directly in Blob Storage without any staging on VM disk, eliminating the `tusd_data` volume as a scaling constraint.

**Why the tusd temp-file approach is rejected for Azure mode:**

The existing `tusd` container writes every incoming byte to the `tusd_data` Docker named volume before the PHP finalizer can act. For a 4 GB video upload, 4 GB of VM disk is consumed during the entire upload window. This is the same disk dependency the refactor is meant to eliminate. The admin UI already surfaces this operationally: `"SERVER DISK FULL: free space on /srv/tusd-data/data/"` is a known live error condition. Keeping tusd in the Azure upload path preserves the exact coupling we are removing from the read path.

**Chosen approach: PHP tus 1.0 Block Blob streaming server.**

PHP handles all tus protocol requests (`POST`, `PATCH`, `HEAD`) directly. Each PATCH body is immediately forwarded to Azure Blob Storage as a `PUT Block` REST call. No intermediate file is ever written. The SHA256 checksum is computed as blocks stream through PHP in-memory. On the final PATCH, PHP calls `PUT Block List` to atomically commit the assembled blob. The DB row is written immediately after the commit — without duration or thumbnail (those are filled by an async post-processing step).

`TUSKit` (iOS) and `tus-js-client` (browser) require **no changes** — they speak the same tus 1.0 `creation` extension protocol. The server endpoint URL and response behavior are unchanged from their perspective.

**PHP version prerequisite:**

Three PHP language features set the minimum version floor:

| Feature | Minimum |
|---|---|
| `HashContext` serialization (`hash_init` + `serialize`) | 8.0 |
| `readonly` promoted constructor properties | 8.1 |
| `readonly class` modifier (`final readonly class`) | **8.2** |

The binding constraint is PHP **8.2**. Before deploying Phase 3, verify the container PHP version:

```bash
docker exec apacheWebServer php -r 'echo PHP_VERSION . PHP_EOL;'
# Must print 8.2.x or higher; if not, update the base image before proceeding
```

Add this as a pre-task assertion in the `docker` Ansible role for the Phase 3 deploy:

```yaml
- name: "Assert PHP >= 8.2 in web container"
  community.docker.docker_container_exec:
    container: apacheWebServer
    command: >
      php -r 'exit(PHP_MAJOR_VERSION > 8 ||
              (PHP_MAJOR_VERSION === 8 && PHP_MINOR_VERSION >= 2) ? 0 : 1);'
  register: php_ver_check
  failed_when: php_ver_check.rc != 0
  tags: [php_tus_server]
```

If this assertion fails, update `ansible/roles/docker/templates/Dockerfile.j2` (or the base image tag in `docker-compose.yml.j2`) to a PHP 8.2+ image before continuing. Also update `composer.json` from `"php": ">=7.4"` to `"php": ">=8.2"` and run `composer update --ignore-platform-reqs` to verify no installed packages break.

**PHP runtime configuration requirements for `tus-upload.php`:**

`tus-upload.php` must set the following at the top of the file before any output:

```php
set_time_limit(0);   // prevent PHP killing a slow Azure PUT Block call mid-upload
ignore_user_abort(true); // continue finalizing even if client disconnects on last PATCH
```

The Apache container PHP config (`.htaccess` or `php.ini` override inside the webroot) must set:

```
php_value post_max_size 16M
```

Default `post_max_size` is 8 MB. If the tus chunk size equals `post_max_size`, PHP silently truncates the body. Setting it to 16 MB provides headroom above the default 8 MB tus chunk size. If the chunk size is increased, `post_max_size` must be updated to match.

**Security: `upload_id` is always server-generated.**

The `POST /files/` handler generates the `upload_id` server-side as a UUID v4 (`sprintf('%s-%s-%s-%s-%s', ...)` from `bin2hex(random_bytes(...))`). Any `Upload-ID` or similar header from the client is silently ignored. A client-controlled `upload_id` would allow path traversal in `/tmp/tus-staging/{upload_id}` and blob key injection. The server is the sole authority on `upload_id`.

**Apache routing change — unconditional, all deployments (`default-ssl.conf.j2`):**

```apache
# All deployments: PHP tus server handles tus protocol
# tusd container is retired — no ProxyPass to tusd
RewriteRule ^/files(/.*)?$ /api/tus-upload.php [L,QSA,E=TUS_PATH:$1]
```

The `tusd` container is retired from **all** compose files. `tusd_data` and `tus_hooks` volumes are removed from all deployments. The `post-finish` hook is not used anywhere.

**ModSecurity rule update required (`default-ssl.conf.j2`):**

The existing modsecurity `LocationMatch /files/` exception for tusd must be recreated for the PHP tus endpoint. Without this, ModSecurity's default `SecRequestBodyLimit` (13 MB) will silently truncate any PATCH body larger than ~13 MB. The rule must:

```apache
<LocationMatch "^/files/">
    # Allow large PATCH bodies for tus chunk uploads
    # Default SecRequestBodyLimit is 13 MB; raise to match max chunk size + overhead
    SecRequestBodyLimit     20971520   # 20 MB
    SecRequestBodyNoFilesLimit 20971520
    # Disable multipart body inspection — chunks arrive as raw binary, not multipart
    SecRuleEngine DetectionOnly
</LocationMatch>
```

This must be added to `default-ssl.conf.j2` as part of Phase 3, alongside the `RewriteRule` for `/files/`. Omitting it will cause all PATCH requests larger than 13 MB to be silently truncated by ModSecurity, producing corrupted uploads with no error visible to the client.

**New file: `api/tus-upload.php`** (backed by `src/Services/TusBlockUploadService.php`)

**Security: file type validation must happen at `POST /files/`, not at the final block.**

The tus `POST /files/` request carries an `Upload-Metadata` header containing the client-declared filename and content-type (e.g., `filename dmlkZW8ubXA0,filetype dmlkZW8vbXA0`). These must be validated against an allowlist **before any PATCH is accepted**. Deferring this check to the final block means the full file (potentially 4 GB) has been received by PHP and PUT to Azure before a rejection is issued.

Required checks at `POST /files/`:
1. Parse `Upload-Metadata` header — extract decoded `filename` and `filetype`
2. Validate `filetype` against allowlist: `audio/mpeg`, `audio/mp3`, `audio/wav`, `audio/aac`, `video/mp4`, `video/quicktime`, `video/webm` (extend as needed)
3. Validate `filename` extension matches `filetype` MIME family — reject mismatches
4. Reject `Upload-Length` above configured max (e.g., 4 GB) before any PATCH — return `413 Request Entity Too Large`

A MIME sniff of the actual content bytes can additionally happen at the final block (after commit) to catch spoofed `Upload-Metadata`, but the coarse type gate must be at `POST`. This preserves the validation that `finalizeTusUpload()` was performing, applied earlier in the lifecycle.

The tus server handles three request types identically for both backends:

| Method | tus purpose | Azure action | Local action |
|--------|-------------|--------------|--------------|
| `POST /files/` | Create upload | Validate metadata; allocate DB row `status=pending`; return `Location: /files/{upload_id}` | same |
| `PATCH /files/{id}` | Send chunk | Stream body → `PUT Block` to Azure; advance `Upload-Offset`; on final chunk → `PUT Block List` + DB asset row | Stream body to `/tmp/tus-staging/{id}`; on final chunk → mv to media dir + DB asset row |
| `HEAD /files/{id}` | Resume query | Return `Upload-Offset` from DB block count × block size | Return `Upload-Offset` from DB staged bytes |

**In-memory block streaming (no disk write):**

```php
// Per PATCH request:
$blockId  = base64_encode(str_pad((string)$blockIndex, 6, '0', STR_PAD_LEFT));
$body     = fopen('php://input', 'r');  // PHP input stream — no disk write

// Compute SHA256 partial hash (accumulated across all blocks)
while (!feof($body)) {
    $chunk = fread($body, 65536);
    hash_update($hashCtx, $chunk);
    // buffer chunk to array for the PUT Block body
}

// PUT Block to Azure Blob REST:
// PUT https://<account>.blob.core.windows.net/<container>/<prefix><uploadId>?comp=block&blockid=<blockId>
// Authorization: Bearer <identity_token>
// Content-Length: <chunk_size>
// Body: chunk bytes

// Store $blockId in DB row for this upload_id
```

**Final PATCH — commit:**

```php
// PUT Block List:
// PUT https://.../blob?comp=blocklist
// Body: <BlockList><Latest>block001</Latest><Latest>block002</Latest>...</BlockList>
//
// On 201 Created:
//   $checksum = hash_final($hashCtx);
//   INSERT INTO assets (...) with checksum, size, mime, file_type
//   UPDATE upload metadata to status=complete
//   Enqueue async post-processing job (ffprobe + thumbnail)
```

**How SHA256 accumulates across stateless PHP requests:**

PHP-FPM processes are stateless between requests — an in-memory `HashContext` is gone after the PATCH response is sent. The hash context must be persisted across requests. PHP 8.0+ supports serialization of `HashContext` via `serialize()`/`unserialize()`, enabling the following pattern:

```php
// At the start of each PATCH: restore context from DB
$serialized = $upload['sha256_ctx'];  // BLOB column in tus_uploads
$hashCtx = ($serialized !== null) ? unserialize($serialized) : hash_init('sha256');

// Stream the PATCH body through the hash and buffer for PUT Block:
$blockBytes = '';
while (!feof($body)) {
    $chunk = fread($body, 65536);
    hash_update($hashCtx, $chunk);
    $blockBytes .= $chunk;
}

// After successful PUT Block: persist updated context to DB
$pdo->prepare('UPDATE tus_uploads SET sha256_ctx = ?, block_count = block_count + 1 WHERE upload_id = ?')
    ->execute([serialize($hashCtx), $uploadId]);

// On final PATCH after PUT Block List:
$checksum = hash_final($hashCtx);
```

This requires PHP 8.2+ (HashContext serialization available from 8.0; `readonly class` used in the service layer requires 8.2). The GigHive Docker container PHP version must be ≥ 8.2. The `sha256_ctx` column in `tus_uploads` is a `BLOB` (up to 65,535 bytes; a serialized PHP SHA-256 `HashContext` is typically 1–2 KB — well within `BLOB` capacity, but do not use ~300 bytes as a sizing assumption in any downstream schema or monitoring alert). At commit time, `hash_final()` produces the correct SHA256 of the entire file without any disk read or second network call.

**`block_size` is set from the first PATCH body length and never changed thereafter.**

On the first PATCH (`block_count == 0`), `block_size` is populated from the actual received body length. On all subsequent PATCHes it must not be updated — the final chunk is typically smaller than `block_size` and would corrupt the `HEAD` offset calculation if written back. The UPDATE for the first PATCH:

```php
if ($upload['block_count'] === 0) {
    // Set block_size from the first chunk; all subsequent PATCHes leave it unchanged
    $pdo->prepare('UPDATE tus_uploads SET sha256_ctx = ?, block_count = 1, block_size = ?
                   WHERE upload_id = ?')
        ->execute([serialize($hashCtx), strlen($blockBytes), $uploadId]);
} else {
    $pdo->prepare('UPDATE tus_uploads SET sha256_ctx = ?, block_count = block_count + 1
                   WHERE upload_id = ?')
        ->execute([serialize($hashCtx), $uploadId]);
}
```

`HEAD` then returns `Upload-Offset = block_count * block_size` for all intermediate blocks. For a completed upload (`status=complete`), `HEAD` returns `Upload-Offset = upload_length` directly — `block_count * block_size` is not used for the final offset.

**Resumability:** Azure Blob Storage retains uncommitted blocks for 7 days. On a `HEAD` request, PHP queries the DB for the number of blocks committed so far and returns `Upload-Offset = blockCount × blockSize`. The client resumes from that offset. If the client's block size matches consistently (it always does with `TUSKit` and `tus-js-client` since chunk size is set at init), this is reliable.

**New DB tables required (`create_media_db.sql` must be updated):**

```sql
-- Upload state tracking for PHP tus server
CREATE TABLE IF NOT EXISTS tus_uploads (
    id            INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    upload_id     VARCHAR(36)   NOT NULL,          -- server-generated UUID v4; never client-provided
    user_id       INT UNSIGNED  NOT NULL,           -- authenticated user who initiated the upload
    status        ENUM('pending','complete','failed') NOT NULL DEFAULT 'pending',
    upload_length BIGINT UNSIGNED NOT NULL,         -- total file size from Upload-Length header
    block_count   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    block_size    INT UNSIGNED  NOT NULL DEFAULT 0, -- set from first PATCH body length; never updated after
    sha256_ctx    BLOB          NULL,               -- serialized HashContext (PHP 8.0+); 1-2 KB
    file_type     ENUM('audio','video') NOT NULL,
    mime_type     VARCHAR(128)  NOT NULL DEFAULT '',
    asset_id      INT UNSIGNED  NULL,               -- populated on commit
    created_at    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at    DATETIME      NOT NULL,           -- NOW() + 24h; cron clears expired rows
    PRIMARY KEY (id),
    UNIQUE KEY uq_upload_id (upload_id),
    INDEX idx_user_pending (user_id, status),      -- used by per-user rate-limit check
    INDEX idx_expires (expires_at),
    INDEX idx_status  (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Async post-processing job queue (ffprobe + thumbnail)
CREATE TABLE IF NOT EXISTS probe_jobs (
    id         INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    asset_id   INT UNSIGNED  NOT NULL,
    blob_key   VARCHAR(512)  NOT NULL,   -- e.g. video/a3f2...c1.mp4
    file_type  ENUM('audio','video') NOT NULL,
    status     ENUM('queued','running','done','failed') NOT NULL DEFAULT 'queued',
    attempts   TINYINT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    started_at DATETIME NULL,                    -- set when cron claims the row; used for stuck-job detection
    PRIMARY KEY (id),
    INDEX idx_queued  (status, created_at),
    INDEX idx_running (status, started_at)       -- used by stuck-job reset query
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

Both tables must be added to `create_media_db.sql` as part of Phase 3. A migration script must be run on all existing environments before the PHP tus server is deployed. The `tus_uploads` table is safe to create empty on running instances; no data migration required. The `probe_jobs` table is also new; existing assets already have duration and thumbnail filled, so no backfill of this table is needed.

**`tus_uploads` expired row + staging file cleanup (required):**

The `tus_uploads` table sets `expires_at = NOW() + INTERVAL 24 HOUR` on every `POST /files/`. Abandoned uploads leave rows with `status=pending` and, in local mode, a staging file at `/tmp/tus-staging/{upload_id}`. Both must be cleaned up together. Add a dedicated cleanup script invoked from `gighive-probe.cron.j2`:

```bash
#!/usr/bin/env php
<?php
// src/Jobs/cleanup_expired_uploads.php
// Deletes expired tus_uploads rows and their local staging files (if any).
// Safe in Azure mode — no staging files exist; only DB rows are removed.
require_once __DIR__ . '/../../vendor/autoload.php';
use Production\Api\Infrastructure\Database;

$pdo = Database::createFromEnv();
$stagingDir = getenv('TUS_LOCAL_STAGING_DIR') ?: '/tmp/tus-staging';

$stmt = $pdo->query(
    "SELECT upload_id FROM tus_uploads
     WHERE expires_at < NOW() AND status != 'complete'
     LIMIT 500"
);
$rows = $stmt->fetchAll(PDO::FETCH_COLUMN);

foreach ($rows as $uploadId) {
    $stagingPath = rtrim($stagingDir, '/') . '/' . $uploadId;
    if (file_exists($stagingPath)) {
        @unlink($stagingPath);  // best-effort; missing file is not an error
    }
}

if (!empty($rows)) {
    $ids = implode(',', array_fill(0, count($rows), '?'));
    $pdo->prepare("DELETE FROM tus_uploads WHERE upload_id IN ($ids)")->execute($rows);
}
exit(0);
```

Cron entry in `gighive-probe.cron.j2`:

```
# Clean up expired tus upload rows and local staging files daily at 03:00
0 3 * * * www-data php /var/www/html/src/Jobs/cleanup_expired_uploads.php >> /var/log/probe_job.log 2>&1
```

The `LIMIT 500` prevents long-running deletes that block PATCH transactions during peak hours. In Azure mode, uncommitted blocks expire in Blob Storage automatically after 7 days — no Blob-side cleanup is needed. The script is safe to run in Azure mode (staging file deletion is a no-op when the directory is empty).

Add `src/Jobs/cleanup_expired_uploads.php` to the Files Under Change → New list.

**Azure Block Blob constraint — enforce at POST:**

Azure allows a maximum of 50,000 blocks per blob. At the default 8 MB chunk size this gives a maximum file size of ~390 GB — effectively unlimited for GigHive. If chunk size is reduced, verify `50,000 × chunkSize >= maxAllowedFileSize`. `TusBlockUploadService::handlePost()` must assert this at upload creation time:

```php
// At POST /files/ — after Upload-Length is validated against maxFileSizeBytes:
$maxChunkSize = $this->config->maxFileSizeBytes;    // upper bound for any single chunk
$maxBlocks    = 50_000;
if (ceil($uploadLength / $maxChunkSize) > $maxBlocks) {
    // In practice unreachable at 8 MB chunks + 4 GB max file size, but assert defensively
    http_response_code(413);
    echo json_encode(['error' => 'File too large for Azure Block Blob block limit']);
    exit;
}
```

Add this check to `handlePost()` acceptance criteria in Phase 3.

**Concurrent PATCH protection:** tus clients are single-threaded per upload; however a mobile reconnect scenario can produce two PATCH requests for the same upload ID in flight simultaneously. PHP must protect against this or risk offset corruption and duplicate block IDs. At the start of every PATCH handler:

```php
// Acquire exclusive lock on the upload row before touching offset or block list
$pdo->beginTransaction();
$stmt = $pdo->prepare('SELECT id, status, block_count, upload_length, block_size FROM tus_uploads WHERE upload_id = ? FOR UPDATE');
$stmt->execute([$uploadId]);
$upload = $stmt->fetch();

if (!$upload) {
    $pdo->rollBack();
    http_response_code(404); exit;
}
if ($upload['status'] === 'complete') {
    $pdo->rollBack();
    http_response_code(204);
    header('Upload-Offset: ' . $upload['upload_length']);
    exit;
}
// ... proceed with PUT Block; commit transaction after DB block_count update
```

The `FOR UPDATE` lock ensures that if two PATCH requests arrive concurrently, the second blocks at the DB level until the first completes. **The transaction is committed immediately after both the `block_count` and `sha256_ctx` UPDATE, before the response is sent.**

**Lock window acknowledgment:** The transaction (and row lock) is held for the full duration of the Azure `PUT Block` REST call — potentially 2–15 seconds for an 8 MB chunk over variable-bandwidth network. A second concurrent PATCH will block at the MySQL lock until the first commits. MySQL's default `innodb_lock_wait_timeout` is 50 seconds. At GigHive's current scale (admin-only uploads, no simultaneous multi-user scenario for the same file), this is an acceptable tradeoff for correctness simplicity over non-blocking optimistic concurrency.

**`innodb_lock_wait_timeout` pre-deployment check (required for Phase 3):**

Add this as an Ansible pre-task assertion before deploying Phase 3, alongside the PHP version check:

```yaml
- name: "Assert innodb_lock_wait_timeout >= 60 for tus PATCH lock window"
  community.docker.docker_container_exec:
    container: apacheWebServer
    command: >
      php -r "
        $pdo = new PDO('mysql:host=' . getenv('DB_HOST') . ';dbname=' . getenv('DB_NAME'),
                       getenv('DB_USER'), getenv('DB_PASS'));
        $v = (int)$pdo->query('SELECT @@innodb_lock_wait_timeout')->fetchColumn();
        exit(\$v >= 60 ? 0 : 1);
      "
  register: lock_timeout_check
  failed_when: lock_timeout_check.rc != 0
  tags: [php_tus_server]
```

If this fails, add `innodb_lock_wait_timeout = 60` to the MySQL `[mysqld]` config (deployed by the `mysql` Ansible role) and restart before proceeding.

**`apcu.enable_cli=1` pre-deployment check (required for Phase 3):**

`AzureIdentityTokenCache` uses APCu to cache IMDS tokens. APCu is disabled for CLI by default in most PHP installations. Without `apcu.enable_cli=1`, every invocation of `run_probe_job.php` from cron fetches a fresh IMDS token — not fatal, but generates unnecessary IMDS traffic. Add a pre-deployment assertion and ensure the PHP container's `php-cli.ini` (or `php.ini` if shared) has this setting:

```yaml
- name: "Assert apcu.enable_cli=1 for probe job cron token caching"
  community.docker.docker_container_exec:
    container: apacheWebServer
    command: >
      php -r "exit(ini_get('apc.enable_cli') === '1' ? 0 : 1);"
  register: apcu_cli_check
  failed_when: apcu_cli_check.rc != 0
  tags: [php_tus_server]
```

If this fails, add `apc.enable_cli=1` to `/etc/php/8.2/cli/conf.d/` (or the equivalent path for the container's PHP CLI config) via the `docker` role template.

Also add to the Phase 3 pre-deployment assertions line in the Progress checklist: `apcu.enable_cli=1`.

**Async post-processing job (ffprobe and thumbnail):**

ffprobe requires a local file path. With direct Blob streaming, no local file ever exists. The solution is a DB-backed job queue polled by a cron job on the VM.

**Delivery mechanism:** `probe_jobs` table (schema above) + cron entry in the Apache container (or VM host cron via Ansible):

```
# /etc/cron.d/gighive-probe  (deployed by ansible/roles/docker role)
* * * * * www-data php /var/www/html/src/Jobs/run_probe_job.php >> /var/log/probe_job.log 2>&1
* * * * * www-data sleep 10 && php /var/www/html/src/Jobs/run_probe_job.php >> /var/log/probe_job.log 2>&1
* * * * * www-data sleep 20 && php /var/www/html/src/Jobs/run_probe_job.php >> /var/log/probe_job.log 2>&1
* * * * * www-data sleep 30 && php /var/www/html/src/Jobs/run_probe_job.php >> /var/log/probe_job.log 2>&1
* * * * * www-data sleep 40 && php /var/www/html/src/Jobs/run_probe_job.php >> /var/log/probe_job.log 2>&1
* * * * * www-data sleep 50 && php /var/www/html/src/Jobs/run_probe_job.php >> /var/log/probe_job.log 2>&1
```

This gives ~10-second polling cadence without a process manager. `run_probe_job.php` claims one row via `SELECT ... FOR UPDATE` to avoid double-claim, processes it, marks `done` or `failed`.

**Retry cap and stuck-job recovery** — `run_probe_job.php` must perform these two steps at startup before claiming a new row:

```php
// 1. Reset jobs stuck in 'running' for > 10 minutes (process crash recovery)
//    Use started_at (not created_at) — a job may have sat queued for a long time
//    before being claimed. created_at would incorrectly reset recently-claimed jobs.
$pdo->prepare("UPDATE probe_jobs SET status = 'queued', started_at = NULL
               WHERE status = 'running' AND started_at < NOW() - INTERVAL 10 MINUTE")
    ->execute();

// 2. Permanently fail jobs that have exceeded the retry cap
$pdo->prepare("UPDATE probe_jobs SET status = 'failed'
               WHERE status = 'queued' AND attempts >= 3")
    ->execute();
```

After these resets, claim one remaining `queued` row. Jobs that fail three times are left in `status='failed'` for manual inspection.

**Manual re-trigger query** (for operators and admin tooling):

```sql
-- Re-queue a specific failed job by asset_id
UPDATE probe_jobs SET status = 'queued', attempts = 0 WHERE asset_id = ? AND status = 'failed';

-- Show current queue state
SELECT status, COUNT(*) as n FROM probe_jobs GROUP BY status;
```

Add a "Probe job queue" row to the Phase 10 admin tooling table so the queue state is visible from the admin UI.

**Log rotation:** `/var/log/probe_job.log` must be rotated. Add a `logrotate` config via Ansible (e.g., `ansible/roles/docker/templates/logrotate-probe.j2`):

```
/var/log/probe_job.log {
    daily
    rotate 7
    compress
    missingok
    notifempty
}
```

Without rotation this file grows at ~6 runs/minute × log lines/run and will eventually fill the OS disk.

**Rate limiting note:** The `POST /files/` endpoint is auth-gated, limiting upload creation to authenticated users. There is no per-user concurrency limit. An authenticated user could create many concurrent pending uploads, filling `tus_uploads` and (in Azure mode) creating many uncommitted Blob containers that persist for 7 days. This is a low-priority risk for GigHive's current scale but should be revisited if the platform grows. A pragmatic mitigation is a DB check at `POST /files/`: `SELECT COUNT(*) FROM tus_uploads WHERE user_id = ? AND status = 'pending' AND expires_at > NOW()` — reject if count exceeds a configured threshold (e.g., 5 concurrent pending uploads per user).

```
On blob commit (inside TusBlockUploadService::handleFinalPatch()):
  → INSERT INTO assets (...) with checksum, size, mime, file_type (duration=NULL, thumbnail=NULL)
  → INSERT INTO probe_jobs (asset_id, blob_key, file_type, status) VALUES (?, ?, ?, 'queued')
  → UPDATE tus_uploads SET status='complete', asset_id=? WHERE upload_id=?
  → Respond 204 to final PATCH immediately

Background job (run_probe_job.php, runs within ~10s on same VM):
  → Claim one queued row (SELECT ... FOR UPDATE / UPDATE status='running', started_at=NOW())
  → Azure: GET blob → stream to /tmp/{asset_id}.{ext}
  → Local: fopen existing local media file path
  → ffprobe → extract duration_seconds, media_info_json
  → If video: ffmpeg thumbnail seek → /tmp/{asset_id}_thumb.png
    → Azure: PUT thumbnail blob → update assets.thumbnail_blob_key
    → Local: mv thumbnail to thumbnails/ dir
  → UPDATE assets SET duration_seconds=?, media_info_json=?, ... WHERE id=?
  → UPDATE probe_jobs SET status='done' WHERE id=?
  → unlink(/tmp/{asset_id}.{ext}) and temp thumbnail
```

The temp file in this step is short-lived (seconds) and lives in `/tmp`, not in the `tusd_data` volume or webroot. It is only as large as needed for probing — and if thumbnails are generated from a single frame seek, ffprobe can often probe duration with a very small initial read rather than downloading the full file.

**UX implication:** Duration and thumbnail are not available in the finalize API response. They appear in the DB within seconds of the upload completing. The iOS app and any admin UI that displays these fields must tolerate `null` duration and absent thumbnail until the async job completes. This is the standard behavior on all media platforms (YouTube, Vimeo, etc.).

**`POST /api/uploads/finalize` endpoint update:**

The existing `/api/uploads/finalize` endpoint (routed through `UploadController.php` → `UploadService.php`) previously called `finalizeTusUpload()` which polled for the `tus_hooks` volume notification file. That function is retired. In the new model the upload is already committed in DB by the time the client calls this endpoint. The replacement logic is:

```php
// New finalizeTusUpload() implementation (or renamed method):
$stmt = $pdo->prepare(
    'SELECT asset_id FROM tus_uploads WHERE upload_id = ? AND status = ?'
);
$stmt->execute([$uploadId, 'complete']);
$row = $stmt->fetch();
if (!$row) {
    http_response_code(202);  // upload still pending (rare: client called too fast)
    echo json_encode(['status' => 'pending']);
    exit;
}
// Fetch and return asset row as before
$asset = fetchAssetById($row['asset_id']);
http_response_code(201);
echo json_encode($asset);
exit;
```

No polling loop. No hook file. The client may call this endpoint immediately after the final 204; if the DB write hasn't committed yet (very rare sub-millisecond race), a 202 signals the client to retry after a short delay. `UploadController.php` is listed as **Unchanged** — the controller signature is unaffected; only the service implementation changes.

**Failure handling:**

| Failure point | Behavior |
|---|---|
| `PUT Block` fails mid-upload | Azure returns non-201; PHP returns `500` to client; tus client retries the PATCH from the last committed offset; uncommitted blocks on Azure expire in 7 days |
| `PUT Block List` (commit) fails | No blob committed; DB asset row not written; upload marked failed; client may retry from last offset |
| DB write fails after blob commit | Blob committed as orphan; DB row not written; reconciliation tool detects blob with no DB record |
| Async ffprobe job fails | Asset is fully accessible; duration and thumbnail remain null; admin can re-trigger probe job |
| Async temp file not deleted | File in `/tmp`, not in webroot or tusd volume; standard tmpwatch cleanup handles it |

**Smoke test requirement for `tus-upload.php`:** `post_build_checks/tasks/main.yml` must include:

```yaml
- name: "[smoke] tus-upload.php returns 400 on plain GET (not a tus request)"
  ansible.builtin.uri:
    url: "https://{{ ansible_host }}/files/"
    method: GET
    status_code: 400
    validate_certs: false
  tags: [smoke]

- name: "[smoke] tus-upload.php returns 401 on unauthenticated POST without tus headers"
  ansible.builtin.uri:
    url: "https://{{ ansible_host }}/files/"
    method: POST
    status_code: 401
    validate_certs: false
  tags: [smoke]
```

These two checks verify: (1) the PHP tus server is live and responding (not a 404 or 502 from a missing container), and (2) auth is enforced before any tus processing. Both are non-destructive and run on every deploy.

#### SonarQube / Best-Practice Notes — Phase 3

- **RSPEC-3776 (cognitive complexity):** The PATCH handler (lock → validate → stream → PUT Block → hash → update DB → optionally commit) must be decomposed: `handlePost()`, `handlePatch()`, `handleHead()` at the top level; each delegates to private `acquireUploadLock()`, `streamBlockToAzure()`, `persistHashContext()`, `commitBlobAndInsertAsset()`. No single method should exceed 20 statements.
- **RSPEC-6426 (null dereference):** All `$upload[...]` accesses after the `FOR UPDATE` fetch must guard against a missing row (upload expired, double-delete). Check `if (!$upload)` immediately after fetch and return 404 before any further access.
- **RSPEC-107 (too many parameters):** `TusBlockUploadService` constructor will have many dependencies (DB, blob account, container, prefixes, identity client). Group into a `TusUploadConfig` value object.
- **Block ID format:** `base64_encode(str_pad((string)$blockIndex, 6, '0', STR_PAD_LEFT))` is the correct Azure Block Blob block ID format. Block IDs must be consistent length within a blob. Assert that `strlen(base64_encode(str_pad(...))) === 8` in a unit test.

#### Phase 3 Rollback Plan

Phase 3 retires tusd from every compose template unconditionally. There is no env var toggle that re-enables tusd — the rollback requires a code change and re-deploy.

**If a critical defect is found after Phase 3 deploys:**

1. Take a VM snapshot before Phase 3 deploy begins (add this to the Phase 3 deploy runbook)
2. If rollback is required: restore the snapshot, or:
   a. Revert the tusd removal commit in `docker-compose.yml.j2`
   b. Revert the Apache `ProxyPass` routing change in `default-ssl.conf.j2`
   c. Re-run Ansible on affected environments
3. `tus_uploads` and `probe_jobs` tables can remain — they will be empty and harmless during tusd operation
4. The `GIGHIVE_MEDIA_STORAGE_BACKEND=local` env var can remain set — the PHP service layer is a no-op until traffic reaches it

The most vulnerable window is the first deploy on a live environment. Deploy to `devvm` first and hold for a minimum of one full upload-test cycle before promoting to `stagingvm` or `prod`.

---

### Phase 4 — Application-mediated media streaming

**Goal:** Replace static Apache file serving with PHP-proxied streaming so Apache remains the only public surface and Blob stays private.

**New endpoint:** `api/media-stream.php`

This endpoint handles:
- `/media/audio/{key}` → streams audio blob
- `/media/video/{key}` → streams video blob
- `/media/video/thumbnails/{key}` → streams thumbnail blob

**Backward-compatible URL routing (required):**

Existing media URLs stored in the DB and cached by iOS/browser clients use the old static paths `/audio/{key}` and `/video/{key}`. These must continue to work after Phase 4 deploys. Apache must route the old paths to `media-stream.php` alongside the new `/media/` prefix:

```apache
# New canonical paths (all deployments)
RewriteRule ^/media/(audio|video)/thumbnails/(.+)$ /api/media-stream.php [L,QSA,E=MEDIA_TYPE:video/thumbnails,E=MEDIA_KEY:$2]
RewriteRule ^/media/(audio|video)/(.+)$            /api/media-stream.php [L,QSA,E=MEDIA_TYPE:$1,E=MEDIA_KEY:$2]

# Backward-compat aliases — existing stored URLs continue to work without a data migration
RewriteRule ^/(audio|video)/thumbnails/(.+)$       /api/media-stream.php [L,QSA,E=MEDIA_TYPE:video/thumbnails,E=MEDIA_KEY:$2]
RewriteRule ^/(audio|video)/(.+)$                  /api/media-stream.php [L,QSA,E=MEDIA_TYPE:$1,E=MEDIA_KEY:$2]
```

Both old and new paths resolve to the same `media-stream.php` handler. `media-stream.php` extracts `MEDIA_TYPE` and `MEDIA_KEY` from the request environment variables (or from the URI path as a fallback). No DB migration of stored asset URLs is required.

**API URL standardization:** New asset records written after Phase 4 should have their `asset_url` (or equivalent field) populated using the `/media/` prefix. Existing records with `/audio/` or `/video/` URLs remain valid via the backward-compat rules above and do not need rewriting. The read path is identical regardless of which prefix was used.

**Phase 11 step 10 prerequisite:** Before removing host media bind mounts, verify that both `/audio/{key}` and `/media/audio/{key}` return the correct bytes and correct `Content-Type` headers for a test asset.

**Thumbnail authentication:** Browser `<img>` tags and iOS `UIImageView` do not send auth headers. If thumbnails are served under `/media/video/thumbnails/` with the same auth gate as audio and video, they will fail to load in any `<img>` context. GigHive's admin UI uses session-cookie-based auth, which the browser sends automatically for same-origin `<img>` requests — thumbnails will load correctly in the browser admin panel via this mechanism. For iOS, thumbnail fetch should be done via authenticated `URLSession` (not `UIImageView` with a plain URL) to send session credentials. Document this assumption before deploying Phase 4 to avoid a silent thumbnail breakage in the iOS UI.

The endpoint must:
1. Validate `{key}` format against a strict regex before any blob operation
2. Authorize the request (same session-cookie or token auth as other API endpoints; applies to audio, video, and thumbnails uniformly)
3. Call `MediaStorageService::getMeta()` to get `Content-Type`, `Content-Length`, `ETag`
4. Parse `Range` header if present
5. Call `MediaStorageService::getRangeStream()` or `getStream()`
6. Set response headers and stream bytes to client

**Required response headers:**

```php
header('Content-Type: ' . $meta->contentType);
header('Content-Length: ' . $rangeLength);
header('Accept-Ranges: bytes');
header('ETag: "' . $meta->etag . '"');
header('Cache-Control: private, max-age=3600');
if ($isRange) {
    http_response_code(206);
    header('Content-Range: bytes ' . $start . '-' . $end . '/' . $meta->size);
} else {
    http_response_code(200);
}
```

**Range request handling:**

```php
$rangeHeader = $_SERVER['HTTP_RANGE'] ?? null;
if ($rangeHeader && preg_match('/^bytes=(\d+)-(\d*)$/', $rangeHeader, $m)) {
    $start = (int)$m[1];
    $end   = $m[2] !== '' ? (int)$m[2] : $meta->size - 1;
    $end   = min($end, $meta->size - 1);
    if ($start > $end || $start >= $meta->size) {
        http_response_code(416);
        header('Content-Range: bytes */' . $meta->size);
        exit;
    }
    $isRange = true;
} else {
    $start   = 0;
    $end     = $meta->size - 1;
    $isRange = false;
}
```

The range is forwarded to `AzureBlobMediaBackend::getRangeStream()` which sets the `Range: bytes=X-Y` header on the Blob REST GET request. Azure Blob Storage natively supports byte-range reads; this is not a full-download-then-slice approach.

**Smoke test requirement:** `post_build_checks/tasks/main.yml` must include:

```yaml
- name: "[smoke] media-stream.php returns 401 without auth"
  ansible.builtin.uri:
    url: "https://{{ ansible_host }}/api/media-stream.php"
    method: GET
    status_code: 401
    validate_certs: false
  tags: [smoke]
```

**iOS thumbnail authentication — acceptance criterion for Phase 11 step 9:**

Browser `<img>` tags and `UIImageView` do not send credentials. Thumbnails are served under the same auth gate as audio and video. The expected behavior by context:

| Context | How auth is satisfied |
|---|---|
| Browser admin panel `<img src="/media/video/thumbnails/...">` | Session cookie sent automatically (same-origin request) — works |
| iOS `UIImageView` with a plain URL string | No credential sent — **will return 401** |
| iOS `URLSession` with session cookie / token header | Credential sent — works |

Before proceeding to Phase 11 step 10, verify explicitly:
1. Load the admin panel in a browser — confirm thumbnails render in `<img>` tags (cookie auth)
2. In the iOS app, confirm thumbnails load via `URLSession` (not `UIImageView` with a raw URL)
3. If any iOS code uses `UIImageView` + plain URL for thumbnails, update it to use `URLSession` + auth headers before deploying Phase 4

Add this as a checklist item in Phase 11 step 9.

#### SonarQube / Best-Practice Notes — Phase 4

- **RSPEC-3776 (cognitive complexity):** `media-stream.php` must decompose into `validateKey()`, `authenticateRequest()`, `parseRangeHeader()`, `buildStreamResponse()`. The main file should read as a sequence of four calls with early exits, not nested conditionals.
- **RSPEC-6426 (null dereference):** `$_SERVER['HTTP_RANGE']` is `string|undefined`; always use `?? null` before passing to the regex. Never pass an unvalidated header string to `header()`.
- **Range edge case:** A request with `Range: bytes=0-` (open-ended) is valid and must resolve `$end = $meta->size - 1`. The regex `^bytes=(\d+)-(\d*)$` already handles this via `$m[2] !== ''` check; verify in integration test.

---

### Phase 5 — Local / VirtualBox / Baremetal (Tranche 1 final step)

Once Azure (Phase 11) is confirmed stable:

1. `LocalFileTusBackend` is already deployed and running from Phase 3 — no new upload code
2. Build `LocalMediaBackend` for `MediaStorageService` (PHP-mediated read path using `fopen`/`fread`)
3. Deploy `api/media-stream.php` with `LocalMediaBackend` to local/VirtualBox inventories
4. Verify read path (full file, range seek) via PHP for local environments
5. Disable Apache static serving of the media paths — `LocalMediaBackend` still reads files from `/var/www/html/audio|video/` via `fopen`/`fread`. The bind mounts must **remain** in the local-mode compose; they are the mechanism that makes the host media directory visible inside the container, and PHP depends on them. The only change at this step is that the new `RewriteRule` routes for `/audio/` and `/video/` (added in Phase 4) take precedence over Apache's static handler, so requests go through PHP instead of being served as static files. No compose change is needed at this step.
6. Retire `MEDIA_SEARCH_DIRS` from `.env.j2` — replaced by the `media-stream.php` `LocalMediaBackend` configuration (`MEDIA_SEARCH_DIR_AUDIO` / `MEDIA_SEARCH_DIR_VIDEO` set from Ansible group vars)

**Rollback per phase:**

| Phase | Rollback |
|---|---|
| PHP tus server (Phase 11 step 2) | Revert Apache config to `ProxyPass` tusd; redeploy tusd container temporarily |
| Terraform Phase 6 | Re-apply with `public_network_access_enabled = true`; remove private endpoint resources |
| Azure storage backend switch | Set `gighive_media_storage_backend: "local"` in group vars; redeploy |
| Local bind mount removal (Phase 5 step 5) | Restore bind mount lines in compose; redeploy |

#### Validation Checklist — Phase 5

*5 of 7 automated (`post_build_checks`); 2 manual. Prerequisite group_var: `smoke_test_audio_sha256` — SHA-256 of a known audio asset present on the VirtualBox host media directory, used by T-65.*

---

**T-64 [post_build_checks]** — `/media/audio/` returns 401 (not 403/500) — `LocalMediaBackend` is live and enforcing auth.

```yaml
# Add to post_build_checks/tasks/main.yml
- name: "[T-64] /media/audio/ returns 401 without auth (LocalMediaBackend live)"
  ansible.builtin.uri:
    url: "{{ gighive_base_url }}/media/audio/"
    method: GET
    validate_certs: "{{ gighive_validate_certs }}"
    status_code: [400, 401, 404]
    headers: "{{ {'Host': gighive_hostname_for_host_header} if (gighive_hostname_for_host_header | length) > 0 else omit }}"
  changed_when: false
  when: gighive_media_storage_backend == 'local'
  tags: [smoke, media_storage]
```

**T-65 [post_build_checks]** — Range request to a known audio asset returns 206 with correct `Content-Range` header.

```yaml
# Add to post_build_checks/tasks/main.yml
- name: "[T-65] Range request returns 206 for known audio asset (local mode)"
  ansible.builtin.uri:
    url: "{{ gighive_base_url }}/media/audio/{{ smoke_test_audio_sha256 }}.mp3"
    method: GET
    url_username: "{{ uploader_user }}"
    url_password: "{{ gighive_uploader_password }}"
    force_basic_auth: yes
    validate_certs: "{{ gighive_validate_certs }}"
    headers:
      Range: "bytes=0-4095"
      Host: "{{ gighive_hostname_for_host_header if (gighive_hostname_for_host_header | length) > 0 else omit }}"
    status_code: [206]
  register: range_response
  changed_when: false
  when:
    - gighive_media_storage_backend == 'local'
    - smoke_test_audio_sha256 is defined
  tags: [smoke, media_storage]

- name: "[T-65] Assert Content-Range header present in 206 response"
  ansible.builtin.assert:
    that:
      - range_response.content_range is defined
      - range_response.content_range is match("^bytes 0-4095/")
    fail_msg: >
      Content-Range header missing or incorrect.
      Got: {{ range_response.content_range | default('none') }}
  when:
    - gighive_media_storage_backend == 'local'
    - smoke_test_audio_sha256 is defined
  tags: [smoke, media_storage]
```

**T-66 [post_build_checks]** — Direct `/audio/` request returns 401 (PHP-mediated, not Apache static file serving).

```yaml
# Add to post_build_checks/tasks/main.yml
# /audio/ now routes through media-stream.php RewriteRule → 401 without auth
# A 403 would indicate Apache is still serving it as a static directory listing
- name: "[T-66] /audio/ returns 401 — PHP handler active, not Apache static serving"
  ansible.builtin.uri:
    url: "{{ gighive_base_url }}/audio/test.mp3"
    method: GET
    validate_certs: "{{ gighive_validate_certs }}"
    status_code: [401]
    headers: "{{ {'Host': gighive_hostname_for_host_header} if (gighive_hostname_for_host_header | length) > 0 else omit }}"
  changed_when: false
  when: gighive_media_storage_backend == 'local'
  tags: [smoke, media_storage]
```

**T-67 [post_build_checks]** — Audio and video bind mounts are present in local mode. *(Covered by T-12.)*

**T-68 [post_build_checks]** — `MEDIA_SEARCH_DIRS` env var is absent from the container (retired).

```yaml
# Add to post_build_checks/tasks/main.yml
- name: "[T-68] MEDIA_SEARCH_DIRS env var is not set in container (retired)"
  ansible.builtin.command: >
    docker exec -i "{{ apache_container_name }}" printenv MEDIA_SEARCH_DIRS
  register: media_search_dirs_check
  changed_when: false
  failed_when: media_search_dirs_check.rc == 0
  when: gighive_media_storage_backend == 'local'
  tags: [smoke, media_storage]
  # rc=1 means the var is not set — which is the expected (passing) state
```

**T-69 [Manual]** — Full regression pass on a VirtualBox deployment: audio upload, video upload, full-file playback, range seek, thumbnail in admin panel.
> Covers Phase 2–4 acceptance criteria (Build Order items 1–13) on local backends end-to-end.

**T-70 [Manual]** — iOS `TUSKit` upload completes via `LocalFileTusBackend`; probe job runs; asset appears in the app.
> Requires a real iOS device or simulator with the GigHive app pointed at the VirtualBox host.

---

### Phase 7 — Thumbnails and derived media into Blob Storage

**Goal:** Store thumbnails in Blob alongside primary media under the agreed key convention.

**Blob key layout:**

```
audio/<sha256>.<ext>         e.g. audio/a3f2...c1.mp3
video/<sha256>.<ext>         e.g. video/b9e1...d4.mp4
video/thumbnails/<sha256>.png
```

This aligns with the key scheme already used by the existing import/export helpers in `import_media_zip.php` (it already parses `video/`, `audio/`, and `video/thumbnails/` prefixes from blob paths).

**Thumbnail generation is part of the async post-processing job defined in Phase 3.** There is no longer a separate "thumbnail step during finalization." The sequence is:

1. Blob committed by PHP Block Blob server (Phase 3)
2. Async job runs: downloads blob to `/tmp` temp file → `ffprobe` + `ffmpeg` generate thumbnail → PUT thumbnail blob → update DB `thumbnail_blob_key` → delete temp file

At no point is a thumbnail or its source video written to the `tusd_data` volume, webroot, or any persistent VM path.

#### Validation Checklist — Phase 7

*7 of 8 automated (`post_build_checks` / `validate_app`); 1 manual. Tests T-14–T-19 and T-21 require at least one video and one audio asset to have been processed by the probe job — gate with a `when` condition checking row count.*

*Prerequisite group_var: `smoke_test_video_sha256` — set this to the SHA-256 of any known video asset that has completed the probe job on the target environment. Used by T-15, T-16, T-17, T-19.*

---

**T-14 [validate_app]** — Most recent completed video probe job has `thumbnail_blob_key` set.

```yaml
# Add to validate_app/tasks/main.yml
- name: "[T-14] Query count of video assets with thumbnail_blob_key set"
  ansible.builtin.command: >
    docker exec -i "{{ mysql_container_name }}"
      sh -lc 'mysql -uroot -p"{{ mysql_root_password }}" media_db -sN -e
        "SELECT COUNT(*) FROM assets a
         JOIN probe_jobs p ON p.asset_id = a.id
         WHERE a.file_type = ''video''
           AND p.status = ''done''
           AND a.thumbnail_blob_key IS NOT NULL"'
  register: thumb_key_count
  changed_when: false
  no_log: true
  when: gighive_media_storage_backend == 'azure_blob'
  tags: [media_storage]

- name: "[T-14] Assert at least one video asset has thumbnail_blob_key"
  ansible.builtin.assert:
    that:
      - thumb_key_count.stdout | trim | int > 0
    fail_msg: >
      No video assets have thumbnail_blob_key set after probe job completion.
      Check MediaProbeJobService thumbnail upload step.
  when: gighive_media_storage_backend == 'azure_blob'
  tags: [media_storage]
```

**T-15 [validate_app]** — Thumbnail blob exists in storage at the key recorded in `assets`.

```yaml
# Add to validate_app/tasks/main.yml
- name: "[T-15] Thumbnail blob exists at expected key in Blob storage"
  ansible.builtin.command: >
    docker exec -i "{{ apache_container_name }}"
      sh -lc 'php -r "
        require_once \"/var/www/html/vendor/autoload.php\";
        \$s = \Production\Api\Services\MediaStorageService::make();
        echo \$s->exists(\"video/thumbnails/{{ smoke_test_video_sha256 }}.png\") ? \"1\" : \"0\";
      "'
  register: thumb_blob_exists
  changed_when: false
  when:
    - gighive_media_storage_backend == 'azure_blob'
    - smoke_test_video_sha256 is defined
  tags: [media_storage]

- name: "[T-15] Assert thumbnail blob exists"
  ansible.builtin.assert:
    that:
      - thumb_blob_exists.stdout | trim == "1"
    fail_msg: "Thumbnail blob not found for video/thumbnails/{{ smoke_test_video_sha256 }}.png"
  when:
    - gighive_media_storage_backend == 'azure_blob'
    - smoke_test_video_sha256 is defined
  tags: [media_storage]
```

**T-16 [validate_app]** — Downloaded thumbnail is a valid PNG file.

**T-17 [validate_app]** — Thumbnail dimensions are non-zero. *(Runs as part of the same task block as T-16.)*

```yaml
# Add to validate_app/tasks/main.yml
- name: "[T-16/T-17] Download thumbnail blob and verify PNG validity and dimensions"
  ansible.builtin.command: >
    docker exec -i "{{ apache_container_name }}"
      sh -lc '
        php -r "
          require_once \"/var/www/html/vendor/autoload.php\";
          \$s = \Production\Api\Services\MediaStorageService::make();
          \$tmp = sys_get_temp_dir() . \"/smoke_thumb_{{ smoke_test_video_sha256 }}.png\";
          file_put_contents(\$tmp, stream_get_contents(\$s->streamRaw(\"video/thumbnails/{{ smoke_test_video_sha256 }}.png\")));
          \$info = getimagesize(\$tmp);
          unlink(\$tmp);
          if (!\$info || \$info[0] < 1 || \$info[1] < 1 || \$info[2] !== IMAGETYPE_PNG) {
            fwrite(STDERR, \"Invalid PNG or zero dimensions\");
            exit(1);
          }
          echo \$info[0] . \"x\" . \$info[1];
        "
      '
  register: thumb_dimensions
  changed_when: false
  when:
    - gighive_media_storage_backend == 'azure_blob'
    - smoke_test_video_sha256 is defined
  tags: [media_storage]

- name: "[T-16/T-17] Assert thumbnail is valid PNG with non-zero dimensions"
  ansible.builtin.assert:
    that:
      - thumb_dimensions.rc == 0
      - thumb_dimensions.stdout | regex_search('^\d+x\d+$') is not none
    fail_msg: "Thumbnail PNG invalid or zero dimensions. Output: {{ thumb_dimensions.stdout }} {{ thumb_dimensions.stderr }}"
  when:
    - gighive_media_storage_backend == 'azure_blob'
    - smoke_test_video_sha256 is defined
  tags: [media_storage]
```

**T-18 [post_build_checks]** — No thumbnail files persist on VM disk in Azure mode.

```yaml
# Add to post_build_checks/tasks/main.yml
- name: "[T-18] Count thumbnail files on VM disk (should be 0 in Azure mode)"
  ansible.builtin.command: >
    docker exec -i "{{ apache_container_name }}"
      sh -lc 'find /var/www/html/video/thumbnails/ -type f 2>/dev/null | wc -l'
  register: disk_thumb_count
  changed_when: false
  failed_when: false
  when: gighive_media_storage_backend == 'azure_blob'
  tags: [smoke, media_storage]

- name: "[T-18] Assert no thumbnail files on VM disk (Azure mode)"
  ansible.builtin.assert:
    that:
      - disk_thumb_count.stdout | trim == "0"
    fail_msg: >
      {{ disk_thumb_count.stdout | trim }} thumbnail file(s) found on VM disk in Azure mode.
      Probe job may be writing to disk instead of Blob.
  when: gighive_media_storage_backend == 'azure_blob' and disk_thumb_count.rc == 0
  tags: [smoke, media_storage]
```

**T-19 [post_build_checks]** — `GET /media/video/thumbnails/{sha256}.png` returns 200 with `Content-Type: image/png`.

```yaml
# Add to post_build_checks/tasks/main.yml
- name: "[T-19] Thumbnail served via media-stream.php returns 200 image/png"
  ansible.builtin.uri:
    url: "{{ gighive_base_url }}/media/video/thumbnails/{{ smoke_test_video_sha256 }}.png"
    method: GET
    url_username: "{{ uploader_user }}"
    url_password: "{{ gighive_uploader_password }}"
    force_basic_auth: yes
    validate_certs: "{{ gighive_validate_certs }}"
    status_code: [200]
    headers: "{{ {'Host': gighive_hostname_for_host_header} if (gighive_hostname_for_host_header | length) > 0 else omit }}"
  register: thumb_http
  changed_when: false
  when:
    - gighive_media_storage_backend == 'azure_blob'
    - smoke_test_video_sha256 is defined
  tags: [smoke, media_storage]

- name: "[T-19] Assert thumbnail Content-Type is image/png"
  ansible.builtin.assert:
    that:
      - "'image/png' in (thumb_http.content_type | default(''))"
    fail_msg: "Expected Content-Type: image/png, got: {{ thumb_http.content_type | default('none') }}"
  when:
    - gighive_media_storage_backend == 'azure_blob'
    - smoke_test_video_sha256 is defined
  tags: [smoke, media_storage]
```

**T-20 [Manual]** — Thumbnail loads in the browser admin panel.
> Requires a real browser session with cookie authentication. The `uri` module cannot replicate session-cookie auth for the admin panel's same-origin `<img>` requests.

**T-21 [validate_app]** — Audio probe job completes with `duration_seconds` set but `thumbnail_blob_key` remains null.

```yaml
# Add to validate_app/tasks/main.yml
- name: "[T-21] Audio assets have no thumbnail_blob_key after probe job"
  ansible.builtin.command: >
    docker exec -i "{{ mysql_container_name }}"
      sh -lc 'mysql -uroot -p"{{ mysql_root_password }}" media_db -sN -e
        "SELECT COUNT(*) FROM assets a
         JOIN probe_jobs p ON p.asset_id = a.id
         WHERE a.file_type = ''audio''
           AND p.status = ''done''
           AND a.thumbnail_blob_key IS NOT NULL"'
  register: audio_thumb_count
  changed_when: false
  no_log: true
  when: gighive_media_storage_backend == 'azure_blob'
  tags: [media_storage]

- name: "[T-21] Assert no audio assets have thumbnail_blob_key (audio has no thumbnail)"
  ansible.builtin.assert:
    that:
      - audio_thumb_count.stdout | trim == "0"
    fail_msg: >
      {{ audio_thumb_count.stdout | trim }} audio asset(s) have thumbnail_blob_key set.
      Audio should never produce a thumbnail.
  when: gighive_media_storage_backend == 'azure_blob'
  tags: [media_storage]
```

---

### Phase 8 — Runtime auth: Managed Identity replaces SAS for media path

**Goal:** No long-lived secrets in the runtime media configuration.

**What IMDS is and why it is used:**

IMDS (Azure Instance Metadata Service) is a local HTTP endpoint at `http://169.254.169.254` that exists only inside Azure VMs. It is not reachable from the public internet and requires no credentials to contact — the VM's identity is proven by the fact that the request originates from within that VM.

The Azure VM is assigned a **Managed Identity** (a service principal baked into the VM by Terraform, not a username or password). When PHP calls IMDS and asks for a Bearer token, Azure verifies the request came from a VM that holds that identity and returns a short-lived token (valid ~60 minutes). That token then authorizes all subsequent Blob REST calls.

Key properties:
- No secrets stored anywhere — no connection strings, no keys in `.env`
- Tokens expire automatically; `AzureIdentityTokenCache` refreshes them 5 minutes before expiry
- Scoped to a single resource (`https://storage.azure.com/`) — not a blanket credential
- The VM's RBAC assignment (Storage Blob Data Contributor) controls exactly which operations are permitted

**Why `extra_hosts: host.docker.internal:host-gateway` is required:**

`169.254.169.254` is a link-local address on the VM host's network stack. The PHP container runs inside Docker's bridge network, where that address is not directly routable. The `extra_hosts` line adds a DNS alias resolving to the host's gateway IP, allowing the container to reach the VM host's network stack — and through it, Azure's IMDS endpoint. Without this, every IMDS call times out silently and all Blob operations return 403.

**Token flow from PHP container:**

```
PHP container
  → GET http://169.254.169.254/metadata/identity/oauth2/token
        ?api-version=2018-02-01
        &resource=https%3A%2F%2Fstorage.azure.com%2F
        &client_id=<AZURE_IDENTITY_CLIENT_ID>
      Header: Metadata: true
  → Azure IMDS (reachable via host.docker.internal → host gateway → 169.254.169.254)
  → returns { access_token, expires_in }
  → cached until (now + expires_in - 300s)
```

**Blob REST auth header:**

```
Authorization: Bearer <access_token>
x-ms-version: 2020-04-08
x-ms-date: <RFC1123>
```

**What remains SAS-based:** `AZURE_BLOB_SAS_TOKEN` in `.env.j2` stays for the admin import/export tooling only. It must not be used by any runtime media-serving or upload-finalization path. The env var should be renamed or documented to make this boundary clear.

#### Validation Checklist — Phase 8

*5 of 8 automated (`post_build_checks`); 3 manual. Token cache timing and SAS admin tooling require manual or long-running observation.*

---

**T-22 [post_build_checks]** — IMDS token endpoint returns 200 with a valid JSON body containing `access_token` and `expires_in`.

**T-23 [post_build_checks]** — `access_token` is a non-empty JWT string (starts with `eyJ`). *(Runs in the same task block as T-22.)*

```yaml
# Add to post_build_checks/tasks/main.yml
- name: "[T-22/T-23] Fetch IMDS Bearer token from inside Apache container"
  ansible.builtin.command: >
    docker exec -i "{{ apache_container_name }}"
      curl -sf -H "Metadata: true" --connect-timeout 5
        "http://169.254.169.254/metadata/identity/oauth2/token\
?api-version=2018-02-01\
&resource=https%3A%2F%2Fstorage.azure.com%2F\
&client_id={{ azure_identity_client_id }}"
  register: imds_token_raw
  changed_when: false
  no_log: true
  when: gighive_media_storage_backend == 'azure_blob'
  tags: [smoke, media_storage]

- name: "[T-22/T-23] Parse IMDS token response"
  ansible.builtin.set_fact:
    imds_token_json: "{{ imds_token_raw.stdout | from_json }}"
  no_log: true
  when: gighive_media_storage_backend == 'azure_blob'
  tags: [smoke, media_storage]

- name: "[T-22] Assert IMDS response contains access_token and expires_in"
  ansible.builtin.assert:
    that:
      - imds_token_json.access_token is defined
      - imds_token_json.expires_in is defined
    fail_msg: "IMDS response missing access_token or expires_in"
  no_log: true
  when: gighive_media_storage_backend == 'azure_blob'
  tags: [smoke, media_storage]

- name: "[T-23] Assert access_token is a JWT (starts with eyJ)"
  ansible.builtin.assert:
    that:
      - imds_token_json.access_token | length > 0
      - imds_token_json.access_token is match("^eyJ")
    fail_msg: "access_token is empty or not a JWT"
  no_log: true
  when: gighive_media_storage_backend == 'azure_blob'
  tags: [smoke, media_storage]
```

**T-24 [post_build_checks]** — Bearer token is accepted by Azure Blob REST (container-level HEAD returns 200).

```yaml
# Add to post_build_checks/tasks/main.yml
- name: "[T-24] Bearer token accepted by Azure Blob REST (container HEAD)"
  ansible.builtin.command: >
    docker exec -i "{{ apache_container_name }}"
      curl -sf -o /dev/null -w "%{http_code}"
        -H "Authorization: Bearer {{ imds_token_json.access_token }}"
        -H "x-ms-version: 2020-04-08"
        "https://{{ azure_blob_account_name }}.blob.core.windows.net/\
{{ azure_blob_container }}?restype=container"
  register: blob_auth_code
  changed_when: false
  no_log: true
  when: gighive_media_storage_backend == 'azure_blob'
  tags: [smoke, media_storage]

- name: "[T-24] Assert Blob container HEAD returns 200 with Bearer token"
  ansible.builtin.assert:
    that:
      - blob_auth_code.stdout | trim == "200"
    fail_msg: >
      Azure Blob rejected the Bearer token — got HTTP {{ blob_auth_code.stdout | trim }}.
      Check Managed Identity RBAC assignment (Storage Blob Data Contributor).
  when: gighive_media_storage_backend == 'azure_blob'
  tags: [smoke, media_storage]
```

**T-25 [Manual]** — APCu cache is hit on the second IMDS request within the same minute (only one HTTP call made).
> Verifying cache-hit behaviour requires either a `strace` on the PHP process or a temporary `error_log()` call in `AzureIdentityTokenCache`. Not automatable without modifying production code.

**T-26 [Manual]** — IMDS token refreshes ~5 minutes before expiry over a 55-minute observation window.
> Requires a long-running observation window. Can be approximated in a test environment by temporarily lowering `EXPIRY_BUFFER_SECONDS`, but that requires a code change.

**T-27 [post_build_checks]** — No Bearer token strings in Apache error or access logs.

```yaml
# Add to post_build_checks/tasks/main.yml
- name: "[T-27] Scan Apache logs for leaked Bearer token strings"
  ansible.builtin.command: >
    docker exec -i "{{ apache_container_name }}"
      sh -lc 'grep -rn "Bearer ey" /var/log/apache2/ /var/www/html/logs/ 2>/dev/null | wc -l'
  register: bearer_leak_count
  changed_when: false
  when: gighive_media_storage_backend == 'azure_blob'
  tags: [smoke, media_storage]

- name: "[T-27] Assert no Bearer tokens in logs"
  ansible.builtin.assert:
    that:
      - bearer_leak_count.stdout | trim == "0"
    fail_msg: >
      Bearer token strings found in logs — token leakage detected.
      Run: docker exec {{ apache_container_name }}
        grep -rn "Bearer ey" /var/log/apache2/ /var/www/html/logs/
  when: gighive_media_storage_backend == 'azure_blob'
  tags: [smoke, media_storage]
```

**T-28 [post_build_checks]** — No SAS query parameters appear in runtime `/files/` or `/media/` access log entries.

```yaml
# Add to post_build_checks/tasks/main.yml
- name: "[T-28] Scan access logs for SAS query params in runtime endpoints"
  ansible.builtin.command: >
    docker exec -i "{{ apache_container_name }}"
      sh -lc 'grep -E "\"(POST|PATCH|GET) /(files|media)/[^\"]*\?(sv|sig)="
        /var/log/apache2/access.log 2>/dev/null | wc -l'
  register: sas_runtime_count
  changed_when: false
  when: gighive_media_storage_backend == 'azure_blob'
  tags: [smoke, media_storage]

- name: "[T-28] Assert no SAS tokens in runtime request access logs"
  ansible.builtin.assert:
    that:
      - sas_runtime_count.stdout | trim == "0"
    fail_msg: >
      SAS token query params (?sv= or ?sig=) found in /files/ or /media/ access log.
      Runtime path must use Bearer token only.
  when: gighive_media_storage_backend == 'azure_blob'
  tags: [smoke, media_storage]
```

**T-29 [Manual]** — SAS token still works correctly for admin import/export tooling.
> Requires a functional test of `export_media_worker_azure.php` and `import_media_zip_worker_azure.php` with real admin session credentials. Not automatable without a full integration test harness.

---

### Phase 9 — `2bootstrap.sh` and Ansible wiring

**Goal:** Produce Terraform outputs the Ansible build can consume for storage configuration.

**`terraform/outputs.tf` additions:**

```hcl
output "media_storage_account_name" {
  value = azurerm_storage_account.media.name
}
output "media_container_name" {
  value = azurerm_storage_container.media.name
}
output "media_identity_client_id" {
  value = azurerm_user_assigned_identity.media_identity.client_id
}
```

**`2bootstrap.sh` additions** (after Terraform apply):

```bash
MEDIA_SA=$(terraform -chdir=terraform output -raw media_storage_account_name)
MEDIA_CONTAINER=$(terraform -chdir=terraform output -raw media_container_name)
MEDIA_IDENTITY=$(terraform -chdir=terraform output -raw media_identity_client_id)

# Write storage values into the Azure group vars file so Ansible picks them up
# on the next ansible-playbook run without manual editing.
AZURE_GV="ansible/inventories/group_vars/azure.yml"
# Remove any previous values for these keys, then append updated values
sed -i '/^azure_blob_account_name:/d; /^azure_blob_container:/d; /^azure_identity_client_id:/d' "${AZURE_GV}"
cat >> "${AZURE_GV}" << EOF
azure_blob_account_name: "${MEDIA_SA}"
azure_blob_container:    "${MEDIA_CONTAINER}"
azure_identity_client_id: "${MEDIA_IDENTITY}"
EOF
echo "Wrote storage config to ${AZURE_GV}"
```

The `azure.yml` group vars file is version-controlled but the storage account name, container, and identity client ID are Terraform outputs that change per deployment. The `sed` + `cat >>` pattern is safe and idempotent: it removes stale values before appending fresh ones. The `2bootstrap.sh` script is the authoritative step for this wiring; no manual group_vars editing is required after `terraform apply`.

#### Validation Checklist — Phase 9

*1 of 6 automated (Ansible controller check via `delegate_to: localhost`); 5 manual. `2bootstrap.sh` runs on the developer's machine outside Ansible's VM scope.*

---

**T-30 [Manual]** — `2bootstrap.sh` exits 0 after `terraform apply`.
> Script runs on developer's local machine. Check `echo $?` immediately after the script.

**T-31 [post_build_checks, delegate_to: localhost]** — `azure.yml` has exactly one entry per storage key (no duplicates from repeated runs).

```yaml
# Add to post_build_checks/tasks/main.yml
# Runs on the Ansible controller, not the VM
- name: "[T-31] azure.yml has no duplicate azure_blob_account_name entries"
  ansible.builtin.shell: >
    grep -c "^azure_blob_account_name:"
      "{{ inventory_dir }}/group_vars/azure.yml"
  register: acct_key_count
  delegate_to: localhost
  changed_when: false
  failed_when: false
  tags: [smoke, media_storage]

- name: "[T-31] Assert exactly one azure_blob_account_name entry in azure.yml"
  ansible.builtin.assert:
    that:
      - acct_key_count.stdout | trim == "1"
    fail_msg: >
      {{ acct_key_count.stdout | trim }} entries for azure_blob_account_name in azure.yml
      (expected 1). Re-running 2bootstrap.sh may have duplicated the key.
  delegate_to: localhost
  tags: [smoke, media_storage]
```

**T-32 [Manual]** — Values in `azure.yml` match `terraform output` verbatim.
> Requires Terraform CLI on developer machine: `terraform -chdir=terraform output -raw media_storage_account_name` and compare with `azure.yml`.

**T-33 [Manual]** — Running `ansible-playbook` without any manual group_vars edits after `2bootstrap.sh` produces a successful deploy.
> Process/workflow check; the deployment log is the evidence.

**T-34 [Manual]** — Re-running `2bootstrap.sh` a second time leaves `azure.yml` with exactly one entry per key.
> Developer runs the script twice and checks for duplicates. T-31 detects the symptom if this fails on the next deploy.

**T-35 [Manual]** — `terraform state list` continues to work from the developer machine after apply.
> Confirms Terraform state backend (separate storage account) is unaffected by `public_network_access_enabled = false` on the media account.

---

### Phase 10 — Admin tooling updates

**Goal:** Ensure admin screens are correct under Blob-backed mode.

| Admin capability | Action |
|---|---|
| Media stats (file counts, bytes) | Replace `glob()` filesystem scan with `MediaStorageService::list()` + blob metadata |
| Disk usage reporting | Retire or replace with Blob account usage metric |
| "Delete all media files from disk" | Gate on storage backend; in Blob mode, delete from Blob via service; update confirmation text |
| `MEDIA_SEARCH_DIRS` hard-fail in `clear_media_files.php` | Remove hard-fail when `GIGHIVE_MEDIA_STORAGE_BACKEND=azure_blob`; replace with Blob-aware delete |
| Import/export helpers | Delegate to `MediaStorageService`; remove inline `uploadBlobFromFile`/`downloadBlobToFile` |
| `mysqlPrep_normalized.py` / ffprobe | This tool probes local files for duration/media info. In Blob-backed mode it has no local path. The plan for this tool is: download to a temp path → probe → delete. The ffprobe probe step is not on the critical upload path; it can be deferred or run asynchronously |
| Catalog scan tools | Catalog scan walks `MEDIA_SEARCH_DIRS` on disk. These must be updated to enumerate blobs via `MediaStorageService::list()` rather than scanning a local directory |
| Probe job queue monitor | Add admin panel row showing `probe_jobs` queue depth by status (`queued`, `running`, `failed`); allow re-queue of failed jobs via the UI; query: `SELECT status, COUNT(*) FROM probe_jobs GROUP BY status` |

#### Validation Checklist — Phase 10

*4 of 9 automated (`post_build_checks`); 5 manual. Admin UI visual checks and functional tests (import/export, delete, re-queue) require interactive sessions.*

---

**T-36 [Manual]** — Media stats admin page shows correct file count matching `SELECT COUNT(*) FROM assets`.
> Requires browser session to view the admin UI and compare displayed count against a direct DB query.

**T-37 [Manual]** — Disk usage reporting no longer shows VM disk `df` output in Azure mode.
> Visual check; admin UI page inspection required.

**T-38 [Manual]** — "Delete all media files" confirmation page shows Blob-appropriate text; executing it deletes from Blob, not VM disk.
> Destructive operation — must be tested in a non-production environment with a controlled asset set.

**T-39 [post_build_checks]** — `clear_media_files.php` returns non-500 in Azure mode. *(Covered by T-13; duplicate reference here for Phase 10 completeness.)*

See T-13 YAML above.

**T-40 [post_build_checks]** — Import worker no longer contains a direct `uploadBlobFromFile()` call.

```yaml
# Add to post_build_checks/tasks/main.yml
- name: "[T-40] import_media_zip_worker_azure.php has no direct uploadBlobFromFile() call"
  ansible.builtin.command: >
    docker exec -i "{{ apache_container_name }}"
      grep -l "uploadBlobFromFile"
        /var/www/html/src/import_media_zip_worker_azure.php
  register: import_direct_call
  changed_when: false
  failed_when: import_direct_call.rc == 0
  when: gighive_media_storage_backend == 'azure_blob'
  tags: [smoke, media_storage]
  # rc=1 means grep found nothing — which is the passing state
```

**T-41 [post_build_checks]** — Export worker no longer contains a direct `downloadBlobToFile()` call.

```yaml
# Add to post_build_checks/tasks/main.yml
- name: "[T-41] export_media_worker_azure.php has no direct downloadBlobToFile() call"
  ansible.builtin.command: >
    docker exec -i "{{ apache_container_name }}"
      grep -l "downloadBlobToFile"
        /var/www/html/src/export_media_worker_azure.php
  register: export_direct_call
  changed_when: false
  failed_when: export_direct_call.rc == 0
  when: gighive_media_storage_backend == 'azure_blob'
  tags: [smoke, media_storage]
```

**T-42 [post_build_checks]** — No `glob()` calls remain in the catalog scan path for media files.

```yaml
# Add to post_build_checks/tasks/main.yml
# Adjust the path pattern to match the actual catalog scan file(s) in this codebase
- name: "[T-42] No glob() calls scanning media directories in catalog code"
  ansible.builtin.command: >
    docker exec -i "{{ apache_container_name }}"
      sh -lc 'grep -rn "glob(" /var/www/html/src/
        | grep -v "vendor/"
        | grep -i "audio\|video\|media"
        | wc -l'
  register: catalog_glob_count
  changed_when: false
  when: gighive_media_storage_backend == 'azure_blob'
  tags: [smoke, media_storage]

- name: "[T-42] Assert no media glob() calls remain"
  ansible.builtin.assert:
    that:
      - catalog_glob_count.stdout | trim == "0"
    fail_msg: >
      {{ catalog_glob_count.stdout | trim }} glob() call(s) referencing media paths found.
      Catalog scan must use MediaStorageService::list() in Azure mode.
  when: gighive_media_storage_backend == 'azure_blob'
  tags: [smoke, media_storage]
```

**T-43 [Manual]** — Probe job queue monitor row appears in the admin panel; depth matches DB query output.
> Requires browser session to view the admin panel row and compare against `SELECT status, COUNT(*) as n FROM probe_jobs GROUP BY status`.

**T-44 [Manual]** — Re-queuing a failed probe job via the admin UI resets `status = queued` and `attempts = 0`; next cron run processes it.
> Requires an interactive admin session and a controlled failed probe_job row as a test fixture.

---

### Phase 11 — Azure migration and rollout

**Goal:** Transition safely; Azure first to validate; then extend unified path to all deployments.

#### Phase 11 — Azure (primary validation target)

1. Build `TusBlockUploadService` with `LocalFileTusBackend` only — no Azure changes yet
2. Replace tusd with PHP tus server in all compose/Apache configs; verify uploads work via `LocalFileTusBackend`
3. Verify all local and VirtualBox environments still function end-to-end (upload + stream + probe)
4. Add `AzureBlobTusBackend` and full Azure Blob integration; test from Azure dev build
5. Enable `azure_blob` in Azure group vars; deploy to Azure
6. Apply Terraform Phase 6 (private endpoint + disable public access) — **only after step 4 is verified working**
7. Verify new uploads land in Blob; verify DB write; verify async probe job runs; verify thumbnail appears
8. Run backfill script (see below) — copy existing VM-disk media to Blob; verify checksum per file
9. Verify Blob counts match VM-disk counts; verify thumbnails, full-file playback, range seeks
10. Switch read path fully to Blob streaming; remove host media bind mounts from Azure compose

**Split-read window during migration (steps 7–9):**

New uploads land in Blob from step 7. Older files on VM disk have not yet been backfilled. During this window, `MediaStorageService::getMeta()` may return 404 for old files that don't yet exist in Blob, causing media-stream.php to return 404 for those assets even though the file is on disk.

Implement a temporary `FallbackMediaBackend` for the duration of steps 7–9:

```php
// src/Services/FallbackMediaBackend.php
// TEMPORARY — remove after Phase 11 step 9 backfill is verified complete.
// Tries Azure Blob first; falls back to local file if blob returns 404.
// Only needed during the migration window; never deploy to local/VirtualBox.
final class FallbackMediaBackend implements MediaStorageBackendInterface
{
    public function __construct(
        private readonly AzureBlobMediaBackend $blob,
        private readonly LocalMediaBackend     $local,
    ) {}

    public function getMeta(string $key): ?MediaMetaDto
    {
        return $this->blob->getMeta($key) ?? $this->local->getMeta($key);
    }

    public function stream(string $key): void
    {
        $meta = $this->blob->getMeta($key);
        if ($meta !== null) { $this->blob->stream($key); return; }
        $this->local->stream($key);
    }

    public function streamRange(string $key, int $start, int $end): void
    {
        $meta = $this->blob->getMeta($key);
        if ($meta !== null) { $this->blob->streamRange($key, $start, $end); return; }
        $this->local->streamRange($key, $start, $end);
    }

    // put / delete / exists / list delegate to blob only — new writes always go to Blob
    public function put(string $key, string $localPath, string $mimeType): void
    { $this->blob->put($key, $localPath, $mimeType); }
    public function delete(string $key): void  { $this->blob->delete($key); }
    public function exists(string $key): bool  { return $this->blob->exists($key) || $this->local->exists($key); }
    public function list(string $prefix): array { return $this->blob->list($prefix); }
}
```

Wire via a third `GIGHIVE_MEDIA_STORAGE_BACKEND=azure_blob_with_local_fallback` value in `MediaStorageService::make()`. Set this value in Azure group vars for the duration of the migration window; revert to `azure_blob` once backfill is verified in step 9.

**Backfill plan (Phase 11 step 8):**

The backfill copies all files in `/var/www/html/audio/` and `/var/www/html/video/` (and `thumbnails/`) from VM disk to Azure Blob, verifying each against its DB checksum. Run as a one-off PHP script on the VM — not a cron job:

```bash
# On the Azure VM:
php /var/www/html/src/Jobs/backfill_media_to_blob.php 2>&1 | tee /var/log/backfill.log
```

```php
// src/Jobs/backfill_media_to_blob.php
// One-shot script: copies VM-disk media to Azure Blob and verifies SHA256.
// Run once during Phase 11 step 8 with GIGHIVE_MEDIA_STORAGE_BACKEND=azure_blob_with_local_fallback.
// DO NOT run when backend=azure_blob (bind mounts may be absent).
// DO NOT run twice — use --dry-run flag first to preview.
require_once __DIR__ . '/../../vendor/autoload.php';
use Production\Api\Infrastructure\Database;
use Production\Api\Services\MediaStorageService;

$dryRun = in_array('--dry-run', $argv, true);
$pdo    = Database::createFromEnv();
$storage = MediaStorageService::make();   // must be azure_blob_with_local_fallback mode

// Enumerate all assets with a known checksum
$assets = $pdo->query(
    "SELECT id, file_type, checksum_sha256, mime_type FROM assets
     WHERE checksum_sha256 IS NOT NULL AND checksum_sha256 != ''
     ORDER BY id"
)->fetchAll(PDO::FETCH_ASSOC);

$ok = $fail = $skip = 0;
foreach ($assets as $asset) {
    $ext     = mimeToExt($asset['mime_type']);   // helper: 'audio/mpeg' → 'mp3' etc.
    $type    = $asset['file_type'];              // 'audio' | 'video'
    $key     = $asset['checksum_sha256'] . '.' . $ext;
    $localPath = "/var/www/html/{$type}/{$key}";

    if (!file_exists($localPath)) {
        echo "SKIP (no local file): {$type}/{$key}\n";
        $skip++; continue;
    }
    if ($storage->exists($type, $key)) {
        echo "SKIP (already in Blob): {$type}/{$key}\n";
        $skip++; continue;
    }
    // Verify local file SHA256 matches DB record before uploading
    $actual = hash_file('sha256', $localPath);
    if ($actual !== $asset['checksum_sha256']) {
        echo "FAIL (checksum mismatch): {$type}/{$key} local={$actual}\n";
        $fail++; continue;
    }
    if ($dryRun) {
        echo "DRY-RUN would upload: {$type}/{$key}\n";
        $ok++; continue;
    }
    $storage->put($type, $key, $localPath, $asset['mime_type']);
    // Re-verify via getMeta after upload
    $meta = $storage->getMeta($type, $key);
    if ($meta === null || $meta->size !== filesize($localPath)) {
        echo "FAIL (post-upload verify): {$type}/{$key}\n";
        $fail++; continue;
    }
    echo "OK: {$type}/{$key}\n";
    $ok++;
}
// TODO: also enumerate and upload thumbnails from video/thumbnails/
echo "\nDone: ok={$ok} skip={$skip} fail={$fail}\n";
exit($fail > 0 ? 1 : 0);
```

Key properties of this script:
- **Idempotent** — skips files already in Blob; safe to re-run after a partial failure
- **Verifies checksum before upload** — detects corrupted local files before they reach Blob
- **Verifies size after upload** — confirms the PUT round-tripped without truncation
- **Non-destructive** — never deletes local files; bind mounts remain until step 10
- **`--dry-run` mode** — preview what would be uploaded without writing anything
- **`fail` exit code 1** — CI/operator can detect partial failure and halt before step 9

Add `src/Jobs/backfill_media_to_blob.php` to the Files Under Change → New list.

**Ordering constraint:** Step 9 verification must confirm zero `fail` and `skip` count matches expected missing files (files added after the backfill started should upload on their own via the new upload path). Do not proceed to step 10 (removing bind mounts) until `fail=0`.

#### Validation Checklist — Phase 11

*6 of 17 automated (`post_build_checks`); 11 manual. Functional upload/playback tests and migration steps require interactive or device-level testing.*

---

**Steps 1–3 (Local TUS, no Azure changes yet)**

**T-45 [Manual]** — `LocalFileTusBackend` upload flow completes end-to-end on a local/VirtualBox deployment.
> Requires a real TUSKit / tus-js-client upload session. Verify `tus_uploads.status = complete` and `probe_jobs` row inserted in DB after the final PATCH.

**T-46 [Manual]** — Full regression pass on all local/VirtualBox environments (audio upload, video upload, playback, range seek, thumbnail).
> Covers all Phase 2–4 acceptance criteria (Build Order table items 1–13) on local backends.

**T-47 [post_build_checks]** — `tusd` container is not running; PHP tus handler returns expected status on `/files/`.

```yaml
# Add to post_build_checks/tasks/main.yml
- name: "[T-47] tusd container is not running"
  ansible.builtin.command: >
    docker ps --filter "name=tusd" --format '{% raw %}{{.Names}}{% endraw %}'
  register: tusd_running
  changed_when: false
  tags: [smoke, media_storage]

- name: "[T-47] Assert tusd container is stopped"
  ansible.builtin.assert:
    that:
      - tusd_running.stdout | trim == ""
    fail_msg: "tusd container is still running: {{ tusd_running.stdout }}"
  tags: [smoke, media_storage]
```

---

**Step 4 (AzureBlobTusBackend)**

**T-48 [Manual]** — Upload via `AzureBlobTusBackend` from Azure dev build; committed blob visible at `{container}/audio/{sha256}.ext` or `{container}/video/{sha256}.ext`.
> Requires an Azure dev deployment with a TUSKit / tus-js-client upload test. Verify via Azure Storage Explorer or Azurite browser.

**T-49 [Manual]** — SHA-256 in `assets` row matches `sha256sum` of the original uploaded file.
> Part of the upload verification in T-48; check `assets.checksum_sha256` against local `sha256sum` of the test file.

**T-50 [Manual]** — Probe job runs and completes; `assets.duration_seconds` populated; thumbnail blob exists for video.
> Functional test following T-48; wait for cron to pick up the `probe_jobs` row.

---

**Step 5 (azure_blob group vars deployed)**

**T-51 [post_build_checks]** — `GIGHIVE_MEDIA_STORAGE_BACKEND=azure_blob` confirmed in container after deploy. *(Covered by T-8; run T-8 after the Azure group vars deploy.)*

---

**Step 6 (Terraform Phase 6 applied)**

**T-52** — All Phase 6 checks pass. *(Run T-3, T-6 from Phase 6 checklist above.)*

---

**Steps 7–9 (Blob live, backfill, verify)**

**T-53 [Manual]** — New upload lands in Blob; `assets` row has non-null `checksum_sha256`; `probe_jobs` transitions `queued → running → done`; thumbnail blob exists.
> Functional end-to-end upload test on the live Azure deployment during the migration window.

**T-54 [post_build_checks]** — `GIGHIVE_MEDIA_STORAGE_BACKEND` is `azure_blob_with_local_fallback` during the migration window.

```yaml
# Add to post_build_checks/tasks/main.yml
- name: "[T-54] Backend is azure_blob_with_local_fallback during migration window"
  ansible.builtin.command: >
    docker exec -i "{{ apache_container_name }}"
      printenv GIGHIVE_MEDIA_STORAGE_BACKEND
  register: fallback_backend_env
  changed_when: false
  when: gighive_media_storage_backend == 'azure_blob_with_local_fallback'
  tags: [smoke, media_storage]

- name: "[T-54] Assert correct fallback backend value"
  ansible.builtin.assert:
    that:
      - fallback_backend_env.stdout | trim == 'azure_blob_with_local_fallback'
    fail_msg: >
      Expected azure_blob_with_local_fallback, got {{ fallback_backend_env.stdout | trim }}
  when: gighive_media_storage_backend == 'azure_blob_with_local_fallback'
  tags: [smoke, media_storage]
```

**T-55 [Manual]** — Backfill dry run: `php backfill_media_to_blob.php --dry-run` lists all expected assets with no `FAIL` lines.
> Operator runs the script and reviews output before proceeding to the live run.

**T-56 [Manual]** — Backfill live run exits with code 0; `fail=0`; `ok` count matches `SELECT COUNT(*) FROM assets WHERE checksum_sha256 IS NOT NULL`.
> Operator runs and inspects exit code and summary line. The script itself exits 1 on any `fail`, enabling detection.

**T-57 [Manual]** — Post-backfill blob count for `audio/` prefix matches `SELECT COUNT(*) FROM assets WHERE file_type='audio'`; same for `video/`.
> Requires Azure CLI (`az storage blob list`) or Azurite equivalent on the VM. Not automatable in `post_build_checks` without the Azure CLI installed.

**T-58 [Manual]** — Full-file playback and range seek for a pre-backfill asset (originally on VM disk) works via Blob.
> Requires a known pre-backfill asset SHA256 and a browser or curl test with Range header.

**T-59 [Manual]** — iOS thumbnail auth: `GET /media/video/thumbnails/{sha256}.png` with a valid session cookie returns 200 and a valid PNG.
> Device test; requires a real iOS session cookie.

---

**Step 10 (bind mounts removed)**

**T-60 [post_build_checks]** — Apache container has no audio/video bind mounts after Step 10. *(Covered by T-11; run T-11 after bind mounts are removed.)*

**T-61 [post_build_checks]** — Media endpoint returns 401 (not 403/500) after bind mounts removed. *(Covered by T-6.)*

**T-62 [post_build_checks]** — `GIGHIVE_MEDIA_STORAGE_BACKEND` reverted to `azure_blob`. *(Covered by T-8.)*

**T-63 [Manual]** — `FallbackMediaBackend` class flagged or deleted from the codebase.
> Code review / grep check: `grep -r "FallbackMediaBackend" src/` should return only a deletion commit or a `// TODO: remove` comment after Step 10.
