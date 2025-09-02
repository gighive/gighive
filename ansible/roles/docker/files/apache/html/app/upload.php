<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: text/html; charset=UTF-8");

// Database connection using PDO
$host = "mysqlServer";
$dbname = "music_db";
$username = "appuser";
$password = "musiclibrary";

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
} catch (PDOException $e) {
    die(json_encode(["message" => "Database connection failed: " . $e->getMessage()]));
}

// Define upload directories
$audioDir = "/var/www/html/audio/";
$videoDir = "/var/www/html/video/";

if ($_SERVER["REQUEST_METHOD"] === "GET") {
    // Serve the file upload form
    echo <<<HTML
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Upload File</title>
    </head>
    <body>
        <h2>Upload a File</h2>
        <form action="upload.php" method="POST" enctype="multipart/form-data">
            <label for="file">Select File:</label>
            <input type="file" id="file" name="file" accept="audio/*,video/*" required><br><br>

            <label for="jam_date">Jam Date:</label>
            <input type="date" id="jam_date" name="jam_date" required><br><br>

            <label for="jam_summary">Jam Summary:</label>
            <input type="text" id="jam_summary" name="jam_summary" required><br><br>

            <label for="crew">Crew (comma-separated):</label>
            <input type="text" id="crew" name="crew" required><br><br>

            <label for="song_title">Song Title:</label>
            <input type="text" id="song_title" name="song_title" required><br><br>

            <button type="submit">Upload</button>
        </form>
    </body>
    </html>
    HTML;
    exit;
}

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_FILES["file"])) {
    echo "Debug: Processing file upload.<br>";
    
    $file = $_FILES["file"];
    
    // Check for file upload errors
    if ($file["error"] !== UPLOAD_ERR_OK) {
        die(json_encode(["message" => "File upload error: " . $file["error"]]));
    }
    
    $fileType = mime_content_type($file["tmp_name"]);
    $fileName = preg_replace("/[^a-zA-Z0-9\._-]/", "_", basename($file["name"])); // Normalize filename
    
    echo "Debug: Extracted filename - " . $fileName . "<br>";
    error_log("DEBUG: Extracted filename - " . $fileName);
    
    // Determine file directory
    if (strpos($fileType, "audio") !== false) {
        $targetDir = $audioDir;
        $fileCategory = "audio";
    } elseif (strpos($fileType, "video") !== false) {
        $targetDir = $videoDir;
        $fileCategory = "video";
    } else {
        die(json_encode(["message" => "Invalid file type. Only audio and video files are allowed."]));
    }

    $targetFile = $targetDir . $fileName;
    
    // Retrieve metadata
    $jam_date = $_POST["jam_date"] ?? null;
    $jam_summary = $_POST["jam_summary"] ?? null;
    $crew = $_POST["crew"] ?? null;
    $song_title = $_POST["song_title"] ?? null;
    
    echo "Debug: Metadata retrieved.<br>";
    
    try {
        $pdo->beginTransaction();
        
        // Step 1: Check if a jam session exists for the selected date
        $stmt = $pdo->prepare("SELECT jam_session_id FROM jam_sessions WHERE date = :jam_date LIMIT 1");
        $stmt->execute([':jam_date' => $jam_date]);
        $jam_session = $stmt->fetch();
        
        if (!$jam_session) {
            $stmt = $pdo->prepare("INSERT INTO jam_sessions (date, jam_summary, crew) VALUES (:date, :summary, :crew)");
            $stmt->execute([':date' => $jam_date, ':summary' => $jam_summary, ':crew' => $crew]);
            $jam_session_id = $pdo->lastInsertId();
        } else {
            $jam_session_id = $jam_session['jam_session_id'];
        }
        
        $stmt = $pdo->prepare("INSERT INTO songs (title, type) VALUES (:title, 'song')");
        $stmt->execute([':title' => $song_title]);
        $song_id = $pdo->lastInsertId();
        
        $stmt = $pdo->prepare("INSERT INTO jam_session_songs (jam_session_id, song_id) VALUES (:jam_session_id, :song_id)");
        $stmt->execute([':jam_session_id' => $jam_session_id, ':song_id' => $song_id]);
        
        if (move_uploaded_file($file["tmp_name"], $targetFile)) {
            error_log("DEBUG: Attempting to insert file with file_name = $fileName and file_type = $fileCategory");
            $stmt = $pdo->prepare("INSERT INTO files (file_name, file_type) VALUES (:file_name, :file_type)");
            $stmt->execute([
                ':file_name' => $fileName, 
                ':file_type' => $fileCategory
            ]);
            $file_id = $pdo->lastInsertId();
            
            $stmt = $pdo->prepare("INSERT INTO song_files (song_id, file_id) VALUES (:song_id, :file_id)");
            $stmt->execute([':song_id' => $song_id, ':file_id' => $file_id]);
        } else {
            throw new Exception("File upload failed.");
        }
        
        echo "Debug: Upload completed successfully.<br>";
        
        $pdo->commit();
        echo json_encode(["message" => "Upload successful!", "file" => $fileName]);
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(["message" => "Database error: " . $e->getMessage()]);
    }
}
?>

