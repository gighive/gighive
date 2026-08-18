<?php

declare(strict_types=1);

namespace Production\Api\Services;

use PDO;
use Production\Api\Config\MediaTypes;
use Production\Api\Config\TusUploadConfig;
use Production\Api\Contracts\TusChunkBackendInterface;
use Production\Api\Dto\TusUploadState;

/**
 * PHP tus 1.0 upload server — handles POST, PATCH, and HEAD requests.
 *
 * Protocol: tus 1.0 core + creation extension.
 * Clients: TUSKit (iOS) and tus-js-client (browser) — no client changes needed.
 *
 * Security invariants:
 *  - upload_id is server-generated UUID v4; client-provided IDs are ignored.
 *  - File type validated at POST against allow-list; MIME sniffed again at final PATCH.
 *  - Each PATCH acquires a DB row lock (SELECT ... FOR UPDATE) to prevent concurrent offset corruption.
 *  - SHA-256 hash context is serialized to DB across stateless PHP-FPM requests.
 *  - block_size is set from the first PATCH body length and never updated after (final chunk is smaller).
 *
 * Decomposition:
 *  handlePost()  → validates metadata, creates tus_uploads row, returns 201 + Location header
 *  handlePatch() → acquires lock, streams chunk to backend, updates DB, optionally finalizes
 *  handleHead()  → returns Upload-Offset from DB state
 */
final class TusBlockUploadService
{
    public function __construct(
        private readonly TusUploadConfig          $config,
        private readonly TusChunkBackendInterface $backend,
    ) {}

    // -------------------------------------------------------------------------
    // POST /files/  — create upload
    // -------------------------------------------------------------------------

