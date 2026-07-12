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
$archivePath   = $jobDir . 'archive.tar.gz';
$audioListPath = $jobDir . 'audio_files.txt';
$videoListPath = $jobDir . 'video_files.txt';

if (!is_dir($jobDir)) {
    fwrite(STDERR, "export_media_worker: job directory not found: $jobDir\n");
    exit(1);
}

require_once __DIR__ . '/admin_media_lib.php';

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

    $skipped    = 0;
    $bytesAdded = 0;
    $audioFiles = [];
    $videoFiles = [];

    foreach ($rows as $row) {
        $type = (string)($row['file_type']       ?? '');
        $sha  = trim((string)($row['checksum_sha256'] ?? ''));
        $ext  = strtolower(trim((string)($row['file_ext'] ?? '')));

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

        $filename = $ext !== '' ? ($sha . '.' . $ext) : $sha;
        $filePath = $dir . '/' . $filename;

        if (!is_file($filePath)) {
            $skipped++;
            continue;
        }

        if ($type === 'audio') {
            $audioFiles[] = $filename;
        } else {
            $videoFiles[] = $filename;
        }
        $bytesAdded += (int)filesize($filePath);
    }

    $added = count($audioFiles) + count($videoFiles);

    // Zero-file guard — must check before calling tar
    if ($added === 0) {
        writeJobStatus($jsonPath, [
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

    // Write flat filelists (bare filenames, one per line)
    if ($audioFiles !== []) {
        file_put_contents($audioListPath, implode("\n", $audioFiles) . "\n");
    }
    if ($videoFiles !== []) {
        file_put_contents($videoListPath, implode("\n", $videoFiles) . "\n");
    }

    // Build tar command — omit -C pair for any empty list
    $tarArgs = ['tar', '-czvf', $archivePath];
    if ($audioFiles !== []) {
        array_push($tarArgs, '-C', $audioDir, '--files-from', $audioListPath);
    }
    if ($videoFiles !== []) {
        array_push($tarArgs, '-C', $videoDir, '--files-from', $videoListPath);
    }

    // Option A progress: verbose stdout line = one file added; update status.json every 10
    $verboseCount = 0;
    $result = runTar($tarArgs, null, [], static function (string $line) use (&$verboseCount, $added, $skipped, $jsonPath, $jobId): void {
        if ($line === '') return;
        $verboseCount++;
        if ($verboseCount % 10 === 0) {
            writeJobStatus($jsonPath, [
                'success'     => true,
                'job_id'      => $jobId,
                'state'       => 'running',
                'processed'   => $verboseCount,
                'total'       => $added,
                'added'       => $verboseCount,
                'skipped'     => $skipped,
                'bytes_added' => 0,
                'steps'       => [
                    ['name' => 'Build archive', 'status' => 'running',
                     'message'  => $verboseCount . ' / ' . $added . ' written',
                     'progress' => ['processed' => $verboseCount, 'total' => $added]],
                ],
            ]);
        }
    });

    // Cleanup filelists regardless of tar outcome
    @unlink($audioListPath);
    @unlink($videoListPath);

    if ($result['exit_code'] !== 0) {
        @unlink($archivePath);
        throw new RuntimeException('tar failed (exit ' . $result['exit_code'] . '): ' . trim($result['stderr']));
    }

    writeJobStatus($jsonPath, [
        'success'       => true,
        'job_id'        => $jobId,
        'state'         => 'done',
        'processed'     => $added,
        'total'         => $added,
        'added'         => $added,
        'skipped'       => $skipped,
        'bytes_added'   => $bytesAdded,
        'archive_bytes' => (int)filesize($archivePath),
        'completed_at'  => date('c'),
        'steps'         => [
            ['name' => 'Build archive', 'status' => 'ok',
             'message'  => $added . ' file(s) written (' . $skipped . ' skipped)',
             'progress' => ['processed' => $added, 'total' => $added]],
        ],
    ]);

} catch (Throwable $e) {
    @unlink($archivePath);
    @unlink($audioListPath);
    @unlink($videoListPath);
    writeJobStatus($jsonPath, [
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
