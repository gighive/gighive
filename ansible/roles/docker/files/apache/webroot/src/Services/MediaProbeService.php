<?php declare(strict_types=1);
namespace Production\Api\Services;

use Production\Api\Config\MediaTypes;

/**
 * Shared media probing and type inference helpers.
 * Extracted from UploadService so all ingestion paths (S1, S3, W1) share
 * identical probe, thumbnail, and MIME-inference behaviour.
 */
final class MediaProbeService
{
    private ?string $ffprobeTool = null;

    public function inferType(string $mime, string $ext): string
    {
        $m = strtolower($mime);
        if (str_starts_with($m, 'audio/')) return 'audio';
        if (str_starts_with($m, 'video/')) return 'video';
        $audioExts = MediaTypes::audioExts();
        if ($audioExts === []) {
            $audioExts = ['mp3', 'wav', 'flac', 'aac'];
        }
        if (in_array($ext, $audioExts, true)) return 'audio';
        $videoExts = MediaTypes::videoExts();
        if ($videoExts === []) {
            $videoExts = ['mp4', 'mov', 'mkv', 'webm'];
        }
        if (in_array($ext, $videoExts, true)) return 'video';
        return 'unknown';
    }

    public function sanitizeFilename(string $name): string
    {
        $name = preg_replace('/[^A-Za-z0-9._-]+/', '-', $name) ?? 'upload.bin';
        $name = trim($name, '-');
        if ($name === '' || $name === '.' || $name === '..') {
            $name = 'upload.bin';
        }
        return $name;
    }

    public function probeDuration(string $path): ?int
    {
        if (class_exists('getID3')) {
            try {
                $getID3 = new \getID3();
                $info = @$getID3->analyze($path);
                if (is_array($info) && isset($info['playtime_seconds'])) {
                    $sec = (int)round((float)$info['playtime_seconds']);
                    if ($sec >= 0) return $sec;
                }
            } catch (\Throwable $e) {
                // ignore and fall through to ffprobe
            }
        }
        $which = @shell_exec('command -v ffprobe 2>/dev/null');
        if (is_string($which) && trim($which) !== '') {
            $cmd = sprintf(
                'ffprobe -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 %s',
                escapeshellarg($path)
            );
            $out = @shell_exec($cmd);
            if (is_string($out)) {
                $out = trim($out);
                if ($out !== '' && is_numeric($out)) {
                    $sec = (int)round((float)$out);
                    if ($sec >= 0) return $sec;
                }
            }
        }
        return null;
    }

    public function ffprobeToolString(): ?string
    {
        if ($this->ffprobeTool !== null) {
            return $this->ffprobeTool;
        }
        $which = @shell_exec('command -v ffprobe 2>/dev/null');
        if (!is_string($which) || trim($which) === '') {
            $this->ffprobeTool = null;
            return null;
        }
        $out = @shell_exec('ffprobe -version 2>/dev/null');
        if (!is_string($out) || trim($out) === '') {
            $this->ffprobeTool = null;
            return null;
        }
        $first = trim(strtok($out, "\n"));
        if (preg_match('/\bversion\s+([^\s]+)/', $first, $m) && isset($m[1])) {
            $this->ffprobeTool = 'ffprobe ' . $m[1];
            return $this->ffprobeTool;
        }
        $this->ffprobeTool = null;
        return null;
    }

