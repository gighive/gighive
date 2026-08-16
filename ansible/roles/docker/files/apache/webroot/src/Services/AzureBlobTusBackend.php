<?php

declare(strict_types=1);

namespace Production\Api\Services;

use Production\Api\Contracts\TusChunkBackendInterface;

/**
 * tus chunk backend that streams each PATCH body directly to Azure Block Blob Storage.
 *
 * No intermediate disk write occurs. Each chunk becomes one PUT Block call.
 * On finalize, PUT Block List atomically commits all blocks.
 *
 * Block ID format: base64_encode(str_pad((string)$blockIndex, 6, '0', STR_PAD_LEFT))
 * This produces fixed-length IDs ('000000', '000001', ...) required by Azure.
 *
 * Blob staging key (uncommitted): {prefix}{uploadId}  (e.g. "audio/upload-uuid-here")
 * Blob final key:                 {prefix}{sha256}.{ext}
 *
 * The prefix ('audio/' or 'video/') is determined from $fileType at writeChunk/finalize time,
 * matching the AZURE_BLOB_PREFIX_* env vars set in TusUploadConfig.
 */
final class AzureBlobTusBackend implements TusChunkBackendInterface
{
    public function __construct(
        private readonly AzureBlobRestClient $client,
    ) {}

    /**
     * Write one chunk as a PUT Block to Azure Blob Storage.
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
        $blockId    = $this->blockId($blockIndex);
        $stagingKey = $this->stagingKey($uploadId, $fileType);

        $result = $this->client->putBlock($stagingKey, $blockId, $data, $mimeType);

        if (!$result->isSuccess()) {
            throw new \RuntimeException(
                sprintf(
                    '[AzureBlobTusBackend] PUT Block failed for upload=%s block=%d: HTTP %d — %s',
                    $uploadId,
                    $blockIndex,
                    $result->status,
                    substr($result->body, 0, 256),
                )
            );
        }
    }

    /**
     * Commit all blocks via PUT Block List, then copy the blob to its final key.
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
        $stagingKey = $this->stagingKey($uploadId, $fileType);
        $finalKey   = $this->finalKey($checksum, $fileExt, $fileType);

        // Retrieve committed block list from Azure to build the PUT Block List body
        $listResult = $this->client->getBlockList($stagingKey);
        if (!$listResult->isSuccess()) {
            throw new \RuntimeException(
                sprintf(
                    '[AzureBlobTusBackend] GET Block List failed for upload=%s: HTTP %d — %s',
                    $uploadId,
                    $listResult->status,
                    substr($listResult->body, 0, 256),
                )
            );
        }

        // Build block list XML from uncommitted block IDs returned by Azure
        $blockIds = $this->parseUncommittedBlockIds($listResult->body);
        if (empty($blockIds)) {
            throw new \RuntimeException(
                '[AzureBlobTusBackend] No uncommitted blocks found for upload=' . $uploadId
            );
        }
        $blockListXml = $this->buildBlockListXml($blockIds);

        // Commit
        $commitResult = $this->client->putBlockList($stagingKey, $blockListXml, $mimeType);
        if (!$commitResult->isSuccess()) {
            throw new \RuntimeException(
                sprintf(
                    '[AzureBlobTusBackend] PUT Block List failed for upload=%s: HTTP %d — %s',
                    $uploadId,
                    $commitResult->status,
                    substr($commitResult->body, 0, 256),
                )
            );
        }

        // Copy committed blob to final key (idempotent — uses SHA256 name)
        $copyResult = $this->client->copyBlob($stagingKey, $finalKey);
        if (!$copyResult->isSuccess()) {
            throw new \RuntimeException(
                sprintf(
                    '[AzureBlobTusBackend] Blob copy from staging to final key failed: HTTP %d — %s',
                    $copyResult->status,
                    substr($copyResult->body, 0, 256),
                )
            );
        }

        // Delete staging blob (best-effort; Azure GC handles it if this fails)
        $this->client->deleteBlob($stagingKey);

        return $finalKey;
    }

    /**
     * Delete the staging blob to abort an upload.
     * Best-effort — does not throw on 404.
     *
     * {@inheritDoc}
     */
    public function abortUpload(string $uploadId, string $fileType): void
    {
        $stagingKey = $this->stagingKey($uploadId, $fileType);
        $result     = $this->client->deleteBlob($stagingKey);
        if (!$result->isSuccess() && $result->status !== 404) {
            error_log(
                '[AzureBlobTusBackend] abortUpload: DELETE returned HTTP ' . $result->status
                . ' for upload=' . $uploadId
            );
        }
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /** Azure Block Blob block ID: fixed-length base64 of zero-padded index. */
    private function blockId(int $blockIndex): string
    {
        return base64_encode(str_pad((string)$blockIndex, 6, '0', STR_PAD_LEFT));
    }

    /** Staging blob key (uncommitted, uses uploadId as name). */
    private function stagingKey(string $uploadId, string $fileType): string
    {
        return ($fileType === 'video' ? 'video/' : 'audio/') . 'staging/' . $uploadId;
    }

    /** Final blob key (uses SHA-256 checksum as name). */
    private function finalKey(string $checksum, string $fileExt, string $fileType): string
    {
        return ($fileType === 'video' ? 'video/' : 'audio/') . $checksum . '.' . $fileExt;
    }

    /**
     * Parse uncommitted block IDs from Azure GET Block List XML response.
     *
     * @return string[]
     */
    private function parseUncommittedBlockIds(string $xml): array
    {
        $ids = [];
        if (preg_match_all('/<UncommittedBlock>\s*<Name>([^<]+)<\/Name>/i', $xml, $matches)) {
            $ids = $matches[1];
        }
        return $ids;
    }

    /**
     * Build PUT Block List XML body from an ordered list of block IDs.
     *
     * @param string[] $blockIds
     */
    private function buildBlockListXml(array $blockIds): string
    {
        $xml = '<?xml version="1.0" encoding="utf-8"?><BlockList>';
        foreach ($blockIds as $id) {
            $xml .= '<Latest>' . htmlspecialchars($id, ENT_XML1, 'UTF-8') . '</Latest>';
        }
        $xml .= '</BlockList>';
        return $xml;
    }
}
