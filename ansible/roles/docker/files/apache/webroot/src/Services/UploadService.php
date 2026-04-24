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
                $itemType    = ($eventType === 'wedding') ? 'event_label' : 'song';
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
        $itemType    = ($eventType === 'wedding') ? 'event_label' : 'song';
        $position    = $this->eventItemRepo->nextPosition($eventId);
        $eventItemId = $this->eventItemRepo->ensureEventItem($eventId, $assetId, $itemType, $label, $position);

        // Attach participants
        $this->attachParticipants($eventId, $participants);

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
     * Finalize a completed tusd upload (Option A).
     * Expects JSON body containing upload_id + same metadata fields used by handleUpload.
     */
    public function finalizeTusUpload(array $post): array
    {
        $uploadId = trim((string)($post['upload_id'] ?? ''));
        if ($uploadId === '' || preg_match('/^[A-Za-z0-9_-]+$/', $uploadId) !== 1) {
            throw new \InvalidArgumentException('Missing or invalid upload_id');
        }

        // tusd data + hook outputs are mounted into the Apache container under /var/www/private
        $hookDir = '/var/www/private/tus-hooks/uploads';
        $finalDir = '/var/www/private/tus-hooks/finalized';
        $dataDir = '/var/www/private/tus-data';

        $this->storage->ensureDir($finalDir);
        $finalMarker = $finalDir . '/' . $uploadId . '.json';
        if (is_file($finalMarker)) {
            $raw = @file_get_contents($finalMarker);
            $decoded = is_string($raw) ? json_decode($raw, true) : null;
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        $hookFile = $hookDir . '/' . $uploadId . '.json';
        if (!is_file($hookFile)) {
            // tusd fires the post-finish hook asynchronously: the final PATCH 204 reaches
            // the browser (and triggers this finalize call) before the hook subprocess has
            // finished writing its JSON file.  Poll briefly to absorb that race.
            $maxAttempts = 10;
            $sleepUs     = 200000; // 200 ms
            for ($i = 0; $i < $maxAttempts; $i++) {
                usleep($sleepUs);
                if (is_file($hookFile)) {
                    break;
                }
            }
        }
        if (!is_file($hookFile)) {
            $dataFileExists = is_file($dataDir . '/' . $uploadId);
            $infoFileExists = is_file($dataDir . '/' . $uploadId . '.info');
            if ($dataFileExists || $infoFileExists) {
                throw new \RuntimeException(
                    'Upload data exists on disk but post-finish hook output has not been written yet '
                    . '(upload_id=' . $uploadId . '). The server may still be processing; retry in a moment.'
                );
            }
            throw new \RuntimeException('Upload not found: no data or hook record for upload_id=' . $uploadId);
        }
        $hookRaw = (string)@file_get_contents($hookFile);
        $hook = json_decode($hookRaw, true);
        if (!is_array($hook)) {
            throw new \RuntimeException('Invalid tus hook payload');
        }

        $meta = [];
        if (isset($hook['Event']['Upload']['MetaData']) && is_array($hook['Event']['Upload']['MetaData'])) {
            $meta = $hook['Event']['Upload']['MetaData'];
        } elseif (isset($hook['Upload']['MetaData']) && is_array($hook['Upload']['MetaData'])) {
            $meta = $hook['Upload']['MetaData'];
        } elseif (isset($hook['MetaData']) && is_array($hook['MetaData'])) {
            $meta = $hook['MetaData'];
        }
        if (!is_array($meta)) $meta = [];
        $origName = (string)($meta['filename'] ?? 'upload.bin');
        $origName = $this->probe->sanitizeFilename($origName);

        $mergedPost = $post;
        $fallbackKeys = [
            'event_date',
            'org_name',
            'event_type',
            'label',
            'participants',
            'keywords',
            'location',
            'rating',
            'notes',
        ];
        foreach ($fallbackKeys as $k) {
            $cur = $mergedPost[$k] ?? null;
            if ($cur === null || (is_string($cur) && trim($cur) === '')) {
                if (isset($meta[$k]) && is_string($meta[$k]) && trim($meta[$k]) !== '') {
                    $mergedPost[$k] = $this->normalizer->normalizeForStorage($meta[$k]);
                }
            }
        }

        $srcPath = $dataDir . '/' . $uploadId;
        if (!is_file($srcPath)) {
            throw new \RuntimeException('Upload data missing on disk');
        }

        $size = (int)@filesize($srcPath);
        if ($size <= 0) {
            throw new \RuntimeException('Uploaded file is empty');
        }

        $mime = '';
        $fi = new \finfo(FILEINFO_MIME_TYPE);
        $detected = @$fi->file($srcPath);
        if (is_string($detected) && $detected !== '') {
            $mime = $detected;
        }

        $files = [
            'file' => [
                'name' => $origName,
                'type' => $mime,
                'tmp_name' => $srcPath,
                'error' => UPLOAD_ERR_OK,
                'size' => $size,
            ],
        ];

        try {
            $result = $this->handleUpload($files, $mergedPost);
        } catch (DuplicateChecksumException $e) {
            // If we reject the upload, clean up tusd data to avoid disk accumulation.
            if (is_file($srcPath)) {
                @unlink($srcPath);
            }
            throw $e;
        }

        $markerResult = $result;
        if (is_array($markerResult) && array_key_exists('delete_token', $markerResult)) {
            unset($markerResult['delete_token']);
        }
        @file_put_contents($finalMarker, json_encode($markerResult));
        return $result;
    }

    /**
     * Finalize a completed TUS upload for a manifest-driven import job.
     *
     * Unlike finalizeTusUpload(), this path UPDATES the existing manifest-created
     * files row identified by checksum instead of rejecting it as a duplicate.
     * The manifest row must already exist (written by Step 1 / import_manifest_worker).
     *
     * @param string $uploadId  The TUS upload ID.
     * @param string $checksum  Expected SHA-256 checksum from the manifest.
     * @return array            Result map written to upload_status.json.
     */
    public function finalizeManifestTusUpload(string $uploadId, string $checksum): array
    {
        $uploadId = trim($uploadId);
        $checksum = strtolower(trim($checksum));

        if ($uploadId === '' || preg_match('/^[A-Za-z0-9_-]+$/', $uploadId) !== 1) {
            throw new \InvalidArgumentException('Missing or invalid upload_id');
        }
        if (!preg_match('/^[0-9a-f]{64}$/', $checksum)) {
            throw new \InvalidArgumentException('Missing or invalid checksum_sha256');
        }

        $hookDir  = '/var/www/private/tus-hooks/uploads';
        $finalDir = '/var/www/private/tus-hooks/finalized';
        $dataDir  = '/var/www/private/tus-data';

        $this->storage->ensureDir($finalDir);
        // Use a distinct marker prefix so manifest finalizations never collide
        // with normal finalizeTusUpload() markers for the same upload_id.
        $finalMarker = $finalDir . '/manifest-' . $uploadId . '.json';
        if (is_file($finalMarker)) {
            $raw = @file_get_contents($finalMarker);
            $decoded = is_string($raw) ? json_decode($raw, true) : null;
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        $srcPath = $dataDir . '/' . $uploadId;
        if (!is_file($srcPath)) {
            throw new \RuntimeException('Upload data missing on disk');
        }
        $size = (int)@filesize($srcPath);
        if ($size <= 0) {
            throw new \RuntimeException('Uploaded file is empty');
        }

        // Verify uploaded bytes match the manifest checksum before doing anything else.
        $actualChecksum = @hash_file('sha256', $srcPath) ?: '';
        if ($actualChecksum !== $checksum) {
            throw new \RuntimeException(
                sprintf('Checksum mismatch: expected %s, got %s', $checksum, $actualChecksum)
            );
        }

        $existing = $this->assetRepo->findByChecksum($checksum);
        if (!$existing || !isset($existing['asset_id'])) {
            throw new \RuntimeException(
                'No manifest row found for checksum ' . $checksum . '; Step 1 must complete before Step 2'
            );
        }
        $assetId  = (int)$existing['asset_id'];
        $fileType = (string)($existing['file_type'] ?? '');
        if (!in_array($fileType, ['audio', 'video'], true)) {
            throw new \RuntimeException('Invalid file_type in manifest row: ' . $fileType);
        }

        // Determine file extension from TUS hook metadata, falling back to the
        // existing file_name column written by the manifest import in Step 1.
        $ext = '';
        $hookFile = $hookDir . '/' . $uploadId . '.json';
        if (is_file($hookFile)) {
            $hookRaw = (string)@file_get_contents($hookFile);
            $hook = json_decode($hookRaw, true);
            if (is_array($hook)) {
                $meta = $hook['Event']['Upload']['MetaData']
                    ?? $hook['Upload']['MetaData']
                    ?? $hook['MetaData']
                    ?? [];
                $origName = isset($meta['filename']) ? $this->probe->sanitizeFilename((string)$meta['filename']) : '';
                if ($origName !== '') {
                    $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
                }
            }
        }
        if ($ext === '') {
            $ext = strtolower((string)($existing['file_ext'] ?? ''));
        }

        // Store as {checksum}.{ext} — matches upload_media_by_hash.py naming convention.
        $baseDir   = dirname(__DIR__, 2); // .../webroot
        $targetDir = $baseDir . '/' . $fileType;
        $this->storage->ensureDir($targetDir);
        $storedName = $ext !== '' ? ($checksum . '.' . $ext) : $checksum;
        $targetPath = $targetDir . '/' . $storedName;

        if (!is_file($targetPath)) {
            if (!@copy($srcPath, $targetPath)) {
                throw new \RuntimeException('Failed to store uploaded file at ' . $targetPath);
            }
            @chmod($targetPath, 0644);
        }

        // Detect MIME type from the stored file.
        $mime = '';
        $fi = new \finfo(FILEINFO_MIME_TYPE);
        $detected = @$fi->file($targetPath);
        if (is_string($detected) && $detected !== '') {
            $mime = $detected;
        }

        // Delegate probe + UPDATE to UIC (fills in stub asset row written by W1).
        $uicResult       = $this->uic->ingestComplete($assetId, $targetPath, $storedName, $size, $mime, $fileType, $checksum);
        $durationSeconds = $uicResult['duration_seconds'];

        $thumbnailDone = false;
        if ($fileType === 'video') {
            $thumbPath = $baseDir . '/video/thumbnails/' . $checksum . '.png';
            $thumbnailDone = is_file($thumbPath);
        }

        $result = [
            'asset_id'         => $assetId,
            'file_name'        => $storedName,
            'file_type'        => $fileType,
            'size_bytes'       => $size,
            'mime_type'        => $mime,
            'checksum_sha256'  => $checksum,
            'duration_seconds' => $durationSeconds,
            'thumbnail_done'   => $thumbnailDone,
            'db_done'          => true,
        ];

        // Write marker before unlinking source so a retry can succeed if unlink fails.
        @file_put_contents($finalMarker, json_encode($result, JSON_UNESCAPED_SLASHES));
        // Clean up TUS source data (best-effort).
        @unlink($srcPath);

        return $result;
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
