<?php declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Production\Api\Infrastructure\Database;
use Production\Api\Repositories\FileRepository;
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
    if (!isset($payload['file_ids']) || !is_array($payload['file_ids'])) {
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'error' => 'Bad Request',
            'message' => 'Expected JSON body with file_ids array',
        ]);
        exit;
    }

    $fileIds = [];
    foreach ($payload['file_ids'] as $v) {
        if (is_int($v)) {
            $n = $v;
        } elseif (is_string($v) && ctype_digit($v)) {
            $n = (int)$v;
        } else {
            continue;
        }
        if ($n > 0) {
            $fileIds[$n] = true;
        }
    }
    $fileIds = array_keys($fileIds);
} else {
    $fileId = $payload['file_id'] ?? null;
    if (is_int($fileId)) {
        $n = $fileId;
    } elseif (is_string($fileId) && ctype_digit($fileId)) {
        $n = (int)$fileId;
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
            'message' => 'Expected JSON body with file_id and delete_token',
        ]);
        exit;
    }

    $fileIds = [$n];
}

if (!$fileIds) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'error' => 'Bad Request',
        'message' => 'No valid file_ids provided',
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

$extFromPath = static function (?string $path): string {
    if ($path === null) {
        return '';
    }
    $path = trim($path);
    if ($path === '') {
        return '';
    }
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    return $ext !== '' ? $ext : '';
};

$servedName = static function (string $sha, ?string $sourceRelpath, ?string $fallbackFileName) use ($extFromPath): string {
    $ext = $extFromPath($sourceRelpath);
    if ($ext === '') {
        $ext = $extFromPath($fallbackFileName);
    }
    return $ext !== '' ? ($sha . '.' . $ext) : $sha;
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

    $filesRepo = new FileRepository($pdo);

    if ($user === 'uploader') {
        $fileIdToDelete = (int)$fileIds[0];
        $storedHash = $filesRepo->getDeleteTokenHashById($fileIdToDelete);
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

    $select = $pdo->prepare('SELECT file_id, file_type, file_name, source_relpath, checksum_sha256 FROM files WHERE file_id = :id');
    $delete = $pdo->prepare('DELETE FROM files WHERE file_id = :id');

    foreach ($fileIds as $id) {
        $select->execute([':id' => $id]);
        $row = $select->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            $results['errors'][] = ['file_id' => $id, 'error' => 'Not found'];
            continue;
        }

        $type = isset($row['file_type']) ? (string)$row['file_type'] : '';
        $sha = isset($row['checksum_sha256']) ? (string)$row['checksum_sha256'] : '';
        $sourceRelpath = isset($row['source_relpath']) ? (string)$row['source_relpath'] : '';
        $fileName = isset($row['file_name']) ? (string)$row['file_name'] : '';

        if (!$shaOk($sha)) {
            $results['errors'][] = ['file_id' => $id, 'error' => 'Missing/invalid checksum_sha256 (checksum-only delete)'];
            continue;
        }

        if ($type !== 'audio' && $type !== 'video') {
            $results['errors'][] = ['file_id' => $id, 'error' => 'Unknown file_type'];
            continue;
        }

        $served = $servedName($sha, $sourceRelpath !== '' ? $sourceRelpath : null, $fileName !== '' ? $fileName : null);
        $mediaPath = ($type === 'audio') ? ($audioDir . '/' . $served) : ($videoDir . '/' . $served);
        $thumbPath = $thumbDir . '/' . $sha . '.png';

        if (is_file($mediaPath)) {
            if (!@unlink($mediaPath)) {
                $results['errors'][] = ['file_id' => $id, 'error' => 'Failed to delete media file on disk'];
                continue;
            }
        }

        if ($type === 'video' && is_file($thumbPath)) {
            if (!@unlink($thumbPath)) {
                $results['errors'][] = ['file_id' => $id, 'error' => 'Failed to delete thumbnail on disk'];
                continue;
            }
        }

        $delete->execute([':id' => $id]);
        if ($delete->rowCount() < 1) {
            $results['errors'][] = ['file_id' => $id, 'error' => 'Database delete did not remove any rows'];
            continue;
        }

        $results['deleted'][] = ['file_id' => $id];
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
