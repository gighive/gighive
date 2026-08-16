<?php declare(strict_types=1);
namespace Production\Api\Services;

use Production\Api\Config\MediaTypes;
use Production\Api\Exceptions\DuplicateChecksumException;
use Production\Api\Infrastructure\FileStorage;
use Production\Api\Repositories\AssetRepository;
use Production\Api\Repositories\EventItemRepository;
use Production\Api\Repositories\EventRepository;
use Production\Api\Validation\UploadValidator;
use PDO;


final class UploadService
{
    public function __construct(
        private PDO $pdo,
        private ?UploadValidator $validator = null,
        private ?FileStorage $storage = null,
        private ?MediaProbeService $probe = null,
        private ?UnifiedIngestionCore $uic = null,
        private ?TextNormalizer $normalizer = null,
        private ?AssetRepository $assetRepo = null,
        private ?EventRepository $eventRepo = null,
        private ?EventItemRepository $eventItemRepo = null,
    ) {
        $this->validator     = $this->validator     ?? new UploadValidator();
        $this->storage       = $this->storage       ?? new FileStorage();
        $this->probe         = $this->probe         ?? new MediaProbeService();
        $this->normalizer    = $this->normalizer    ?? new TextNormalizer();
        $this->assetRepo     = $this->assetRepo     ?? new AssetRepository($pdo);
        $this->eventRepo     = $this->eventRepo     ?? new EventRepository($pdo);
        $this->eventItemRepo = $this->eventItemRepo ?? new EventItemRepository($pdo);
        if ($this->uic === null) {
            $legacyFilesForUic = new \Production\Api\Repositories\FileRepository($pdo);
            $this->uic = new UnifiedIngestionCore($pdo, $legacyFilesForUic, $this->probe, $this->normalizer);
        }
    }

