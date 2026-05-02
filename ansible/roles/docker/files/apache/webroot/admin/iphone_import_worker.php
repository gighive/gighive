<?php declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit(1);
}

$jobId = '';
foreach ($argv as $arg) {
    if (str_starts_with((string)$arg, '--job_id=')) {
        $jobId = substr((string)$arg, strlen('--job_id='));
    }
}

if ($jobId === '' || !preg_match('/^[0-9]{8}-[0-9]{6}-[a-f0-9]{12}$/', $jobId)) {
    fwrite(STDERR, "iphone_import_worker: invalid or missing --job_id\n");
    exit(1);
}

require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once __DIR__ . '/import_manifest_lib.php';

use Production\Api\Infrastructure\Database;
use Production\Api\Repositories\EventItemRepository;
use Production\Api\Repositories\EventRepository;
use Production\Api\Services\UnifiedIngestionCore;

[$jobRoot, $jobDir] = gighive_manifest_job_paths($jobId);

if (!is_dir($jobDir)) {
    fwrite(STDERR, "iphone_import_worker: job directory not found: $jobDir\n");
    exit(1);
}

$lockDir    = '/var/www/private/iphone_import_worker.lock';
$stagingDir = '/var/iphone-import';
$videoExts  = ['mp4', 'mov', 'm4v'];
$audioExts  = ['mp3', 'm4a', 'aac'];

$steps = [
    ['name' => 'Scan staging directory',   'status' => 'pending', 'message' => '', 'index' => 0],
    ['name' => 'Hash and ingest files',     'status' => 'pending', 'message' => '', 'index' => 1],
    ['name' => 'Copy files to asset store', 'status' => 'pending', 'message' => '', 'index' => 2],
];

