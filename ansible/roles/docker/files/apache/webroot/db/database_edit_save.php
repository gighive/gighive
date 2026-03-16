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
    echo json_encode([
        'success' => false,
        'error' => 'Forbidden',
        'message' => 'Admin access required',
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Content-Type: application/json');
    header('Allow: POST');
    echo json_encode([
        'success' => false,
        'error' => 'Method Not Allowed',
        'message' => 'Only POST requests are accepted',
    ]);
    exit;
}

$rawBody = file_get_contents('php://input');
$payload = json_decode(is_string($rawBody) ? $rawBody : '', true);
if (!is_array($payload)) {
    $payload = [];
}

$appFlavor = getenv('APP_FLAVOR');
$appFlavor = $appFlavor !== false ? trim((string)$appFlavor) : '';
if ($appFlavor === '') {
    $appFlavor = 'defaultcodebase';
}
$supportsExtendedSessionMetadata = $appFlavor === 'defaultcodebase';
$supportsMusiciansEdit = $appFlavor === 'defaultcodebase';

$sessionId = $payload['session_id'] ?? null;
$songId = $payload['song_id'] ?? null;
$orgName = $payload['org_name'] ?? '';
$rating = $payload['rating'] ?? '';
$keywords = $payload['keywords'] ?? '';
$location = $payload['location'] ?? '';
$summary = $payload['summary'] ?? '';
$songTitle = $payload['song_title'] ?? '';
$musiciansCsv = $payload['musicians_csv'] ?? '';

$sessionId = is_int($sessionId) ? $sessionId : (is_string($sessionId) && ctype_digit($sessionId) ? (int)$sessionId : 0);
$songId = is_int($songId) ? $songId : (is_string($songId) && ctype_digit($songId) ? (int)$songId : 0);
$orgName = is_string($orgName) ? trim($orgName) : '';
$rating = is_string($rating) ? trim($rating) : '';
$keywords = is_string($keywords) ? trim($keywords) : '';
$location = is_string($location) ? trim($location) : '';
$summary = is_string($summary) ? trim($summary) : '';
$songTitle = is_string($songTitle) ? trim($songTitle) : '';
$musiciansCsv = is_string($musiciansCsv) ? trim($musiciansCsv) : '';

if ($sessionId <= 0 || $songId <= 0) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'error' => 'Bad Request',
        'message' => 'Missing/invalid session_id or song_id',
    ]);
    exit;
}

$validateText = static function (string $label, string $val, int $maxLen): ?string {
    if ($val === '') {
        return $label . ' is required.';
    }
    if (mb_strlen($val) > $maxLen) {
        return $label . ' too long (max ' . (string)$maxLen . ' chars).';
    }
    if (preg_match('/[\x00-\x1F\x7F]/', $val)) {
        return $label . ' contains control characters.';
    }
    return null;
};

$errors = [];
$e1 = $validateText('Band or Event', $orgName, 120);
if ($e1 !== null) $errors[] = $e1;
if ($supportsExtendedSessionMetadata) {
    $e1b = $validateText('Rating', $rating, 40);
    if ($e1b !== null) $errors[] = $e1b;
    $e1c = $validateText('Keywords', $keywords, 255);
    if ($e1c !== null) $errors[] = $e1c;
    $e1d = $validateText('Location', $location, 200);
    if ($e1d !== null) $errors[] = $e1d;
    $e1e = $validateText('Summary', $summary, 1000);
    if ($e1e !== null) $errors[] = $e1e;
}
$e2 = $validateText('Song Name', $songTitle, 200);
if ($e2 !== null) $errors[] = $e2;

$splitNames = static function (string $s): array {
    $parts = array_map('trim', explode(',', $s));
    $parts = array_values(array_filter($parts, static fn($x) => $x !== ''));
    $seen = [];
    $out = [];
    foreach ($parts as $p) {
        $k = mb_strtolower($p);
        if (isset($seen[$k])) continue;
        $seen[$k] = true;
        $out[] = $p;
    }
    return $out;
};

$names = $supportsMusiciansEdit ? $splitNames($musiciansCsv) : [];

$validateName = static function (string $name): ?string {
    $name = trim($name);
    if ($name === '') {
        return 'Empty musician name.';
    }
    if (mb_strlen($name) > 80) {
        return 'Musician name too long (max 80 chars).';
    }
    if (preg_match('/[\x00-\x1F\x7F]/', $name)) {
        return 'Musician name contains control characters.';
    }
    return null;
};

