<?php declare(strict_types=1);
namespace Production\Api;

use OpenApi\Attributes as OA;

#[OA\Info(
    title: 'GigHive API',
    version: '1.0.0',
    description: 'GigHive media management API for uploading and retrieving audio/video files with event and asset metadata.'
)]
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
        new OA\Property(property: 'asset_id', type: 'integer', format: 'int64', nullable: true),
        new OA\Property(property: 'event_id', type: 'integer', format: 'int64', nullable: true),
        new OA\Property(property: 'source_relpath', type: 'string', nullable: true),
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
        new OA\Property(property: 'existing_asset_id', type: 'integer', format: 'int64'),
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
#[OA\Schema(
    schema: 'AiJob',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'integer', format: 'int64'),
        new OA\Property(property: 'job_type', type: 'string', example: 'categorize_video'),
        new OA\Property(property: 'target_type', type: 'string', example: 'asset'),
        new OA\Property(property: 'target_id', type: 'integer', format: 'int64'),
        new OA\Property(property: 'status', type: 'string', enum: ['queued', 'running', 'done', 'failed']),
        new OA\Property(property: 'error_message', type: 'string', nullable: true),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time', nullable: true),
    ]
)]
#[OA\Schema(
    schema: 'Tag',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'integer', format: 'int64'),
        new OA\Property(property: 'namespace', type: 'string'),
        new OA\Property(property: 'name', type: 'string'),
        new OA\Property(property: 'usage_count', type: 'integer'),
    ]
)]
#[OA\Schema(
    schema: 'Tagging',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'integer', format: 'int64'),
        new OA\Property(property: 'namespace', type: 'string'),
        new OA\Property(property: 'name', type: 'string'),
        new OA\Property(property: 'confidence', type: 'number', format: 'float'),
        new OA\Property(property: 'source', type: 'string', enum: ['ai', 'human']),
        new OA\Property(property: 'start_seconds', type: 'number', nullable: true),
        new OA\Property(property: 'end_seconds', type: 'number', nullable: true),
        new OA\Property(property: 'run_id', type: 'integer', nullable: true),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
    ]
)]
#[OA\Schema(
    schema: 'UploadToken',
    type: 'object',
    properties: [
        new OA\Property(property: 'event_id', type: 'integer', format: 'int64'),
        new OA\Property(property: 'event_date', type: 'string', format: 'date'),
        new OA\Property(property: 'org_name', type: 'string'),
        new OA\Property(property: 'event_type', type: 'string', enum: ['band', 'wedding']),
    ]
)]
#[OA\Schema(
    schema: 'GuestVideo',
    type: 'object',
    properties: [
        new OA\Property(property: 'upload_job_id', type: 'integer', format: 'int64'),
        new OA\Property(property: 'label', type: 'string'),
        new OA\Property(property: 'stream_url', type: 'string'),
        new OA\Property(property: 'display_name', type: 'string', nullable: true),
        new OA\Property(property: 'approved_at', type: 'string', format: 'date-time'),
    ]
)]
#[OA\Get(
    path: '/ai_jobs.php',
    operationId: 'getAiJobs',
    summary: 'List AI jobs, get a single job, or get status counts',
    description: 'Variants: no params → paginated list (?status, ?limit); ?id=N → single job; ?action=status_counts → aggregate counts by status (?job_ids=1,2,3 scopes to those jobs; omit for global). Admin required for status_counts.',
    servers: [new OA\Server(url: '/api')],
    tags: ['ai'],
    parameters: [
        new OA\Parameter(name: 'id', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
        new OA\Parameter(name: 'action', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['status_counts'])),
        new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['queued', 'running', 'done', 'failed'])),
        new OA\Parameter(name: 'limit', in: 'query', required: false, schema: new OA\Schema(type: 'integer', maximum: 500, default: 100)),
        new OA\Parameter(name: 'job_ids', in: 'query', required: false, description: 'Comma-separated job IDs (for action=status_counts)', schema: new OA\Schema(type: 'string')),
    ],
    responses: [
        new OA\Response(response: 200, description: 'OK'),
        new OA\Response(response: 403, description: 'Forbidden — admin required'),
        new OA\Response(response: 404, description: 'Job not found'),
    ]
)]
#[OA\Post(
    path: '/ai_jobs.php',
    operationId: 'postAiJobs',
    summary: 'Enqueue or cancel AI jobs (admin)',
    description: 'action omitted → enqueue single job (body: job_type, target_type, target_id); action=enqueue_all_untagged → bulk enqueue all untagged videos; action=retag_all → re-enqueue all videos including already-tagged; action=cancel_jobs → cancel queued jobs by ID (body: job_ids[]).',
    servers: [new OA\Server(url: '/api')],
    tags: ['ai'],
    parameters: [
        new OA\Parameter(name: 'action', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['enqueue_all_untagged', 'retag_all', 'cancel_jobs'])),
    ],
    requestBody: new OA\RequestBody(
        required: false,
        content: [
            new OA\MediaType(
                mediaType: 'application/json',
                schema: new OA\Schema(
                    properties: [
                        new OA\Property(property: 'job_type', type: 'string', example: 'categorize_video'),
                        new OA\Property(property: 'target_type', type: 'string', example: 'asset'),
                        new OA\Property(property: 'target_id', type: 'integer', description: 'Required when action is omitted'),
                        new OA\Property(property: 'job_ids', type: 'array', items: new OA\Items(type: 'integer'), description: 'Required for action=cancel_jobs'),
                    ]
                )
            ),
        ]
    ),
    responses: [
        new OA\Response(response: 200, description: 'Already queued (single enqueue) or cancel result'),
        new OA\Response(response: 201, description: 'Job enqueued'),
        new OA\Response(response: 400, description: 'Bad request or AI_WORKER_ENABLED=false'),
        new OA\Response(response: 403, description: 'Admin required'),
    ]
)]
#[OA\Get(
    path: '/tags.php',
    operationId: 'getTags',
    summary: 'List tags or fetch taggings for a target',
    description: 'No target params → tag list (optionally ?namespace=X with usage counts); ?target_type=asset&target_id=N → taggings for one asset; ?target_type=asset&asset_ids=1,2,3 → batch taggings map keyed by asset ID.',
    servers: [new OA\Server(url: '/api')],
    tags: ['tags'],
    parameters: [
        new OA\Parameter(name: 'namespace', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
        new OA\Parameter(name: 'target_type', in: 'query', required: false, schema: new OA\Schema(type: 'string', example: 'asset')),
        new OA\Parameter(name: 'target_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
        new OA\Parameter(name: 'asset_ids', in: 'query', required: false, description: 'Comma-separated asset IDs for batch tagging fetch', schema: new OA\Schema(type: 'string')),
    ],
    responses: [
        new OA\Response(response: 200, description: 'OK'),
        new OA\Response(response: 405, description: 'Method not allowed'),
    ]
)]
#[OA\Patch(
    path: '/taggings.php',
    operationId: 'patchTagging',
    summary: 'Confirm or edit a tagging (admin)',
    servers: [new OA\Server(url: '/api')],
    tags: ['tags'],
    parameters: [
        new OA\Parameter(name: 'id', in: 'query', required: true, schema: new OA\Schema(type: 'integer')),
    ],
    requestBody: new OA\RequestBody(
        required: true,
        content: [
            new OA\MediaType(
                mediaType: 'application/json',
                schema: new OA\Schema(
                    properties: [
                        new OA\Property(property: 'source', type: 'string', enum: ['ai', 'human']),
                        new OA\Property(property: 'confidence', type: 'number', minimum: 0.0, maximum: 1.0),
                        new OA\Property(property: 'namespace', type: 'string'),
                        new OA\Property(property: 'name', type: 'string'),
                    ]
                )
            ),
        ]
    ),
    responses: [
        new OA\Response(response: 200, description: 'Updated'),
        new OA\Response(response: 400, description: 'id required'),
        new OA\Response(response: 403, description: 'Admin required'),
        new OA\Response(response: 404, description: 'Tagging not found'),
    ]
)]
#[OA\Post(
    path: '/taggings.php',
    operationId: 'createTagging',
    summary: 'Create a manual tagging (admin)',
    servers: [new OA\Server(url: '/api')],
    tags: ['tags'],
    requestBody: new OA\RequestBody(
        required: true,
        content: [
            new OA\MediaType(
                mediaType: 'application/json',
                schema: new OA\Schema(
                    required: ['target_type', 'target_id', 'namespace', 'name'],
                    properties: [
                        new OA\Property(property: 'target_type', type: 'string', enum: ['asset', 'event', 'event_item', 'segment']),
                        new OA\Property(property: 'target_id', type: 'integer'),
                        new OA\Property(property: 'namespace', type: 'string'),
                        new OA\Property(property: 'name', type: 'string'),
                        new OA\Property(property: 'confidence', type: 'number', minimum: 0.0, maximum: 1.0, default: 1.0),
                    ]
                )
            ),
        ]
    ),
    responses: [
        new OA\Response(response: 201, description: 'Created'),
        new OA\Response(response: 400, description: 'Validation error'),
        new OA\Response(response: 403, description: 'Admin required'),
    ]
)]
#[OA\Delete(
    path: '/taggings.php',
    operationId: 'deleteTagging',
    summary: 'Remove a tagging (admin)',
    servers: [new OA\Server(url: '/api')],
    tags: ['tags'],
    parameters: [
        new OA\Parameter(name: 'id', in: 'query', required: true, schema: new OA\Schema(type: 'integer')),
    ],
    responses: [
        new OA\Response(response: 200, description: 'Deleted'),
        new OA\Response(response: 400, description: 'id required'),
        new OA\Response(response: 403, description: 'Admin required'),
    ]
)]
#[OA\Get(
    path: '/upload-token.php',
    operationId: 'validateUploadToken',
    summary: 'Validate an event upload token',
    description: 'Returns event context for a valid token. Used by guest upload clients to resolve event metadata before uploading.',
    servers: [new OA\Server(url: '/api')],
    tags: ['uploads'],
    parameters: [
        new OA\Parameter(name: 'token', in: 'query', required: true, schema: new OA\Schema(type: 'string', maxLength: 128)),
    ],
    responses: [
        new OA\Response(response: 200, description: 'Valid token', content: new OA\JsonContent(ref: '#/components/schemas/UploadToken')),
        new OA\Response(response: 400, description: 'Missing or oversized token'),
        new OA\Response(response: 404, description: 'Invalid or expired token'),
    ]
)]
#[OA\Get(
    path: '/guest-status.php',
    operationId: 'getGuestStatus',
    summary: 'Check moderation status for a guest upload',
    description: 'Returns the moderation status of the upload identified by the nonce, plus event name, approved video count, and gallery days remaining.',
    servers: [new OA\Server(url: '/api')],
    tags: ['guest'],
    parameters: [
        new OA\Parameter(name: 'nonce', in: 'query', required: true, schema: new OA\Schema(type: 'string', minLength: 30, maxLength: 40, pattern: '^[A-Za-z0-9_\-]+$')),
    ],
    responses: [
        new OA\Response(response: 200, description: 'OK'),
        new OA\Response(response: 400, description: 'Invalid nonce format'),
        new OA\Response(response: 404, description: 'Nonce not found'),
    ]
)]
#[OA\Get(
    path: '/guest-gallery.php',
    operationId: 'getGuestGallery',
    summary: 'List approved gallery videos for a guest',
    description: 'Returns all approved videos for the event associated with the nonce. Requires the nonce owner to also be approved. Returns expired status with empty video list if gallery has expired.',
    servers: [new OA\Server(url: '/api')],
    tags: ['guest'],
    parameters: [
        new OA\Parameter(name: 'nonce', in: 'query', required: true, schema: new OA\Schema(type: 'string', minLength: 30, maxLength: 40, pattern: '^[A-Za-z0-9_\-]+$')),
    ],
    responses: [
        new OA\Response(response: 200, description: 'OK — includes status, days_remaining, and videos array of GuestVideo'),
        new OA\Response(response: 400, description: 'Invalid nonce format'),
        new OA\Response(response: 403, description: 'Forbidden — nonce not approved'),
    ]
)]
#[OA\Get(
    path: '/guest-stream.php',
    operationId: 'streamGuestVideo',
    summary: 'Stream an approved video for a guest (supports HTTP range requests)',
    description: 'Streams video/mp4. Requires the nonce owner to be approved and the requested job_id to belong to the same event. Supports Range header for seeking.',
    servers: [new OA\Server(url: '/api')],
    tags: ['guest'],
    parameters: [
        new OA\Parameter(name: 'nonce', in: 'query', required: true, schema: new OA\Schema(type: 'string', minLength: 30, maxLength: 40)),
        new OA\Parameter(name: 'job_id', in: 'query', required: true, schema: new OA\Schema(type: 'integer', minimum: 1)),
    ],
    responses: [
        new OA\Response(response: 200, description: 'Full video stream (video/mp4)'),
        new OA\Response(response: 206, description: 'Partial content (range request)'),
        new OA\Response(response: 400, description: 'Invalid nonce or job_id'),
        new OA\Response(response: 403, description: 'Forbidden or gallery expired'),
        new OA\Response(response: 404, description: 'Video not found'),
    ]
)]
#[OA\Post(
    path: '/guest-report.php',
    operationId: 'reportGuestVideo',
    summary: 'Flag a video for moderation review',
    description: 'Allows an approved guest to flag another approved video in the same event. Sets guest_flagged=1 on the upload_jobs row. Idempotent — repeat calls update guest_flagged_at.',
    servers: [new OA\Server(url: '/api')],
    tags: ['guest'],
    requestBody: new OA\RequestBody(
        required: true,
        content: [
            new OA\MediaType(
                mediaType: 'application/json',
                schema: new OA\Schema(
                    required: ['nonce', 'upload_job_id'],
                    properties: [
                        new OA\Property(property: 'nonce', type: 'string'),
                        new OA\Property(property: 'upload_job_id', type: 'integer', minimum: 1),
                    ]
                )
            ),
        ]
    ),
    responses: [
        new OA\Response(response: 200, description: 'Flagged successfully'),
        new OA\Response(response: 400, description: 'Invalid request body'),
        new OA\Response(response: 403, description: 'Forbidden — nonce not approved or video not in same event'),
    ]
)]
#[OA\Post(
    path: '/guest-delete.php',
    operationId: 'deleteGuestVideo',
    summary: 'Soft-delete the current guest\'s own uploaded video',
    description: 'Sets guest_deleted=1; moderation_status unchanged so gallery access is preserved. Idempotent — repeat calls update guest_deleted_at. Pre-approval uploads (pending/rejected) cannot be deleted via this endpoint.',
    servers: [new OA\Server(url: '/api')],
    tags: ['guest'],
    requestBody: new OA\RequestBody(
        required: true,
        content: [
            new OA\MediaType(
                mediaType: 'application/json',
                schema: new OA\Schema(
                    required: ['nonce', 'upload_job_id'],
                    properties: [
                        new OA\Property(property: 'nonce', type: 'string', minLength: 30, maxLength: 40, pattern: '^[A-Za-z0-9_\-]+$'),
                        new OA\Property(property: 'upload_job_id', description: 'upload_jobs.id (integer PK) of the guest\'s own upload', type: 'integer', minimum: 1),
                    ]
                )
            ),
        ]
    ),
    responses: [
        new OA\Response(response: 200, description: 'Soft-deleted successfully'),
        new OA\Response(response: 400, description: 'Invalid request body — malformed nonce or non-positive upload_job_id'),
        new OA\Response(response: 403, description: 'Forbidden — nonce not approved, nonce not found, or attempting to delete another guest\'s video'),
    ]
)]
#[OA\Schema(
    schema: 'SystemStats',
    type: 'object',
    properties: [
        new OA\Property(property: 'success', type: 'boolean'),
        new OA\Property(
            property: 'db',
            type: 'object',
            nullable: true,
            description: 'Database summary. Null if DB is unreachable.',
            properties: [
                new OA\Property(property: 'version',    type: 'string'),
                new OA\Property(property: 'db_name',    type: 'string'),
                new OA\Property(property: 'size_bytes', type: 'integer', format: 'int64'),
                new OA\Property(property: 'counts',     type: 'object',  description: 'Row count per table (catalog_scans, catalog_entries, events, assets, event_items, participants, tags, taggings, ai_jobs)'),
            ]
        ),
        new OA\Property(
            property: 'media',
            type: 'object',
            nullable: true,
            description: 'File counts and byte totals per media type. Null if MEDIA_SEARCH_DIRS is unset.',
            properties: [
                new OA\Property(property: 'audio',      type: 'object', properties: [new OA\Property(property: 'count', type: 'integer'), new OA\Property(property: 'bytes', type: 'integer', format: 'int64')]),
                new OA\Property(property: 'video',      type: 'object', properties: [new OA\Property(property: 'count', type: 'integer'), new OA\Property(property: 'bytes', type: 'integer', format: 'int64')]),
                new OA\Property(property: 'thumbnails', type: 'object', properties: [new OA\Property(property: 'count', type: 'integer'), new OA\Property(property: 'bytes', type: 'integer', format: 'int64')]),
                new OA\Property(property: 'total',      type: 'object', properties: [new OA\Property(property: 'count', type: 'integer'), new OA\Property(property: 'bytes', type: 'integer', format: 'int64')]),
            ]
        ),
        new OA\Property(
            property: 'disk',
            type: 'array',
            nullable: true,
            description: 'Host filesystem free/total via bind-mounted media paths (deduplicated by device ID).',
            items: new OA\Items(properties: [
                new OA\Property(property: 'free_bytes',  type: 'integer', format: 'int64'),
                new OA\Property(property: 'total_bytes', type: 'integer', format: 'int64'),
            ])
        ),
        new OA\Property(
            property: 'memory',
            type: 'object',
            nullable: true,
            description: 'Host RAM via /proc/meminfo.',
            properties: [
                new OA\Property(property: 'total_bytes',     type: 'integer', format: 'int64'),
                new OA\Property(property: 'available_bytes', type: 'integer', format: 'int64'),
            ]
        ),
        new OA\Property(
            property: 'network',
            type: 'array',
            nullable: true,
            description: 'Per-interface rx/tx bytes/sec for the apache container (1-second sample).',
            items: new OA\Items(properties: [
                new OA\Property(property: 'iface',  type: 'string'),
                new OA\Property(property: 'rx_bps', type: 'integer'),
                new OA\Property(property: 'tx_bps', type: 'integer'),
            ])
        ),
    ]
)]
#[OA\Get(
    path: '/download_backup.php',
    operationId: 'downloadBackup',
    summary: 'Download a database backup file (admin)',
    description: 'Streams a previously created mysqldump .sql.gz backup file as a browser download. The filename must be a bare basename matching the pattern created by run_backup.php (e.g. media_db_2026-07-12_120000.sql.gz). Used by the Save Backup to Folder option in Section C of the admin system page.',
    servers: [new OA\Server(url: '/db')],
    tags: ['admin'],
    parameters: [
        new OA\Parameter(
            name: 'filename',
            in: 'query',
            required: true,
            schema: new OA\Schema(type: 'string', pattern: '^[\\w\\-.]+\\.sql\\.gz$'),
            description: 'Bare filename of the backup (no path components)'
        ),
    ],
    responses: [
        new OA\Response(response: 200, description: 'OK — backup file streamed as application/gzip attachment'),
        new OA\Response(response: 400, description: 'Missing or invalid filename parameter'),
        new OA\Response(response: 403, description: 'Forbidden — admin access required'),
        new OA\Response(response: 404, description: 'Backup file not found'),
        new OA\Response(response: 405, description: 'Method not allowed — GET only'),
        new OA\Response(response: 500, description: 'Backup directory not configured'),
    ]
)]
#[OA\Post(
    path: '/upload_restore_backup.php',
    operationId: 'uploadRestoreBackup',
    summary: 'Upload a local backup file for restore (admin)',
    description: 'Accepts a raw .sql.gz gzip stream as the POST body (Content-Type: application/octet-stream). Filename is passed as the `filename` query parameter and must end with .sql.gz. Validates the gzip magic bytes before saving. Used by the Accept backup from a local folder option in Section B of the admin system page.',
    servers: [new OA\Server(url: '/db')],
    security: [['basicAuth' => []]],
    parameters: [
        new OA\Parameter(name: 'filename', in: 'query', required: true, schema: new OA\Schema(type: 'string'), description: 'Original filename; must end with .sql.gz'),
    ],
    requestBody: new OA\RequestBody(
        required: true,
        content: [new OA\MediaType(mediaType: 'application/octet-stream', schema: new OA\Schema(type: 'string', format: 'binary'))]
    ),
    responses: [
        new OA\Response(response: 200, description: 'File saved — returns {success, filename, bytes}'),
        new OA\Response(response: 400, description: 'Invalid filename, not a gzip file, or no data received'),
        new OA\Response(response: 403, description: 'Admin required'),
        new OA\Response(response: 405, description: 'Method not allowed — POST only'),
        new OA\Response(response: 500, description: 'Backup directory not writable or stream error'),
    ]
)]
#[OA\Get(
    path: '/admin_system_stats.php',
    operationId: 'getSystemStats',
    summary: 'Get live system stats (admin)',
    description: 'Returns DB counts, media file stats, host disk/memory, and apache container network I/O. Used by the Live mode toggle on the admin system page. Note: incurs a ~1-second server-side delay for the network sample.',
    servers: [new OA\Server(url: '/admin')],
    tags: ['admin'],
    responses: [
        new OA\Response(response: 200, description: 'OK', content: new OA\JsonContent(ref: '#/components/schemas/SystemStats')),
        new OA\Response(response: 403, description: 'Forbidden — admin access required'),
        new OA\Response(response: 405, description: 'Method not allowed'),
        new OA\Response(response: 500, description: 'Server error', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
    ]
)]
class OpenApi {}
