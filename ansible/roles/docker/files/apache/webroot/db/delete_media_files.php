<?php declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Production\Api\Infrastructure\Database;
use Production\Api\Repositories\AssetRepository;
use PDO;

$user = $_SERVER['PHP_AUTH_USER']
    ?? $_SERVER['REMOTE_USER']
    ?? $_SERVER['REDIRECT_REMOTE_USER']
    ?? null;

if ($user !== 'admin' && $user !== 'uploader') {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'error' => 'Forbidden',
        'message' => 'Forbidden',
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Content-Type: application/json');
    header('Allow: POST');
    echo json_encode([
        'success' => false,
        'error' => 'Method Not Allowed',
        'message' => 'Only POST requests are accepted',
    ]);
    exit;
}

$rawBody = file_get_contents('php://input');
$payload = json_decode(is_string($rawBody) ? $rawBody : '', true);

if (!is_array($payload)) {
    $payload = [];
}

$deleteToken = null;

if ($user === 'admin') {
    if (!isset($payload['asset_ids']) || !is_array($payload['asset_ids'])) {
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'error' => 'Bad Request',
            'message' => 'Expected JSON body with asset_ids array',
        ]);
        exit;
    }

    $assetIds = [];
    foreach ($payload['asset_ids'] as $v) {
        if (is_int($v)) {
            $n = $v;
        } elseif (is_string($v) && ctype_digit($v)) {
            $n = (int)$v;
        } else {
            continue;
        }
        if ($n > 0) {
            $assetIds[$n] = true;
        }
    }
    $assetIds = array_keys($assetIds);
} else {
    $assetId = $payload['asset_id'] ?? null;
    if (is_int($assetId)) {
        $n = $assetId;
    } elseif (is_string($assetId) && ctype_digit($assetId)) {
        $n = (int)$assetId;
    } else {
        $n = 0;
    }
    $deleteToken = $payload['delete_token'] ?? null;
    $deleteToken = is_string($deleteToken) ? trim($deleteToken) : '';

    if ($n <= 0 || $deleteToken === '') {
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'error' => 'Bad Request',
            'message' => 'Expected JSON body with asset_id and delete_token',
        ]);
        exit;
    }

    $assetIds = [$n];
}

if (!$assetIds) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'error' => 'Bad Request',
        'message' => 'No valid asset_ids provided',
    ]);
    exit;
}

$shaOk = static function (?string $sha): bool {
    if ($sha === null) {
        return false;
    }
    $sha = trim($sha);
    return $sha !== '' && preg_match('/^[a-f0-9]{64}$/i', $sha) === 1;
};

$root = '/var/www/html';
$audioDir = $root . '/audio';
$videoDir = $root . '/video';
$thumbDir = $videoDir . '/thumbnails';

$dbHost = getenv('DB_HOST') ?: 'localhost';
$dbName = getenv('MYSQL_DATABASE') ?: 'music_db';

$results = [
    'deleted' => [],
    'errors' => [],
];

try {
    $pdo = Database::createFromEnv();

    $assetsRepo = new AssetRepository($pdo);

    if ($user === 'uploader') {
        $assetIdToDelete = (int)$assetIds[0];
        $storedHash = $assetsRepo->getDeleteTokenHashById($assetIdToDelete);
        $providedHash = hash('sha256', (string)$deleteToken);

        if (!is_string($storedHash) || $storedHash === '' || !hash_equals($storedHash, $providedHash)) {
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'error' => 'Forbidden',
                'message' => 'Invalid delete token',
            ]);
            exit;
        }
    }

    $select      = $pdo->prepare('SELECT asset_id, file_type, file_ext, checksum_sha256 FROM assets WHERE asset_id = :id');
    $deleteItems = $pdo->prepare('DELETE FROM event_items WHERE asset_id = :id');
    $deleteAsset = $pdo->prepare('DELETE FROM assets WHERE asset_id = :id');

    foreach ($assetIds as $id) {
        $select->execute([':id' => $id]);
        $row = $select->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            $results['errors'][] = ['asset_id' => $id, 'error' => 'Not found'];
            continue;
        }

        $type = isset($row['file_type']) ? (string)$row['file_type'] : '';
        $sha  = isset($row['checksum_sha256']) ? (string)$row['checksum_sha256'] : '';
        $ext  = isset($row['file_ext']) ? strtolower(trim((string)$row['file_ext'])) : '';

        $sha = trim($sha);
        if ($sha === '' || preg_match('/^[a-f0-9]{64}$/i', $sha) !== 1) {
            $results['errors'][] = ['asset_id' => $id, 'error' => 'Missing/invalid checksum_sha256'];
            continue;
        }

        if ($type !== 'audio' && $type !== 'video') {
            $results['errors'][] = ['asset_id' => $id, 'error' => 'Unknown file_type'];
            continue;
        }

        $served    = $ext !== '' ? ($sha . '.' . $ext) : $sha;
        $mediaPath = ($type === 'audio') ? ($audioDir . '/' . $served) : ($videoDir . '/' . $served);
        $thumbPath = $thumbDir . '/' . $sha . '.png';

        if (is_file($mediaPath)) {
            if (!@unlink($mediaPath)) {
                $results['errors'][] = ['asset_id' => $id, 'error' => 'Failed to delete media file on disk'];
                continue;
            }
        }

        if ($type === 'video' && is_file($thumbPath)) {
            if (!@unlink($thumbPath)) {
                $results['errors'][] = ['asset_id' => $id, 'error' => 'Failed to delete thumbnail on disk'];
                continue;
            }
        }

        $deleteItems->execute([':id' => $id]);
        $deleteAsset->execute([':id' => $id]);
        if ($deleteAsset->rowCount() < 1) {
            $results['errors'][] = ['asset_id' => $id, 'error' => 'Database delete did not remove any rows'];
            continue;
        }

        $results['deleted'][] = ['asset_id' => $id];
    }

    $ok = count($results['errors']) === 0;
    http_response_code(200);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => $ok,
        'db' => [
            'host' => $dbHost,
            'name' => $dbName,
        ],
        'deleted_count' => count($results['deleted']),
        'error_count' => count($results['errors']),
        'results' => $results,
    ]);

} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'error' => 'Server Error',
        'message' => $e->getMessage(),
    ]);
}
