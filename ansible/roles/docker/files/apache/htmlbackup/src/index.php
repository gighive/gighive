<?php
header('Content-Type: application/json');

// Capture the rewritten request parameter
$request_uri = isset($_GET['request']) ? $_GET['request'] : '';

// Log the request for debugging
error_log("API index.php reached! Request: " . $request_uri);

require_once __DIR__ . '/routes.php';

// If routes.php does not handle the request, return an error
echo json_encode(["error" => "routes.php did not handle this request"]);
exit;

