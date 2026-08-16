<?php

declare(strict_types=1);

/**
 * PHP tus 1.0 upload server entry point.
 *
 * Handles POST, PATCH, HEAD, and OPTIONS for /files/ tus protocol requests.
 * Routed here from Apache via:
 *   RewriteRule ^/files(/.*)?$ /api/tus-upload.php [L,QSA,E=TUS_PATH:$1]
 *
 * Auth model (enforced by Apache LocationMatch before PHP runs):
 *  - Basic auth: admin or uploader → userId = 0 (admin-side uploads)
 *  - QR token (X-Upload-Token header): validated by UploadTokenValidator → userId = tokenId
 *
 * PHP runtime requirements:
 *  set_time_limit(0)      — prevent PHP killing a slow Azure PUT Block call mid-upload
 *  ignore_user_abort(true) — continue finalizing even if client disconnects on last PATCH
 */

set_time_limit(0);
ignore_user_abort(true);

require_once __DIR__ . '/../vendor/autoload.php';

use Production\Api\Config\TusUploadConfig;
use Production\Api\Services\MediaBackend;
use Production\Api\Services\AzureBlobTusBackend;
use Production\Api\Services\LocalFileTusBackend;
use Production\Api\Services\TusBlockUploadService;
use Production\Api\Services\UploadTokenValidator;

// -------------------------------------------------------------------------
// Resolve upload_id from URL path
// -------------------------------------------------------------------------
// Apache sets TUS_PATH env var via E=TUS_PATH:$1 in the RewriteRule.
// Path is either empty (POST /files/) or /{upload_id} (PATCH/HEAD /files/{id}).
$tusPath  = (string)getenv('TUS_PATH');
$uploadId = trim($tusPath, '/');

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? '');

// -------------------------------------------------------------------------
// CORS / OPTIONS pre-flight
// -------------------------------------------------------------------------
header('Tus-Resumable: 1.0.0');
header('Tus-Version: 1.0.0');
header('Tus-Extension: creation');

if ($method === 'OPTIONS') {
    header('Tus-Max-Size: ' . (getenv('UPLOAD_MAX_BYTES') ?: (string)(4 * 1024 * 1024 * 1024)));
    http_response_code(204);
    exit;
}

// -------------------------------------------------------------------------
// Resolve user identity
// -------------------------------------------------------------------------
$userId = 0; // default: basic-auth (admin/uploader)

$rawToken = $_SERVER['HTTP_X_UPLOAD_TOKEN'] ?? '';
if ($rawToken !== '') {
    // QR token upload — validate and use tokenId as userId
    $validator = new UploadTokenValidator();
    $tokenResult = $validator->validate($rawToken);
    if ($tokenResult === null) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Invalid or expired upload token']);
        exit;
    }
    $userId = $tokenResult->tokenId;
}

// -------------------------------------------------------------------------
// Build service
// -------------------------------------------------------------------------
$config = TusUploadConfig::fromEnv();

$backend = $config->isAzure()
    ? new AzureBlobTusBackend($config->azureClient)
    : new LocalFileTusBackend(
        localStagingDir: $config->localStagingDir,
        localAudioDir:   $config->localAudioDir,
        localVideoDir:   $config->localVideoDir,
    );

$service = new TusBlockUploadService($config, $backend);

// -------------------------------------------------------------------------
// Normalize request headers (lowercase keys)
// -------------------------------------------------------------------------
$requestHeaders = [];
foreach ($_SERVER as $key => $value) {
    if (str_starts_with($key, 'HTTP_')) {
        $name = strtolower(str_replace('_', '-', substr($key, 5)));
        $requestHeaders[$name] = (string)$value;
    }
}
// CONTENT_TYPE and CONTENT_LENGTH are not prefixed with HTTP_ in $_SERVER
if (isset($_SERVER['CONTENT_TYPE'])) {
    $requestHeaders['content-type'] = (string)$_SERVER['CONTENT_TYPE'];
}
if (isset($_SERVER['CONTENT_LENGTH'])) {
    $requestHeaders['content-length'] = (string)$_SERVER['CONTENT_LENGTH'];
}

// -------------------------------------------------------------------------
// Route by method
// -------------------------------------------------------------------------
switch ($method) {
    case 'POST':
        // Must be /files/ with no trailing upload_id
        if ($uploadId !== '') {
            http_response_code(400);
            echo json_encode(['error' => 'POST must target /files/ not /files/{id}']);
            exit;
        }
        $service->handlePost($userId, $requestHeaders);
        break; // never reached (handlePost exits)

    case 'PATCH':
        if ($uploadId === '') {
            http_response_code(400);
            echo json_encode(['error' => 'PATCH requires /files/{upload_id}']);
            exit;
        }
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $uploadId)) {
            http_response_code(404);
            exit;
        }
        $service->handlePatch($uploadId, $requestHeaders);
        break; // never reached

    case 'HEAD':
        if ($uploadId === '') {
            http_response_code(400);
            exit;
        }
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $uploadId)) {
            http_response_code(404);
            exit;
        }
        $service->handleHead($uploadId);
        break; // never reached

    case 'GET':
        // Plain GET on /files/ — not a tus request; return 400 per T-84
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'GET is not supported on the tus upload endpoint']);
        exit;

    default:
        http_response_code(405);
        header('Allow: OPTIONS, POST, PATCH, HEAD');
        exit;
}
