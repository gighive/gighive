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
$supportsParticipantsEdit = $appFlavor === 'defaultcodebase';

$eventId = $payload['event_id'] ?? null;
$eventItemId = $payload['event_item_id'] ?? null;
$orgName = $payload['org_name'] ?? '';
$rating = $payload['rating'] ?? '';
$keywords = $payload['keywords'] ?? '';
$location = $payload['location'] ?? '';
$summary = $payload['summary'] ?? '';
$itemLabel = $payload['item_label'] ?? '';
$participantsCsv = $payload['participants_csv'] ?? '';

$eventId = is_int($eventId) ? $eventId : (is_string($eventId) && ctype_digit($eventId) ? (int)$eventId : 0);
$eventItemId = is_int($eventItemId) ? $eventItemId : (is_string($eventItemId) && ctype_digit($eventItemId) ? (int)$eventItemId : 0);
$orgName = is_string($orgName) ? trim($orgName) : '';
$rating = is_string($rating) ? trim($rating) : '';
$keywords = is_string($keywords) ? trim($keywords) : '';
$location = is_string($location) ? trim($location) : '';
$summary = is_string($summary) ? trim($summary) : '';
$itemLabel = is_string($itemLabel) ? trim($itemLabel) : '';
$participantsCsv = is_string($participantsCsv) ? trim($participantsCsv) : '';

if ($eventId <= 0 || $eventItemId <= 0) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'error' => 'Bad Request',
        'message' => 'Missing/invalid event_id or event_item_id',
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
$e2 = $validateText('Item Label', $itemLabel, 200);
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

$names = $supportsParticipantsEdit ? $splitNames($participantsCsv) : [];

$validateName = static function (string $name): ?string {
    $name = trim($name);
    if ($name === '') {
        return 'Empty participant name.';
    }
    if (mb_strlen($name) > 80) {
        return 'Participant name too long (max 80 chars).';
    }
    if (preg_match('/[\x00-\x1F\x7F]/', $name)) {
        return 'Participant name contains control characters.';
    }
    return null;
};

if ($supportsParticipantsEdit) {
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
         FROM events e
         JOIN event_items ei ON e.event_id = ei.event_id
         WHERE e.event_id = :eid AND ei.event_item_id = :eiid
         LIMIT 1'
    );
    $pairCheck->execute([':eid' => $eventId, ':eiid' => $eventItemId]);
    if (!$pairCheck->fetchColumn()) {
        throw new RuntimeException('The selected row no longer maps to a valid event/event_item pair.');
    }

    if ($supportsExtendedSessionMetadata) {
        $u1 = $pdo->prepare('UPDATE events SET org_name = :o, rating = :rating, keywords = :keywords, location = :location, summary = :summary WHERE event_id = :eid');
        $u1->execute([
            ':o' => $orgName,
            ':rating' => $rating,
            ':keywords' => $keywords,
            ':location' => $location,
            ':summary' => $summary,
            ':eid' => $eventId,
        ]);
    } else {
        $u1 = $pdo->prepare('UPDATE events SET org_name = :o WHERE event_id = :eid');
        $u1->execute([
            ':o' => $orgName,
            ':eid' => $eventId,
        ]);
    }
    if ($u1->rowCount() < 1) {
        $exists = $pdo->prepare('SELECT 1 FROM events WHERE event_id = :eid LIMIT 1');
        $exists->execute([':eid' => $eventId]);
        if (!$exists->fetchColumn()) {
            throw new RuntimeException('Event not found.');
        }
    }

    $u2 = $pdo->prepare('UPDATE event_items SET label = :t WHERE event_item_id = :eiid');
    $u2->execute([':t' => $itemLabel, ':eiid' => $eventItemId]);
    if ($u2->rowCount() < 1) {
        $exists = $pdo->prepare('SELECT 1 FROM event_items WHERE event_item_id = :eiid LIMIT 1');
        $exists->execute([':eiid' => $eventItemId]);
        if (!$exists->fetchColumn()) {
            throw new RuntimeException('Event item not found.');
        }
    }

    $participantIds = [];
    $newParticipants = [];
    if ($supportsParticipantsEdit) {
        $selectPar = $pdo->prepare('SELECT participant_id, name FROM participants WHERE LOWER(name) = :n LIMIT 1');
        $insertPar = $pdo->prepare('INSERT INTO participants (name) VALUES (:name)');

        foreach ($names as $n) {
            $selectPar->execute([':n' => mb_strtolower($n)]);
            $row = $selectPar->fetch(PDO::FETCH_ASSOC);
            if ($row && isset($row['participant_id'])) {
                $participantIds[] = (int)$row['participant_id'];
                continue;
            }

            $insertPar->execute([':name' => $n]);
            $pid = (int)$pdo->lastInsertId();
            if ($pid > 0) {
                $participantIds[] = $pid;
                $newParticipants[] = $n;
            }
        }

        $pdo->prepare('DELETE FROM event_participants WHERE event_id = :eid')->execute([':eid' => $eventId]);

        if ($participantIds) {
            $link = $pdo->prepare('INSERT INTO event_participants (event_id, participant_id) VALUES (:eid, :pid)');
            foreach ($participantIds as $pid) {
                $link->execute([':eid' => $eventId, ':pid' => $pid]);
            }
        }
    }

    $pdo->commit();

    http_response_code(200);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'event_id' => $eventId,
        'event_item_id' => $eventItemId,
        'org_name' => $orgName,
        'rating' => $rating,
        'keywords' => $keywords,
        'location' => $location,
        'summary' => $summary,
        'item_label' => $itemLabel,
        'participants' => $names,
        'new_participants' => $newParticipants,
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
