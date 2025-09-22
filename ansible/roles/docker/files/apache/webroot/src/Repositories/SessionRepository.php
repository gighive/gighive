<?php declare(strict_types=1);
namespace Production\Api\Repositories;

use PDO;

final class SessionRepository
{
    public function __construct(private PDO $pdo) {}

    /**
     * Fetch rows for the media listing, aligned with the standardized schema.
     */
    public function fetchMediaList(): array
    {
        $sql = <<<SQL
SELECT
    sesh.date                             AS date,
    sesh.org_name                         AS org_name,
    sesh.rating                           AS rating,
    sesh.keywords                         AS keywords,
    f.duration_seconds                    AS duration_seconds,
    sesh.location                         AS location,
    sesh.summary                          AS summary,
    GROUP_CONCAT(DISTINCT m.name ORDER BY m.name SEPARATOR ', ') AS crew,
    s.title                               AS song_title,
    f.file_type                           AS file_type,
    f.file_name                           AS file_name
FROM sessions sesh
JOIN session_songs seshsongs
  ON sesh.session_id = seshsongs.session_id
JOIN songs s
  ON seshsongs.song_id = s.song_id
LEFT JOIN song_files sf
  ON s.song_id = sf.song_id
LEFT JOIN files f
  ON sf.file_id = f.file_id
LEFT JOIN session_musicians seshmus
  ON sesh.session_id = seshmus.session_id
LEFT JOIN musicians m
  ON seshmus.musician_id = m.musician_id
GROUP BY sesh.session_id, s.song_id, f.file_id
ORDER BY sesh.date DESC
SQL;

        $stmt = $this->pdo->query($sql);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
