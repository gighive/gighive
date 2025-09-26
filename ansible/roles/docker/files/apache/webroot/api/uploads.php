<?php declare(strict_types=1);

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
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Method Not Allowed']);
        exit;
    }

    http_response_code($resp['status']);
    if (!empty($resp['headers'])) {
        foreach ($resp['headers'] as $h => $v) header($h . ': ' . $v);
    }

    // Decide response format. Default JSON; if UI=html or Accept prefers text/html and this was a POST,
    // render a minimal confirmation page with a link to the database.
    $ui = $_GET['ui'] ?? '';
    $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
    $wantsHtml = ($ui === 'html') || (stripos($accept, 'text/html') !== false);

    if ($method === 'POST' && $wantsHtml) {
        header_remove('Content-Type');
        header('Content-Type: text/html; charset=utf-8');
        $ok = ($resp['status'] ?? 500) >= 200 && ($resp['status'] ?? 500) < 300;
        $bodyPretty = htmlspecialchars(json_encode($resp['body'], JSON_PRETTY_PRINT), ENT_QUOTES);
        $newId = '';
        if (is_array($resp['body']) && isset($resp['body']['id'])) {
            $newId = (string)$resp['body']['id'];
        }
        $dbUrl = $newId !== '' ? ('/db/database.php#media-' . rawurlencode($newId)) : '/db/database.php#all';
        echo "<!DOCTYPE html>\n<html lang=\"en\">\n<head>\n<meta charset=\"utf-8\">\n<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">\n<title>Upload " . ($ok ? "Success" : "Status") . "</title>\n<style>body{font-family:system-ui,Arial,sans-serif;margin:20px;} pre{background:#f7f7f7;padding:12px;overflow:auto;} .status{font-weight:700;color:" . ($ok ? "#0a0" : "#a00") . ";}</style>\n</head>\n<body>\n<h1 class=\"status\">" . ($ok ? "Upload completed" : "Upload status: " . (int)($resp['status'] ?? 0)) . "</h1>\n<p><a href=\"$dbUrl\">Go to database</a></p>\n<details><summary>Response details</summary><pre>$bodyPretty</pre></details>\n</body>\n</html>";
    } else {
        header('Content-Type: application/json');
        echo json_encode($resp['body']);
    }
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Server error', 'message' => $e->getMessage()]);
}
