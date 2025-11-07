<?php declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Production\Api\Controllers\UploadController;
use Production\Api\Infrastructure\Database;

// Debug logging function
function debug_log($message) {
    error_log("[SRC_ROUTER_DEBUG] " . $message);
}

try {
    $pdo = Database::createFromEnv();
    $controller = new UploadController($pdo);

    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $requestUri = $_SERVER['REQUEST_URI'] ?? '';
    
    // Debug: Log incoming request
    debug_log("Incoming request: $method $requestUri");
    debug_log("SERVER vars: " . json_encode([
        'REQUEST_URI' => $_SERVER['REQUEST_URI'] ?? 'null',
        'SCRIPT_NAME' => $_SERVER['SCRIPT_NAME'] ?? 'null',
        'PATH_INFO' => $_SERVER['PATH_INFO'] ?? 'null'
    ]));
    
    // Remove /src or /api prefix and any query string
    $path = parse_url($requestUri, PHP_URL_PATH);
    $path = preg_replace('#^/(src|api)#', '', $path);
    
    debug_log("Parsed path after prefix removal: '$path'");
    
    // Route matching
    $matches = [];
    if ($method === 'GET' && preg_match('#^/uploads/([0-9]+)$#', $path, $matches)) {
        // GET /src/uploads/{id}
        debug_log("Matched GET route for uploads/{id}");
        $id = (int)$matches[1];
        $resp = $controller->get($id);
    } elseif ($method === 'POST' && $path === '/uploads') {
        // POST /src/uploads
        debug_log("Matched POST route for /uploads");
        $resp = $controller->post($_FILES, $_POST);
    } else {
        // 404 for unmatched routes
        debug_log("No route matched! Method: $method, Path: '$path'");
        debug_log("Available routes: GET /uploads/{id}, POST /uploads");
        http_response_code(404);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Not Found', 'debug' => ['method' => $method, 'path' => $path]]);
        exit;
    }

    // Send response (same logic as /api/uploads.php)
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
