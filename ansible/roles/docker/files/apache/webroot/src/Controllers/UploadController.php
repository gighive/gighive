<?php declare(strict_types=1);
namespace Production\Api\Controllers;

use PDO;
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

    /**
     * Handle POST /api/uploads
     * @param array $files Typically $_FILES
     * @param array $post  Typically $_POST
     * @return array {status:int, headers:array, body:array}
     */
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
}