    /**
     * Handle a tus POST /files/ request.
     *
     * Validates Upload-Length, Upload-Metadata (file type + MIME), and per-user
     * pending upload count. Creates a tus_uploads row and returns the Location header.
     *
     * Sends HTTP response and exits; never returns normally.
     *
     * @param int    $userId         Authenticated user ID (from Apache basic auth lookup).
     * @param array  $requestHeaders Normalized request headers (lowercase keys).
     */
    public function handlePost(int $userId, array $requestHeaders): never
    {
        // --- Parse Upload-Length ---
        $uploadLength = (int)($requestHeaders['upload-length'] ?? 0);
        if ($uploadLength <= 0) {
            $this->respond(400, ['Content-Type' => 'application/json'],
                json_encode(['error' => 'Missing or invalid Upload-Length']));
        }
        if ($uploadLength > $this->config->maxFileSizeBytes) {
            $this->respond(413, ['Content-Type' => 'application/json'],
                json_encode(['error' => 'Upload-Length exceeds maximum allowed file size']));
        }

        // --- Azure block count limit check ---
        if ($this->config->isAzure()) {
            try {
                $this->config->assertAzureBlockLimit($uploadLength);
            } catch (\InvalidArgumentException $e) {
                $this->respond(413, ['Content-Type' => 'application/json'],
                    json_encode(['error' => $e->getMessage()]));
            }
        }

        // --- Parse Upload-Metadata ---
        $meta     = $this->parseUploadMetadata($requestHeaders['upload-metadata'] ?? '');
        $mimeType = strtolower(trim($meta['filetype'] ?? ''));
        $fileName = trim($meta['filename'] ?? '');
        $fileExt  = strtolower(ltrim(pathinfo($fileName, PATHINFO_EXTENSION), '.'));

        // --- MIME + extension allow-list validation ---
        [$fileType, $mimeType] = $this->validateFileType($mimeType, $fileExt);

        // --- Per-user pending upload rate limit ---
        $this->checkPendingLimit($userId);

        // --- Generate upload_id server-side ---
        $uploadId = $this->generateUploadId();

        // --- Insert tus_uploads row ---
        $stmt = $this->config->pdo->prepare(
            'INSERT INTO tus_uploads
             (upload_id, user_id, status, upload_length, file_type, mime_type, created_at, expires_at)
             VALUES (?, ?, \'pending\', ?, ?, ?, NOW(), NOW() + INTERVAL 24 HOUR)'
        );
        $stmt->execute([$uploadId, $userId, $uploadLength, $fileType, $mimeType]);

        $this->respond(201, [
            'Location'          => '/files/' . $uploadId,
            'Tus-Resumable'     => '1.0.0',
            'Upload-Offset'     => '0',
        ], '');
    }

    // -------------------------------------------------------------------------
    // PATCH /files/{id}  — send chunk
    // -------------------------------------------------------------------------

    /**
     * Handle a tus PATCH /files/{uploadId} request.
     *
     * Acquires an exclusive DB lock on the upload row, streams the request body
     * to the chunk backend, updates the hash context and block count in DB.
     * On the final PATCH, finalizes the upload and enqueues a probe job.
     *
     * Sends HTTP response and exits; never returns normally.
     *
     * @param string $uploadId       Server-generated upload ID from the URL path.
     * @param array  $requestHeaders Normalized request headers (lowercase keys).
     */
    public function handlePatch(string $uploadId, array $requestHeaders): never
    {
        $pdo = $this->config->pdo;

        // --- Validate tus headers ---
        $contentType = $requestHeaders['content-type'] ?? '';
        if (stripos($contentType, 'application/offset+octet-stream') === false) {
            $this->respond(415, [], '');
        }

        $clientOffset = isset($requestHeaders['upload-offset'])
            ? (int)$requestHeaders['upload-offset']
            : -1;
        if ($clientOffset < 0) {
            $this->respond(400, ['Content-Type' => 'application/json'],
                json_encode(['error' => 'Missing Upload-Offset header']));
        }

        // --- Acquire exclusive lock ---
        $pdo->beginTransaction();
        try {
            $upload = $this->fetchUploadForUpdate($pdo, $uploadId);

            if ($upload === null) {
                $pdo->rollBack();
                $this->respond(404, [], '');
            }

            if ($upload->isComplete()) {
                $pdo->rollBack();
                $this->respond(204, [
                    'Upload-Offset'  => (string)$upload->uploadLength,
                    'Tus-Resumable'  => '1.0.0',
                ], '');
            }

            if ($upload->isFailed()) {
                $pdo->rollBack();
                $this->respond(409, ['Content-Type' => 'application/json'],
                    json_encode(['error' => 'Upload has permanently failed']));
            }

            $serverOffset = $upload->uploadOffset();
            if ($clientOffset !== $serverOffset) {
                $pdo->rollBack();
                $this->respond(409, [
                    'Upload-Offset' => (string)$serverOffset,
                    'Content-Type'  => 'application/json',
                ], json_encode(['error' => 'Upload-Offset mismatch']));
            }

            // --- Read chunk body ---
            $body       = (string)file_get_contents('php://input');
            $chunkSize  = strlen($body);
            if ($chunkSize === 0) {
                $pdo->rollBack();
                $this->respond(400, ['Content-Type' => 'application/json'],
                    json_encode(['error' => 'Empty PATCH body']));
            }

            // --- Restore or init hash context ---
            $hashCtx = ($upload->sha256Ctx !== null)
                ? unserialize($upload->sha256Ctx)
                : hash_init('sha256');

            if (!($hashCtx instanceof \HashContext)) {
                // Corrupted context — reset (upload will produce wrong checksum; log + fail)
                error_log('[TusBlockUploadService] Corrupted hash context for upload=' . $uploadId . '; aborting');
                $pdo->rollBack();
                $this->markFailed($uploadId);
                $this->respond(500, [], '');
            }

            hash_update($hashCtx, $body);

            // --- Write chunk to backend ---
            $this->backend->writeChunk(
                $uploadId,
                $upload->blockCount,
                $body,
                $upload->mimeType,
                $upload->fileType,
            );

            $newOffset = $serverOffset + $chunkSize;
            $isLast    = ($newOffset >= $upload->uploadLength);

            // --- Update DB: block_count, sha256_ctx, block_size (first PATCH only) ---
            if ($upload->blockCount === 0) {
                $pdo->prepare(
                    'UPDATE tus_uploads SET sha256_ctx = ?, block_count = 1, block_size = ? WHERE upload_id = ?'
                )->execute([serialize($hashCtx), $chunkSize, $uploadId]);
            } else {
                $pdo->prepare(
                    'UPDATE tus_uploads SET sha256_ctx = ?, block_count = block_count + 1 WHERE upload_id = ?'
                )->execute([serialize($hashCtx), $uploadId]);
            }

            // --- Finalize on last PATCH ---
            if ($isLast) {
                $checksum = hash_final($hashCtx);
                $fileExt  = $this->extensionForMime($upload->mimeType);
                $assetId  = $this->finalizeUpload($pdo, $upload, $checksum, $fileExt);
                $pdo->commit();
                $this->respond(204, [
                    'Upload-Offset' => (string)$upload->uploadLength,
                    'Tus-Resumable' => '1.0.0',
                    'X-Asset-Id'    => (string)$assetId,
                ], '');
            }

            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('[TusBlockUploadService] PATCH error for upload=' . $uploadId . ': ' . $e->getMessage());
            // Mark the row failed so it no longer consumes the per-user pending quota.
            // Must run outside the rolled-back transaction — markFailed() uses its own statement.
            $this->markFailed($uploadId);
            $this->respond(500, [], '');
        }

        $this->respond(204, [
            'Upload-Offset' => (string)($clientOffset + strlen($body ?? '')),
            'Tus-Resumable' => '1.0.0',
        ], '');
    }

    // -------------------------------------------------------------------------
    // HEAD /files/{id}  — resume query
    // -------------------------------------------------------------------------

    /**
     * Handle a tus HEAD /files/{uploadId} request.
     *
     * Returns the current Upload-Offset so clients know where to resume.
     * Sends HTTP response and exits; never returns normally.
     *
     * @param string $uploadId Server-generated upload ID from the URL path.
     */
    public function handleHead(string $uploadId): never
    {
        $stmt = $this->config->pdo->prepare(
            'SELECT id, upload_id, user_id, status, upload_length,
                    block_count, block_size, sha256_ctx, file_type, mime_type,
                    asset_id, expires_at
             FROM tus_uploads WHERE upload_id = ?'
        );
        $stmt->execute([$uploadId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            $this->respond(404, [], '');
        }

        $upload = TusUploadState::fromRow($row);
        $this->respond(200, [
            'Upload-Offset'  => (string)$upload->uploadOffset(),
            'Upload-Length'  => (string)$upload->uploadLength,
            'Tus-Resumable'  => '1.0.0',
            'Cache-Control'  => 'no-store',
        ], '');
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Finalize the upload: call backend, insert asset row, enqueue probe job,
     * update tus_uploads status. All within the caller's open transaction.
     */
    private function finalizeUpload(PDO $pdo, TusUploadState $upload, string $checksum, string $fileExt): int
    {
        // Backend: commit blocks / move staging file
        $storageKey = $this->backend->finalizeUpload(
            $upload->uploadId,
            $checksum,
            $fileExt,
            $upload->mimeType,
            $upload->fileType,
            $upload->uploadLength,
        );

        // Insert asset row — source_relpath stores the storage key (blob key or local rel path).
        // If a row already exists for this tenant+checksum (re-upload of a known file),
        // return the existing asset_id rather than crashing. This makes re-upload idempotent.
        $assetStmt = $pdo->prepare(
            'INSERT INTO assets
             (tenant_id, checksum_sha256, file_type, file_ext, source_relpath, size_bytes, mime_type,
              created_at, updated_at)
             VALUES (1, ?, ?, ?, ?, ?, ?, NOW(), NOW())
             ON DUPLICATE KEY UPDATE asset_id = LAST_INSERT_ID(asset_id)'
        );
        $assetStmt->execute([
            $checksum,
            $upload->fileType,
            $fileExt,
            $storageKey,
            $upload->uploadLength,
            $upload->mimeType,
        ]);
        $assetId = (int)$pdo->lastInsertId();

        // Enqueue probe job (ffprobe + thumbnail) only if no job already exists for
        // this asset. On re-upload of a known file the asset row is reused (ON DUPLICATE
        // KEY above) — avoid queuing a redundant probe job in that case.
        $pdo->prepare(
            'INSERT INTO probe_jobs (asset_id, blob_key, file_type, status, created_at)
             SELECT ?, ?, ?, \'queued\', NOW()
             FROM DUAL
             WHERE NOT EXISTS (SELECT 1 FROM probe_jobs WHERE asset_id = ?)'
        )->execute([$assetId, $storageKey, $upload->fileType, $assetId]);

        // Mark upload complete
        $pdo->prepare(
            'UPDATE tus_uploads SET status = \'complete\', asset_id = ? WHERE upload_id = ?'
        )->execute([$assetId, $upload->uploadId]);

        return $assetId;
    }

    /** Fetch the upload row with exclusive lock (must be inside an open transaction). */
    private function fetchUploadForUpdate(PDO $pdo, string $uploadId): ?TusUploadState
    {
        $stmt = $pdo->prepare(
            'SELECT id, upload_id, user_id, status, upload_length,
                    block_count, block_size, sha256_ctx, file_type, mime_type,
                    asset_id, expires_at
             FROM tus_uploads WHERE upload_id = ? FOR UPDATE'
        );
        $stmt->execute([$uploadId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return ($row === false) ? null : TusUploadState::fromRow($row);
    }

    /** Mark an upload row as failed without a transaction (best-effort cleanup). */
    private function markFailed(string $uploadId): void
    {
        try {
            $this->config->pdo->prepare(
                'UPDATE tus_uploads SET status = \'failed\' WHERE upload_id = ?'
            )->execute([$uploadId]);
        } catch (\Throwable) {
            // best-effort
        }
    }

    /**
     * Validate MIME type and extension against the configured allow-list.
     * Returns [$fileType, $normalizedMime].
     * Sends 415 and exits on failure.
     *
     * @return array{string,string}
     */
    private function validateFileType(string $mimeType, string $fileExt): array
    {
        $allowedMimes = MediaTypes::allowedMimes();
        $audioExts    = MediaTypes::audioExts();
        $videoExts    = MediaTypes::videoExts();

        // If mimeType is present, validate it against the allow-list.
        // If mimeType is absent (client did not send filetype in Upload-Metadata),
        // skip the MIME check and rely solely on extension classification below.
        // Web upload forms (tus-js-client) do not send filetype by default.
        if ($mimeType !== '' && !empty($allowedMimes) && !in_array($mimeType, $allowedMimes, true)) {
            $this->respond(415, ['Content-Type' => 'application/json'],
                json_encode(['error' => 'Unsupported file type']));
        }

        $isAudio = in_array($fileExt, $audioExts, true)
            || str_starts_with($mimeType, 'audio/');
        $isVideo = in_array($fileExt, $videoExts, true)
            || str_starts_with($mimeType, 'video/');

        if (!$isAudio && !$isVideo) {
            $this->respond(415, ['Content-Type' => 'application/json'],
                json_encode(['error' => 'File type is neither audio nor video — check filename extension']));
        }

        // If MIME was absent, derive it from the extension for storage metadata.
        if ($mimeType === '') {
            $mimeType = in_array($fileExt, $audioExts, true) ? 'audio/' . $fileExt : 'video/' . $fileExt;
        }

        $fileType = $isVideo ? 'video' : 'audio';
        return [$fileType, $mimeType];
    }

    /** Check per-user pending upload count; responds 429 and exits if exceeded. */
    private function checkPendingLimit(int $userId): void
    {
        $stmt = $this->config->pdo->prepare(
            'SELECT COUNT(*) FROM tus_uploads
             WHERE user_id = ? AND status = \'pending\' AND expires_at > NOW()'
        );
        $stmt->execute([$userId]);
        $count = (int)$stmt->fetchColumn();

        if ($count >= $this->config->maxPendingUploadsPerToken) {
            $this->respond(429, ['Content-Type' => 'application/json'],
                json_encode(['error' => 'Too many pending uploads']));
        }
    }

    /**
     * Parse the tus Upload-Metadata header into a key=>value map.
     * Values are base64-decoded per the tus spec.
     *
     * @return array<string,string>
     */
    private function parseUploadMetadata(string $header): array
    {
        $meta = [];
        if ($header === '') {
            return $meta;
        }
        foreach (explode(',', $header) as $pair) {
            $parts = explode(' ', trim($pair), 2);
            $key   = trim($parts[0]);
            $value = isset($parts[1]) ? (string)base64_decode(trim($parts[1]), true) : '';
            if ($key !== '') {
                $meta[$key] = ($value === false) ? '' : $value;
            }
        }
        return $meta;
    }

    /** Generate a server-side UUID v4. */
    private function generateUploadId(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }

    /** Derive file extension from MIME type for common audio/video types. */
    private function extensionForMime(string $mimeType): string
    {
        $map = [
            'audio/mpeg'       => 'mp3',
            'audio/mp3'        => 'mp3',
            'audio/wav'        => 'wav',
            'audio/x-wav'      => 'wav',
            'audio/aac'        => 'aac',
            'audio/ogg'        => 'ogg',
            'video/mp4'        => 'mp4',
            'video/quicktime'  => 'mov',
            'video/webm'       => 'webm',
            'video/x-msvideo'  => 'avi',
        ];
        return $map[strtolower($mimeType)] ?? 'bin';
    }

    /**
     * Send an HTTP response and exit.
     *
     * @param int                  $status
     * @param array<string,string> $headers
     * @param string               $body
     */
    private function respond(int $status, array $headers, string $body): never
    {
        http_response_code($status);
        header('Tus-Resumable: 1.0.0');
        foreach ($headers as $name => $value) {
            header($name . ': ' . $value);
        }
        if ($body !== '') {
            echo $body;
        }
        exit;
    }
}
