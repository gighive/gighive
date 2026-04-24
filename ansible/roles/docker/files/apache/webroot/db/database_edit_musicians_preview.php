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

$raw = $payload['participants_csv'] ?? '';
$raw = is_string($raw) ? trim($raw) : '';

$split = static function (string $s): array {
    $parts = array_map('trim', explode(',', $s));
    $parts = array_values(array_filter($parts, static fn($x) => $x !== ''));
    $seen = [];
    $out = [];
    foreach ($parts as $p) {
        $k = mb_strtolower($p);
        if (isset($seen[$k])) {
            continue;
        }
        $seen[$k] = true;
        $out[] = $p;
    }
    return $out;
};

$names = $split($raw);

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

$errors = [];
foreach ($names as $n) {
    $err = $validateName($n);
    if ($err !== null) {
        $errors[] = $n . ': ' . $err;
    }
}

if ($errors) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'error' => 'Bad Request',
        'message' => 'Invalid participant list',
        'errors' => $errors,
        'existing' => [],
        'new' => [],
        'normalized' => $names,
    ]);
    exit;
}

try {
    $pdo = Database::createFromEnv();

    $existing = [];
    $new = [];

    if ($names) {
        $placeholders = [];
        $params = [];
        foreach ($names as $i => $n) {
            $k = ':n' . $i;
            $placeholders[] = $k;
            $params[$k] = mb_strtolower($n);
        }

        $sql = 'SELECT participant_id, name FROM participants WHERE LOWER(name) IN (' . implode(',', $placeholders) . ')';
        $stmt = $pdo->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v, PDO::PARAM_STR);
        }
        $stmt->execute();

        $foundByLower = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $nm = isset($row['name']) ? (string)$row['name'] : '';
            $foundByLower[mb_strtolower($nm)] = $nm;
        }

        foreach ($names as $n) {
            $lk = mb_strtolower($n);
            if (isset($foundByLower[$lk])) {
                $existing[] = $foundByLower[$lk];
            } else {
                $new[] = $n;
            }
        }
    }

    http_response_code(200);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'existing' => $existing,
        'new' => $new,
        'normalized' => $names,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'error' => 'Server Error',
        'message' => $e->getMessage(),
    ]);
}
