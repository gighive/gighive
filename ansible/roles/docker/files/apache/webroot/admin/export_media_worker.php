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
if ($jobId === '' || !preg_match('/^[a-f0-9]{16}$/', $jobId)) {
    fwrite(STDERR, "export_media_worker: invalid or missing --job_id\n");
    exit(1);
}

$jobDir       = sys_get_temp_dir() . '/gighive_export_' . $jobId . '/';
$filelistPath = $jobDir . 'filelist.json';
$jsonPath     = $jobDir . 'status.json';
$zipPath      = $jobDir . 'archive.zip';

if (!is_dir($jobDir)) {
    fwrite(STDERR, "export_media_worker: job directory not found: $jobDir\n");
    exit(1);
}

$writeStatus = function (array $payload) use ($jsonPath): void {
    $payload['updated_at'] = date('c');
    @file_put_contents($jsonPath, json_encode($payload, JSON_UNESCAPED_SLASHES) . "\n", LOCK_EX);
};

$audioDir = '/var/www/html/audio';
$videoDir = '/var/www/html/video';

try {
    set_time_limit(0);

    if (!is_file($filelistPath)) {
        throw new RuntimeException('filelist.json not found');
    }
    $rows = json_decode((string)file_get_contents($filelistPath), true);
    @unlink($filelistPath);

    if (!is_array($rows)) {
        throw new RuntimeException('Invalid filelist.json content');
    }

    $total      = count($rows);
    $processed  = 0;
    $added      = 0;
    $skipped    = 0;
    $bytesAdded = 0;

    foreach ($rows as $row) {
        $type = (string)($row['file_type']       ?? '');
        $sha  = trim((string)($row['checksum_sha256'] ?? ''));
        $ext  = strtolower(trim((string)($row['file_ext'] ?? '')));

        $processed++;

        if ($sha === '' || preg_match('/^[a-f0-9]{64}$/i', $sha) !== 1) {
            $skipped++;
            continue;
        }

        $dir = match ($type) {
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

        $entryName = $served; // {sha256}.{ext} — hash-based, Phase 2 import-compatible

        $zip = new ZipArchive();
        $rc  = $zip->open($zipPath, ZipArchive::CREATE);
        if ($rc !== true) {
            throw new RuntimeException("ZipArchive::open failed (code $rc)");
        }
        if (!$zip->addFile($filePath, $entryName)) {
            $zip->close();
            throw new RuntimeException("ZipArchive::addFile failed for entry: $entryName");
        }
        if (!$zip->close()) {
            throw new RuntimeException("ZipArchive::close failed (disk full or I/O error) for entry: $entryName");
        }

        $added++;
        $bytesAdded += (int)filesize($filePath);

        if ($processed % 10 === 0) {
            $writeStatus([
                'success'     => true,
                'job_id'      => $jobId,
                'state'       => 'running',
                'processed'   => $processed,
                'total'       => $total,
                'added'       => $added,
                'skipped'     => $skipped,
                'bytes_added' => $bytesAdded,
                'steps'       => [
                    ['name' => 'Build archive', 'status' => 'running',
                     'message'  => $processed . ' / ' . $total . ' written',
                     'progress' => ['processed' => $processed, 'total' => $total]],
                ],
            ]);
        }
    }

    if ($added === 0 && !is_file($zipPath)) {
        $writeStatus([
            'success'       => true,
            'job_id'        => $jobId,
            'state'         => 'error',
            'error_message' => 'No exportable files found on disk',
            'steps'         => [
                ['name' => 'Build archive', 'status' => 'error',
                 'message' => 'No exportable files found on disk (skipped: ' . $skipped . ')'],
            ],
        ]);
        exit(1);
    }

    $writeStatus([
        'success'      => true,
        'job_id'       => $jobId,
        'state'        => 'done',
        'processed'    => $processed,
        'total'        => $total,
        'added'        => $added,
        'skipped'      => $skipped,
        'bytes_added'  => $bytesAdded,
        'completed_at' => date('c'),
        'steps'        => [
            ['name' => 'Build archive', 'status' => 'ok',
             'message'  => $added . ' file(s) written (' . $skipped . ' skipped)',
             'progress' => ['processed' => $total, 'total' => $total]],
        ],
    ]);

} catch (Throwable $e) {
    $writeStatus([
        'success'       => true,
        'job_id'        => $jobId,
        'state'         => 'error',
        'error_message' => $e->getMessage(),
        'steps'         => [
            ['name' => 'Build archive', 'status' => 'error',
             'message' => 'Worker error: ' . $e->getMessage()],
        ],
    ]);
    exit(1);
}
