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
if (!in_array($typeFilter, ['all', 'audio', 'video'], true)) {
    $typeFilter = 'all';
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
header('Content-Length: ' . $size);
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

readfile($tmpFile);
@unlink($tmpFile);
exit;
