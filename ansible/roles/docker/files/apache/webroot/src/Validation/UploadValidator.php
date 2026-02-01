<?php declare(strict_types=1);
namespace Production\Api\Validation;

 use Production\Api\Config\MediaTypes;

final class UploadValidator
{
    /** @var int */
    private $maxBytes;
    /** @var string[] */
    private array $allowedMimes;

    public function __construct(?int $maxBytes = null, ?array $allowedMimes = null) {
        // Default to 6 GB if not specified; allow override via env UPLOAD_MAX_BYTES
        // Use 6 * 1024 * 1024 * 1024 = 6442450944 bytes
        $env = getenv('UPLOAD_MAX_BYTES');
        $defaultMax = 6 * 1024 * 1024 * 1024; // 6 GB calculated at runtime to avoid literal overflow
        $this->maxBytes = $maxBytes ?? ($env !== false && ctype_digit((string)$env) ? (int)$env : $defaultMax);

        if ($allowedMimes === null) {
            $fromEnv = MediaTypes::allowedMimes();
            $this->allowedMimes = $fromEnv;
        } else {
            $this->allowedMimes = $allowedMimes;
        }
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
        $name = (string)($f['name'] ?? '');
        $ext = '';
        $dot = strrpos($name, '.');
        if (is_int($dot) && $dot !== false) {
            $ext = strtolower(substr($name, $dot + 1));
        }
        $extAllowed = false;
        if ($ext !== '') {
            $allowedExts = array_merge(MediaTypes::audioExts(), MediaTypes::videoExts());
            if ($allowedExts !== [] && in_array($ext, $allowedExts, true)) {
                $extAllowed = true;
            }
        }

        if ($mime !== '' && !in_array($mime, $this->allowedMimes, true)) {
            // Guard obvious non-media, but allow cases where finfo is too strict as long as
            // the filename extension indicates a supported media type.
            if (strpos($mime, 'audio/') !== 0 && strpos($mime, 'video/') !== 0 && !$extAllowed) {
                throw new \InvalidArgumentException('Unsupported media type');
            }
        }
    }
}
