<?php declare(strict_types=1);
header('Cache-Control: no-store');

require_once __DIR__ . '/../vendor/autoload.php';

use Production\Api\Infrastructure\Database;

$nonce = $_GET['nonce'] ?? '';
if (preg_match('/^[A-Za-z0-9_\-]{30,40}$/', $nonce) !== 1) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'invalid nonce']);
    exit;
}

$jobId = isset($_GET['job_id']) ? filter_var($_GET['job_id'], FILTER_VALIDATE_INT) : false;
if ($jobId === false || $jobId <= 0) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'invalid job_id']);
    exit;
}

try {
    $pdo = Database::createFromEnv();
} catch (\Throwable $e) {
    http_response_code(500);
    exit;
}

try {
    // Step 1: authenticate nonce — requester's own upload must be approved; get event_id + expiry
    $stmt = $pdo->prepare(
        'SELECT t.event_id, e.gallery_expires_at
         FROM anon_upload_attributions a
         JOIN upload_jobs j ON j.job_id = a.upload_job_id
         JOIN event_upload_tokens t ON t.token_id = a.token_id
         JOIN events e ON e.event_id = t.event_id
         WHERE a.status_nonce = ? AND j.moderation_status = \'approved\''
    );
    $stmt->execute([$nonce]);
    $authRow = $stmt->fetch(\PDO::FETCH_ASSOC);
} catch (\PDOException $e) {
    http_response_code(500);
    exit;
}

if ($authRow === false) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

$eventId       = (int)$authRow['event_id'];
$galleryExpiry = $authRow['gallery_expires_at'] !== null ? new \DateTime($authRow['gallery_expires_at']) : null;
$now           = new \DateTime('now');

if ($galleryExpiry !== null && $galleryExpiry <= $now) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'gallery expired']);
    exit;
}

try {
    // Step 2: resolve file_relpath for the requested job, verifying it belongs to the same event
    // and is approved
    $stmt = $pdo->prepare(
        'SELECT j.file_relpath
         FROM upload_jobs j
         JOIN anon_upload_attributions a ON a.upload_job_id = j.job_id
         JOIN event_upload_tokens t ON t.token_id = a.token_id
         WHERE j.id = ? AND t.event_id = ? AND j.moderation_status = \'approved\''
    );
    $stmt->execute([$jobId, $eventId]);
    $videoRow = $stmt->fetch(\PDO::FETCH_ASSOC);
} catch (\PDOException $e) {
    http_response_code(500);
    exit;
}

if ($videoRow === false) {
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'video not found']);
    exit;
}

$fileRelpath = $videoRow['file_relpath'];

// Basic path traversal guard — relpath must be a clean relative path
if (
    strpos($fileRelpath, '..') !== false ||
    strpos($fileRelpath, '/') === 0 ||
    !preg_match('#^[a-zA-Z0-9_/\-\.]+$#', $fileRelpath)
) {
    http_response_code(500);
    exit;
}

$webroot  = realpath(__DIR__ . '/../');
$filePath = $webroot . '/' . $fileRelpath;

if ($webroot === false || !is_file($filePath) || !is_readable($filePath)) {
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'file not found on disk']);
    exit;
}

$fileSize = (int)filesize($filePath);
$mimeType = 'video/mp4';

$rangeHeader = $_SERVER['HTTP_RANGE'] ?? null;

if ($rangeHeader !== null && preg_match('/bytes=(\d+)-(\d*)/', $rangeHeader, $m)) {
    $start  = (int)$m[1];
    $end    = $m[2] !== '' ? (int)$m[2] : $fileSize - 1;
    $end    = min($end, $fileSize - 1);
    $length = $end - $start + 1;

    http_response_code(206);
    header('Content-Type: ' . $mimeType);
    header('Content-Range: bytes ' . $start . '-' . $end . '/' . $fileSize);
    header('Content-Length: ' . $length);
    header('Accept-Ranges: bytes');

    $fp = fopen($filePath, 'rb');
    fseek($fp, $start);
    $remaining = $length;
    while ($remaining > 0 && !feof($fp)) {
        $chunk = (int)min(65536, $remaining);
        echo fread($fp, $chunk);
        $remaining -= $chunk;
        if (connection_aborted()) {
            break;
        }
    }
    fclose($fp);
} else {
    http_response_code(200);
    header('Content-Type: ' . $mimeType);
    header('Content-Length: ' . $fileSize);
    header('Accept-Ranges: bytes');
    readfile($filePath);
}
