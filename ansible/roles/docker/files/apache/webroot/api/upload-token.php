<?php declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use Production\Api\Infrastructure\Database;
use Production\Api\Services\UploadTokenValidator;

header('Content-Type: application/json');

$rawToken = $_GET['token'] ?? '';
if ($rawToken === '') { http_response_code(400); echo json_encode(['error' => 'missing token']); exit; }
// Bound length before hashing — prevents DoS on pathologically long input
if (strlen($rawToken) > 128) { http_response_code(400); echo json_encode(['error' => 'invalid token']); exit; }

try {
    $pdo = Database::createFromEnv();
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'DB connection failed']);
    exit;
}

$validator = new UploadTokenValidator($pdo);
$result = $validator->validate($rawToken);

if ($result === null) { http_response_code(404); echo json_encode(['error' => 'invalid or expired']); exit; }

echo json_encode([
    'event_id'   => $result->eventId,
    'event_date' => $result->eventDate,
    'org_name'   => $result->orgName,
    'event_type' => $result->eventType,
]);
