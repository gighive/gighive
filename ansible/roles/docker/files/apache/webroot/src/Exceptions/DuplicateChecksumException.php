<?php declare(strict_types=1);
namespace Production\Api\Exceptions;

final class DuplicateChecksumException extends \RuntimeException
{
    private int $existingAssetId;
    private string $checksumSha256;

    public function __construct(int $existingAssetId, string $checksumSha256)
    {
        parent::__construct('Duplicate upload');
        $this->existingAssetId = $existingAssetId;
        $this->checksumSha256  = $checksumSha256;
    }

    public function getExistingAssetId(): int
    {
        return $this->existingAssetId;
    }

    public function getChecksumSha256(): string
    {
        return $this->checksumSha256;
    }
}
