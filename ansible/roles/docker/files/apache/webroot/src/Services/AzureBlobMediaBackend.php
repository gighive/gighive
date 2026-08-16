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
 * Key format: fully qualified blob key including prefix, e.g. 'audio/a3f2.mp3'.
 * The prefix (audio/, video/, video/thumbnails/) is applied by MediaStorageService
 * before passing the key here. This class never prepends anything.
 */
final class AzureBlobMediaBackend implements MediaStorageBackendInterface
{
    public function __construct(
        private readonly AzureBlobRestClient $rest,
    ) {}

    /**
     * Upload a local file to Blob Storage as a block blob.
     * Uses streaming PUT to avoid loading the entire file into memory.
     */
    public function put(string $key, string $localPath, string $mimeType): void
    {
        $size = filesize($localPath);
        if ($size === false) {
            throw new RuntimeException("Cannot stat file for upload: {$localPath}");
        }

        $fh = fopen($localPath, 'rb');
        if ($fh === false) {
            throw new RuntimeException("Cannot open file for upload: {$localPath}");
        }

        try {
            $url    = $this->rest->blobUrl($key);
            $result = $this->rest->curl('PUT', $url, [
                'x-ms-blob-type: BlockBlob',
                'Content-Type: ' . $mimeType,
                'Content-Length: ' . $size,
            ], $fh);
        } finally {
            fclose($fh);
        }

        if (!$result->isSuccess()) {
            throw new RuntimeException(
                "Azure PUT blob failed for key '{$key}': HTTP {$result->status}. Body: "
                . substr($result->body, 0, 256)
            );
        }
    }

    /**
     * Stream the full blob body to PHP's output buffer.
     * Uses CURLOPT_WRITEFUNCTION to avoid buffering the entire blob in memory.
     */
    public function stream(string $key): void
    {
        $this->streamRange($key, 0, -1);
    }

    /**
     * Stream a byte range of the blob to PHP's output buffer.
     * $start and $end are both inclusive. Pass $end = -1 for full-file streaming.
     */
    public function streamRange(string $key, int $start, int $end): void
    {
        $url     = $this->rest->blobUrl($key);
        $headers = $this->rest->authHeaders();

        if ($end >= 0) {
            $headers[] = "Range: bytes={$start}-{$end}";
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_WRITEFUNCTION  => static function ($ch, string $chunk): int {
                echo $chunk;
                return strlen($chunk);
            },
            CURLOPT_TIMEOUT        => 300,  // large blobs on slow links
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_FAILONERROR    => false,
        ]);

        $ok     = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err    = curl_error($ch);
        curl_close($ch);

        if (!$ok || $err !== '') {
            throw new RuntimeException("Azure stream failed for key '{$key}': {$err}");
        }

        if ($status !== 200 && $status !== 206) {
            throw new RuntimeException("Azure stream returned HTTP {$status} for key '{$key}'");
        }
    }

    /**
     * Return metadata for the blob via HEAD request, or null for 404.
     */
    public function getMeta(string $key): ?MediaMetaDto
    {
        $url    = $this->rest->blobUrl($key);
        $result = $this->rest->curl('HEAD', $url);

        if ($result->status === 404) {
            return null;
        }

        if (!$result->isSuccess()) {
            throw new RuntimeException(
                "Azure HEAD blob failed for key '{$key}': HTTP {$result->status}"
            );
        }

        $size        = (int)($result->headers['content-length'] ?? 0);
        $etag        = trim($result->headers['etag'] ?? '', '"');
        $contentType = $result->headers['content-type'] ?? 'application/octet-stream';

        return new MediaMetaDto(
            size:        $size,
            etag:        $etag,
            contentType: $contentType,
        );
    }

    /**
     * Delete a blob. No-op on 404.
     */
    public function delete(string $key): void
    {
        $url    = $this->rest->blobUrl($key);
        $result = $this->rest->curl('DELETE', $url);

        if ($result->status === 404) {
            return; // already gone — idempotent
        }

        if (!$result->isSuccess()) {
            throw new RuntimeException(
                "Azure DELETE blob failed for key '{$key}': HTTP {$result->status}"
            );
        }
    }

    /**
     * Return true if the blob exists.
     */
    public function exists(string $key): bool
    {
        return $this->getMeta($key) !== null;
    }

    /**
     * List blob keys with the given prefix using the Blob List REST API.
     * Returns fully-qualified keys (e.g. 'audio/a3f2.mp3').
     *
     * @return string[]
     */
    public function list(string $prefix): array
    {
        $url    = $this->rest->blobUrl('', '?restype=container&comp=list&prefix=' . urlencode($prefix));
        $result = $this->rest->curl('GET', $url);

        if (!$result->isSuccess()) {
            throw new RuntimeException(
                "Azure list blobs failed for prefix '{$prefix}': HTTP {$result->status}"
            );
        }

        // Parse <Name> elements from the XML response
        $keys = [];
        if (preg_match_all('/<Name>([^<]+)<\/Name>/i', $result->body, $matches)) {
            $keys = $matches[1];
        }

        return $keys;
    }
}
