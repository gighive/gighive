<?php declare(strict_types=1);
namespace Production\Api\Infrastructure;

final class FileStorage
{
    public function ensureDir(string $dir): void
    {
        if (!is_dir($dir)) {
            if (!mkdir($dir, 0775, true) && !is_dir($dir)) {
                throw new \RuntimeException('Failed to create directory: ' . $dir);
            }
        }
    }

    public function moveUploadedFile(string $tmpPath, string $targetPath): void
    {
        if (!is_uploaded_file($tmpPath)) {
            // In some environments (e.g., testing) we may not have an actual uploaded file
            if (!rename($tmpPath, $targetPath)) {
                throw new \RuntimeException('Failed to move file (rename)');
            }
            return;
        }
        if (!move_uploaded_file($tmpPath, $targetPath)) {
            throw new \RuntimeException('Failed to move uploaded file');
        }
    }
}
