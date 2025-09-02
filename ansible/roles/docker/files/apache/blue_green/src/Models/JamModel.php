<?php

namespace Production\Api\Models;  // ✅ Namespace must be at the top

use Api\Database;  // ✅ Use namespacing instead of require_once
use PDO;

class JamModel {
    public static function getAllJams() {
        error_log("JamModel::getAllJams() called");

        $db = (new Database())->connect();
        $query = "SELECT jam_session_id, name, date, duration FROM jam_sessions";
        $stmt = $db->prepare($query);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
?>

