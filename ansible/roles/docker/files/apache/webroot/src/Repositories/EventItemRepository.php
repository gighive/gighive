<?php declare(strict_types=1);
namespace Production\Api\Repositories;

use PDO;

final class EventItemRepository
{
    public function __construct(private PDO $pdo) {}

    public function findLink(int $eventId, int $assetId): ?int
    {
        $stmt = $this->pdo->prepare(
            'SELECT event_item_id FROM event_items WHERE event_id = :eid AND asset_id = :aid LIMIT 1'
        );
        $stmt->execute([':eid' => $eventId, ':aid' => $assetId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? (int)$row['event_item_id'] : null;
    }

    public function nextPosition(int $eventId): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COALESCE(MAX(position), 0) AS max_pos FROM event_items WHERE event_id = :eid'
        );
        $stmt->execute([':eid' => $eventId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return (int)($row['max_pos'] ?? 0) + 1;
    }

    public function ensureEventItem(int $eventId, int $assetId, string $itemType, string $label, ?int $position): int
    {
        $pos = $position ?? $this->nextPosition($eventId);
        $sql = 'INSERT INTO event_items (event_id, asset_id, item_type, label, position)'
             . ' VALUES (:eid, :aid, :itype, :label, :pos)'
             . ' ON DUPLICATE KEY UPDATE event_item_id = LAST_INSERT_ID(event_item_id)';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':eid'   => $eventId,
            ':aid'   => $assetId,
            ':itype' => $itemType !== '' ? $itemType : 'song',
            ':label' => $label,
            ':pos'   => $pos,
        ]);
        return (int)$this->pdo->lastInsertId();
    }
}