$writeStatus = function(string $state, string $message) use ($jobDir, $jobId, &$steps): void {
    @file_put_contents($jobDir . '/status.json', json_encode([
        'success'    => true,
        'job_id'     => $jobId,
        'state'      => $state,
        'message'    => $message,
        'updated_at' => date('c'),
        'steps'      => $steps,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n", LOCK_EX);
};

$releaseLock = function() use ($lockDir): void {
    @unlink($lockDir . '/job_id');
    @unlink($lockDir . '/started_at');
    @rmdir($lockDir);
};

try {
    $meta      = gighive_manifest_load_job_meta($jobDir);
    $orgName   = (string)($meta['org_name']   ?? 'iPhone');
    $eventType = (string)($meta['event_type'] ?? 'band');

    // ── Step 0: Scan staging directory ──────────────────────────────────────
    $steps[0]['status'] = 'running';
    $writeStatus('running', 'Scanning staging directory');

    $mediaFiles = [];
    $rit = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($stagingDir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY
    );
    foreach ($rit as $fileInfo) {
        if (!$fileInfo->isFile()) {
            continue;
        }
        $ext = strtolower($fileInfo->getExtension());
        if (in_array($ext, $videoExts, true)) {
            $type = 'video';
        } elseif (in_array($ext, $audioExts, true)) {
            $type = 'audio';
        } else {
            continue;
        }
        $mediaFiles[] = [
            'path' => $fileInfo->getPathname(),
            'name' => $fileInfo->getFilename(),
            'ext'  => $ext,
            'type' => $type,
            'size' => (int)$fileInfo->getSize(),
        ];
    }

    $steps[0]['status']  = 'ok';
    $steps[0]['message'] = 'Found ' . count($mediaFiles) . ' media file(s)';
    $writeStatus('running', 'Scan complete');

    gighive_manifest_throw_if_canceled($jobDir);

    if (empty($mediaFiles)) {
        $steps[1]['status']  = 'ok';
        $steps[1]['message'] = 'No files to process';
        $steps[2]['status']  = 'ok';
        $steps[2]['message'] = 'No files to copy';
        gighive_manifest_write_json($jobDir . '/result.json', [
            'success'         => true,
            'job_id'          => $jobId,
            'mode'            => 'add',
            'message'         => 'No media files found in staging directory.',
            'inserted_count'  => 0,
            'duplicate_count' => 0,
            'copied_count'    => 0,
            'error_count'     => 0,
            'steps'           => $steps,
        ], 0640);
        $releaseLock();
        exit(0);
    }

    // ── Step 1: Hash + ingest DB stubs ───────────────────────────────────────
    $pdo           = Database::createFromEnv();
    $uic           = new UnifiedIngestionCore($pdo);
    $eventRepo     = new EventRepository($pdo);
    $eventItemRepo = new EventItemRepository($pdo);

    $total       = count($mediaFiles);
    $processed   = 0;
    $inserted    = 0;
    $duplicates  = 0;
    $errors      = 0;
    $eventsByKey = [];
    $ingestedFiles = [];

    $steps[1]['status']   = 'running';
    $steps[1]['message']  = 'Processed 0 / ' . $total;
    $steps[1]['progress'] = ['processed' => 0, 'total' => $total];
    $writeStatus('running', 'Hashing and ingesting files');

    foreach ($mediaFiles as $f) {
        gighive_manifest_throw_if_canceled($jobDir);

        $processed++;

        $checksum = @hash_file('sha256', $f['path']);
        if ($checksum === false || $checksum === '') {
            $errors++;
            error_log('iphone_import_worker: hash_file failed for ' . $f['path']);
            continue;
        }

        $mtime     = @filemtime($f['path']);
        $eventDate = ($mtime !== false) ? date('Y-m-d', $mtime) : date('Y-m-d');

        $eventKey = $eventDate . '|' . $orgName;
        if (!isset($eventsByKey[$eventKey])) {
            $eventsByKey[$eventKey] = $eventRepo->ensureEvent($eventDate, $orgName, $eventType);
        }

        $result  = $uic->ingestStub([
            'checksum_sha256' => $checksum,
            'file_type'       => $f['type'],
            'file_name'       => $f['name'],
            'source_relpath'  => ltrim(str_replace($stagingDir, '', $f['path']), '/'),
            'size_bytes'      => $f['size'],
        ]);
        $assetId     = (int)$result['asset_id'];
        $wasDuplicate = ($result['status'] === 'skipped');

        if ($wasDuplicate) {
            $duplicates++;
        } else {
            $inserted++;
        }

        $label = pathinfo($f['name'], PATHINFO_FILENAME);
        $eventItemRepo->ensureEventItem((int)$eventsByKey[$eventKey], $assetId, 'clip', $label, null);

        $ingestedFiles[] = array_merge($f, [
            'checksum'     => $checksum,
            'asset_id'     => $assetId,
            'was_duplicate' => $wasDuplicate,
        ]);

        if ($processed % 25 === 0) {
            $steps[1]['message']  = 'Processed ' . $processed . ' / ' . $total;
            $steps[1]['progress'] = ['processed' => $processed, 'total' => $total];
            $writeStatus('running', 'Hashing and ingesting files');
        }
    }

    $steps[1]['status']   = 'ok';
    $steps[1]['message']  = 'Inserted: ' . $inserted . ', duplicates: ' . $duplicates . ($errors > 0 ? ', hash errors: ' . $errors : '');
    $steps[1]['progress'] = ['processed' => $total, 'total' => $total];
    $writeStatus('running', 'Ingestion complete');

    gighive_manifest_throw_if_canceled($jobDir);

    // ── Step 2: Copy files to asset store + ingestComplete ───────────────────
    $copyTotal     = count($ingestedFiles);
    $copyProcessed = 0;
    $copied        = 0;
    $copyErrors    = 0;

    $steps[2]['status']   = 'running';
    $steps[2]['message']  = 'Copying 0 / ' . $copyTotal;
    $steps[2]['progress'] = ['processed' => 0, 'total' => $copyTotal];
    $writeStatus('running', 'Copying files to asset store');

    foreach ($ingestedFiles as $f) {
        gighive_manifest_throw_if_canceled($jobDir);

        $copyProcessed++;
        $storedFileName = $f['checksum'] . '.' . $f['ext'];
        $destDir        = ($f['type'] === 'video') ? '/var/www/html/video' : '/var/www/html/audio';
        $destPath       = $destDir . '/' . $storedFileName;

        if (!file_exists($destPath)) {
            if (!@copy($f['path'], $destPath)) {
                $copyErrors++;
                error_log('iphone_import_worker: copy failed ' . $f['path'] . ' -> ' . $destPath);

                if ($copyProcessed % 10 === 0) {
                    $steps[2]['message']  = 'Copied ' . $copied . ' / ' . $copyTotal;
                    $steps[2]['progress'] = ['processed' => $copyProcessed, 'total' => $copyTotal];
                    $writeStatus('running', 'Copying files to asset store');
                }
                continue;
            }
            $copied++;
        }

        // Probe + finalize (safe to call even if file already existed)
        if (!$f['was_duplicate']) {
            try {
                $uic->ingestComplete(
                    $f['asset_id'],
                    $destPath,
                    $storedFileName,
                    $f['size'],
                    '',
                    $f['type'],
                    $f['checksum']
                );
            } catch (Throwable $e) {
                error_log('iphone_import_worker: ingestComplete failed for asset ' . $f['asset_id'] . ': ' . $e->getMessage());
            }
        }

        if ($copyProcessed % 10 === 0) {
            $steps[2]['message']  = 'Copied ' . $copied . ' / ' . $copyTotal;
            $steps[2]['progress'] = ['processed' => $copyProcessed, 'total' => $copyTotal];
            $writeStatus('running', 'Copying files to asset store');
        }
    }

    $totalErrors = $errors + $copyErrors;
    $steps[2]['status']   = ($totalErrors > 0) ? 'error' : 'ok';
    $steps[2]['message']  = 'Copied: ' . $copied . ($copyErrors > 0 ? ', copy errors: ' . $copyErrors : '');
    $steps[2]['progress'] = ['processed' => $copyTotal, 'total' => $copyTotal];

    gighive_manifest_write_json($jobDir . '/result.json', [
        'success'         => ($totalErrors === 0),
        'job_id'          => $jobId,
        'mode'            => 'add',
        'message'         => 'iPhone import completed.' . ($totalErrors > 0 ? ' ' . $totalErrors . ' error(s) — check worker.log.' : ''),
        'inserted_count'  => $inserted,
        'duplicate_count' => $duplicates,
        'copied_count'    => $copied,
        'error_count'     => $totalErrors,
        'steps'           => $steps,
    ], 0640);

    $releaseLock();

} catch (Throwable $e) {
    $msg        = $e->getMessage();
    $isCanceled = (str_contains(strtolower($msg), 'canceled'));
    if (!$isCanceled) {
        error_log('iphone_import_worker: fatal: ' . $msg);
    }
    @file_put_contents($jobDir . '/result.json', json_encode([
        'success' => false,
        'job_id'  => $jobId,
        'mode'    => 'add',
        'error'   => $isCanceled ? 'Canceled' : 'Worker error',
        'message' => $isCanceled ? 'Canceled by user' : $msg,
        'steps'   => $steps,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n", LOCK_EX);
    $releaseLock();
    exit($isCanceled ? 0 : 1);
}
