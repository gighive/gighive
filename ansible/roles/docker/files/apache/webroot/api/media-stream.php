<?php

declare(strict_types=1);

/**
 * Application-mediated media streaming endpoint.
 *
 * Handles full-file and byte-range reads for audio, video, and thumbnails.
 * Routed here from Apache via (Phase 4 RewriteRules):
 *   RewriteRule ^/media/video/thumbnails/(.+)$  /api/media-stream.php [L,QSA,E=MEDIA_TYPE:video/thumbnails,E=MEDIA_KEY:$1]
 *   RewriteRule ^/media/(audio|video)/(.+)$     /api/media-stream.php [L,QSA,E=MEDIA_TYPE:$1,E=MEDIA_KEY:$2]
 *   RewriteRule ^/video/thumbnails/(.+)$        /api/media-stream.php [L,QSA,E=MEDIA_TYPE:video/thumbnails,E=MEDIA_KEY:$1]
 *   RewriteRule ^/(audio|video)/(.+)$           /api/media-stream.php [L,QSA,E=MEDIA_TYPE:$1,E=MEDIA_KEY:$2]
 *
 * Auth model (enforced entirely in PHP — Apache grants this endpoint):
 *  1. Basic Auth (Authorization: Basic ...) — admin / uploader via htpasswd
 *  2. Upload token (X-Upload-Token header)  — event-scoped QR token
 *  3. Gallery nonce (?nonce= query param)   — guest gallery thumbnail access
 *
 * Apache sets REDIRECT_MEDIA_TYPE and REDIRECT_MEDIA_KEY via E= flags.
 * Falls back to URI parsing if those vars are absent.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Production\Api\Infrastructure\Database;
use Production\Api\Services\GuestCredentialResolver;
use Production\Api\Services\MediaStorageService;
use Production\Api\Services\UploadTokenValidator;

// ── Helpers ───────────────────────────────────────────────────────────────────

/**
 * Validate $type against the allowlist and $key against the SHA-256 filename
 * regex. Returns true if both are valid; false otherwise.
 *
 * Called before any blob or DB access to prevent path traversal via a
 * client-constructed MEDIA_TYPE or MEDIA_KEY value.
 */
function validateKey(string $type, string $key): bool
{
    if (!in_array($type, ['audio', 'video', 'video/thumbnails'], true)) {
        return false;
    }
    // No i flag — SHA-256 hex from hash_final() is always lowercase; uppercase
    // indicates a client-constructed key and must be rejected, not normalised.
    return (bool)preg_match('/^[a-f0-9]{64}\.[a-z0-9]{2,5}$/', $key);
}

/**
 * Authenticate the request via three credential paths in priority order.
 * Returns true if any path succeeds; false if all fail.
 *
 * Requires a live $pdo only for paths 2 and 3; path 1 is header-only.
 * $pdo is passed by reference so it can be initialised lazily and reused.
 */
function authenticateRequest(\PDO $pdo): bool
{
    // Path 1: Basic Auth — PHP is served via mod_proxy_fcgi (PHP-FPM). Apache never
    // sets PHP_AUTH_USER / PHP_AUTH_PW for FPM requests. Instead, default-ssl.conf.j2
    // uses SetEnvIf to copy the Authorization header into HTTP_AUTHORIZATION, which
    // mod_proxy_fcgi forwards to PHP-FPM as $_SERVER['HTTP_AUTHORIZATION'].
    // Apache validates the credential against htpasswd before SetEnvIf fires, so
    // HTTP_AUTHORIZATION is only present when Apache has already accepted the credential.
    // We confirm the header is present and is a Basic scheme — Apache did the verification.
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (str_starts_with($authHeader, 'Basic ')) {
        return true;
    }

    // Path 2: Upload token (X-Upload-Token header)
    $rawToken = $_SERVER['HTTP_X_UPLOAD_TOKEN'] ?? null;
    if ($rawToken !== null) {
        return (new UploadTokenValidator($pdo))->validate($rawToken) !== null;
    }

    // Path 3: Gallery nonce (?nonce= query string) — guest gallery thumbnails.
    // Uses the same shared resolver as guest-gallery.php, guest-report.php,
    // and guest-stream.php. Do not inline a custom SQL query here.
    $nonce = $_GET['nonce'] ?? '';
    if ($nonce !== '') {
        // Validate nonce format before hitting the DB (mirrors guest-gallery.php)
        if (preg_match('/^[A-Za-z0-9_\-]{30,43}$/', $nonce) !== 1) {
            return false;
        }
        $resolver = new GuestCredentialResolver($pdo);
        try {
            $result = $resolver->resolveNonceOrToken($nonce);
        } catch (\PDOException $e) {
            http_response_code(500);
            exit;
        }
        if ($result === false) {
            return false;
        }
        try {
            $expiry = new \DateTime($result['expires_at']);
            return $expiry > new \DateTime('now');
        } catch (\Exception $e) {
            return false;
        }
    }

    return false;
}