if ($supportsMusiciansEdit) {
    foreach ($names as $n) {
        $err = $validateName($n);
        if ($err !== null) {
            $errors[] = $n . ': ' . $err;
        }
    }
}

if ($errors) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'error' => 'Bad Request',
        'message' => 'Validation failed',
        'errors' => $errors,
    ]);
    exit;
}

try {
    $pdo = Database::createFromEnv();
    $pdo->beginTransaction();

    $pairCheck = $pdo->prepare(
        'SELECT 1
         FROM sessions sesh
         JOIN session_songs ss ON sesh.session_id = ss.session_id
         JOIN songs s ON ss.song_id = s.song_id
         WHERE sesh.session_id = :sid AND s.song_id = :gid
         LIMIT 1'
    );
    $pairCheck->execute([':sid' => $sessionId, ':gid' => $songId]);
    if (!$pairCheck->fetchColumn()) {
        throw new RuntimeException('The selected row no longer maps to a valid session/song pair.');
    }

    if ($supportsExtendedSessionMetadata) {
        $u1 = $pdo->prepare('UPDATE sessions SET org_name = :o, rating = :rating, keywords = :keywords, location = :location, summary = :summary WHERE session_id = :sid');
        $u1->execute([
            ':o' => $orgName,
            ':rating' => $rating,
            ':keywords' => $keywords,
            ':location' => $location,
            ':summary' => $summary,
            ':sid' => $sessionId,
        ]);
    } else {
        $u1 = $pdo->prepare('UPDATE sessions SET org_name = :o WHERE session_id = :sid');
        $u1->execute([
            ':o' => $orgName,
            ':sid' => $sessionId,
        ]);
    }
    if ($u1->rowCount() < 1) {
        $exists = $pdo->prepare('SELECT 1 FROM sessions WHERE session_id = :sid LIMIT 1');
        $exists->execute([':sid' => $sessionId]);
        if (!$exists->fetchColumn()) {
            throw new RuntimeException('Session not found.');
        }
    }

    $u2 = $pdo->prepare('UPDATE songs SET title = :t WHERE song_id = :gid');
    $u2->execute([':t' => $songTitle, ':gid' => $songId]);
    if ($u2->rowCount() < 1) {
        $exists = $pdo->prepare('SELECT 1 FROM songs WHERE song_id = :gid LIMIT 1');
        $exists->execute([':gid' => $songId]);
        if (!$exists->fetchColumn()) {
            throw new RuntimeException('Song not found.');
        }
    }

    $musicianIds = [];
    $newMusicians = [];
    if ($supportsMusiciansEdit) {
        $selectMus = $pdo->prepare('SELECT musician_id, name FROM musicians WHERE LOWER(name) = :n LIMIT 1');
        $insertMus = $pdo->prepare('INSERT INTO musicians (name) VALUES (:name)');

        foreach ($names as $n) {
            $selectMus->execute([':n' => mb_strtolower($n)]);
            $row = $selectMus->fetch(PDO::FETCH_ASSOC);
            if ($row && isset($row['musician_id'])) {
                $musicianIds[] = (int)$row['musician_id'];
                continue;
            }

            $insertMus->execute([':name' => $n]);
            $mid = (int)$pdo->lastInsertId();
            if ($mid > 0) {
                $musicianIds[] = $mid;
                $newMusicians[] = $n;
            }
        }

        $pdo->prepare('DELETE FROM session_musicians WHERE session_id = :sid')->execute([':sid' => $sessionId]);

        if ($musicianIds) {
            $link = $pdo->prepare('INSERT INTO session_musicians (session_id, musician_id) VALUES (:sid, :mid)');
            foreach ($musicianIds as $mid) {
                $link->execute([':sid' => $sessionId, ':mid' => $mid]);
            }
        }
    }

    $pdo->commit();

    http_response_code(200);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'session_id' => $sessionId,
        'song_id' => $songId,
        'org_name' => $orgName,
        'rating' => $rating,
        'keywords' => $keywords,
        'location' => $location,
        'summary' => $summary,
        'song_title' => $songTitle,
        'musicians' => $names,
        'new_musicians' => $newMusicians,
    ]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'error' => 'Server Error',
        'message' => $e->getMessage(),
    ]);
}
