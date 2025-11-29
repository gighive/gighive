<?php declare(strict_types=1);
/**
 * clear_media.php — Truncates all media tables (preserves users table)
 * Admin-only endpoint for clearing demo content
 */

// Load autoloader for Database class
// In production, overlays/gighive files are copied to /var/www/html/
// vendor/ is also in /var/www/html/, so same directory
require_once __DIR__ . '/vendor/autoload.php';

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
try {
    $pdo = Database::createFromEnv();
    
    // Begin transaction for atomicity
    $pdo->beginTransaction();
    
    // Disable foreign key checks temporarily
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    
    // Truncate junction tables first
    $pdo->exec('TRUNCATE TABLE session_musicians');
    $pdo->exec('TRUNCATE TABLE session_songs');
    $pdo->exec('TRUNCATE TABLE song_files');
    
    // Truncate core media tables
    $pdo->exec('TRUNCATE TABLE files');
    $pdo->exec('TRUNCATE TABLE songs');
    $pdo->exec('TRUNCATE TABLE sessions');
    
    // Truncate reference tables
    $pdo->exec('TRUNCATE TABLE musicians');
    $pdo->exec('TRUNCATE TABLE genres');
    $pdo->exec('TRUNCATE TABLE styles');
    
    // Re-enable foreign key checks
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    
    // Commit transaction
    $pdo->commit();
    
    $response = [
        'status' => 200,
        'headers' => ['Content-Type' => 'application/json'],
        'body' => [
            'success' => true,
            'message' => 'All media tables cleared successfully. Users table preserved.',
            'tables_cleared' => [
                'junction' => ['session_musicians', 'session_songs', 'song_files'],
                'media' => ['files', 'songs', 'sessions'],
                'reference' => ['musicians', 'genres', 'styles']
            ]
        ]
    ];
    
} catch (\PDOException $e) {
    // Rollback on database error
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    
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
    // Rollback on any other error
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    
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
