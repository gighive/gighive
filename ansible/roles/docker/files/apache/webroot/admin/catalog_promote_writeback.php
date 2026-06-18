<?php declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

use Production\Api\Infrastructure\Database;

$user = $_SERVER['PHP_AUTH_USER']
    ?? $_SERVER['REMOTE_USER']
    ?? $_SERVER['REDIRECT_REMOTE_USER']
    ?? null;

if ($user !== 'admin') {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Forbidden']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Method Not Allowed']);
    exit;
}

header('Content-Type: application/json');

try {
    $raw     = file_get_contents('php://input');
    $payload = json_decode(is_string($raw) ? $raw : '', true);
    if (!is_array($payload)) {
        $payload = [];
    }

    $pathHash    = trim((string)($payload['path_hash']       ?? ''));
    $checksum    = strtolower(trim((string)($payload['checksum_sha256'] ?? '')));
    $uploadJobId = trim((string)($payload['upload_job_id']   ?? ''));

    if (!preg_match('/^[0-9a-f]{64}$/', $pathHash)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid or missing path_hash']);
        exit;
    }
    if (!preg_match('/^[0-9a-f]{64}$/', $checksum)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid or missing checksum_sha256']);
        exit;
    }
    if ($uploadJobId === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'upload_job_id is required']);
        exit;
    }

    $pdo = Database::createFromEnv();

    // Resolve asset_id via checksum — ingestStub/ingestComplete must have run before writeback
    $assetStmt = $pdo->prepare('SELECT asset_id FROM assets WHERE checksum_sha256 = ? LIMIT 1');
    $assetStmt->execute([$checksum]);
    $assetId = $assetStmt->fetchColumn();

    if ($assetId === false) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Asset not found for checksum_sha256']);
        exit;
    }

    $upd = $pdo->prepare(
        "UPDATE catalog_entries
            SET status = 'imported', asset_id = ?, upload_job_id = ?
          WHERE path_hash = ?"
    );
    $upd->execute([(int)$assetId, $uploadJobId, $pathHash]);

    echo json_encode([
        'success'  => true,
        'asset_id' => (int)$assetId,
        'updated'  => $upd->rowCount(),
    ]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server Error', 'message' => $e->getMessage()]);
}
