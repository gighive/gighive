<?php declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use Production\Api\Infrastructure\Database;

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
$startStep('Upsert sessions');
$startStep('Insert files (dedupe by checksum_sha256)');
$startStep('Link labels (songs)');

$lockPath = '/var/www/private/import_database.lock';
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

$ensureSession = function(PDO $pdo, string $eventDate, string $orgName, string $eventType): int {
    $stmt = $pdo->prepare('SELECT session_id FROM sessions WHERE date = :d AND org_name = :o LIMIT 1');
    $stmt->execute([':d' => $eventDate, ':o' => $orgName]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row && isset($row['session_id'])) {
        return (int)$row['session_id'];
    }

    $sql = 'INSERT INTO sessions (title, date, event_type, org_name) VALUES (:title, :date, :etype, :org)';
    $stmt = $pdo->prepare($sql);
    $title = $orgName . ' ' . $eventDate;
    $stmt->execute([
        ':title' => $title,
        ':date' => $eventDate,
        ':etype' => $eventType !== '' ? $eventType : null,
        ':org' => $orgName,
    ]);
    return (int)$pdo->lastInsertId();
};

$nextSeq = function(PDO $pdo, int $sessionId): int {
    $stmt = $pdo->prepare('SELECT COALESCE(MAX(seq), 0) AS max_seq FROM files WHERE session_id = :sid');
    $stmt->execute([':sid' => $sessionId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $max = (int)($row['max_seq'] ?? 0);
    return $max + 1;
};

$ensureSong = function(PDO $pdo, string $title, string $type): int {
    $stmt = $pdo->prepare('SELECT song_id FROM songs WHERE title = :t AND type = :ty LIMIT 1');
    $stmt->execute([':t' => $title, ':ty' => $type]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row && isset($row['song_id'])) {
        return (int)$row['song_id'];
    }
    $stmt = $pdo->prepare('INSERT INTO songs (title, type) VALUES (:t, :ty)');
    $stmt->execute([':t' => $title, ':ty' => $type]);
    return (int)$pdo->lastInsertId();
};

$ensureSessionSong = function(PDO $pdo, int $sessionId, int $songId): void {
    $sql = 'INSERT INTO session_songs (session_id, song_id) VALUES (:s, :g)'
        . ' ON DUPLICATE KEY UPDATE position = position';
    $pdo->prepare($sql)->execute([':s' => $sessionId, ':g' => $songId]);
};

$linkSongFile = function(PDO $pdo, int $songId, int $fileId): void {
    $sql = 'INSERT INTO song_files (song_id, file_id) VALUES (:g, :f)'
        . ' ON DUPLICATE KEY UPDATE file_id = file_id';
    $pdo->prepare($sql)->execute([':g' => $songId, ':f' => $fileId]);
};

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

    $pdo = Database::createFromEnv();

    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    $pdo->exec('TRUNCATE TABLE session_musicians');
    $pdo->exec('TRUNCATE TABLE session_songs');
    $pdo->exec('TRUNCATE TABLE song_files');
    $pdo->exec('TRUNCATE TABLE files');
    $pdo->exec('TRUNCATE TABLE songs');
    $pdo->exec('TRUNCATE TABLE sessions');
    $pdo->exec('TRUNCATE TABLE musicians');
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

    $sessionsByKey = [];
    foreach ($validated as $it) {
        $key = $it['event_date'] . '|' . $orgName;
        if (!isset($sessionsByKey[$key])) {
            $sessionsByKey[$key] = $ensureSession($pdo, $it['event_date'], $orgName, $eventType);
        }
    }
    $finishStep(4, 'ok', 'Sessions ensured: ' . count($sessionsByKey));

    $inserted = 0;
    $duplicates = 0;
    $duplicateSamples = [];
    $seen = [];

    $insertSql = 'INSERT INTO files (file_name, source_relpath, file_type, session_id, seq, size_bytes, checksum_sha256)'
        . ' VALUES (:file_name, :source_relpath, :file_type, :session_id, :seq, :size_bytes, :checksum)';
    $insertStmt = $pdo->prepare($insertSql);

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

        $sessionId = $sessionsByKey[$it['event_date'] . '|' . $orgName];

        try {
            $seq = $nextSeq($pdo, (int)$sessionId);
            $insertStmt->execute([
                ':file_name' => $it['file_name'],
                ':source_relpath' => $it['source_relpath'] !== '' ? $it['source_relpath'] : null,
                ':file_type' => $it['file_type'],
                ':session_id' => $sessionId,
                ':seq' => $seq,
                ':size_bytes' => $it['size_bytes'],
                ':checksum' => $checksum,
            ]);

            $fileId = (int)$pdo->lastInsertId();
            $inserted++;

            $labelSource = $it['file_name'] !== '' ? $it['file_name'] : $it['source_relpath'];
            $label = $basenameNoExt($labelSource);
            if ($label !== '') {
                $songType = 'song';
                $songId = $ensureSong($pdo, $label, $songType);
                $ensureSessionSong($pdo, (int)$sessionId, $songId);
                $linkSongFile($pdo, $songId, $fileId);
            }

        } catch (PDOException $e) {
            if (($e->getCode() ?? '') === '23000') {
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
            throw $e;
        }
    }

    $finishStep(5, 'ok', 'Inserted: ' . $inserted . ', duplicates skipped: ' . $duplicates);
    $finishStep(6, 'ok', 'Label links created for newly inserted files');

    $tableCounts = [];
    try {
        $tableCounts = [
            'sessions' => (int)$pdo->query('SELECT COUNT(*) FROM sessions')->fetchColumn(),
            'musicians' => (int)$pdo->query('SELECT COUNT(*) FROM musicians')->fetchColumn(),
            'songs' => (int)$pdo->query('SELECT COUNT(*) FROM songs')->fetchColumn(),
            'files' => (int)$pdo->query('SELECT COUNT(*) FROM files')->fetchColumn(),
            'session_musicians' => (int)$pdo->query('SELECT COUNT(*) FROM session_musicians')->fetchColumn(),
            'session_songs' => (int)$pdo->query('SELECT COUNT(*) FROM session_songs')->fetchColumn(),
            'song_files' => (int)$pdo->query('SELECT COUNT(*) FROM song_files')->fetchColumn(),
        ];
    } catch (Throwable $e) {
        $tableCounts = [];
    }

    http_response_code(200);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'message' => 'Database reload completed successfully.',
        'inserted_count' => $inserted,
        'duplicate_count' => $duplicates,
        'duplicates' => $duplicateSamples,
        'steps' => $steps,
        'table_counts' => $tableCounts,
    ]);

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

    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'error' => 'Import failed',
        'message' => $e->getMessage(),
        'steps' => $steps,
    ]);

} finally {
    flock($lockFp, LOCK_UN);
    fclose($lockFp);
}
