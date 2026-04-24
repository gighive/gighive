<?php declare(strict_types=1);
namespace Production\Api\Controllers;

use Production\Api\Controllers\RandomController;
use Production\Api\Infrastructure\Database;
use Production\Api\Repositories\AssetRepository;
use GuzzleHttp\Psr7\Response;

require __DIR__ . '/../vendor/autoload.php';

ini_set('display_errors', '1');
error_reporting(E_ALL);

try {
    $pdo        = Database::createFromEnv();
    $assetRepo  = new AssetRepository($pdo);
    $controller = new RandomController($assetRepo);
    $response   = $controller->playRandom();
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
