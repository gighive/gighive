<?php declare(strict_types=1);
namespace Production\Api\Services;

use Production\Api\Repositories\FileRepository;
use PDO;

/**
 * Unified Ingestion Core (UIC).
 *
 * Single canonical write path for all media ingestion:
 *
 *   ingestStub()     — stub INSERT for manifest worker (W1); file not yet on disk.
 *   ingestComplete() — probe + UPDATE for TUS finalize (S3); file now on disk.
 *
 * Upsert logic keyed on checksum_sha256:
 *   No existing row        → INSERT   (ingestStub)
 *   Existing stub row      → UPDATE with probe metadata (ingestComplete)
 *   Existing complete row  → return existing record, no second write (ingestComplete)
 *
 * Also provides shared session/song linkage helpers previously duplicated between
 * UploadService (S1/S3) and import_manifest_lib.php (W1).
 */
final class UnifiedIngestionCore
{
    public function __construct(
        private PDO $pdo,
        private ?FileRepository $files = null,
        private ?MediaProbeService $probe = null,
    ) {
        $this->files = $this->files ?? new FileRepository($pdo);
        $this->probe = $this->probe ?? new MediaProbeService();
    }

    /**
     * Stub INSERT for the manifest worker (W1).
     *
     * File is not yet on disk — inserts a row without probe metadata.
     * If the checksum already exists the call is idempotent (returns 'skipped').
     * seq is computed automatically from the session when not supplied.
     *
     * @param array $params {
     *   checksum_sha256: string,
     *   file_type: 'audio'|'video',
     *   file_name: string,
     *   source_relpath?: string,
     *   session_id?: int,
     *   size_bytes?: int,
     * }
     * @return array{status: 'inserted'|'skipped', file_id: int, checksum_sha256: string}
     */
    public function ingestStub(array $params): array
    {
        $checksum   = strtolower(trim((string)($params['checksum_sha256'] ?? '')));
        $fileType   = (string)($params['file_type'] ?? '');
        $fileName   = (string)($params['file_name'] ?? '');
        $srcRelpath = (isset($params['source_relpath']) && $params['source_relpath'] !== '')
            ? (string)$params['source_relpath'] : null;
        $sessionId  = isset($params['session_id']) ? (int)$params['session_id'] : null;
        $sizeBytes  = (isset($params['size_bytes']) && $params['size_bytes'] !== null)
            ? (int)$params['size_bytes'] : null;

        if ($checksum === '' || !preg_match('/^[0-9a-f]{64}$/', $checksum)) {
            throw new \InvalidArgumentException('Invalid checksum_sha256');
        }
        if (!in_array($fileType, ['audio', 'video'], true)) {
            throw new \InvalidArgumentException('Invalid file_type: ' . $fileType);
        }

        $existing = $this->files->findByChecksum($checksum);
        if ($existing && isset($existing['file_id'])) {
            return [
                'status'          => 'skipped',
                'file_id'         => (int)$existing['file_id'],
                'checksum_sha256' => $checksum,
            ];
        }

        $seq = null;
        if ($sessionId !== null) {
            $seq = $this->files->nextSequence($sessionId);
        }

        $fileId = $this->files->create([
            'file_name'        => $fileName,
            'source_relpath'   => $srcRelpath,
            'file_type'        => $fileType,
            'session_id'       => $sessionId,
            'seq'              => $seq,
            'size_bytes'       => $sizeBytes,
            'checksum_sha256'  => $checksum,
            'duration_seconds' => null,
            'media_info'       => null,
            'media_info_tool'  => null,
            'mime_type'        => null,
        ]);

        return [
            'status'          => 'inserted',
            'file_id'         => $fileId,
            'checksum_sha256' => $checksum,
        ];
    }

