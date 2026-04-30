<?php declare(strict_types=1);
/**
 * api/taggings.php — Tagging CRUD (human review layer).
 *
 * PATCH  /api/taggings.php?id=N            — confirm / edit a tagging (admin)
 *   body: {"source":"human","confidence":0.9,"name":"new_name","namespace":"scene"}
 * POST   /api/taggings.php                 — create manual tag (admin)
 *   body: {"target_type":"asset","target_id":N,"namespace":"scene","name":"foo"}
 * DELETE /api/taggings.php?id=N            — remove a tagging (admin)
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Production\Api\Infrastructure\Database;

header('Content-Type: application/json; charset=utf-8');

$user    = $_SERVER['PHP_AUTH_USER']
        ?? $_SERVER['REMOTE_USER']
        ?? $_SERVER['REDIRECT_REMOTE_USER']
        ?? null;
$isAdmin = ($user === 'admin');

function json_ok(array $body, int $code = 200): void
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

if (!$isAdmin) {
    json_err('Admin required', 403);
}

try {
    $pdo = Database::createFromEnv();
} catch (Throwable $e) {
    json_err('DB connection failed: ' . $e->getMessage(), 500);
}

$method   = $_SERVER['REQUEST_METHOD'] ?? '';
$taggingId = (int)($_GET['id'] ?? 0);
$raw      = file_get_contents('php://input') ?: '';
$body     = json_decode($raw, true);
if (!is_array($body)) {
    $body = [];
}

// ── DELETE ────────────────────────────────────────────────────────────────────
if ($method === 'DELETE') {
    if ($taggingId <= 0) {
        json_err('id required');
    }
    $pdo->prepare("DELETE FROM taggings WHERE id=:id")->execute([':id' => $taggingId]);
    json_ok(['deleted' => $taggingId]);
}

// ── PATCH — confirm / edit ────────────────────────────────────────────────────
if ($method === 'PATCH') {
    if ($taggingId <= 0) {
        json_err('id required');
    }
    // Fetch current row
    $stmt = $pdo->prepare("SELECT tg.*, t.namespace, t.name FROM taggings tg JOIN tags t ON t.id=tg.tag_id WHERE tg.id=:id");
    $stmt->execute([':id' => $taggingId]);
    $current = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$current) {
        json_err('Tagging not found', 404);
    }

    $newSource     = in_array($body['source'] ?? '', ['ai','human'], true) ? $body['source'] : $current['source'];
    $newConfidence = isset($body['confidence']) ? max(0.0, min(1.0, (float)$body['confidence'])) : $current['confidence'];

    // Handle name/namespace rename → may need to create a new tag row
    $newNs   = isset($body['namespace']) ? (string)$body['namespace'] : $current['namespace'];
    $newName = isset($body['name'])      ? mb_strtolower(trim((string)$body['name'])) : $current['name'];
    $newName = preg_replace('/[^a-z0-9_]/', '', str_replace(' ', '_', $newName));
    $newName = $newName !== '' ? $newName : $current['name'];

    // Upsert tag if different
    $tagId = (int)$current['tag_id'];
    if ($newNs !== $current['namespace'] || $newName !== $current['name']) {
        $pdo->prepare("INSERT IGNORE INTO tags (namespace,name) VALUES (:ns,:nm)")
            ->execute([':ns' => $newNs, ':nm' => $newName]);
        $ts = $pdo->prepare("SELECT id FROM tags WHERE namespace=:ns AND name=:nm");
        $ts->execute([':ns' => $newNs, ':nm' => $newName]);
        $tagId = (int)$ts->fetchColumn();
    }

    $pdo->prepare(
        "UPDATE taggings SET tag_id=:tid, source=:src, confidence=:cf WHERE id=:id"
    )->execute([':tid' => $tagId, ':src' => $newSource, ':cf' => $newConfidence, ':id' => $taggingId]);

    json_ok(['updated' => $taggingId, 'source' => $newSource, 'confidence' => $newConfidence]);
}

// ── POST — create manual tagging ─────────────────────────────────────────────
if ($method === 'POST') {
    $targetType = (string)($body['target_type'] ?? '');
    $targetId   = (int)($body['target_id']      ?? 0);
    $namespace  = mb_strtolower(trim((string)($body['namespace'] ?? '')));
    $name       = mb_strtolower(trim((string)($body['name']      ?? '')));
    $name       = preg_replace('/[^a-z0-9_]/', '', str_replace(' ', '_', $name));
    $confidence = isset($body['confidence']) ? max(0.0, min(1.0, (float)$body['confidence'])) : 1.0;

    if (!in_array($targetType, ['asset','event','event_item','segment'], true)) {
        json_err('Invalid target_type');
    }
    if ($targetId <= 0)  { json_err('target_id must be positive'); }
    if ($namespace === '') { json_err('namespace required'); }
    if ($name === '')      { json_err('name required'); }

    // Ensure tag exists
    $pdo->prepare("INSERT IGNORE INTO tags (namespace,name) VALUES (:ns,:nm)")
        ->execute([':ns' => $namespace, ':nm' => $name]);
    $ts = $pdo->prepare("SELECT id FROM tags WHERE namespace=:ns AND name=:nm");
    $ts->execute([':ns' => $namespace, ':nm' => $name]);
    $tagId = (int)$ts->fetchColumn();

    $ins = $pdo->prepare(
        "INSERT INTO taggings (tag_id,target_type,target_id,confidence,source) "
        . "VALUES (:tid,:tt,:ti,:cf,'human') "
        . "ON DUPLICATE KEY UPDATE source='human', confidence=VALUES(confidence)"
    );
    $ins->execute([':tid' => $tagId, ':tt' => $targetType, ':ti' => $targetId, ':cf' => $confidence]);

    json_ok(['status' => 'created', 'tag_id' => $tagId], 201);
}

json_err('Method not allowed', 405);
