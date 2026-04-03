<?php declare(strict_types=1);
namespace Production\Api\Repositories;

use PDO;

final class FileRepository
{
    public function __construct(private PDO $pdo) {}

    public function create(array $data): int
    {
        // files(file_id PK AI, file_name, file_type, session_id?, seq?, duration_seconds?, mime_type?, size_bytes?, checksum_sha256?)
        $sql = 'INSERT INTO files (file_name, source_relpath, file_type, session_id, seq, duration_seconds, media_info, media_info_tool, mime_type, size_bytes, checksum_sha256, media_created_at)'
             . ' VALUES (:file_name, :source_relpath, :file_type, :session_id, :seq, :duration_seconds, :media_info, :media_info_tool, :mime_type, :size_bytes, :checksum, :media_created_at)';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':file_name' => $data['file_name'] ?? '',
            ':source_relpath' => $data['source_relpath'] ?? null,
            ':file_type' => $data['file_type'] ?? '',
            ':session_id' => $data['session_id'] ?? null,
            ':seq' => $data['seq'] ?? null,
            ':duration_seconds' => $data['duration_seconds'] ?? null,
            ':media_info' => $data['media_info'] ?? null,
            ':media_info_tool' => $data['media_info_tool'] ?? null,
            ':mime_type' => $data['mime_type'] ?? null,
            ':size_bytes' => $data['size_bytes'] ?? null,
            ':checksum' => $data['checksum_sha256'] ?? null,
            ':media_created_at' => $data['media_created_at'] ?? null,
        ]);
        return (int)$this->pdo->lastInsertId();
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM files WHERE file_id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function findByChecksum(string $sha256): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM files WHERE checksum_sha256 = :c LIMIT 1');
        $stmt->execute([':c' => $sha256]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function getDeleteTokenHashById(int $fileId): ?string
    {
        $stmt = $this->pdo->prepare('SELECT delete_token_hash FROM files WHERE file_id = :id');
        $stmt->execute([':id' => $fileId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        $v = $row['delete_token_hash'] ?? null;
        if ($v === null) {
            return null;
        }
        $s = trim((string)$v);
        return $s !== '' ? $s : null;
    }

    public function setDeleteTokenHashIfNull(int $fileId, string $hash): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE files SET delete_token_hash = :h WHERE file_id = :id AND delete_token_hash IS NULL'
        );
        $stmt->execute([':h' => $hash, ':id' => $fileId]);
        return $stmt->rowCount() === 1;
    }

    /**
     * Fill in probe metadata on an existing stub row (S3 / ingestComplete path).
     * Writes file_name, size_bytes, mime_type, duration_seconds, media_info, media_info_tool.
     */
    public function updateProbeMetadata(
        int $fileId,
        string $fileName,
        int $sizeBytes,
        ?string $mimeType,
        ?int $durationSeconds,
        ?string $mediaInfo,
        ?string $mediaInfoTool,
        ?string $mediaCreatedAt = null
    ): void {
        $sql = 'UPDATE files SET'
             . '  file_name        = :file_name,'
             . '  size_bytes       = :size_bytes,'
             . '  mime_type        = :mime_type,'
             . '  duration_seconds = :duration_seconds,'
             . '  media_info       = :media_info,'
             . '  media_info_tool  = :media_info_tool,'
             . '  media_created_at = :media_created_at'
             . ' WHERE file_id = :file_id';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':file_name'        => $fileName,
            ':size_bytes'       => $sizeBytes,
            ':mime_type'        => $mimeType,
            ':duration_seconds' => $durationSeconds,
            ':media_info'       => $mediaInfo,
            ':media_info_tool'  => $mediaInfoTool,
            ':media_created_at' => $mediaCreatedAt,
            ':file_id'          => $fileId,
        ]);
    }

    /**
     * Compute the next per-session sequence number (1-based).
     */
    public function nextSequence(int $sessionId): int
    {
        $stmt = $this->pdo->prepare('SELECT COALESCE(MAX(seq), 0) AS max_seq FROM files WHERE session_id = :sid');
        $stmt->execute([':sid' => $sessionId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $max = (int)($row['max_seq'] ?? 0);
        return $max + 1;
    }
}
