<?php declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Production\Api\Infrastructure\Database;
use PDO;

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

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    header('Content-Type: application/json');
    header('Allow: GET');
    echo json_encode(['success' => false, 'error' => 'Method Not Allowed']);
    exit;
}

header('Content-Type: application/json');

$scanId   = (int)($_GET['scan_id']      ?? 0);
$page     = max(1, (int)($_GET['page']  ?? 1));
$limit    = max(1, min(500, (int)($_GET['limit'] ?? 100)));
$fileType = trim((string)($_GET['file_type']     ?? ''));
$status   = trim((string)($_GET['status']        ?? ''));
$isSupp   = trim((string)($_GET['is_supported']  ?? ''));
$offset   = ($page - 1) * $limit;

if ($scanId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'scan_id is required']);
    exit;
}

try {
    $pdo = Database::createFromEnv();

    $scanStmt = $pdo->prepare('SELECT * FROM catalog_scans WHERE scan_id = ? LIMIT 1');
    $scanStmt->execute([$scanId]);
    $scan = $scanStmt->fetch(PDO::FETCH_ASSOC);
    if (!$scan) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Scan not found']);
        exit;
    }

    $where  = ['scan_id = ?'];
    $params = [$scanId];

    if (in_array($fileType, ['audio', 'video', 'unknown'], true)) {
        $where[] = 'file_type = ?';
        $params[] = $fileType;
    }
    if (in_array($status, ['cataloged', 'selected', 'skipped', 'imported', 'failed'], true)) {
        $where[] = 'status = ?';
        $params[] = $status;
    }
    if ($isSupp === '0' || $isSupp === '1') {
        $where[] = 'is_supported = ?';
        $params[] = (int)$isSupp;
    }

    $whereClause = 'WHERE ' . implode(' AND ', $where);

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM catalog_entries $whereClause");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    $listParams   = array_merge($params, [$limit, $offset]);
    $listStmt     = $pdo->prepare(
        "SELECT * FROM catalog_entries $whereClause ORDER BY source_relpath ASC LIMIT ? OFFSET ?"
    );
    $listStmt->execute($listParams);
    $entries = $listStmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'scan'    => $scan,
        'entries' => $entries,
        'total'   => $total,
        'page'    => $page,
        'limit'   => $limit,
        'pages'   => (int)ceil($total / max(1, $limit)),
    ]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server Error', 'message' => $e->getMessage()]);
}
