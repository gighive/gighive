<?php declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use Production\Api\Infrastructure\Database;

function gighive_manifest_job_id(): string {
    return date('Ymd-His') . '-' . bin2hex(random_bytes(6));
}

function gighive_manifest_job_paths(string $jobId): array {
    $jobRoot = '/var/www/private/import_jobs';
    $jobDir = $jobRoot . '/' . $jobId;
    return [$jobRoot, $jobDir];
}

function gighive_manifest_write_json(string $path, array $data, int $mode = 0640): void {
    if (@file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX) === false) {
        throw new RuntimeException('Failed to write ' . basename($path));
    }
    @chmod($path, $mode);
}

function gighive_manifest_load_job_meta(string $jobDir): array {
    $metaPath = $jobDir . '/meta.json';
    if (!is_file($metaPath) || !is_readable($metaPath)) {
        throw new RuntimeException('Missing meta.json');
    }
    $raw = file_get_contents($metaPath);
    $meta = json_decode($raw ?: '', true);
    if (!is_array($meta)) {
        throw new RuntimeException('Invalid meta.json');
    }
    return $meta;
}

function gighive_manifest_load_job_payload(string $jobDir): array {
    $manifestPath = $jobDir . '/manifest.json';
    if (!is_file($manifestPath) || !is_readable($manifestPath)) {
        throw new RuntimeException('Missing manifest.json');
    }
    $raw = file_get_contents($manifestPath);
    $payload = json_decode($raw ?: '', true);
    if (!is_array($payload)) {
        throw new RuntimeException('Invalid manifest.json');
    }
    return $payload;
}

function gighive_manifest_is_canceled(string $jobDir): bool {
    $p = $jobDir . '/cancel.json';
    return is_file($p);
}

function gighive_manifest_throw_if_canceled(string $jobDir): void {
    if (gighive_manifest_is_canceled($jobDir)) {
        throw new RuntimeException('Canceled by user');
    }
}

function gighive_manifest_init_steps(string $mode): array {
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

    $startStep('Upload received');
    $startStep('Validate request');

    if ($mode === 'reload') {
        $startStep('Truncate tables');
        $startStep('Seed genres/styles');
    }

    $startStep('Upsert sessions');
    $startStep('Insert files (deduped by checksum_sha256, may take a minute or two for progress meter to appear)');
    $startStep('Link labels (songs)');

    return $steps;
}

function gighive_manifest_set_step(array &$steps, int $i, string $status, string $message = ''): void {
    if (!isset($steps[$i])) return;
    $steps[$i]['status'] = $status;
    $steps[$i]['message'] = $message;
}

function gighive_manifest_fail_first_pending(array &$steps, string $message): void {
    for ($i = 0; $i < count($steps); $i++) {
        if (($steps[$i]['status'] ?? '') === 'pending') {
            gighive_manifest_set_step($steps, $i, 'error', $message);
            return;
        }
    }
}

function gighive_manifest_basename_no_ext(string $pathOrName): string {
    $s = trim($pathOrName);
    if ($s === '') return '';
    $s = str_replace('\\', '/', $s);
    $base = basename($s);
    $dot = strrpos($base, '.');
    return $dot === false ? $base : substr($base, 0, $dot);
}

function gighive_manifest_validate_payload(array $payload): array {
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

    return [$orgName, $eventType, $validated];
}

