<?php declare(strict_types=1);
namespace Production\Api\Dto;

/**
 * Return value from AzureBlobRestClient::curl().
 *
 * A non-2xx status is NOT an exception — callers check isSuccess() and branch
 * accordingly. Only a cURL transport error (no response at all) throws.
 */
final readonly class CurlResult
{
    public function __construct(
        public int    $status,   // HTTP response status code
        public string $body,     // response body (may be empty for HEAD/DELETE)
        public array  $headers,  // associative; keys lowercased
    ) {}

    public function isSuccess(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }
}
