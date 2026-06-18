<?php declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

use Production\Api\Infrastructure\Database;

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
    header('Allow: POST');
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Method Not Allowed']);
    exit;
}

header('Content-Type: application/json');

try {
    $pdo = Database::createFromEnv();

    $stmt = $pdo->query(
        "SELECT
            e.catalog_entry_id,
            e.path_hash,
            e.scan_id,
            e.source_relpath,
            e.file_name,
            e.file_ext,
            e.file_type,
            e.mime_type,
            e.size_bytes,
            e.label,
            e.item_type,
            e.participants,
            s.source_root                          AS source_root,
            COALESCE(e.org_name,   s.org_name)     AS org_name,
            COALESCE(e.event_date, s.event_date)   AS event_date,
            COALESCE(e.event_type, s.event_type)   AS event_type,
            COALESCE(e.location,   s.location)     AS location,
            COALESCE(e.keywords,   s.keywords)     AS keywords,
            COALESCE(e.summary,    s.summary)      AS summary
         FROM catalog_entries e
         JOIN catalog_scans   s ON s.scan_id = e.scan_id
        WHERE e.status = 'selected' AND e.is_supported = 1"
    );
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Count selected-but-unsupported entries excluded from this batch
    $exclStmt = $pdo->query(
        "SELECT COUNT(*) FROM catalog_entries WHERE status = 'selected' AND is_supported = 0"
    );
    $excludedCount = (int)$exclStmt->fetchColumn();

    // Required-field validation (org_name and event_date must be non-empty after COALESCE)
    $validationErrors = [];
    foreach ($rows as $row) {
        $missing = [];
        if (trim((string)($row['org_name']   ?? '')) === '') $missing[] = 'org_name';
        if (trim((string)($row['event_date'] ?? '')) === '') $missing[] = 'event_date';
        if ($missing) {
            $validationErrors[] = [
                'catalog_entry_id' => (int)$row['catalog_entry_id'],
                'file_name'        => (string)$row['file_name'],
                'source_root'      => (string)$row['source_root'],
                'missing'          => $missing,
            ];
        }
    }

    if ($validationErrors) {
        http_response_code(422);
        echo json_encode([
            'success' => false,
            'status'  => 'validation_error',
            'message' => count($validationErrors) . ' selected '
                . (count($validationErrors) === 1 ? 'entry is' : 'entries are')
                . ' missing required fields (org_name and/or event_date).',
            'entries' => $validationErrors,
        ]);
        exit;
    }

    // Detect source_root collisions: same source_root name appears under multiple scan_ids
    $sourceRootScanIds = [];
    foreach ($rows as $row) {
        $sr  = (string)$row['source_root'];
        $sid = (int)$row['scan_id'];
        $sourceRootScanIds[$sr][$sid] = true;
    }
    $collisionWarnings = [];
    foreach ($sourceRootScanIds as $sr => $scanIds) {
        if (count($scanIds) > 1) {
            $collisionWarnings[] = (string)$sr;
        }
    }

    // Ordered list of distinct source_roots (for folder-picker sequence).
    // array_keys() silently casts all-digit keys (e.g. "20050526") to integers;
    // strval() forces them back to strings so json_encode emits JSON strings, not numbers.
    $sourceRoots = array_map('strval', array_keys($sourceRootScanIds));
    sort($sourceRoots);

    // Build manifest items (auto-derive item_type where NULL)
    $items = [];
    foreach ($rows as $row) {
        $itemType = (isset($row['item_type']) && $row['item_type'] !== '') ? $row['item_type'] : null;
        if ($itemType === null) {
            $itemType = ($row['event_type'] === 'wedding') ? 'clip' : 'song';
        }
        $items[] = [
            'catalog_entry_id' => (int)$row['catalog_entry_id'],
            'path_hash'        => (string)$row['path_hash'],
            'source_root'      => (string)$row['source_root'],
            'source_relpath'   => (string)$row['source_relpath'],
            'file_name'        => (string)$row['file_name'],
            'file_ext'         => (string)($row['file_ext'] ?? ''),
            'file_type'        => (string)$row['file_type'],
            'size_bytes'       => $row['size_bytes'] !== null ? (int)$row['size_bytes'] : null,
            'org_name'         => (string)$row['org_name'],
            'event_date'       => (string)$row['event_date'],
            'event_type'       => $row['event_type'] ?? 'band',
            'location'         => $row['location'],
            'keywords'         => $row['keywords'],
            'summary'          => $row['summary'],
            'label'            => $row['label'],
            'item_type'        => $itemType,
            'participants'     => $row['participants'],
        ];
    }

    echo json_encode([
        'success'            => true,
        'item_count'         => count($items),
        'excluded_count'     => $excludedCount,
        'source_roots'       => $sourceRoots,
        'collision_warnings' => $collisionWarnings,
        'items'              => $items,
    ]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server Error', 'message' => $e->getMessage()]);
}
