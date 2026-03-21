<?php

declare(strict_types=1);

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'POST') {
    http_response_code(405);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'method_not_allowed']);
    exit;
}

$raw = file_get_contents('php://input');
if ($raw === false || $raw === '') {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'empty_body']);
    exit;
}

$payload = json_decode($raw, true);
if (!is_array($payload)) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'invalid_json']);
    exit;
}

$required = [
    'event_name',
    'app_version',
    'install_channel',
    'install_method',
    'app_flavor',
    'timestamp',
    'install_id',
];

foreach ($required as $field) {
    if (!array_key_exists($field, $payload) || !is_string($payload[$field]) || trim($payload[$field]) === '') {
        http_response_code(422);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'missing_or_invalid_field', 'field' => $field]);
        exit;
    }
}

if (!in_array($payload['event_name'], ['install_attempt', 'install_success'], true)) {
    http_response_code(422);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'invalid_event_name']);
    exit;
}

if (!in_array($payload['install_channel'], ['full', 'quickstart'], true)) {
    http_response_code(422);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'invalid_install_channel']);
    exit;
}

$timestamp = strtotime($payload['timestamp']);
if ($timestamp === false) {
    http_response_code(422);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'invalid_timestamp']);
    exit;
}

$countryCode = null;
foreach (['HTTP_CF_IPCOUNTRY', 'GEOIP_COUNTRY_CODE', 'HTTP_X_COUNTRY_CODE'] as $headerName) {
    if (!empty($_SERVER[$headerName]) && is_string($_SERVER[$headerName])) {
        $candidate = strtoupper(trim($_SERVER[$headerName]));
        if (preg_match('/^[A-Z]{2}$/', $candidate) === 1) {
            $countryCode = $candidate;
            break;
        }
    }
}

$dsn = sprintf(
    'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
    getenv('DB_HOST') ?: 'telemetry_db',
    getenv('DB_PORT') ?: '3306',
    getenv('MYSQL_DATABASE') ?: 'installation_telemetry'
);

try {
    $pdo = new PDO(
        $dsn,
        getenv('MYSQL_USER') ?: '',
        getenv('MYSQL_PASSWORD') ?: '',
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );

    $stmt = $pdo->prepare(
        'INSERT INTO installation_events (event_name, app_version, install_channel, install_method, app_flavor, install_id, event_timestamp, country_code)
         VALUES (:event_name, :app_version, :install_channel, :install_method, :app_flavor, :install_id, :event_timestamp, :country_code)'
    );

    $stmt->execute([
        ':event_name' => $payload['event_name'],
        ':app_version' => $payload['app_version'],
        ':install_channel' => $payload['install_channel'],
        ':install_method' => $payload['install_method'],
        ':app_flavor' => $payload['app_flavor'],
        ':install_id' => $payload['install_id'],
        ':event_timestamp' => gmdate('Y-m-d H:i:s', $timestamp),
        ':country_code' => $countryCode,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'storage_failed']);
    exit;
}

http_response_code(204);
