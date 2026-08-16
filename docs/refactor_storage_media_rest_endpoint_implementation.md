# Storage Media REST Endpoint — Implementation Reference

## Status — 2026-08-12

**Tranche 1 in progress — Phase 1 complete.**

| Phase | Status | Notes |
|-------|--------|-------|
| Phase 1 — Runtime config and IMDS access | **Complete** | Group vars, `.env.j2`, `docker-compose.yml.j2`, `clear_media_files.php`, post_build_checks T-8–T-13 |
| Phase 2 — PHP storage abstraction layer | Not started | Awaiting approval |
| Phase 3 — PHP tus upload server | Not started | Awaiting approval |
| Phase 4 — Media streaming endpoint | Not started | Awaiting approval |
| Phase 5 — Local/VirtualBox final step | Not started | Awaiting approval |
| Phases 6–11 (Tranche 2) | Deferred | Azure activation; not in scope until SaaS rollout |

---

## Elevator Pitch

This document is the hands-on build guide for the media storage refactor. It provides the PHP class skeletons, interface contracts, DB schema, Apache routing rules, and phase-by-phase deployment checklist that a developer needs to implement the change described in the main refactor doc — without having to re-derive any scaffolding from scratch.

---

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
        $backend = getenv('GIGHIVE_MEDIA_STORAGE_BACKEND') ?: MediaBackend::LOCAL;

        // Only create AzureBlobRestClient in azure_blob mode — avoids unnecessary
        // object construction (and the misleading appearance of an IMDS dependency)
        // when running in local/VirtualBox environments.
        $restClient = $backend === MediaBackend::AZURE_BLOB
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

> **Pre-implementation checks required before Phase 2/3 coding begins:**
>
> **B2 — `MediaTypes::allowedMimes()` existence:** `TusUploadConfig::fromEnv()` calls `MediaTypes::allowedMimes()` (line 415 above). Before implementing Phase 3, confirm whether this class already exists in the codebase:
> ```bash
> grep -r "class MediaTypes" ansible/roles/docker/files/apache/webroot/src/
> ```
> If absent, create `src/Infrastructure/MediaTypes.php` with the allowed MIME list (see Phase 3 body for the list) as a **Phase 3 prerequisite**, and add it to the Phase 3 Files Under Change. Do not leave `TusUploadConfig::fromEnv()` calling a non-existent class.
>
> **B3 — `UploadTokenValidator::$tokenId` property:** `api/tus-upload.php` uses `$tokenResult->tokenId` to obtain the authenticated user ID. Before implementing Phase 3, confirm the property name returned by `UploadTokenValidator::validate()`:
> ```bash
> grep -n "tokenId\|user_id\|userId\|return" ansible/roles/docker/files/apache/webroot/src/Auth/UploadTokenValidator.php
> ```
> If the return type exposes a different property name (e.g. `userId` or `user_id`), update the `tus-upload.php` skeleton accordingly. Do not assume `tokenId` without verification.

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
            $this->config->backend === MediaBackend::AZURE_BLOB
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
        // Defense-in-depth: validate UUID v4 format before using $uploadId in a path.
        // upload_id is server-generated, so this should always pass, but an explicit
        // check prevents path traversal if a future code path accidentally passes
        // non-UUID input (e.g. a value read back from DB that was corrupted or injected).
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $uploadId)) {
            throw new \InvalidArgumentException("Invalid upload_id format: {$uploadId}");
        }
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

// Validate UUID v4 format on PATCH and HEAD before passing to service methods.
// PATH_INFO is populated by Apache from the URL; although the client cannot inject
// arbitrary upload_ids (server-generated only), an unexpected URL pattern could
// produce a non-UUID string here. Reject early to prevent path traversal in
// LocalFileTusBackend::stagingPath() and to avoid a misleading 404 from the DB lookup.
if ($method !== 'POST' && $uploadId !== '') {
    if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $uploadId)) {
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Invalid upload ID format']);
        exit;
    }
}

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

## Test Inventory: Permanent vs Phase-Gate Checks

This index lists every automated check (`post_build_checks`, `validate_app`) in this refactor plan. Each is classified as a **permanent fixture** (stays in the Ansible roles indefinitely) or a **phase gate** (removed once its tranche is verified stable). Cleanup runbooks are at the end of each tranche — see *Tranche 1 Cleanup* after Phase 5 and *Tranche 2 Cleanup* after Phase 11.

### Permanent Fixtures

These remain in `post_build_checks` and `validate_app` after the full refactor is complete. They detect configuration drift, security regressions, and routing breakage on future deploys.

| Check | Role | Applies to | What it guards |
|---|---|---|---|
| T-3 | post_build_checks | Azure | Blob DNS resolves to private IP after Terraform changes |
| T-6 | post_build_checks | Azure | Media endpoint accessible; no regression after Terraform |
| T-8 | post_build_checks | all | `GIGHIVE_MEDIA_STORAGE_BACKEND` env var matches group vars |
| T-9 | post_build_checks | all | `host.docker.internal` extra_host present (IMDS routing) |
| T-10 | post_build_checks | Azure | IMDS reachable from Apache container |
| T-11 | post_build_checks | Azure | No local bind mounts in Azure mode (storage coupling) |
| T-12 | post_build_checks | local | Audio/video bind mounts present in local/VirtualBox mode |
| T-13 | post_build_checks | Azure | `clear_media_files.php` returns non-500 |
| T-14 | validate_app | Azure | Video probe job produces `thumbnail_blob_key` |
| T-15 | validate_app | Azure | Thumbnail blob exists at the recorded key |
| T-16/T-17 | validate_app | Azure | Thumbnail is a valid PNG with non-zero dimensions |
| T-18 | post_build_checks | Azure | No thumbnail files on VM disk (probe writes to Blob only) |
| T-19 | post_build_checks | Azure | `GET /media/video/thumbnails/` → 200 `image/png` |
| T-21 | validate_app | Azure | Audio probe: `duration_seconds` set; no `thumbnail_blob_key` |
| T-22/T-23 | post_build_checks | Azure | IMDS returns 200 with a valid JWT |
| T-24 | post_build_checks | Azure | Bearer token accepted by Azure Blob REST |
| T-27 | post_build_checks | Azure | No Bearer token strings in Apache logs |
| T-28 | post_build_checks | Azure | No SAS params in runtime `/files/` or `/media/` logs |
| T-31 | post_build_checks | Azure (controller) | `azure.yml` has no duplicate storage keys |
| T-39 | post_build_checks | Azure | `clear_media_files.php` non-500 *(delegates to T-13)* |
| T-47 | post_build_checks | all | tusd container not running *(mirrors T-83)* |
| T-51 | post_build_checks | Azure | `GIGHIVE_MEDIA_STORAGE_BACKEND=azure_blob` *(delegates to T-8)* |
| T-60 | post_build_checks | Azure | No bind mounts after Phase 11 step 10 *(delegates to T-11)* |
| T-61 | post_build_checks | Azure | Media endpoint 401 after bind mount removal *(delegates to T-6)* |
| T-62 | post_build_checks | Azure | Backend reverted to `azure_blob` *(delegates to T-8)* |
| T-64 | post_build_checks | local | `/media/audio/` returns 401 (local mode routing) |
| T-65 | post_build_checks | local | Range request → 206 with correct `Content-Range` |
| T-66 | post_build_checks | local | `/audio/` old path → 401 (PHP-mediated, not Apache static) |
| T-67 | post_build_checks | local | Bind mounts present *(delegates to T-12)* |
| T-68 | post_build_checks | local | `MEDIA_SEARCH_DIRS` absent (retired env var) |
| T-68b | validate_app | all | Warn on orphaned `tus_uploads` rows (complete, no asset_id, >1hr old) |
| T-73 | post_build_checks | all | APCu extension loaded (required by `AzureIdentityTokenCache`) |
| T-79 | post_build_checks | all | PHP >= 8.2 (required by `HashContext` serialization) |
| T-82 | post_build_checks | all | Probe job cron file present in container |
| T-83 | post_build_checks | all | tusd container not running |
| T-84 | post_build_checks | all | `GET /files/` → 400 (PHP tus handler live) |
| T-85 | post_build_checks | all | `POST /files/` unauthenticated → 401 |
| T-86 | post_build_checks | all | `innodb_lock_wait_timeout >= 60` |
| T-86b | validate_app | all | Warn on permanently-failed `probe_jobs` rows (status=failed, attempts >= 3) |
| T-90 | post_build_checks | all | `GET /api/media-stream.php` → 401 without auth |
| T-91 | post_build_checks | all | `GET /media/audio/` → 400/401 (canonical path routing) |
| T-92 | post_build_checks | all | `GET /audio/` old path → 401 (backward-compat routing) |

### Phase-Gate Checks — Remove After Tranche 1

Remove from `post_build_checks` at the Tranche 1 Cleanup step (after Phase 5 is verified stable). These are one-time structural checks that belong in CI pre-deploy, not in ongoing post-deploy smoke.

| Check | Role | What it confirmed |
|---|---|---|
| T-71 | post_build_checks | `composer dump-autoload` exits 0 (Phase 2 classes discoverable) |
| T-72 | post_build_checks | `php -l` passes on Phase 2 service files |
| T-74 | post_build_checks | `MediaStorageService::make()` instantiates without error |
| T-80 | post_build_checks | `tus_uploads` table has all required columns |
| T-81 | post_build_checks | `probe_jobs` table has all required columns |

### Phase-Gate Checks — Remove After Tranche 2

Remove from `post_build_checks` at the Tranche 2 Cleanup step (after Phase 11 is verified stable). These are one-time structural checks for the Phase 10 code refactor.

| Check | Role | What it confirmed |
|---|---|---|
| T-40 | post_build_checks | Import worker has no direct `uploadBlobFromFile()` call |
| T-41 | post_build_checks | Export worker has no direct `downloadBlobToFile()` call |
| T-42 | post_build_checks | No `glob()` calls in the media catalog scan path |

### Migration-Window-Only — Remove at Phase 11 Step 9

| Check | Role | When to remove |
|---|---|---|
| T-54 | post_build_checks | When `gighive_media_storage_backend` reverts from `azure_blob_with_local_fallback` to `azure_blob` |

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
> *Lifecycle: **permanent** — keep in `post_build_checks`; private endpoint DNS can drift after Terraform changes.*

```yaml
# Add to post_build_checks/tasks/main.yml — gated on azure_blob mode
- name: "[T-3] Blob DNS resolves to private IP inside Apache container"
  ansible.builtin.command: >
    docker exec -i "&#123;&#123; apache_container_name &#125;&#125;"
      nslookup &#123;&#123; azure_blob_account_name &#125;&#125;.blob.core.windows.net
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
      nslookup output: &#123;&#123; blob_dns.stdout &#125;&#125;
  when: gighive_media_storage_backend == 'azure_blob'
  tags: [smoke, media_storage]
```

**T-4 [Manual]** — `validate_app` Azure connectivity probe passes using the updated private-endpoint probe (not the legacy SAS-over-public-endpoint probe).
> This is itself a `validate_app` run — it passes automatically once the probe is updated in the role. Cross-reference: Ansible Role Interactions section in the design doc.

**T-5 [Manual]** — Direct `curl https://{account}.blob.core.windows.net/...` from the developer's laptop returns 403 or times out.
> Must be run from outside the VM — Ansible runs on the VM and would succeed via the private endpoint, masking a misconfigured public-access block.

**T-6 [post_build_checks]** — Existing media endpoint responds normally post-apply (no 403 regression from inside the container).
> *Lifecycle: **permanent** — keep in `post_build_checks`; detects access regressions after any Azure networking change.*

```yaml
# Add to post_build_checks/tasks/main.yml
- name: "[T-6] Media endpoint returns 401 (not 403/500) after Phase 6 apply"
  ansible.builtin.uri:
    url: "&#123;&#123; gighive_base_url &#125;&#125;/media/audio/"
    method: GET
    validate_certs: "&#123;&#123; gighive_validate_certs &#125;&#125;"
    status_code: [400, 401, 404]
    headers: "&#123;&#123; {'Host': gighive_hostname_for_host_header} if (gighive_hostname_for_host_header | length) > 0 else omit &#125;&#125;"
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
upload_chunk_size_bytes:        8388608         # 8 MB — must match TUSKit/tus-js-client chunk size
                                                # used by handlePost() Azure block-limit check
tus_max_pending_uploads_per_token: 5            # per-token concurrent pending upload limit (POST /files/)

# ai_worker safety gate — set false in Azure group vars until ai_worker is updated to
# download blobs (Option A in Phase 10). Without this, the ai_worker container will start
# but silently process no media in Azure mode after Phase 11 step 10 removes bind mounts.
ai_worker_enabled: true                         # override to false in Azure group vars (Phase 1)
```

**`.env.j2` additions:**

```
GIGHIVE_MEDIA_STORAGE_BACKEND=&#123;&#123; gighive_media_storage_backend | default('local') &#125;&#125;
AZURE_BLOB_ACCOUNT_NAME=&#123;&#123; azure_blob_account_name | default('') &#125;&#125;
AZURE_BLOB_CONTAINER=&#123;&#123; azure_blob_container | default('') &#125;&#125;
AZURE_BLOB_PREFIX_AUDIO=&#123;&#123; azure_blob_prefix_audio | default('audio/') &#125;&#125;
AZURE_BLOB_PREFIX_VIDEO=&#123;&#123; azure_blob_prefix_video | default('video/') &#125;&#125;
# Note: thumbnail prefix 'video/thumbnails/' is baked into MediaStorageService::putThumbnail()
#       and is not a configurable env var.
AZURE_IDENTITY_CLIENT_ID=&#123;&#123; azure_identity_client_id | default('') &#125;&#125;
MEDIA_LOCAL_AUDIO_DIR=&#123;&#123; media_local_audio_dir | default('/var/www/html/audio') &#125;&#125;
MEDIA_LOCAL_VIDEO_DIR=&#123;&#123; media_local_video_dir | default('/var/www/html/video') &#125;&#125;
MEDIA_LOCAL_THUMB_DIR=&#123;&#123; media_local_thumb_dir | default('/var/www/html/video/thumbnails') &#125;&#125;
TUS_LOCAL_STAGING_DIR=&#123;&#123; tus_local_staging_dir | default('/tmp/tus-staging') &#125;&#125;
UPLOAD_CHUNK_SIZE_BYTES=&#123;&#123; upload_chunk_size_bytes | default(8388608) &#125;&#125;
UPLOAD_MAX_PENDING_PER_TOKEN=&#123;&#123; tus_max_pending_uploads_per_token | default(5) &#125;&#125;
```

