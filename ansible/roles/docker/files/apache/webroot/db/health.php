<?php declare(strict_types=1);
/**
 * Database health check endpoint - NO AUTH REQUIRED
 * Returns only success/failure status, no sensitive data
 */

require __DIR__ . '/../vendor/autoload.php';

use Production\Api\Infrastructure\Database;

header('Content-Type: application/json');

try {
    $pdo = Database::createFromEnv();
    
    // Simple connectivity test
    $stmt = $pdo->query('SELECT 1');
    $result = $stmt->fetch();
    
    if ($result) {
        http_response_code(200);
        echo json_encode([
            'status' => 'ok',
            'message' => 'Database connection successful'
        ]);
    } else {
        throw new \Exception('Query returned no results');
    }
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Database connection failed',
        'error' => $e->getMessage()
    ]);
}
