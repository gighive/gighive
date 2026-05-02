<?php declare(strict_types=1);

$user = $_SERVER['PHP_AUTH_USER']
    ?? $_SERVER['REMOTE_USER']
    ?? $_SERVER['REDIRECT_REMOTE_USER']
    ?? null;

if ($user !== 'admin') {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Forbidden']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Content-Type: application/json');
    header('Allow: POST');
    echo json_encode(['success' => false, 'error' => 'Method Not Allowed']);
    exit;
}

header('Content-Type: application/json');

$stagingDir   = '/var/iphone-import';
$sentinelFile = $stagingDir . '/.prerequisites_ok';

if (!is_dir($stagingDir)) {
    echo json_encode(['success' => false, 'error' => 'Staging directory not accessible']);
    exit;
}

$deleted = 0;
$errors  = 0;

try {
    $rit = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($stagingDir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($rit as $fileInfo) {
        $path = $fileInfo->getPathname();

        // Preserve the sentinel file
        if ($path === $sentinelFile) {
            continue;
        }

        if ($fileInfo->isFile() || $fileInfo->isLink()) {
            if (@unlink($path)) {
                $deleted++;
            } else {
                $errors++;
            }
        } elseif ($fileInfo->isDir()) {
            // Only remove if empty after file deletions
            @rmdir($path);
        }
    }
} catch (Throwable $e) {
    echo json_encode([
        'success' => false,
        'error'   => 'Failed to clear staging directory: ' . $e->getMessage(),
    ]);
    exit;
}

echo json_encode([
    'success'       => ($errors === 0),
    'deleted_count' => $deleted,
    'error_count'   => $errors,
    'message'       => $errors === 0
        ? 'Staging folder cleared. Sentinel file preserved.'
        : $deleted . ' file(s) deleted, ' . $errors . ' error(s).',
]);
