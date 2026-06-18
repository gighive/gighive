<?php declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Production\Api\Infrastructure\Database;
use PDO;

$user = $_SERVER['PHP_AUTH_USER']
    ?? $_SERVER['REMOTE_USER']
    ?? $_SERVER['REDIRECT_REMOTE_USER']
    ?? null;

if ($user !== 'admin') {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Forbidden']);
    exit;
}

header('Content-Type: application/json');

try {
    $pdo = Database::createFromEnv();

    $row = $pdo->query(
        'SELECT
           COUNT(*)                                                                      AS total_files,
           COALESCE(SUM(is_supported), 0)                                               AS supported_files,
           COALESCE(SUM(1 - is_supported), 0)                                           AS unsupported_files,
           COALESCE(SUM(COALESCE(size_bytes, 0)), 0)                                    AS total_size_bytes,
           COALESCE(SUM(CASE WHEN file_type = \'audio\' THEN 1 ELSE 0 END), 0)           AS audio_count,
           COALESCE(SUM(CASE WHEN file_type = \'video\' THEN 1 ELSE 0 END), 0)           AS video_count,
           COALESCE(SUM(CASE WHEN file_type = \'audio\' THEN COALESCE(size_bytes, 0) ELSE 0 END), 0) AS audio_size_bytes,
           COALESCE(SUM(CASE WHEN file_type = \'video\' THEN COALESCE(size_bytes, 0) ELSE 0 END), 0) AS video_size_bytes
         FROM catalog_entries'
    )->fetch(PDO::FETCH_ASSOC);

    $totalFiles      = (int)$row['total_files'];
    $supportedFiles  = (int)$row['supported_files'];
    $unsupportedFiles= (int)$row['unsupported_files'];
    $totalSizeBytes  = (int)$row['total_size_bytes'];
    $audioCount      = (int)$row['audio_count'];
    $videoCount      = (int)$row['video_count'];
    $audioSizeBytes  = (int)$row['audio_size_bytes'];
    $videoSizeBytes  = (int)$row['video_size_bytes'];

    $estAudioMin = (int)round($audioSizeBytes / (192  * 125 * 60));
    $estVideoMin = (int)round($videoSizeBytes / (4000 * 125 * 60));
    $estAiCost   = round($videoCount * 0.046, 2);

    echo json_encode([
        'success' => true,
        'summary' => [
            'total_files'             => $totalFiles,
            'supported_files'         => $supportedFiles,
            'unsupported_files'       => $unsupportedFiles,
            'total_size_bytes'        => $totalSizeBytes,
            'audio_count'             => $audioCount,
            'video_count'             => $videoCount,
            'audio_size_bytes'        => $audioSizeBytes,
            'video_size_bytes'        => $videoSizeBytes,
            'estimated_audio_minutes' => $estAudioMin,
            'estimated_video_minutes' => $estVideoMin,
            'estimated_ai_cost_usd'   => $estAiCost,
            'ai_model'                => (string)(getenv('OPENAI_MODEL') ?: 'n/a'),
        ],
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
