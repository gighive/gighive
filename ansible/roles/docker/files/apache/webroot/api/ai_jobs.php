<?php declare(strict_types=1);
/**
 * api/ai_jobs.php — REST endpoint for AI job management.
 *
 * GET  /api/ai_jobs.php               — list recent jobs (admin only for full list)
 * GET  /api/ai_jobs.php?id=N          — single job
 * POST /api/ai_jobs.php               — enqueue a new job (admin only)
 *   body: {"job_type":"categorize_video","target_type":"asset","target_id":N}
 * POST /api/ai_jobs.php?action=enqueue_all_untagged — bulk enqueue (admin only)
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Production\Api\Infrastructure\Database;

header('Content-Type: application/json; charset=utf-8');

$user = $_SERVER['PHP_AUTH_USER']
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

try {
    $pdo = Database::createFromEnv();
} catch (Throwable $e) {
    json_err('DB connection failed: ' . $e->getMessage(), 500);
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = $_GET['action'] ?? '';

// ── GET single job ──────────────────────────────────────────────────────────
if ($method === 'GET' && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    $stmt = $pdo->prepare("SELECT * FROM ai_jobs WHERE id=:id");
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        json_err('Job not found', 404);
    }
    json_ok(['job' => $row]);
}

// ── GET list ─────────────────────────────────────────────────────────────────
if ($method === 'GET') {
    $status = $_GET['status'] ?? '';
    $limit  = min((int)($_GET['limit'] ?? 100), 500);
    $params = [];
    $where  = '';
    if ($status !== '') {
        $where = 'WHERE status = :st';
        $params[':st'] = $status;
    }
    $rows = $pdo->prepare("SELECT * FROM ai_jobs $where ORDER BY created_at DESC LIMIT $limit");
    $rows->execute($params);
    json_ok(['jobs' => $rows->fetchAll(PDO::FETCH_ASSOC)]);
}

// ── POST enqueue_all_untagged ─────────────────────────────────────────────────
if ($method === 'POST' && $action === 'enqueue_all_untagged') {
    if (!$isAdmin) {
        json_err('Admin required', 403);
    }
    $ai_enabled = filter_var(getenv('AI_WORKER_ENABLED'), FILTER_VALIDATE_BOOLEAN);
    if (!$ai_enabled) {
        json_err('AI_WORKER_ENABLED is false', 400);
    }
    // Find all video assets without a queued/running/done categorize_video job.
    // Exclude stub rows (duration_seconds IS NULL) created by ingestStub() during
    // manifest Step 1 — those assets have no file on disk yet and will cause
    // immediate "Video file not found" failures.
    $sql = "SELECT a.asset_id FROM assets a
            WHERE a.file_type='video'
              AND a.duration_seconds IS NOT NULL
              AND NOT EXISTS (
                SELECT 1 FROM ai_jobs j
                WHERE j.target_type='asset' AND j.target_id=a.asset_id
                  AND j.job_type='categorize_video'
                  AND j.status IN ('queued','running','done')
              )";
    $stmt = $pdo->query($sql);
    $assets = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $insert = $pdo->prepare(
        "INSERT INTO ai_jobs (job_type, target_type, target_id) VALUES ('categorize_video', 'asset', :aid)"
    );
    $enqueued = 0;
    $job_ids = [];
    foreach ($assets as $a) {
        $insert->execute([':aid' => $a['asset_id']]);
        $job_ids[] = (int)$pdo->lastInsertId();
        $enqueued++;
    }
    json_ok(['enqueued' => $enqueued, 'job_ids' => $job_ids]);
}

// ── POST retag_all ────────────────────────────────────────────────────────────
if ($method === 'POST' && $action === 'retag_all') {
    if (!$isAdmin) {
        json_err('Admin required', 403);
    }
    $ai_enabled = filter_var(getenv('AI_WORKER_ENABLED'), FILTER_VALIDATE_BOOLEAN);
    if (!$ai_enabled) {
        json_err('AI_WORKER_ENABLED is false', 400);
    }
    // Enqueue ALL fully-ingested video assets (duration_seconds IS NOT NULL),
    // skipping only those with an already active (queued/running) job.
    // Unlike enqueue_all_untagged, 'done' status is NOT excluded — this forces
    // re-tagging of previously tagged assets (e.g. after a model or config change).
    $sql = "SELECT a.asset_id FROM assets a
            WHERE a.file_type='video'
              AND a.duration_seconds IS NOT NULL
              AND NOT EXISTS (
                SELECT 1 FROM ai_jobs j
                WHERE j.target_type='asset' AND j.target_id=a.asset_id
                  AND j.job_type='categorize_video'
                  AND j.status IN ('queued','running')
              )";
    $stmt = $pdo->query($sql);
    $assets = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $insert = $pdo->prepare(
        "INSERT INTO ai_jobs (job_type, target_type, target_id) VALUES ('categorize_video', 'asset', :aid)"
    );
    $enqueued = 0;
    $job_ids = [];
    foreach ($assets as $a) {
        $insert->execute([':aid' => $a['asset_id']]);
        $job_ids[] = (int)$pdo->lastInsertId();
        $enqueued++;
    }
    // Include IDs of already-active jobs that were skipped (de-duplicated) so the
    // frontend total matches the full "retag all N" batch size.
    $skippedStmt = $pdo->query(
        "SELECT j.id FROM ai_jobs j
         JOIN assets a ON a.asset_id = j.target_id AND j.target_type='asset'
         WHERE j.job_type='categorize_video'
           AND j.status IN ('queued','running')
           AND a.file_type='video'
           AND a.duration_seconds IS NOT NULL"
    );
    $skipped_ids = array_map('intval', array_column($skippedStmt->fetchAll(PDO::FETCH_ASSOC), 'id'));
    $all_job_ids = array_values(array_unique(array_merge($job_ids, $skipped_ids)));
    json_ok(['enqueued' => $enqueued, 'job_ids' => $all_job_ids]);
}

// ── POST cancel_jobs ────────────────────────────────────────────────────────────
if ($method === 'POST' && $action === 'cancel_jobs') {
    if (!$isAdmin) {
        json_err('Admin required', 403);
    }
    $raw  = file_get_contents('php://input') ?: '';
    $body = json_decode($raw, true);
    $ids  = array_filter(array_map('intval', (array)($body['job_ids'] ?? [])));
    if (empty($ids)) {
        json_ok(['cancelled' => 0]);
    }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare(
        "DELETE FROM ai_jobs WHERE id IN ($placeholders) AND status='queued'"
    );
    $stmt->execute(array_values($ids));
    json_ok(['cancelled' => $stmt->rowCount()]);
}

// ── POST enqueue single ───────────────────────────────────────────────────────
if ($method === 'POST') {
    if (!$isAdmin) {
        json_err('Admin required', 403);
    }
    $raw  = file_get_contents('php://input') ?: '';
    $body = json_decode($raw, true);
    if (!is_array($body)) {
        json_err('JSON body required');
    }
    $jobType    = (string)($body['job_type']    ?? 'categorize_video');
    $targetType = (string)($body['target_type'] ?? 'asset');
    $targetId   = (int)($body['target_id']      ?? 0);
    if ($targetId <= 0) {
        json_err('target_id must be a positive integer');
    }
    // Check for duplicate active job
    $check = $pdo->prepare(
        "SELECT id FROM ai_jobs WHERE job_type=:jt AND target_type=:tt AND target_id=:tid "
        . "AND status IN ('queued','running') LIMIT 1"
    );
    $check->execute([':jt' => $jobType, ':tt' => $targetType, ':tid' => $targetId]);
    if ($check->fetch()) {
        json_ok(['status' => 'already_queued'], 200);
    }
    $ins = $pdo->prepare(
        "INSERT INTO ai_jobs (job_type, target_type, target_id) VALUES (:jt, :tt, :tid)"
    );
    $ins->execute([':jt' => $jobType, ':tt' => $targetType, ':tid' => $targetId]);
    json_ok(['status' => 'queued', 'job_id' => (int)$pdo->lastInsertId()], 201);
}

json_err('Method not allowed', 405);
