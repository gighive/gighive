<?php declare(strict_types=1);
namespace Production\Api\Controllers;

use Production\Api\Controllers\MediaController;
use Production\Api\Infrastructure\Database;
use Production\Api\Repositories\AssetRepository;
use Production\Api\Repositories\EventRepository;
use GuzzleHttp\Psr7\Response;

require __DIR__ . '/../vendor/autoload.php';

ini_set('display_errors', '1');
error_reporting(E_ALL);

try {
    $pdo        = Database::createFromEnv();
    $assetRepo  = new AssetRepository($pdo);
    $eventRepo  = new EventRepository($pdo);
    $controller = new MediaController($assetRepo, $eventRepo);

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

