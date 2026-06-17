<?php declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Production\Api\Infrastructure\Database;
use PDO;

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

$raw     = file_get_contents('php://input');
$payload = json_decode(is_string($raw) ? $raw : '', true);
if (!is_array($payload)) $payload = [];

$filesIn   = $payload['files']   ?? null;
$mode      = trim((string)($payload['mode']        ?? 'reload'));
$scanLabel = trim((string)($payload['scan_label']  ?? '')) ?: null;
$orgName   = trim((string)($payload['org_name']    ?? '')) ?: null;
$eventDate = trim((string)($payload['event_date']  ?? '')) ?: null;
$eventType = trim((string)($payload['event_type']  ?? '')) ?: null;
$location  = trim((string)($payload['location']    ?? '')) ?: null;
$keywords  = trim((string)($payload['keywords']    ?? '')) ?: null;
$summary   = trim((string)($payload['summary']     ?? '')) ?: null;
$notes     = trim((string)($payload['notes']       ?? '')) ?: null;

if (!in_array($mode, ['reload', 'add'], true)) $mode = 'reload';
if ($eventType !== null && !in_array($eventType, ['band', 'wedding', 'other'], true)) $eventType = null;
if ($eventDate !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $eventDate)) $eventDate = null;

// Guard: files array required, non-empty, within limit
if (!is_array($filesIn) || count($filesIn) === 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'No files found in selected folder']);
    exit;
}
const MAX_FILES = 50000;
if (count($filesIn) > MAX_FILES) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Too many files; split into smaller batches (max ' . MAX_FILES . ')']);
    exit;
}
// Derive source_root from first relpath's leading segment
$firstRelpath = trim((string)($filesIn[0]['relpath'] ?? ''));
if (strpos($firstRelpath, '/') === false) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid relpath: expected webkitRelativePath with folder/file format']);
    exit;
}
$sourceRoot = explode('/', $firstRelpath, 2)[0];

// Load supported extensions from env (mirrors import page logic)
$jsonEnvArray = static function (string $key): array {
    $raw = getenv($key);
    if (!is_string($raw) || trim($raw) === '') return [];
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) return [];
    $out = [];
    foreach ($decoded as $x) {
        if (is_string($x) && trim($x) !== '') $out[] = strtolower(trim($x));
    }
    return array_values(array_unique($out));
};
$audioExts    = $jsonEnvArray('UPLOAD_AUDIO_EXTS_JSON') ?: ['mp3', 'wav', 'flac', 'aac', 'ogg', 'm4a'];
$videoExts    = $jsonEnvArray('UPLOAD_VIDEO_EXTS_JSON') ?: ['mp4', 'mov', 'mkv', 'avi', 'webm', 'm4v'];
$audioExtsSet = array_flip($audioExts);
$videoExtsSet = array_flip($videoExts);

// Static ext → MIME map (no file probing)
$mimeMap = [
    'mp3'  => 'audio/mpeg',        'wav'  => 'audio/wav',
    'flac' => 'audio/flac',        'aac'  => 'audio/aac',
    'ogg'  => 'audio/ogg',         'm4a'  => 'audio/mp4',
    'opus' => 'audio/opus',        'wma'  => 'audio/x-ms-wma',
    'mp4'  => 'video/mp4',         'mov'  => 'video/quicktime',
    'mkv'  => 'video/x-matroska',  'avi'  => 'video/x-msvideo',
    'webm' => 'video/webm',        'm4v'  => 'video/x-m4v',
    'wmv'  => 'video/x-ms-wmv',    'flv'  => 'video/x-flv',
    'mts'  => 'video/mp2t',        'ts'   => 'video/mp2t',
];

set_time_limit(120);
$startMs = (int)(microtime(true) * 1000);
$scanId  = 0;

