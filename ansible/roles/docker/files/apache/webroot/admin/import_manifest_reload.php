<?php declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

use Production\Api\Infrastructure\Database;
use Production\Api\Repositories\AssetRepository;
use Production\Api\Repositories\EventItemRepository;
use Production\Api\Repositories\EventRepository;

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

$steps = [];
$stepIndex = 0;
$startStep = function(string $name) use (&$steps, &$stepIndex): void {
    $steps[] = [
        'name' => $name,
        'status' => 'pending',
        'message' => '',
        'index' => $stepIndex,
    ];
    $stepIndex++;
};
$finishStep = function(int $i, string $status, string $message = '') use (&$steps): void {
    $steps[$i]['status'] = $status;
    $steps[$i]['message'] = $message;
};

$startStep('Upload received');
$startStep('Validate request');
$startStep('Truncate tables');
$startStep('Seed genres/styles');
$startStep('Upsert events');
$startStep('Insert assets (dedupe by checksum_sha256)');
$startStep('Link event_items');

$lockPath = '/var/www/private/import_database.lock';
$jobRoot = '/var/www/private/import_jobs';
$jobId = '';
$jobDir = '';
$lockFp = @fopen($lockPath, 'c');
if (!$lockFp) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'error' => 'Server Error',
        'message' => 'Failed to create lock file',
        'steps' => $steps,
    ]);
    exit;
}

if (!flock($lockFp, LOCK_EX | LOCK_NB)) {
    http_response_code(409);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'error' => 'Conflict',
        'message' => 'An import is already running. Please wait for it to finish.',
        'steps' => $steps,
    ]);
    fclose($lockFp);
    exit;
}

$basenameNoExt = function(string $pathOrName): string {
    $s = trim($pathOrName);
    if ($s === '') return '';
    $s = str_replace('\\', '/', $s);
    $base = basename($s);
    $dot = strrpos($base, '.');
    return $dot === false ? $base : substr($base, 0, $dot);
};

