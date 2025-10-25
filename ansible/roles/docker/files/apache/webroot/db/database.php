<?php declare(strict_types=1);
namespace Production\Api\Controllers;

use Production\Api\Controllers\MediaController;
use Production\Api\Infrastructure\Database;
use Production\Api\Repositories\SessionRepository;
use GuzzleHttp\Psr7\Response;

require __DIR__ . '/../vendor/autoload.php';

ini_set('display_errors', '1');
error_reporting(E_ALL);

try {
    $pdo        = Database::createFromEnv();
    $repo       = new SessionRepository($pdo);
    $controller = new MediaController($repo);

    // Check if JSON format is requested via query parameter
    $wantsJson = isset($_GET['format']) && $_GET['format'] === 'json';

    // Route to appropriate method
    $response = $wantsJson ? $controller->listJson() : $controller->list();
} catch (\Throwable $e) {
    $body = json_encode([
        'error'   => 'Internal Server Error',
        'message' => $e->getMessage(),
    ]);
    $response = new Response(500, ['Content-Type' => 'application/json'], $body);
}

http_response_code($response->getStatusCode());
foreach ($response->getHeaders() as $header => $values) {
    foreach ($values as $value) {
        header("$header: $value", false);
    }
}
echo $response->getBody();

