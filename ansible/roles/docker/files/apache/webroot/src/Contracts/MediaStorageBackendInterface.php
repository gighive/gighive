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
