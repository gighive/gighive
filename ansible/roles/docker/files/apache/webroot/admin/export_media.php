<?php
$user = $_SERVER['PHP_AUTH_USER']
    ?? $_SERVER['REMOTE_USER']
    ?? $_SERVER['REDIRECT_REMOTE_USER']
    ?? null;

if ($user !== 'admin') {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Admin access required']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$orgFilter  = isset($_POST['org_name'])  ? trim((string)$_POST['org_name'])  : '';
$typeFilter = isset($_POST['file_type']) ? trim((string)$_POST['file_type']) : 'all';
$mode       = isset($_POST['mode'])      ? trim((string)$_POST['mode'])      : 'build';
if (!in_array($typeFilter, ['all', 'audio', 'video'], true)) {
    $typeFilter = 'all';
}
if (!in_array($mode, ['prepare', 'build', 'start'], true)) {
    $mode = 'build';
}

require __DIR__ . '/../vendor/autoload.php';
use Production\Api\Infrastructure\Database;

try {
    $pdo = Database::createFromEnv();
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'DB connection failed: ' . $e->getMessage()]);
    exit;
}

$sql = 'SELECT DISTINCT a.asset_id, a.checksum_sha256, a.file_type, a.file_ext, a.source_relpath'
     . ' FROM assets a'
     . ' JOIN event_items ei ON a.asset_id = ei.asset_id'
     . ' JOIN events e ON ei.event_id = e.event_id'
     . ' WHERE 1=1';
$params = [];

if ($orgFilter !== '') {
    $sql .= ' AND e.org_name = :org_name';
    $params[':org_name'] = $orgFilter;
}
if ($typeFilter === 'audio') {
    $sql .= " AND a.file_type = 'audio'";
} elseif ($typeFilter === 'video') {
    $sql .= " AND a.file_type = 'video'";
}

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Query failed: ' . $e->getMessage()]);
    exit;
}

if (!$rows) {
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'No matching records found in database']);
    exit;
}

$audioDir = '/var/www/html/audio';
$videoDir = '/var/www/html/video';

if ($mode === 'prepare') {
    $found      = 0;
    $skipped    = 0;
    $totalBytes = 0;
    foreach ($rows as $row) {
        $type = (string)($row['file_type'] ?? '');
        $sha  = trim((string)($row['checksum_sha256'] ?? ''));
        $ext  = strtolower(trim((string)($row['file_ext'] ?? '')));
        if ($sha === '' || preg_match('/^[a-f0-9]{64}$/i', $sha) !== 1) { $skipped++; continue; }
        $dir = match($type) { 'audio' => $audioDir, 'video' => $videoDir, default => null };
        if ($dir === null) { $skipped++; continue; }
        $served = $ext !== '' ? ($sha . '.' . $ext) : $sha;
        $path   = $dir . '/' . $served;
        if (is_file($path)) { $found++; $totalBytes += (int)filesize($path); } else { $skipped++; }
    }
    if ($found === 0) {
        http_response_code(404);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'No media files found on disk for the matching records (skipped: ' . $skipped . ')']);
        exit;
    }
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'count' => $found, 'skipped' => $skipped, 'total_bytes' => $totalBytes]);
    exit;
}

if ($mode === 'start') {
    // Filter to only rows whose file exists on disk — mirrors prepare mode logic exactly.
    // This ensures $total in the worker equals prepare's count and the worker has no expected skips.
    $filtered = [];
    foreach ($rows as $row) {
        $sha = trim((string)($row['checksum_sha256'] ?? ''));
        $ext = strtolower(trim((string)($row['file_ext'] ?? '')));
        $typ = (string)($row['file_type'] ?? '');
        if ($sha === '' || preg_match('/^[a-f0-9]{64}$/i', $sha) !== 1) continue;
        $dir = match($typ) { 'audio' => $audioDir, 'video' => $videoDir, default => null };
        if ($dir === null) continue;
        $served = $ext !== '' ? ($sha . '.' . $ext) : $sha;
        if (!is_file($dir . '/' . $served)) continue;
        $filtered[] = $row;
    }
    $rows = $filtered;

    if (empty($rows)) {
        http_response_code(404);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'No media files found on disk for the matching records']);
        exit;
    }

    $jobId = bin2hex(random_bytes(8));

    $labelPart = $orgFilter !== ''
        ? preg_replace('/[^a-zA-Z0-9_\-]/', '_', $orgFilter)
        : 'all';
    $typePart = $typeFilter !== 'all' ? '_' . $typeFilter : '';
    $filename = 'gighive_export_' . $labelPart . $typePart . '_' . date('Ymd_His') . '.zip';

    if (!function_exists('exec')) {
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'exec() is disabled; background worker cannot be spawned']);
        exit;
    }

    $jobDir = sys_get_temp_dir() . '/gighive_export_' . $jobId . '/';
    if (!mkdir($jobDir, 0700, true)) {
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Failed to create job directory']);
        exit;
    }

    if (file_put_contents($jobDir . 'filelist.json', json_encode($rows, JSON_UNESCAPED_SLASHES)) === false) {
        @rmdir($jobDir);
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Failed to write file list']);
        exit;
    }

    $total = count($rows);
    $initialStatus = json_encode([
        'success'     => true,
        'job_id'      => $jobId,
        'state'       => 'running',
        'updated_at'  => date('c'),
        'processed'   => 0,
        'total'       => $total,
        'added'       => 0,
        'skipped'     => 0,
        'bytes_added' => 0,
        'filename'    => $filename,
        'steps'       => [
            ['name' => 'Build archive', 'status' => 'running', 'message' => '0 / ' . $total . ' written', 'progress' => ['processed' => 0, 'total' => $total]],
        ],
    ], JSON_UNESCAPED_SLASHES);

    if (file_put_contents($jobDir . 'status.json', $initialStatus . "\n", LOCK_EX) === false) {
        @unlink($jobDir . 'filelist.json');
        @rmdir($jobDir);
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Failed to write initial status']);
        exit;
    }

    exec('php ' . escapeshellarg(__DIR__ . '/export_media_worker.php') . ' --job_id=' . escapeshellarg($jobId) . ' >> ' . escapeshellarg($jobDir . 'worker.log') . ' 2>&1 &');

    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'job_id' => $jobId, 'total' => $total]);
    exit;
}

