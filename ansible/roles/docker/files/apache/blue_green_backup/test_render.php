<?php
require __DIR__ . '/vendor/autoload.php';

use Production\Api\Presentation\ViewRenderer;

$renderer = new ViewRenderer();
$response = $renderer->render('media/list.php', ['rows' => []]);
http_response_code($response->getStatusCode());
header('Content-Type: ' . $response->getHeaderLine('Content-Type'));
echo $response->getBody();
