<?php declare(strict_types=1);
header('Content-Type: application/json');

require __DIR__ . '/../vendor/autoload.php';

use Production\Api\Controllers\UploadController;
use Production\Api\Infrastructure\Database;

try {
    $pdo = Database::createFromEnv();
    $controller = new UploadController($pdo);

    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $pathInfo = $_SERVER['PATH_INFO'] ?? '';
    $matches = [];
    if ($method === 'GET' && preg_match('#/([0-9]+)$#', $pathInfo, $matches)) {
        $id = (int)$matches[1];
        $resp = $controller->get($id);
    } elseif ($method === 'POST') {
        $resp = $controller->post($_FILES, $_POST);
    } else {
        http_response_code(405);
        echo json_encode(['error' => 'Method Not Allowed']);
        exit;
    }

    http_response_code($resp['status']);
    if (!empty($resp['headers'])) {
        foreach ($resp['headers'] as $h => $v) header($h . ': ' . $v);
    }
    echo json_encode($resp['body']);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Server error', 'message' => $e->getMessage()]);
}
