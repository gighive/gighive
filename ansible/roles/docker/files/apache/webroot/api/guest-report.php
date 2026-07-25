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
if (preg_match('/^[A-Za-z0-9_\-]{30,43}$/', $body->nonce ?? '') !== 1) {
    http_response_code(400);
    exit;
}
$uploadJobId = filter_var($body->upload_job_id ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($uploadJobId === false) {
    http_response_code(400);
    exit;
}
$nonce    = (string)$body->nonce;
$reported = isset($body->reported) ? (bool)$body->reported : true;

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
    try {
        $tokenHash = hash('sha256', $nonce);
        $stmt = $pdo->prepare(
            'SELECT t.event_id
             FROM event_upload_tokens t
             WHERE t.token_hash = ? AND t.is_active = 1 AND t.expires_at > NOW()'
        );
        $stmt->execute([$tokenHash]);
        $tokenRow = $stmt->fetch(\PDO::FETCH_ASSOC);
    } catch (\PDOException $e) {
        http_response_code(500);
        exit;
    }
    if ($tokenRow === false) {
        http_response_code(403);
        echo json_encode(['error' => 'Forbidden']);
        exit;
    }
    $eventId = (int)$tokenRow['event_id'];
} else {
    $eventId = (int)$row['event_id'];
}

$credentialHash = hash('sha256', $nonce);

try {
    // Step 2: validate target video belongs to the authenticated event (outside transaction)
    $stmt = $pdo->prepare(
        'SELECT j.id
         FROM upload_jobs j
         JOIN anon_upload_attributions a ON a.upload_job_id = j.job_id
         JOIN event_upload_tokens t ON t.token_id = a.token_id
         WHERE j.id = ? AND j.moderation_status = \'approved\'
           AND t.event_id = ?'
    );
    $stmt->execute([$uploadJobId, $eventId]);
    if ($stmt->fetch() === false) {
        http_response_code(403);
        echo json_encode(['error' => 'Forbidden']);
        exit;
    }
} catch (\PDOException $e) {
    http_response_code(500);
    exit;
}

try {
    // Step 3: write per-guest report row and recompute aggregate (transactional)
    $pdo->beginTransaction();

    if ($reported) {
        $pdo->prepare(
            'INSERT INTO guest_video_reports (event_id, upload_job_id, reporter_credential_hash)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE updated_at = updated_at'
        )->execute([$eventId, $uploadJobId, $credentialHash]);
    } else {
        $pdo->prepare(
            'DELETE FROM guest_video_reports
             WHERE upload_job_id = ? AND reporter_credential_hash = ? AND event_id = ?'
        )->execute([$uploadJobId, $credentialHash, $eventId]);
    }

    $agg = $pdo->prepare(
        'SELECT COUNT(*) AS remaining, MAX(created_at) AS latest_at
         FROM guest_video_reports
         WHERE upload_job_id = ?'
    );
    $agg->execute([$uploadJobId]);
    $aggRow    = $agg->fetch(\PDO::FETCH_ASSOC);
    $remaining = (int)$aggRow['remaining'];
    $latestAt  = $aggRow['latest_at'];

    $pdo->prepare(
        'UPDATE upload_jobs
         SET guest_flagged    = IF(? > 0, 1, 0),
             guest_flagged_at = IF(? > 0, ?, NULL)
         WHERE id = ?'
    )->execute([$remaining, $remaining, $latestAt, $uploadJobId]);

    $pdo->commit();
} catch (\PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    exit;
}

echo json_encode(['success' => true, 'reported_by_me' => $reported]);
