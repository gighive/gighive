<?php declare(strict_types=1);
/**
 * api/tags.php — Read tags and taggings.
 *
 * GET /api/tags.php                                — all tags (optionally ?namespace=X)
 * GET /api/tags.php?target_type=asset&target_id=N — taggings for one target
 * GET /api/tags.php?target_type=asset&asset_ids=1,2,3 — batch taggings for multiple targets
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Production\Api\Infrastructure\Database;

header('Content-Type: application/json; charset=utf-8');

function json_ok(mixed $body, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($body);
    exit;
}

function json_err(string $msg, int $code = 400): void
{
    http_response_code($code);
    echo json_encode(['error' => $msg]);
    exit;
}

try {
    $pdo = Database::createFromEnv();
} catch (Throwable $e) {
    json_err('DB connection failed: ' . $e->getMessage(), 500);
}

$method      = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$targetType  = $_GET['target_type'] ?? '';
$targetId    = (int)($_GET['target_id'] ?? 0);
$assetIdsRaw = $_GET['asset_ids'] ?? '';
$namespace   = $_GET['namespace'] ?? '';

if ($method !== 'GET') {
    json_err('Method not allowed', 405);
}

// ── batch taggings for multiple assets ───────────────────────────────────────
if ($assetIdsRaw !== '' && $targetType === 'asset') {
    $ids = array_filter(array_map('intval', explode(',', $assetIdsRaw)));
    if (empty($ids)) {
        json_ok([]);
    }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $sql = "SELECT tg.target_id, t.namespace, t.name, tg.confidence, tg.source
            FROM taggings tg
            JOIN tags t ON t.id = tg.tag_id
            WHERE tg.target_type='asset' AND tg.target_id IN ($placeholders)
            ORDER BY tg.target_id, t.namespace, t.name";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($ids);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $map = [];
    foreach ($rows as $r) {
        $map[(int)$r['target_id']][] = [
            'namespace'  => $r['namespace'],
            'name'       => $r['name'],
            'confidence' => (float)$r['confidence'],
            'source'     => $r['source'],
        ];
    }
    json_ok($map);
}

// ── taggings for single target ────────────────────────────────────────────────
if ($targetType !== '' && $targetId > 0) {
    $sql = "SELECT tg.id, t.namespace, t.name, tg.confidence, tg.source,
                   tg.start_seconds, tg.end_seconds, tg.run_id, tg.created_at
            FROM taggings tg
            JOIN tags t ON t.id = tg.tag_id
            WHERE tg.target_type=:tt AND tg.target_id=:tid
            ORDER BY t.namespace, t.name";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':tt' => $targetType, ':tid' => $targetId]);
    json_ok(['taggings' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
}

// ── tag list (browse all) ─────────────────────────────────────────────────────
$params = [];
$where  = '';
if ($namespace !== '') {
    $where = 'WHERE namespace=:ns';
    $params[':ns'] = $namespace;
}
$sql = "SELECT t.id, t.namespace, t.name,
               COUNT(tg.id) AS usage_count
        FROM tags t
        LEFT JOIN taggings tg ON tg.tag_id = t.id
        $where
        GROUP BY t.id, t.namespace, t.name
        ORDER BY t.namespace, t.name";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
json_ok(['tags' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
