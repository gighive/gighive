<?php declare(strict_types=1);
namespace Production\Api\Controllers;

use OpenApi\Attributes as OA;
use PDO;
use Production\Api\Exceptions\DuplicateChecksumException;
use Production\Api\Services\UploadService;
use Production\Api\Repositories\FileRepository;

final class UploadController
{
    private UploadService $service;
    private FileRepository $files;

    public function __construct(private PDO $pdo)
    {
        $this->service = new UploadService($pdo);
        $this->files   = new FileRepository($pdo);
    }

    private function duplicateChecksumResponse(DuplicateChecksumException $e): array
    {
        return [
            'status'  => 409,
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => [
                'error' => 'Duplicate Upload',
                'message' => 'A file with the same checksum_sha256 already exists on the server. Upload rejected to prevent duplicates.',
                'existing_file_id' => $e->getExistingFileId(),
                'checksum_sha256' => $e->getChecksumSha256(),
            ],
        ];
    }

    /**
     * Handle POST /api/uploads
     * @param array $files Typically $_FILES
     * @param array $post  Typically $_POST
     * @return array {status:int, headers:array, body:array}
     */
    #[OA\Post(
        path: '/uploads',
        operationId: 'uploadMedia',
        summary: 'Upload a media file',
        description: 'Accepts a media file and optional metadata. Stores file, records metadata, and links to a session (created if not present) identified by event_date + org_name. File type (audio/video) is inferred from MIME type and extension.',
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
                            new OA\Property(property: 'event_type', type: 'string', enum: ['band', 'wedding'], description: 'Type of event. Defaults to band'),
                            new OA\Property(property: 'org_name', type: 'string', description: 'Organization name (e.g. band name or wedding short name). Defaults by deployment'),
                            new OA\Property(property: 'label', type: 'string', description: 'Song title (band) or wedding table label. Required'),
                            new OA\Property(property: 'participants', type: 'string', description: 'Comma-separated participant names (musicians or guests)'),
                            new OA\Property(property: 'keywords', type: 'string', description: 'Keywords for searching and categorization'),
                            new OA\Property(property: 'location', type: 'string', description: 'Venue or location name'),
                            new OA\Property(property: 'rating', type: 'string', description: 'Optional rating value'),
                            new OA\Property(property: 'notes', type: 'string', description: 'Free-form notes'),
                        ]
                    )
                ),
            ]
        ),
        responses: [
            new OA\Response(response: 201, description: 'Created', content: new OA\JsonContent(ref: '#/components/schemas/UploadResult')),
            new OA\Response(response: 400, description: 'Bad request (validation/mime/size)', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
            new OA\Response(response: 401, description: 'Unauthorized'),
            new OA\Response(response: 409, description: 'Duplicate upload — a file with the same checksum already exists', content: new OA\JsonContent(ref: '#/components/schemas/DuplicateError')),
            new OA\Response(response: 413, description: 'Payload too large'),
            new OA\Response(response: 415, description: 'Unsupported media type'),
            new OA\Response(response: 500, description: 'Server error', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
        ]
    )]
    public function post(array $files, array $post): array
    {
        try {
            // Helpful error when uploads exceed PHP limits and $_FILES is empty
            if (empty($files) || !isset($files['file'])) {
                $contentLen = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
                if ($contentLen > 0) {
                    return [
                        'status'  => 413,
                        'headers' => ['Content-Type' => 'application/json'],
                        'body'    => [
                            'error' => 'Payload Too Large',
                            'message' => 'Upload exceeded server limits (post_max_size / upload_max_filesize or web server request size).',
                        ],
                    ];
                }
                return [
                    'status'  => 400,
                    'headers' => ['Content-Type' => 'application/json'],
                    'body'    => ['error' => 'Bad Request', 'message' => 'Missing file field'],
                ];
            }
            $result = $this->service->handleUpload($files, $post);
            return [
                'status'  => 201,
                'headers' => ['Content-Type' => 'application/json'],
                'body'    => $result,
            ];
        } catch (DuplicateChecksumException $e) {
            return $this->duplicateChecksumResponse($e);
        } catch (\InvalidArgumentException $e) {
            return [
                'status'  => 400,
                'headers' => ['Content-Type' => 'application/json'],
                'body'    => ['error' => 'Bad Request', 'message' => $e->getMessage()],
            ];
        } catch (\RuntimeException $e) {
            return [
                'status'  => 500,
                'headers' => ['Content-Type' => 'application/json'],
                'body'    => ['error' => 'Server Error', 'message' => $e->getMessage()],
            ];
        }
    }

    /**
     * Handle GET /api/uploads/{id}
     */
    #[OA\Get(
        path: '/uploads/{id}',
        operationId: 'getUploadById',
        summary: 'Get uploaded file metadata',
        servers: [new OA\Server(url: '/api')],
        tags: ['uploads'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer', format: 'int64')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'OK', content: new OA\JsonContent(ref: '#/components/schemas/File')),
            new OA\Response(response: 404, description: 'Not found'),
            new OA\Response(response: 500, description: 'Server error'),
        ]
    )]
    public function get(int $id): array
    {
        $row = $this->files->findById($id);
        if (!$row) {
            return [
                'status'  => 404,
                'headers' => ['Content-Type' => 'application/json'],
                'body'    => ['error' => 'Not Found'],
            ];
        }
        // Normalize payload keys to match OpenAPI File schema
        return [
            'status'  => 200,
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => [
                'id'               => (int)($row['file_id'] ?? 0),
                'file_name'        => (string)($row['file_name'] ?? ''),
                'file_type'        => (string)($row['file_type'] ?? ''),
                'mime_type'        => (string)($row['mime_type'] ?? ''),
                'size_bytes'       => isset($row['size_bytes']) ? (int)$row['size_bytes'] : null,
                'checksum_sha256'  => $row['checksum_sha256'] ?? null,
                'session_id'       => isset($row['session_id']) ? (int)$row['session_id'] : null,
                'seq'              => isset($row['seq']) ? (int)$row['seq'] : null,
                'created_at'       => $row['created_at'] ?? null,
            ],
        ];
    }

    /**
     * Handle POST /api/uploads/finalize
     * @param array $post JSON body (preferred) or form fields
     */
    #[OA\Post(
        path: '/uploads/finalize',
        operationId: 'finalizeTusUpload',
        summary: 'Finalize a TUS chunked upload',
        description: 'Finalizes a completed TUS chunked upload. The client calls this after the last TUS PATCH 204. The server reads the tusd post-finish hook output and runs the same ingestion pipeline as POST /uploads. Metadata fields supplement or override TUS upload metadata. Idempotent via a per-upload_id finalization marker.',
        servers: [new OA\Server(url: '/api')],
        tags: ['uploads'],
        requestBody: new OA\RequestBody(
            required: true,
            content: [
                new OA\MediaType(
                    mediaType: 'application/json',
                    schema: new OA\Schema(
                        required: ['upload_id'],
                        properties: [
                            new OA\Property(property: 'upload_id', type: 'string', pattern: '^[A-Za-z0-9_-]+$', description: 'The TUS upload ID returned by the TUS creation response'),
                            new OA\Property(property: 'event_date', type: 'string', format: 'date', description: 'Event date (YYYY-MM-DD). Falls back to TUS metadata if omitted'),
                            new OA\Property(property: 'event_type', type: 'string', enum: ['band', 'wedding']),
                            new OA\Property(property: 'org_name', type: 'string'),
                            new OA\Property(property: 'label', type: 'string'),
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
            new OA\Response(response: 201, description: 'Created — ingestion complete', content: new OA\JsonContent(ref: '#/components/schemas/UploadResult')),
            new OA\Response(response: 400, description: 'Bad request (missing upload_id, upload not found, empty file, etc.)', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
            new OA\Response(response: 401, description: 'Unauthorized'),
            new OA\Response(response: 409, description: 'Duplicate upload — a file with the same checksum already exists', content: new OA\JsonContent(ref: '#/components/schemas/DuplicateError')),
            new OA\Response(response: 500, description: 'Server error', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
        ]
    )]
    public function finalize(array $post): array
    {
        try {
            $result = $this->service->finalizeTusUpload($post);
            return [
                'status'  => 201,
                'headers' => ['Content-Type' => 'application/json'],
                'body'    => $result,
            ];
        } catch (DuplicateChecksumException $e) {
            return $this->duplicateChecksumResponse($e);
        } catch (\InvalidArgumentException $e) {
            return [
                'status'  => 400,
                'headers' => ['Content-Type' => 'application/json'],
                'body'    => ['error' => 'Bad Request', 'message' => $e->getMessage()],
            ];
        } catch (\RuntimeException $e) {
            return [
                'status'  => 500,
                'headers' => ['Content-Type' => 'application/json'],
                'body'    => ['error' => 'Server Error', 'message' => $e->getMessage()],
            ];
        }
    }
}
