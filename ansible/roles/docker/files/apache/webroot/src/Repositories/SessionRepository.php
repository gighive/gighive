<?php declare(strict_types=1);
namespace Production\Api\Repositories;

use PDO;

final class SessionRepository
{
    public function __construct(private PDO $pdo) {}

    public function validateMediaListFilters(array $filters): array
    {
        [, , $errors, $warnings] = $this->buildMediaListFilters($filters);
        return [$errors, $warnings];
    }

    private function buildMediaListFilters(array $filters): array
    {
        $where = ['f.file_id IS NOT NULL'];
        $params = [];
        $errors = [];
        $warnings = [];

        $maxTermsPerField = 10;

        $map = [
            'date' => ['sql' => 'LOWER(sesh.date) LIKE LOWER(%s)', 'param_base' => 'date'],
            'org_name' => ['sql' => 'LOWER(sesh.org_name) LIKE LOWER(%s)', 'param_base' => 'org_name'],
            'rating' => ['sql' => 'LOWER(sesh.rating) LIKE LOWER(%s)', 'param_base' => 'rating'],
            'keywords' => ['sql' => 'LOWER(sesh.keywords) LIKE LOWER(%s)', 'param_base' => 'keywords'],
            'location' => ['sql' => 'LOWER(sesh.location) LIKE LOWER(%s)', 'param_base' => 'location'],
            'summary' => ['sql' => 'LOWER(sesh.summary) LIKE LOWER(%s)', 'param_base' => 'summary'],
            'crew' => ['sql' => 'LOWER(m.name) LIKE LOWER(%s)', 'param_base' => 'crew'],
            'song_title' => ['sql' => 'LOWER(s.title) LIKE LOWER(%s)', 'param_base' => 'song_title'],
            'file_type' => ['sql' => 'LOWER(f.file_type) LIKE LOWER(%s)', 'param_base' => 'file_type'],
            'file_name' => ['sql' => 'LOWER(f.file_name) LIKE LOWER(%s)', 'param_base' => 'file_name'],
            'source_relpath' => ['sql' => 'LOWER(f.source_relpath) LIKE LOWER(%s)', 'param_base' => 'source_relpath'],
            'duration_seconds' => ['sql' => 'f.duration_seconds LIKE %s', 'param_base' => 'duration_seconds'],
            'media_info' => ['sql' => 'LOWER(f.media_info) LIKE LOWER(%s)', 'param_base' => 'media_info'],
        ];

        $makeParam = static function (string $base, int $orIdx, int $andIdx): string {
            return ':' . $base . '_' . $orIdx . '_' . $andIdx;
        };

        $hasInvalidEmptyTerm = static function (string $raw): bool {
            $raw = trim($raw);
            if ($raw === '') {
                return false;
            }
            if (str_contains($raw, '||') || str_contains($raw, '&&')) {
                return true;
            }
            if (str_starts_with($raw, '|') || str_ends_with($raw, '|')) {
                return true;
            }
            if (str_starts_with($raw, '&') || str_ends_with($raw, '&')) {
                return true;
            }
            if (str_contains($raw, '|&') || str_contains($raw, '&|')) {
                return true;
            }
            return false;
        };

        foreach ($map as $key => $cfg) {
            $raw = $filters[$key] ?? '';
            if (!is_string($raw)) {
                continue;
            }
            $raw = trim($raw);
            if ($raw === '') {
                continue;
            }

            if ($hasInvalidEmptyTerm($raw)) {
                $errors[] = 'Search for column "' . $key . '" contains empty terms around "|" or "&". Please remove extra operators.';
                continue;
            }

            $orParts = array_map('trim', explode('|', $raw));
            $orParts = array_values(array_filter($orParts, static fn($x) => $x !== ''));

            if (empty($orParts)) {
                $errors[] = 'Search for column "' . $key . '" is invalid.';
                continue;
            }

            $totalTerms = 0;
            $orExprs = [];
            foreach ($orParts as $orIdx => $orRaw) {
                $andParts = array_map('trim', explode('&', $orRaw));
                $andParts = array_values(array_filter($andParts, static fn($x) => $x !== ''));
                if (empty($andParts)) {
                    $errors[] = 'Search for column "' . $key . '" is invalid.';
                    $orExprs = [];
                    break;
                }

                $andExprs = [];
                foreach ($andParts as $andIdx => $term) {
                    $negated = false;
                    $term = trim($term);
                    if ($term === '!') {
                        $errors[] = 'Search for column "' . $key . '" contains an invalid NOT term. Use !term (e.g. !bob).';
                        $orExprs = [];
                        break 2;
                    }
                    if (str_starts_with($term, '!!')) {
                        $errors[] = 'Search for column "' . $key . '" contains an invalid NOT term. Use !term (e.g. !bob).';
                        $orExprs = [];
                        break 2;
                    }
                    if (str_starts_with($term, '!')) {
                        $negated = true;
                        $term = trim(substr($term, 1));
                        if ($term === '') {
                            $errors[] = 'Search for column "' . $key . '" contains an invalid NOT term. Use !term (e.g. !bob).';
                            $orExprs = [];
                            break 2;
                        }
                    }

                    $totalTerms++;
                    $param = $makeParam((string)$cfg['param_base'], $orIdx, $andIdx);
                    $andExpr = sprintf((string)$cfg['sql'], $param);
                    if ($negated) {
                        $andExpr = str_replace(' LIKE ', ' NOT LIKE ', $andExpr);
                    }
                    $andExprs[] = $andExpr;
                    $params[$param] = '%' . $term . '%';
                }
                $orExprs[] = '(' . implode(' AND ', $andExprs) . ')';
            }

            if (!empty($orExprs) && $totalTerms > $maxTermsPerField) {
                $errors[] = 'Search for column "' . $key . '" has too many terms (max ' . (string)$maxTermsPerField . ').';
                continue;
            }

            if (!empty($orExprs)) {
                $where[] = '(' . implode(' OR ', $orExprs) . ')';
            }
        }

        if (!empty($errors)) {
            return ['WHERE 1=0', [], $errors, $warnings];
        }

        $whereSql = '';
        if (!empty($where)) {
            $whereSql = 'WHERE ' . implode(' AND ', $where);
        }

        return [$whereSql, $params, $errors, $warnings];
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
  JOIN song_files sf
    ON s.song_id = sf.song_id
  JOIN files f
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
JOIN song_files sf
  ON s.song_id = sf.song_id
JOIN files f
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
JOIN song_files sf
  ON s.song_id = sf.song_id
JOIN files f
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
JOIN song_files sf
  ON s.song_id = sf.song_id
JOIN files f
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
