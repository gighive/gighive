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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Content-Type: application/json');
    header('Allow: POST');
    echo json_encode(['success' => false, 'error' => 'Method Not Allowed']);
    exit;
}

header('Content-Type: application/json');

$raw     = file_get_contents('php://input');
$payload = json_decode(is_string($raw) ? $raw : '', true);
if (!is_array($payload)) $payload = [];

$entryId = $payload['catalog_entry_id'] ?? null;
$action  = trim((string)($payload['action'] ?? 'save'));

$entryId = is_int($entryId)
    ? $entryId
    : (is_string($entryId) && ctype_digit($entryId) ? (int)$entryId : 0);

if ($entryId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'catalog_entry_id is required']);
    exit;
}
if (!in_array($action, ['save', 'delete'], true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'action must be "save" or "delete"']);
    exit;
}

try {
    $pdo = Database::createFromEnv();

    $existStmt = $pdo->prepare('SELECT is_supported, status FROM catalog_entries WHERE catalog_entry_id = ? LIMIT 1');
    $existStmt->execute([$entryId]);
    $existing = $existStmt->fetch(PDO::FETCH_ASSOC);
    if (!$existing) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Entry not found']);
        exit;
    }

    if ($action === 'delete') {
        $pdo->prepare('DELETE FROM catalog_entries WHERE catalog_entry_id = ?')->execute([$entryId]);
        echo json_encode(['success' => true]);
        exit;
    }

    // --- action === 'save' ---

    // Collect only fields present in the payload (array_key_exists distinguishes "not sent" from "sent as null")
    $updates    = [];
    $updateVals = [];

    $addField = static function (string $col, $raw, ?callable $validate = null)
        use (&$updates, &$updateVals): ?string
    {
        $val = ($raw === '' || $raw === null) ? null : trim((string)$raw);
        if ($validate !== null && $val !== null) {
            $err = $validate($val);
            if ($err !== null) return $err;
        }
        $updates[]    = "$col = ?";
        $updateVals[] = $val;
        return null;
    };

    $errors = [];

    if (array_key_exists('status', $payload)) {
        $s = trim((string)($payload['status'] ?? ''));
        if (in_array($s, ['imported', 'failed'], true)) {
            $errors[] = 'status=imported/failed is pipeline-set and cannot be assigned by the operator';
        } elseif (!in_array($s, ['cataloged', 'selected', 'skipped'], true)) {
            $errors[] = 'Invalid status value';
        } elseif ($s === 'selected' && !(int)$existing['is_supported']) {
            $errors[] = 'Cannot select an unsupported file type for import';
        } else {
            $updates[]    = 'status = ?';
            $updateVals[] = $s;
        }
    }

    if (array_key_exists('event_date', $payload)) {
        $err = $addField('event_date', $payload['event_date'], static function (string $v): ?string {
            return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? null : 'event_date must be YYYY-MM-DD';
        });
        if ($err) $errors[] = $err;
    }

    if (array_key_exists('event_type', $payload)) {
        $err = $addField('event_type', $payload['event_type'], static function (string $v): ?string {
            return in_array($v, ['band', 'wedding', 'other'], true) ? null : 'Invalid event_type';
        });
        if ($err) $errors[] = $err;
    }

    if (array_key_exists('item_type', $payload)) {
        $err = $addField('item_type', $payload['item_type'], static function (string $v): ?string {
            return in_array($v, ['song', 'loop', 'clip', 'highlight'], true) ? null : 'Invalid item_type';
        });
        if ($err) $errors[] = $err;
    }

    foreach (['org_name', 'location', 'label', 'keywords', 'summary', 'participants', 'notes'] as $col) {
        if (array_key_exists($col, $payload)) {
            $addField($col, $payload[$col]);
        }
    }

    if ($errors) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Validation failed', 'errors' => $errors]);
        exit;
    }

    if (!$updates) {
        echo json_encode(['success' => true, 'message' => 'Nothing to update']);
        exit;
    }

    $updateVals[] = $entryId;
    $pdo->prepare(
        'UPDATE catalog_entries SET ' . implode(', ', $updates) . ' WHERE catalog_entry_id = ?'
    )->execute($updateVals);

    echo json_encode(['success' => true]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server Error', 'message' => $e->getMessage()]);
}
