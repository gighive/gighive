<?php declare(strict_types=1);

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

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    header('Content-Type: application/json');
    header('Allow: GET');
    echo json_encode(['success' => false, 'error' => 'Method Not Allowed']);
    exit;
}

header('Content-Type: application/json');

$stagingDir   = '/var/iphone-import';
$sentinelFile = $stagingDir . '/.prerequisites_ok';

$videoExts = ['mp4', 'mov', 'm4v'];
$audioExts = ['mp3', 'm4a', 'aac'];

// ── Check staging directory accessibility ────────────────────────────────────
$dirAccessible = is_dir($stagingDir) && is_writable($stagingDir);

if (!$dirAccessible) {
    echo json_encode([
        'success'          => true,
        'dir_accessible'   => false,
        'prerequisites_ok' => false,
        'video_count'      => 0,
        'audio_count'      => 0,
        'total_bytes'      => 0,
        'proxy_warning'    => false,
        'proxy_detail'     => null,
    ]);
    exit;
}

// ── Check sentinel file ───────────────────────────────────────────────────────
$prerequisitesOk = file_exists($sentinelFile);

// ── Walk staging directory recursively and collect media files ────────────────
$videoFiles = [];
$audioFiles = [];
$totalBytes = 0;

try {
    $rit = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($stagingDir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY
    );
    foreach ($rit as $fileInfo) {
        if (!$fileInfo->isFile()) {
            continue;
        }
        $ext  = strtolower($fileInfo->getExtension());
        $size = $fileInfo->getSize();
        if (in_array($ext, $videoExts, true)) {
            $videoFiles[] = $fileInfo->getPathname();
            $totalBytes  += $size;
        } elseif (in_array($ext, $audioExts, true)) {
            $audioFiles[] = $fileInfo->getPathname();
            $totalBytes  += $size;
        }
    }
} catch (\Throwable $e) {
    echo json_encode([
        'success' => false,
        'error'   => 'Failed to scan staging directory: ' . $e->getMessage(),
    ]);
    exit;
}

$videoCount = count($videoFiles);
$audioCount = count($audioFiles);

// ── ffprobe proxy detection — sample up to 10 video files ────────────────────
$proxyWarning  = false;
$proxyDetail   = null;
$proxySampled  = 0;
$proxyFlagged  = 0;
$minHeight     = null;

if ($videoCount > 0) {
    $ffprobeAvailable = false;
    $which = @shell_exec('command -v ffprobe 2>/dev/null');
    if (is_string($which) && trim($which) !== '') {
        $ffprobeAvailable = true;
    }

    if ($ffprobeAvailable) {
        $sampleSize = min(10, $videoCount);
        // Sample evenly across the file list
        $indices = [];
        if ($sampleSize >= $videoCount) {
            $indices = range(0, $videoCount - 1);
        } else {
            for ($i = 0; $i < $sampleSize; $i++) {
                $indices[] = (int)round($i * ($videoCount - 1) / ($sampleSize - 1));
            }
            $indices = array_unique($indices);
        }

        foreach ($indices as $idx) {
            $path = $videoFiles[$idx];
            $cmd  = sprintf(
                'ffprobe -v error -select_streams v:0 -show_entries stream=height -of csv=p=0 %s 2>/dev/null',
                escapeshellarg($path)
            );
            $output = @shell_exec($cmd);
            if (!is_string($output)) {
                continue;
            }
            $height = (int)trim($output);
            if ($height <= 0) {
                continue;
            }
            if ($minHeight === null || $height < $minHeight) {
                $minHeight = $height;
            }
            if ($height < 480) {
                $proxyFlagged++;
            }
            $proxySampled++;
        }

        if ($proxySampled > 0 && $proxyFlagged > 0) {
            $proxyWarning = true;
            $proxyDetail  = [
                'sampled'    => $proxySampled,
                'flagged'    => $proxyFlagged,
                'min_height' => $minHeight,
            ];
        }
    }
}

echo json_encode([
    'success'          => true,
    'dir_accessible'   => $dirAccessible,
    'prerequisites_ok' => $prerequisitesOk,
    'video_count'      => $videoCount,
    'audio_count'      => $audioCount,
    'total_bytes'      => $totalBytes,
    'proxy_warning'    => $proxyWarning,
    'proxy_detail'     => $proxyDetail,
    'proxy_sampled'    => $proxySampled,
]);
