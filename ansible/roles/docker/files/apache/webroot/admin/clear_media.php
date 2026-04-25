<?php declare(strict_types=1);
/**
 * clear_media.php — Truncates all media tables (preserves users table)
 * Admin-only endpoint for clearing demo content
 */

// Load autoloader for Database class
// In production, vendor/ is at /var/www/html/vendor/ (parent of this admin/ directory)
require_once dirname(__DIR__) . '/vendor/autoload.php';

use Production\Api\Infrastructure\Database;

/** ---- Access Gate: allow only Basic-Auth user 'admin' ---- */
$user = $_SERVER['PHP_AUTH_USER']
     ?? $_SERVER['REMOTE_USER']
     ?? $_SERVER['REDIRECT_REMOTE_USER']
     ?? null;

if ($user !== 'admin') {
    $response = [
        'status' => 403,
        'headers' => ['Content-Type' => 'application/json'],
        'body' => [
            'success' => false,
            'error' => 'Forbidden',
            'message' => 'Admin access required'
        ]
    ];
    http_response_code($response['status']);
    foreach ($response['headers'] as $h => $v) {
        header("$h: $v");
    }
    echo json_encode($response['body']);
    exit;
}

/** ---- Only accept POST requests ---- */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $response = [
        'status' => 405,
        'headers' => ['Content-Type' => 'application/json', 'Allow' => 'POST'],
        'body' => [
            'success' => false,
            'error' => 'Method Not Allowed',
            'message' => 'Only POST requests are accepted'
        ]
    ];
    http_response_code($response['status']);
    foreach ($response['headers'] as $h => $v) {
        header("$h: $v");
    }
    echo json_encode($response['body']);
    exit;
}

/** ---- Execute truncation ---- */
$pdo = null;
try {
    error_log("clear_media.php: Starting truncation process");
    $pdo = Database::createFromEnv();
    error_log("clear_media.php: Database connection established");
    
    // Note: TRUNCATE is a DDL statement that auto-commits, so we can't use transactions
    // Disable foreign key checks temporarily
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    error_log("clear_media.php: Foreign key checks disabled");

    // Truncate canonical junction tables first (FK dependents)
    $pdo->exec('TRUNCATE TABLE event_participants');
    $pdo->exec('TRUNCATE TABLE event_items');
    error_log("clear_media.php: Junction tables truncated");

    // Truncate canonical core media tables
    $pdo->exec('TRUNCATE TABLE assets');
    $pdo->exec('TRUNCATE TABLE events');
    $pdo->exec('TRUNCATE TABLE participants');
    error_log("clear_media.php: Core media tables truncated");

    // Re-enable foreign key checks
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    error_log("clear_media.php: Foreign key checks re-enabled - all tables cleared successfully");

    $response = [
        'status' => 200,
        'headers' => ['Content-Type' => 'application/json'],
        'body' => [
            'success' => true,
            'message' => 'All media tables cleared successfully. Users table preserved.',
            'tables_cleared' => [
                'junction'   => ['event_participants', 'event_items'],
                'media'      => ['assets', 'events', 'participants'],
            ]
        ]
    ];
    
} catch (\PDOException $e) {
    error_log("clear_media.php: PDOException caught: " . $e->getMessage());
    
    $response = [
        'status' => 500,
        'headers' => ['Content-Type' => 'application/json'],
        'body' => [
            'success' => false,
            'error' => 'Database Error',
            'message' => 'Failed to clear media tables: ' . $e->getMessage()
        ]
    ];
    
} catch (\Throwable $e) {
    error_log("clear_media.php: Throwable caught: " . $e->getMessage());
    
    $response = [
        'status' => 500,
        'headers' => ['Content-Type' => 'application/json'],
        'body' => [
            'success' => false,
            'error' => 'Server Error',
            'message' => $e->getMessage()
        ]
    ];
}

/** ---- Send response ---- */
http_response_code($response['status']);
foreach ($response['headers'] as $h => $v) {
    header("$h: $v");
}
echo json_encode($response['body']);