    /**
     * Probe + UPDATE for TUS finalize (S3).
     *
     * File is now on disk. Probes it and fills in metadata on the existing stub row.
     * The file must already exist on disk at $filePath before this is called.
     *
     * @param int    $fileId          Existing files.file_id (written by W1 ingestStub).
     * @param string $filePath        Full filesystem path to the stored file (for probing).
     * @param string $storedFileName  Value to write into files.file_name (e.g. "{checksum}.{ext}").
     * @param int    $sizeBytes       Actual file size in bytes.
     * @param string $mimeType        Detected MIME type; empty string treated as NULL.
     * @param string $fileType        'audio' or 'video' (determines thumbnail generation).
     * @param string $checksum        SHA-256 hex string (used for video thumbnail file naming).
     * @return array{status: 'updated', file_id: int, duration_seconds: ?int, media_info: ?string, media_info_tool: ?string}
     */
    public function ingestComplete(
        int $fileId,
        string $filePath,
        string $storedFileName,
        int $sizeBytes,
        string $mimeType,
        string $fileType,
        string $checksum
    ): array {
        $durationSeconds = $this->probe->probeDuration($filePath);
        if ($fileType === 'video') {
            $this->probe->generateVideoThumbnail($filePath, $checksum, $durationSeconds);
        }
        $mediaInfo     = $this->probe->probeMediaInfo($filePath);
        $mediaInfoTool = $mediaInfo !== null ? $this->probe->ffprobeToolString() : null;

        $this->files->updateProbeMetadata(
            $fileId,
            $storedFileName,
            $sizeBytes,
            $mimeType !== '' ? $mimeType : null,
            $durationSeconds,
            $mediaInfo,
            $mediaInfoTool,
        );

        return [
            'status'           => 'updated',
            'file_id'          => $fileId,
            'duration_seconds' => $durationSeconds,
            'media_info'       => $mediaInfo,
            'media_info_tool'  => $mediaInfoTool,
        ];
    }

    /**
     * Find or create a session row keyed on (date, org_name).
     * When the session already exists and $keywords is non-empty, updates keywords.
     */
    public function ensureSession(
        string $date,
        string $orgName,
        string $eventType,
        string $location = '',
        string $rating   = '',
        string $notes    = '',
        string $keywords = ''
    ): int {
        $stmt = $this->pdo->prepare(
            'SELECT session_id FROM sessions WHERE date = :d AND org_name = :o LIMIT 1'
        );
        $stmt->execute([':d' => $date, ':o' => $orgName]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row && isset($row['session_id'])) {
            $sid = (int)$row['session_id'];
            if ($keywords !== '') {
                $this->pdo->prepare('UPDATE sessions SET keywords = :kw WHERE session_id = :sid')
                    ->execute([':kw' => $keywords, ':sid' => $sid]);
            }
            return $sid;
        }
        $sql = 'INSERT INTO sessions (title, date, location, summary, rating, event_type, org_name, keywords)'
             . ' VALUES (:title, :date, :location, :summary, :rating, :etype, :org, :kw)';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':title'    => $orgName . ' ' . $date,
            ':date'     => $date,
            ':location' => $location  !== '' ? $location  : null,
            ':summary'  => $notes     !== '' ? $notes     : null,
            ':rating'   => $rating    !== '' ? $rating    : null,
            ':etype'    => $eventType !== '' ? $eventType : null,
            ':org'      => $orgName,
            ':kw'       => $keywords  !== '' ? $keywords  : null,
        ]);
        return (int)$this->pdo->lastInsertId();
    }

    /**
     * Find or create a song row keyed on (title, type).
     */
    public function ensureSong(string $title, string $type): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT song_id FROM songs WHERE title = :t AND type = :ty LIMIT 1'
        );
        $stmt->execute([':t' => $title, ':ty' => $type]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row && isset($row['song_id'])) {
            return (int)$row['song_id'];
        }
        $stmt = $this->pdo->prepare('INSERT INTO songs (title, type) VALUES (:t, :ty)');
        $stmt->execute([':t' => $title, ':ty' => $type]);
        return (int)$this->pdo->lastInsertId();
    }

    /**
     * Link a session to a song (INSERT IGNORE semantics via ON DUPLICATE KEY).
     */
    public function ensureSessionSong(int $sessionId, int $songId): void
    {
        $this->pdo->prepare(
            'INSERT INTO session_songs (session_id, song_id) VALUES (:s, :g)'
            . ' ON DUPLICATE KEY UPDATE position = position'
        )->execute([':s' => $sessionId, ':g' => $songId]);
    }

    /**
     * Link a song to a file (INSERT IGNORE semantics via ON DUPLICATE KEY).
     */
    public function linkSongFile(int $songId, int $fileId): void
    {
        $this->pdo->prepare(
            'INSERT INTO song_files (song_id, file_id) VALUES (:g, :f)'
            . ' ON DUPLICATE KEY UPDATE file_id = file_id'
        )->execute([':g' => $songId, ':f' => $fileId]);
    }
}
