<?php declare(strict_types=1);
header('Cache-Control: no-store');
header('Content-Type: application/json');

require_once __DIR__ . '/../vendor/autoload.php';

use Production\Api\Infrastructure\Database;

$body = json_decode(file_get_contents('php://input'));
if ($body === null) {
    http_response_code(400);
    echo json_encode(['error' => 'invalid request']);
    exit;
}
if (preg_match('/^[A-Za-z0-9_\-]{30,40}$/', $body->nonce ?? '') !== 1) {
    http_response_code(400);
    exit;
}
$uploadJobId = filter_var($body->upload_job_id ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($uploadJobId === false) {
    http_response_code(400);
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
    // Step 1: verify nonce is an approved contributor and get event_id
    $stmt = $pdo->prepare(
        'SELECT t.event_id
         FROM anon_upload_attributions a
         JOIN upload_jobs j_mine ON j_mine.job_id = a.upload_job_id
         JOIN event_upload_tokens t ON t.token_id = a.token_id
         WHERE a.status_nonce = ? AND j_mine.moderation_status = \'approved\''
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

$eventId = (int)$row['event_id'];

try {
    // Step 2: flag the target video (approved, same event); idempotent — guest_flagged_at updates on repeat
    $stmt = $pdo->prepare(
        'UPDATE upload_jobs j_target
         JOIN anon_upload_attributions a2 ON a2.upload_job_id = j_target.job_id
         JOIN event_upload_tokens t2 ON t2.token_id = a2.token_id
         SET j_target.guest_flagged = 1, j_target.guest_flagged_at = NOW()
         WHERE j_target.id = ? AND j_target.moderation_status = \'approved\'
           AND t2.event_id = ?'
    );
    $stmt->execute([$uploadJobId, $eventId]);
    if ($stmt->rowCount() === 0) {
        http_response_code(403);
        echo json_encode(['error' => 'Forbidden']);
        exit;
    }
} catch (\PDOException $e) {
    http_response_code(500);
    exit;
}

echo json_encode(['success' => true]);
