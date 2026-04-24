<?php declare(strict_types=1);
namespace Production\Api\Repositories;

use PDO;

final class EventRepository
{
    public function __construct(private PDO $pdo) {}

    public function validateEventFilters(array $filters): array
    {
        [, , $errors, $warnings] = $this->buildEventFilters($filters, null);
        return [$errors, $warnings];
    }

    private function buildEventFilters(array $filters, ?int $eventId): array
    {
        $where    = [];
        $params   = [];
        $errors   = [];
        $warnings = [];

        if ($eventId !== null) {
            $where[]              = 'e.event_id = :__event_id';
            $params[':__event_id'] = $eventId;
        }

        $maxTermsPerField = 10;

        $map = [
            'date'             => ['sql' => 'LOWER(CAST(e.event_date AS CHAR)) LIKE LOWER(%s)', 'param_base' => 'date'],
            'org_name'         => ['sql' => 'LOWER(e.org_name) LIKE LOWER(%s)',                  'param_base' => 'org_name'],
            'rating'           => ['sql' => 'LOWER(CAST(e.rating AS CHAR)) LIKE LOWER(%s)',      'param_base' => 'rating'],
            'keywords'         => ['sql' => 'LOWER(e.keywords) LIKE LOWER(%s)',                  'param_base' => 'keywords'],
            'location'         => ['sql' => 'LOWER(e.location) LIKE LOWER(%s)',                  'param_base' => 'location'],
            'summary'          => ['sql' => 'LOWER(e.summary) LIKE LOWER(%s)',                   'param_base' => 'summary'],
            'crew'             => ['sql' => 'LOWER(p.name) LIKE LOWER(%s)',                      'param_base' => 'crew'],
            'item_label'       => ['sql' => 'LOWER(ei.label) LIKE LOWER(%s)',                    'param_base' => 'item_label'],
            'file_type'        => ['sql' => 'LOWER(a.file_type) LIKE LOWER(%s)',                 'param_base' => 'file_type'],
            'source_relpath'   => ['sql' => 'LOWER(a.source_relpath) LIKE LOWER(%s)',            'param_base' => 'source_relpath'],
            'duration_seconds' => ['sql' => 'a.duration_seconds LIKE %s',                        'param_base' => 'duration_seconds'],
            'media_info'       => ['sql' => 'LOWER(a.media_info) LIKE LOWER(%s)',                'param_base' => 'media_info'],
        ];

        $makeParam = static function (string $base, int $orIdx, int $andIdx): string {
            return ':' . $base . '_' . $orIdx . '_' . $andIdx;
        };

        $hasInvalidEmptyTerm = static function (string $raw): bool {
            $raw = trim($raw);
            if ($raw === '') { return false; }
            if (str_contains($raw, '||') || str_contains($raw, '&&')) { return true; }
            if (str_starts_with($raw, '|') || str_ends_with($raw, '|')) { return true; }
            if (str_starts_with($raw, '&') || str_ends_with($raw, '&')) { return true; }
            if (str_contains($raw, '|&') || str_contains($raw, '&|')) { return true; }
            return false;
        };

        foreach ($map as $key => $cfg) {
            $raw = $filters[$key] ?? '';
            if (!is_string($raw)) { continue; }
            $raw = trim($raw);
            if ($raw === '') { continue; }

            if ($hasInvalidEmptyTerm($raw)) {
                $errors[] = 'Search for column "' . $key . '" contains empty terms around "|" or "&". Please remove extra operators.';
                continue;
            }

            $orParts = array_values(array_filter(array_map('trim', explode('|', $raw)), static fn($x) => $x !== ''));
            if (empty($orParts)) {
                $errors[] = 'Search for column "' . $key . '" is invalid.';
                continue;
            }

            $totalTerms = 0;
            $orExprs    = [];
            foreach ($orParts as $orIdx => $orRaw) {
                $andParts = array_values(array_filter(array_map('trim', explode('&', $orRaw)), static fn($x) => $x !== ''));
                if (empty($andParts)) {
                    $errors[] = 'Search for column "' . $key . '" is invalid.';
                    $orExprs  = [];
                    break;
                }

                $andExprs = [];
                foreach ($andParts as $andIdx => $term) {
                    $negated = false;
                    $term    = trim($term);
                    if ($term === '!' || str_starts_with($term, '!!')) {
                        $errors[] = 'Search for column "' . $key . '" contains an invalid NOT term. Use !term (e.g. !bob).';
                        $orExprs  = [];
                        break 2;
                    }
                    if (str_starts_with($term, '!')) {
                        $negated = true;
                        $term    = trim(substr($term, 1));
                        if ($term === '') {
                            $errors[] = 'Search for column "' . $key . '" contains an invalid NOT term. Use !term (e.g. !bob).';
                            $orExprs  = [];
                            break 2;
                        }
                    }
                    $totalTerms++;
                    $param      = $makeParam((string)$cfg['param_base'], $orIdx, $andIdx);
                    $andExpr    = sprintf((string)$cfg['sql'], $param);
                    if ($negated) {
                        $andExpr = str_replace(' LIKE ', ' NOT LIKE ', $andExpr);
                    }
                    $andExprs[]     = $andExpr;
                    $params[$param] = '%' . $term . '%';
                }
                if (!empty($andExprs)) {
                    $orExprs[] = '(' . implode(' AND ', $andExprs) . ')';
                }
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

        $whereSql = !empty($where) ? ('WHERE ' . implode(' AND ', $where)) : '';
        return [$whereSql, $params, $errors, $warnings];
    }

    private function eventSelectCols(): string
    {
        return <<<'SQL'
    a.asset_id                                                               AS id,
    e.event_id                                                               AS event_id,
    ei.event_item_id                                                         AS event_item_id,
    e.event_date                                                             AS date,
    e.org_name                                                               AS org_name,
    IFNULL(CAST(e.rating AS CHAR), '')                                       AS rating,
    IFNULL(e.keywords, '')                                                   AS keywords,
    IFNULL(e.location, '')                                                   AS location,
    IFNULL(e.summary, '')                                                    AS summary,
    GROUP_CONCAT(DISTINCT p.name ORDER BY p.name SEPARATOR ', ')             AS crew,
    IFNULL(ei.label, '')                                                     AS item_label,
    a.file_type                                                              AS file_type,
    a.file_ext                                                               AS file_ext,
    a.source_relpath                                                         AS source_relpath,
    a.checksum_sha256                                                        AS checksum_sha256,
    a.duration_seconds                                                       AS duration_seconds,
    a.media_info                                                             AS media_info,
    a.media_created_at                                                       AS media_created_at
SQL;
    }

    private function eventFromClause(): string
    {
        return <<<'SQL'
FROM events e
JOIN event_items ei ON e.event_id = ei.event_id
JOIN assets a ON ei.asset_id = a.asset_id
LEFT JOIN event_participants ep ON e.event_id = ep.event_id
LEFT JOIN participants p ON ep.participant_id = p.participant_id
SQL;
    }

    public function countEventViewRows(?int $eventId, array $filters = []): int
    {
        [$whereSql, $params] = $this->buildEventFilters($filters, $eventId);
        $from = $this->eventFromClause();
        $sql  = <<<SQL
SELECT COUNT(*) AS row_count
FROM (
    SELECT e.event_id, ei.event_item_id, a.asset_id
    $from
    $whereSql
    GROUP BY e.event_id, ei.event_item_id, a.asset_id
) t
SQL;
        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, is_int($v) ? $v : (string)$v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return (int)($row['row_count'] ?? 0);
    }

    public function fetchEventViewPage(?int $eventId, array $filters, int $limit, int $offset): array
    {
        [$whereSql, $params] = $this->buildEventFilters($filters, $eventId);
        $cols = $this->eventSelectCols();
        $from = $this->eventFromClause();
        $sql  = <<<SQL
SELECT
$cols
$from
$whereSql
GROUP BY e.event_id, ei.event_item_id, a.asset_id
ORDER BY e.event_date DESC, e.event_id ASC, ei.position ASC, a.asset_id ASC
LIMIT :limit OFFSET :offset
SQL;
        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, is_int($v) ? $v : (string)$v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function fetchEventViewFiltered(?int $eventId, array $filters): array
    {
        [$whereSql, $params] = $this->buildEventFilters($filters, $eventId);
        $cols = $this->eventSelectCols();
        $from = $this->eventFromClause();
        $sql  = <<<SQL
SELECT
$cols
$from
$whereSql
GROUP BY e.event_id, ei.event_item_id, a.asset_id
ORDER BY e.event_date DESC, e.event_id ASC, ei.position ASC, a.asset_id ASC
SQL;
        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, is_int($v) ? $v : (string)$v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function fetchEventView(?int $eventId): array
    {
        [$whereSql, $params] = $this->buildEventFilters([], $eventId);
        $cols = $this->eventSelectCols();
        $from = $this->eventFromClause();
        $sql  = <<<SQL
SELECT
$cols
$from
$whereSql
GROUP BY e.event_id, ei.event_item_id, a.asset_id
ORDER BY e.event_date DESC, e.event_id ASC, ei.position ASC, a.asset_id ASC
SQL;
        if (!empty($params)) {
            $stmt = $this->pdo->prepare($sql);
            foreach ($params as $k => $v) {
                $stmt->bindValue($k, is_int($v) ? $v : (string)$v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
            }
            $stmt->execute();
        } else {
            $stmt = $this->pdo->query($sql);
        }
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function ensureEvent(
        string $date,
        string $orgName,
        string $eventType,
        string $location = '',
        string $rating   = '',
        string $notes    = '',
        string $keywords = ''
    ): int {
        $stmt = $this->pdo->prepare(
            'SELECT event_id FROM events WHERE event_date = :d AND org_name = :o LIMIT 1'
        );
        $stmt->execute([':d' => $date, ':o' => $orgName]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row && isset($row['event_id'])) {
            $eid = (int)$row['event_id'];
            if ($keywords !== '') {
                $this->pdo->prepare('UPDATE events SET keywords = :kw WHERE event_id = :eid')
                    ->execute([':kw' => $keywords, ':eid' => $eid]);
            }
            return $eid;
        }
        $ratingVal = ($rating !== '' && ctype_digit($rating)) ? (int)$rating : null;
        $sql = 'INSERT INTO events (event_date, org_name, event_type, location, summary, rating, keywords)'
             . ' VALUES (:date, :org, :etype, :location, :summary, :rating, :kw)';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':date'     => $date,
            ':org'      => $orgName,
            ':etype'    => $eventType  !== '' ? $eventType  : null,
            ':location' => $location   !== '' ? $location   : null,
            ':summary'  => $notes      !== '' ? $notes      : null,
            ':rating'   => $ratingVal,
            ':kw'       => $keywords   !== '' ? $keywords   : null,
        ]);
        return (int)$this->pdo->lastInsertId();
    }
}