**`docker-compose.yml.j2` change — conditional bind mounts:**

```yaml
&#123;% if gighive_media_storage_backend | default('local') != 'azure_blob' %&#125;
      - "/home/&#123;&#123; ansible_user &#125;&#125;/audio:&#123;&#123; media_search_dir_audio &#125;&#125;"
      - "/home/&#123;&#123; ansible_user &#125;&#125;/video:&#123;&#123; media_search_dir_video &#125;&#125;"
&#123;% endif %&#125;
```

**`docker-compose.yml.j2` change — IMDS access (add unconditionally):**

```yaml
    extra_hosts:
      - "host.docker.internal:host-gateway"
```

This is required for Managed Identity token acquisition from inside the Docker bridge network. The Azure IMDS endpoint (`169.254.169.254`) is only reachable from the host. The `host-gateway` alias lets the container reach the VM host's network stack, through which IMDS is accessible. Without this, every call to acquire an Azure AD token silently times out and all Blob operations return 403. The compose already uses this pattern for the telemetry proxy when `gighive_enable_telemetry_proxy` is true; this makes it unconditional for all Azure-mode deployments.

**`MEDIA_SEARCH_DIRS` handling and `clear_media_files.php` gate (required in Phase 1):**

When `GIGHIVE_MEDIA_STORAGE_BACKEND=azure_blob`, the local media directories do not exist inside the container. `clear_media_files.php` currently hard-fails when `MEDIA_SEARCH_DIRS` is absent or points to a non-existent path, producing a 500 response. This must be patched **in Phase 1** — before the new env vars land — because the Phase 1 deploy introduces `GIGHIVE_MEDIA_STORAGE_BACKEND` without a `MEDIA_SEARCH_DIRS` replacement. The minimum required change: gate the hard-fail on `GIGHIVE_MEDIA_STORAGE_BACKEND !== 'azure_blob'`. The full Blob-aware delete UI is a Phase 10 concern. Deploying Phase 1 without this patch will cause the admin page to 500 immediately on all Azure environments.

**`ai_worker` safety gate (required in Phase 1 — Azure group vars only):**

The `ai_worker` role bind-mounts `{{ video_dir }}` and `{{ audio_dir }}` into the container for AI analysis. After Phase 11 step 10 removes the Azure media bind mounts, those host directories will be empty and the ai-worker will silently process no media — container running, no errors, no output. **Set `ai_worker_enabled: false` in the Azure group vars file as part of Phase 1** before any Phase 11 bind-mount removal happens. This prevents the silent operational failure. Remove the gate only when `ai_worker` is updated to download blobs via `MediaStorageService` (Option A in Phase 10 / Phase 11 planning).

#### Validation Checklist — Phase 1

*6 of 6 automated (`post_build_checks`). All checks are container-introspection or HTTP and run fully from Ansible.*

---

**T-8 [post_build_checks]** — `GIGHIVE_MEDIA_STORAGE_BACKEND` env var is set in the container and matches group vars; Azure-specific vars are non-empty in Azure mode.
> *Lifecycle: **permanent** — keep in `post_build_checks`; detects env var drift between group vars and running container.*

```yaml
# Add to post_build_checks/tasks/main.yml
- name: "[T-8] GIGHIVE_MEDIA_STORAGE_BACKEND matches expected value"
  ansible.builtin.command: >
    docker exec -i "&#123;&#123; apache_container_name &#125;&#125;" printenv GIGHIVE_MEDIA_STORAGE_BACKEND
  register: media_backend_env
  changed_when: false
  tags: [smoke, media_storage]

- name: "[T-8] Assert GIGHIVE_MEDIA_STORAGE_BACKEND equals gighive_media_storage_backend"
  ansible.builtin.assert:
    that:
      - media_backend_env.stdout | trim == gighive_media_storage_backend
    fail_msg: >
      Container GIGHIVE_MEDIA_STORAGE_BACKEND=&#123;&#123; media_backend_env.stdout | trim &#125;&#125;,
      expected &#123;&#123; gighive_media_storage_backend &#125;&#125;
  tags: [smoke, media_storage]

- name: "[T-8] Azure Blob env vars are non-empty (Azure mode only)"
  ansible.builtin.command: >
    docker exec -i "&#123;&#123; apache_container_name &#125;&#125;"
      sh -lc 'test -n "$(printenv &#123;&#123; item &#125;&#125;)"'
  loop:
    - AZURE_BLOB_ACCOUNT_NAME
    - AZURE_BLOB_CONTAINER
    - AZURE_IDENTITY_CLIENT_ID
  changed_when: false
  when: gighive_media_storage_backend == 'azure_blob'
  tags: [smoke, media_storage]
```

**T-9 [post_build_checks]** — Apache container has `host.docker.internal:host-gateway` in `ExtraHosts`.
> *Lifecycle: **permanent** — keep in `post_build_checks`; detects compose drift that would break IMDS connectivity.*

```yaml
# Add to post_build_checks/tasks/main.yml
- name: "[T-9] Read Apache container ExtraHosts"
  ansible.builtin.command: >
    docker inspect
      -f '&#123;% raw %&#125;&#123;&#123;range .HostConfig.ExtraHosts&#125;&#125;&#123;&#123;.&#125;&#125;&#123;&#123;"\n"&#125;&#125;&#123;&#123;end&#125;&#125;&#123;% endraw %&#125;'
      "&#123;&#123; apache_container_name &#125;&#125;"
  register: container_extra_hosts
  changed_when: false
  tags: [smoke, media_storage]

- name: "[T-9] Assert host.docker.internal extra host is present"
  ansible.builtin.assert:
    that:
      - "'host.docker.internal' in container_extra_hosts.stdout"
    fail_msg: >
      host.docker.internal not found in ExtraHosts.
      Current ExtraHosts: &#123;&#123; container_extra_hosts.stdout &#125;&#125;
  tags: [smoke, media_storage]
```

**T-10 [post_build_checks]** — IMDS instance endpoint is reachable from inside the Apache container (Azure mode only).
> *Lifecycle: **permanent** — keep in `post_build_checks`; detects networking regressions that would silently break all Azure Blob auth.*

```yaml
# Add to post_build_checks/tasks/main.yml
- name: "[T-10] IMDS instance endpoint reachable from Apache container"
  ansible.builtin.command: >
    docker exec -i "&#123;&#123; apache_container_name &#125;&#125;"
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
      IMDS returned &#123;&#123; imds_instance_code.stdout | trim &#125;&#125; (expected 200).
      Check extra_hosts host-gateway config and Azure VM identity assignment.
  when: gighive_media_storage_backend == 'azure_blob'
  tags: [smoke, media_storage]
```

**T-11 [post_build_checks]** — Apache container has no local media bind mounts in Azure mode.
> *Lifecycle: **permanent** — keep in `post_build_checks`; detects compose drift that would re-couple media to VM disk.*

```yaml
# Add to post_build_checks/tasks/main.yml
- name: "[T-11] Read Apache container mount sources"
  ansible.builtin.command: >
    docker inspect
      -f '&#123;% raw %&#125;&#123;&#123;range .Mounts&#125;&#125;&#123;&#123;.Source&#125;&#125;&#123;&#123;"\n"&#125;&#125;&#123;&#123;end&#125;&#125;&#123;% endraw %&#125;'
      "&#123;&#123; apache_container_name &#125;&#125;"
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
      Mounts: &#123;&#123; container_mounts.stdout &#125;&#125;
  when: gighive_media_storage_backend == 'azure_blob'
  tags: [smoke, media_storage]
```

**T-12 [post_build_checks]** — Apache container has audio and video bind mounts present in local/VirtualBox mode.
> *Lifecycle: **permanent** — keep in `post_build_checks`; confirms local-mode compose is intact after any role change.*

```yaml
# Add to post_build_checks/tasks/main.yml
- name: "[T-12] Assert audio and video host dirs are mounted (local mode)"
  ansible.builtin.assert:
    that:
      - container_mounts.stdout | regex_search('/audio') is not none
      - container_mounts.stdout | regex_search('/video') is not none
    fail_msg: >
      Expected audio/video bind mounts not found in local mode.
      Mounts: &#123;&#123; container_mounts.stdout &#125;&#125;
  when: gighive_media_storage_backend != 'azure_blob'
  tags: [smoke, media_storage]
  # Depends on T-11 register: container_mounts — place after T-11 block
```

**T-13 [post_build_checks]** — `clear_media_files.php` admin page returns non-500 in Azure mode.
> *Lifecycle: **permanent** — keep in `post_build_checks`; detects admin-page regressions after backend changes.*

```yaml
# Add to post_build_checks/tasks/main.yml
- name: "[T-13] clear_media_files.php does not 500 without local media dirs (Azure mode)"
  ansible.builtin.uri:
    url: "&#123;&#123; gighive_base_url &#125;&#125;/src/clear_media_files.php"
    method: GET
    url_username: "&#123;&#123; uploader_user &#125;&#125;"
    url_password: "&#123;&#123; gighive_uploader_password &#125;&#125;"
    force_basic_auth: yes
    validate_certs: "&#123;&#123; gighive_validate_certs &#125;&#125;"
    status_code: [200, 302, 401, 403]
    headers: "&#123;&#123; {'Host': gighive_hostname_for_host_header} if (gighive_hostname_for_host_header | length) > 0 else omit &#125;&#125;"
  changed_when: false
  when: gighive_media_storage_backend == 'azure_blob'
  tags: [smoke, media_storage]
```

---

### Phase 2 — PHP storage abstraction layer

**Goal:** Centralize all blob operations in one service so no other code cares whether storage is local or Blob.

**New files (Phase 2):**

- `src/Infrastructure/MediaBackend.php` — **required deliverable, not optional.** Provides string constants for every backend identifier. Every comparison in PHP must use these constants — never raw string literals. This eliminates the entire class of silent backend-mismatch bugs from typos.

```php
// src/Infrastructure/MediaBackend.php
namespace Production\Api\Infrastructure;

final class MediaBackend
{
    public const LOCAL          = 'local';
    public const AZURE_BLOB     = 'azure_blob';
    public const AZURE_FALLBACK = 'azure_blob_with_local_fallback';

    // Non-instantiable
    private function __construct() {}
}
```

- `src/Services/MediaStorageService.php` — storage facade

- `src/Services/FallbackMediaBackend.php` — **stub required at Phase 2.** `MediaStorageService::make()` references this class statically, so the Composer autoloader must be able to resolve it from the moment `MediaStorageService.php` is deployed. At Phase 2 the class file contains the full interface signature and constructor but `/* TODO: Phase 11 — implement split-read logic */` bodies for all methods. The class must implement `MediaStorageBackendInterface` so the type-checker is satisfied; every method may `throw new \LogicException('FallbackMediaBackend not yet active — set backend to azure_blob or local')`. When Phase 11 arrives, the bodies are replaced with the real split-read logic in the same file.

The backend implements the authoritative interface (see implementation reference doc for full body):

```php
// Authoritative MediaStorageBackendInterface — key is pre-qualified (type prefix included)
interface MediaStorageBackendInterface {
    public function put(string $key, string $localPath, string $mimeType): void;
    public function stream(string $key): void;
    public function streamRange(string $key, int $start, int $end): void;
    public function getMeta(string $key): ?MediaMetaDto;   // null on 404
    public function delete(string $key): void;
    public function exists(string $key): bool;
    public function list(string $prefix): array;
}
```

`MediaStorageService` is the only caller of the backend interface. It accepts `($type, $key)` separately and builds the qualified key internally via `qualifiedKey($type, $key)` before forwarding to the backend. No code outside `MediaStorageService` ever calls backend methods directly.

Three concrete implementations:
- `LocalMediaBackend` — reads/writes from `/var/www/html/audio` and `/var/www/html/video`
- `AzureBlobMediaBackend` — uses REST, acquires identity token via IMDS, caches token until 5 minutes before expiry
- `FallbackMediaBackend` — Phase 2 stub; Phase 11 split-read implementation

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
- `downloadBlobToFile()` from `import_media_zip_worker_azure.php` becomes the `stream()` / `streamRange()` backend — same auth swap
- `listAzureBlobs()` from `admin_media_lib.php` becomes the `list()` backend

Do not reinvent these. Extract, refactor auth, wrap.

**`MediaStorageService` is instantiated once per request via a factory that reads `GIGHIVE_MEDIA_STORAGE_BACKEND` from the environment.**

`AzureBlobMediaBackend` and `AzureBlobTusBackend` both take an injected `AzureBlobRestClient` (see `AzureBlobRestClient.php` in the implementation reference doc). `AzureBlobRestClient` owns `AzureIdentityTokenCache` and all cURL execution, eliminating duplication between the two Azure backends.

```php
// MediaStorageService::make() — see implementation reference doc for full body
// All backend comparisons use MediaBackend:: constants — never raw string literals.
use Production\Api\Infrastructure\MediaBackend;

final class MediaStorageService {
    public static function make(): self {
        $backend = getenv('GIGHIVE_MEDIA_STORAGE_BACKEND') ?: MediaBackend::LOCAL;
        if ($backend === MediaBackend::AZURE_BLOB) {
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
        if ($backend === MediaBackend::AZURE_FALLBACK) {
            // Phase 11 only — FallbackMediaBackend stub is present at Phase 2;
            // full implementation added in Phase 11.
            $rest = new AzureBlobRestClient(/* ... same as above ... */);
            return new self(new FallbackMediaBackend(
                new AzureBlobMediaBackend($rest),
                new LocalMediaBackend(/* ... */),
            ));
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

#### Validation Checklist — Phase 2

*4 of 8 automated (`post_build_checks`); 4 manual. Automated checks verify the service layer is syntactically and structurally correct inside the container. Functional correctness of `LocalMediaBackend` and `AzureBlobMediaBackend` requires manual verification against real storage.*

---

**T-71 [post_build_checks]** — `composer dump-autoload` exits 0 inside the Apache container (all new Phase 2 classes are discoverable).
> *Lifecycle: **phase gate (Tranche 1)** — remove from `post_build_checks` at Tranche 1 cleanup; autoload is stable once confirmed and belongs in CI not post-deploy smoke.*

```yaml
# Add to post_build_checks/tasks/main.yml
- name: "[T-71] composer dump-autoload succeeds inside Apache container"
  community.docker.docker_container_exec:
    container: "&#123;&#123; apache_container_name &#125;&#125;"
    command:
      - composer
      - dump-autoload
      - --no-dev
      - --working-dir=/var/www/html
  register: composer_dump
  changed_when: false
  failed_when: false
  tags: [smoke, media_storage]

