<?php declare(strict_types=1);
namespace Production\Api\Services;

use Production\Api\Dto\CurlResult;
use RuntimeException;

/**
 * Shared Azure Blob Storage REST helper.
 *
 * Centralises the logic shared between AzureBlobMediaBackend and AzureBlobTusBackend:
 *   1. Building authenticated request headers (Bearer token + x-ms-version + x-ms-date)
 *   2. Executing cURL requests and returning a typed CurlResult
 *   3. Building canonical blob URLs
 *
 * Both backends hold a reference to one shared instance of this class.
 * AzureIdentityTokenCache (and therefore APCu) is shared automatically.
 */
final class AzureBlobRestClient
{
    public const API_VERSION = '2020-04-08';

    public function __construct(
        private readonly string                  $account,
        private readonly string                  $container,
        private readonly AzureIdentityTokenCache $tokenCache,
        private readonly int                     $curlTimeoutSeconds = 30,
    ) {}

    /**
     * Build the canonical blob URL.
     * https://{account}.blob.core.windows.net/{container}/{key}
     * Appends $queryString verbatim if provided (caller must include leading '?').
     */
    public function blobUrl(string $key, string $queryString = ''): string
    {
        return sprintf(
            'https://%s.blob.core.windows.net/%s/%s%s',
            $this->account,
            $this->container,
            ltrim($key, '/'),
            $queryString,
        );
    }

    /**
     * Return auth + version headers for CURLOPT_HTTPHEADER:
     *   Authorization: Bearer {token}
     *   x-ms-version: 2020-04-08
     *   x-ms-date: {RFC1123}
     *
     * @return string[]
     * @throws RuntimeException if token fetch fails
     */
    public function authHeaders(): array
    {
        $token = $this->tokenCache->getToken();
        return [
            'Authorization: Bearer ' . $token,
            'x-ms-version: ' . self::API_VERSION,
            'x-ms-date: ' . gmdate('D, d M Y H:i:s') . ' GMT',
        ];
    }

    /**
     * Execute a cURL request and return a CurlResult.
     *
     * Throws RuntimeException on cURL transport error (no response at all).
     * Callers must check CurlResult::isSuccess() and handle HTTP errors themselves.
     *
     * @param mixed $body  string body, a readable file resource for streaming PUT, or null
     */
    public function curl(
        string $method,
        string $url,
        array  $extraHeaders = [],
        mixed  $body = null,
    ): CurlResult {
        $ch = curl_init($url);

        $allHeaders = array_merge($this->authHeaders(), $extraHeaders);

        $opts = [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_HTTPHEADER     => $allHeaders,
            CURLOPT_TIMEOUT        => $this->curlTimeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => false,
        ];

        if ($method === 'HEAD') {
            $opts[CURLOPT_NOBODY] = true;
        }

        if (is_string($body) && $body !== '') {
            $opts[CURLOPT_POSTFIELDS] = $body;
        } elseif (is_resource($body)) {
            // Streaming PUT — caller must set Content-Length header in $extraHeaders
            $opts[CURLOPT_PUT]    = true;
            $opts[CURLOPT_INFILE] = $body;
        }

        curl_setopt_array($ch, $opts);

        $raw    = curl_exec($ch);
        $err    = curl_error($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $hdrLen = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        if ($raw === false || $err !== '') {
            throw new RuntimeException("Azure Blob cURL transport error [{$method} {$url}]: {$err}");
        }

        $rawStr    = (string)$raw;
        $headerStr = substr($rawStr, 0, $hdrLen);
        $respBody  = substr($rawStr, $hdrLen);

        return new CurlResult(
            status:  $status,
            body:    $respBody,
            headers: $this->parseHeaders($headerStr),
        );
    }

    // ── private ──────────────────────────────────────────────────────────────

    /**
     * Parse raw HTTP response headers into a lowercased-key associative array.
     * Multi-value headers keep the last value (sufficient for ETag, Content-Type, etc.).
     *
     * @return array<string, string>
     */
    private function parseHeaders(string $raw): array
    {
        $headers = [];
        foreach (explode("\r\n", $raw) as $line) {
            $pos = strpos($line, ':');
            if ($pos === false) {
                continue;
            }
            $name           = strtolower(trim(substr($line, 0, $pos)));
            $value          = trim(substr($line, $pos + 1));
            $headers[$name] = $value;
        }
        return $headers;
    }
}
