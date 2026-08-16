<?php declare(strict_types=1);
namespace Production\Api\Services;

use RuntimeException;

/**
 * APCu-backed Azure Managed Identity token cache.
 *
 * Fetches a Bearer token from IMDS when the cache is empty or within
 * EXPIRY_BUFFER_SECONDS of expiry. Shared by AzureBlobMediaBackend and
 * AzureBlobTusBackend to eliminate redundant IMDS calls under concurrent load.
 *
 * Requirements:
 *   - APCu extension enabled in the PHP container (apcu package in Dockerfile)
 *   - Container must have extra_hosts: host.docker.internal:host-gateway
 *     so that 169.254.169.254 is reachable via the host network stack
 *   - apcu.enable_cli=1 required if called from cron (run_probe_job.php)
 */
final class AzureIdentityTokenCache
{
    private const EXPIRY_BUFFER_SECONDS = 300;   // refresh 5 min before expiry
    private const CACHE_PREFIX          = 'azure_token:';
    private const IMDS_BASE_URL         = 'http://169.254.169.254';
    private const CURL_TIMEOUT_SECONDS  = 5;     // IMDS must be fast; fail quickly if unreachable

    public function __construct(
        private readonly string $clientId,
    ) {}

    /**
     * Return a valid Bearer token string.
     *
     * Checks APCu first. If the cached entry is missing or stale, fetches a
     * fresh token from IMDS and re-caches it.
     *
     * @throws RuntimeException if IMDS is unreachable or returns a non-200 response
     */
    public function getToken(): string
    {
        $cached = apcu_fetch($this->cacheKey(), $success);
        if ($success && is_string($cached) && $cached !== '') {
            return $cached;
        }

        return $this->fetchAndCache();
    }

    // ── private ──────────────────────────────────────────────────────────────

    /**
     * Fetch a fresh token from IMDS, cache it in APCu, and return it.
     * APCu TTL is set to expires_in - EXPIRY_BUFFER_SECONDS so the cache
     * entry expires before the token does.
     */
    private function fetchAndCache(): string
    {
        $data  = $this->imdsRequest();
        $token = $data['access_token'] ?? '';
        if ($token === '') {
            throw new RuntimeException('IMDS response missing access_token field');
        }

        $expiresIn = (int)($data['expires_in'] ?? 0);
        $ttl       = max(1, $expiresIn - self::EXPIRY_BUFFER_SECONDS);

        apcu_store($this->cacheKey(), $token, $ttl);

        return $token;
    }

    /**
     * cURL call to IMDS token endpoint.
     * Returns decoded JSON array on HTTP 200.
     *
     * @return array<string, mixed>
     * @throws RuntimeException on cURL error or non-200 response
     */
    private function imdsRequest(): array
    {
        $url = self::IMDS_BASE_URL
            . '/metadata/identity/oauth2/token'
            . '?api-version=2018-02-01'
            . '&resource=https://storage.azure.com/'
            . '&client_id=' . urlencode($this->clientId);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['Metadata: true'],
            CURLOPT_TIMEOUT        => self::CURL_TIMEOUT_SECONDS,
            CURLOPT_CONNECTTIMEOUT => self::CURL_TIMEOUT_SECONDS,
            CURLOPT_FOLLOWLOCATION => false,
        ]);

        $body   = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err    = curl_error($ch);
        curl_close($ch);

        if ($body === false || $err !== '') {
            throw new RuntimeException('IMDS cURL transport error: ' . $err);
        }

        if ($status !== 200) {
            throw new RuntimeException(
                "IMDS returned HTTP {$status} — check Managed Identity assignment and client_id. Body: "
                . substr((string)$body, 0, 256)
            );
        }

        $data = json_decode((string)$body, true);
        if (!is_array($data)) {
            throw new RuntimeException('IMDS response is not valid JSON: ' . substr((string)$body, 0, 256));
        }

        return $data;
    }

    private function cacheKey(): string
    {
        return self::CACHE_PREFIX . $this->clientId;
    }
}
