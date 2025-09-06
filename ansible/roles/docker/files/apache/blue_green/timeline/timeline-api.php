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
    
    // Query to get ALL sessions, even those without songs or musicians
    $sql = <<<SQL
SELECT 
    sesh.session_id,
    sesh.title,
    sesh.date,
    sesh.cover_image_url,
    sesh.duration_seconds,
    COALESCE(crew_data.crew, '') AS crew,
    COALESCE(song_data.song_list, '') AS song_list,
    COALESCE(media_data.media_link, '') AS media_link
FROM sessions sesh
LEFT JOIN (
    SELECT 
        sm.session_id,
        GROUP_CONCAT(DISTINCT m.name ORDER BY m.name SEPARATOR ', ') AS crew
    FROM session_musicians sm
    JOIN musicians m ON sm.musician_id = m.musician_id
    GROUP BY sm.session_id
) crew_data ON sesh.session_id = crew_data.session_id
LEFT JOIN (
    SELECT 
        ss.session_id,
        GROUP_CONCAT(DISTINCT s.title ORDER BY ss.position, s.title SEPARATOR ', ') AS song_list
    FROM session_songs ss
    JOIN songs s ON ss.song_id = s.song_id
    GROUP BY ss.session_id
) song_data ON sesh.session_id = song_data.session_id
LEFT JOIN (
    SELECT DISTINCT
        ss3.session_id,
        FIRST_VALUE(CONCAT(
            CASE 
                WHEN f.file_type = 'video' THEN '/video/'
                WHEN f.file_type = 'audio' THEN '/audio/'
                ELSE '/'
            END,
            f.file_name
        )) OVER (
            PARTITION BY ss3.session_id 
            ORDER BY 
                CASE WHEN f.file_type = 'video' THEN 1 ELSE 2 END,
                ss3.position ASC
        ) AS media_link
    FROM session_songs ss3
    JOIN song_files sf ON ss3.song_id = sf.song_id
    JOIN files f ON sf.file_id = f.file_id
) media_data ON sesh.session_id = media_data.session_id
ORDER BY sesh.date ASC
SQL;

    $stmt = $pdo->query($sql);
    $sessions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Debug: Log the count of sessions returned
    error_log("Timeline API: Found " . count($sessions) . " sessions in database");
    
    // Transform to timeline format
    $timeline_events = [];
    foreach ($sessions as $session) {
        // Generate event ID from date
        $date = new DateTime($session['date']);
        $event_id = $date->format('Ymd');
        
        // Format title (use session title or fallback to date format)
        $title = $session['title'] ?: $date->format('M j');
        
        // Create ISO datetime string for start/end
        $start_datetime = $date->format('Y-m-d') . 'T18:00:00';
        $end_datetime = $date->format('Y-m-d') . 'T21:00:00';
        
        $timeline_events[] = [
            'id' => $event_id,
            'title' => $title,
            'start' => $start_datetime,
            'end' => $end_datetime,
            'crew' => $session['crew'] ?: '',
            'songList' => $session['song_list'] ?: '',
            'image' => $session['cover_image_url'] ?: '',
            'link' => $session['media_link'] ?: ''
        ];
    }
    
    // Debug: Log final count
    error_log("Timeline API: Returning " . count($timeline_events) . " events to frontend");
    
    // Add debug info to response in development
    $response = [
        'events' => $timeline_events,
        'debug' => [
            'total_sessions' => count($sessions),
            'total_events' => count($timeline_events),
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
