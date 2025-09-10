<?php

namespace Production\Api\Models;  // ✅ Namespace must be at the top

use Api\Database;  // ✅ Use namespacing instead of require_once
use PDO;

class SongModel {
    public static function getAllSongs() {
        error_log("SongModel::getAllSongs() called");

        $db = (new Database())->connect();
        $query = "SELECT song_id, title, duration, type FROM songs";
        $stmt = $db->prepare($query);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    public static function getSongsByJam($jamSessionId) {
        $db = (new Database())->connect();
        $query = 'SELECT 
                      song_id, 
                      title, 
                      duration, 
                      genre_id, 
                      style_id, 
                      type 
                  FROM songs 
                  WHERE jam_session_id = :jamSessionId 
                  ORDER BY song_id';
        $stmt = $db->prepare($query);
        $stmt->bindParam(':jamSessionId', $jamSessionId, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function getSongDetails($songId) {
        $db = (new Database())->connect();
        $query = 'SELECT 
                      song_id, 
                      title, 
                      duration, 
                      genre_id, 
                      style_id, 
                      jam_session_id, 
                      type 
                  FROM songs 
                  WHERE song_id = :songId';
        $stmt = $db->prepare($query);
        $stmt->bindParam(':songId', $songId, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public static function getSongsByGenre($genreId) {
        $db = (new Database())->connect();
        $query = 'SELECT 
                      song_id, 
                      title, 
                      duration, 
                      style_id, 
                      jam_session_id, 
                      type 
                  FROM songs 
                  WHERE genre_id = :genreId 
                  ORDER BY title';
        $stmt = $db->prepare($query);
        $stmt->bindParam(':genreId', $genreId, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function getSongsByStyle($styleId) {
        $db = (new Database())->connect();
        $query = 'SELECT 
                      song_id, 
                      title, 
                      duration, 
                      genre_id, 
                      jam_session_id, 
                      type 
                  FROM songs 
                  WHERE style_id = :styleId 
                  ORDER BY title';
        $stmt = $db->prepare($query);
        $stmt->bindParam(':styleId', $styleId, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
?>

