<?php declare(strict_types=1);
namespace Production\Api\Exceptions;

final class DuplicateChecksumException extends \RuntimeException
{
    private int $existingFileId;
    private string $checksumSha256;

    public function __construct(int $existingFileId, string $checksumSha256)
    {
        parent::__construct('Duplicate upload');
        $this->existingFileId = $existingFileId;
        $this->checksumSha256 = $checksumSha256;
    }

    public function getExistingFileId(): int
    {
        return $this->existingFileId;
    }

    public function getChecksumSha256(): string
    {
        return $this->checksumSha256;
    }
}
