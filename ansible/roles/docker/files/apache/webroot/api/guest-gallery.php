<?php declare(strict_types=1);
header('Cache-Control: no-store');
header('Content-Type: application/json');

require_once __DIR__ . '/../vendor/autoload.php';

use Production\Api\Infrastructure\Database;

$nonce = $_GET['nonce'] ?? '';
if (preg_match('/^[A-Za-z0-9_\-]{30,43}$/', $nonce) !== 1) {
    http_response_code(400);
    echo json_encode(['error' => 'invalid nonce']);
    exit;
}

try {
    $pdo = Database::createFromEnv();
} catch (\Throwable $e) {
    http_response_code(500);
    exit;
}

try {
    // Step 1: verify nonce's own upload is approved and gallery is not expired
    $stmt = $pdo->prepare(
        'SELECT t.event_id, t.expires_at
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
    // Fallback: authenticate as a raw upload token (viewer path)
    try {
        $tokenHash = hash('sha256', $nonce);
        $stmt = $pdo->prepare(
            'SELECT t.event_id, t.expires_at
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
    try {
        $tokenExpiry   = new \DateTime($tokenRow['expires_at']);
        $now           = new \DateTime('now');
        $diff          = $now->diff($tokenExpiry);
        $daysRemaining = $diff->days > 3650 ? null : max(0, (int)$diff->days);
    } catch (\Exception $e) {
        http_response_code(500);
        exit;
    }
} else {
    $eventId     = (int)$row['event_id'];
    $tokenExpiry = new \DateTime($row['expires_at']);
    $now         = new \DateTime('now');
    $isExpired   = $tokenExpiry <= $now;

    if ($isExpired) {
        echo json_encode(['status' => 'expired', 'days_remaining' => 0, 'videos' => []]);
        exit;
    }

    $diff          = $now->diff($tokenExpiry);
    $daysRemaining = $diff->days > 3650 ? null : max(0, (int)$diff->days);
}

try {
    // Step 2: fetch all approved videos for the event in chronological capture order
    $stmt = $pdo->prepare(
        'SELECT j.id AS upload_job_id, j.label, j.file_relpath, j.approved_at,
                a.display_name
         FROM upload_jobs j
         JOIN anon_upload_attributions a ON a.upload_job_id = j.job_id
         JOIN event_upload_tokens t ON t.token_id = a.token_id
         WHERE t.event_id = ? AND j.moderation_status = \'approved\' AND j.guest_deleted = 0
         ORDER BY j.started_at ASC'
    );
    $stmt->execute([$eventId]);
    $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
} catch (\PDOException $e) {
    http_response_code(500);
    exit;
}

$videos = [];
foreach ($rows as $r) {
    $streamUrl = '/api/guest-stream.php?nonce=' . urlencode($nonce) . '&job_id=' . (int)$r['upload_job_id'];
    $videos[]  = [
        'upload_job_id' => (int)$r['upload_job_id'],
        'label'         => $r['label'],
        'stream_url'    => $streamUrl,
        'display_name'  => $r['display_name'],
        'approved_at'   => $r['approved_at'],
    ];
}

echo json_encode([
    'status'         => 'approved',
    'days_remaining' => $daysRemaining,
    'videos'         => $videos,
]);
