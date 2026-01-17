<?php declare(strict_types=1);
/**
 * write_resize_request.php — Writes a minimal disk resize request file for later execution on the VirtualBox host.
 */

$user = $_SERVER['PHP_AUTH_USER']
     ?? $_SERVER['REMOTE_USER']
     ?? $_SERVER['REDIRECT_REMOTE_USER']
     ?? null;

if ($user !== 'admin') {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'error' => 'Forbidden',
        'message' => 'Admin access required',
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

$raw = file_get_contents('php://input');
$data = json_decode($raw ?: '', true);
if (!is_array($data)) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'error' => 'Bad Request',
        'message' => 'Invalid JSON body',
    ]);
    exit;
}

$inventoryHost = isset($data['inventory_host']) ? trim((string)$data['inventory_host']) : '';
$diskSizeMb = isset($data['disk_size_mb']) ? (int)$data['disk_size_mb'] : 0;

if ($inventoryHost === '' || !preg_match('/^[a-zA-Z0-9._-]+$/', $inventoryHost)) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'error' => 'Bad Request',
        'message' => 'Invalid inventory_host',
    ]);
    exit;
}

if ($diskSizeMb < 16384) { // 16 GiB minimum
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'error' => 'Bad Request',
        'message' => 'disk_size_mb must be at least 16384 (16 GiB)',
    ]);
    exit;
}

$requestDir = '/var/www/private/resizerequests';
try {
    if (!is_dir($requestDir)) {
        throw new RuntimeException('Request directory does not exist: ' . $requestDir);
    }
    if (!is_writable($requestDir)) {
        throw new RuntimeException('Request directory is not writable by web server user: ' . $requestDir);
    }

    $requestId = date('Ymd-His') . '-' . bin2hex(random_bytes(6));
    $requestFile = $requestDir . '/req-' . $requestId . '.json';

    $payload = [
        'kind' => 'gighive.disk_resize',
        'version' => 1,
        'inventory_host' => $inventoryHost,
        'disk_size_mb' => $diskSizeMb,
        'requested_at' => date('c'),
        'requested_by' => $user,
    ];

    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new RuntimeException('Failed to encode request JSON');
    }

    $bytes = @file_put_contents($requestFile, $json . "\n", LOCK_EX);
    if ($bytes === false) {
        throw new RuntimeException('Failed to write request file');
    }

    @chmod($requestFile, 0640);

    http_response_code(200);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'message' => 'Resize request written successfully.',
        'request_file' => basename($requestFile),
        'request' => $payload,
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
