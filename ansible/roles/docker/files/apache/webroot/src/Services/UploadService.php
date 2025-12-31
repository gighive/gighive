<?php declare(strict_types=1);
namespace Production\Api\Services;

use Production\Api\Config\MediaTypes;
use Production\Api\Infrastructure\FileStorage;
use Production\Api\Repositories\FileRepository;
use Production\Api\Validation\UploadValidator;
use PDO;

final class UploadService
{
    private ?string $ffprobeTool = null;

    public function __construct(
        private PDO $pdo,
        private ?UploadValidator $validator = null,
        private ?FileStorage $storage = null,
        private ?FileRepository $files = null,
    ) {
        $this->validator = $this->validator ?? new UploadValidator();
        $this->storage   = $this->storage   ?? new FileStorage();
        $this->files     = $this->files     ?? new FileRepository($pdo);
    }

    /**
     * Handle a single file upload. Returns a map suitable for API JSON response.
     * @param array $files Typically $_FILES
     * @param array $post  Typically $_POST (may include session_id, title, etc.)
     */
    public function handleUpload(array $files, array $post): array
    {
        $this->validator->validateFilesArray($files);

        $f = $files['file'];
        $tmpPath  = (string)($f['tmp_name'] ?? '');
        $origName = (string)($f['name'] ?? 'upload.bin');
        $size     = (int)($f['size'] ?? 0);
        $mime     = (string)($f['type'] ?? 'application/octet-stream');

        // Decide file_type and target directory
        $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
        $fileType = $this->inferType($mime, $ext); // 'audio' | 'video' | 'unknown'
        if ($fileType === 'unknown') {
            // Map common extensions if mime is unreliable
            $audioExts = MediaTypes::audioExts();
            if ($audioExts === []) {
                $audioExts = ['mp3','wav','flac','aac'];
            }
            $videoExts = MediaTypes::videoExts();
            if ($videoExts === []) {
                $videoExts = ['mp4','mov','mkv','webm'];
            }
            $fileType = in_array($ext, $audioExts, true) ? 'audio' : (in_array($ext, $videoExts, true) ? 'video' : 'unknown');
        }
        if ($fileType === 'unknown') {
            throw new \InvalidArgumentException('Unsupported media type');
        }

        // Event context
        $eventDate = trim((string)($post['event_date'] ?? date('Y-m-d')));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $eventDate)) {
            throw new \InvalidArgumentException('Invalid event_date format; expected YYYY-MM-DD');
        }
        $orgName   = trim((string)($post['org_name'] ?? 'StormPigs')); // default for blue_green
        $eventType = trim((string)($post['event_type'] ?? 'band'));
        $label     = trim((string)($post['label'] ?? ''));
        $participants = trim((string)($post['participants'] ?? ''));
        $keywords   = trim((string)($post['keywords'] ?? ''));
        $location  = trim((string)($post['location'] ?? ''));
        $rating    = trim((string)($post['rating'] ?? ''));
        $notes     = trim((string)($post['notes'] ?? ''));

        // Validate required fields
        if ($label === '') {
            throw new \InvalidArgumentException('Label is required');
        }

        // Ensure a session exists (by date + org_name); create if not present
        $sessionId = $this->ensureSession($eventDate, $orgName, $eventType, $location, $rating, $notes, $keywords);

        // Compute next per-session sequence
        $seq = $this->files->nextSequence($sessionId);

        // Canonical filename: {orgslug}{YYYYMMDD}_{seqPadded}_{labelslug}.{ext}
        $orgSlug   = $this->slugify($orgName);
        $ymd       = str_replace('-', '', $eventDate);
        $padWidthEnv = getenv('FILENAME_SEQ_PAD');
        $padWidth = is_string($padWidthEnv) && ctype_digit($padWidthEnv) ? max(1, min(9, (int)$padWidthEnv)) : 5;
        $seqPadded = str_pad((string)$seq, $padWidth, '0', STR_PAD_LEFT);
        $labelSlug = $label !== '' ? $this->slugify($label) : 'media';
        $finalName = sprintf('%s%s_%s_%s.%s', $orgSlug, $ymd, $seqPadded, $labelSlug, $ext ?: 'bin');

        $baseDir  = dirname(__DIR__, 2); // .../webroot (app web root directory)
        $targetDir = $baseDir . '/' . $fileType; // /audio or /video under webroot
        $this->storage->ensureDir($targetDir);
        $targetPath = $this->uniquePath($targetDir, $finalName);

        // Compute checksum (before move for reliability)
        $checksum = @hash_file('sha256', $tmpPath) ?: null;

        // Store on disk as {sha256}.{ext} when possible, but keep canonical name in DB.
        if ($checksum !== null) {
            $checksumNorm = strtolower(trim($checksum));
            if (preg_match('/^[0-9a-f]{64}$/', $checksumNorm) === 1) {
                $storedName = $ext !== '' ? ($checksumNorm . '.' . $ext) : $checksumNorm;
                $targetPath = $this->uniquePath($targetDir, $storedName);
                $checksum = $checksumNorm;
            }
        }

        // Move the file
        $this->storage->moveUploadedFile($tmpPath, $targetPath);

        // Optional: probe duration via ffprobe if available
        $durationSeconds = $this->probeDuration($targetPath);

        if ($fileType === 'video' && is_string($checksum) && preg_match('/^[0-9a-f]{64}$/', $checksum) === 1) {
            $this->generateVideoThumbnail($targetPath, $checksum, $durationSeconds);
        }

        $mediaInfo = $this->probeMediaInfo($targetPath);
        $mediaInfoTool = $mediaInfo !== null ? $this->ffprobeToolString() : null;

        // Persist metadata (with session linkage and seq)
        $id = $this->files->create([
            'file_name'       => basename($targetPath),
            'source_relpath'  => $finalName,
            'file_type'       => $fileType,
            'session_id'      => $sessionId,
            'seq'             => $seq,
            'duration_seconds'=> $durationSeconds,
            'media_info'      => $mediaInfo,
            'media_info_tool' => $mediaInfoTool,
            'mime_type'       => $mime ?: null,
            'size_bytes'      => $size ?: null,
            'checksum_sha256' => $checksum,
        ]);

        // Link to a label (song or wedding table name)
        $songType = ($eventType === 'wedding') ? 'event_label' : 'song';
        $songId = $this->ensureSong($label, $songType);
        $this->ensureSessionSong($sessionId, $songId);
        $this->linkSongFile($songId, $id);

        // Optional: participants mapping (now gated by env to avoid session-wide pollution)
        // Set UPLOAD_PARTICIPANTS_MODE to 'session' to enable previous behavior.
        // Future modes could include 'file' or 'song' after schema changes.
        $participantsMode = getenv('UPLOAD_PARTICIPANTS_MODE') ?: 'none'; // default: do nothing
        if ($participantsMode === 'session' && $participants !== '') {
            $this->attachParticipants($sessionId, $participants);
        }

        return [
            'id'              => $id,
            'file_name'       => $finalName,
            'file_type'       => $fileType,
            'mime_type'       => $mime,
            'size_bytes'      => $size,
            'checksum_sha256' => $checksum,
            'session_id'      => $sessionId,
            'event_date'      => $eventDate,
            'org_name'        => $orgName,
            'event_type'      => $eventType,
            'seq'             => $seq,
            'label'           => $label,
            'participants'    => $participants,
            'keywords'        => $keywords,
            'duration_seconds'=> $durationSeconds,
        ];
    }

    private function pickThumbnailTimestamp(?int $durationSeconds, string $sha256): float
    {
        $dur = is_int($durationSeconds) ? $durationSeconds : 0;
        if ($dur <= 0) return 0.0;
        if ($dur < 4) return max(0.0, $dur / 2.0);

        $start = min(2.0, $dur * 0.10);
        $end = max($dur - 2.0, $start);

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
        $start = microtime(true);

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

    private function generateVideoThumbnail(string $videoPath, string $sha256, ?int $durationSeconds): void
    {
        // Best-effort: never fail the upload if thumbnail generation fails.
        $which = @shell_exec('command -v ffmpeg 2>/dev/null');
        if (!is_string($which) || trim($which) === '') {
            return;
        }

        $baseDir = dirname(__DIR__, 2); // .../webroot
        $thumbDir = $baseDir . '/video/thumbnails';
        $this->storage->ensureDir($thumbDir);

        $thumbPath = $thumbDir . '/' . $sha256 . '.png';
        if (file_exists($thumbPath)) {
            return;
        }

        $tmpPath = $thumbPath . '.tmp.png';
        $t = $this->pickThumbnailTimestamp($durationSeconds, $sha256);
        $timeout = 30;
        $width = 320;

        $cmd = [
            'ffmpeg',
            '-nostdin',
            '-hide_banner',
            '-loglevel', 'error',
            '-y',
            '-ss', sprintf('%.3f', $t),
            '-i', $videoPath,
            '-frames:v', '1',
            '-vf', 'scale=' . $width . ':-1',
            '-an',
            '-sn',
            $tmpPath,
        ];

        [$code, $out, $err] = $this->runWithTimeout($cmd, $timeout);
        if ($code !== 0) {
            @unlink($tmpPath);
            error_log('ffmpeg thumbnail failed (rc=' . $code . '): ' . trim($err ?: $out));
            return;
        }

        if (!@rename($tmpPath, $thumbPath)) {
            @unlink($tmpPath);
        }
    }

    private function inferType(string $mime, string $ext): string
    {
        $m = strtolower($mime);
        if (str_starts_with($m, 'audio/')) return 'audio';
        if (str_starts_with($m, 'video/')) return 'video';
        // fallback by extension
        $audioExts = MediaTypes::audioExts();
        if ($audioExts === []) {
            $audioExts = ['mp3','wav','flac','aac'];
        }
        if (in_array($ext, $audioExts, true)) return 'audio';

        $videoExts = MediaTypes::videoExts();
        if ($videoExts === []) {
            $videoExts = ['mp4','mov','mkv','webm'];
        }
        if (in_array($ext, $videoExts, true)) return 'video';
        return 'unknown';
    }

    private function sanitizeFilename(string $name): string
    {
        $name = preg_replace('/[^A-Za-z0-9._-]+/', '-', $name) ?? 'upload.bin';
        $name = trim($name, '-');
        if ($name === '' || $name === '.' || $name === '..') {
            $name = 'upload.bin';
        }
        return $name;
    }

    private function uniquePath(string $dir, string $name): string
    {
        $base = pathinfo($name, PATHINFO_FILENAME);
        $ext  = pathinfo($name, PATHINFO_EXTENSION);
        $candidate = $name;
        $i = 1;
        while (file_exists($dir . '/' . $candidate)) {
            $candidate = $base . '-' . $i++ . ($ext !== '' ? '.' . $ext : '');
        }
        return $dir . '/' . $candidate;
    }

    private function slugify(string $s): string
    {
        $s = strtolower($s);
        $s = preg_replace('/[^a-z0-9]+/', '-', $s) ?? '';
        $s = trim($s, '-');
        return $s !== '' ? $s : 'item';
    }

    private function ensureSession(string $date, string $orgName, string $eventType, string $location, string $rating, string $notes, string $keywords): int
    {
        // Try find by (date, org_name)
        $stmt = $this->pdo->prepare('SELECT session_id FROM sessions WHERE date = :d AND org_name = :o LIMIT 1');
        $stmt->execute([':d' => $date, ':o' => $orgName]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row && isset($row['session_id'])) {
            $sid = (int)$row['session_id'];
            // If keywords provided, update session keywords (append if non-empty)
            if ($keywords !== '') {
                $this->pdo->prepare('UPDATE sessions SET keywords = :kw WHERE session_id = :sid')
                    ->execute([':kw' => $keywords, ':sid' => $sid]);
            }
            return $sid;
        }

        // Create new session with basic fields
        $sql = 'INSERT INTO sessions (title, date, location, summary, rating, event_type, org_name, keywords)'
             . ' VALUES (:title, :date, :location, :summary, :rating, :etype, :org, :kw)';
        $stmt = $this->pdo->prepare($sql);
        $title = $orgName . ' ' . $date;
        $stmt->execute([
            ':title'    => $title,
            ':date'     => $date,
            ':location' => $location !== '' ? $location : null,
            ':summary'  => $notes !== '' ? $notes : null,
            ':rating'   => $rating !== '' ? $rating : null,
            ':etype'    => $eventType !== '' ? $eventType : null,
            ':org'      => $orgName,
            ':kw'       => $keywords !== '' ? $keywords : null,
        ]);
        return (int)$this->pdo->lastInsertId();
    }

    private function ensureSong(string $title, string $type): int
    {
        $stmt = $this->pdo->prepare('SELECT song_id FROM songs WHERE title = :t AND type = :ty LIMIT 1');
        $stmt->execute([':t' => $title, ':ty' => $type]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row && isset($row['song_id'])) {
            return (int)$row['song_id'];
        }
        $stmt = $this->pdo->prepare('INSERT INTO songs (title, type) VALUES (:t, :ty)');
        $stmt->execute([':t' => $title, ':ty' => $type]);
        return (int)$this->pdo->lastInsertId();
    }

    private function ensureSessionSong(int $sessionId, int $songId): void
    {
        // Use INSERT IGNORE semantics
        $sql = 'INSERT INTO session_songs (session_id, song_id) VALUES (:s, :g)'
             . ' ON DUPLICATE KEY UPDATE position = position';
        $this->pdo->prepare($sql)->execute([':s' => $sessionId, ':g' => $songId]);
    }

    private function linkSongFile(int $songId, int $fileId): void
    {
        $sql = 'INSERT INTO song_files (song_id, file_id) VALUES (:g, :f)'
             . ' ON DUPLICATE KEY UPDATE file_id = file_id';
        $this->pdo->prepare($sql)->execute([':g' => $songId, ':f' => $fileId]);
    }

    private function attachParticipants(int $sessionId, string $csv): void
    {
        $names = array_filter(array_map('trim', explode(',', $csv)));
        if (!$names) return;
        foreach ($names as $name) {
            // ensure musician row
            $stmt = $this->pdo->prepare('SELECT musician_id FROM musicians WHERE name = :n LIMIT 1');
            $stmt->execute([':n' => $name]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $mid = $row['musician_id'] ?? null;
            if (!$mid) {
                $this->pdo->prepare('INSERT INTO musicians (name) VALUES (:n)')->execute([':n' => $name]);
                $mid = (int)$this->pdo->lastInsertId();
            } else {
                $mid = (int)$mid;
            }
            // link to session
            $sql = 'INSERT INTO session_musicians (session_id, musician_id) VALUES (:s, :m)'
                 . ' ON DUPLICATE KEY UPDATE role = role';
            $this->pdo->prepare($sql)->execute([':s' => $sessionId, ':m' => $mid]);
        }
    }

    private function probeDuration(string $path): ?int
    {
        // 1) Try getID3 (pure PHP, lightweight)
        if (class_exists('getID3')) {
            try {
                $getID3 = new \getID3();
                $info = @$getID3->analyze($path);
                if (is_array($info) && isset($info['playtime_seconds'])) {
                    $sec = (int)round((float)$info['playtime_seconds']);
                    if ($sec >= 0) return $sec;
                }
            } catch (\Throwable $e) {
                // ignore and try fallback
            }
        }

        // 2) Fallback to ffprobe if available in PATH
        $which = @shell_exec('command -v ffprobe 2>/dev/null');
        if (is_string($which) && trim($which) !== '') {
            $cmd = sprintf('ffprobe -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 %s', escapeshellarg($path));
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

    private function ffprobeToolString(): ?string
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

    private function probeMediaInfo(string $path): ?string
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
}
