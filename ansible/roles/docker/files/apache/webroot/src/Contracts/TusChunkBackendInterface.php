<?php

declare(strict_types=1);

namespace Production\Api\Contracts;

/**
 * Backend contract for tus chunk storage.
 *
 * Implementations write each PATCH chunk to their respective storage medium
 * (Azure Block Blob or local staging file) and finalize on the last PATCH.
 *
 * The calling service (TusBlockUploadService) is responsible for:
 *  - maintaining upload state in the tus_uploads DB table
 *  - serializing/restoring the HashContext across requests
 *  - enforcing auth and tus protocol headers
 *
 * Each method receives only what it needs — no DB PDO is injected here.
 */
interface TusChunkBackendInterface
{
    /**
     * Write one chunk (block) of an upload.
     *
     * @param string   $uploadId   Server-generated UUID for this upload.
     * @param int      $blockIndex Zero-based block index (matches DB block_count before this call).
     * @param string   $data       Raw chunk bytes.
     * @param string   $mimeType   MIME type declared at POST time.
     * @param string   $fileType   'audio' or 'video'.
     */
    public function writeChunk(
        string $uploadId,
        int    $blockIndex,
        string $data,
        string $mimeType,
        string $fileType,
    ): void;

    /**
     * Finalize the upload: commit all blocks and move the file to its final location.
     *
     * Called once after the last chunk has been written and the SHA-256 checksum finalized.
     * Returns the qualified storage key (e.g. "audio/abc123.mp3") so the caller can
     * record it in the assets table.
     *
     * @param string $uploadId     Server-generated UUID for this upload.
     * @param string $checksum     Hex SHA-256 of the complete file.
     * @param string $fileExt      Extension without leading dot (e.g. "mp4").
     * @param string $mimeType     MIME type declared at POST time.
     * @param string $fileType     'audio' or 'video'.
     * @param int    $uploadLength Total expected byte count.
     * @return string              Qualified storage key written to assets.source_relpath.
     */
    public function finalizeUpload(
        string $uploadId,
        string $checksum,
        string $fileExt,
        string $mimeType,
        string $fileType,
        int    $uploadLength,
    ): string;

    /**
     * Abort an upload and remove all stored blocks/staging data.
     *
     * Best-effort; implementations must not throw on missing data.
     *
     * @param string $uploadId Server-generated UUID.
     * @param string $fileType 'audio' or 'video'.
     */
    public function abortUpload(string $uploadId, string $fileType): void;
}
