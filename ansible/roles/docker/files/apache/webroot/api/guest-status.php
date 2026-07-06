<?php declare(strict_types=1);
header('Cache-Control: no-store');
header('Content-Type: application/json');

require_once __DIR__ . '/../vendor/autoload.php';

use Production\Api\Infrastructure\Database;

$nonce = $_GET['nonce'] ?? '';
if (preg_match('/^[A-Za-z0-9_\-]{30,40}$/', $nonce) !== 1) {
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
    $stmt = $pdo->prepare(
        'SELECT j.moderation_status,
                e.event_date, e.org_name, e.gallery_expires_at,
                (SELECT COUNT(*) FROM upload_jobs j2
                 JOIN anon_upload_attributions a2 ON a2.upload_job_id = j2.job_id
                 JOIN event_upload_tokens t2 ON t2.token_id = a2.token_id
                 WHERE t2.event_id = t.event_id AND j2.moderation_status = \'approved\'
                ) AS video_count
         FROM anon_upload_attributions a
         JOIN upload_jobs j ON j.job_id = a.upload_job_id
         JOIN event_upload_tokens t ON t.token_id = a.token_id
         JOIN events e ON e.event_id = t.event_id
         WHERE a.status_nonce = ?'
    );
    $stmt->execute([$nonce]);
    $row = $stmt->fetch(\PDO::FETCH_ASSOC);
} catch (\PDOException $e) {
    http_response_code(500);
    exit;
}

if ($row === false) {
    http_response_code(404);
    echo json_encode(['error' => 'not found']);
    exit;
}

$now           = new \DateTime('now');
$galleryExpiry = $row['gallery_expires_at'] !== null ? new \DateTime($row['gallery_expires_at']) : null;
$isExpired     = $galleryExpiry !== null && $galleryExpiry <= $now;

$status        = $isExpired ? 'expired' : (string)$row['moderation_status'];
$daysRemaining = null;
if ($galleryExpiry !== null) {
    $daysRemaining = $isExpired ? 0 : max(0, (int)$now->diff($galleryExpiry)->days);
}

echo json_encode([
    'status'         => $status,
    'event_name'     => $row['org_name'] . ' \u2014 ' . $row['event_date'],
    'video_count'    => (int)$row['video_count'],
    'days_remaining' => $daysRemaining,
]);