- name: "[T-71] Assert composer dump-autoload exited 0"
  ansible.builtin.assert:
    that:
      - composer_dump.rc == 0
    fail_msg: "composer dump-autoload failed: &#123;&#123; composer_dump.stderr &#125;&#125;"
  tags: [smoke, media_storage]
```

**T-72 [post_build_checks]** — PHP syntax check (`php -l`) passes on all new Phase 2 service files.
> *Lifecycle: **phase gate (Tranche 1)** — remove from `post_build_checks` at Tranche 1 cleanup; syntax is confirmed once at deployment and belongs in CI pre-deploy.*

```yaml
# Add to post_build_checks/tasks/main.yml
- name: "[T-72] PHP syntax check on Phase 2 service files"
  community.docker.docker_container_exec:
    container: "&#123;&#123; apache_container_name &#125;&#125;"
    command:
      - php
      - -l
      - "&#123;&#123; item &#125;&#125;"
  loop:
    - /var/www/html/src/Services/MediaStorageService.php
    - /var/www/html/src/Services/AzureBlobRestClient.php
    - /var/www/html/src/Services/AzureIdentityTokenCache.php
  register: php_syntax_p2
  changed_when: false
  tags: [smoke, media_storage]

- name: "[T-72] Assert all Phase 2 service files pass php -l"
  ansible.builtin.assert:
    that:
      - item.rc == 0
    fail_msg: "PHP syntax error in &#123;&#123; item.item &#125;&#125;: &#123;&#123; item.stderr &#125;&#125;"
  loop: "&#123;&#123; php_syntax_p2.results &#125;&#125;"
  tags: [smoke, media_storage]
```

**T-73 [post_build_checks]** — APCu extension is loaded inside the Apache container (`AzureIdentityTokenCache` requires it).
> *Lifecycle: **permanent** — keep in `post_build_checks`; APCu availability can change when the base image is updated.*

```yaml
# Add to post_build_checks/tasks/main.yml
- name: "[T-73] APCu extension is loaded inside the Apache container"
  community.docker.docker_container_exec:
    container: "&#123;&#123; apache_container_name &#125;&#125;"
    command:
      - php
      - -r
      - "exit(function_exists('apcu_store') ? 0 : 1);"
  register: apcu_check
  changed_when: false
  failed_when: false
  tags: [smoke, media_storage]

- name: "[T-73] Assert APCu is available"
  ansible.builtin.assert:
    that:
      - apcu_check.rc == 0
    fail_msg: >
      APCu extension not loaded — AzureIdentityTokenCache will fail to cache tokens.
      Ensure the php-apcu package is installed in the Apache container image.
  tags: [smoke, media_storage]
```

**T-74 [post_build_checks]** — `MediaStorageService::make()` instantiates without error in local mode.
> *Lifecycle: **phase gate (Tranche 1)** — remove from `post_build_checks` at Tranche 1 cleanup; one-time confidence check; ongoing routing smoke (T-90/T-91) covers functional regression.*

```yaml
# Add to post_build_checks/tasks/main.yml
- name: "[T-74] MediaStorageService::make() instantiates without exception (local mode)"
  community.docker.docker_container_exec:
    container: "&#123;&#123; apache_container_name &#125;&#125;"
    command:
      - php
      - -r
      - "require '/var/www/html/vendor/autoload.php'; \\Production\\Api\\Services\\MediaStorageService::make(); echo 'ok';"
  register: storage_make_check
  changed_when: false
  failed_when: false
  when: gighive_media_storage_backend == 'local'
  tags: [smoke, media_storage]

- name: "[T-74] Assert MediaStorageService::make() printed ok"
  ansible.builtin.assert:
    that:
      - storage_make_check.stdout | trim == 'ok'
    fail_msg: >
      \Production\Api\Services\MediaStorageService::make() threw an exception in local mode.
      Stderr: &#123;&#123; storage_make_check.stderr &#125;&#125;
  when: gighive_media_storage_backend == 'local'
  tags: [smoke, media_storage]
