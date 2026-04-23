<?php declare(strict_types=1);
namespace Production\Api;

use OpenApi\Attributes as OA;

#[OA\Info(
    title: 'GigHive API',
    version: '1.0.0',
    description: 'GigHive media management API for uploading and retrieving audio/video files with session metadata.'
)]
#[OA\Server(url: '/api', description: 'Upload API endpoints')]
#[OA\Server(url: '/db', description: 'Database and media listing endpoints')]
#[OA\Server(url: '/admin', description: 'Admin-only endpoints (requires HTTP Basic auth with admin user)')]
#[OA\Schema(
    schema: 'File',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'integer', format: 'int64'),
        new OA\Property(property: 'file_name', type: 'string'),
        new OA\Property(property: 'file_type', type: 'string', enum: ['audio', 'video']),
        new OA\Property(property: 'duration_seconds', type: 'number', nullable: true),
        new OA\Property(property: 'mime_type', type: 'string'),
        new OA\Property(property: 'size_bytes', type: 'integer', format: 'int64', nullable: true),
        new OA\Property(property: 'checksum_sha256', type: 'string', nullable: true),
        new OA\Property(property: 'session_id', type: 'integer', format: 'int64', nullable: true),
        new OA\Property(property: 'seq', type: 'integer', nullable: true),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time', nullable: true),
    ]
)]
#[OA\Schema(
    schema: 'UploadResult',
    description: 'Response returned by POST /uploads and POST /uploads/finalize',
    allOf: [
        new OA\Schema(ref: '#/components/schemas/File'),
        new OA\Schema(
            properties: [
                new OA\Property(property: 'event_date', type: 'string', format: 'date'),
                new OA\Property(property: 'org_name', type: 'string'),
                new OA\Property(property: 'event_type', type: 'string', enum: ['band', 'wedding']),
                new OA\Property(property: 'label', type: 'string'),
                new OA\Property(property: 'participants', type: 'string'),
                new OA\Property(property: 'keywords', type: 'string'),
                new OA\Property(
                    property: 'delete_token',
                    type: 'string',
                    nullable: true,
                    description: 'One-time delete token. Present only on new uploads; absent if the record already existed'
                ),
            ]
        ),
    ]
)]
#[OA\Schema(
    schema: 'MediaEntry',
    type: 'object',
    description: 'Media entry with session and song metadata',
    properties: [
        new OA\Property(property: 'id', type: 'integer', format: 'int64'),
        new OA\Property(property: 'index', type: 'integer', description: 'Sequential index in the list'),
        new OA\Property(property: 'date', type: 'string', format: 'date', description: 'Event/session date'),
        new OA\Property(property: 'org_name', type: 'string', description: 'Organization or band name'),
        new OA\Property(property: 'duration', type: 'string', description: 'Human-readable duration (HH:MM:SS)'),
        new OA\Property(property: 'duration_seconds', type: 'integer', description: 'Duration in seconds'),
        new OA\Property(property: 'song_title', type: 'string', description: 'Song or event label'),
        new OA\Property(property: 'file_type', type: 'string', enum: ['audio', 'video']),
        new OA\Property(property: 'file_name', type: 'string'),
        new OA\Property(property: 'url', type: 'string', description: 'Relative URL to the media file'),
    ]
)]
#[OA\Schema(
    schema: 'Error',
    type: 'object',
    properties: [
        new OA\Property(property: 'error', type: 'string'),
        new OA\Property(property: 'message', type: 'string'),
    ]
)]
#[OA\Schema(
    schema: 'DuplicateError',
    type: 'object',
    properties: [
        new OA\Property(property: 'error', type: 'string', example: 'Duplicate Upload'),
        new OA\Property(property: 'message', type: 'string'),
        new OA\Property(property: 'existing_file_id', type: 'integer', format: 'int64'),
        new OA\Property(property: 'checksum_sha256', type: 'string'),
    ]
)]
#[OA\Schema(
    schema: 'ManifestFinalizeResult',
    type: 'object',
    properties: [
        new OA\Property(property: 'success', type: 'boolean', example: true),
        new OA\Property(property: 'job_id', type: 'string'),
        new OA\Property(property: 'checksum_sha256', type: 'string'),
        new OA\Property(property: 'state', type: 'string', enum: ['db_done', 'thumbnail_done', 'uploaded', 'already_present']),
        new OA\Property(property: 'file_name', type: 'string', nullable: true),
        new OA\Property(property: 'duration_seconds', type: 'number', nullable: true),
        new OA\Property(property: 'thumbnail_done', type: 'boolean'),
        new OA\Property(property: 'db_done', type: 'boolean'),
        new OA\Property(property: 'all_done', type: 'boolean', description: 'True when all files in the job have reached a terminal state'),
        new OA\Property(property: 'trace', type: 'array', items: new OA\Items(type: 'object')),
    ]
)]
#[OA\Schema(
    schema: 'ManifestFinalizeError',
    type: 'object',
    properties: [
        new OA\Property(property: 'success', type: 'boolean', example: false),
        new OA\Property(property: 'error', type: 'string'),
        new OA\Property(property: 'message', type: 'string'),
        new OA\Property(
            property: 'failure_code',
            type: 'string',
            enum: ['disk_full', 'checksum_mismatch', 'db_error', 'thumbnail_error', 'invalid_manifest_state', 'finalize_error']
        ),
        new OA\Property(property: 'retryable', type: 'boolean', nullable: true),
        new OA\Property(
            property: 'diagnostics',
            type: 'object',
            nullable: true,
            properties: [
                new OA\Property(property: 'tus_data_path', type: 'string'),
                new OA\Property(property: 'tus_data_free_bytes', type: 'integer', nullable: true),
                new OA\Property(property: 'tus_data_total_bytes', type: 'integer', nullable: true),
                new OA\Property(property: 'tus_data_pct_used', type: 'number', nullable: true),
                new OA\Property(property: 'media_file_exists', type: 'boolean'),
                new OA\Property(property: 'media_file_path', type: 'string'),
                new OA\Property(property: 'thumbnail_exists', type: 'boolean', nullable: true),
                new OA\Property(property: 'hook_payload_exists', type: 'boolean', nullable: true),
            ]
        ),
        new OA\Property(property: 'trace', type: 'array', items: new OA\Items(type: 'object')),
    ]
)]
#[OA\Post(
    path: '/media-files',
    operationId: 'uploadMediaAlias',
    summary: 'Upload a media file (iOS alias for POST /uploads)',
    description: 'Alias for POST /uploads. Accepts identical request body and returns an identical response. Provided for iOS client compatibility.',
    servers: [new OA\Server(url: '/api')],
    tags: ['uploads'],
    requestBody: new OA\RequestBody(
        required: true,
        content: [
            new OA\MediaType(
                mediaType: 'multipart/form-data',
                schema: new OA\Schema(
                    required: ['file', 'label'],
                    properties: [
                        new OA\Property(property: 'file', type: 'string', format: 'binary', description: 'The media file to upload (audio/video)'),
                        new OA\Property(property: 'event_date', type: 'string', format: 'date', description: 'Event date (YYYY-MM-DD). Defaults to today if omitted'),
                        new OA\Property(property: 'event_type', type: 'string', enum: ['band', 'wedding']),
                        new OA\Property(property: 'org_name', type: 'string'),
                        new OA\Property(property: 'label', type: 'string', description: 'Song title (band) or wedding table label. Required'),
                        new OA\Property(property: 'participants', type: 'string'),
                        new OA\Property(property: 'keywords', type: 'string'),
                        new OA\Property(property: 'location', type: 'string'),
                        new OA\Property(property: 'rating', type: 'string'),
                        new OA\Property(property: 'notes', type: 'string'),
                    ]
                )
            ),
        ]
    ),
    responses: [
        new OA\Response(response: 201, description: 'Created', content: new OA\JsonContent(ref: '#/components/schemas/UploadResult')),
        new OA\Response(response: 400, description: 'Bad request', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
        new OA\Response(response: 401, description: 'Unauthorized'),
        new OA\Response(response: 409, description: 'Duplicate upload', content: new OA\JsonContent(ref: '#/components/schemas/DuplicateError')),
        new OA\Response(response: 413, description: 'Payload too large'),
        new OA\Response(response: 500, description: 'Server error', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
    ]
)]
#[OA\Get(
    path: '/media-files',
    operationId: 'listMediaFilesStub',
    summary: 'List media files (not yet implemented)',
    description: 'Planned endpoint. Currently returns 501 Not Implemented.',
    servers: [new OA\Server(url: '/api')],
    tags: ['uploads'],
    responses: [
        new OA\Response(response: 501, description: 'Not Implemented', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
    ]
)]
#[OA\Get(
    path: '/database.php',
    operationId: 'getMediaList',
    summary: 'Get media list',
    description: "Returns a list of all media files with metadata. Can return HTML view or JSON based on format parameter.",
    servers: [new OA\Server(url: '/db')],
    tags: ['database'],
    parameters: [
        new OA\Parameter(
            name: 'format',
            in: 'query',
            required: false,
            schema: new OA\Schema(type: 'string', enum: ['json']),
            description: "Response format. If 'json', returns JSON array; otherwise returns HTML view"
        ),
    ],
    responses: [
        new OA\Response(
            response: 200,
            description: 'OK',
            content: [
                new OA\MediaType(
                    mediaType: 'application/json',
                    schema: new OA\Schema(
                        type: 'object',
                        properties: [
                            new OA\Property(
                                property: 'entries',
                                type: 'array',
                                items: new OA\Items(ref: '#/components/schemas/MediaEntry')
                            ),
                        ]
                    )
                ),
                new OA\MediaType(
                    mediaType: 'text/html',
                    schema: new OA\Schema(type: 'string', description: 'HTML page with media list')
                ),
            ]
        ),
        new OA\Response(response: 500, description: 'Server error'),
    ]
)]
#[OA\Post(
    path: '/import_manifest_upload_finalize.php',
    operationId: 'finalizeManifestUpload',
    summary: 'Finalize a manifest TUS upload (admin)',
    description: 'Admin-only endpoint. Finalizes the TUS upload for a single file within a manifest import job. The manifest row (created by the manifest worker Step 1) must already exist in the database before calling this endpoint. Idempotent via per-upload_id finalization markers. Requires HTTP Basic authentication with the admin user.',
    servers: [new OA\Server(url: '/admin')],
    tags: ['admin'],
    requestBody: new OA\RequestBody(
        required: true,
        content: [
            new OA\MediaType(
                mediaType: 'application/json',
                schema: new OA\Schema(
                    required: ['job_id', 'upload_id', 'checksum_sha256'],
                    properties: [
                        new OA\Property(
                            property: 'job_id',
                            type: 'string',
                            pattern: '^\d{8}-\d{6}-[0-9a-f]{12}$',
                            description: 'Import job ID (format YYYYMMDD-HHMMSS-{12hex})'
                        ),
                        new OA\Property(
                            property: 'upload_id',
                            type: 'string',
                            pattern: '^[A-Za-z0-9_-]+$',
                            description: 'TUS upload ID'
                        ),
                        new OA\Property(
                            property: 'checksum_sha256',
                            type: 'string',
                            pattern: '^[0-9a-f]{64}$',
                            description: 'Expected SHA-256 checksum of the uploaded file'
                        ),
                    ]
                )
            ),
        ]
    ),
    responses: [
        new OA\Response(response: 200, description: 'Finalize succeeded', content: new OA\JsonContent(ref: '#/components/schemas/ManifestFinalizeResult')),
        new OA\Response(response: 400, description: 'Bad request or finalize error', content: new OA\JsonContent(ref: '#/components/schemas/ManifestFinalizeError')),
        new OA\Response(response: 403, description: 'Forbidden — admin access required'),
        new OA\Response(response: 404, description: 'Job not found'),
        new OA\Response(response: 405, description: 'Method not allowed'),
    ]
)]
class OpenApi {}
