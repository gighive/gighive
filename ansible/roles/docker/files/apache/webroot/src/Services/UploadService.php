<?php declare(strict_types=1);
namespace Production\Api\Services;

use Production\Api\Config\MediaTypes;
use Production\Api\Exceptions\DuplicateChecksumException;
use Production\Api\Infrastructure\FileStorage;
use Production\Api\Repositories\FileRepository;
use Production\Api\Validation\UploadValidator;
use PDO;


final class UploadService
{
    public function __construct(
        private PDO $pdo,
        private ?UploadValidator $validator = null,
        private ?FileStorage $storage = null,
        private ?FileRepository $files = null,
        private ?MediaProbeService $probe = null,
        private ?UnifiedIngestionCore $uic = null,
        private ?TextNormalizer $normalizer = null,
    ) {
        $this->validator  = $this->validator  ?? new UploadValidator();
        $this->storage    = $this->storage    ?? new FileStorage();
        $this->files      = $this->files      ?? new FileRepository($pdo);
        $this->probe      = $this->probe      ?? new MediaProbeService();
        $this->normalizer = $this->normalizer ?? new TextNormalizer();
        $this->uic        = $this->uic        ?? new UnifiedIngestionCore($pdo, $this->files, $this->probe, $this->normalizer);
    }

    /**
     * Handle a single file upload. Returns a map suitable for API JSON response.
     * @param array $files Typically $_FILES
     * @param array $post  Typically $_POST (may include session_id, title, etc.)
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
        $orgName   = trim((string)($post['org_name'] ?? 'Band')); // default for blue_green
        $eventType = trim((string)($post['event_type'] ?? 'band'));
        $label     = trim((string)($post['label'] ?? ''));
        $participants = trim((string)($post['participants'] ?? ''));
        $keywords   = trim((string)($post['keywords'] ?? ''));
        $location  = trim((string)($post['location'] ?? ''));
        $rating    = trim((string)($post['rating'] ?? ''));
        $notes     = trim((string)($post['notes'] ?? ''));

        // Validate required fields
        if ($label === '') {
            throw new \InvalidArgumentException('Label is required');
        }

        // Ensure a session exists (by date + org_name); create if not present
        $sessionId = $this->uic->ensureSession($eventDate, $orgName, $eventType, $location, $rating, $notes, $keywords);

        // Compute next per-session sequence
        $seq = $this->files->nextSequence($sessionId);

        // Canonical filename: {orgslug}{YYYYMMDD}_{seqPadded}_{labelslug}.{ext}
        $orgSlug   = $this->slugify($orgName);
        $ymd       = str_replace('-', '', $eventDate);
        $padWidthEnv = getenv('FILENAME_SEQ_PAD');
        $padWidth = is_string($padWidthEnv) && ctype_digit($padWidthEnv) ? max(1, min(9, (int)$padWidthEnv)) : 5;
        $seqPadded = str_pad((string)$seq, $padWidth, '0', STR_PAD_LEFT);
        $labelSlug = $label !== '' ? $this->slugify($label) : 'media';
        $finalName = sprintf('%s%s_%s_%s.%s', $orgSlug, $ymd, $seqPadded, $labelSlug, $ext ?: 'bin');

        $baseDir  = dirname(__DIR__, 2); // .../webroot (app web root directory)
        $targetDir = $baseDir . '/' . $fileType; // /audio or /video under webroot
        $this->storage->ensureDir($targetDir);
        $targetPath = $this->uniquePath($targetDir, $finalName);

        // Compute checksum (before move for reliability)
        $checksum = @hash_file('sha256', $tmpPath) ?: null;

        // Store on disk as {sha256}.{ext} when possible, but keep canonical name in DB.
        if ($checksum !== null) {
            $checksumNorm = strtolower(trim($checksum));
            if (preg_match('/^[0-9a-f]{64}$/', $checksumNorm) === 1) {
                $storedName = $ext !== '' ? ($checksumNorm . '.' . $ext) : $checksumNorm;
                $targetPath = $this->uniquePath($targetDir, $storedName);
                $checksum = $checksumNorm;
            }
        }

        // Server policy: reject duplicate uploads by checksum_sha256 (server-wide).
        // Do this before moving the file so we don't persist duplicate bytes.
        if (is_string($checksum) && preg_match('/^[0-9a-f]{64}$/', $checksum) === 1) {
            $existing = $this->files->findByChecksum($checksum);
            if ($existing && isset($existing['file_id'])) {
                throw new DuplicateChecksumException((int)$existing['file_id'], (string)$checksum);
            }
        }

        // Move the file
        $this->storage->moveUploadedFile($tmpPath, $targetPath);

        // Optional: probe duration via ffprobe if available
        $durationSeconds = $this->probe->probeDuration($targetPath);

        if ($fileType === 'video' && is_string($checksum) && preg_match('/^[0-9a-f]{64}$/', $checksum) === 1) {
            $this->probe->generateVideoThumbnail($targetPath, $checksum, $durationSeconds);
        }

        $mediaInfo = $this->probe->probeMediaInfo($targetPath);
        $mediaInfoTool = $mediaInfo !== null ? $this->probe->ffprobeToolString() : null;
        $mediaCreatedAt = $this->probe->probeMediaCreatedAt($mediaInfo);

        $deleteToken = null;
        $createdNew = true;

        // Persist metadata (with session linkage and seq)
        try {
            $id = $this->files->create([
                'file_name'       => basename($targetPath),
                'source_relpath'  => $finalName,
                'file_type'       => $fileType,
                'session_id'      => $sessionId,
                'seq'             => $seq,
                'duration_seconds'=> $durationSeconds,
                'media_info'      => $mediaInfo,
                'media_info_tool' => $mediaInfoTool,
                'mime_type'       => $mime ?: null,
                'size_bytes'      => $size ?: null,
                'checksum_sha256' => $checksum,
                'media_created_at'=> $mediaCreatedAt,
            ]);
        } catch (\PDOException $e) {
            if (!is_string($checksum) || preg_match('/^[0-9a-f]{64}$/', $checksum) !== 1 || !$this->isDuplicateChecksumException($e)) {
                throw $e;
            }

            $existing = $this->files->findByChecksum($checksum);
            if (!$existing || !isset($existing['file_id'])) {
                throw $e;
            }

            $existingFileName = (string)($existing['file_name'] ?? '');
            if ($existingFileName !== '' && basename($targetPath) !== $existingFileName && is_file($targetPath)) {
                @unlink($targetPath);
            }

            // Server policy: reject duplicate uploads by checksum.
            // We already cleaned up any newly-stored file bytes above.
            throw new DuplicateChecksumException((int)$existing['file_id'], (string)$checksum);
        }

        if ($createdNew) {
            $deleteToken = bin2hex(random_bytes(32));
            $hash = hash('sha256', $deleteToken);
            $stored = $this->files->setDeleteTokenHashIfNull($id, $hash);
            if (!$stored) {
                $deleteToken = null;
            }
        }

        // Link to a label (song or wedding table name)
        $songType = ($eventType === 'wedding') ? 'event_label' : 'song';
        $songId = $this->uic->ensureSong($label, $songType);
        $this->uic->ensureSessionSong($sessionId, $songId);
        $this->uic->linkSongFile($songId, $id);

        // Optional: participants mapping (now gated by env to avoid session-wide pollution)
        // Set UPLOAD_PARTICIPANTS_MODE to 'session' to enable previous behavior.
        // Future modes could include 'file' or 'song' after schema changes.
        $participantsMode = getenv('UPLOAD_PARTICIPANTS_MODE') ?: 'none'; // default: do nothing
        if ($participantsMode === 'session' && $participants !== '') {
            $this->attachParticipants($sessionId, $participants);
        }

        $resp = [
            'id'              => $id,
            'file_name'       => $finalName,
            'file_type'       => $fileType,
            'mime_type'       => $mime,
            'size_bytes'      => $size,
            'checksum_sha256' => $checksum,
            'session_id'      => $sessionId,
            'event_date'      => $eventDate,
            'org_name'        => $orgName,
            'event_type'      => $eventType,
            'seq'             => $seq,
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
            return str_contains($msg, 'files_uq_files_checksum_sha256') || str_contains($msg, 'checksum_sha256');
        }
        $msg = $e->getMessage();
        return str_contains($msg, 'SQLSTATE[23000]') && str_contains($msg, '1062') && (str_contains($msg, 'checksum_sha256') || str_contains($msg, 'files_uq_files_checksum_sha256'));
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
            throw new \RuntimeException('Upload not found or not finished yet');
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

        // The manifest row MUST already exist — this is not a new-upload path.
        $existing = $this->files->findByChecksum($checksum);
        if (!$existing || !isset($existing['file_id'])) {
            throw new \RuntimeException(
                'No manifest row found for checksum ' . $checksum . '; Step 1 must complete before Step 2'
            );
        }
        $fileId   = (int)$existing['file_id'];
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
            $ext = strtolower(pathinfo((string)($existing['file_name'] ?? ''), PATHINFO_EXTENSION));
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

        // Delegate probe + UPDATE to UIC (upsert-aware: fills in stub row written by W1).
        $uicResult       = $this->uic->ingestComplete($fileId, $targetPath, $storedName, $size, $mime, $fileType, $checksum);
        $durationSeconds = $uicResult['duration_seconds'];

        $thumbnailDone = false;
        if ($fileType === 'video') {
            $thumbPath = $baseDir . '/video/thumbnails/' . $checksum . '.png';
            $thumbnailDone = is_file($thumbPath);
        }

        $result = [
            'file_id'          => $fileId,
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

    private function attachParticipants(int $sessionId, string $csv): void
    {
        $names = array_filter(array_map('trim', explode(',', $csv)));
        if (!$names) return;
        foreach ($names as $name) {
            // ensure musician row
            $stmt = $this->pdo->prepare('SELECT musician_id FROM musicians WHERE name = :n LIMIT 1');
            $stmt->execute([':n' => $name]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $mid = $row['musician_id'] ?? null;
            if (!$mid) {
                $this->pdo->prepare('INSERT INTO musicians (name) VALUES (:n)')->execute([':n' => $name]);
                $mid = (int)$this->pdo->lastInsertId();
            } else {
                $mid = (int)$mid;
            }
            // link to session
            $sql = 'INSERT INTO session_musicians (session_id, musician_id) VALUES (:s, :m)'
                 . ' ON DUPLICATE KEY UPDATE role = role';
            $this->pdo->prepare($sql)->execute([':s' => $sessionId, ':m' => $mid]);
        }
    }
}