```

**T-75 [Manual]** — `AzureBlobRestClient::blobUrl('audio/test.mp3')` returns a correctly formed URL; `authHeaders()` returns an array containing `Authorization`, `x-ms-version`, and `x-ms-date` keys.
> Run a one-off `docker exec` PHP snippet against a dev environment with Azure env vars set. Inspect the printed URL and header array for correctness. Azure mode only.

**T-76 [Manual]** — `LocalMediaBackend` functional: `put()` copies a test file to the audio dir; `getMeta()` returns the correct size; `stream()` pipes the correct bytes to stdout; `exists()` returns `true` for the copied file and `false` for a non-existent key.
> Run via `docker exec` PHP snippet with a small test file in `/tmp`. Verify byte-for-byte output of `stream()` matches the original. Local mode only.

**T-77 [Manual]** — `AzureBlobMediaBackend` functional: `getMeta()` returns correct `size` and `ETag` for a known blob; `stream()` pipes correct bytes; `put()` creates a blob visible in the Azure portal at the expected key.
> Run via `docker exec` PHP snippet against a dev Azure environment. Use a known small test blob. Azure mode only.

**T-78 [Manual]** — `MediaStorageService::make()` throws `RuntimeException` when `AZURE_BLOB_ACCOUNT_NAME`, `AZURE_BLOB_CONTAINER`, or `AZURE_IDENTITY_CLIENT_ID` is unset while `GIGHIVE_MEDIA_STORAGE_BACKEND=azure_blob`.
> Run `docker exec` with the env var temporarily unset (one at a time). Confirm exception message is thrown and no silent fallback occurs.

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
    block_count   INT UNSIGNED  NOT NULL DEFAULT 0,      -- Azure Block Blob: number of PUT Block calls committed so far.
                                                         -- INT UNSIGNED (max ~4 billion) chosen over SMALLINT UNSIGNED
                                                         -- (max 65,535) for forward safety: if maxFileSizeBytes is ever
                                                         -- raised independently of this schema, SMALLINT would silently
                                                         -- overflow at 65,535 blocks (~512 GB at 8 MB chunks).
                                                         -- Azure-backend-specific state. A future S3 backend would track
                                                         -- part numbers + ETags via a separate mechanism rather than this column.
    block_size    INT UNSIGNED  NOT NULL DEFAULT 0,      -- set from first PATCH body length; never updated after.
                                                         -- Azure Block Blob: all blocks except the final one are this size.
                                                         -- Azure-backend-specific state (S3 uses variable-size parts).
    sha256_ctx    BLOB          NULL,                    -- serialized HashContext (PHP 8.0+); 1-2 KB
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

**Live DDL for existing environments (run before Phase 3 code deploy):**

Use the same schema shown above, but apply it to existing environments from the Docker host with the MySQL container command below:

```bash
docker exec -i mysqlServer bash -c 'mysql -u root -p"$MYSQL_ROOT_PASSWORD" media_db' <<'SQL'
CREATE TABLE IF NOT EXISTS tus_uploads (
    id            INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    upload_id     VARCHAR(36)   NOT NULL,
    user_id       INT UNSIGNED  NOT NULL,
    status        ENUM('pending','complete','failed') NOT NULL DEFAULT 'pending',
    upload_length BIGINT UNSIGNED NOT NULL,
    block_count   INT UNSIGNED  NOT NULL DEFAULT 0,      -- Azure Block Blob state; INT not SMALLINT (see schema comments above)
    block_size    INT UNSIGNED  NOT NULL DEFAULT 0,      -- Azure Block Blob state; set from first PATCH body, never updated after
    sha256_ctx    BLOB          NULL,
    file_type     ENUM('audio','video') NOT NULL,
    mime_type     VARCHAR(128)  NOT NULL DEFAULT '',
    asset_id      INT UNSIGNED  NULL,
    created_at    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at    DATETIME      NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_upload_id (upload_id),
    INDEX idx_user_pending (user_id, status),
    INDEX idx_expires (expires_at),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS probe_jobs (
    id         INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    asset_id   INT UNSIGNED  NOT NULL,
    blob_key   VARCHAR(512)  NOT NULL,
    file_type  ENUM('audio','video') NOT NULL,
    status     ENUM('queued','running','done','failed') NOT NULL DEFAULT 'queued',
    attempts   TINYINT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    started_at DATETIME NULL,
    PRIMARY KEY (id),
    INDEX idx_queued  (status, created_at),
    INDEX idx_running (status, started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
SQL
```

Both tables must be added to `create_media_db.sql` as part of Phase 3, and the equivalent live SQL above must be run on all existing environments before the PHP tus server is deployed. The `tus_uploads` table is safe to create empty on running instances; no data migration required. The `probe_jobs` table is also new; existing assets already have duration and thumbnail filled, so no backfill of this table is needed.

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

// Prune old permanently-failed probe_jobs rows.
// Rows with status='failed' AND attempts >= 3 will never be retried; they accumulate
// indefinitely, bloating the table and slowing the idx_queued index scan.
// LIMIT prevents a long-running delete from blocking PATCH transactions during peak hours.
$pdo->exec(
    "DELETE FROM probe_jobs
     WHERE status = 'failed' AND created_at < NOW() - INTERVAL 30 DAY
     LIMIT 200"
);

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
//
// IMPORTANT: use the configured chunk size (8 MB default), NOT maxFileSizeBytes.
// maxFileSizeBytes is the total file size limit (e.g. 4 GB).
// Using maxFileSizeBytes here would make ceil(4 GB / 4 GB) = 1, which always passes
// regardless of actual chunk size — the check would never fire even if chunks were 1 byte.
//
// The chunk size used by TUSKit and tus-js-client is set at client init (default 8 MB).
// Server-side, use a constant or a `UPLOAD_CHUNK_SIZE_BYTES` env var (default 8 MB)
// that must be kept in sync with the client chunk size configuration.
$chunkSizeBytes = (int)(getenv('UPLOAD_CHUNK_SIZE_BYTES') ?: (string)(8 * 1024 * 1024));
$maxBlocks      = 50_000;
if ($chunkSizeBytes > 0 && ceil($uploadLength / $chunkSizeBytes) > $maxBlocks) {
    // In practice unreachable at 8 MB chunks + 4 GB max file size, but assert defensively
    http_response_code(413);
    echo json_encode(['error' => 'File too large for Azure Block Blob block limit']);
    exit;
}
```

Add `UPLOAD_CHUNK_SIZE_BYTES` to the group_vars and `.env.j2` alongside the other Phase 1 env vars (default `8388608` — 8 MB). Add this check to `handlePost()` acceptance criteria in Phase 3.

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

**Rate limiting note:** The `POST /files/` endpoint is auth-gated, limiting upload creation to authenticated users. There is no per-user concurrency limit. An authenticated user could create many concurrent pending uploads, filling `tus_uploads` and (in Azure mode) creating many uncommitted Blob containers that persist for 7 days. This is a low-priority risk for GigHive's current scale but should be revisited if the platform grows. A pragmatic mitigation is a DB check at `POST /files/`:

```php
// In TusBlockUploadService::handlePost() — before inserting the new tus_uploads row:
$pendingCount = (int)$pdo->prepare(
    'SELECT COUNT(*) FROM tus_uploads WHERE user_id = ? AND status = ? AND expires_at > NOW()'
)->execute([$userId, 'pending'])->fetchColumn();

if ($pendingCount >= $this->config->maxPendingUploadsPerToken) {
    http_response_code(429);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Too many pending uploads']);
    exit;
}
```

The threshold must come from `TusUploadConfig` (sourced from the `UPLOAD_MAX_PENDING_PER_TOKEN` env var, which in turn is set from the `tus_max_pending_uploads_per_token` group_var — **never hardcode the number `5` in PHP**). The group_var and env var are defined in Phase 1 alongside the other tus config vars. Add `UPLOAD_MAX_PENDING_PER_TOKEN={{ tus_max_pending_uploads_per_token | default(5) }}` to `.env.j2`.

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

**Sequencing constraint — validation guards must be ported before `finalizeTusUpload()` is retired:**

`finalizeTusUpload()` currently enforces allowed file types (audio/video only), MIME type checking, and max file size. These guards must not be retired along with the hook-polling logic. The required sequence for Phase 3 is:

1. Implement file-type and max-size guards in `TusBlockUploadService::handlePost()` — enforce at upload creation before any PATCH is accepted.
2. Implement MIME-sniff guard in `TusBlockUploadService::handlePatch()` — check after the final block is received.
3. Verify both guards fire correctly (manual test: attempt upload of a disallowed type; confirm 415 at POST, not after full upload).
4. Only then replace `finalizeTusUpload()` with the simple DB-lookup below.

Do not retire `finalizeTusUpload()` in the same commit that adds `TusBlockUploadService` unless step 3 is confirmed. A window where the guards are absent breaks upload validation across all deployments.

The existing `/api/uploads/finalize` endpoint (routed through `UploadController.php` → `UploadService.php`) previously called `finalizeTusUpload()` which polled for the `tus_hooks` volume notification file. That function is retired once the above sequencing is complete. In the new model the upload is already committed in DB by the time the client calls this endpoint. The replacement logic is:

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
- name: "[T-84] tus-upload.php returns 400 on plain GET (not a tus request)"
  ansible.builtin.uri:
    url: "&#123;&#123; gighive_base_url &#125;&#125;/files/"
    method: GET
    validate_certs: "&#123;&#123; gighive_validate_certs &#125;&#125;"
    status_code: 400
    headers: "&#123;&#123; {'Host': gighive_hostname_for_host_header} if (gighive_hostname_for_host_header | length) > 0 else omit &#125;&#125;"
  changed_when: false
  tags: [smoke, media_storage]

- name: "[T-85] tus-upload.php returns 401 on unauthenticated POST"
  ansible.builtin.uri:
    url: "&#123;&#123; gighive_base_url &#125;&#125;/files/"
    method: POST
    validate_certs: "&#123;&#123; gighive_validate_certs &#125;&#125;"
    status_code: 401
    headers: "&#123;&#123; {'Host': gighive_hostname_for_host_header} if (gighive_hostname_for_host_header | length) > 0 else omit &#125;&#125;"
  changed_when: false
  tags: [smoke, media_storage]
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

#### Validation Checklist — Phase 3

*9 of 12 automated (`post_build_checks` / `validate_app`); 3 manual. T-79, T-86, and the pre-task assertions in the Phase 3 body are prerequisites that must pass before deployment; T-80–T-85 run as post-deploy smoke checks; T-86b is a permanent `validate_app` warning check.*

---

**T-79 [post_build_checks]** — PHP >= 8.2 inside the Apache container (`HashContext` serialization and `readonly class` require 8.2).
> *Lifecycle: **permanent** — keep in `post_build_checks`; base image updates can silently downgrade the PHP version.*

```yaml
# Add to post_build_checks/tasks/main.yml (also used as a pre-task assertion — see Phase 3 body)
- name: "[T-79] PHP version is >= 8.2 inside the Apache container"
  community.docker.docker_container_exec:
    container: "&#123;&#123; apache_container_name &#125;&#125;"
    command:
      - php
      - -r
      - "exit(PHP_MAJOR_VERSION > 8 || (PHP_MAJOR_VERSION === 8 && PHP_MINOR_VERSION >= 2) ? 0 : 1);"
  register: php_ver_p3
  changed_when: false
  failed_when: false
  tags: [smoke, media_storage]

- name: "[T-79] Assert PHP >= 8.2"
  ansible.builtin.assert:
    that:
      - php_ver_p3.rc == 0
    fail_msg: >
      PHP version is below 8.2. Update the base image before deploying Phase 3.
  tags: [smoke, media_storage]
```

**T-80 [post_build_checks]** — `tus_uploads` table exists in `media_db` with all required columns.
> *Lifecycle: **phase gate (Tranche 1)** — remove from `post_build_checks` at Tranche 1 cleanup; schema is stable once the DDL is applied.*

```yaml
# Add to post_build_checks/tasks/main.yml
- name: "[T-80] Count expected columns in tus_uploads"
  community.docker.docker_container_exec:
    container: "&#123;&#123; mysql_container_name &#125;&#125;"
    command:
      - mysql
      - -uroot
      - media_db
      - -sN
      - -e
      - >-
        SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA='media_db'
          AND TABLE_NAME='tus_uploads'
          AND COLUMN_NAME IN (
            'upload_id','user_id','status',
            'upload_length','block_count','block_size',
            'sha256_ctx','file_type','asset_id','expires_at')
    env:
      MYSQL_PWD: "&#123;&#123; mysql_root_password &#125;&#125;"
  register: tus_uploads_cols
  changed_when: false
  no_log: true
  tags: [smoke, media_storage]

- name: "[T-80] Assert tus_uploads has all 10 expected columns"
  ansible.builtin.assert:
    that:
      - tus_uploads_cols.stdout | trim | int == 10
    fail_msg: >
      tus_uploads column count={{ tus_uploads_cols.stdout | trim }}, expected 10.
      Apply the Phase 3 DDL from create_media_db.sql before deploying.
  tags: [smoke, media_storage]
```

**T-81 [post_build_checks]** — `probe_jobs` table exists in `media_db` with all required columns.
> *Lifecycle: **phase gate (Tranche 1)** — remove from `post_build_checks` at Tranche 1 cleanup; same rationale as T-80.*

```yaml
# Add to post_build_checks/tasks/main.yml
- name: "[T-81] Count expected columns in probe_jobs"
  community.docker.docker_container_exec:
    container: "&#123;&#123; mysql_container_name &#125;&#125;"
    command:
      - mysql
      - -uroot
      - media_db
      - -sN
      - -e
      - >-
        SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA='media_db'
          AND TABLE_NAME='probe_jobs'
          AND COLUMN_NAME IN (
            'asset_id','blob_key','file_type',
            'status','attempts','started_at')
    env:
      MYSQL_PWD: "&#123;&#123; mysql_root_password &#125;&#125;"
  register: probe_jobs_cols
  changed_when: false
  no_log: true
  tags: [smoke, media_storage]

- name: "[T-81] Assert probe_jobs has all 6 expected columns"
  ansible.builtin.assert:
    that:
      - probe_jobs_cols.stdout | trim | int == 6
    fail_msg: >
      probe_jobs column count={{ probe_jobs_cols.stdout | trim }}, expected 6.
      Apply the Phase 3 DDL from create_media_db.sql before deploying.
  tags: [smoke, media_storage]
```

**T-82 [post_build_checks]** — Probe job cron file `/etc/cron.d/gighive-probe` exists inside the Apache container and references `run_probe_job.php`.
> *Lifecycle: **permanent** — keep in `post_build_checks`; a role refactor could drop the cron deployment silently.*

```yaml
# Add to post_build_checks/tasks/main.yml
- name: "[T-82] Read probe cron file inside Apache container"
  community.docker.docker_container_exec:
    container: "&#123;&#123; apache_container_name &#125;&#125;"
    command:
      - cat
      - /etc/cron.d/gighive-probe
  register: probe_cron_check
  changed_when: false
  failed_when: false
  tags: [smoke, media_storage]

- name: "[T-82] Assert probe cron file is present and references run_probe_job.php"
  ansible.builtin.assert:
    that:
      - probe_cron_check.rc == 0
      - "'run_probe_job' in probe_cron_check.stdout"
    fail_msg: >
      /etc/cron.d/gighive-probe missing or does not reference run_probe_job.php.
      Deploy gighive-probe.cron.j2 via the docker role.
  tags: [smoke, media_storage]
```

**T-83 [post_build_checks]** — `tusd` container is not running; the PHP tus handler is the sole upload target.
> *Lifecycle: **permanent** — keep in `post_build_checks`; guards against tusd being re-added to compose accidentally.*

```yaml
# Add to post_build_checks/tasks/main.yml
- name: "[T-83] Gather tusd container info"
  community.docker.docker_container_info:
    name: "&#123;&#123; tusd_container_name &#125;&#125;"
  register: tusd_info
  tags: [smoke, media_storage]

- name: "[T-83] Assert tusd container is absent or not running"
  ansible.builtin.assert:
    that:
      - not (tusd_info.exists and tusd_info.container.State.Status == 'running')
    fail_msg: >
      tusd container &#123;&#123; tusd_container_name &#125;&#125; is still running.
      Remove it from docker-compose.yml.j2 and redeploy.
  tags: [smoke, media_storage]
```

**T-84 [post_build_checks]** — `GET /files/` returns 400 (PHP tus server is live and routing correctly; not a 404 or 502 from a missing container). *(YAML snippet already in Phase 3 body — move to this checklist.)*
> *Lifecycle: **permanent** — keep in `post_build_checks`; any Apache config change could break /files/ routing.*

**T-85 [post_build_checks]** — Unauthenticated `POST /files/` returns 401 (auth enforced before any tus processing). *(YAML snippet already in Phase 3 body — move to this checklist.)*
> *Lifecycle: **permanent** — keep in `post_build_checks`; detects auth regressions on the upload endpoint.*

**T-86 [post_build_checks]** — `innodb_lock_wait_timeout >= 60` (required to cover the PATCH lock window during slow Azure `PUT Block` calls). *(Pre-task assertion in Phase 3 body; also add to post_build_checks for ongoing verification.)*
> *Lifecycle: **permanent** — keep in `post_build_checks`; MySQL config can drift after restarts or container rebuilds.*

```yaml
# Add to post_build_checks/tasks/main.yml
- name: "[T-86] Read innodb_lock_wait_timeout"
  community.docker.docker_container_exec:
    container: "&#123;&#123; mysql_container_name &#125;&#125;"
    command:
      - mysql
      - -uroot
      - -sN
      - -e
      - "SELECT @@innodb_lock_wait_timeout"
    env:
      MYSQL_PWD: "&#123;&#123; mysql_root_password &#125;&#125;"
  register: lock_timeout
  changed_when: false
  no_log: true
  tags: [smoke, media_storage]

- name: "[T-86] Assert innodb_lock_wait_timeout >= 60"
  ansible.builtin.assert:
    that:
      - lock_timeout.stdout | trim | int >= 60
    fail_msg: >
      innodb_lock_wait_timeout={{ lock_timeout.stdout | trim }}, expected >= 60.
      Add innodb_lock_wait_timeout=60 to the MySQL [mysqld] config and restart.
  tags: [smoke, media_storage]
```

**T-86b [validate_app]** — `probe_jobs` has no permanently-failed rows (warns, does not fail, so a single odd failure doesn't break a deploy).
> *Lifecycle: **permanent** — keep in `validate_app`; a non-zero count after a quiet period indicates a systematic probe failure (bad ffprobe binary, wrong media path, missing env var). Warn rather than fail so a single transient failure doesn't block deploys.*

```yaml
# Add to validate_app/tasks/main.yml
- name: "[T-86b] Count permanently-failed probe_jobs rows"
  community.docker.docker_container_exec:
    container: "&#123;&#123; mysql_container_name &#125;&#125;"
    command:
      - mysql
      - -uroot
      - -sN
      - -e
      - "SELECT COUNT(*) FROM probe_jobs WHERE status='failed' AND attempts >= 3"
    env:
      MYSQL_PWD: "&#123;&#123; mysql_root_password &#125;&#125;"
  register: failed_probe_jobs
  changed_when: false
  no_log: true
  tags: [validate, media_storage]

- name: "[T-86b] Warn if permanently-failed probe_jobs rows exist"
  ansible.builtin.debug:
    msg: >
      WARNING: {{ failed_probe_jobs.stdout | trim }} permanently-failed probe_jobs row(s) found
      (status='failed', attempts >= 3). These will be pruned by cleanup_expired_uploads.php
      after 30 days. If count is growing, check /var/log/probe_job.log for the failure reason.
  when: (failed_probe_jobs.stdout | trim | int) > 0
  tags: [validate, media_storage]
```

**T-87 [Manual]** — Full tus upload flow end-to-end: `POST /files/` → `PATCH /files/{id}` × N → final PATCH → `tus_uploads` row transitions `pending → complete`; `probe_jobs` row inserted with `status=queued`; SHA-256 in `assets` row matches `sha256sum` of the original file; `block_size` in `tus_uploads` is non-zero after the first PATCH.
> Perform with both `tus-js-client` (browser) and iOS `TUSKit`. Verify DB state with `SELECT * FROM tus_uploads WHERE upload_id='...'` and `SELECT * FROM probe_jobs ORDER BY id DESC LIMIT 1`.

**T-88 [Manual]** — Azure only: a single `writeChunk()` call issues exactly one `PUT Block`; the block appears in the Azure portal's uncommitted block list for the upload's blob key; `finalizeUpload()` calls `PUT Block List` and commits the blob; the committed blob is readable via `AzureBlobMediaBackend::getMeta()`.
> Inspect Azure portal → Storage account → Container → blob → Block list before and after finalize. Azure mode only.

**T-89 [Manual]** — Probe job runs end-to-end: one `queued` row is claimed and transitions `queued → running → done`; `assets.duration_seconds` is updated with a non-null value; for a video upload, the thumbnail blob exists at `video/thumbnails/{sha256}.png` (Azure) or in the local thumbnails directory.
> Wait up to 60 seconds for the cron to fire. Query: `SELECT status, attempts FROM probe_jobs WHERE asset_id = ?`.

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
# Thumbnail rule must be listed BEFORE the general /media/(audio|video)/... rule so it matches first.
# Only video/thumbnails/ exists — audio thumbnails never existed; do not accept /media/audio/thumbnails/.
RewriteRule ^/media/video/thumbnails/(.+)$         /api/media-stream.php [L,QSA,E=MEDIA_TYPE:video/thumbnails,E=MEDIA_KEY:$1]
RewriteRule ^/media/(audio|video)/(.+)$            /api/media-stream.php [L,QSA,E=MEDIA_TYPE:$1,E=MEDIA_KEY:$2]

# Backward-compat aliases — existing stored URLs continue to work without a data migration
# Only /video/thumbnails/ was ever used; /audio/thumbnails/ never existed.
RewriteRule ^/video/thumbnails/(.+)$               /api/media-stream.php [L,QSA,E=MEDIA_TYPE:video/thumbnails,E=MEDIA_KEY:$1]
RewriteRule ^/(audio|video)/(.+)$                  /api/media-stream.php [L,QSA,E=MEDIA_TYPE:$1,E=MEDIA_KEY:$2]
```

Both old and new paths resolve to the same `media-stream.php` handler. `media-stream.php` extracts `MEDIA_TYPE` and `MEDIA_KEY` from the request environment variables (or from the URI path as a fallback). No DB migration of stored asset URLs is required.

**API URL standardization:** New asset records written after Phase 4 should have their `asset_url` (or equivalent field) populated using the `/media/` prefix. Existing records with `/audio/` or `/video/` URLs remain valid via the backward-compat rules above and do not need rewriting. The read path is identical regardless of which prefix was used.

**Phase 11 step 10 prerequisite:** Before removing host media bind mounts, verify that both `/audio/{key}` and `/media/audio/{key}` return the correct bytes and correct `Content-Type` headers for a test asset.

**Guest gallery nonce authentication (required in Phase 4):**

The guest gallery (`api/guest-gallery.php`) serves thumbnail URLs in the form `/video/thumbnails/<sha256>.png?nonce=<nonce>`. Today Apache handles this: `SetEnvIfExpr` detects the `nonce=` query string and sets `gallery_nonce_auth`, satisfying `Require env gallery_nonce_auth` without credentials. After Phase 4 routes `/video/thumbnails/` through `media-stream.php`, the same URL hits PHP instead. PHP currently only checks `X-Upload-Token` — it ignores the nonce — and returns 401. **Every guest user (event attendees, wedding guests) loses thumbnail loading the moment Phase 4 deploys.** This must be fixed in Phase 4.

**Fix:** Add gallery nonce validation as a third credential path in `media-stream.php`, alongside Basic Auth and `X-Upload-Token`. Use `GuestCredentialResolver::resolveNonceOrToken()` — this is the shared service already used by `guest-gallery.php`, `guest-report.php`, and `guest-stream.php` for exactly this purpose. Do not inline a custom SQL query; the resolver handles both nonce auth paths correctly.

```php
// In media-stream.php — authorization block
// Credential path 3: gallery nonce (query string) — for guest gallery thumbnail requests.
// Uses the same shared resolver as guest-gallery.php, guest-report.php, guest-stream.php.
use Production\Api\Services\GuestCredentialResolver;

$nonce = $_GET['nonce'] ?? '';
if ($nonce !== '' && !$authorized) {
    // Validate nonce format before hitting the DB (mirrors guest-gallery.php line 11)
    if (preg_match('/^[A-Za-z0-9_\-]{30,43}$/', $nonce) === 1) {
        $resolver = new GuestCredentialResolver($pdo);
        try {
            $result = $resolver->resolveNonceOrToken($nonce);
            if ($result !== false) {
                // Check expiry (resolver returns expires_at; compare here as guest-gallery.php does)
                $expiry = new \DateTime($result['expires_at']);
                if ($expiry > new \DateTime('now')) {
                    $authorized = true;
                }
            }
        } catch (\PDOException $e) {
            http_response_code(500);
            exit;
        }
    }
    // Invalid nonce format or expired/unknown nonce: fall through to 401 below
}
```

`GuestCredentialResolver` is in `src/Services/GuestCredentialResolver.php` and requires the `$pdo` instance already constructed earlier in `media-stream.php`. It handles both lookup paths: `status_nonce → approved upload → event_upload_tokens` and `token_hash → active event_upload_tokens`. No custom SQL is needed in `media-stream.php`.

**Also required in Phase 4:** Update `guest-gallery.php` so that when `media-stream.php` is the active handler, the `thumbnail_url` it generates continues to include `?nonce=<nonce>` so `media-stream.php` receives the credential. The current format (`/video/thumbnails/<sha256>.png?nonce=<nonce>`) already does this — no change needed to `guest-gallery.php` itself as long as the backward-compat `RewriteRule` for `/video/thumbnails/` passes the query string through (the `[QSA]` flag on the RewriteRule already does this).

The endpoint must:
1. Validate `{key}` format against a strict regex before any blob operation
2. Authorize the request — three credential paths in priority order:
   - Basic Auth (`Authorization: Basic ...`) — authenticated admin/uploader users
   - Upload token (`X-Upload-Token` header) — upload-event-scoped token
   - Gallery nonce (`?nonce=` query parameter) — guest gallery thumbnail access (see above); **required for Phase 4**
   - Session-cookie auth for browser `<img>` admin panel contexts is a follow-on addition (see Remaining — Follow-on Tasks)
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

The range is forwarded to `AzureBlobMediaBackend::streamRange()` which sets the `Range: bytes=X-Y` header on the Blob REST GET request. Azure Blob Storage natively supports byte-range reads; this is not a full-download-then-slice approach.

**Smoke test requirement:** `post_build_checks/tasks/main.yml` must include:

```yaml
- name: "[T-90] media-stream.php returns 401 without auth"
  ansible.builtin.uri:
    url: "&#123;&#123; gighive_base_url &#125;&#125;/api/media-stream.php"
    method: GET
    validate_certs: "&#123;&#123; gighive_validate_certs &#125;&#125;"
    status_code: [400, 401]
    headers: "&#123;&#123; {'Host': gighive_hostname_for_host_header} if (gighive_hostname_for_host_header | length) > 0 else omit &#125;&#125;"
  changed_when: false
  tags: [smoke, media_storage]
```

**Thumbnail authentication — acceptance criteria for Phase 4:**

| Context | How auth is satisfied | Status after Phase 4 |
|---|---|---|
| Guest gallery iOS app (`URLSession`, `?nonce=` in URL) | Gallery nonce in query string — validated by `media-stream.php` nonce path | **Works — required Phase 4 fix** |
| Guest gallery browser (`<img src="...?nonce=...">`) | Same nonce path | **Works — required Phase 4 fix** |
| Browser admin panel `<img src="/media/video/thumbnails/...">` (no nonce) | Session cookie — **not yet implemented** | Returns 401 until cookie auth added (Follow-on Tasks) |
| iOS `UIImageView` with plain URL, no nonce | No credential — **will return 401** | Not currently used in the iOS app |

The guest gallery is the only active thumbnail consumer today; the admin panel thumbnail breakage is a pre-existing gap that exists before this refactor (admin thumbnails are not currently displayed via `<img>` tags in production).

Before proceeding to Phase 11 step 10, verify explicitly:
1. Guest gallery thumbnails load correctly via nonce path (Phase 4 acceptance test)
2. Session-cookie auth has been added to `media-stream.php` if admin panel thumbnail display has been added (prerequisite)
2. Load the admin panel in a browser — confirm thumbnails render in `<img>` tags (cookie auth)
3. In the iOS app, confirm thumbnails load via `URLSession` (not `UIImageView` with a raw URL)
4. If any iOS code uses `UIImageView` + plain URL for thumbnails, update it to use `URLSession` + auth headers before deploying Phase 4

Add this as a checklist item in Phase 11 step 9.

#### SonarQube / Best-Practice Notes — Phase 4

- **RSPEC-3776 (cognitive complexity):** `media-stream.php` must decompose into `validateKey()`, `authenticateRequest()`, `parseRangeHeader()`, `buildStreamResponse()`. The main file should read as a sequence of four calls with early exits, not nested conditionals.
- **RSPEC-6426 (null dereference):** `$_SERVER['HTTP_RANGE']` is `string|undefined`; always use `?? null` before passing to the regex. Never pass an unvalidated header string to `header()`.
- **Range edge case:** A request with `Range: bytes=0-` (open-ended) is valid and must resolve `$end = $meta->size - 1`. The regex `^bytes=(\d+)-(\d*)$` already handles this via `$m[2] !== ''` check; verify in integration test.

#### Validation Checklist — Phase 4

*3 of 7 automated (`post_build_checks`); 4 manual. T-90 YAML snippet is already in the Phase 4 body — consolidate here. Functional correctness of byte delivery, range seeking, and backward-compat requires manual verification against a real asset.*

---

**T-90 [post_build_checks]** — `GET /api/media-stream.php` (no key) returns 401 without auth (PHP endpoint is live; not a 404). *(YAML snippet already in Phase 4 body — move to this checklist.)*
> *Lifecycle: **permanent** — keep in `post_build_checks`; any Apache config change or missing file would produce a 404 instead.*

**T-91 [post_build_checks]** — `GET /media/audio/` returns 400 or 401 (PHP handler is routing correctly; not a 404 or 502 from a missing file).
> *Lifecycle: **permanent** — keep in `post_build_checks`; detects Apache RewriteRule regressions on the canonical media path.*

```yaml
# Add to post_build_checks/tasks/main.yml
- name: "[T-91] /media/audio/ returns 400 or 401 (PHP handler live)"
  ansible.builtin.uri:
    url: "&#123;&#123; gighive_base_url &#125;&#125;/media/audio/"
    method: GET
    validate_certs: "&#123;&#123; gighive_validate_certs &#125;&#125;"
    status_code: [400, 401]
    headers: "&#123;&#123; {'Host': gighive_hostname_for_host_header} if (gighive_hostname_for_host_header | length) > 0 else omit &#125;&#125;"
  changed_when: false
  tags: [smoke, media_storage]
```

**T-92 [post_build_checks]** — Old-path `GET /audio/` returns 401 (PHP-mediated via backward-compat `RewriteRule`; not an Apache static 404 or 403).
> *Lifecycle: **permanent** — keep in `post_build_checks`; guards the backward-compat routing that existing iOS clients depend on.*

```yaml
# Add to post_build_checks/tasks/main.yml
- name: "[T-92] Old /audio/ path returns 401 (PHP-mediated, not Apache static)"
  ansible.builtin.uri:
    url: "&#123;&#123; gighive_base_url &#125;&#125;/audio/"
    method: GET
    validate_certs: "&#123;&#123; gighive_validate_certs &#125;&#125;"
    status_code: [400, 401]
    headers: "&#123;&#123; {'Host': gighive_hostname_for_host_header} if (gighive_hostname_for_host_header | length) > 0 else omit &#125;&#125;"
  changed_when: false
  tags: [smoke, media_storage]
```

**T-93 [Manual]** — Full-file `GET /media/audio/{key}` with a valid auth token returns `200 OK` with the correct `Content-Type`, correct `Content-Length` matching the file size, and `Accept-Ranges: bytes` header; byte content matches the original file.
> Use `curl -H 'X-Upload-Token: ...' -o /tmp/test.mp3` and compare `sha256sum` against the original.

**T-94 [Manual]** — Range request `GET /media/audio/{key}` with `Range: bytes=0-65535` returns `206 Partial Content` with correct `Content-Range: bytes 0-65535/{total}` and `Content-Length: 65536`; bytes delivered match the corresponding byte range of the original file.
> Also test a mid-file range and an open-ended `Range: bytes=N-` to exercise the `$m[2] !== ''` edge case.

**T-95 [Manual]** — `GET /audio/{key}` (old path) delivers identical bytes to `GET /media/audio/{key}` for the same asset (backward-compat `RewriteRule` is functioning).
> Compare `sha256sum` of both responses. A mismatch indicates the backward-compat rule is routing to the wrong handler or key.

**T-96 [Manual]** — A URL with an invalid `$type` segment (e.g. `GET /media/documents/{key}`) returns `400 Bad Request`; a URL with a key that fails the regex returns `400`; neither returns `500`.
> Verify the key validation guard fires before any `MediaStorageService` call is made (no backend error logged alongside the 400).

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

**Retire `blobfuse2` Ansible role (Phase 5 step 7):**

The `blobfuse2` role mounts Azure Blob Storage as a FUSE filesystem. It was never applied in production and has no references in `site.yml`. Its continued presence in the repo creates a risk of accidental future inclusion. Remove it in Phase 5 once the new storage abstraction is confirmed stable:

```bash
git rm -r ansible/roles/blobfuse2/
git commit -m "Remove retired blobfuse2 Ansible role — replaced by AzureBlobMediaBackend"
```

Confirm it is gone from the repo and no playbook or `site.yml` imports it:

```bash
grep -r "blobfuse2" ansible/
# Expected: no output
```

**Update `docs/media_file_location_variables.md` (Phase 5 step 8):**

The document currently describes only the `local` model (VM host bind mounts → container). After Phase 5, update it to describe all three models:

- **`local` mode** — bind mounts from VM host (`{{ video_dir }}`, `{{ audio_dir }}`) into the container; PHP reads via `LocalMediaBackend`; `MEDIA_SEARCH_DIRS` retired
- **`azure_blob` mode** — no local media bind mounts; PHP reads/writes via `AzureBlobMediaBackend` over REST; `MEDIA_LOCAL_*` dirs unused in this mode
- **`azure_blob_with_local_fallback` mode** — Tranche 2 / Phase 11 only; both bind mounts and Blob configured simultaneously during backfill window

Also update the variable glossary to add the new Phase 1 env vars (`GIGHIVE_MEDIA_STORAGE_BACKEND`, `MEDIA_LOCAL_AUDIO_DIR`, `MEDIA_LOCAL_VIDEO_DIR`, `MEDIA_LOCAL_THUMB_DIR`, `TUS_LOCAL_STAGING_DIR`) and mark `MEDIA_SEARCH_DIRS` as retired.

#### Validation Checklist — Phase 5

*6 of 8 automated (`post_build_checks` / `validate_app`); 2 manual. Prerequisite group_var: `smoke_test_audio_sha256` — SHA-256 of a known audio asset present on the VirtualBox host media directory, used by T-65. T-68b is a permanent `validate_app` warning check.*

---

**T-64 [post_build_checks]** — `/media/audio/` returns 401 (not 403/500) — `LocalMediaBackend` is live and enforcing auth.
> *Lifecycle: **permanent** — keep in `post_build_checks`; detects routing regressions on local/VirtualBox deployments.*

```yaml
# Add to post_build_checks/tasks/main.yml
- name: "[T-64] /media/audio/ returns 401 without auth (LocalMediaBackend live)"
  ansible.builtin.uri:
    url: "&#123;&#123; gighive_base_url &#125;&#125;/media/audio/"
    method: GET
    validate_certs: "&#123;&#123; gighive_validate_certs &#125;&#125;"
    status_code: [400, 401, 404]
    headers: "&#123;&#123; {'Host': gighive_hostname_for_host_header} if (gighive_hostname_for_host_header | length) > 0 else omit &#125;&#125;"
  changed_when: false
  when: gighive_media_storage_backend == 'local'
  tags: [smoke, media_storage]
```

**T-65 [post_build_checks]** — Range request to a known audio asset returns 206 with correct `Content-Range` header.
> *Lifecycle: **permanent** — keep in `post_build_checks`; detects regression in the range-request path (when smoke_test_audio_sha256 is set).*

```yaml
# Add to post_build_checks/tasks/main.yml
- name: "[T-65] Range request returns 206 for known audio asset (local mode)"
  ansible.builtin.uri:
    url: "&#123;&#123; gighive_base_url &#125;&#125;/media/audio/&#123;&#123; smoke_test_audio_sha256 &#125;&#125;.mp3"
    method: GET
    url_username: "&#123;&#123; uploader_user &#125;&#125;"
    url_password: "&#123;&#123; gighive_uploader_password &#125;&#125;"
    force_basic_auth: yes
    validate_certs: "&#123;&#123; gighive_validate_certs &#125;&#125;"
    headers:
      Range: "bytes=0-4095"
      Host: "&#123;&#123; gighive_hostname_for_host_header if (gighive_hostname_for_host_header | length) > 0 else omit &#125;&#125;"
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
      # ansible.builtin.uri lowercases all response headers and exposes them as direct
      # keys on the registered result (ansible-core >= 2.14). 'Content-Range' → content_range.
      # If you see this assertion fail with "content_range is undefined" and the curl
      # manual check passes, verify Ansible version: ansible --version | grep 'core'.
      # On older ansible-core (< 2.14) headers are only in range_response.msg; use:
      #   range_response.msg | regex_search('(?i)content-range: bytes 0-4095/')
      - range_response.content_range is defined
      - range_response.content_range is match("^bytes 0-4095/")
    fail_msg: >
      Content-Range header missing or incorrect.
      Got: &#123;&#123; range_response.content_range | default('none') &#125;&#125;
      If content_range is 'none', check Ansible version and header key format (see comment above).
  when:
    - gighive_media_storage_backend == 'local'
    - smoke_test_audio_sha256 is defined
  tags: [smoke, media_storage]
```

**T-66 [post_build_checks]** — Direct `/audio/` request returns 401 (PHP-mediated, not Apache static file serving).
> *Lifecycle: **permanent** — keep in `post_build_checks`; a 403 here would indicate Apache static serving is active again.*

```yaml
# Add to post_build_checks/tasks/main.yml
# /audio/ now routes through media-stream.php RewriteRule → 401 without auth
# A 403 would indicate Apache is still serving it as a static directory listing
- name: "[T-66] /audio/ returns 401 — PHP handler active, not Apache static serving"
  ansible.builtin.uri:
    url: "&#123;&#123; gighive_base_url &#125;&#125;/audio/test.mp3"
    method: GET
    validate_certs: "&#123;&#123; gighive_validate_certs &#125;&#125;"
    status_code: [401]
    headers: "&#123;&#123; {'Host': gighive_hostname_for_host_header} if (gighive_hostname_for_host_header | length) > 0 else omit &#125;&#125;"
  changed_when: false
  when: gighive_media_storage_backend == 'local'
  tags: [smoke, media_storage]
```

**T-67 [post_build_checks]** — Audio and video bind mounts are present in local mode. *(Covered by T-12.)*
> *Lifecycle: **permanent** — covered by T-12; no separate task needed.*

**T-68 [post_build_checks]** — `MEDIA_SEARCH_DIRS` env var is absent from the container (retired).
> *Lifecycle: **permanent** — keep in `post_build_checks`; detects accidental re-introduction of the retired env var.*

```yaml
# Add to post_build_checks/tasks/main.yml
- name: "[T-68] MEDIA_SEARCH_DIRS env var is not set in container (retired)"
  ansible.builtin.command: >
    docker exec -i "&#123;&#123; apache_container_name &#125;&#125;" printenv MEDIA_SEARCH_DIRS
  register: media_search_dirs_check
  changed_when: false
  failed_when: media_search_dirs_check.rc == 0
  when: gighive_media_storage_backend == 'local'
  tags: [smoke, media_storage]
  # rc=1 means the var is not set — which is the expected (passing) state
```

**T-68b [validate_app]** — No orphaned `tus_uploads` rows (status=`complete`, `asset_id` IS NULL, older than 1 hour). A non-zero count means a DB write failed after a successful file commit; warns rather than fails so a single transient race doesn't block deploys.
> *Lifecycle: **permanent** — keep in `validate_app` for both local and Azure modes; detects the broken-pipeline case where a blob or local file is committed but the `assets` row was never written.*

```yaml
# Add to validate_app/tasks/main.yml
- name: "[T-68b] Count orphaned tus_uploads rows (complete but no asset_id)"
  community.docker.docker_container_exec:
    container: "&#123;&#123; mysql_container_name &#125;&#125;"
    command:
      - mysql
      - -uroot
      - -sN
      - -e
      - "SELECT COUNT(*) FROM tus_uploads WHERE status='complete' AND asset_id IS NULL AND created_at < NOW() - INTERVAL 1 HOUR"
    env:
      MYSQL_PWD: "&#123;&#123; mysql_root_password &#125;&#125;"
  register: orphan_uploads
  changed_when: false
  no_log: true
  tags: [validate, media_storage]

- name: "[T-68b] Warn if orphaned tus_uploads rows exist"
  ansible.builtin.debug:
    msg: >
      WARNING: {{ orphan_uploads.stdout | trim }} orphaned tus_uploads row(s) found
      (status='complete', asset_id IS NULL, older than 1 hour).
      This means the final DB write to 'assets' failed after the file/blob was committed.
      Recovery: for each row, verify the file/blob exists, then manually insert the
      assets row and update tus_uploads SET asset_id=<new_id> WHERE upload_id=<upload_id>.
      Diagnostic query:
        SELECT upload_id, blob_key, created_at FROM tus_uploads
        WHERE status='complete' AND asset_id IS NULL AND created_at < NOW() - INTERVAL 1 HOUR;
  when: (orphan_uploads.stdout | trim | int) > 0
  tags: [validate, media_storage]
```

**T-69 [Manual]** — Full regression pass on a VirtualBox deployment: audio upload, video upload, full-file playback, range seek, thumbnail in admin panel.
> Covers Phase 2–4 acceptance criteria (Build Order items 1–13) on local backends end-to-end.

**T-70 [Manual]** — iOS `TUSKit` upload completes via `LocalFileTusBackend`; probe job runs; asset appears in the app.
> Requires a real iOS device or simulator with the GigHive app pointed at the VirtualBox host.

---

### Tranche 1 Cleanup — Remove Phase-Gate Checks from `post_build_checks`

**When:** After Phase 5 is verified stable on all local and VirtualBox inventories (T-69 and T-70 signed off).

**Rationale:** The five checks below confirmed structural integrity of the Phase 2–3 initial deployment. They are one-time validations. Retaining them on every subsequent deploy adds noise without new signal and will produce false failures if class paths or DB schemas are legitimately changed later.

**File to edit:** `ansible/roles/post_build_checks/tasks/main.yml`

Remove the following task pairs — each check consists of a command task and a subsequent `Assert` task; remove both:

| T-N | Task `name:` string to search for and remove |
|---|---|
| T-71 | `[T-71] composer dump-autoload succeeds inside Apache container` |
| T-72 | `[T-72] PHP syntax check on Phase 2 service files` |
| T-74 | `[T-74] MediaStorageService::make() instantiates without exception (local mode)` |
| T-80 | `[T-80] Count expected columns in tus_uploads` |
| T-81 | `[T-81] Count expected columns in probe_jobs` |

**After removal:** Run `ansible-playbook --tags smoke,media_storage` against a local/VirtualBox inventory. Confirm all remaining tasks pass and no task references a variable set only by a removed task.

**Also consider at this step:** Add T-71 and T-72 equivalents to the CI pipeline (lint stage) so they continue to run pre-deploy where they belong.

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
> *Lifecycle: **permanent** — keep in `validate_app`; ensures the probe job pipeline is running correctly on each deploy.*

```yaml
# Add to validate_app/tasks/main.yml
- name: "[T-14] Query count of video assets with thumbnail_blob_key set"
  ansible.builtin.command: >
    docker exec -i "&#123;&#123; mysql_container_name &#125;&#125;"
      sh -lc 'mysql -uroot -p"&#123;&#123; mysql_root_password &#125;&#125;" media_db -sN -e
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
> *Lifecycle: **permanent** — keep in `validate_app`; confirms the blob written by the probe job is accessible.*

```yaml
# Add to validate_app/tasks/main.yml
- name: "[T-15] Thumbnail blob exists at expected key in Blob storage"
  ansible.builtin.command: >
    docker exec -i "&#123;&#123; apache_container_name &#125;&#125;"
      sh -lc 'php -r "
        require_once \"/var/www/html/vendor/autoload.php\";
        \$s = \Production\Api\Services\MediaStorageService::make();
        echo \$s->exists(\"video/thumbnails/&#123;&#123; smoke_test_video_sha256 &#125;&#125;.png\") ? \"1\" : \"0\";
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
    fail_msg: "Thumbnail blob not found for video/thumbnails/&#123;&#123; smoke_test_video_sha256 &#125;&#125;.png"
  when:
    - gighive_media_storage_backend == 'azure_blob'
    - smoke_test_video_sha256 is defined
  tags: [media_storage]
```

**T-16 [validate_app]** — Downloaded thumbnail is a valid PNG file.
> *Lifecycle: **permanent** — keep in `validate_app`; detects probe job failures that produce corrupted thumbnails.*

**T-17 [validate_app]** — Thumbnail dimensions are non-zero. *(Runs as part of the same task block as T-16.)*
> *Lifecycle: **permanent** — keep in `validate_app`; same task block as T-16; runs as part of T-16 YAML.*

```yaml
# Add to validate_app/tasks/main.yml
- name: "[T-16/T-17] Download thumbnail blob and verify PNG validity and dimensions"
  ansible.builtin.command: >
    docker exec -i "&#123;&#123; apache_container_name &#125;&#125;"
      sh -lc '
        php -r "
          require_once \"/var/www/html/vendor/autoload.php\";
          \$s = \Production\Api\Services\MediaStorageService::make();
          \$tmp = sys_get_temp_dir() . \"/smoke_thumb_&#123;&#123; smoke_test_video_sha256 &#125;&#125;.png\";
          file_put_contents(\$tmp, stream_get_contents(\$s->streamRaw(\"video/thumbnails/&#123;&#123; smoke_test_video_sha256 &#125;&#125;.png\")));
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
    fail_msg: "Thumbnail PNG invalid or zero dimensions. Output: &#123;&#123; thumb_dimensions.stdout &#125;&#125; &#123;&#123; thumb_dimensions.stderr &#125;&#125;"
  when:
    - gighive_media_storage_backend == 'azure_blob'
    - smoke_test_video_sha256 is defined
  tags: [media_storage]
```

**T-18 [post_build_checks]** — No thumbnail files persist on VM disk in Azure mode.
> *Lifecycle: **permanent** — keep in `post_build_checks`; detects probe job writing temp files to disk instead of Blob.*

```yaml
# Add to post_build_checks/tasks/main.yml
- name: "[T-18] Count thumbnail files on VM disk (should be 0 in Azure mode)"
  ansible.builtin.command: >
    docker exec -i "&#123;&#123; apache_container_name &#125;&#125;"
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
      &#123;&#123; disk_thumb_count.stdout | trim &#125;&#125; thumbnail file(s) found on VM disk in Azure mode.
      Probe job may be writing to disk instead of Blob.
  when: gighive_media_storage_backend == 'azure_blob' and disk_thumb_count.rc == 0
  tags: [smoke, media_storage]
```

**T-19 [post_build_checks]** — `GET /media/video/thumbnails/{sha256}.png` returns 200 with `Content-Type: image/png`.
> *Lifecycle: **permanent** — keep in `post_build_checks`; detects thumbnail routing regressions (when smoke_test_video_sha256 is set).*

```yaml
# Add to post_build_checks/tasks/main.yml
- name: "[T-19] Thumbnail served via media-stream.php returns 200 image/png"
  ansible.builtin.uri:
    url: "&#123;&#123; gighive_base_url &#125;&#125;/media/video/thumbnails/&#123;&#123; smoke_test_video_sha256 &#125;&#125;.png"
    method: GET
    url_username: "&#123;&#123; uploader_user &#125;&#125;"
    url_password: "&#123;&#123; gighive_uploader_password &#125;&#125;"
    force_basic_auth: yes
    validate_certs: "&#123;&#123; gighive_validate_certs &#125;&#125;"
    status_code: [200]
    headers: "&#123;&#123; {'Host': gighive_hostname_for_host_header} if (gighive_hostname_for_host_header | length) > 0 else omit &#125;&#125;"
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
    fail_msg: "Expected Content-Type: image/png, got: &#123;&#123; thumb_http.content_type | default('none') &#125;&#125;"
  when:
    - gighive_media_storage_backend == 'azure_blob'
    - smoke_test_video_sha256 is defined
  tags: [smoke, media_storage]
```

**T-20 [Manual]** — Thumbnail loads in the browser admin panel.
> Requires a real browser session with cookie authentication. The `uri` module cannot replicate session-cookie auth for the admin panel's same-origin `<img>` requests.

**T-21 [validate_app]** — Audio probe job completes with `duration_seconds` set but `thumbnail_blob_key` remains null.
> *Lifecycle: **permanent** — keep in `validate_app`; detects if audio processing starts incorrectly generating thumbnails.*

```yaml
# Add to validate_app/tasks/main.yml
- name: "[T-21] Audio assets have no thumbnail_blob_key after probe job"
  ansible.builtin.command: >
    docker exec -i "&#123;&#123; mysql_container_name &#125;&#125;"
      sh -lc 'mysql -uroot -p"&#123;&#123; mysql_root_password &#125;&#125;" media_db -sN -e
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
      &#123;&#123; audio_thumb_count.stdout | trim &#125;&#125; audio asset(s) have thumbnail_blob_key set.
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
> *Lifecycle: **permanent** — keep in `post_build_checks`; detects IMDS connectivity failures that would silently break all Blob operations.*

**T-23 [post_build_checks]** — `access_token` is a non-empty JWT string (starts with `eyJ`). *(Runs in the same task block as T-22.)*
> *Lifecycle: **permanent** — keep in `post_build_checks`; same task block as T-22; runs as part of T-22 YAML.*

```yaml
# Add to post_build_checks/tasks/main.yml
- name: "[T-22/T-23] Fetch IMDS Bearer token from inside Apache container"
  ansible.builtin.command: >
    docker exec -i "&#123;&#123; apache_container_name &#125;&#125;"
      curl -sf -H "Metadata: true" --connect-timeout 5
        "http://169.254.169.254/metadata/identity/oauth2/token\
?api-version=2018-02-01\
&resource=https%3A%2F%2Fstorage.azure.com%2F\
&client_id=&#123;&#123; azure_identity_client_id &#125;&#125;"
  register: imds_token_raw
  changed_when: false
  no_log: true
  when: gighive_media_storage_backend == 'azure_blob'
  tags: [smoke, media_storage]

- name: "[T-22/T-23] Parse IMDS token response"
  ansible.builtin.set_fact:
    imds_token_json: "&#123;&#123; imds_token_raw.stdout | from_json &#125;&#125;"
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
> *Lifecycle: **permanent** — keep in `post_build_checks`; detects RBAC/identity regressions that would break media access.*

```yaml
# Add to post_build_checks/tasks/main.yml
- name: "[T-24] Bearer token accepted by Azure Blob REST (container HEAD)"
  ansible.builtin.command: >
    docker exec -i "&#123;&#123; apache_container_name &#125;&#125;"
      curl -sf -o /dev/null -w "%{http_code}"
        -H "Authorization: Bearer &#123;&#123; imds_token_json.access_token &#125;&#125;"
        -H "x-ms-version: 2020-04-08"
        "https://&#123;&#123; azure_blob_account_name &#125;&#125;.blob.core.windows.net/\
&#123;&#123; azure_blob_container &#125;&#125;?restype=container"
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
      Azure Blob rejected the Bearer token — got HTTP &#123;&#123; blob_auth_code.stdout | trim &#125;&#125;.
      Check Managed Identity RBAC assignment (Storage Blob Data Contributor).
  when: gighive_media_storage_backend == 'azure_blob'
  tags: [smoke, media_storage]
```

**T-25 [Manual]** — APCu cache is hit on the second IMDS request within the same minute (only one HTTP call made).
> Verifying cache-hit behaviour requires either a `strace` on the PHP process or a temporary `error_log()` call in `AzureIdentityTokenCache`. Not automatable without modifying production code.

**T-26 [Manual]** — IMDS token refreshes ~5 minutes before expiry over a 55-minute observation window.
> Requires a long-running observation window. Can be approximated in a test environment by temporarily lowering `EXPIRY_BUFFER_SECONDS`, but that requires a code change.

**T-27 [post_build_checks]** — No Bearer token strings in Apache error or access logs.
> *Lifecycle: **permanent** — keep in `post_build_checks`; ongoing security regression check; a logging change could inadvertently expose tokens.*

```yaml
# Add to post_build_checks/tasks/main.yml
- name: "[T-27] Scan Apache logs for leaked Bearer token strings"
  ansible.builtin.command: >
    docker exec -i "&#123;&#123; apache_container_name &#125;&#125;"
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
      Run: docker exec &#123;&#123; apache_container_name &#125;&#125;
        grep -rn "Bearer ey" /var/log/apache2/ /var/www/html/logs/
  when: gighive_media_storage_backend == 'azure_blob'
  tags: [smoke, media_storage]
```

**T-28 [post_build_checks]** — No SAS query parameters appear in runtime `/files/` or `/media/` access log entries.
> *Lifecycle: **permanent** — keep in `post_build_checks`; detects any code path that reverts to SAS-based access for runtime media.*

```yaml
# Add to post_build_checks/tasks/main.yml
- name: "[T-28] Scan access logs for SAS query params in runtime endpoints"
  ansible.builtin.command: >
    docker exec -i "&#123;&#123; apache_container_name &#125;&#125;"
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
> *Lifecycle: **permanent** — keep in `post_build_checks`; detects 2bootstrap.sh idempotency failures that would corrupt group vars on re-runs.*

```yaml
# Add to post_build_checks/tasks/main.yml
# Runs on the Ansible controller, not the VM
- name: "[T-31] azure.yml has no duplicate azure_blob_account_name entries"
  ansible.builtin.shell: >
    grep -c "^azure_blob_account_name:"
      "&#123;&#123; inventory_dir &#125;&#125;/group_vars/azure.yml"
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
      &#123;&#123; acct_key_count.stdout | trim &#125;&#125; entries for azure_blob_account_name in azure.yml
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
> *Lifecycle: **permanent** — covered by T-13; no separate task needed.*

See T-13 YAML above.

**T-40 [post_build_checks]** — Import worker no longer contains a direct `uploadBlobFromFile()` call.
> *Lifecycle: **phase gate (Tranche 2)** — remove from `post_build_checks` at Tranche 2 cleanup; one-time code structure check; the abstraction is stable once confirmed.*

```yaml
# Add to post_build_checks/tasks/main.yml
- name: "[T-40] import_media_zip_worker_azure.php has no direct uploadBlobFromFile() call"
  ansible.builtin.command: >
    docker exec -i "&#123;&#123; apache_container_name &#125;&#125;"
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
> *Lifecycle: **phase gate (Tranche 2)** — remove from `post_build_checks` at Tranche 2 cleanup; same rationale as T-40.*

```yaml
# Add to post_build_checks/tasks/main.yml
- name: "[T-41] export_media_worker_azure.php has no direct downloadBlobToFile() call"
  ansible.builtin.command: >
    docker exec -i "&#123;&#123; apache_container_name &#125;&#125;"
      grep -l "downloadBlobToFile"
        /var/www/html/src/export_media_worker_azure.php
  register: export_direct_call
  changed_when: false
  failed_when: export_direct_call.rc == 0
  when: gighive_media_storage_backend == 'azure_blob'
  tags: [smoke, media_storage]
```

**T-42 [post_build_checks]** — No `glob()` calls remain in the catalog scan path for media files.
> *Lifecycle: **phase gate (Tranche 2)** — remove from `post_build_checks` at Tranche 2 cleanup; one-time code structure check; the catalog scan is stable once confirmed.*

```yaml
# Add to post_build_checks/tasks/main.yml
# Adjust the path pattern to match the actual catalog scan file(s) in this codebase
- name: "[T-42] No glob() calls scanning media directories in catalog code"
  ansible.builtin.command: >
    docker exec -i "&#123;&#123; apache_container_name &#125;&#125;"
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
      &#123;&#123; catalog_glob_count.stdout | trim &#125;&#125; glob() call(s) referencing media paths found.
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

/**
 * Map a MIME type to the file extension used in the stored key.
 * Must match the extension the asset was originally stored with.
 * Extend this map if new MIME types are added to the allowlist.
 */
function mimeToExt(string $mime): string
{
    return match ($mime) {
        'audio/mpeg', 'audio/mp3' => 'mp3',
        'audio/wav'               => 'wav',
        'audio/aac'               => 'aac',
        'video/mp4'               => 'mp4',
        'video/quicktime'         => 'mov',
        'video/webm'              => 'webm',
        default                   => throw new \RuntimeException("Unknown MIME type for backfill: {$mime}"),
    };
}

// Enumerate all assets with a known checksum
$assets = $pdo->query(
    "SELECT id, file_type, checksum_sha256, mime_type FROM assets
     WHERE checksum_sha256 IS NOT NULL AND checksum_sha256 != ''
     ORDER BY id"
)->fetchAll(PDO::FETCH_ASSOC);

$ok = $fail = $skip = 0;
foreach ($assets as $asset) {
    $ext     = mimeToExt($asset['mime_type']);   // 'audio/mpeg' → 'mp3' etc.
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
> *Lifecycle: **permanent** — keep in `post_build_checks`; mirrors T-83; keep in post_build_checks for all environments.*

```yaml
# Add to post_build_checks/tasks/main.yml
- name: "[T-47] tusd container is not running"
  ansible.builtin.command: >
    docker ps --filter "name=tusd" --format '&#123;% raw %&#125;&#123;&#123;.Names&#125;&#125;&#123;% endraw %&#125;'
  register: tusd_running
  changed_when: false
  tags: [smoke, media_storage]

- name: "[T-47] Assert tusd container is stopped"
  ansible.builtin.assert:
    that:
      - tusd_running.stdout | trim == ""
    fail_msg: "tusd container is still running: &#123;&#123; tusd_running.stdout &#125;&#125;"
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
> *Lifecycle: **permanent** — covered by T-8; no separate task needed.*

---

**Step 6 (Terraform Phase 6 applied)**

**T-52** — All Phase 6 checks pass. *(Run T-3, T-6 from Phase 6 checklist above.)*

---

**Steps 7–9 (Blob live, backfill, verify)**

**T-53 [Manual]** — New upload lands in Blob; `assets` row has non-null `checksum_sha256`; `probe_jobs` transitions `queued → running → done`; thumbnail blob exists.
> Functional end-to-end upload test on the live Azure deployment during the migration window.

**T-54 [post_build_checks]** — `GIGHIVE_MEDIA_STORAGE_BACKEND` is `azure_blob_with_local_fallback` during the migration window.
> *Lifecycle: **migration window only** — remove when backend reverts to `azure_blob` at Phase 11 step 9.*

```yaml
# Add to post_build_checks/tasks/main.yml
- name: "[T-54] Backend is azure_blob_with_local_fallback during migration window"
  ansible.builtin.command: >
    docker exec -i "&#123;&#123; apache_container_name &#125;&#125;"
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
      Expected azure_blob_with_local_fallback, got &#123;&#123; fallback_backend_env.stdout | trim &#125;&#125;
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
> *Lifecycle: **permanent** — covered by T-11; no separate task needed.*

**T-61 [post_build_checks]** — Media endpoint returns 401 (not 403/500) after bind mounts removed. *(Covered by T-6.)*
> *Lifecycle: **permanent** — covered by T-6; no separate task needed.*

**T-62 [post_build_checks]** — `GIGHIVE_MEDIA_STORAGE_BACKEND` reverted to `azure_blob`. *(Covered by T-8.)*
> *Lifecycle: **permanent** — covered by T-8; no separate task needed.*

**T-63 [Manual]** — `FallbackMediaBackend` class flagged or deleted from the codebase.
> Code review / grep check: `grep -r "FallbackMediaBackend" src/` should return only a deletion commit or a `// TODO: remove` comment after Step 10.

---

### Tranche 2 Cleanup — Remove Phase-Gate and Migration-Window Checks from `post_build_checks`

**When:** After Phase 11 step 9 is fully complete — meaning all of: T-56 (`fail=0` on backfill), T-57 (blob count matches DB), T-63 (`FallbackMediaBackend` deleted), and `gighive_media_storage_backend` reverted to `azure_blob` in group vars.

**File to edit:** `ansible/roles/post_build_checks/tasks/main.yml`

Remove the following task pairs (command task + Assert task):

| T-N | Task `name:` string to search for and remove | Reason |
|---|---|---|
| T-40 | `[T-40] import_media_zip_worker_azure.php has no direct uploadBlobFromFile() call` | One-time code structure check; storage abstraction stable |
| T-41 | `[T-41] export_media_worker_azure.php has no direct downloadBlobToFile() call` | One-time code structure check; storage abstraction stable |
| T-42 | `[T-42] No glob() calls scanning media directories in catalog code` | One-time code structure check; catalog scan stable |
| T-54 | `[T-54] Backend is azure_blob_with_local_fallback during migration window` | Migration window closed; `when:` guard already skips it but the task is dead weight |

**Also clean up the PHP source tree at this step:**

- Delete `src/Services/FallbackMediaBackend.php` (temporary migration class; see T-63)
- Delete `src/Jobs/backfill_media_to_blob.php` (one-shot script; not for repeated production use)
- Remove the `azure_blob_with_local_fallback` branch from `MediaStorageService::make()` (now dead code; do not leave it as a silent back-door)

**After removal:** Run `ansible-playbook --tags smoke,media_storage` against the Azure inventory. Confirm all remaining tasks pass.

**Note on Phase 11 reference items:** T-47, T-51, T-60, T-61, T-62 are reminders to re-run earlier permanent checks (T-83, T-8, T-11, T-6, T-8) at specific step boundaries. They have no standalone YAML tasks — nothing to remove here.

---

## Ansible Role Interactions

Every role in `ansible/roles/` was reviewed against this refactor. The findings are grouped by severity.

**No new Ansible roles are required by this refactor.** All changes are in-place modifications of existing roles. The two new Phase 3 cron jobs (`run_probe_job.php`, `cleanup_expired_uploads.php`) belong in the `docker` role as a new `/etc/cron.d/` template — the `docker` role already owns the full application deployment. The `blobfuse2` role is retired (not replaced).

---

### Roles that break — must be fixed as part of this refactor

#### `post_build_checks` — tusd checks become dead code

`roles/post_build_checks/tasks/main.yml` contains:

1. **"Verify tusd container is running"** — checks `docker container info tusd_container_name`; will fail or pass vacuously once tusd is removed.
2. **"Probe tusd directly via Docker DNS from inside apache container"** — curls `http://tusd:8080/files/`; the container does not exist after Phase 3.
3. **"Build internal tusd probe URL"** and associated assertions — dead after Phase 3.

**Required change (Phase 3):** Remove the three tusd-specific checks. Replace with:
- Assert PHP tus server responds: `GET /files/` → 400 (not a 404 or 502)
- Assert `POST /files/` without auth → 401
- These are already specified in Phase 3 smoke test requirements; they must also replace the tusd checks here.

---

#### `validate_app` — tusd version check + Azure connectivity probe both broken

**Issue 1 — tusd version in stack summary:**

`validate_app` includes:
```yaml
- name: Get tusd version from tusd container
  command: docker exec &#123;&#123; tusd_container_name &#125;&#125; tusd --version
  register: stack_tusd_raw
```
and builds `stack_versions_summary.tusd` from this. After Phase 3, the container does not exist; `docker exec` fails silently (`failed_when: false`), and `stack_tusd_raw.stdout` is empty. The summary shows `tusd: N/A` forever with no indication that the field is now meaningless.

**Required change (Phase 3):** Remove `stack_tusd_raw` task and the `tusd:` line from `stack_versions_summary`. Add a `PHP_TUS_Server: "php_tus_block_upload_service"` or similar static label confirming the PHP server is the active upload backend.

**Issue 2 — Azure Blob connectivity probe uses SAS token over public endpoint:**

```yaml
azure_probe_url: "https://&#123;&#123; azure_blob_account_name &#125;&#125;.blob.core.windows.net/&#123;&#123; azure_blob_container &#125;&#125;/test-connectivity/ansible-probe-...?&#123;&#123; azure_blob_sas_token &#125;&#125;"
```

This probe PUTs a sentinel blob directly to the storage account's public hostname using a SAS token. After Phase 6 (Terraform disables public network access), the storage account is reachable only through the private endpoint inside the VNet. The probe will time out and fail on every deploy after Phase 6.

**Required change (Phase 6 / Phase 2):** Replace the probe with one that runs from inside the Apache container (which has access to the private endpoint via Docker `host-gateway`):
```yaml
- name: Azure Blob connectivity probe (from inside container via private endpoint)
  community.docker.docker_container_exec:
    container: "&#123;&#123; apache_container_name &#125;&#125;"
    command: >
      php -r "
        \$ch = curl_init('https://&#123;&#123; azure_blob_account_name &#125;&#125;.blob.core.windows.net/&#123;&#123; azure_blob_container &#125;&#125;?restype=container');
        curl_setopt_array(\$ch, [CURLOPT_RETURNTRANSFER => true,
                                  CURLOPT_HTTPHEADER => ['x-ms-version: 2020-04-08']]);
        \$code = curl_getinfo(\$ch, CURLINFO_HTTP_CODE);
        curl_exec(\$ch);
        exit(\$code === 403 ? 0 : 1);  // 403 = auth required but endpoint reachable
      "
  register: azure_private_probe
  failed_when: azure_private_probe.rc != 0
  tags: [smoke, azure]
```
A `403` response (auth required) confirms the private endpoint is routable and the storage account is responding. An IMDS token check (Managed Identity) can follow as a second probe.

---

#### `upload_tests` — test_7.yml has three tusd-specific assumptions

`roles/upload_tests/tasks/test_7.yml` performs the full TUS upload flow. Three parts assume the tusd model:

**Issue 1 — "Wait for post-finish hook to write payload" (3-second pause):**
```yaml
- name: Upload tests 7 - wait for post-finish hook to write payload
  ansible.builtin.pause:
    seconds: 3
```
This waits for the tusd `post-finish` hook to write a notification file that PHP polls. In the new model, the DB row is committed synchronously during the final PATCH. The pause is unnecessary and should be removed. The finalize endpoint will respond immediately without any wait.

**Issue 2 — Cleanup removes non-existent tusd volume paths:**
```yaml
- name: Remove tus staging artifacts for this upload_id
  command: >
    docker exec &#123;&#123; apache_container_name &#125;&#125;
    sh -lc 'rm -f
    "/var/www/private/tus-data/&#123;&#123; tus_upload_id &#125;&#125;"
    "/var/www/private/tus-hooks/uploads/&#123;&#123; tus_upload_id &#125;&#125;.json"
    "/var/www/private/tus-hooks/finalized/&#123;&#123; tus_upload_id &#125;&#125;.json"'
```
These paths are from the tusd volume (`tusd_data`, `tus_hooks`). After Phase 3, these volumes are gone. The cleanup should be updated to delete the `tus_uploads` DB row for the test upload ID instead:
```yaml
- name: Remove tus_uploads DB row for smoke-test upload
  community.docker.docker_container_exec:
    container: "&#123;&#123; mysql_container_name &#125;&#125;"
    command: >
      sh -lc 'mysql -h 127.0.0.1 -u root -p"$MYSQL_ROOT_PASSWORD" -D "$MYSQL_DATABASE"
              -e "DELETE FROM tus_uploads WHERE upload_id = ''&#123;&#123; tus_upload_id &#125;&#125;'' LIMIT 1;"'
```

**Issue 3 — test_7 assertions need async probe job tolerance:**

`upload_tests_7_finalize_resp.checksum_sha256` is asserted present. This is fine — the new model writes checksum synchronously. However, `duration_seconds` and `thumbnail` are `null` until the async probe job completes. If the finalize assertions ever check for those fields, they will fail. Currently they do not, but this should be explicitly documented in the test so future assertions against `duration_seconds` know to add a retry/wait loop.

**Required changes (Phase 3):** Remove the 3-second pause; update cleanup to delete DB row; add comment about async probe job fields.

---

#### `ai_worker` — bind-mounts the local media directories; silent breakage in Azure mode

`roles/ai_worker/templates/docker-compose-ai-worker.yml.j2`:
```yaml
volumes:
  - &#123;&#123; video_dir &#125;&#125;:/data/video:ro
  - &#123;&#123; audio_dir &#125;&#125;:/data/audio:ro
```

`&#123;&#123; video_dir &#125;&#125;` is `&#123;&#123; gighive_home &#125;&#125;/video` (the VM host path). After Phase 11 step 10 removes the Azure media bind mounts, `&#123;&#123; video_dir &#125;&#125;` is empty. The ai-worker container starts, but finds no media files at `/data/video` or `/data/audio`. It processes nothing and raises no error — a silent operational failure.

The ai-worker uses these paths to read video frames and audio for AI analysis. In Azure mode it needs to download blobs to temp paths before processing them.

**Required change (Phase 10 / before Phase 11 step 10):**

In Azure mode, the ai-worker cannot use bind mounts. Two approaches:

- **Option A (preferred):** Add a Blob download step to the ai-worker Python code: download the target blob to `/data/ai_assets/tmp/{asset_id}.{ext}`, process it, then delete. Mirror the pattern used by `MediaProbeJobService`. The compose volumes for `video` and `audio` become conditional on `gighive_media_storage_backend`:
  ```yaml
  # in docker-compose-ai-worker.yml.j2:
  &#123;% if gighive_media_storage_backend != 'azure_blob' %&#125;
        - &#123;&#123; video_dir &#125;&#125;:/data/video:ro
        - &#123;&#123; audio_dir &#125;&#125;:/data/audio:ro
  &#123;% endif %&#125;
  ```
  Pass `GIGHIVE_MEDIA_STORAGE_BACKEND` and the Azure env vars into the ai-worker container so it can access Blob.

- **Option B (stopgap):** Disable ai-worker in Azure mode with `ai_worker_enabled: false` in Azure group_vars until Option A is implemented.

This is the **highest-risk silent failure** in the entire refactor. The ai-worker will appear to function (container running, no errors) while silently processing no media.

---

### Roles that need conditional guards — media directory creation/sync

#### `base` — unconditionally creates and syncs local media directories

`roles/base/tasks/main.yml` does the following unconditionally:

1. Creates `/home/&#123;&#123; ansible_user &#125;&#125;/audio` and `video` with www-data ownership
2. `rsync`s full or reduced audio/video sets from the controller to the VM (`sync_audio`, `sync_video` tasks)
3. Creates `&#123;&#123; video_dir &#125;&#125;/podcasts`

In Azure mode, these directories are not needed as media storage (they exist only as bind mount sources, which are removed after Phase 11 step 10). The rsync tasks are wasted work and potentially misleading — they populate directories that are not the canonical media store.

**Required change (Phase 1 / before Azure Blob cutover):** gate the sync tasks and directory creation that are only meaningful for local media storage on:

```yaml
when: gighive_media_storage_backend != 'azure_blob'
```

If thumbnails are still written locally in any intermediate step, keep only the required local temp/thumb paths — not the full media sync.

---

### Roles that need new tasks — schema migration tracking

#### `db_migrations` — new tables are not tracked

`roles/db_migrations/tasks/main.yml` uses a pattern of checking whether columns exist and adding them if missing. The two new tables this refactor adds — `tus_uploads` and `probe_jobs` — are created by `create_media_db.sql`, but there are no migration tasks in `db_migrations` to verify or idempotently create them.

If `create_media_db.sql` is not run on an existing environment (e.g., an upgrade path where the container was recreated), the tables will be absent and `TusBlockUploadService` will fail with a MySQL error.

**Required change (Phase 3):** Add two migration tasks to `db_migrations/tasks/main.yml` following the existing pattern:

```yaml
- name: Check if tus_uploads table exists
  community.docker.docker_container_exec:
    container: "&#123;&#123; mysql_container_name | default('mysqlServer') &#125;&#125;"
    command: >-
      sh -lc 'mysql -h 127.0.0.1 -u root -p"$MYSQL_ROOT_PASSWORD" -D "$MYSQL_DATABASE" -Nse
      "SELECT COUNT(*) FROM information_schema.TABLES
       WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ''tus_uploads'';"'
  register: _tus_uploads_table_exists
  changed_when: false

- name: Create tus_uploads table if missing
  community.docker.docker_container_exec:
    container: "&#123;&#123; mysql_container_name | default('mysqlServer') &#125;&#125;"
    command: >-
      sh -lc 'mysql -h 127.0.0.1 -u root -p"$MYSQL_ROOT_PASSWORD" -D "$MYSQL_DATABASE"
              < /docker-entrypoint-initdb.d/create_media_db.sql'
  when: (_tus_uploads_table_exists.stdout | trim) == '0'
  changed_when: true
```

Repeat for `probe_jobs`. This ensures the tables are present on any environment regardless of whether `create_media_db.sql` was manually applied.

---

### Roles with minor issues

#### `mysql_backup` — backup includes in-progress upload state

`roles/mysql_backup` dumps the entire database on a schedule. After Phase 3, this includes `tus_uploads` rows with `status=pending` and `sha256_ctx` BLOB columns containing serialized PHP `HashContext` objects.

Restoring a backup that captures a pending upload leaves an orphaned incomplete Azure blob (uncommitted blocks) with a DB row claiming the upload is in progress. The blocks expire in 7 days; the DB row will remain until `cleanup_expired_uploads.php` fires.

**No code change required.** Add a note to the backup rotation configuration and runbook: restoring `tus_uploads` rows with `status=pending` is safe — they will expire naturally. Do not manually attempt to resume or complete pending uploads from a restored backup.

#### `one_shot_bundle` — bundles local media files; Azure mode has none

`roles/one_shot_bundle` builds a deployment bundle that includes `_host_audio/` and `_host_video/` directories populated from the local VM media paths (via `&#123;&#123; _one_shot_bundle_assets_prefix &#125;&#125;/audio/` and `/video/`). In Azure mode, those VM directories are empty after Phase 11 step 10, so the bundle contains no media files.

`one_shot_bundle` is a local/VirtualBox deployment tool and is not used for Azure production. **No code change required.** Add a guard comment or `when: gighive_media_storage_backend != 'azure_blob'` to the `output_bundle.yml` tasks that populate `_host_audio` and `_host_video` so it is clear the bundle is not expected to contain media in Azure mode.

#### `security_owasp_crs` — no conflict, but ordering matters

`roles/security_owasp_crs` toggles `IncludeOptional` directives in `modsecurity.conf`. The refactor adds a `<LocationMatch "^/files/">` `SecRequestBodyLimit` rule in `default-ssl.conf.j2`. These are in different files and do not conflict. However, if `security_owasp_crs` is ever extended to manage `default-ssl.conf.j2` directly, the ordering constraint is: the `/files/` location exception must always be present whenever ModSecurity is enabled. **No immediate change required** — document the constraint.

#### `blobfuse2` — retire as planned

The role installs blobfuse2, renders a config, mounts the container, and adds an fstab entry. The refactor's Non-Goals section already calls out "no blobfuse2 at any point." The Follow-on task says to retire or mark deprecated. **No new finding** — the existing Follow-on task covers this.

---

### Summary table

| Role | Severity | Phase | Action required |
|---|---|---|---|
| `post_build_checks` | **Critical** | 4 | Remove tusd container checks; add PHP tus server smoke tests |
| `validate_app` | **Critical** | 1 + 4 | Fix Azure connectivity probe (SAS → private endpoint); remove tusd version task |
| `upload_tests` (test_7) | **Critical** | 4 | Remove 3-second hook pause; replace tusd volume cleanup with DB row delete |
| `ai_worker` | **Critical** | 9 (before 10A step 10) | Make video/audio bind mounts conditional; add Blob download in Azure mode |
| `base` | **Significant** | 2 | Gate `sync_video` / `sync_audio` tasks on `gighive_media_storage_backend != 'azure_blob'` |
| `db_migrations` | **Significant** | 4 | Add idempotent `tus_uploads` and `probe_jobs` table checks |
| `mysql_backup` | Minor | — | Runbook note only; no code change |
| `one_shot_bundle` | Minor | — | Add `when` guard or comment; no functional impact |
| `security_owasp_crs` | Minor | — | Document ordering constraint; no code change |
| `blobfuse2` | Minor | Follow-on | Retire per existing task |

---

## Progress

### Completed

_(nothing yet — plan stage)_

### Remaining — This Refactor

#### Azure (Phase 11 — primary)

- [ ] Phase 6: Terraform private endpoint + disable public network access; **update `validate_app` Azure connectivity probe to use private endpoint from inside container (SAS probe breaks after public access disabled)**
- [ ] Phase 1: Runtime config, group_vars, compose IMDS fix, storage backend switch; `extra_hosts` unconditional; **gate `base` role `sync_video`/`sync_audio` tasks on `gighive_media_storage_backend != 'azure_blob'`**
- [ ] Phase 2: `MediaStorageService` with `LocalMediaBackend` and `AzureBlobMediaBackend`
- [ ] Phase 3: `api/tus-upload.php` + `TusBlockUploadService` (`AzureBlobTusBackend` + `LocalFileTusBackend`); `run_probe_job.php` (async ffprobe + thumbnail); `cleanup_expired_uploads.php` (cron DB row + staging file cleanup); `gighive-probe.cron.j2`; pre-deployment Ansible assertions: PHP ≥ 8.2, `innodb_lock_wait_timeout` ≥ 60, `apcu.enable_cli=1`; retire tusd from **all** compose files; unconditional Apache routing + ModSecurity exception; **`post_build_checks`: remove tusd container checks, add PHP tus server smoke tests**; **`validate_app`: remove tusd version task from stack_versions_summary**; **`upload_tests` test_7: remove 3s hook pause, replace tusd volume cleanup with DB row delete**; **`db_migrations`: add idempotent `tus_uploads` + `probe_jobs` table checks**
- [ ] Phase 4: `api/media-stream.php` streaming endpoint with range support; smoke test; iOS thumbnail auth acceptance criterion verified
- [ ] Phase 7: Thumbnail async generation and Blob storage (part of Phase 3 probe job)
- [ ] Phase 8: Managed Identity token acquisition + caching verified from inside container
- [ ] Phase 9: `2bootstrap.sh` Terraform output extraction + Ansible variable wiring
- [ ] Phase 10: Admin tooling updates (Blob-backed stats, delete, `mysqlPrep_normalized.py` Blob-download mode, catalog scan via `MediaStorageService::list()`); **`ai_worker`: make video/audio bind mounts conditional; add Blob download path in Azure mode — must be done before Phase 11 step 10 or ai-worker silently processes no media**
- [ ] Phase 11: Deploy `FallbackMediaBackend` (`azure_blob_with_local_fallback`); run `backfill_media_to_blob.php --dry-run` then live; verify all checksums; verify counts, thumbnails, range seeks; confirm iOS thumbnails load; switch to `azure_blob`; remove Azure media bind mounts; delete `FallbackMediaBackend.php`

#### Local / VirtualBox / Baremetal (Phase 5 — Tranche 1 final step)

- [ ] Phase 5: Deploy `LocalMediaBackend` read path to local inventories; verify PHP-mediated stream + range; remove local media bind mounts; retire `MEDIA_SEARCH_DIRS`

### Remaining — Follow-on Tasks

- ~~Retire `ansible/roles/blobfuse2/` role~~ — **moved to Phase 5** (step 7); remove role directory, confirm absent from `site.yml`.
- ~~Update `docs/media_file_location_variables.md`~~ — **moved to Phase 5** (step 8); update variable glossary for new storage model.
- ~~Add logrotate config for `/var/log/probe_job.log`~~ — **moved to Phase 3 deliverable** (`ansible/roles/docker/files/logrotate.d/gighive-probe`).
- ~~`probe_jobs` failed row accumulation~~ — **moved to Phase 3** (`cleanup_expired_uploads.php` prunes rows older than 30 days; T-86b `validate_app` check warns on non-zero count).
- ~~Orphan row reconciliation query~~ — **moved to Phase 5** (T-68b `validate_app` warns on orphaned `tus_uploads` rows; recovery instructions in task `msg`).
- ~~`audio/mp3` MIME type in allowlist~~ — **resolved**: `gighive2.yml` group_vars already include both `audio/mp3` and `audio/mpeg`; no change needed.
- **Session-cookie auth for `media-stream.php`** — guest gallery thumbnails are handled by the gallery nonce path (Phase 4). The remaining gap is the browser admin panel: if admin thumbnail display via `<img>` tags is ever added, session-cookie auth must be added to `media-stream.php` as a fourth credential path. Not blocking for Tranche 1.
- **Evaluate retiring `AZURE_BLOB_SAS_TOKEN`** — four admin PHP files (`export_media_worker_azure.php`, `import_media_zip_worker_azure.php`, `import_media_zip.php`, `export_media.php`) still read this token directly. Retirement requires migrating all four to use `MediaStorageService` instead of inline SAS calls — that is Phase 10 (Tranche 2) work. Until then, keep the token in `.env.j2`.
- **Evaluate Apache `X-Sendfile`** for local-mode read path if PHP file serving becomes a CPU or throughput bottleneck under real load. Not a known issue at current scale; revisit if profiling shows it.
- **Delete `src/Services/FallbackMediaBackend.php`** after Phase 11 step 9 backfill is verified complete — it is a migration-window-only class and must not persist in the codebase beyond Tranche 2.
