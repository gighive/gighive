<?php declare(strict_types=1);
namespace Production\Api\Repositories;

use PDO;

final class AssetRepository
{
    public function __construct(private PDO $pdo) {}

    public function validateLibrarianFilters(array $filters): array
    {
        [, , $errors, $warnings] = $this->buildLibrarianFilters($filters);
        return [$errors, $warnings];
    }

    private function buildLibrarianFilters(array $filters): array
    {
        $where    = [];
        $params   = [];
        $errors   = [];
        $warnings = [];

        $maxTermsPerField = 10;

        $map = [
            'file_type'        => ['sql' => 'LOWER(a.file_type) LIKE LOWER(%s)',      'param_base' => 'file_type'],
            'source_relpath'   => ['sql' => 'LOWER(a.source_relpath) LIKE LOWER(%s)', 'param_base' => 'source_relpath'],
            'duration_seconds' => ['sql' => 'a.duration_seconds LIKE %s',             'param_base' => 'duration_seconds'],
            'media_info'       => ['sql' => 'LOWER(a.media_info) LIKE LOWER(%s)',     'param_base' => 'media_info'],
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

        // Tag filter — EXISTS subquery against taggings/tags tables
        $tagRaw = trim((string)($filters['tag'] ?? ''));
        if ($tagRaw !== '') {
            $where[]        = 'EXISTS (SELECT 1 FROM taggings tg2 JOIN tags t2 ON t2.id = tg2.tag_id'
                            . ' WHERE tg2.target_type = \'asset\' AND tg2.target_id = a.asset_id'
                            . ' AND LOWER(t2.name) LIKE LOWER(:tag_name))';
            $params[':tag_name'] = '%' . $tagRaw . '%';
        }

        if (!empty($errors)) {
            return ['WHERE 1=0', [], $errors, $warnings];
        }

        $whereSql = !empty($where) ? ('WHERE ' . implode(' AND ', $where)) : '';
        return [$whereSql, $params, $errors, $warnings];
    }

    private function assetSelectCols(): string
    {
        return <<<'SQL'
    a.asset_id         AS id,
    NULL               AS event_id,
    NULL               AS event_item_id,
    ''                 AS date,
    ''                 AS org_name,
    ''                 AS rating,
    ''                 AS keywords,
    ''                 AS location,
    ''                 AS summary,
    ''                 AS crew,
    ''                 AS item_label,
    a.file_type        AS file_type,
    a.file_ext         AS file_ext,
    a.source_relpath   AS source_relpath,
    a.checksum_sha256  AS checksum_sha256,
    a.duration_seconds AS duration_seconds,
    a.media_info       AS media_info,
    a.media_created_at AS media_created_at
SQL;
    }

    public function countLibrarianRows(array $filters = []): int
    {
        [$whereSql, $params] = $this->buildLibrarianFilters($filters);
        $sql  = "SELECT COUNT(*) AS row_count FROM assets a $whereSql";
        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v, PDO::PARAM_STR);
        }
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return (int)($row['row_count'] ?? 0);
    }

    public function fetchLibrarianPage(array $filters, int $limit, int $offset): array
    {
        [$whereSql, $params] = $this->buildLibrarianFilters($filters);
        $cols = $this->assetSelectCols();
        $sql  = <<<SQL
SELECT
$cols
FROM assets a
$whereSql
ORDER BY a.asset_id DESC
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

    public function fetchLibrarianFiltered(array $filters): array
    {
        [$whereSql, $params] = $this->buildLibrarianFilters($filters);
        $cols = $this->assetSelectCols();
        $sql  = <<<SQL
SELECT
$cols
FROM assets a
$whereSql
ORDER BY a.asset_id DESC
SQL;
        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v, PDO::PARAM_STR);
        }
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function fetchLibrarian(): array
    {
        $cols = $this->assetSelectCols();
        $sql  = <<<SQL
SELECT
$cols
FROM assets a
ORDER BY a.asset_id DESC
SQL;
        $stmt = $this->pdo->query($sql);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function fetchAll(): array
    {
        return $this->fetchLibrarian();
    }

    public function create(array $data): int
    {
        $sql = 'INSERT INTO assets'
             . ' (checksum_sha256, file_ext, file_type, source_relpath, size_bytes,'
             . '  duration_seconds, media_info, media_info_tool, mime_type, media_created_at)'
             . ' VALUES'
             . ' (:checksum_sha256, :file_ext, :file_type, :source_relpath, :size_bytes,'
             . '  :duration_seconds, :media_info, :media_info_tool, :mime_type, :media_created_at)';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':checksum_sha256'  => $data['checksum_sha256'] ?? '',
            ':file_ext'         => $data['file_ext'] ?? '',
            ':file_type'        => $data['file_type'] ?? '',
            ':source_relpath'   => $data['source_relpath'] ?? null,
            ':size_bytes'       => isset($data['size_bytes']) ? (int)$data['size_bytes'] : null,
            ':duration_seconds' => isset($data['duration_seconds']) ? (int)$data['duration_seconds'] : null,
            ':media_info'       => $data['media_info'] ?? null,
            ':media_info_tool'  => $data['media_info_tool'] ?? null,
            ':mime_type'        => $data['mime_type'] ?? null,
            ':media_created_at' => $data['media_created_at'] ?? null,
        ]);
        return (int)$this->pdo->lastInsertId();
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM assets WHERE asset_id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function findByChecksum(string $sha256): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM assets WHERE checksum_sha256 = :c LIMIT 1');
        $stmt->execute([':c' => $sha256]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function getDeleteTokenHashById(int $assetId): ?string
    {
        $stmt = $this->pdo->prepare('SELECT delete_token_hash FROM assets WHERE asset_id = :id');
        $stmt->execute([':id' => $assetId]);
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

    public function setDeleteTokenHashIfNull(int $assetId, string $hash): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE assets SET delete_token_hash = :h WHERE asset_id = :id AND delete_token_hash IS NULL'
        );
        $stmt->execute([':h' => $hash, ':id' => $assetId]);
        return $stmt->rowCount() === 1;
    }

    public function updateProbeMetadata(
        int $assetId,
        string $fileExt,
        int $sizeBytes,
        ?string $mimeType,
        ?int $durationSeconds,
        ?string $mediaInfo,
        ?string $mediaInfoTool,
        ?string $mediaCreatedAt = null
    ): void {
        $sql = 'UPDATE assets SET'
             . '  file_ext         = :file_ext,'
             . '  size_bytes       = :size_bytes,'
             . '  mime_type        = :mime_type,'
             . '  duration_seconds = :duration_seconds,'
             . '  media_info       = :media_info,'
             . '  media_info_tool  = :media_info_tool,'
             . '  media_created_at = :media_created_at'
             . ' WHERE asset_id = :asset_id';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':file_ext'         => $fileExt,
            ':size_bytes'       => $sizeBytes,
            ':mime_type'        => $mimeType,
            ':duration_seconds' => $durationSeconds,
            ':media_info'       => $mediaInfo,
            ':media_info_tool'  => $mediaInfoTool,
            ':media_created_at' => $mediaCreatedAt,
            ':asset_id'         => $assetId,
        ]);
    }
}