// build mode — deprecated
http_response_code(410);
header('Content-Type: application/json');
echo json_encode(['success' => false, 'error' => 'build mode is deprecated; use mode=start']);
exit;

$tmpFile = tempnam(sys_get_temp_dir(), 'gighive_export_');
if ($tmpFile === false) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Failed to create temp file']);
    exit;
}

$zip = new ZipArchive();
if ($zip->open($tmpFile, ZipArchive::OVERWRITE) !== true) {
    @unlink($tmpFile);
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Failed to create ZIP archive']);
    exit;
}

$added    = 0;
$skipped  = 0;
$seen     = [];

foreach ($rows as $row) {
    $type = (string)($row['file_type'] ?? '');
    $sha  = trim((string)($row['checksum_sha256'] ?? ''));
    $ext  = strtolower(trim((string)($row['file_ext'] ?? '')));
    $src  = (string)($row['source_relpath'] ?? '');

    if ($sha === '' || preg_match('/^[a-f0-9]{64}$/i', $sha) !== 1) {
        $skipped++;
        continue;
    }

    $dir = match($type) {
        'audio' => $audioDir,
        'video' => $videoDir,
        default => null,
    };
    if ($dir === null) {
        $skipped++;
        continue;
    }

    $served   = $ext !== '' ? ($sha . '.' . $ext) : $sha;
    $filePath = $dir . '/' . $served;
    if (!is_file($filePath)) {
        $skipped++;
        continue;
    }

    $base      = $src !== '' ? basename($src) : $served;
    $entryName = str_replace(['/', '\\', "\0"], '_', $base);
    if ($entryName === '') {
        $entryName = $served;
    }

    if (isset($seen[$entryName])) {
        $entryName = pathinfo($entryName, PATHINFO_FILENAME)
            . '_' . substr($sha, 0, 8)
            . '.' . pathinfo($entryName, PATHINFO_EXTENSION);
    }
    $seen[$entryName] = true;

    $zip->addFile($filePath, $entryName);
    $added++;
}

$zip->close();

if ($added === 0) {
    @unlink($tmpFile);
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'No media files found on disk for the matching records (skipped: ' . $skipped . ')']);
    exit;
}

$labelPart = $orgFilter !== ''
    ? preg_replace('/[^a-zA-Z0-9_\-]/', '_', $orgFilter)
    : 'all';
$typePart  = $typeFilter !== 'all' ? '_' . $typeFilter : '';
$filename  = 'gighive_export_' . $labelPart . $typePart . '_' . date('Ymd_His') . '.zip';
$size      = (int)filesize($tmpFile);

header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $filename . '"');
// Intentionally omitting Content-Length so Apache mod_proxy_fcgi streams
// the response incrementally rather than buffering the full FCGI body first
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

// Disable any remaining output buffering / compression so chunks reach the browser incrementally
@ini_set('zlib.output_compression', 'off');
while (ob_get_level() > 0) { ob_end_clean(); }

// Stream in 256 KB chunks so the browser ReadableStream sees incremental data
$handle = @fopen($tmpFile, 'rb');
if ($handle !== false) {
    while (!feof($handle) && !connection_aborted()) {
        $data = fread($handle, 262144);
        if ($data === false || $data === '') break;
        echo $data;
        if (ob_get_level() > 0) ob_flush();
        flush();
    }
    fclose($handle);
}
@unlink($tmpFile);
exit;
