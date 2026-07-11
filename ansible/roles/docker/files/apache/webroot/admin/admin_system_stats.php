<?php declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

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

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    header('Content-Type: application/json');
    header('Allow: GET');
    echo json_encode(['success' => false, 'error' => 'Method Not Allowed']);
    exit;
}

header('Content-Type: application/json');

try {
    // ── DB stats ──────────────────────────────────────────────────────────────
    $db = null;
    try {
        $pdo    = Database::createFromEnv();
        $dbName = getenv('MYSQL_DATABASE') ?: 'media_db';
        $ver    = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
        $szStmt = $pdo->prepare(
            'SELECT COALESCE(SUM(data_length + index_length), 0) FROM information_schema.tables WHERE table_schema = ?'
        );
        $szStmt->execute([$dbName]);
        $sizeBytes = (int) $szStmt->fetchColumn();
        $counts    = [];
        foreach (['events', 'assets', 'event_items', 'participants', 'tags', 'taggings'] as $tbl) {
            try { $counts[$tbl] = (int) $pdo->query("SELECT COUNT(*) FROM `{$tbl}`")->fetchColumn(); }
            catch (Throwable) { $counts[$tbl] = 0; }
        }
        $db = ['version' => $ver, 'db_name' => $dbName, 'size_bytes' => $sizeBytes, 'counts' => $counts];
    } catch (Throwable) {}

    // ── Media + disk stats ────────────────────────────────────────────────────
    $media = null;
    $disk  = null;
    $mdirsEnv = getenv('MEDIA_SEARCH_DIRS');
    if (is_string($mdirsEnv) && $mdirsEnv !== '') {
        $mdirs  = array_filter(array_map('trim', explode(':', $mdirsEnv)));
        $audioD = null;
        $videoD = null;
        foreach ($mdirs as $d) {
            $d = rtrim($d, '/');
            if ($audioD === null && str_ends_with($d, '/audio'))     { $audioD = $d; }
            elseif ($videoD === null && str_ends_with($d, '/video')) { $videoD = $d; }
        }
        $mscan = [
            'audio'      => $audioD,
            'video'      => $videoD,
            'thumbnails' => $videoD !== null ? $videoD . '/thumbnails' : null,
        ];
        $media = [];
        foreach ($mscan as $lbl => $path) {
            $cnt = 0; $bytes = 0;
            if ($path !== null && is_dir($path) && is_readable($path)) {
                foreach (glob($path . '/*') ?: [] as $f) {
                    if (is_file($f)) { $cnt++; $bytes += (int) (@filesize($f) ?: 0); }
                }
            }
            $media[$lbl] = ['count' => $cnt, 'bytes' => $bytes];
        }
        $media['total'] = [
            'count' => array_sum(array_column($media, 'count')),
            'bytes' => array_sum(array_column($media, 'bytes')),
        ];
        $diskPaths = array_filter([$audioD, $videoD], fn($p) => $p !== null && is_dir($p));
        if (!empty($diskPaths)) {
            $diskSeen = [];
            foreach ($diskPaths as $dp) {
                $st    = @stat($dp);
                $dev   = ($st !== false && isset($st['dev'])) ? (int)$st['dev'] : null;
                $dfree = @disk_free_space($dp);
                $dtot  = @disk_total_space($dp);
                if ($dfree === false || $dtot === false || (int)$dtot <= 0) continue;
                $dkey = $dev !== null ? $dev : $dp;
                if (!isset($diskSeen[$dkey])) {
                    $diskSeen[$dkey] = ['free_bytes' => (int)$dfree, 'total_bytes' => (int)$dtot];
                }
            }
            if (!empty($diskSeen)) $disk = array_values($diskSeen);
        }
    }

    // ── Memory stats ──────────────────────────────────────────────────────────
    $memory = null;
    $meminfoRaw = @file_get_contents('/proc/meminfo');
    if (is_string($meminfoRaw)) {
        $mvals = [];
        foreach (explode("\n", $meminfoRaw) as $line) {
            if (preg_match('/^(\w+):\s+(\d+)\s+kB/', $line, $m)) {
                $mvals[$m[1]] = (int)$m[2] * 1024;
            }
        }
        if (isset($mvals['MemTotal'], $mvals['MemAvailable'])) {
            $memory = ['total_bytes' => $mvals['MemTotal'], 'available_bytes' => $mvals['MemAvailable']];
        }
    }

    // ── Network stats (1-second sample) ───────────────────────────────────────
    $network = null;
    $raw1 = @file_get_contents('/proc/net/dev');
    if (is_string($raw1)) {
        sleep(1);
        $raw2 = @file_get_contents('/proc/net/dev');
        if (is_string($raw2)) {
            $parseDev = function(string $raw): array {
                $out = [];
                foreach (explode("\n", $raw) as $line) {
                    $line = trim($line);
                    if (!str_contains($line, ':')) continue;
                    [$iface, $data] = explode(':', $line, 2);
                    $iface = trim($iface);
                    if ($iface === '' || $iface === 'lo') continue;
                    $fields = preg_split('/\s+/', trim($data));
                    if (count($fields) < 9) continue;
                    $out[$iface] = ['rx' => (int)$fields[0], 'tx' => (int)$fields[8]];
                }
                return $out;
            };
            $nd1     = $parseDev($raw1);
            $nd2     = $parseDev($raw2);
            $network = [];
            foreach ($nd1 as $iface => $v1) {
                if (!isset($nd2[$iface])) continue;
                $network[] = [
                    'iface'  => $iface,
                    'rx_bps' => max(0, $nd2[$iface]['rx'] - $v1['rx']),
                    'tx_bps' => max(0, $nd2[$iface]['tx'] - $v1['tx']),
                ];
            }
            if (empty($network)) $network = null;
        }
    }

    echo json_encode([
        'success' => true,
        'db'      => $db,
        'media'   => $media,
        'disk'    => $disk,
        'memory'  => $memory,
        'network' => $network,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
