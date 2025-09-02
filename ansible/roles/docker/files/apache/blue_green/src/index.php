<?php
header('Content-Type: application/json');

<?php
// Use your existing request variable (or pull from server)
$request_uri = isset($request_uri) ? (string)$request_uri : ((string)($_SERVER['REQUEST_URI'] ?? ''));

// Normalize line breaks so they can't break the log format
$input = str_replace(["\r", "\n"], ['\\r', '\\n'], $request_uri);

// Allow common RFC 3986 URI characters: unreserved + sub-delims + ":" "@" "/" "?" and "%"
if (preg_match('/[^A-Za-z0-9._~\-\/?:@!$&\'()*+,;=%]/', $input)) {
    $safeinput = '[' . base64_encode($input) . ']';
} else {
    $safeinput = $input;
}

error_log('API index.php reached! Request: ' . $safeinput);

require_once __DIR__ . '/../routes.php';

// If routes.php does not handle the request, return an error
echo json_encode(["error" => "routes.php did not handle this request"]);
exit;

