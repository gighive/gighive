<?php declare(strict_types=1);
header('Cache-Control: no-store');
header('Content-Type: application/json');

require_once __DIR__ . '/../vendor/autoload.php';

use Production\Api\Infrastructure\Database;
use Production\Api\Services\GuestCredentialResolver;
use Production\Api\Services\MediaStorageService;

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

// Step 1: resolve guest credentials via shared helper (nonce path + raw-token fallback)
$resolver = new GuestCredentialResolver($pdo);
try {
    $result = $resolver->resolveNonceOrToken($nonce);
} catch (\PDOException $e) {
    http_response_code(500);
    exit;
}
if ($result === false) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}
$eventId = (int)$result['event_id'];
try {
    $tokenExpiry   = new \DateTime($result['expires_at']);
    $now           = new \DateTime('now');
    $isExpired     = $tokenExpiry <= $now;
    if ($isExpired) {
        echo json_encode(['status' => 'expired', 'days_remaining' => 0, 'videos' => []]);
        exit;
    }
    $diff          = $now->diff($tokenExpiry);
    $daysRemaining = $diff->days > 3650 ? null : max(0, (int)$diff->days);
} catch (\Exception $e) {
    http_response_code(500);
    exit;
}

$credentialHash = hash('sha256', $nonce);

try {
    // Step 2: fetch all approved videos for the event in chronological capture order
    $stmt = $pdo->prepare(
        'SELECT j.id AS upload_job_id, j.label, j.file_relpath, j.approved_at,
                a.display_name,
                (gvr.report_id IS NOT NULL) AS reported_by_me
         FROM upload_jobs j
         JOIN anon_upload_attributions a ON a.upload_job_id = j.job_id
         JOIN event_upload_tokens t ON t.token_id = a.token_id
         LEFT JOIN guest_video_reports gvr
           ON  gvr.upload_job_id            = j.id
           AND gvr.reporter_credential_hash = ?
           AND gvr.event_id                 = ?
         WHERE t.event_id = ? AND j.moderation_status = \'approved\' AND j.guest_deleted = 0
         ORDER BY j.started_at ASC'
    );
    $stmt->execute([$credentialHash, $eventId, $eventId]);
    $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
} catch (\PDOException $e) {
    http_response_code(500);
    exit;
}

$videos = [];
foreach ($rows as $r) {
    $streamUrl    = '/api/guest-stream.php?nonce=' . urlencode($nonce) . '&job_id=' . (int)$r['upload_job_id'];
    $thumbnailUrl = null;
    if (preg_match('@^video/([0-9a-f]{64})\.@', (string)($r['file_relpath'] ?? ''), $m)) {
        // Use MediaStorageService::exists() rather than is_file() so this works
        // in both local mode (bind-mounted filesystem) and Azure Blob mode
        // (no local file; existence must be checked against Blob Storage).
        if (MediaStorageService::make()->exists('video/thumbnails', $m[1] . '.png')) {
            $thumbnailUrl = '/video/thumbnails/' . $m[1] . '.png?nonce=' . urlencode($nonce);
        }
    }
    $videos[]  = [
        'upload_job_id'  => (int)$r['upload_job_id'],
        'label'          => $r['label'],
        'stream_url'     => $streamUrl,
        'thumbnail_url'  => $thumbnailUrl,
        'display_name'   => $r['display_name'],
        'approved_at'    => $r['approved_at'],
        'reported_by_me' => (bool)$r['reported_by_me'],
    ];
}

echo json_encode([
    'status'         => 'approved',
    'days_remaining' => $daysRemaining,
    'videos'         => $videos,
]);
