<?php declare(strict_types=1);
namespace Production\Api\Repositories;

use PDO;

final class SessionRepository
{
    public function __construct(private PDO $pdo) {}

    private function buildMediaListFilters(array $filters): array
    {
        $where = [];
        $params = [];

        $map = [
            'date' => ['sql' => 'LOWER(sesh.date) LIKE LOWER(:date)', 'param' => ':date'],
            'org_name' => ['sql' => 'LOWER(sesh.org_name) LIKE LOWER(:org_name)', 'param' => ':org_name'],
            'rating' => ['sql' => 'LOWER(sesh.rating) LIKE LOWER(:rating)', 'param' => ':rating'],
            'keywords' => ['sql' => 'LOWER(sesh.keywords) LIKE LOWER(:keywords)', 'param' => ':keywords'],
            'location' => ['sql' => 'LOWER(sesh.location) LIKE LOWER(:location)', 'param' => ':location'],
            'summary' => ['sql' => 'LOWER(sesh.summary) LIKE LOWER(:summary)', 'param' => ':summary'],
            'crew' => ['sql' => 'LOWER(m.name) LIKE LOWER(:crew)', 'param' => ':crew'],
            'song_title' => ['sql' => 'LOWER(s.title) LIKE LOWER(:song_title)', 'param' => ':song_title'],
            'file_type' => ['sql' => 'LOWER(f.file_type) LIKE LOWER(:file_type)', 'param' => ':file_type'],
            'file_name' => ['sql' => 'LOWER(f.file_name) LIKE LOWER(:file_name)', 'param' => ':file_name'],
            'source_relpath' => ['sql' => 'LOWER(f.source_relpath) LIKE LOWER(:source_relpath)', 'param' => ':source_relpath'],
            'duration_seconds' => ['sql' => 'f.duration_seconds LIKE :duration_seconds', 'param' => ':duration_seconds'],
            'media_info' => ['sql' => 'LOWER(f.media_info) LIKE LOWER(:media_info)', 'param' => ':media_info'],
        ];

        foreach ($map as $key => $cfg) {
            $raw = $filters[$key] ?? '';
            if (!is_string($raw)) {
                continue;
            }
            $raw = trim($raw);
            if ($raw === '') {
                continue;
            }
            $where[] = $cfg['sql'];
            $params[$cfg['param']] = '%' . $raw . '%';
        }

        $whereSql = '';
        if (!empty($where)) {
            $whereSql = 'WHERE ' . implode(' AND ', $where);
        }

        return [$whereSql, $params];
    }

    public function countMediaListRows(array $filters = []): int
    {
        [$whereSql, $params] = $this->buildMediaListFilters($filters);

        $sql = <<<SQL
SELECT COUNT(*) AS row_count
FROM (
  SELECT
      sesh.session_id,
      s.song_id,
      f.file_id
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
  $whereSql
  GROUP BY sesh.session_id, s.song_id, f.file_id
) t
SQL;

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v, PDO::PARAM_STR);
        }
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return (int)($row['row_count'] ?? 0);
    }

    public function fetchMediaListPage(array $filters, int $limit, int $offset): array
    {
        [$whereSql, $params] = $this->buildMediaListFilters($filters);

        $sql = <<<SQL
SELECT
    f.file_id                           AS id,
    sesh.date                             AS date,
    sesh.org_name                         AS org_name,
    sesh.rating                           AS rating,
    sesh.keywords                         AS keywords,
    f.duration_seconds                    AS duration_seconds,
    f.media_info                          AS media_info,
    f.checksum_sha256                     AS checksum_sha256,
    sesh.location                         AS location,
    sesh.summary                          AS summary,
    GROUP_CONCAT(DISTINCT m.name ORDER BY m.name SEPARATOR ', ') AS crew,
    s.title                               AS song_title,
    f.file_type                           AS file_type,
    f.file_name                           AS file_name,
    f.source_relpath                      AS source_relpath
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
$whereSql
GROUP BY sesh.session_id, s.song_id, f.file_id
ORDER BY sesh.date DESC
LIMIT :limit OFFSET :offset
SQL;

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v, PDO::PARAM_STR);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function fetchMediaListFiltered(array $filters): array
    {
        [$whereSql, $params] = $this->buildMediaListFilters($filters);

        $sql = <<<SQL
SELECT
    f.file_id                           AS id,
    sesh.date                             AS date,
    sesh.org_name                         AS org_name,
    sesh.rating                           AS rating,
    sesh.keywords                         AS keywords,
    f.duration_seconds                    AS duration_seconds,
    f.media_info                          AS media_info,
    f.checksum_sha256                     AS checksum_sha256,
    sesh.location                         AS location,
    sesh.summary                          AS summary,
    GROUP_CONCAT(DISTINCT m.name ORDER BY m.name SEPARATOR ', ') AS crew,
    s.title                               AS song_title,
    f.file_type                           AS file_type,
    f.file_name                           AS file_name,
    f.source_relpath                      AS source_relpath
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
$whereSql
GROUP BY sesh.session_id, s.song_id, f.file_id
ORDER BY sesh.date DESC
SQL;

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v, PDO::PARAM_STR);
        }
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Fetch rows for the media listing, aligned with the standardized schema.
     */
    public function fetchMediaList(): array
    {
        $sql = <<<SQL
SELECT
    f.file_id                           AS id,
    sesh.date                             AS date,
    sesh.org_name                         AS org_name,
    sesh.rating                           AS rating,
    sesh.keywords                         AS keywords,
    f.duration_seconds                    AS duration_seconds,
    f.media_info                          AS media_info,
    f.checksum_sha256                     AS checksum_sha256,
    sesh.location                         AS location,
    sesh.summary                          AS summary,
    GROUP_CONCAT(DISTINCT m.name ORDER BY m.name SEPARATOR ', ') AS crew,
    s.title                               AS song_title,
    f.file_type                           AS file_type,
    f.file_name                           AS file_name,
    f.source_relpath                      AS source_relpath
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
