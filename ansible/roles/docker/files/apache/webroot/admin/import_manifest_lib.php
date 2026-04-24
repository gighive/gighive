<?php declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

use Production\Api\Infrastructure\Database;
use Production\Api\Repositories\AssetRepository;
use Production\Api\Repositories\EventItemRepository;
use Production\Api\Repositories\EventRepository;
use Production\Api\Services\TextNormalizer;
use Production\Api\Services\UnifiedIngestionCore;

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

 function gighive_manifest_append_upload_trace(string $jobDir, array $entry): void {
    $path = $jobDir . '/upload_trace.jsonl';
    $record = [
        'ts' => date('c'),
        'ts_unix_ms' => (int)floor(microtime(true) * 1000),
    ];
    foreach ($entry as $k => $v) {
        $record[$k] = $v;
    }
    $line = json_encode($record, JSON_UNESCAPED_SLASHES);
    if (!is_string($line) || $line === '') {
        return;
    }
    @file_put_contents($path, $line . "\n", FILE_APPEND | LOCK_EX);
    @chmod($path, 0640);
 }

 function gighive_manifest_read_upload_trace(string $jobDir, int $limit = 300): array {
    $path = $jobDir . '/upload_trace.jsonl';
    if (!is_file($path) || !is_readable($path)) {
        return [];
    }
    $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($lines) || !$lines) {
        return [];
    }
    $slice = array_slice($lines, -max(1, $limit));
    $out = [];
    foreach ($slice as $line) {
        $decoded = json_decode((string)$line, true);
        if (is_array($decoded)) {
            $out[] = $decoded;
        }
    }
    return $out;
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
    $startStep = function(string $name, string $initMessage = '') use (&$steps, &$stepIndex): void {
        $steps[] = [
            'name' => $name,
            'status' => 'pending',
            'message' => $initMessage,
            'index' => $stepIndex,
        ];
        $stepIndex++;
    };

    $startStep('Upload received');
    $startStep('Validate request');

    if ($mode === 'reload') {
        $startStep('Truncate tables');
    }

    $startStep('Upsert events');
    $startStep('Insert assets (deduped by checksum_sha256)', 'may take a minute or two for progress meter to appear');
    $startStep('Link event_items');

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
    $normalizer = new TextNormalizer();

    $orgName = trim((string)($payload['org_name'] ?? 'default'));
    $normalizer->assertValidUtf8($orgName, 'org_name');
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
        $normalizer->assertValidUtf8($fileName, 'file_name at index ' . $i);

        $sourceRelpath = isset($it['source_relpath']) ? (string)$it['source_relpath'] : '';
        if ($sourceRelpath !== '') {
            $normalizer->assertValidUtf8($sourceRelpath, 'source_relpath at index ' . $i);
        }

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
    $uic          = new UnifiedIngestionCore($pdo);
    $eventRepo    = new EventRepository($pdo);
    $eventItemRepo = new EventItemRepository($pdo);

    if ($mode === 'reload') {
        gighive_manifest_throw_if_canceled($jobDir);
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        $pdo->exec('TRUNCATE TABLE event_participants');
        $pdo->exec('TRUNCATE TABLE event_items');
        $pdo->exec('TRUNCATE TABLE events');
        $pdo->exec('TRUNCATE TABLE assets');
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

        gighive_manifest_set_step($steps, 2, 'ok', 'Tables truncated');
        $writeStatus('running', 'Tables truncated', $steps);

        gighive_manifest_throw_if_canceled($jobDir);

        gighive_manifest_throw_if_canceled($jobDir);
    }

    $eventsByKey = [];
    foreach ($validated as $it) {
        $key = $it['event_date'] . '|' . $orgName;
        if (!isset($eventsByKey[$key])) {
            $eventsByKey[$key] = $eventRepo->ensureEvent($it['event_date'], $orgName, $eventType);
        }
    }

    $eventsStep = ($mode === 'add') ? 2 : 3;
    gighive_manifest_set_step($steps, $eventsStep, 'ok', 'Events ensured: ' . count($eventsByKey));
    $writeStatus('running', 'Upserted events', $steps);

    gighive_manifest_throw_if_canceled($jobDir);

    $insertStep = $eventsStep + 1;
    $linkStep = $insertStep + 1;

    $inserted = 0;
    $duplicates = 0;
    $duplicateSamples = [];
    $seen = [];

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

        $eventId = $eventsByKey[$it['event_date'] . '|' . $orgName];

        $result = $uic->ingestStub([
            'checksum_sha256' => $checksum,
            'file_type'       => $it['file_type'],
            'file_name'       => $it['file_name'],
            'source_relpath'  => $it['source_relpath'],
            'size_bytes'      => $it['size_bytes'],
        ]);

        if ($result['status'] === 'skipped') {
            $assetId = (int)$result['asset_id'];
            $duplicates++;
            if (count($duplicateSamples) < 25) {
                $duplicateSamples[] = [
                    'file_name' => $it['file_name'],
                    'source_relpath' => $it['source_relpath'],
                    'checksum_sha256' => $checksum,
                ];
            }
        } else {
            $assetId = (int)$result['asset_id'];
            $inserted++;
        }

        $labelSource = $it['file_name'] !== '' ? $it['file_name'] : $it['source_relpath'];
        $label = gighive_manifest_basename_no_ext($labelSource);
        $eventItemRepo->ensureEventItem((int)$eventId, $assetId, 'clip', $label, null);
    }

    if (isset($steps[$insertStep]) && is_array($steps[$insertStep])) {
        $steps[$insertStep]['progress'] = ['processed' => $totalToProcess, 'total' => $totalToProcess];
    }
    gighive_manifest_set_step($steps, $insertStep, 'ok', 'Inserted: ' . $inserted . ', duplicates: ' . $duplicates);
    gighive_manifest_set_step($steps, $linkStep, 'ok', 'event_items linked');
    $writeStatus('running', 'Inserted assets', $steps);

    gighive_manifest_throw_if_canceled($jobDir);

    $tableCounts = [];
    if ($mode === 'reload') {
        try {
            $tableCounts = [
                'events'      => (int)$pdo->query('SELECT COUNT(*) FROM events')->fetchColumn(),
                'assets'      => (int)$pdo->query('SELECT COUNT(*) FROM assets')->fetchColumn(),
                'event_items' => (int)$pdo->query('SELECT COUNT(*) FROM event_items')->fetchColumn(),
                'participants' => (int)$pdo->query('SELECT COUNT(*) FROM participants')->fetchColumn(),
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
