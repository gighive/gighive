<?php declare(strict_types=1);
namespace Production\Api\Repositories;

use PDO;

final class SessionRepository
{
    public function __construct(private PDO $pdo) {}

    /**
     * Fetch rows for the media listing.
     *
     * ⬇️ Paste your current SELECT from MediaController *here*, unchanged,
     * and keep any bind parameters in this method. Example shown for shape.
     */
    public function fetchMediaList(): array
    {
        // TODO: Replace this example with your actual SELECT.
        $sql = <<<SQL
SELECT
    js.date AS jam_date,
    js.rating AS rating,
    js.keywords AS keywords,
    js.duration AS duration,
    js.location AS location,
    js.summary AS summary,
    GROUP_CONCAT(DISTINCT m.name ORDER BY m.name SEPARATOR ', ') AS crew,
    s.title AS song_name,
    f.file_type AS file_type,
    f.file_name AS file_name
FROM sessions js
JOIN session_songs jss ON js.session_id = jss.session_id
JOIN songs s ON jss.song_id = s.song_id
LEFT JOIN song_files sf ON s.song_id = sf.song_id
LEFT JOIN files f ON sf.file_id = f.file_id
LEFT JOIN session_musicians jsm ON js.session_id = jsm.session_id
LEFT JOIN musicians m ON jsm.musician_id = m.musician_id
GROUP BY js.session_id, s.song_id, f.file_id
ORDER BY js.date DESC
SQL;

        $stmt = $this->pdo->query($sql);
        return $stmt->fetchAll();
    }
}

