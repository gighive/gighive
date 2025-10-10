<?php declare(strict_types=1);
namespace Production\Api\Validation;

final class UploadValidator
{
    private int $maxBytes;
    /** @var string[] */
    private array $allowedMimes;

    public function __construct(?int $maxBytes = null, array $allowedMimes = [
        'audio/mpeg', 'audio/mp3', 'audio/wav', 'audio/x-wav', 'audio/aac', 'audio/flac', 'audio/mp4',
        'video/mp4', 'video/quicktime', 'video/x-matroska', 'video/webm', 'video/x-msvideo'
    ]) {
        // Default to 6 GB if not specified; allow override via env UPLOAD_MAX_BYTES
        $env = getenv('UPLOAD_MAX_BYTES');
        $this->maxBytes = $maxBytes ?? ($env !== false && ctype_digit((string)$env) ? (int)$env : 6_442_450_944);
        $this->allowedMimes = $allowedMimes;
    }

    public function validateFilesArray(array $files): void
    {
        if (!isset($files['file'])) {
            throw new \InvalidArgumentException('Missing file field');
        }
        $f = $files['file'];
        if (is_array($f['name'])) {
            throw new \InvalidArgumentException('Multiple files not supported yet');
        }
        if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new \RuntimeException('Upload failed with code ' . (string)($f['error'] ?? -1));
        }
        $size = (int)($f['size'] ?? 0);
        if ($size <= 0) {
            throw new \InvalidArgumentException('Empty upload');
        }
        if ($size > $this->maxBytes) {
            throw new \InvalidArgumentException('File too large (exceeds application limit)');
        }

        // Prefer server-side detection for MIME
        $mime = '';
        if (isset($f['tmp_name']) && is_file($f['tmp_name'])) {
            $fi = new \finfo(FILEINFO_MIME_TYPE);
            $detected = @$fi->file($f['tmp_name']);
            if (is_string($detected) && $detected !== '') {
                $mime = $detected;
            }
        }
        if ($mime === '') {
            $mime = (string)($f['type'] ?? '');
        }
        if ($mime !== '' && !in_array($mime, $this->allowedMimes, true)) {
            // Be permissive but guard obvious non-media
            if (strpos($mime, 'audio/') !== 0 && strpos($mime, 'video/') !== 0) {
                throw new \InvalidArgumentException('Unsupported media type');
            }
        }
    }
}
