<?php declare(strict_types=1);

$user = $_SERVER['PHP_AUTH_USER']
     ?? $_SERVER['REMOTE_USER']
     ?? $_SERVER['REDIRECT_REMOTE_USER']
     ?? null;

if ($user !== 'admin') {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'error' => 'Forbidden',
        'message' => 'Admin access required',
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Content-Type: application/json');
    header('Allow: POST');
    echo json_encode([
        'success' => false,
        'error' => 'Method Not Allowed',
        'message' => 'Only POST requests are accepted',
    ]);
    exit;
}

$raw = file_get_contents('php://input');
$data = json_decode($raw ?: '', true);
if (!is_array($data)) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'error' => 'Bad Request',
        'message' => 'Invalid JSON body',
    ]);
    exit;
}

$filename = isset($data['filename']) ? trim((string)$data['filename']) : '';
$confirm = isset($data['confirm']) ? trim((string)$data['confirm']) : '';

if ($confirm !== 'RESTORE') {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'error' => 'Bad Request',
        'message' => 'Confirmation phrase mismatch (type RESTORE).',
    ]);
    exit;
}

$backupDir = getenv('GIGHIVE_MYSQL_BACKUPS_DIR') ?: '';
$logDir = getenv('GIGHIVE_MYSQL_RESTORE_LOG_DIR') ?: '';

if ($backupDir === '' || $logDir === '') {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'error' => 'Server Error',
        'message' => 'Restore paths not configured (missing env vars).',
    ]);
    exit;
}

try {
    if (!is_dir($backupDir) || !is_readable($backupDir)) {
        throw new RuntimeException('Backups directory not readable: ' . $backupDir);
    }
    if (!is_dir($logDir)) {
        throw new RuntimeException('Restore log directory does not exist: ' . $logDir);
    }
    if (!is_writable($logDir)) {
        throw new RuntimeException('Restore log directory not writable by web server user: ' . $logDir);
    }

    // Validate filename: basename only, no paths.
    if ($filename === '' || basename($filename) !== $filename) {
        throw new RuntimeException('Invalid filename.');
    }

    $dbName = getenv('MYSQL_DATABASE') ?: '';
    if ($dbName === '') {
        throw new RuntimeException('MYSQL_DATABASE is not set in environment.');
    }

    $re = '/^' . preg_quote($dbName, '/') . '_(?:latest|\d{4}-\d{2}-\d{2}_\d{6})\.sql\.gz$/';
    if (!preg_match($re, $filename)) {
        throw new RuntimeException('Filename is not an allowed backup file for this database.');
    }

    $backupPath = rtrim($backupDir, '/') . '/' . $filename;
    if (!is_file($backupPath) || !is_readable($backupPath)) {
        throw new RuntimeException('Backup file not found or not readable: ' . $filename);
    }

    $dbHost = getenv('DB_HOST') ?: '';
    $dbPort = getenv('DB_PORT') ?: '3306';

    // Prefer root if present, else app user.
    $dbUser = '';
    $dbPass = '';
    $rootPass = getenv('MYSQL_ROOT_PASSWORD') ?: '';
    if ($rootPass !== '') {
        $dbUser = 'root';
        $dbPass = $rootPass;
    } else {
        $dbUser = getenv('MYSQL_USER') ?: '';
        $dbPass = getenv('MYSQL_PASSWORD') ?: '';
    }

    if ($dbHost === '' || $dbUser === '' || $dbPass === '') {
        throw new RuntimeException('Database connection env vars missing (DB_HOST/MYSQL_*).');
    }

    $jobId = gmdate('Ymd-His') . '-' . bin2hex(random_bytes(6));
    $logFile = rtrim($logDir, '/') . '/restore-' . $jobId . '.log';
    $rcFile = rtrim($logDir, '/') . '/restore-' . $jobId . '.rc';
    $pidFile = rtrim($logDir, '/') . '/restore-' . $jobId . '.pid';

    // Ensure files don't already exist (extremely unlikely).
    if (file_exists($logFile) || file_exists($rcFile) || file_exists($pidFile)) {
        throw new RuntimeException('Restore job collision; please retry.');
    }

    $header = "START " . gmdate('c') . "\n";
    $header .= "Selected backup: {$filename}\n";
    $header .= "Target DB: {$dbName}\n";
    $header .= "Host: {$dbHost}:{$dbPort}\n";
    $header .= "User: {$dbUser}\n\n";

    if (@file_put_contents($logFile, $header, LOCK_EX) === false) {
        throw new RuntimeException('Failed to create restore log file.');
    }
    @chmod($logFile, 0640);

    $script = implode('; ', [
        'set -Eeuo pipefail',
        'umask 027',
        'echo "' . str_replace('"', '\\"', 'INFO: restore job started') . '"',
        'gzip -t -- ' . escapeshellarg($backupPath),
        'echo "INFO: gzip integrity check OK"',
        // Use MYSQL_PWD to avoid showing password in process args.
        // IMPORTANT: In a pipeline, VAR=cmd only applies to that cmd, so apply it to mysql.
        'zcat -- ' . escapeshellarg($backupPath)
            . ' | MYSQL_PWD=' . escapeshellarg($dbPass)
            . ' mysql -h' . escapeshellarg($dbHost)
            . ' -P' . escapeshellarg((string)$dbPort)
            . ' -u' . escapeshellarg($dbUser)
            . ' --default-character-set=utf8mb4',
        'rc=$?',
        'echo "EXIT_CODE=${rc}"',
        'echo "$rc" > ' . escapeshellarg($rcFile),
        'exit 0',
    ]);

    $cmd = "bash -lc " . escapeshellarg(
        "( { {$script}; } >>" . escapeshellarg($logFile) . " 2>&1; ) & echo $! > " . escapeshellarg($pidFile)
    );

    $proc = @proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($proc)) {
        throw new RuntimeException('Failed to start restore process.');
    }

    foreach ($pipes as $p) {
        if (is_resource($p)) {
            fclose($p);
        }
    }

    proc_close($proc);

    http_response_code(200);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'job_id' => $jobId,
        'message' => 'Restore started. Monitor logs for progress.',
    ]);

} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'error' => 'Server Error',
        'message' => $e->getMessage(),
    ]);
}
