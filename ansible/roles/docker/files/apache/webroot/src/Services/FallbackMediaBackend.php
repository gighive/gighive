<?php declare(strict_types=1);
namespace Production\Api\Services;

use Production\Api\Contracts\MediaStorageBackendInterface;
use Production\Api\Dto\MediaMetaDto;

/**
 * Phase 11 ONLY — temporary split-read backend.
 *
 * Activated via GIGHIVE_MEDIA_STORAGE_BACKEND=azure_blob_with_local_fallback.
 * Tries the Azure Blob backend first for every read operation; falls back to
 * the local filesystem backend for assets not yet backfilled to Blob Storage.
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
    public function put(string $key, string $localPath, string $mimeType): void
    {
        $this->primary->put($key, $localPath, $mimeType);
    }

    /**
     * Try primary; on null getMeta (404) fall back to local stream.
     * Any non-404 RuntimeException from primary propagates — do not mask Azure errors.
     */
    public function stream(string $key): void
    {
        $meta = $this->primary->getMeta($key);
        if ($meta !== null) {
            $this->primary->stream($key);
        } else {
            $this->fallback->stream($key);
        }
    }

    /**
     * Try primary; on null getMeta (404) fall back to local range stream.
     */
    public function streamRange(string $key, int $start, int $end): void
    {
        $meta = $this->primary->getMeta($key);
        if ($meta !== null) {
            $this->primary->streamRange($key, $start, $end);
        } else {
            $this->fallback->streamRange($key, $start, $end);
        }
    }

    /**
     * Try primary; if primary returns null (404), try fallback.
     * Non-404 errors from primary propagate.
     */
    public function getMeta(string $key): ?MediaMetaDto
    {
        $meta = $this->primary->getMeta($key);
        if ($meta !== null) {
            return $meta;
        }

        return $this->fallback->getMeta($key);
    }

    /**
     * Delete from primary only.
     */
    public function delete(string $key): void
    {
        $this->primary->delete($key);
    }

    /**
     * True if primary exists OR fallback exists.
     * Used by backfill_media_to_blob.php to skip already-present blobs.
     */
    public function exists(string $key): bool
    {
        return $this->primary->exists($key) || $this->fallback->exists($key);
    }

    /**
     * Returns primary list only. Fallback list is not merged — after backfill,
     * primary is the authoritative source of truth.
     *
     * @return string[]
     */
    public function list(string $prefix): array
    {
        return $this->primary->list($prefix);
    }
}
