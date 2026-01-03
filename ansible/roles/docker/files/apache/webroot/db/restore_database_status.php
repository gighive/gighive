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

$jobId = isset($_GET['job_id']) ? trim((string)$_GET['job_id']) : '';
$offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;
if ($offset < 0) {
    $offset = 0;
}

if ($jobId === '' || !preg_match('/^[0-9]{8}-[0-9]{6}-[a-f0-9]{12}$/', $jobId)) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'error' => 'Bad Request',
        'message' => 'Invalid job_id',
    ]);
    exit;
}

$logDir = getenv('GIGHIVE_MYSQL_RESTORE_LOG_DIR') ?: '';
if ($logDir === '') {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'error' => 'Server Error',
        'message' => 'Restore log directory not configured (missing env var).',
    ]);
    exit;
}

$logFile = rtrim($logDir, '/') . '/restore-' . $jobId . '.log';
$rcFile = rtrim($logDir, '/') . '/restore-' . $jobId . '.rc';
$pidFile = rtrim($logDir, '/') . '/restore-' . $jobId . '.pid';

try {
    if (!is_file($logFile) || !is_readable($logFile)) {
        throw new RuntimeException('Log file not found for job_id.');
    }

    $state = 'running';
    $exitCode = null;

    if (is_file($rcFile) && is_readable($rcFile)) {
        $rawRc = trim((string)file_get_contents($rcFile));
        if (preg_match('/^-?\d+$/', $rawRc)) {
            $exitCode = (int)$rawRc;
            $state = ($exitCode === 0) ? 'ok' : 'error';
        }
    } else {
        // Best-effort running check via pid file.
        if (is_file($pidFile) && is_readable($pidFile)) {
            $rawPid = trim((string)file_get_contents($pidFile));
            if (preg_match('/^\d+$/', $rawPid)) {
                $pid = (int)$rawPid;
                if ($pid > 0 && !is_dir('/proc/' . $pid)) {
                    // Process ended but rc file not present (unexpected). Mark as error.
                    $state = 'error';
                }
            }
        }
    }

    $fh = fopen($logFile, 'rb');
    if ($fh === false) {
        throw new RuntimeException('Failed to open log file.');
    }

    $stat = fstat($fh);
    $size = is_array($stat) && isset($stat['size']) ? (int)$stat['size'] : 0;
    if ($offset > $size) {
        $offset = $size;
    }

    if (fseek($fh, $offset) !== 0) {
        // If fseek fails, fall back to start.
        $offset = 0;
        fseek($fh, 0);
    }

    $maxBytes = 131072; // 128KiB per poll
    $data = '';
    while (!feof($fh) && strlen($data) < $maxBytes) {
        $chunk = fread($fh, min(8192, $maxBytes - strlen($data)));
        if ($chunk === false || $chunk === '') {
            break;
        }
        $data .= $chunk;
    }

    $newOffset = $offset + strlen($data);
    fclose($fh);

    http_response_code(200);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'job_id' => $jobId,
        'state' => $state,
        'exit_code' => $exitCode,
        'offset' => $newOffset,
        'log_chunk' => $data,
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