    public function probeMediaInfo(string $path): ?string
    {
        $which = @shell_exec('command -v ffprobe 2>/dev/null');
        if (!is_string($which) || trim($which) === '') {
            return null;
        }
        $cmd = sprintf(
            'ffprobe -v error -print_format json -show_format -show_streams -show_chapters -show_programs %s',
            escapeshellarg($path)
        );
        $out = @shell_exec($cmd);
        if (!is_string($out)) {
            return null;
        }
        $out = trim($out);
        if ($out === '') {
            return null;
        }
        $decoded = json_decode($out, true);
        if (!is_array($decoded)) {
            return null;
        }
        if (
            isset($decoded['format'])
            && is_array($decoded['format'])
            && isset($decoded['format']['filename'])
            && is_string($decoded['format']['filename'])
        ) {
            $decoded['format']['filename'] = basename($decoded['format']['filename']);
        }
        return json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function probeMediaCreatedAt(?string $mediaInfoJson): ?string
    {
        if ($mediaInfoJson === null || trim($mediaInfoJson) === '') {
            return null;
        }
        $decoded = json_decode($mediaInfoJson, true);
        if (!is_array($decoded)) {
            return null;
        }
        $raw = null;
        if (
            isset($decoded['format']['tags']['creation_time'])
            && is_string($decoded['format']['tags']['creation_time'])
            && trim($decoded['format']['tags']['creation_time']) !== ''
        ) {
            $raw = trim($decoded['format']['tags']['creation_time']);
        } elseif (
            isset($decoded['streams'][0]['tags']['creation_time'])
            && is_string($decoded['streams'][0]['tags']['creation_time'])
            && trim($decoded['streams'][0]['tags']['creation_time']) !== ''
        ) {
            $raw = trim($decoded['streams'][0]['tags']['creation_time']);
        }
        if ($raw === null) {
            return null;
        }
        // Normalize ISO 8601 (e.g. "2023-08-15T14:22:18.000000Z") → "YYYY-MM-DD HH:MM:SS"
        $normalized = str_replace('T', ' ', $raw);
        $normalized = substr($normalized, 0, 19);
        if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $normalized)) {
            return null;
        }
        return $normalized;
    }

    public function generateVideoThumbnail(string $videoPath, string $sha256, ?int $durationSeconds): void
    {
        $which = @shell_exec('command -v ffmpeg 2>/dev/null');
        if (!is_string($which) || trim($which) === '') {
            return;
        }
        $baseDir  = dirname(__DIR__, 2); // .../webroot
        $thumbDir = $baseDir . '/video/thumbnails';
        if (!is_dir($thumbDir)) {
            if (!mkdir($thumbDir, 0775, true) && !is_dir($thumbDir)) {
                return; // best-effort: never fail the upload on thumbnail dir failure
            }
        }
        $thumbPath = $thumbDir . '/' . $sha256 . '.png';
        if (file_exists($thumbPath)) {
            return;
        }
        $tmpPath = $thumbPath . '.tmp.png';
        $t = $this->pickThumbnailTimestamp($durationSeconds, $sha256);
        $cmd = [
            'ffmpeg',
            '-nostdin',
            '-hide_banner',
            '-loglevel', 'error',
            '-y',
            '-ss', sprintf('%.3f', $t),
            '-i', $videoPath,
            '-frames:v', '1',
            '-vf', 'scale=320:-1',
            '-an',
            '-sn',
            $tmpPath,
        ];
        [$code, $out, $err] = $this->runWithTimeout($cmd, 30);
        if ($code !== 0) {
            @unlink($tmpPath);
            error_log('ffmpeg thumbnail failed (rc=' . $code . '): ' . trim($err ?: $out));
            return;
        }
        if (!@rename($tmpPath, $thumbPath)) {
            @unlink($tmpPath);
        }
    }

    private function pickThumbnailTimestamp(?int $durationSeconds, string $sha256): float
    {
        $dur = is_int($durationSeconds) ? $durationSeconds : 0;
        if ($dur <= 0) return 0.0;
        if ($dur < 4) return max(0.0, $dur / 2.0);
        $start = min(2.0, $dur * 0.10);
        $end   = max($dur - 2.0, $start);
        $prefix = substr($sha256, 0, 8);
        $n = ctype_xdigit($prefix) ? hexdec($prefix) : 0;
        $r = $n / 0xFFFFFFFF;
        $t = $start + $r * ($end - $start);
        if ($t < 0.0) return 0.0;
        if ($t > $dur) return (float)$dur;
        return (float)$t;
    }

    private function runWithTimeout(array $cmd, int $timeoutSeconds): array
    {
        $proc = @proc_open(
            $cmd,
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            null,
            null,
            ['bypass_shell' => true]
        );
        if (!is_resource($proc)) {
            return [127, '', 'proc_open failed'];
        }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $stdout = '';
        $stderr = '';
        $start  = microtime(true);
        while (true) {
            $stdout .= (string)stream_get_contents($pipes[1]);
            $stderr .= (string)stream_get_contents($pipes[2]);
            $status = proc_get_status($proc);
            if (!is_array($status) || !($status['running'] ?? false)) {
                break;
            }
            if ((microtime(true) - $start) > max(1, $timeoutSeconds)) {
                @proc_terminate($proc);
                usleep(200000);
                @proc_terminate($proc, 9);
                break;
            }
            usleep(25000);
        }
        $stdout .= (string)stream_get_contents($pipes[1]);
        $stderr .= (string)stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);
        return [(int)$code, $stdout, $stderr];
    }
}
