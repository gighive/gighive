<?php declare(strict_types=1);
namespace Production\Api\Services;

use Production\Api\Infrastructure\FileStorage;
use Production\Api\Repositories\FileRepository;
use Production\Api\Validation\UploadValidator;
use PDO;

final class UploadService
{
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
            $fileType = in_array($ext, ['mp3','wav','flac','aac'], true) ? 'audio' : (in_array($ext, ['mp4','mov','mkv','webm'], true) ? 'video' : 'unknown');
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

        $baseDir  = dirname(__DIR__, 2); // .../blue_green
        $targetDir = $baseDir . '/' . $fileType; // /audio or /video under blue_green
        $this->storage->ensureDir($targetDir);
        $targetPath = $this->uniquePath($targetDir, $finalName);

        // Compute checksum (before move for reliability)
        $checksum = @hash_file('sha256', $tmpPath) ?: null;

        // Move the file
        $this->storage->moveUploadedFile($tmpPath, $targetPath);

        // Optional: probe duration via ffprobe if available
        $durationSeconds = $this->probeDuration($targetPath);

        // Persist metadata (with session linkage and seq)
        $id = $this->files->create([
            'file_name'       => basename($targetPath),
            'file_type'       => $fileType,
            'session_id'      => $sessionId,
            'seq'             => $seq,
            'duration_seconds'=> $durationSeconds,
            'mime_type'       => $mime ?: null,
            'size_bytes'      => $size ?: null,
            'checksum_sha256' => $checksum,
        ]);

        // Optional: link to a label (song or wedding table name)
        if ($label !== '') {
            $songType = ($eventType === 'wedding') ? 'event_label' : 'song';
            $songId = $this->ensureSong($label, $songType);
            $this->ensureSessionSong($sessionId, $songId);
            $this->linkSongFile($songId, $id);
        }

        // Optional: participants mapping (store names only for now; normalization later)
        if ($participants !== '') {
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

    private function inferType(string $mime, string $ext): string
    {
        $m = strtolower($mime);
        if (str_starts_with($m, 'audio/')) return 'audio';
        if (str_starts_with($m, 'video/')) return 'video';
        // fallback by extension
        if (in_array($ext, ['mp3','wav','flac','aac'], true)) return 'audio';
        if (in_array($ext, ['mp4','mov','mkv','webm'], true)) return 'video';
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
}