    /**
     * Handle a single file upload. Returns a map suitable for API JSON response.
     * @param array $files Typically $_FILES
     * @param array $post  Typically $_POST
     */
    public function handleUpload(array $files, array $post): array
    {
        $this->validator->validateFilesArray($files);

        $f = $files['file'];
        $tmpPath  = (string)($f['tmp_name'] ?? '');
        $origName = (string)($f['name'] ?? 'upload.bin');
        $size     = (int)($f['size'] ?? 0);
        $mime     = (string)($f['type'] ?? 'application/octet-stream');

        // Decide file_type and target directory
        $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
        $fileType = $this->probe->inferType($mime, $ext); // 'audio' | 'video' | 'unknown'
        if ($fileType === 'unknown') {
            // Map common extensions if mime is unreliable
            $audioExts = MediaTypes::audioExts();
            if ($audioExts === []) {
                $audioExts = ['mp3','wav','flac','aac'];
            }
            $videoExts = MediaTypes::videoExts();
            if ($videoExts === []) {
                $videoExts = ['mp4','mov','mkv','webm'];
            }
            $fileType = in_array($ext, $audioExts, true) ? 'audio' : (in_array($ext, $videoExts, true) ? 'video' : 'unknown');
        }
        if ($fileType === 'unknown') {
            throw new \InvalidArgumentException('Unsupported media type');
        }

        // Event context
        $eventDate = trim((string)($post['event_date'] ?? date('Y-m-d')));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $eventDate)) {
            throw new \InvalidArgumentException('Invalid event_date format; expected YYYY-MM-DD');
        }
        $orgName      = $this->normalizer->normalizeForStorage(trim((string)($post['org_name'] ?? 'Band')));
        $eventType    = trim((string)($post['event_type'] ?? 'band'));
        $label        = trim((string)($post['label'] ?? ''));
        $participants = trim((string)($post['participants'] ?? ''));
        $keywords     = trim((string)($post['keywords'] ?? ''));
        $location     = trim((string)($post['location'] ?? ''));
        $rating       = trim((string)($post['rating'] ?? ''));
        $notes        = trim((string)($post['notes'] ?? ''));

        // Validate required fields
        if ($label === '') {
            throw new \InvalidArgumentException('Label is required');
        }

        // Ensure an event exists (by date + org_name); create if not present
        $eventId = $this->eventRepo->ensureEvent($eventDate, $orgName, $eventType, $location, $rating, $notes, $keywords);

        // Derive source_relpath for provenance (position now lives in event_items)
        $orgSlug       = $this->slugify($orgName);
        $ymd           = str_replace('-', '', $eventDate);
        $labelSlug     = $label !== '' ? $this->slugify($label) : 'media';
        $sourceRelpath = sprintf('%s%s_%s.%s', $orgSlug, $ymd, $labelSlug, $ext ?: 'bin');

        $baseDir   = dirname(__DIR__, 2); // .../webroot
        $targetDir = $baseDir . '/' . $fileType;
        $this->storage->ensureDir($targetDir);

        // Compute checksum before moving the file
        $checksum = @hash_file('sha256', $tmpPath) ?: null;

        // Stored filename is always {sha256}.{ext}
        $storedName = null;
        if ($checksum !== null) {
            $checksumNorm = strtolower(trim($checksum));
            if (preg_match('/^[0-9a-f]{64}$/', $checksumNorm) === 1) {
                $storedName = $ext !== '' ? ($checksumNorm . '.' . $ext) : $checksumNorm;
                $checksum   = $checksumNorm;
            }
        }
        if ($storedName === null) {
            $storedName = basename($this->uniquePath($targetDir, $sourceRelpath));
        }
        $targetPath = $targetDir . '/' . $storedName;

        // Cross-event reuse and duplicate detection (before disk write)
        if (is_string($checksum) && preg_match('/^[0-9a-f]{64}$/', $checksum) === 1) {
            $existingAsset = $this->assetRepo->findByChecksum($checksum);
            if ($existingAsset !== null) {
                $existingAssetId = (int)$existingAsset['asset_id'];
                $existingLinkId  = $this->eventItemRepo->findLink($eventId, $existingAssetId);
                if ($existingLinkId !== null) {
                    // Same file already linked to this event — true duplicate
                    throw new DuplicateChecksumException($existingAssetId, $checksum);
                }
                // Cross-event reuse: create event_items link; no disk write needed
                $itemType    = ($eventType === 'wedding') ? 'clip' : 'song';
                $position    = $this->eventItemRepo->nextPosition($eventId);
                $eventItemId = $this->eventItemRepo->ensureEventItem($eventId, $existingAssetId, $itemType, $label, $position);
                $this->attachParticipants($eventId, $participants);
                $reusedName = $ext !== '' ? ($checksum . '.' . $ext) : $checksum;
                return [
                    'id'              => $existingAssetId,
                    'asset_id'        => $existingAssetId,
                    'event_id'        => $eventId,
                    'event_item_id'   => $eventItemId,
                    'position'        => $position,
                    'file_name'       => $reusedName,
                    'file_type'       => (string)($existingAsset['file_type'] ?? $fileType),
                    'mime_type'       => $mime,
                    'size_bytes'      => $size,
                    'checksum_sha256' => $checksum,
                    'event_date'      => $eventDate,
                    'org_name'        => $orgName,
                    'event_type'      => $eventType,
                    'label'           => $label,
                    'participants'    => $participants,
                    'keywords'        => $keywords,
                    'duration_seconds'=> isset($existingAsset['duration_seconds']) ? (int)$existingAsset['duration_seconds'] : null,
                ];
            }
        }

        // Move the file
        $this->storage->moveUploadedFile($tmpPath, $targetPath);

        // Probe duration and media info
        $durationSeconds = $this->probe->probeDuration($targetPath);
        if ($fileType === 'video' && is_string($checksum) && preg_match('/^[0-9a-f]{64}$/', $checksum) === 1) {
            $this->probe->generateVideoThumbnail($targetPath, $checksum, $durationSeconds);
        }
        $mediaInfo     = $this->probe->probeMediaInfo($targetPath);
        $mediaInfoTool = $mediaInfo !== null ? $this->probe->ffprobeToolString() : null;
        $mediaCreatedAt = $this->probe->probeMediaCreatedAt($mediaInfo);

        $deleteToken = null;

        // Persist asset
        try {
            $assetId = $this->assetRepo->create([
                'checksum_sha256'  => $checksum,
                'file_ext'         => $ext,
                'file_type'        => $fileType,
                'source_relpath'   => $sourceRelpath,
                'duration_seconds' => $durationSeconds,
                'media_info'       => $mediaInfo,
                'media_info_tool'  => $mediaInfoTool,
                'mime_type'        => $mime ?: null,
                'size_bytes'       => $size ?: null,
                'media_created_at' => $mediaCreatedAt,
            ]);
        } catch (\PDOException $e) {
            if (!is_string($checksum) || preg_match('/^[0-9a-f]{64}$/', $checksum) !== 1 || !$this->isDuplicateChecksumException($e)) {
                throw $e;
            }
            $existingAsset = $this->assetRepo->findByChecksum($checksum);
            if (!$existingAsset || !isset($existingAsset['asset_id'])) {
                throw $e;
            }
            $existingAssetId = (int)$existingAsset['asset_id'];
            if (is_file($targetPath)) {
                @unlink($targetPath);
            }
            throw new DuplicateChecksumException($existingAssetId, (string)$checksum);
        }

        // Set delete token
        $deleteToken = bin2hex(random_bytes(32));
        $hash = hash('sha256', $deleteToken);
        if (!$this->assetRepo->setDeleteTokenHashIfNull($assetId, $hash)) {
            $deleteToken = null;
        }

        // Create event_items link
        $itemType    = ($eventType === 'wedding') ? 'clip' : 'song';
        $position    = $this->eventItemRepo->nextPosition($eventId);
        $eventItemId = $this->eventItemRepo->ensureEventItem($eventId, $assetId, $itemType, $label, $position);

        // Attach participants
        $this->attachParticipants($eventId, $participants);

        if ($fileType === 'video' && filter_var(getenv('AI_WORKER_ENABLED'), FILTER_VALIDATE_BOOLEAN)) {
            $this->uic->enqueueAiJob($assetId, 'categorize_video', 'asset');
        }

        $storedFileName = $ext !== '' ? ($checksum . '.' . $ext) : ($checksum ?? $storedName);
        $resp = [
            'id'              => $assetId,
            'asset_id'        => $assetId,
            'event_id'        => $eventId,
            'event_item_id'   => $eventItemId,
            'position'        => $position,
            'file_name'       => $storedFileName,
            'file_type'       => $fileType,
            'mime_type'       => $mime,
            'size_bytes'      => $size,
            'checksum_sha256' => $checksum,
            'event_date'      => $eventDate,
            'org_name'        => $orgName,
            'event_type'      => $eventType,
            'label'           => $label,
            'participants'    => $participants,
            'keywords'        => $keywords,
            'duration_seconds'=> $durationSeconds,
        ];
        if (is_string($deleteToken) && $deleteToken !== '') {
            $resp['delete_token'] = $deleteToken;
        }
        return $resp;
    }

    private function isDuplicateChecksumException(\PDOException $e): bool
    {
        $info = $e->errorInfo;
        if (is_array($info) && isset($info[1]) && (int)$info[1] === 1062) {
            $msg = $e->getMessage();
            return str_contains($msg, 'assets_uq_checksum') || str_contains($msg, 'checksum_sha256');
        }
        $msg = $e->getMessage();
        return str_contains($msg, 'SQLSTATE[23000]') && str_contains($msg, '1062') && (str_contains($msg, 'checksum_sha256') || str_contains($msg, 'assets_uq_checksum'));
    }

    /**
     * Finalize a completed tus upload.
     *
     * Looks up the upload in the tus_uploads table (written by TusBlockUploadService).
     * Returns a map compatible with the existing /api/uploads/finalize response shape.
     * If the upload is still pending (rare sub-millisecond race), returns status=pending
     * so the client can retry after a short delay.
     *
     * Token-mode: records upload_jobs + anon_upload_attributions for QR guest uploads.
     */
    public function finalizeTusUpload(array $post, ?TokenValidationResult $tokenResult = null): array
    {
        $uploadId = trim((string)($post['upload_id'] ?? ''));
        if ($uploadId === '' || preg_match('/^[0-9a-f-]+$/i', $uploadId) !== 1) {
            throw new \InvalidArgumentException('Missing or invalid upload_id');
        }

        // Token-mode field validation (unchanged from prior implementation)
        $tokenLabel       = null;
        $tokenDisplayName = null;
        if ($tokenResult !== null) {
            $tokenLabel       = trim((string)($post['label'] ?? ''));
            $tosAccepted      = $post['tos_accepted'] ?? null;
            $tokenDisplayName = isset($post['display_name'])
                ? substr(strip_tags(trim((string)$post['display_name'])), 0, 100)
                : null;
            if ($tokenLabel === '' || strlen($tokenLabel) > 255 || $tosAccepted !== true) {
                throw new \InvalidArgumentException('Missing or invalid token-mode fields (label, tos_accepted)');
            }
        }

        // Look up upload state in the new tus_uploads table
        $stmt = $this->pdo->prepare(
            'SELECT tu.status, tu.asset_id, tu.file_type, tu.mime_type,
                    a.checksum_sha256, a.file_ext, a.size_bytes, a.source_relpath
             FROM tus_uploads tu
             LEFT JOIN assets a ON a.asset_id = tu.asset_id
             WHERE tu.upload_id = ?'
        );
        $stmt->execute([$uploadId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($row === false) {
            throw new \RuntimeException('Upload not found: upload_id=' . $uploadId);
        }

        if ($row['status'] === 'pending') {
            // Upload still in progress — very rare race; client should retry
            return ['status' => 'pending', 'upload_id' => $uploadId];
        }

        if ($row['status'] === 'failed') {
            throw new \RuntimeException('Upload failed: upload_id=' . $uploadId);
        }

        // status === 'complete'
        $assetId  = (int)$row['asset_id'];
        $fileType = (string)$row['file_type'];
        $checksum = (string)($row['checksum_sha256'] ?? '');
        $fileExt  = (string)($row['file_ext'] ?? '');
        $fileSize = (int)($row['size_bytes'] ?? 0);
        $mimeType = (string)($row['mime_type'] ?? '');
        $fileName = $fileExt !== '' ? ($checksum . '.' . $fileExt) : $checksum;

        $result = [
            'asset_id'        => $assetId,
            'file_name'       => $fileName,
            'file_type'       => $fileType,
            'size_bytes'      => $fileSize,
            'mime_type'       => $mimeType,
            'checksum_sha256' => $checksum,
            'duration_seconds'=> null, // filled async by probe job
            'thumbnail_done'  => false, // filled async by probe job
            'db_done'         => true,
        ];

        // Token-mode: record upload_jobs + anon_upload_attributions
        if ($tokenResult !== null) {
            $statusNonce = rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
            $fileRelpath = $fileType . '/' . $fileName;
            $tenantId    = (int)(getenv('QR_GUEST_UPLOAD_TENANT_ID') ?: 1);
            $this->pdo->beginTransaction();
            try {
                $jobStmt = $this->pdo->prepare(
                    'INSERT INTO upload_jobs (tenant_id, job_id, job_type, status, total_files, started_at,
                                              label, file_relpath, moderation_status)
                     VALUES (?, ?, \'qr_guest_upload\', \'completed\', 1, NOW(), ?, ?, \'pending\')'
                );
                $jobStmt->execute([$tenantId, $uploadId, $tokenLabel, $fileRelpath]);
                $uploadJobsRowId = (int)$this->pdo->lastInsertId();
                $this->pdo->prepare(
                    'INSERT INTO anon_upload_attributions
                       (token_id, upload_job_id, display_name, tos_accepted_at, status_nonce)
                     VALUES (?, ?, ?, NOW(), ?)'
                )->execute([$tokenResult->tokenId, $uploadId, $tokenDisplayName, $statusNonce]);
                $this->pdo->commit();
            } catch (\Throwable $attrEx) {
                $this->pdo->rollBack();
                throw new \RuntimeException('Failed to record guest upload attribution: ' . $attrEx->getMessage());
            }
            $result['status_nonce']  = $statusNonce;
            $result['upload_job_id'] = $uploadJobsRowId;
        }

        return $result;
    }

    /**
     * Finalize a manifest-driven import upload (admin import worker).
     *
     * Looks up the completed tus_uploads row and verifies the stored asset checksum
     * matches the manifest expectation. The asset row was created by TusBlockUploadService
     * on the final PATCH; this method just verifies and returns the result map.
     *
     * @param string $uploadId  The tus upload ID.
     * @param string $checksum  Expected SHA-256 checksum from the manifest.
     * @return array            Result map.
     */
    public function finalizeManifestTusUpload(string $uploadId, string $checksum): array
    {
        $uploadId = trim($uploadId);
        $checksum = strtolower(trim($checksum));

        if ($uploadId === '' || preg_match('/^[0-9a-f-]+$/i', $uploadId) !== 1) {
            throw new \InvalidArgumentException('Missing or invalid upload_id');
        }
        if (!preg_match('/^[0-9a-f]{64}$/', $checksum)) {
            throw new \InvalidArgumentException('Missing or invalid checksum_sha256');
        }

        $stmt = $this->pdo->prepare(
            'SELECT tu.status, tu.asset_id, tu.file_type, tu.mime_type,
                    a.checksum_sha256, a.file_ext, a.size_bytes
             FROM tus_uploads tu
             LEFT JOIN assets a ON a.asset_id = tu.asset_id
             WHERE tu.upload_id = ?'
        );
        $stmt->execute([$uploadId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($row === false) {
            throw new \RuntimeException('Upload not found: upload_id=' . $uploadId);
        }
        if ($row['status'] !== 'complete') {
            throw new \RuntimeException(
                'Upload not complete (status=' . $row['status'] . '): upload_id=' . $uploadId
            );
        }

        $storedChecksum = strtolower((string)($row['checksum_sha256'] ?? ''));
        if ($storedChecksum !== $checksum) {
            throw new \RuntimeException(
                sprintf('Checksum mismatch: expected %s, got %s', $checksum, $storedChecksum)
            );
        }

        $assetId  = (int)$row['asset_id'];
        $fileType = (string)$row['file_type'];
        $fileExt  = (string)($row['file_ext'] ?? '');
        $fileSize = (int)($row['size_bytes'] ?? 0);
        $mimeType = (string)($row['mime_type'] ?? '');
        $storedName = $fileExt !== '' ? ($storedChecksum . '.' . $fileExt) : $storedChecksum;

        return [
            'asset_id'         => $assetId,
            'file_name'        => $storedName,
            'file_type'        => $fileType,
            'size_bytes'       => $fileSize,
            'mime_type'        => $mimeType,
            'checksum_sha256'  => $storedChecksum,
            'duration_seconds' => null, // filled async by probe job
            'thumbnail_done'   => false,
            'db_done'          => true,
        ];
    }

    private function uniquePath(string $dir, string $name): string
    {
        $base = pathinfo($name, PATHINFO_FILENAME);
        $ext  = pathinfo($name, PATHINFO_EXTENSION);
        $candidate = $name;
        $i = 1;
        while (file_exists($dir . '/' . $candidate)) {
            $candidate = $base . '-' . $i++ . ($ext !== '' ? '.' . $ext : '');
        }
        return $dir . '/' . $candidate;
    }

    private function slugify(string $s): string
    {
        return $this->normalizer->slugifyForFilename($s);
    }

    private function attachParticipants(int $eventId, string $csv): void
    {
        $names = array_filter(array_map('trim', explode(',', $csv)));
        if (!$names) return;
        foreach ($names as $name) {
            $name = $this->normalizer->normalizeForStorage($name);
            if ($name === '') continue;
            // Find or create participant
            $stmt = $this->pdo->prepare('SELECT participant_id FROM participants WHERE name = :n LIMIT 1');
            $stmt->execute([':n' => $name]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $pid = $row['participant_id'] ?? null;
            if (!$pid) {
                $this->pdo->prepare('INSERT INTO participants (name) VALUES (:n)')->execute([':n' => $name]);
                $pid = (int)$this->pdo->lastInsertId();
            } else {
                $pid = (int)$pid;
            }
            // Link to event
            $sql = 'INSERT INTO event_participants (event_id, participant_id)'
                 . ' VALUES (:e, :p) ON DUPLICATE KEY UPDATE participant_id = participant_id';
            $this->pdo->prepare($sql)->execute([':e' => $eventId, ':p' => $pid]);
        }
    }
}
