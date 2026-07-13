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
    fwrite(STDERR, "import_media_zip_worker: invalid or missing --job_id\n");
    exit(1);
}

$jobDir   = sys_get_temp_dir() . '/gighive_import_' . $jobId . '/';
$jsonPath = $jobDir . 'status.json';

if (!is_dir($jobDir)) {
    fwrite(STDERR, "import_media_zip_worker: job directory not found: $jobDir\n");
    exit(1);
}

require_once __DIR__ . '/admin_media_lib.php';
$exts         = loadMediaExtensions();
$audioExtsSet = $exts['audioSet'];
$videoExtsSet = $exts['videoSet'];

// Read format.txt — strict allowlist, never default
$formatRaw = is_file($jobDir . 'format.txt') ? trim((string)file_get_contents($jobDir . 'format.txt')) : '';
if ($formatRaw !== 'zip' && $formatRaw !== 'tar.gz') {
    fwrite(STDERR, "import_media_zip_worker: invalid or missing format.txt\n");
    exit(1);
}
$format      = $formatRaw;
$archivePath = $jobDir . ($format === 'tar.gz' ? 'upload.tar.gz' : 'upload.zip');

try {
    set_time_limit(0);

    if (!is_file($archivePath) || !is_readable($archivePath)) {
        writeJobStatus($jsonPath, [
            'success'       => true,
            'job_id'        => $jobId,
            'state'         => 'error',
            'error_message' => 'Archive file not found',
            'steps'         => [
                ['name' => 'Import files', 'status' => 'error', 'message' => 'Archive file not found'],
            ],
        ]);
        exit(1);
    }

    $processed        = 0;
    $added            = 0;
    $alreadyExists    = 0;
    $bytesAdded       = 0;
    $unsupportedCount = 0;
    $errors           = [];
    $total            = 0;

    // Destination directory check (shared by both branches)
    if (!is_dir('/var/www/html/audio') || !is_dir('/var/www/html/video')) {
        throw new RuntimeException('Destination directory /var/www/html/audio or /var/www/html/video does not exist — volume may not be mounted');
    }

    if ($format === 'zip') {
        // ── ZIP branch (unchanged) ────────────────────────────────────────────────────────────

        $zip = new ZipArchive();
        $rc  = $zip->open($archivePath, ZipArchive::RDONLY);
        if ($rc !== true) {
            writeJobStatus($jsonPath, [
                'success'       => true,
                'job_id'        => $jobId,
                'state'         => 'error',
                'error_message' => "ZipArchive::open failed (code $rc)",
                'steps'         => [
                    ['name' => 'Import files', 'status' => 'error', 'message' => "ZipArchive::open failed (code $rc)"],
                ],
            ]);
            exit(1);
        }

        $uncompressedTotal = 0;

        // Pre-scan: count valid entries and total uncompressed bytes
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if ($stat === false) continue;
            $uncompressedTotal += (int)($stat['size'] ?? 0);
            $name = (string)($stat['name'] ?? '');
            if (isValidMediaEntry($name, $audioExtsSet, $videoExtsSet)) {
                $total++;
            }
        }

        writeJobStatus($jsonPath, [
            'success'        => true,
            'job_id'         => $jobId,
            'state'          => 'running',
            'processed'      => 0,
            'total'          => $total,
            'added'          => 0,
            'already_exists' => 0,
            'bytes_added'    => 0,
            'steps'          => [
                ['name' => 'Import files', 'status' => 'running',
                 'message'  => '0 / ' . $total . ' files imported',
                 'progress' => ['processed' => 0, 'total' => $total]],
            ],
        ]);

        $freeBytes = disk_free_space('/var/www/html');
        if ($freeBytes !== false && $freeBytes < $uncompressedTotal * 1.1) {
            $zip->close();
            writeJobStatus($jsonPath, [
                'success'       => true,
                'job_id'        => $jobId,
                'state'         => 'error',
                'error_message' => 'Insufficient disk space on destination volume',
                'steps'         => [
                    ['name' => 'Import files', 'status' => 'error', 'message' => 'Insufficient disk space on destination volume'],
                ],
            ]);
            exit(1);
        }

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if ($stat === false) { $unsupportedCount++; continue; }

            $name = (string)($stat['name'] ?? '');
            $ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            $hash = pathinfo($name, PATHINFO_FILENAME);

            if (!isValidMediaEntry($name, $audioExtsSet, $videoExtsSet)) {
                $unsupportedCount++;
                continue;
            }

            $processed++;
            $type = isset($audioExtsSet[$ext]) ? 'audio' : 'video';
            $dest = '/var/www/html/' . $type . '/' . $hash . '.' . $ext;

            if (is_file($dest)) {
                $alreadyExists++;
            } else {
                $stream = $zip->getStream($name);
                if ($stream === false) {
                    $errors[] = 'getStream failed: ' . $name;
                    continue;
                }

                $fp = fopen($dest, 'wb');
                if ($fp === false) {
                    fclose($stream);
                    $errors[] = 'fopen failed: ' . $dest;
                    continue;
                }

                $copied = stream_copy_to_stream($stream, $fp);
                if ($copied === false) {
                    fclose($fp);
                    fclose($stream);
                    @unlink($dest);
                    $errors[] = 'stream_copy failed: ' . $name;
                    continue;
                }

                if (!fclose($fp)) {
                    fclose($stream);
                    @unlink($dest);
                    $errors[] = 'fclose failed (disk full?): ' . $name;
                    continue;
                }

                fclose($stream);
                $added++;
                $bytesAdded += (int)($stat['size'] ?? 0);
            }

            if ($processed % 10 === 0) {
                writeJobStatus($jsonPath, [
                    'success'        => true,
                    'job_id'         => $jobId,
                    'state'          => 'running',
                    'processed'      => $processed,
                    'total'          => $total,
                    'added'          => $added,
                    'already_exists' => $alreadyExists,
                    'bytes_added'    => $bytesAdded,
                    'steps'          => [
                        ['name' => 'Import files', 'status' => 'running',
                         'message'  => $processed . ' / ' . $total . ' files imported',
                         'progress' => ['processed' => $processed, 'total' => $total]],
                    ],
                ]);
            }
        }

        $zip->close();
        @unlink($archivePath);

    } else {
        // ── tar.gz branch ───────────────────────────────────────────────────────────

        $extractDir = $jobDir . 'extract/';
        if (!mkdir($extractDir, 0700, true)) {
            throw new RuntimeException('Failed to create extraction directory');
        }

        try {
            // Pre-scan: list entries, reject '..' traversal, count valid entries
            $scanResult = runTar(['tar', '-tzvf', $archivePath]);
            if ($scanResult['exit_code'] !== 0) {
                throw new RuntimeException('Cannot read archive: ' . trim($scanResult['stderr']));
            }
            $lineCount = 0;
            foreach (explode("\n", trim($scanResult['stdout'])) as $line) {
                $line = trim($line);
                if ($line === '') continue;
                if (++$lineCount > 50000) {
                    throw new RuntimeException('Archive contains too many entries (limit: 50,000)');
                }
                $parts = preg_split('/\s+/', $line, 6);
                $name  = isset($parts[5]) ? trim($parts[5]) : '';
                $size  = isset($parts[2]) ? (int)$parts[2] : 0;
                if (str_contains($name, '..')) {
                    throw new RuntimeException('Suspicious entry name in archive: ' . $name);
                }
                if (isValidMediaEntry($name, $audioExtsSet, $videoExtsSet)) {
                    $total++;
                } elseif (isValidThumbnailEntry($name)) {
                    $total++;
                } else {
                    $unsupportedCount++;
                }
            }

            writeJobStatus($jsonPath, [
                'success'        => true,
                'job_id'         => $jobId,
                'state'          => 'running',
                'processed'      => 0,
                'total'          => $total,
                'added'          => 0,
                'already_exists' => 0,
                'bytes_added'    => 0,
                'steps'          => [
                    ['name' => 'Import files', 'status' => 'running',
                     'message'  => '0 / ' . $total . ' files imported',
                     'progress' => ['processed' => 0, 'total' => $total]],
                ],
            ]);

            // Extract archive into subdir
            $extractResult = runTar(['tar', '-xzvf', $archivePath, '--directory', $extractDir]);
            if ($extractResult['exit_code'] !== 0) {
                throw new RuntimeException('tar extraction failed: ' . trim($extractResult['stderr']));
            }

            // Iterate extracted files: validate, containment-check, copy to destination
            $realExtractDir = realpath($extractDir);
            if ($realExtractDir === false) {
                throw new RuntimeException('Cannot resolve extraction directory path');
            }

            foreach (glob($extractDir . '*') ?: [] as $filePath) {
                $name = basename($filePath);
                $ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                $hash = pathinfo($name, PATHINFO_FILENAME);

                if (!isValidMediaEntry($name, $audioExtsSet, $videoExtsSet)) {
                    $unsupportedCount++;
                    @unlink($filePath);
                    continue;
                }

                // Containment check — defence-in-depth against symlink attacks
                $realFile = realpath($filePath);
                if ($realFile === false ||
                    strncmp($realFile, $realExtractDir . DIRECTORY_SEPARATOR,
                             strlen($realExtractDir) + 1) !== 0) {
                    $unsupportedCount++;
                    continue;
                }

                $processed++;
                $type = isset($audioExtsSet[$ext]) ? 'audio' : 'video';
                $dest = '/var/www/html/' . $type . '/' . $hash . '.' . $ext;

                if (is_file($dest)) {
                    $alreadyExists++;
                    @unlink($filePath);
                } else {
                    // Cross-device copy: extracted subdir may be on tmpfs, dest on media volume
                    $fileBytes = (int)filesize($filePath);
                    if (!copy($filePath, $dest)) {
                        $errors[] = 'copy failed: ' . $name;
                        @unlink($filePath);
                        continue;
                    }
                    @unlink($filePath);
                    $added++;
                    $bytesAdded += $fileBytes;
                }

                if ($processed % 10 === 0) {
                    writeJobStatus($jsonPath, [
                        'success'        => true,
                        'job_id'         => $jobId,
                        'state'          => 'running',
                        'processed'      => $processed,
                        'total'          => $total,
                        'added'          => $added,
                        'already_exists' => $alreadyExists,
                        'bytes_added'    => $bytesAdded,
                        'steps'          => [
                            ['name' => 'Import files', 'status' => 'running',
                             'message'  => $processed . ' / ' . $total . ' files imported',
                             'progress' => ['processed' => $processed, 'total' => $total]],
                        ],
                    ]);
                }
            }

            // Second pass: thumbnail files in thumbnails/ subdir
            $thumbDestDir = '/var/www/html/video/thumbnails';
            @mkdir($thumbDestDir, 0775, true);
            $realThumbExtractDir = is_dir($extractDir . 'thumbnails') ? realpath($extractDir . 'thumbnails') : false;
            foreach (glob($extractDir . 'thumbnails/*.png') ?: [] as $thumbFilePath) {
                $thumbName = basename($thumbFilePath);
                if (!isValidThumbnailEntry('thumbnails/' . $thumbName)) {
                    $unsupportedCount++;
                    @unlink($thumbFilePath);
                    continue;
                }
                $realThumb = realpath($thumbFilePath);
                if ($realThumb === false || $realThumbExtractDir === false ||
                    strncmp($realThumb, $realThumbExtractDir . DIRECTORY_SEPARATOR,
                             strlen($realThumbExtractDir) + 1) !== 0) {
                    $unsupportedCount++;
                    continue;
                }
                $processed++;
                $dest = $thumbDestDir . '/' . $thumbName;
                if (is_file($dest)) {
                    $alreadyExists++;
                    @unlink($thumbFilePath);
                } else {
                    $fileBytes = (int)filesize($thumbFilePath);
                    if (!copy($thumbFilePath, $dest)) {
                        $errors[] = 'thumbnail copy failed: ' . $thumbName;
                        @unlink($thumbFilePath);
                        continue;
                    }
                    @unlink($thumbFilePath);
                    $added++;
                    $bytesAdded += $fileBytes;
                }
                if ($processed % 10 === 0) {
                    writeJobStatus($jsonPath, [
                        'success'        => true,
                        'job_id'         => $jobId,
                        'state'          => 'running',
                        'processed'      => $processed,
                        'total'          => $total,
                        'added'          => $added,
                        'already_exists' => $alreadyExists,
                        'bytes_added'    => $bytesAdded,
                        'steps'          => [
                            ['name' => 'Import files', 'status' => 'running',
                             'message'  => $processed . ' / ' . $total . ' files imported',
                             'progress' => ['processed' => $processed, 'total' => $total]],
                        ],
                    ]);
                }
            }
            @rmdir($extractDir . 'thumbnails/');

            @unlink($archivePath);

        } finally {
            // Always remove the extraction subdir (thumbnails/ first, then flat files)
            foreach (glob($extractDir . 'thumbnails/*.png') ?: [] as $f) { @unlink($f); }
            @rmdir($extractDir . 'thumbnails/');
            foreach (glob($extractDir . '*') ?: [] as $f) { @unlink($f); }
            @rmdir($extractDir);
        }
    }

    if ($added === 0 && $alreadyExists === 0) {
        writeJobStatus($jsonPath, [
            'success'       => true,
            'job_id'        => $jobId,
            'state'         => 'error',
            'error_message' => 'No valid GigHive media entries found in archive',
            'steps'         => [
                ['name' => 'Import files', 'status' => 'error',
                 'message' => 'No valid GigHive media entries found in archive'],
            ],
        ]);
        exit(1);
    }

    // Build final summary message
    $bytesHuman = $bytesAdded >= 1073741824
        ? round($bytesAdded / 1073741824, 1) . ' GB'
        : round($bytesAdded / 1048576, 1) . ' MB';
    $errNote = count($errors) > 0
        ? ', ' . count($errors) . ' error(s) — see worker.log'
        : '';
    $finalMsg = $added . ' added, '
        . $alreadyExists . ' already on disk, '
        . $unsupportedCount . ' skipped (unsupported)'
        . $errNote . ' (' . $bytesHuman . ' added)';

    writeJobStatus($jsonPath, [
        'success'        => true,
        'job_id'         => $jobId,
        'state'          => 'done',
        'processed'      => $processed,
        'total'          => $total,
        'added'          => $added,
        'already_exists' => $alreadyExists,
        'bytes_added'    => $bytesAdded,
        'completed_at'   => date('c'),
        'errors'         => $errors,
        'steps'          => [
            ['name' => 'Import files', 'status' => 'ok',
             'message'  => $finalMsg,
             'progress' => ['processed' => $total, 'total' => $total]],
        ],
    ]);

} catch (Throwable $e) {
    writeJobStatus($jsonPath, [
        'success'       => true,
        'job_id'        => $jobId,
        'state'         => 'error',
        'error_message' => $e->getMessage(),
        'steps'         => [
            ['name' => 'Import files', 'status' => 'error',
             'message' => $e->getMessage()],
        ],
    ]);
    exit(1);
}