/**
 * Parse the Range request header against the known blob size.
 * Returns [$start, $end, $isRange].
 * Sends 416 and exits if the range is syntactically valid but unsatisfiable.
 */
function parseRangeHeader(int $size): array
{
    $rangeHeader = $_SERVER['HTTP_RANGE'] ?? null;
    if ($rangeHeader !== null && preg_match('/^bytes=(\d+)-(\d*)$/', $rangeHeader, $m)) {
        $start = (int)$m[1];
        $end   = $m[2] !== '' ? (int)$m[2] : $size - 1;
        $end   = min($end, $size - 1);
        if ($start > $end || $start >= $size) {
            http_response_code(416);
            header('Content-Range: bytes */' . $size);
            exit;
        }
        return [$start, $end, true];
    }
    return [0, $size - 1, false];
}

/**
 * Set response headers and stream the byte range to the client.
 * Sends 200 or 206 depending on $isRange.
 */
function buildStreamResponse(
    MediaStorageService $storage,
    string $type,
    string $key,
    \Production\Api\Dto\MediaMetaDto $meta,
    int $start,
    int $end,
    bool $isRange,
): void {
    header('Content-Type: '   . $meta->contentType);
    header('Content-Length: ' . ($end - $start + 1));
    header('Accept-Ranges: bytes');
    header('ETag: "'          . $meta->etag . '"');
    header('Cache-Control: private, max-age=3600');

    if ($isRange) {
        http_response_code(206);
        header('Content-Range: bytes ' . $start . '-' . $end . '/' . $meta->size);
    } else {
        http_response_code(200);
    }

    try {
        $storage->streamRange($type, $key, $start, $end);
    } catch (\RuntimeException $e) {
        // Headers already sent; log and abort — client will see a truncated body
        error_log('[media-stream] streamRange failed for ' . $type . '/' . $key . ': ' . $e->getMessage());
    }
}

// ── Resolve $type and $key ────────────────────────────────────────────────────
// Apache sets REDIRECT_MEDIA_TYPE and REDIRECT_MEDIA_KEY via E= flags on the
// RewriteRule. Fall back to URI parsing when those vars are absent (e.g. direct
// PHP invocation during smoke tests or local development).

$type = $_SERVER['REDIRECT_MEDIA_TYPE'] ?? '';
$key  = $_SERVER['REDIRECT_MEDIA_KEY']  ?? '';

if ($type === '' || $key === '') {
    // Parse from REQUEST_URI: /media/video/thumbnails/{key}
    //                         /media/{audio|video}/{key}
    //                         /video/thumbnails/{key}
    //                         /{audio|video}/{key}
    $uri = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
    $uri = '/' . ltrim($uri, '/');

    if (preg_match('@^/media/video/thumbnails/(.+)$@', $uri, $m)) {
        $type = 'video/thumbnails';
        $key  = $m[1];
    } elseif (preg_match('@^/media/(audio|video)/(.+)$@', $uri, $m)) {
        $type = $m[1];
        $key  = $m[2];
    } elseif (preg_match('@^/video/thumbnails/(.+)$@', $uri, $m)) {
        $type = 'video/thumbnails';
        $key  = $m[1];
    } elseif (preg_match('@^/(audio|video)/(.+)$@', $uri, $m)) {
        $type = $m[1];
        $key  = $m[2];
    }
}

// Strip query string from $key if URI parsing left it attached
if (($qPos = strpos($key, '?')) !== false) {
    $key = substr($key, 0, $qPos);
}

// ── Validate type + key (before any DB or blob access) ───────────────────────
if (!validateKey($type, $key)) {
    http_response_code(400);
    exit;
}

// ── Authenticate ──────────────────────────────────────────────────────────────
try {
    $pdo = Database::createFromEnv();
} catch (\Throwable $e) {
    http_response_code(500);
    exit;
}

if (!authenticateRequest($pdo)) {
    http_response_code(401);
    exit;
}

// ── Fetch metadata ────────────────────────────────────────────────────────────
$storage = MediaStorageService::make();

try {
    $meta = $storage->getMeta($type, $key);
} catch (\RuntimeException $e) {
    http_response_code(503);
    exit;
}

if ($meta === null) {
    http_response_code(404);
    exit;
}

// ── Parse Range header ────────────────────────────────────────────────────────
[$start, $end, $isRange] = parseRangeHeader($meta->size);

// ── Stream response ───────────────────────────────────────────────────────────
buildStreamResponse($storage, $type, $key, $meta, $start, $end, $isRange);
exit;
