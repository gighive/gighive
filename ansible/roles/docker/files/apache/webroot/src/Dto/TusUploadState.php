<?php

declare(strict_types=1);

namespace Production\Api\Dto;

/**
 * Immutable value object representing a row from the tus_uploads table.
 *
 * Constructed from a PDO fetch row by TusBlockUploadService.
 * All fields map 1:1 to tus_uploads columns; nullable fields reflect DB NULLs.
 */
final readonly class TusUploadState
{
    public function __construct(
        public readonly int     $id,
        public readonly string  $uploadId,
        public readonly int     $userId,
        public readonly string  $status,           // 'pending' | 'complete' | 'failed'
        public readonly int     $uploadLength,
        public readonly int     $blockCount,
        public readonly int     $blockSize,
        public readonly ?string $sha256Ctx,        // serialized HashContext or null before first PATCH
        public readonly string  $fileType,         // 'audio' | 'video'
        public readonly string  $mimeType,
        public readonly ?string $uploadOrgName,    // org_name from Upload-Metadata; null for non-QR uploads
        public readonly ?string $uploadEventDate,  // event_date from Upload-Metadata; null for non-QR uploads
        public readonly ?string $uploadEventType,  // event_type from Upload-Metadata; null for non-QR uploads
        public readonly ?string $uploadLabel,      // label from Upload-Metadata; null for non-QR uploads
        public readonly ?int    $assetId,          // null until final commit
        public readonly string  $expiresAt,        // DATETIME string
    ) {}

    /**
     * Construct from a PDO associative fetch row.
     *
     * @param array<string,mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            id:              (int)$row['id'],
            uploadId:        (string)$row['upload_id'],
            userId:          (int)$row['user_id'],
            status:          (string)$row['status'],
            uploadLength:    (int)$row['upload_length'],
            blockCount:      (int)$row['block_count'],
            blockSize:       (int)$row['block_size'],
            sha256Ctx:       isset($row['sha256_ctx']) ? (string)$row['sha256_ctx'] : null,
            fileType:        (string)$row['file_type'],
            mimeType:        (string)$row['mime_type'],
            uploadOrgName:   isset($row['upload_org_name'])   ? (string)$row['upload_org_name']   : null,
            uploadEventDate: isset($row['upload_event_date']) ? (string)$row['upload_event_date'] : null,
            uploadEventType: isset($row['upload_event_type']) ? (string)$row['upload_event_type'] : null,
            uploadLabel:     isset($row['upload_label'])      ? (string)$row['upload_label']      : null,
            assetId:         isset($row['asset_id']) ? (int)$row['asset_id'] : null,
            expiresAt:       (string)$row['expires_at'],
        );
    }

    /** Current byte offset (Upload-Offset) for tus HEAD response. */
    public function uploadOffset(): int
    {
        if ($this->status === 'complete') {
            return $this->uploadLength;
        }
        if ($this->blockSize === 0 || $this->blockCount === 0) {
            return 0;
        }
        return $this->blockCount * $this->blockSize;
    }

    public function isPending(): bool   { return $this->status === 'pending'; }
    public function isComplete(): bool  { return $this->status === 'complete'; }
    public function isFailed(): bool    { return $this->status === 'failed'; }
}
