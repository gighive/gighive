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
    // Disable foreign key checks temporarily to allow truncation in any order
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    error_log("clear_media.php: Foreign key checks disabled");

    // Dynamically truncate all media/content tables.
    // SHOW TABLES bypasses the information_schema cache and only returns physically
    // existing tables, making this safe to run immediately after a DB restore.
    // Excluded tables must survive a media wipe:
    //   users   — application credentials
    //   tenants — SaaS seed row (tenant_id=1); wiping this breaks all FK constraints
    //             on events, assets, upload_jobs, etc. See docs/placeholder_delete_tables_minimal.md.
    $allTables = $pdo->query("SHOW TABLES")->fetchAll(\PDO::FETCH_COLUMN);
    $excluded  = ['users', 'tenants'];
    $tables    = array_values(array_filter($allTables, fn($t) => !in_array($t, $excluded, true)));

    foreach ($tables as $t) {
        $pdo->exec('TRUNCATE TABLE `' . $t . '`');
        error_log("clear_media.php: Truncated table: " . $t);
    }

    // Re-enable foreign key checks
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    error_log("clear_media.php: Foreign key checks re-enabled - " . count($tables) . " table(s) cleared");

    $response = [
        'status' => 200,
        'headers' => ['Content-Type' => 'application/json'],
        'body' => [
            'success' => true,
            'message' => 'All tables cleared successfully (users table preserved). ' . count($tables) . ' table(s) truncated.',
            'tables_cleared' => $tables,
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
