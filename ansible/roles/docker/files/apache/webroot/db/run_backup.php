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
        'error'   => 'Forbidden',
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Content-Type: application/json');
    header('Allow: POST');
    echo json_encode([
        'success' => false,
        'error'   => 'Method Not Allowed',
    ]);
    exit;
}

$backupDir = getenv('GIGHIVE_MYSQL_BACKUPS_DIR') ?: '';
$logDir    = getenv('GIGHIVE_MYSQL_RESTORE_LOG_DIR') ?: '';

if ($backupDir === '' || $logDir === '') {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'error'   => 'Backup paths not configured (missing env vars).',
    ]);
    exit;
}

try {
    if (!is_dir($backupDir) || !is_readable($backupDir)) {
        throw new RuntimeException('Backups directory not readable: ' . $backupDir);
    }
    if (!is_writable($backupDir)) {
        throw new RuntimeException('Backups directory not writable by web server user: ' . $backupDir);
    }
    if (!is_dir($logDir)) {
        throw new RuntimeException('Log directory does not exist: ' . $logDir);
    }
    if (!is_writable($logDir)) {
        throw new RuntimeException('Log directory not writable by web server user: ' . $logDir);
    }

    $dbName = getenv('MYSQL_DATABASE') ?: '';
    $dbHost = getenv('DB_HOST') ?: '';
    $dbPort = getenv('DB_PORT') ?: '3306';

    // Prefer root if present, else app user.
    $dbUser   = '';
    $dbPass   = '';
    $rootPass = getenv('MYSQL_ROOT_PASSWORD') ?: '';
    if ($rootPass !== '') {
        $dbUser = 'root';
        $dbPass = $rootPass;
    } else {
        $dbUser = getenv('MYSQL_USER') ?: '';
        $dbPass = getenv('MYSQL_PASSWORD') ?: '';
    }

    if (!$dbHost || !$dbUser || !$dbPass || !$dbName) {
        throw new RuntimeException('Database connection env vars missing (DB_HOST/MYSQL_*).');
    }

    $jobId   = date('Ymd-His') . '-' . bin2hex(random_bytes(6));
    $logFile = rtrim($logDir, '/') . '/backup-' . $jobId . '.log';
    $rcFile  = rtrim($logDir, '/') . '/backup-' . $jobId . '.rc';
    $pidFile = rtrim($logDir, '/') . '/backup-' . $jobId . '.pid';

    // Ensure files don't already exist (extremely unlikely).
    if (file_exists($logFile) || file_exists($rcFile) || file_exists($pidFile)) {
        throw new RuntimeException('Backup job collision; please retry.');
    }

    $header  = "START " . date('c') . "\n";
    $header .= "Target DB: {$dbName}\n";
    $header .= "Host: {$dbHost}:{$dbPort}\n";
    $header .= "User: {$dbUser}\n\n";

    if (@file_put_contents($logFile, $header, LOCK_EX) === false) {
        throw new RuntimeException('Failed to create backup log file.');
    }
    @chmod($logFile, 0640);

    $script = implode('; ', [
        'set -Eeuo pipefail',
        'umask 027',
        'echo "INFO: backup job started"',
        '__BACKUPS_DIR=' . escapeshellarg(rtrim($backupDir, '/')),
        '__DB_NAME=' . escapeshellarg($dbName),
        'STAMP=$(date +"%Y-%m-%d_%H%M%S")',
        'OUTFILE="${__BACKUPS_DIR}/${__DB_NAME}_${STAMP}.sql.gz"',
        'echo "INFO: running mysqldump to ${OUTFILE}"',
        // Use MYSQL_PWD to avoid showing password in process args.
        'MYSQL_PWD=' . escapeshellarg($dbPass)
            . ' mysqldump -h' . escapeshellarg($dbHost)
            . ' -P' . escapeshellarg((string)$dbPort)
            . ' -u' . escapeshellarg($dbUser)
            . ' --single-transaction --quick --lock-tables=0 --routines --events --triggers --default-character-set=utf8mb4'
            . ' --databases ' . escapeshellarg($dbName)
            . ' | gzip > "${OUTFILE}"',
        'echo "INFO: verifying gzip integrity"',
        'gzip -t "${OUTFILE}"',
        'echo "INFO: gzip integrity check OK"',
        'echo "OK: wrote $(stat -c%s ${OUTFILE}) bytes to ${OUTFILE}"',
        'ln -sfn "$(basename ${OUTFILE})" "${__BACKUPS_DIR}/${__DB_NAME}_latest.sql.gz"',
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
        throw new RuntimeException('Failed to start backup process.');
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
        'job_id'  => $jobId,
    ]);

} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'error'   => $e->getMessage(),
    ]);
}
