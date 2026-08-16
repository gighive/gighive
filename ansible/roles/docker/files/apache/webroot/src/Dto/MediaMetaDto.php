<?php declare(strict_types=1);
namespace Production\Api\Dto;

/**
 * Metadata returned by MediaStorageBackendInterface::getMeta().
 * Immutable — constructed once from the blob HEAD response or filesystem stat.
 */
final readonly class MediaMetaDto
{
    public function __construct(
        public int    $size,         // total blob size in bytes
        public string $etag,         // ETag value without surrounding quotes
        public string $contentType,  // e.g. 'video/mp4'
    ) {}
}