try {
    $raw = file_get_contents('php://input');
    if (!is_string($raw) || trim($raw) === '') {
        throw new RuntimeException('Missing request body');
    }

    $payload = json_decode($raw, true);
    if (!is_array($payload)) {
        throw new RuntimeException('Invalid JSON');
    }

    $finishStep(0, 'ok', 'Request received');

    $jobId = date('Ymd-His') . '-' . bin2hex(random_bytes(6));
    $jobDir = $jobRoot . '/' . $jobId;
    if (!is_dir($jobRoot) && !@mkdir($jobRoot, 0775, true)) {
        throw new RuntimeException('Failed to create import root directory');
    }
    if (!@mkdir($jobDir, 0775, true)) {
        throw new RuntimeException('Failed to create job directory');
    }
    $metaOut = [
        'job_type' => 'manifest_import',
        'mode' => 'reload',
        'created_at' => date('c'),
        'item_count' => is_array($payload['items'] ?? null) ? count((array)$payload['items']) : 0,
    ];
    if (@file_put_contents($jobDir . '/meta.json', json_encode($metaOut, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX) === false) {
        throw new RuntimeException('Failed to write meta.json');
    }
    @chmod($jobDir . '/meta.json', 0640);
    if (@file_put_contents($jobDir . '/manifest.json', json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX) === false) {
        throw new RuntimeException('Failed to write manifest.json');
    }
    @chmod($jobDir . '/manifest.json', 0640);

    $orgName = trim((string)($payload['org_name'] ?? 'default'));
    $eventType = trim((string)($payload['event_type'] ?? 'band'));
    $items = $payload['items'] ?? null;
    if (!is_array($items) || !$items) {
        throw new RuntimeException('Missing or empty items array');
    }

    $validated = [];
    foreach ($items as $i => $it) {
        if (!is_array($it)) {
            throw new RuntimeException('Invalid item at index ' . $i);
        }

        $checksum = strtolower(trim((string)($it['checksum_sha256'] ?? '')));
        if ($checksum === '' || !preg_match('/^[0-9a-f]{64}$/', $checksum)) {
            throw new RuntimeException('Invalid checksum_sha256 at index ' . $i);
        }

        $fileType = strtolower(trim((string)($it['file_type'] ?? '')));
        if (!in_array($fileType, ['audio', 'video'], true)) {
            throw new RuntimeException('Invalid file_type at index ' . $i);
        }

        $fileName = trim((string)($it['file_name'] ?? ''));
        if ($fileName === '') {
            throw new RuntimeException('Missing file_name at index ' . $i);
        }

        $sourceRelpath = isset($it['source_relpath']) ? (string)$it['source_relpath'] : '';

        $eventDate = trim((string)($it['event_date'] ?? ''));
        if ($eventDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $eventDate)) {
            throw new RuntimeException('Invalid event_date at index ' . $i);
        }

        $sizeBytes = null;
        if (isset($it['size_bytes']) && $it['size_bytes'] !== '') {
            $v = (int)$it['size_bytes'];
            if ($v >= 0) $sizeBytes = $v;
        }

        $validated[] = [
            'checksum_sha256' => $checksum,
            'file_type' => $fileType,
            'file_name' => $fileName,
            'source_relpath' => $sourceRelpath,
            'event_date' => $eventDate,
            'size_bytes' => $sizeBytes,
        ];
    }

    $finishStep(1, 'ok', 'Validated ' . count($validated) . ' item(s)');

    $pdo          = Database::createFromEnv();
    $eventRepo    = new EventRepository($pdo);
    $assetRepo    = new AssetRepository($pdo);
    $eventItemRepo = new EventItemRepository($pdo);

    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    $pdo->exec('TRUNCATE TABLE event_participants');
    $pdo->exec('TRUNCATE TABLE event_items');
    $pdo->exec('TRUNCATE TABLE events');
    $pdo->exec('TRUNCATE TABLE assets');
    $pdo->exec('TRUNCATE TABLE genres');
    $pdo->exec('TRUNCATE TABLE styles');
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

    $finishStep(2, 'ok', 'Tables truncated');

    $pdo->exec("INSERT IGNORE INTO genres (name) VALUES
('Rock'),('Jazz'),('Blues'),('Funk'),('Hip-Hop'),
('Classical'),('Metal'),('Pop'),('Folk'),
('Electronic'),('Reggae'),('Country'),
('Latin'),('R&B'),('Alternative'),('Experimental');");

    $pdo->exec("INSERT IGNORE INTO styles (name) VALUES
('Acoustic'),('Electric'),('Fusion'),('Improvised'),
('Progressive'),('Psychedelic'),('Hard'),
('Soft'),('Instrumental'),('Vocal');");

    $finishStep(3, 'ok', 'Seeded genres/styles');

    $eventsByKey = [];
    foreach ($validated as $it) {
        $key = $it['event_date'] . '|' . $orgName;
        if (!isset($eventsByKey[$key])) {
            $eventsByKey[$key] = $eventRepo->ensureEvent($it['event_date'], $orgName, $eventType);
        }
    }
    $finishStep(4, 'ok', 'Events ensured: ' . count($eventsByKey));

    $inserted = 0;
    $duplicates = 0;
    $duplicateSamples = [];
    $seen = [];

    foreach ($validated as $it) {
        $checksum = $it['checksum_sha256'];
        if (isset($seen[$checksum])) {
            $duplicates++;
            if (count($duplicateSamples) < 25) {
                $duplicateSamples[] = [
                    'file_name' => $it['file_name'],
                    'source_relpath' => $it['source_relpath'],
                    'checksum_sha256' => $checksum,
                ];
            }
            continue;
        }
        $seen[$checksum] = true;

        $eventId = $eventsByKey[$it['event_date'] . '|' . $orgName];
        $srcRelpath = $it['source_relpath'] !== '' ? $it['source_relpath'] : $it['file_name'];
        $ext = strtolower(pathinfo($srcRelpath, PATHINFO_EXTENSION));

        $existing = $assetRepo->findByChecksum($checksum);
        if ($existing !== null) {
            $assetId = (int)$existing['asset_id'];
            $duplicates++;
            if (count($duplicateSamples) < 25) {
                $duplicateSamples[] = [
                    'file_name' => $it['file_name'],
                    'source_relpath' => $it['source_relpath'],
                    'checksum_sha256' => $checksum,
                ];
            }
        } else {
            $assetId = $assetRepo->create([
                'checksum_sha256'  => $checksum,
                'file_ext'         => $ext,
                'file_type'        => $it['file_type'],
                'source_relpath'   => $srcRelpath !== '' ? $srcRelpath : null,
                'size_bytes'       => $it['size_bytes'],
                'duration_seconds' => null,
                'media_info'       => null,
                'media_info_tool'  => null,
                'mime_type'        => null,
                'media_created_at' => null,
            ]);
            $inserted++;
        }

        $labelSource = $it['file_name'] !== '' ? $it['file_name'] : $it['source_relpath'];
        $label = $basenameNoExt($labelSource);
        $eventItemRepo->ensureEventItem((int)$eventId, $assetId, 'clip', $label, null);
    }

    $finishStep(5, 'ok', 'Inserted: ' . $inserted . ', duplicates: ' . $duplicates);
    $finishStep(6, 'ok', 'event_items linked');

    $tableCounts = [];
    try {
        $tableCounts = [
            'events'       => (int)$pdo->query('SELECT COUNT(*) FROM events')->fetchColumn(),
            'assets'       => (int)$pdo->query('SELECT COUNT(*) FROM assets')->fetchColumn(),
            'event_items'  => (int)$pdo->query('SELECT COUNT(*) FROM event_items')->fetchColumn(),
            'participants' => (int)$pdo->query('SELECT COUNT(*) FROM participants')->fetchColumn(),
        ];
    } catch (Throwable $e) {
        $tableCounts = [];
    }

    $resultOut = [
        'success' => true,
        'job_id' => $jobId,
        'message' => 'Database reload completed successfully.',
        'inserted_count' => $inserted,
        'duplicate_count' => $duplicates,
        'duplicates' => $duplicateSamples,
        'steps' => $steps,
        'table_counts' => $tableCounts,
    ];
    if ($jobDir !== '' && is_dir($jobDir)) {
        @file_put_contents($jobDir . '/result.json', json_encode($resultOut, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
        @chmod($jobDir . '/result.json', 0640);
    }

    http_response_code(200);
    header('Content-Type: application/json');
    echo json_encode($resultOut);

} catch (Throwable $e) {
    $failedAt = null;
    for ($i = 0; $i < count($steps); $i++) {
        if ($steps[$i]['status'] === 'pending') {
            $failedAt = $i;
            break;
        }
    }
    if ($failedAt !== null) {
        $finishStep($failedAt, 'error', $e->getMessage());
    }

    $resultOut = [
        'success' => false,
        'job_id' => $jobId !== '' ? $jobId : null,
        'error' => 'Import failed',
        'message' => $e->getMessage(),
        'steps' => $steps,
    ];
    if ($jobDir !== '' && is_dir($jobDir)) {
        @file_put_contents($jobDir . '/result.json', json_encode($resultOut, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
        @chmod($jobDir . '/result.json', 0640);
    }

    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode($resultOut);

} finally {
    flock($lockFp, LOCK_UN);
    fclose($lockFp);
}
