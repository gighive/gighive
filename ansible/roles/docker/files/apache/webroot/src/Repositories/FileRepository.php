<?php declare(strict_types=1);
namespace Production\Api\Repositories;

use PDO;

final class FileRepository
{
    public function __construct(private PDO $pdo) {}

    public function create(array $data): int
    {
        // files(file_id PK AI, file_name, file_type, session_id?, seq?, duration_seconds?, mime_type?, size_bytes?, checksum_sha256?)
        $sql = 'INSERT INTO files (file_name, source_relpath, file_type, session_id, seq, duration_seconds, media_info, media_info_tool, mime_type, size_bytes, checksum_sha256)'
             . ' VALUES (:file_name, :source_relpath, :file_type, :session_id, :seq, :duration_seconds, :media_info, :media_info_tool, :mime_type, :size_bytes, :checksum)';
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
