<?php declare(strict_types=1);
namespace Production\Api\Services;

readonly class TokenValidationResult {
    public function __construct(
        public int    $tokenId,
        public int    $eventId,
        public string $eventDate,
        public string $orgName,
        public string $eventType,
    ) {}
}

class UploadTokenValidator {
    public function __construct(private \PDO $pdo) {}

    public function validate(string $rawToken): ?TokenValidationResult {
        $hash = hash('sha256', $rawToken);
        $stmt = $this->pdo->prepare(
            'SELECT t.token_id, e.event_id, e.event_date, e.org_name,
                    COALESCE(e.event_type, \'\') AS event_type
             FROM event_upload_tokens t
             JOIN events e ON e.event_id = t.event_id
             WHERE t.token_hash = ? AND t.is_active = 1 AND t.expires_at > NOW()'
        );
        $stmt->execute([$hash]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row) return null;
        return new TokenValidationResult(
            tokenId:   (int)$row['token_id'],
            eventId:   (int)$row['event_id'],
            eventDate: $row['event_date'],
            orgName:   $row['org_name'],
            eventType: $row['event_type'],
        );
    }
}
