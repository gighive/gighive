<?php declare(strict_types=1);
header('Cache-Control: no-store');
header('Content-Type: application/json');

require_once __DIR__ . '/../vendor/autoload.php';

use Production\Api\Infrastructure\Database;

$body = json_decode(file_get_contents('php://input'));
if ($body === null) {
    http_response_code(400);
    echo json_encode(['error' => 'Bad Request']);
    exit;
}
if (preg_match('/^[A-Za-z0-9_\-]{30,40}$/', $body->nonce ?? '') !== 1) {
    http_response_code(400);
    echo json_encode(['error' => 'Bad Request']);
    exit;
}
$uploadJobId = filter_var($body->upload_job_id ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($uploadJobId === false) {
    http_response_code(400);
    echo json_encode(['error' => 'Bad Request']);
    exit;
}
$nonce = (string)$body->nonce;

try {
    $pdo = Database::createFromEnv();
} catch (\Throwable $e) {
    http_response_code(500);
    exit;
}

try {
    // Step 1: validate nonce (own upload approved) — same access gate as guest-gallery.php Step 1.
    // Gallery expiry intentionally NOT checked: guests may delete their own video at any time.
    $stmt = $pdo->prepare(
        'SELECT t.event_id
         FROM anon_upload_attributions a
         JOIN upload_jobs j ON j.job_id = a.upload_job_id
         JOIN event_upload_tokens t ON t.token_id = a.token_id
         WHERE a.status_nonce = ? AND j.moderation_status = \'approved\''
    );
    $stmt->execute([$nonce]);
    $row = $stmt->fetch(\PDO::FETCH_ASSOC);
} catch (\PDOException $e) {
    http_response_code(500);
    exit;
}

if ($row === false) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

try {
    // Step 2: soft-delete — nonce must own the target upload_job_id (upload_jobs.id INT).
    // guest_deleted_at = NOW() unconditionally so rowCount() = 1 on repeat calls (idempotent).
    $stmt = $pdo->prepare(
        'UPDATE upload_jobs j
         JOIN anon_upload_attributions a ON a.upload_job_id = j.job_id
         SET j.guest_deleted = 1, j.guest_deleted_at = NOW()
         WHERE j.id = ? AND a.status_nonce = ?'
    );
    $stmt->execute([$uploadJobId, $nonce]);
    if ($stmt->rowCount() === 0) {
        http_response_code(403);
        echo json_encode(['error' => 'Forbidden']);
        exit;
    }
} catch (\PDOException $e) {
    http_response_code(500);
    exit;
}

echo json_encode(['status' => 'deleted']);
