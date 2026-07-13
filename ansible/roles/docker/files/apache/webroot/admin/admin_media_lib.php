<?php declare(strict_types=1);

/**
 * Shared helpers for media export/import workers and endpoints.
 * Phase 0 of the ZIP → tar.gz migration (docs/refactor_admin_export_media.md).
 */

/**
 * Load supported media extensions from UPLOAD_AUDIO_EXTS_JSON / UPLOAD_VIDEO_EXTS_JSON env vars.
 * Returns ['audio' => [...], 'video' => [...], 'audioSet' => [...flipped...], 'videoSet' => [...flipped...]].
 */
function loadMediaExtensions(): array
{
    $parse = static function (string $key): array {
        $raw = getenv($key);
        if (!is_string($raw) || trim($raw) === '') return [];
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) return [];
        $out = [];
        foreach ($decoded as $x) {
            if (is_string($x) && trim($x) !== '') $out[] = strtolower(trim($x));
        }
        return array_values(array_unique($out));
    };

    $audioExts = $parse('UPLOAD_AUDIO_EXTS_JSON') ?: ['mp3', 'wav', 'flac', 'aac', 'ogg', 'm4a'];
    $videoExts = $parse('UPLOAD_VIDEO_EXTS_JSON') ?: ['mp4', 'mov', 'mkv', 'avi', 'webm', 'm4v'];

    return [
        'audio'    => $audioExts,
        'video'    => $videoExts,
        'audioSet' => array_flip($audioExts),
        'videoSet' => array_flip($videoExts),
    ];
}

/**
 * Return true if $name is a valid flat GigHive media entry:
 * - no path separator
 * - 64-char lowercase hex stem
 * - extension is a known audio or video type
 */
function isValidMediaEntry(string $name, array $audioExtsSet, array $videoExtsSet): bool
{
    if (strpos($name, '/') !== false) return false;
    $ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $hash = pathinfo($name, PATHINFO_FILENAME);
    return preg_match('/^[a-f0-9]{64}$/', $hash) === 1
        && (isset($audioExtsSet[$ext]) || isset($videoExtsSet[$ext]));
}

/**
 * Return true if $name is a valid GigHive thumbnail entry:
 * - exactly one directory level: thumbnails/{sha256}.png
 * - no '..' traversal
 * - 64-char lowercase hex stem, .png extension
 */
function isValidThumbnailEntry(string $name): bool
{
    if (str_contains($name, '..')) return false;
    if (!str_starts_with($name, 'thumbnails/')) return false;
    $base = substr($name, strlen('thumbnails/'));
    if (str_contains($base, '/')) return false;
    $hash = pathinfo($base, PATHINFO_FILENAME);
    $ext  = strtolower(pathinfo($base, PATHINFO_EXTENSION));
    return preg_match('/^[a-f0-9]{64}$/', $hash) === 1 && $ext === 'png';
}

/**
 * Execute a tar command via array-form proc_open.
 * Returns ['exit_code' => int, 'stdout' => string, 'stderr' => string].
 * Always injects LC_ALL=C for consistent verbose output parsing.
 * Uses /dev/null for stdin — tar reads from files, not stdin.
 */
function runTar(array $args, ?string $cwd = null, array $env = [], ?callable $onStdoutLine = null): array
{
    $env['LC_ALL'] = 'C';
    $descriptors = [
        0 => ['file', '/dev/null', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $pipes    = [];
    $stdout   = '';
    $stderr   = '';
    $exitCode = -1;

    $handle = proc_open($args, $descriptors, $pipes, $cwd, $env);
    if ($handle === false) {
        return ['exit_code' => -1, 'stdout' => '', 'stderr' => 'proc_open failed'];
    }

    try {
        if ($onStdoutLine !== null) {
            while (($line = fgets($pipes[1])) !== false) {
                ($onStdoutLine)(rtrim($line, "\n"));
            }
        } else {
            $stdout = (string)stream_get_contents($pipes[1]);
        }
        $stderr = (string)stream_get_contents($pipes[2]);
    } finally {
        if (is_resource($pipes[1])) fclose($pipes[1]);
        if (is_resource($pipes[2])) fclose($pipes[2]);
        $exitCode = proc_close($handle);
    }

    return ['exit_code' => $exitCode, 'stdout' => $stdout, 'stderr' => $stderr];
}

/**
 * Write a JSON status payload to $jsonPath with LOCK_EX.
 * Always sets/overwrites 'updated_at' with the current ISO 8601 timestamp.
 */
function writeJobStatus(string $jsonPath, array $payload): void
{
    $payload['updated_at'] = date('c');
    @file_put_contents(
        $jsonPath,
        json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n",
        LOCK_EX
    );
}