try {
    $pdo = Database::createFromEnv();

    // Step 4 (reload): delete prior scans for this source_root; cascade removes their entries
    if ($mode === 'reload') {
        $pdo->prepare('DELETE FROM catalog_scans WHERE source_root = ?')->execute([$sourceRoot]);
    }

    // Step 5: create scan row with status=running
    $pdo->prepare(
        'INSERT INTO catalog_scans
         (source_root, scan_label, org_name, event_date, event_type, location, keywords, summary, notes, status, started_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, \'running\', NOW())'
    )->execute([$sourceRoot, $scanLabel, $orgName, $eventDate, $eventType, $location, $keywords, $summary, $notes]);
    $scanId = (int)$pdo->lastInsertId();

    // Counters accumulated during walk
    $totalFiles        = 0;
    $supportedFiles    = 0;
    $unsupportedFiles  = 0;
    $totalSizeBytes    = 0;
    $audioCount        = 0;
    $videoCount        = 0;
    $audioSizeBytes    = 0;
    $videoSizeBytes    = 0;
    $skipped           = 0;
    $droppedPaths      = [];
    $submittedRelpaths = [];

    // Batch-INSERT closure (200 rows per query)
    $batchSize = 200;
    $insertBatch = static function (PDO $pdo, array $rows, string $mode): int {
        if (!$rows) return 0;
        $ph  = implode(',', array_fill(0, count($rows), '(?,?,?,?,?,?,?,?,?,?,?,?)'));
        $sql = ($mode === 'reload' ? 'INSERT IGNORE' : 'INSERT') . ' INTO catalog_entries
            (scan_id, source_relpath, file_name, file_ext, file_type, is_supported,
             mime_type, size_bytes, file_mtime, path_hash, first_seen_scan_id, last_seen_scan_id)
            VALUES ' . $ph;
        if ($mode === 'add') {
            $sql .= ' ON DUPLICATE KEY UPDATE last_seen_scan_id = VALUES(last_seen_scan_id)';
        }
        $params = [];
        foreach ($rows as $r) {
            foreach ($r as $v) $params[] = $v;
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    };

    // Step 6: iterate browser-provided files array (no filesystem walk)
    $batch = [];
    foreach ($filesIn as $entry) {
        $relpath = trim((string)($entry['relpath'] ?? ''));
        if ($relpath === '' || strpos($relpath, '/') === false) continue;

        $fileName  = basename($relpath);
        $ext       = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        $sizeBytes = (int)($entry['size_bytes'] ?? 0);
        $lastModMs = (int)($entry['last_modified_ms'] ?? 0);
        $mtime     = $lastModMs > 0 ? date('Y-m-d H:i:s', intval($lastModMs / 1000)) : null;
        $pathHash  = hash('sha256', $relpath);
        $sourceRel = explode('/', $relpath, 2)[1] ?? $fileName;
        $submittedRelpaths[] = $sourceRel;

        $fileType    = 'unknown';
        $isSupported = 0;
        if (isset($audioExtsSet[$ext]))     { $fileType = 'audio'; $isSupported = 1; }
        elseif (isset($videoExtsSet[$ext])) { $fileType = 'video'; $isSupported = 1; }
        $mime = $mimeMap[$ext] ?? null;

        $totalFiles++;
        $totalSizeBytes += $sizeBytes;
        if ($isSupported) {
            $supportedFiles++;
            if ($fileType === 'audio') { $audioCount++; $audioSizeBytes += $sizeBytes; }
            else                       { $videoCount++; $videoSizeBytes += $sizeBytes; }
        } else {
            $unsupportedFiles++;
        }

        $batch[] = [
            $scanId, $sourceRel, $fileName, ($ext !== '' ? $ext : null),
            $fileType, $isSupported, $mime, $sizeBytes, $mtime,
            $pathHash, $scanId, $scanId,
        ];

        if (count($batch) >= $batchSize) {
            $rowCount = $insertBatch($pdo, $batch, $mode);
            if ($mode === 'reload') $skipped += (count($batch) - $rowCount);
            $batch = [];
        }
    }
    if ($batch) {
        $rowCount = $insertBatch($pdo, $batch, $mode);
        if ($mode === 'reload') $skipped += (count($batch) - $rowCount);
    }

    // Post-INSERT diff: identify dropped paths (reload mode, only runs when collisions occurred)
    if ($skipped > 0) {
        $diffStmt = $pdo->prepare('SELECT source_relpath FROM catalog_entries WHERE scan_id = ?');
        $diffStmt->execute([$scanId]);
        $insertedRelpaths = $diffStmt->fetchAll(PDO::FETCH_COLUMN, 0);
        $droppedPaths = array_values(array_diff($submittedRelpaths, $insertedRelpaths));
    }

    // Steps 7+8: write aggregates and mark complete in one UPDATE
    $pdo->prepare(
        'UPDATE catalog_scans
         SET total_files=?, supported_files=?, unsupported_files=?,
             total_size_bytes=?, audio_count=?, video_count=?,
             audio_size_bytes=?, video_size_bytes=?, skipped_count=?,
             status=\'complete\', completed_at=NOW()
         WHERE scan_id=?'
    )->execute([
        $totalFiles, $supportedFiles, $unsupportedFiles,
        $totalSizeBytes, $audioCount, $videoCount,
        $audioSizeBytes, $videoSizeBytes, $skipped,
        $scanId,
    ]);

    // Step 9: derived estimates (not stored; computed on the fly)
    $estAudioMin = (int)round($audioSizeBytes  / (192  * 125 * 60));
    $estVideoMin = (int)round($videoSizeBytes  / (4000 * 125 * 60));
    $estAiCost   = round($videoCount * 0.046, 2);

    $durationMs = (int)(microtime(true) * 1000) - $startMs;

    echo json_encode([
        'success'     => true,
        'scan_id'     => $scanId,
        'mode'        => $mode,
        'ignored'     => ['count' => $skipped, 'paths' => $droppedPaths],
        'summary'     => [
            'total_files'             => $totalFiles,
            'supported_files'         => $supportedFiles,
            'unsupported_files'       => $unsupportedFiles,
            'total_size_bytes'        => $totalSizeBytes,
            'audio_count'             => $audioCount,
            'video_count'             => $videoCount,
            'audio_size_bytes'        => $audioSizeBytes,
            'video_size_bytes'        => $videoSizeBytes,
            'estimated_audio_minutes' => $estAudioMin,
            'estimated_video_minutes' => $estVideoMin,
            'estimated_ai_cost_usd'   => $estAiCost,
        ],
        'duration_ms' => $durationMs,
    ]);

} catch (Throwable $e) {
    if ($scanId > 0 && isset($pdo)) {
        try {
            $pdo->prepare("UPDATE catalog_scans SET status='failed' WHERE scan_id=?")->execute([$scanId]);
        } catch (Throwable $ignored) {}
    }
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server Error', 'message' => $e->getMessage()]);
}