function gighive_manifest_import_run(string $jobDir, string $jobId, string $mode, array $payload, ?string $sourceJobId, callable $writeStatus): array {
    if (!in_array($mode, ['add', 'reload'], true)) {
        throw new RuntimeException('Invalid mode');
    }

    $steps = gighive_manifest_init_steps($mode);
    gighive_manifest_set_step($steps, 0, 'ok', 'Request received');
    $writeStatus('running', 'Request received', $steps);

    gighive_manifest_throw_if_canceled($jobDir);

    [$orgName, $eventType, $validated] = gighive_manifest_validate_payload($payload);
    gighive_manifest_set_step($steps, 1, 'ok', 'Validated ' . count($validated) . ' item(s)');
    $writeStatus('running', 'Validated request', $steps);

    gighive_manifest_throw_if_canceled($jobDir);

    $pdo = Database::createFromEnv();

    $ensureSession = function(PDO $pdo2, string $eventDate2, string $orgName2, string $eventType2): int {
        $stmt = $pdo2->prepare('SELECT session_id FROM sessions WHERE date = :d AND org_name = :o LIMIT 1');
        $stmt->execute([':d' => $eventDate2, ':o' => $orgName2]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row && isset($row['session_id'])) {
            return (int)$row['session_id'];
        }

        $sql = 'INSERT INTO sessions (title, date, event_type, org_name) VALUES (:title, :date, :etype, :org)';
        $stmt = $pdo2->prepare($sql);
        $title = $orgName2 . ' ' . $eventDate2;
        $stmt->execute([
            ':title' => $title,
            ':date' => $eventDate2,
            ':etype' => $eventType2 !== '' ? $eventType2 : null,
            ':org' => $orgName2,
        ]);
        return (int)$pdo2->lastInsertId();
    };

    $nextSeq = function(PDO $pdo2, int $sessionId): int {
        $stmt = $pdo2->prepare('SELECT COALESCE(MAX(seq), 0) AS max_seq FROM files WHERE session_id = :sid');
        $stmt->execute([':sid' => $sessionId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $max = (int)($row['max_seq'] ?? 0);
        return $max + 1;
    };

    $ensureSong = function(PDO $pdo2, string $title, string $type): int {
        $stmt = $pdo2->prepare('SELECT song_id FROM songs WHERE title = :t AND type = :ty LIMIT 1');
        $stmt->execute([':t' => $title, ':ty' => $type]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row && isset($row['song_id'])) {
            return (int)$row['song_id'];
        }
        $stmt = $pdo2->prepare('INSERT INTO songs (title, type) VALUES (:t, :ty)');
        $stmt->execute([':t' => $title, ':ty' => $type]);
        return (int)$pdo2->lastInsertId();
    };

    $ensureSessionSong = function(PDO $pdo2, int $sessionId, int $songId): void {
        $sql = 'INSERT INTO session_songs (session_id, song_id) VALUES (:s, :g)'
            . ' ON DUPLICATE KEY UPDATE position = position';
        $pdo2->prepare($sql)->execute([':s' => $sessionId, ':g' => $songId]);
    };

    $linkSongFile = function(PDO $pdo2, int $songId, int $fileId): void {
        $sql = 'INSERT INTO song_files (song_id, file_id) VALUES (:g, :f)'
            . ' ON DUPLICATE KEY UPDATE file_id = file_id';
        $pdo2->prepare($sql)->execute([':g' => $songId, ':f' => $fileId]);
    };

    if ($mode === 'reload') {
        gighive_manifest_throw_if_canceled($jobDir);
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

        gighive_manifest_set_step($steps, 2, 'ok', 'Tables truncated');
        $writeStatus('running', 'Tables truncated', $steps);

        gighive_manifest_throw_if_canceled($jobDir);

        $pdo->exec("INSERT IGNORE INTO genres (name) VALUES
('Rock'),('Jazz'),('Blues'),('Funk'),('Hip-Hop'),
('Classical'),('Metal'),('Pop'),('Folk'),
('Electronic'),('Reggae'),('Country'),
('Latin'),('R&B'),('Alternative'),('Experimental');");

        $pdo->exec("INSERT IGNORE INTO styles (name) VALUES
('Acoustic'),('Electric'),('Fusion'),('Improvised'),
('Progressive'),('Psychedelic'),('Hard'),
('Soft'),('Instrumental'),('Vocal');");

        gighive_manifest_set_step($steps, 3, 'ok', 'Seeded genres/styles');
        $writeStatus('running', 'Seeded genres/styles', $steps);

        gighive_manifest_throw_if_canceled($jobDir);
    }

    $sessionsByKey = [];
    foreach ($validated as $it) {
        $key = $it['event_date'] . '|' . $orgName;
        if (!isset($sessionsByKey[$key])) {
            $sessionsByKey[$key] = $ensureSession($pdo, $it['event_date'], $orgName, $eventType);
        }
    }

    $sessionsStep = ($mode === 'add') ? 2 : 4;
    gighive_manifest_set_step($steps, $sessionsStep, 'ok', 'Sessions ensured: ' . count($sessionsByKey));
    $writeStatus('running', 'Upserted sessions', $steps);

    gighive_manifest_throw_if_canceled($jobDir);

    $insertStep = $sessionsStep + 1;
    $linkStep = $insertStep + 1;

    $inserted = 0;
    $duplicates = 0;
    $duplicateSamples = [];
    $seen = [];

    $insertSql = 'INSERT INTO files (file_name, source_relpath, file_type, session_id, seq, size_bytes, checksum_sha256)'
        . ' VALUES (:file_name, :source_relpath, :file_type, :session_id, :seq, :size_bytes, :checksum)';
    $insertStmt = $pdo->prepare($insertSql);

    $totalToProcess = count($validated);
    $processed = 0;
    if (isset($steps[$insertStep]) && is_array($steps[$insertStep])) {
        $steps[$insertStep]['progress'] = ['processed' => 0, 'total' => $totalToProcess];
    }
    gighive_manifest_set_step($steps, $insertStep, $steps[$insertStep]['status'] ?? 'pending', 'Processed 0 / ' . $totalToProcess);
    $writeStatus('running', 'Inserting files', $steps);

    foreach ($validated as $it) {
        $processed++;
        if ($processed % 200 === 0) {
            gighive_manifest_throw_if_canceled($jobDir);

            if (isset($steps[$insertStep]) && is_array($steps[$insertStep])) {
                $steps[$insertStep]['progress'] = ['processed' => $processed, 'total' => $totalToProcess];
            }
            gighive_manifest_set_step($steps, $insertStep, $steps[$insertStep]['status'] ?? 'pending', 'Processed ' . $processed . ' / ' . $totalToProcess);
            $writeStatus('running', 'Inserting files', $steps);
        }
        $checksum = $it['checksum_sha256'];
        if ($mode === 'reload') {
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
        }

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
            $label = gighive_manifest_basename_no_ext($labelSource);
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

    if (isset($steps[$insertStep]) && is_array($steps[$insertStep])) {
        $steps[$insertStep]['progress'] = ['processed' => $totalToProcess, 'total' => $totalToProcess];
    }
    gighive_manifest_set_step($steps, $insertStep, 'ok', 'Inserted: ' . $inserted . ', duplicates skipped: ' . $duplicates);
    gighive_manifest_set_step($steps, $linkStep, 'ok', 'Label links created for newly inserted files');
    $writeStatus('running', 'Inserted files', $steps);

    gighive_manifest_throw_if_canceled($jobDir);

    $tableCounts = [];
    if ($mode === 'reload') {
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
    }

    return [
        'success' => true,
        'job_id' => $jobId,
        'source_job_id' => $sourceJobId,
        'mode' => $mode,
        'message' => ($mode === 'add') ? 'Add-to-database completed successfully.' : 'Database reload completed successfully.',
        'inserted_count' => $inserted,
        'duplicate_count' => $duplicates,
        'duplicates' => $duplicateSamples,
        'steps' => $steps,
        'table_counts' => $tableCounts,
    ];
}
