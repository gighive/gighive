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

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    header('Content-Type: application/json');
    header('Allow: GET');
    echo json_encode([
        'success' => false,
        'error' => 'Method Not Allowed',
        'message' => 'Only GET requests are accepted',
    ]);
    exit;
}

$mode = isset($_GET['mode']) ? strtolower(trim((string)$_GET['mode'])) : '';
if (!in_array($mode, ['add', 'reload'], true)) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'error' => 'Bad Request',
        'message' => 'Invalid mode (expected add or reload)',
    ]);
    exit;
}

$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 25;
if ($limit < 1) $limit = 1;
if ($limit > 100) $limit = 100;

$jobRoot = '/var/www/private/import_jobs';

try {
    if (!is_dir($jobRoot) || !is_readable($jobRoot)) {
        http_response_code(200);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'mode' => $mode,
            'jobs' => [],
        ]);
        exit;
    }

    $jobs = [];
    $it = new DirectoryIterator($jobRoot);
    foreach ($it as $fi) {
        if (!$fi->isDir() || $fi->isDot()) {
            continue;
        }
        $jobId = $fi->getFilename();
        if (!preg_match('/^[0-9]{8}-[0-9]{6}-[a-f0-9]{12}$/', $jobId)) {
            continue;
        }
        $dir = $fi->getPathname();
        $metaPath = $dir . '/meta.json';
        if (!is_file($metaPath) || !is_readable($metaPath)) {
            continue;
        }
        $metaRaw = file_get_contents($metaPath);
        $meta = json_decode($metaRaw ?: '', true);
        if (!is_array($meta)) {
            continue;
        }
        if (($meta['job_type'] ?? '') !== 'manifest_import') {
            continue;
        }
        if (($meta['mode'] ?? '') !== $mode) {
            continue;
        }

        $resPath = $dir . '/result.json';
        $state = 'unknown';
        $message = '';
        $success = null;
        if (is_file($resPath) && is_readable($resPath)) {
            $resRaw = file_get_contents($resPath);
            $res = json_decode($resRaw ?: '', true);
            if (is_array($res)) {
                $success = isset($res['success']) ? (bool)$res['success'] : null;
                if ($success === true) $state = 'ok';
                else if ($success === false) $state = 'error';
                $message = (string)($res['message'] ?? ($res['error'] ?? ''));
            }
        }

        $createdAt = (string)($meta['created_at'] ?? '');
        $itemCount = (int)($meta['item_count'] ?? 0);

        $jobs[] = [
            'job_id' => $jobId,
            'mode' => $mode,
            'created_at' => $createdAt,
            'item_count' => $itemCount,
            'state' => $state,
            'message' => $message,
        ];
    }

    usort($jobs, static function ($a, $b) {
        return strcmp((string)($b['job_id'] ?? ''), (string)($a['job_id'] ?? ''));
    });

    $jobs = array_slice($jobs, 0, $limit);

    http_response_code(200);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'mode' => $mode,
        'jobs' => $jobs,
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
