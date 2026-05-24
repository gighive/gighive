<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
header('Access-Control-Allow-Headers: Content-Type');

// Autoload and use env-based DB configuration (no credentials in code)
require_once __DIR__ . '/../vendor/autoload.php';
use Production\Api\Infrastructure\Database;

try {
    // Create PDO from environment variables (DB_HOST, MYSQL_DATABASE, MYSQL_USER, MYSQL_PASSWORD, DB_CHARSET)
    $pdo = Database::createFromEnv();
    
    // Query to get ALL events, even those without items or participants
    $sql = <<<SQL
SELECT
    e.event_id,
    e.title,
    e.event_date                          AS date,
    e.cover_image_url,
    e.duration_seconds,
    e.org_name,
    e.event_type,
    COALESCE(crew_data.crew, '')          AS crew,
    COALESCE(item_data.song_list, '')     AS song_list,
    COALESCE(media_data.media_link, '')   AS media_link
FROM events e
LEFT JOIN (
    SELECT
        ep.event_id,
        GROUP_CONCAT(DISTINCT p.name ORDER BY p.name SEPARATOR ', ') AS crew
    FROM event_participants ep
    JOIN participants p ON ep.participant_id = p.participant_id
    GROUP BY ep.event_id
) crew_data ON e.event_id = crew_data.event_id
LEFT JOIN (
    SELECT
        ei.event_id,
        GROUP_CONCAT(DISTINCT ei.label ORDER BY ei.position, ei.label SEPARATOR ', ') AS song_list
    FROM event_items ei
    GROUP BY ei.event_id
) item_data ON e.event_id = item_data.event_id
LEFT JOIN (
    SELECT DISTINCT
        ei2.event_id,
        FIRST_VALUE(CONCAT(
            CASE WHEN a.file_type = 'video' THEN '/video/' ELSE '/audio/' END,
            a.checksum_sha256, '.', a.file_ext
        )) OVER (
            PARTITION BY ei2.event_id
            ORDER BY
                CASE WHEN a.file_type = 'video' THEN 1 ELSE 2 END,
                ei2.position ASC
        ) AS media_link
    FROM event_items ei2
    JOIN assets a ON ei2.asset_id = a.asset_id
    WHERE a.checksum_sha256 IS NOT NULL
      AND a.file_ext IS NOT NULL AND a.file_ext != ''
) media_data ON e.event_id = media_data.event_id
ORDER BY e.event_date ASC
SQL;

    $stmt = $pdo->query($sql);
    $events = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Debug: Log the count of events returned
    error_log("Timeline API: Found " . count($events) . " events in database");
    
    // Transform to timeline format
    $timeline_events = [];
    foreach ($events as $event) {
        $date = new DateTime($event['date']);
        $event_id = (int)$event['event_id'];
        
        // Format title (use event title or fallback to date format)
        $title = $event['title'] ?: $date->format('M j');
        
        // Create ISO datetime string for start/end
        $start_datetime = $date->format('Y-m-d') . 'T18:00:00';
        $end_datetime = $date->format('Y-m-d') . 'T21:00:00';
        
        $timeline_events[] = [
            'id' => $event_id,
            'title' => $title,
            'start' => $start_datetime,
            'end' => $end_datetime,
            'crew' => $event['crew'] ?: '',
            'songList' => $event['song_list'] ?: '',
            'image' => $event['cover_image_url'] ?: '',
            'link' => $event['media_link'] ?: '',
            'orgName' => $event['org_name'] ?? null,
            'eventType' => $event['event_type'] ?? null,
        ];
    }
    
    // Debug: Log final count
    error_log("Timeline API: Returning " . count($timeline_events) . " events to frontend");
    
    // Add debug info to response in development
    $response = [
        'events' => $timeline_events,
        'debug' => [
            'total_events' => count($events),
            'total_timeline_events' => count($timeline_events),
            'query_executed' => true
        ]
    ];
    
    echo json_encode($response, JSON_PRETTY_PRINT);
    
} catch (PDOException $e) {
    error_log("Timeline API Database Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed: ' . $e->getMessage()]);
} catch (Exception $e) {
    error_log("Timeline API Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'An error occurred: ' . $e->getMessage()]);
}
?>
