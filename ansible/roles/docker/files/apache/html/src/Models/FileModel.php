<?php

namespace Production\Api\Models;  // ✅ Namespace must be at the top

use Api\Database;  // ✅ Use namespacing instead of require_once
use PDO;

class FileModel {
    public static function getFilesBySong($songId) {
        error_log("FileModel::getFilesBySong() called with song ID: " . $songId);
        $db = (new Database())->connect();

        $query = "
            SELECT f.file_id, f.file_name, f.file_type 
            FROM files f
            JOIN song_files sf ON f.file_id = sf.file_id
            WHERE sf.song_id = :songId
            LIMIT 1";  // Ensures we only return ONE file

        $stmt = $db->prepare($query);
        $stmt->bindParam(':songId', $songId, PDO::PARAM_INT);
        $stmt->execute();

        $file = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$file) {
            error_log("No file found for song ID: " . $songId);
            return null;
        }

        return $file;  // Return a single file instead of an array
    }

    public static function getFilesByJam($jamId) {
        error_log("FileModel::getFilesByJam() called with jam ID: " . $jamId);
        $db = (new Database())->connect();

        $query = "
            SELECT f.file_id, f.file_name, f.file_type 
            FROM files f
            JOIN song_files sf ON f.file_id = sf.file_id
            JOIN songs s ON sf.song_id = s.song_id
            WHERE s.jam_session_id = :jamId";  // Allows multiple files

        $stmt = $db->prepare($query);
        $stmt->bindParam(':jamId', $jamId, PDO::PARAM_INT);
        $stmt->execute();

        $files = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (!$files) {
            error_log("No files found for jam ID: " . $jamId);
        }

        return $files;  // Returns an array of files
    }
}
?>

