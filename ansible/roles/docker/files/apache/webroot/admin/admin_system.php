<?php
$user = $_SERVER['PHP_AUTH_USER']
    ?? $_SERVER['REMOTE_USER']
    ?? $_SERVER['REDIRECT_REMOTE_USER']
    ?? null;

if ($user !== 'admin') {
    http_response_code(403);
    echo "<h1>Forbidden</h1><p>Admin access required.</p>";
    exit;
}

$__install_channel = getenv('GIGHIVE_INSTALL_CHANNEL') ?: 'full';
$__show_disk_resize = ($__install_channel === 'full');
$__apache_container_name = getenv('GIGHIVE_APACHE_CONTAINER_NAME') ?: '';

$__restore_backup_dir = getenv('GIGHIVE_MYSQL_BACKUPS_DIR') ?: '';
$__restore_db_name = getenv('MYSQL_DATABASE') ?: '';
$__restore_backup_files = [];

if (is_string($__restore_backup_dir) && $__restore_backup_dir !== '' && is_dir($__restore_backup_dir) && is_readable($__restore_backup_dir) && is_string($__restore_db_name) && $__restore_db_name !== '') {
    $latest = $__restore_db_name . '_latest.sql.gz';
    $latestPath = rtrim($__restore_backup_dir, '/') . '/' . $latest;
    if (is_file($latestPath)) {
        $__restore_backup_files[] = [
            'name' => $latest,
            'mtime' => @filemtime($latestPath) ?: 0,
            'size' => @filesize($latestPath) ?: 0,
            'is_latest' => true,
        ];
    }

    $pattern = rtrim($__restore_backup_dir, '/') . '/' . $__restore_db_name . '_*.sql.gz';
    foreach (glob($pattern) ?: [] as $p) {
        if (!is_string($p) || !is_file($p)) {
            continue;
        }
        $bn = basename($p);
        if ($bn === $latest) {
            continue;
        }
        if (!preg_match('/^' . preg_quote($__restore_db_name, '/') . '_\\d{4}-\\d{2}-\\d{2}_\\d{6}\\.sql\\.gz$/', $bn)) {
            continue;
        }
        $__restore_backup_files[] = [
            'name' => $bn,
            'mtime' => @filemtime($p) ?: 0,
            'size' => @filesize($p) ?: 0,
            'is_latest' => false,
        ];
    }

    usort($__restore_backup_files, function ($a, $b) {
        $aLatest = !empty($a['is_latest']);
        $bLatest = !empty($b['is_latest']);
        if ($aLatest !== $bLatest) {
            return $aLatest ? -1 : 1;
        }
        return ($b['mtime'] ?? 0) <=> ($a['mtime'] ?? 0);
    });
}

function __format_backup_size(int $bytes): string {
    if ($bytes <= 0) return '0 B';
    $units = ['B', 'KB', 'MB', 'GB'];
    $i = (int) floor(log($bytes, 1024));
    $i = min($i, count($units) - 1);
    $val = $bytes / (1024 ** $i);
    return ($i === 0 ? (string)$bytes : number_format($val, 1)) . ' ' . $units[$i];
}

function __stats_mb(int $bytes): string {
    if ($bytes <= 0) return '0.0 MB';
    return number_format($bytes / (1024 ** 2), 1) . ' MB';
}
function __stats_gb(int $bytes): string {
    if ($bytes <= 0) return '0.00 GB';
    return number_format($bytes / (1024 ** 3), 2) . ' GB';
}
function __stats_n(int $n): string { return number_format($n); }
function __stats_bps(int $bps): string {
    if ($bps < 1024)       return $bps . ' B/s';
    if ($bps < 1048576)    return number_format($bps / 1024, 1) . ' KB/s';
    return number_format($bps / 1048576, 1) . ' MB/s';
}

$__db_stats = null;
try {
    $__dsn = sprintf(
        'mysql:host=%s;dbname=%s;charset=utf8mb4',
        getenv('DB_HOST') ?: 'localhost',
        getenv('MYSQL_DATABASE') ?: 'media_db'
    );
    $__spdo = new PDO($__dsn, getenv('MYSQL_USER') ?: 'appuser', getenv('MYSQL_PASSWORD') ?: '', [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    $__dbName  = getenv('MYSQL_DATABASE') ?: 'media_db';
    $__ver     = (string) $__spdo->query('SELECT VERSION()')->fetchColumn();
    $__szStmt  = $__spdo->prepare(
        'SELECT COALESCE(SUM(data_length + index_length), 0) FROM information_schema.tables WHERE table_schema = ?'
    );
    $__szStmt->execute([$__dbName]);
    $__dbBytes = (int) $__szStmt->fetchColumn();
    $__counts  = [];
    foreach (['events', 'assets', 'event_items', 'participants', 'tags', 'taggings'] as $__tbl) {
        try {
            $__counts[$__tbl] = (int) $__spdo->query("SELECT COUNT(*) FROM `{$__tbl}`")->fetchColumn();
        } catch (Throwable $__ignored) {
            $__counts[$__tbl] = 0;
        }
    }
    $__db_stats = ['version' => $__ver, 'db_name' => $__dbName, 'size' => $__dbBytes, 'counts' => $__counts];
    unset($__spdo, $__dsn, $__szStmt, $__tbl, $__ignored, $__ver, $__dbName, $__dbBytes, $__counts);
} catch (Throwable $__ignored) { unset($__ignored); }

$__media_stats = null;
$__media_disk = null;
$__mdirsEnv = getenv('MEDIA_SEARCH_DIRS');
if (is_string($__mdirsEnv) && $__mdirsEnv !== '') {
    $__mdirs  = array_filter(array_map('trim', explode(':', $__mdirsEnv)));
    $__audioD = null;
    $__videoD = null;
    foreach ($__mdirs as $__d) {
        $__d = rtrim($__d, '/');
        if ($__audioD === null && str_ends_with($__d, '/audio'))     { $__audioD = $__d; }
        elseif ($__videoD === null && str_ends_with($__d, '/video')) { $__videoD = $__d; }
    }
    $__mscan = [
        'audio'      => $__audioD,
        'video'      => $__videoD,
        'thumbnails' => $__videoD !== null ? $__videoD . '/thumbnails' : null,
    ];
    $__media_stats = [];
    foreach ($__mscan as $__mlbl => $__mpath) {
        $__mc = 0; $__mb = 0;
        if ($__mpath !== null && is_dir($__mpath) && is_readable($__mpath)) {
            foreach (glob($__mpath . '/*') ?: [] as $__mf) {
                if (is_file($__mf)) { $__mc++; $__mb += (int) (@filesize($__mf) ?: 0); }
            }
        }
        $__media_stats[$__mlbl] = ['count' => $__mc, 'bytes' => $__mb];
    }
    $__media_stats['total'] = [
        'count' => array_sum(array_column($__media_stats, 'count')),
        'bytes' => array_sum(array_column($__media_stats, 'bytes')),
    ];
    $__disk_paths = array_filter([$__audioD, $__videoD], fn($p) => $p !== null && is_dir($p));
    if (!empty($__disk_paths)) {
        $__disk_seen = [];
        foreach ($__disk_paths as $__dp) {
            $__st    = @stat($__dp);
            $__dev   = ($__st !== false && isset($__st['dev'])) ? (int)$__st['dev'] : null;
            $__dfree = @disk_free_space($__dp);
            $__dtot  = @disk_total_space($__dp);
            if ($__dfree === false || $__dtot === false || (int)$__dtot <= 0) continue;
            $__dkey = $__dev !== null ? $__dev : $__dp;
            if (!isset($__disk_seen[$__dkey])) {
                $__disk_seen[$__dkey] = ['free' => (int)$__dfree, 'total' => (int)$__dtot];
            }
        }
        if (!empty($__disk_seen)) {
            $__media_disk = array_values($__disk_seen);
        }
        unset($__disk_paths, $__dp, $__st, $__dev, $__dfree, $__dtot, $__dkey, $__disk_seen);
    } else {
        unset($__disk_paths);
    }
    unset($__mdirs, $__d, $__audioD, $__videoD, $__mscan, $__mlbl, $__mpath, $__mc, $__mb, $__mf);
}
unset($__mdirsEnv);

$__os_mem = null;
$__meminfo_raw = @file_get_contents('/proc/meminfo');
if (is_string($__meminfo_raw)) {
    $__mvals = [];
    foreach (explode("\n", $__meminfo_raw) as $__mline) {
        if (preg_match('/^(\w+):\s+(\d+)\s+kB/', $__mline, $__mm)) {
            $__mvals[$__mm[1]] = (int)$__mm[2] * 1024;
        }
    }
    if (isset($__mvals['MemTotal'], $__mvals['MemAvailable'])) {
        $__os_mem = ['total' => $__mvals['MemTotal'], 'avail' => $__mvals['MemAvailable']];
    }
    unset($__meminfo_raw, $__mvals, $__mline, $__mm);
}

$__os_net = null;
$__net_raw1 = @file_get_contents('/proc/net/dev');
if (is_string($__net_raw1)) {
    sleep(1);
    $__net_raw2 = @file_get_contents('/proc/net/dev');
    if (is_string($__net_raw2)) {
        $__net_parse = function(string $raw): array {
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
        $__nd1 = $__net_parse($__net_raw1);
        $__nd2 = $__net_parse($__net_raw2);
        $__os_net = [];
        foreach ($__nd1 as $__niface => $__nv1) {
            if (!isset($__nd2[$__niface])) continue;
            $__os_net[] = [
                'iface'  => $__niface,
                'rx_bps' => max(0, $__nd2[$__niface]['rx'] - $__nv1['rx']),
                'tx_bps' => max(0, $__nd2[$__niface]['tx'] - $__nv1['tx']),
            ];
        }
        if (empty($__os_net)) $__os_net = null;
        unset($__net_raw2, $__net_parse, $__nd1, $__nd2, $__niface, $__nv1);
    }
    unset($__net_raw1);
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>Admin: System & Recovery</title>
  <style>
    :root { font-family: system-ui, -apple-system, Segoe UI, Roboto, sans-serif; }
    body { margin:0; background:#0b1020; color:#e9eef7; }
    .wrap { max-width: 880px; margin: 3rem auto; padding: 1rem; }
    .card { background:#121a33; border:1px solid #1d2a55; border-radius:16px; padding:1.5rem; position:relative; }
    .row { display:grid; gap:.5rem; margin-bottom:1rem; }
    label { font-weight:600; }
    input[type=text], input[type=number], select { width:100%; padding:.7rem; border-radius:10px; border:1px solid #33427a; background:#0e1530; color:#e9eef7; }
    button { padding:.8rem 1.1rem; border-radius:10px; border:1px solid #3b82f6; background:transparent; color:#e9eef7; cursor:pointer; }
    button:hover { background:#1e40af; color:#fff; }
    button.danger { border-color:#dc2626; }
    button.danger:hover { background:#991b1b; }
    button:disabled { cursor:not-allowed; opacity:1; }
    button.danger:disabled { border-color:#dc2626; color:#a8b3cf; background:transparent; }
    .section-divider { border-top:2px solid #1d2a55; margin:2rem 0; padding-top:2rem; }
    .warning-box { background:#3b1f0d; border:1px solid #b45309; padding:1rem; border-radius:10px; margin-bottom:1rem; }
    .alert-ok { background:#11331a; border:1px solid #1f7a3b; padding:.8rem 1rem; border-radius:10px; margin-bottom:1rem; }
    .alert-err { background:#3b0d14; border:1px solid #b4232a; padding:.8rem 1rem; border-radius:10px; margin-bottom:1rem; }
    .muted { color:#a8b3cf; font-size:.95rem; }
    .stats-block { position:relative; font-size:.85rem; color:#a8b3cf; padding-bottom:.25rem; }
    .stats-grid { display:grid; grid-template-columns:5.5rem 9rem 8rem 12rem; row-gap:.25rem; align-items:start; }
    .sg-lbl { font-weight:600; color:#e9eef7; }
    .sg-sub { display:block; font-size:.75rem; font-weight:normal; color:#a8b3cf; }
    .sg-span { grid-column:2/-1; }
    .stats-grid .snum { text-align:right; font-variant-numeric:tabular-nums; }
    .sg-sep { grid-column:2/-1; border:none; border-top:1px solid #1d2a55; margin:.1rem 0; height:0; padding:0; }
    .stats-grid > span:not(.sg-lbl) { font-size:.75rem; }
  </style>
  <link rel="stylesheet" href="/admin/assets/import_progress.css" />
  <script src="/admin/assets/import_progress.js"></script>
</head>
<body>
  <div class="wrap">
    <div class="card">
      <div style="position:absolute;top:1.5rem;right:1.5rem;display:flex;flex-direction:column;gap:.4rem;align-items:flex-end">
        <a href="/admin/admin.php"><button type="button" style="border-color:#3b82f6;font-size:.8rem;padding:.4rem .8rem">Password Reset</button></a>
        <a href="/admin/admin_database_catalog_media_from_folder.php"><button type="button" style="border-color:#a855f7;font-size:.8rem;padding:.4rem .8rem">Catalog Media</button></a>
        <a href="/admin/admin_database_load_import_media_from_folder.php"><button type="button" style="border-color:#3b82f6;font-size:.8rem;padding:.4rem .8rem">Import Media</button></a>
        <a href="/admin/admin_database_load_import_csv.php"><button type="button" style="border-color:#3b82f6;font-size:.8rem;padding:.4rem .8rem">Import CSV</button></a>
        <a href="/admin/event_qr.php"><button type="button" style="border-color:#22c55e;font-size:.8rem;padding:.4rem .8rem">Guest QR Upload</button></a>
      </div>
      <h1 style="padding-right:210px">Admin: System & Recovery</h1>
      <p class="muted">Signed in as <code><?= htmlspecialchars($user) ?></code>.</p>

      <div class="section-divider stats-block">
        <div class="stats-grid">

          <span class="sg-lbl">DB</span>
          <div class="sg-span" id="stat-db"><?php if ($__db_stats !== null): ?>MySQL <?= htmlspecialchars($__db_stats['version']) ?> &nbsp;&middot;&nbsp; <?= htmlspecialchars($__db_stats['db_name']) ?> &nbsp;&middot;&nbsp; <?= htmlspecialchars(__stats_mb($__db_stats['size'])) ?> on disk<br><?php
            $__cp1 = []; $__cp2 = [];
            foreach ($__db_stats['counts'] as $__ct => $__cn) {
                $__item = '<strong>' . __stats_n($__cn) . '</strong> ' . htmlspecialchars(str_replace('_', ' ', $__ct));
                if ($__ct === 'tags' || $__ct === 'taggings') { $__cp2[] = $__item; } else { $__cp1[] = $__item; }
            }
            echo implode(' &nbsp;&middot;&nbsp; ', $__cp1);
            if (!empty($__cp2)) { echo '<br>' . implode(' &nbsp;&middot;&nbsp; ', $__cp2); }
            unset($__cp1, $__cp2, $__ct, $__cn, $__item);
          ?><?php else: ?><span style="font-style:italic">unavailable</span><?php endif; ?></div>

          <div style="grid-column:1/-1;height:.5rem"></div>

          <?php if ($__media_stats !== null): ?>
            <span class="sg-lbl">Media</span><span>audio</span><span class="snum" data-stat="m-audio-count"><?= __stats_n($__media_stats['audio']['count']) ?> files</span><span class="snum" data-stat="m-audio-bytes"><?= __stats_gb($__media_stats['audio']['bytes']) ?></span>
            <span></span><span>video</span><span class="snum" data-stat="m-video-count"><?= __stats_n($__media_stats['video']['count']) ?> files</span><span class="snum" data-stat="m-video-bytes"><?= __stats_gb($__media_stats['video']['bytes']) ?></span>
            <span></span><span>thumbnails</span><span class="snum" data-stat="m-thumb-count"><?= __stats_n($__media_stats['thumbnails']['count']) ?> files</span><span class="snum" data-stat="m-thumb-bytes"><?= __stats_gb($__media_stats['thumbnails']['bytes']) ?></span>
            <span></span><hr class="sg-sep">
            <span></span><span>total</span><span class="snum" data-stat="m-total-count"><?= __stats_n($__media_stats['total']['count']) ?> files</span><span class="snum" data-stat="m-total-bytes"><?= __stats_gb($__media_stats['total']['bytes']) ?></span>
          <?php else: ?>
            <span class="sg-lbl">Media</span><span class="sg-span" style="font-style:italic">unavailable</span>
          <?php endif; ?>

          <div style="grid-column:1/-1;height:.5rem"></div>

          <?php
            $__os_net_any  = !empty($__os_net);
            $__os_host_any = !empty($__media_disk) || $__os_mem !== null;
            $__os_any      = $__os_net_any || $__os_host_any;
          ?>
          <?php if ($__os_any): ?>
            <span class="sg-lbl">OS</span><span class="sg-span"></span>
            <span class="sg-sub">docker host</span><span class="sg-span"></span>
            <?php if ($__os_host_any): ?>
              <?php if (!empty($__media_disk)): ?>
                <?php foreach ($__media_disk as $__disk): ?>
                  <span></span><span>disk free</span><span class="snum" data-stat="disk-0-free"><?= __stats_gb($__disk['free']) ?></span><span class="snum" data-stat="disk-0-total">of <?= __stats_gb($__disk['total']) ?></span>
                <?php endforeach; unset($__disk); ?>
              <?php endif; ?>
              <?php if ($__os_mem !== null): ?>
                <?php if (!empty($__media_disk)): ?><span></span><hr class="sg-sep"><?php endif; ?>
                <span></span><span>mem free</span><span class="snum" data-stat="mem-avail"><?= __stats_gb($__os_mem['avail']) ?></span><span class="snum" data-stat="mem-total">of <?= __stats_gb($__os_mem['total']) ?></span>
              <?php endif; ?>
            <?php else: ?>
              <span></span><span class="sg-span" style="font-style:italic">unavailable</span>
            <?php endif; ?>
            <div style="grid-column:1/-1;height:.35rem"></div>
            <span class="sg-sub"><?= $__apache_container_name !== '' ? htmlspecialchars($__apache_container_name) : 'apacheWebServer' ?></span><span class="sg-span"></span>
            <?php if ($__os_net_any): ?>
              <?php foreach ($__os_net as $__net_idx => $__net): ?>
                <span></span><span><?= htmlspecialchars($__net['iface']) ?></span><span class="snum" data-stat="net-<?= $__net_idx ?>-rx"><?= __stats_bps($__net['rx_bps']) ?> rx</span><span class="snum" data-stat="net-<?= $__net_idx ?>-tx"><?= __stats_bps($__net['tx_bps']) ?> tx</span>
              <?php endforeach; unset($__net, $__net_idx); ?>
            <?php else: ?>
              <span></span><span class="sg-span" style="font-style:italic">unavailable</span>
            <?php endif; ?>
          <?php else: ?>
            <span class="sg-lbl">OS</span><span class="sg-span" style="font-style:italic">unavailable</span>
          <?php endif; ?>
          <?php unset($__os_any, $__os_net_any, $__os_host_any); ?>
          <div style="grid-column:1/-1;height:.35rem"></div>
          <div style="grid-column:1/-1;display:flex;align-items:center;gap:.6rem">
            <button id="live-btn" type="button" onclick="toggleLive()" style="font-size:.7rem;padding:.2rem .55rem;border-color:#ef4444">Live</button>
            <span id="live-countdown" style="font-size:.7rem;color:#a8b3cf"></span>
          </div>

        </div>
      </div>

      <div class="section-divider">
        <h2>Section A: Clear Database</h2>
        <p class="muted">
          Remove all content (events, assets, event items, participants, etc.) from the database.
          This action is <strong>irreversible</strong> and will clear all media tables.
          This will not, however, clear the media files from the disk. That is done via Section D: Delete All Media Files from Disk.
        </p>
        <div class="warning-box">
          <strong>⚠️ Warning:</strong> This will permanently delete all media data from the database.
          The users table will be preserved.
        </div>
        <div id="clearMediaStatus"></div>
        <button type="button" id="clearMediaBtn" class="danger" onclick="confirmClearMedia()">Clear All Media Data</button>
      </div>

      <div class="section-divider">
        <h2>Section B: Restore Database From Backup (Destructive)</h2>
        <p class="muted">
          A full database backup is created daily by the server. Use this section to restore the entire database if something goes wrong.
          Note that the backup only applies to the database in the container.  The backup does not backup the media files stored on the filesystem.
        </p>
        <div class="warning-box">
          <strong>⚠️ Warning:</strong> This will overwrite the current database with the selected backup.
        </div>

        <div class="row">
          <label for="restore_backup_file">Select a backup</label>
          <select id="restore_backup_file" name="restore_backup_file">
            <?php if (!count($__restore_backup_files)): ?>
              <option value="" selected disabled>No backups yet created</option>
            <?php else: ?>
              <?php foreach ($__restore_backup_files as $__b): ?>
                <option value="<?= htmlspecialchars((string)$__b['name']) ?>">
                  <?= htmlspecialchars((string)$__b['name']) ?> (<?= htmlspecialchars(__format_backup_size((int)($__b['size'] ?? 0))) ?>)
                </option>
              <?php endforeach; ?>
            <?php endif; ?>
          </select>
        </div>

        <div class="row">
          <label for="restore_confirm">Type <code>RESTORE</code> to confirm</label>
          <input type="text" id="restore_confirm" name="restore_confirm" value="" />
        </div>

        <div id="restoreDbStatus"></div>
        <div id="restoreDbLog" style="display:none;margin-top:.75rem;background:#0e1530;border:1px solid #33427a;border-radius:10px;padding:.75rem;max-height:280px;overflow:auto;white-space:pre-wrap;font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,'Liberation Mono','Courier New',monospace;font-size:.85rem;"></div>

        <button type="button" id="restoreDbBtn" class="danger" onclick="confirmRestoreDatabase()" <?php if (!count($__restore_backup_files)): ?>disabled<?php endif; ?>>Restore Database</button>
      </div>

      <div class="section-divider">
        <h2>Section C: Create Backup Now</h2>
        <p class="muted">
          Create an immediate <code>mysqldump | gzip</code> backup of the database. The backup is written to
          the configured backups directory and the <code>_latest.sql.gz</code> symlink is updated.
          Use this before running a restore test on a fresh install where the daily cron has not yet run.
        </p>
        <div class="warning-box">
          <strong>⚠️ Warning:</strong> This will overwrite the <code>_latest.sql.gz</code> symlink to point to the new backup.
        </div>
        <div id="createBackupStatus"></div>
        <div id="createBackupLog" style="display:none;margin-top:.75rem;background:#0e1530;border:1px solid #33427a;border-radius:10px;padding:.75rem;max-height:280px;overflow:auto;white-space:pre-wrap;font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,'Liberation Mono','Courier New',monospace;font-size:.85rem;"></div>
        <button type="button" id="createBackupBtn" onclick="doCreateBackup()">Create Backup Now</button>
      </div>

      <div class="section-divider">
        <h2>Section D: Delete All Media Files from Disk</h2>
        <p class="muted">
          Permanently deletes all audio, video, and thumbnail files stored on the server.
          The database is <strong>not</strong> affected — run Section A first if you also want to clear database records.
        </p>
        <div class="warning-box">
          <strong>⚠️ Warning:</strong> This deletes actual files from disk. Unlike the database,
          files cannot be recovered from a database backup. Ensure <strong>no uploads are currently in progress</strong>
          before proceeding.
        </div>
        <div id="clearMediaFilesStatus"></div>
        <button type="button" id="clearMediaFilesBtn" class="danger" onclick="confirmClearMediaFiles()">Delete All Media Files</button>
      </div>

      <div class="section-divider">
        <h2>Section E: Export Media to ZIP</h2>
        <p class="muted">
          Download a ZIP of media files currently on disk, filtered by band/event name and/or file type.
          Use this to preserve custom files (e.g. tutorial videos) before a database reset, then
          re-import via <a href="/admin/admin_database_load_import_media_from_folder.php" style="color:#60a5fa">Import Media (folder)</a> after rebuilding.
          This tool is designed for full-corpus backup and restore of small-to-medium libraries (guideline: under 20 GB).
          Always create a database backup (Section C) at the same time &mdash; the ZIP and DB backup form a matched restore pair.
          For libraries larger than 20 GB, rsync or direct volume backup is recommended.
        </p>
        <div class="row">
          <label for="export_org_name">Band / Event filter <span class="muted">(leave blank to export all media)</span></label>
          <input type="text" id="export_org_name" name="org_name" placeholder="e.g. tutorial" />
        </div>
        <div class="row">
          <label for="export_file_type">File type</label>
          <select id="export_file_type" name="file_type">
            <option value="all">All (audio + video)</option>
            <option value="audio">Audio only</option>
            <option value="video">Video only</option>
          </select>
        </div>
        <div id="exportMediaStatus"></div>
        <button type="button" id="exportMediaBtn" onclick="doExportMedia()">Download ZIP</button>
      </div>

      <div class="section-divider">
        <h2>Section F: Import Media from ZIP</h2>
        <p class="muted">
          Import audio and video files from a GigHive export ZIP back onto this server's media volumes.
          Only files with SHA-256 hash names and supported extensions are imported; all others are skipped safely.
          Files already present on disk are skipped (idempotent &mdash; safe to re-run).
        </p>
        <div class="warning-box">
          <strong>&#9888;&#65039; Note:</strong> This only restores the media files. A matching database backup
          (Section C) must also be restored separately to reconstruct the full catalogue.
        </div>
        <div class="row">
          <label for="import_zip_file">ZIP file</label>
          <input type="file" id="import_zip_file" name="zip_file" accept=".zip" />
        </div>
        <div id="importZipStatus"></div>
        <button type="button" id="importZipBtn" onclick="doImportMediaZip()">Import ZIP</button>
      </div>

      <?php if ($__show_disk_resize): ?>
      <div class="section-divider">
        <h2>Section G: Write Disk Resize Request (Optional)</h2>
        <p class="muted">
          This creates a resize request file on the server. It does not resize the VM immediately. <a href="https://gighive.app/resizeRequestInstructions.html" target="_blank" rel="noopener noreferrer">Instructions here</a>
        </p>
        <div class="warning-box">
          <strong>⚠️ Warning:</strong> Gighive builds a VM with a default virtual disk size of 64GB.  This command with provide a method to increase the size of the disk.  You will first request a disk resize operation. Then you will run an Ansible script to enlarge the disk. Note that the resize only grows, it DOES NOT SHRINK the virtual disk.
        </div>
        <div class="row">
          <label for="resize_inventory_host">Inventory host</label>
          <input type="text" id="resize_inventory_host" name="resize_inventory_host" value="gighive_vm" />
        </div>
        <div class="row">
          <label for="resize_disk_size_gib">Target disk size (GiB)</label>
          <input type="number" id="resize_disk_size_gib" name="resize_disk_size_gib" min="16" step="1" value="256" />
        </div>
        <div id="resizeRequestStatus"></div>
        <button type="button" id="writeResizeRequestBtn" class="danger" onclick="confirmWriteResizeRequest()">Write Resize Request</button>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <script>
  function confirmWriteResizeRequest() {
    const hostEl = document.getElementById('resize_inventory_host');
    const sizeEl = document.getElementById('resize_disk_size_gib');
    const btn = document.getElementById('writeResizeRequestBtn');
    const status = document.getElementById('resizeRequestStatus');

    const inventoryHost = (hostEl && hostEl.value) ? String(hostEl.value).trim() : '';
    const diskSizeGiB = Number(sizeEl && sizeEl.value ? sizeEl.value : 0);

    if (!inventoryHost) {
      status.innerHTML = '<div class="alert-err">Please enter an inventory host.</div>';
      return;
    }
    if (!Number.isFinite(diskSizeGiB) || diskSizeGiB < 16) {
      status.innerHTML = '<div class="alert-err">Please enter a valid target size (GiB).</div>';
      return;
    }

    const diskSizeMb = Math.floor(diskSizeGiB * 1024);

    if (!confirm('Write disk resize request?\n\nHost: ' + inventoryHost + '\nTarget size: ' + diskSizeGiB + ' GiB (' + diskSizeMb + ' MB)\n\nThis does NOT resize immediately.')) {
      return;
    }

    btn.disabled = true;
    btn.textContent = 'Writing…';
    status.innerHTML = '<div class="muted">Processing request...</div>';

    fetch('write_resize_request.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        inventory_host: inventoryHost,
        disk_size_mb: diskSizeMb
      })
    })
    .then(async response => {
      const data = await response.json().catch(() => null);
      return { ok: response.ok, status: response.status, data };
    })
    .then(({ ok, data }) => {
      if (ok && data && data.success) {
        const msg = (data.message || 'Resize request written successfully.');
        const file = (data.request_file || '');
        const cmd = (data.run_command || '');
        const esc = s => String(s).replace(/[&<>]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;'}[c]));
        let html = '<div class="alert-ok">' + esc(msg);
        if (file) html += '<div class="muted" style="margin-top:.25rem">Request file: ' + esc(file) + '</div>';
        if (cmd) {
          html += '<div style="margin-top:.75rem"><div class="muted" style="margin-bottom:.35rem">cd into the gighive directory and run this on your VirtualBox host:</div>'
            + '<pre id="resizeCmdPre" style="margin:0;background:#0e1530;border:1px solid #33427a;border-radius:8px;padding:.6rem .8rem;white-space:pre-wrap;word-break:break-all;font-size:.82rem;color:#cfd8ee">' + esc(cmd) + '</pre>'
            + '<div class="muted" style="margin-top:.4rem;font-size:.82rem">It is recommended that you do a dry run first to check that syntax is correct. To do this, append <code>--dry-run</code> to the command in the above window.</div>'
            + '<button type="button" id="resizeCopyBtn" style="margin-top:.4rem;font-size:.82rem;padding:.3rem .8rem;border-color:#6b7280" onclick="(function(){var t=document.getElementById(\'resizeCmdPre\').textContent;var b=document.getElementById(\'resizeCopyBtn\');function done(){b.textContent=\'Copied!\';setTimeout(function(){b.textContent=\'Copy Command\'},1500);}if(navigator.clipboard&&navigator.clipboard.writeText){navigator.clipboard.writeText(t).then(done).catch(function(){var ta=document.createElement(\'textarea\');ta.value=t;document.body.appendChild(ta);ta.select();document.execCommand(\'copy\');document.body.removeChild(ta);done();});}else{var ta=document.createElement(\'textarea\');ta.value=t;document.body.appendChild(ta);ta.select();document.execCommand(\'copy\');document.body.removeChild(ta);done();}})()">Copy Command</button>'
            + '</div>';
        }
        html += '</div>';
        status.innerHTML = html;
        btn.textContent = 'Write Resize Request';
        btn.disabled = false;
      } else {
        const msg = (data && (data.message || data.error)) ? (data.message || data.error) : 'Unknown error occurred';
        status.innerHTML = '<div class="alert-err">Error: ' + msg + '</div>';
        btn.textContent = 'Write Resize Request';
        btn.disabled = false;
      }
    })
    .catch(error => {
      status.innerHTML = '<div class="alert-err">Network error: ' + error.message + '</div>';
      btn.textContent = 'Write Resize Request';
      btn.disabled = false;
    });
  }

  function confirmClearMedia() {
    if (!confirm('Are you sure you want to clear ALL media data?\n\nThis will permanently delete:\n- All events\n- All assets\n- All event items\n- All participants\n\nThis action CANNOT be undone!')) {
      return;
    }

    const btn = document.getElementById('clearMediaBtn');
    const status = document.getElementById('clearMediaStatus');

    btn.disabled = true;
    btn.textContent = 'Clearing...';
    status.innerHTML = '<div class="muted">Processing request...</div>';

    fetch('clear_media.php', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json'
      }
    })
    .then(response => response.json())
    .then(data => {
      if (data.success) {
        status.innerHTML = renderOkBannerWithDbLink((data.message || 'Media tables cleared successfully!'), 'See Cleared Database');
        btn.textContent = 'Cleared Successfully';
        btn.style.background = '#28a745';
      } else {
        status.innerHTML = '<div class="alert-err">Error: ' + (data.message || 'Unknown error occurred') + '</div>';
        btn.disabled = false;
        btn.textContent = 'Clear All Media Data';
      }
    })
    .catch(error => {
      status.innerHTML = '<div class="alert-err">Network error: ' + error.message + '</div>';
      btn.disabled = false;
      btn.textContent = 'Clear All Media Data';
    });
  }

  function confirmClearMediaFiles() {
    if (!confirm('Are you sure you want to DELETE ALL MEDIA FILES from disk?\n\nThis will permanently delete:\n- All audio files\n- All video files\n- All thumbnail files\n\nThe database will NOT be affected.\n\nThis action CANNOT be undone and files CANNOT be recovered from a database backup!')) {
      return;
    }
    if (!confirm('Second confirmation required.\n\nEnsure no uploads are currently in progress.\n\nProceed with deleting all media files from disk?')) {
      return;
    }

    const btn = document.getElementById('clearMediaFilesBtn');
    const status = document.getElementById('clearMediaFilesStatus');

    btn.disabled = true;
    btn.textContent = 'Deleting...';
    status.innerHTML = '<div class="muted">Processing request...</div>';

    fetch('clear_media_files.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' }
    })
    .then(response => response.json())
    .then(data => {
      if (data.success) {
        const audio = Number(data.audio_files_deleted) || 0;
        const video = Number(data.video_files_deleted) || 0;
        const thumbs = Number(data.thumbnails_files_deleted) || 0;
        const total = Number(data.total_deleted) || 0;
        status.innerHTML = '<div class="alert-ok">Deleted ' + total + ' file(s) from disk.'
          + ' (audio: ' + audio + ', video: ' + video + ', thumbnails: ' + thumbs + ')</div>';
        btn.textContent = 'Deleted Successfully';
        btn.style.background = '#28a745';
      } else {
        const errs = Array.isArray(data.errors) && data.errors.length ? '<br>' + data.errors.join('<br>') : '';
        status.innerHTML = '<div class="alert-err">Error: ' + (data.message || 'Unknown error occurred') + errs + '</div>';
        btn.disabled = false;
        btn.textContent = 'Delete All Media Files';
      }
    })
    .catch(error => {
      status.innerHTML = '<div class="alert-err">Network error: ' + error.message + '</div>';
      btn.disabled = false;
      btn.textContent = 'Delete All Media Files';
    });
  }

  let __restorePollTimer = null;
  let __restorePollTick = null;

  let __backupPollTimer = null;

  function __fmtBytes(n) {
    if (n < 1024)       return n + ' B';
    if (n < 1048576)    return (n / 1024).toFixed(1) + ' KB';
    if (n < 1073741824) return (n / 1048576).toFixed(1) + ' MB';
    return (n / 1073741824).toFixed(1) + ' GB';
  }

  function doCreateBackup() {
    if (!confirm('Create a new database backup now?\n\nThis will overwrite the _latest.sql.gz symlink.')) {
      return;
    }

    const btn    = document.getElementById('createBackupBtn');
    const status = document.getElementById('createBackupStatus');
    const logEl  = document.getElementById('createBackupLog');

    btn.disabled = true;
    btn.textContent = 'Starting backup…';
    status.innerHTML = '<div class="muted">Starting backup job...</div>';
    logEl.style.display = 'block';
    logEl.textContent = '';

    if (__backupPollTimer) {
      clearInterval(__backupPollTimer);
      __backupPollTimer = null;
    }

    fetch('/db/run_backup.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({})
    })
    .then(async response => {
      const data = await response.json().catch(() => null);
      return { ok: response.ok, data };
    })
    .then(({ ok, data }) => {
      if (!(ok && data && data.success && data.job_id)) {
        const msg = (data && (data.message || data.error)) ? (data.message || data.error) : 'Unknown error occurred';
        status.innerHTML = '<div class="alert-err">Error: ' + String(msg).replace(/[&<>]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;'}[c])) + '</div>';
        btn.disabled = false;
        btn.textContent = 'Create Backup Now';
        return;
      }

      const jobId = String(data.job_id);
      status.innerHTML = '<div class="muted">Backup started. Job: <code>' + jobId.replace(/[&<>]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;'}[c])) + '</code></div>';
      btn.textContent = 'Backup Running…';
      pollBackupLog(jobId);
    })
    .catch(error => {
      status.innerHTML = '<div class="alert-err">Network error: ' + error.message + '</div>';
      btn.disabled = false;
      btn.textContent = 'Create Backup Now';
    });
  }

  function pollBackupLog(jobId) {
    const btn    = document.getElementById('createBackupBtn');
    const status = document.getElementById('createBackupStatus');
    const logEl  = document.getElementById('createBackupLog');

    let offset = 0;

    const tick = () => {
      fetch('/db/run_backup_status.php?job_id=' + encodeURIComponent(jobId) + '&offset=' + String(offset), {
        method: 'GET'
      })
      .then(async response => {
        const data = await response.json().catch(() => null);
        return { ok: response.ok, data };
      })
      .then(({ ok, data }) => {
        if (!(ok && data && data.success)) {
          const msg = (data && (data.message || data.error)) ? (data.message || data.error) : 'Unknown error occurred';
          status.innerHTML = '<div class="alert-err">Error reading backup status: ' + String(msg).replace(/[&<>]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;'}[c])) + '</div>';
          if (__backupPollTimer) {
            clearInterval(__backupPollTimer);
            __backupPollTimer = null;
          }
          btn.disabled = false;
          btn.textContent = 'Create Backup Now';
          return;
        }

        if (typeof data.log_chunk === 'string' && data.log_chunk.length) {
          logEl.textContent += data.log_chunk;
          logEl.scrollTop = logEl.scrollHeight;
        }
        if (typeof data.offset === 'number') {
          offset = data.offset;
        }

        const st = String(data.state || 'running');
        if (st === 'ok') {
          if (__backupPollTimer) {
            clearInterval(__backupPollTimer);
            __backupPollTimer = null;
          }
          status.innerHTML = '<div class="alert-ok">Backup completed successfully.</div>';
          btn.textContent = 'Backup Created!';
          btn.style.background = '#28a745';
          btn.style.borderColor = '#28a745';
          btn.style.color = 'white';

          if (data.filename) {
            const selectEl = document.getElementById('restore_backup_file');
            if (selectEl) {
              const placeholder = selectEl.querySelector('option[disabled]');
              if (placeholder) {
                placeholder.remove();
              }
              const newOpt = document.createElement('option');
              newOpt.value = String(data.filename);
              const sizeLabel = (typeof data.size_bytes === 'number') ? ' (' + __fmtBytes(data.size_bytes) + ')' : '';
              newOpt.textContent = String(data.filename) + sizeLabel;
              selectEl.insertBefore(newOpt, selectEl.firstChild);
            }
          }

          const restoreBtn = document.getElementById('restoreDbBtn');
          if (restoreBtn) {
            restoreBtn.removeAttribute('disabled');
          }
        } else if (st === 'error') {
          if (__backupPollTimer) {
            clearInterval(__backupPollTimer);
            __backupPollTimer = null;
          }
          const code = (data.exit_code !== null && data.exit_code !== undefined) ? String(data.exit_code) : '?';
          status.innerHTML = '<div class="alert-err">Backup failed. Exit code: ' + code.replace(/[&<>]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;'}[c])) + '</div>';
          btn.disabled = false;
          btn.textContent = 'Create Backup Now';
        }
      })
      .catch(err => {
        status.innerHTML = '<div class="alert-err">Network error: ' + err.message + '</div>';
        if (__backupPollTimer) {
          clearInterval(__backupPollTimer);
          __backupPollTimer = null;
        }
        btn.disabled = false;
        btn.textContent = 'Create Backup Now';
      });
    };

    tick();
    __backupPollTimer = setInterval(tick, 1500);
  }

  function confirmRestoreDatabase() {
    const sel = document.getElementById('restore_backup_file');
    const confirmEl = document.getElementById('restore_confirm');
    const btn = document.getElementById('restoreDbBtn');
    const status = document.getElementById('restoreDbStatus');
    const logEl = document.getElementById('restoreDbLog');

    const filename = sel && sel.value ? String(sel.value).trim() : '';
    const confirmText = confirmEl && typeof confirmEl.value === 'string' ? String(confirmEl.value).trim() : '';

    if (!filename) {
      status.innerHTML = '<div class="alert-err">Please select a backup file.</div>';
      return;
    }

    if (confirmText !== 'RESTORE') {
      status.innerHTML = '<div class="alert-err">Please type RESTORE to confirm.</div>';
      return;
    }

    if (!confirm('Restore the database from:\n\n' + filename + '\n\nThis will OVERWRITE the current database. Continue?')) {
      return;
    }

    if (__restorePollTimer) {
      clearInterval(__restorePollTimer);
      __restorePollTimer = null;
    }

    btn.disabled = true;
    btn.style.background = '';
    btn.style.borderColor = '';
    btn.style.color = '';
    btn.textContent = 'Starting restore…';
    status.innerHTML = '<div class="muted">Starting restore job...</div>';
    logEl.style.display = 'block';
    logEl.textContent = '';

    fetch('/db/restore_database.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ filename, confirm: confirmText })
    })
    .then(async response => {
      const data = await response.json().catch(() => null);
      return { ok: response.ok, data };
    })
    .then(({ ok, data }) => {
      if (!(ok && data && data.success && data.job_id)) {
        const msg = (data && (data.message || data.error)) ? (data.message || data.error) : 'Unknown error occurred';
        status.innerHTML = '<div class="alert-err">Error: ' + String(msg).replace(/[&<>]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;'}[c])) + '</div>';
        btn.disabled = false;
        btn.style.background = '';
        btn.style.borderColor = '';
        btn.style.color = '';
        btn.textContent = 'Restore Database';
        return;
      }

      const jobId = String(data.job_id);
      status.innerHTML = '<div class="muted">Restore started. Job: <code>' + jobId.replace(/[&<>]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;'}[c])) + '</code></div>';
      btn.textContent = 'Restore Running…';
      pollRestoreLog(jobId);
    })
    .catch(error => {
      status.innerHTML = '<div class="alert-err">Network error: ' + error.message + '</div>';
      btn.disabled = false;
      btn.style.background = '';
      btn.style.borderColor = '';
      btn.style.color = '';
      btn.textContent = 'Restore Database';
    });
  }

  function pollRestoreLog(jobId) {
    const btn = document.getElementById('restoreDbBtn');
    const status = document.getElementById('restoreDbStatus');
    const logEl = document.getElementById('restoreDbLog');

    let offset = 0;

    const tick = () => {
      fetch('/db/restore_database_status.php?job_id=' + encodeURIComponent(jobId) + '&offset=' + String(offset), {
        method: 'GET'
      })
      .then(async response => {
        const data = await response.json().catch(() => null);
        return { ok: response.ok, data };
      })
      .then(({ ok, data }) => {
        if (!(ok && data && data.success)) {
          const msg = (data && (data.message || data.error)) ? (data.message || data.error) : 'Unknown error occurred';
          status.innerHTML = '<div class="alert-err">Error reading restore status: ' + String(msg).replace(/[&<>]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;'}[c])) + '</div>';
          if (__restorePollTimer) {
            clearInterval(__restorePollTimer);
            __restorePollTimer = null;
          }
          __restorePollTick = null;
          btn.disabled = false;
          btn.textContent = 'Restore Database';
          return;
        }

        if (typeof data.log_chunk === 'string' && data.log_chunk.length) {
          logEl.textContent += data.log_chunk;
          logEl.scrollTop = logEl.scrollHeight;
        }
        if (typeof data.offset === 'number') {
          offset = data.offset;
        }

        const st = String(data.state || 'running');
        if (st === 'ok') {
          if (__restorePollTimer) {
            clearInterval(__restorePollTimer);
            __restorePollTimer = null;
          }
          __restorePollTick = null;
          status.innerHTML = renderOkBannerWithDbLink('Restore completed successfully.', 'See Restored Database');
          btn.disabled = true;
          btn.textContent = 'Database Restored!';
          btn.style.background = '#28a745';
          btn.style.borderColor = '#28a745';
          btn.style.color = 'white';
        } else if (st === 'error') {
          if (__restorePollTimer) {
            clearInterval(__restorePollTimer);
            __restorePollTimer = null;
          }
          __restorePollTick = null;
          const code = (data.exit_code !== null && data.exit_code !== undefined) ? String(data.exit_code) : '?';
          status.innerHTML = '<div class="alert-err">Restore failed. Exit code: ' + code.replace(/[&<>]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;'}[c])) + '</div>';
          btn.disabled = false;
          btn.style.background = '';
          btn.style.borderColor = '';
          btn.style.color = '';
          btn.textContent = 'Restore Database';
        }
      })
      .catch(err => {
        status.innerHTML = '<div class="alert-err">Network error: ' + err.message + '</div>';
        if (__restorePollTimer) {
          clearInterval(__restorePollTimer);
          __restorePollTimer = null;
        }
        __restorePollTick = null;
        btn.disabled = false;
        btn.style.background = '';
        btn.style.borderColor = '';
        btn.style.color = '';
        btn.textContent = 'Restore Database';
      });
    };

    __restorePollTick = tick;
    tick();
    __restorePollTimer = setInterval(tick, 1500);
  }

  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible' && __restorePollTick) {
      __restorePollTick();
    }
  });

  const __dbLinkStyle = 'display:inline-block;margin-left:10px;padding:8px 16px;background:#28a745;color:white;text-decoration:none;border-radius:4px;font-weight:bold;';

  function renderDbLinkButton(label) {
    return ' <a href="/db/database.php?view=librarian" target="_blank" rel="noopener noreferrer" style="' + __dbLinkStyle + '">' + String(label) + '</a>';
  }

  function renderOkBannerWithDbLink(message, linkLabel) {
    return '<div class="alert-ok">' + String(message) + renderDbLinkButton(linkLabel) + '</div>';
  }

  function doExportMedia() {
    const orgName  = (document.getElementById('export_org_name').value  || '').trim();
    const fileType = (document.getElementById('export_file_type').value || 'all');
    const btn      = document.getElementById('exportMediaBtn');
    const statusEl = document.getElementById('exportMediaStatus');

    btn.disabled = true;
    btn.textContent = 'Building ZIP\u2026';

    if (typeof resetProgressLatch === 'function') resetProgressLatch();

    const steps = [
      { name: 'Query database', status: 'running', message: 'Finding matching records\u2026', progress: { processed: 0, total: 1 } },
      { name: 'Build archive',  status: 'pending', message: '',                               progress: null },
      { name: 'Download',       status: 'pending', message: '',                               progress: null },
    ];

    function fmtBytes(n) {
      if (n < 1024)        return n + ' B';
      if (n < 1048576)     return (n / 1024).toFixed(1) + ' KB';
      if (n < 1073741824)  return (n / 1048576).toFixed(1) + ' MB';
      return (n / 1073741824).toFixed(1) + ' GB';
    }

    function render() {
      if (typeof renderImportStepsShared === 'function') {
        statusEl.innerHTML = renderImportStepsShared(steps, { showProgressBar: true, label: 'Export:', statusIndentPx: 80 });
      }
    }

    render();

    const baseParams = { org_name: orgName, file_type: fileType };

    async function exportRun() {
      // ── Step 1: Query database (prepare) ──────────────────────────────────
      let prepResp, prepData;
      try {
        prepResp = await fetch('export_media.php', {
          method: 'POST',
          body: new URLSearchParams({ ...baseParams, mode: 'prepare' })
        });
        prepData = await prepResp.json().catch(() => null);
      } catch (err) {
        steps[0] = { name: 'Query database', status: 'error', message: 'Network error: ' + err.message };
        render();
        return;
      }
      if (!prepResp.ok || !(prepData && prepData.success)) {
        const msg = (prepData && (prepData.error || prepData.message)) ? String(prepData.error || prepData.message) : 'HTTP ' + prepResp.status;
        steps[0] = { name: 'Query database', status: 'error', message: msg };
        render();
        return;
      }
      const count        = Number(prepData.count)      || 0;
      const totalBytes   = Number(prepData.total_bytes) || 0;
      const prepSkipped  = Number(prepData.skipped)     || 0;
      const skippedNote  = prepSkipped > 0 ? ', ' + prepSkipped + ' not on disk' : '';
      steps[0] = { name: 'Query database', status: 'ok',
                   message: count + ' file(s) ready to export (' + fmtBytes(totalBytes) + skippedNote + ')',
                   progress: { processed: 1, total: 1 } };
      const skippedWarn  = prepSkipped > 0 ? '\n\nNote: ' + prepSkipped + ' DB record(s) have no matching file on disk and will be skipped.' : '';
      const confirmMsg = 'You are about to zip ' + fmtBytes(totalBytes) + ' of files.' + skippedWarn + '\n\n' +
                         'Make sure you have enough free space to accommodate this download.\n\n' +
                         'Do you wish to continue?';
      if (!window.confirm(confirmMsg)) {
        steps[1] = { name: 'Build archive', status: 'pending', message: 'Canceled before ZIP build', progress: null };
        steps[2] = { name: 'Download',      status: 'pending', message: '',                           progress: null };
        render();
        return;
      }
      steps[1] = { name: 'Build archive', status: 'running', message: 'Starting\u2026',
                   progress: { processed: 0, total: count || 1 } };
      render();

      // ── Step 2: Start async worker ─────────────────────────────────────────
      let startResp, startData;
      try {
        startResp = await fetch('export_media.php', {
          method: 'POST',
          body: new URLSearchParams({ ...baseParams, mode: 'start' })
        });
        startData = await startResp.json().catch(() => null);
      } catch (err) {
        steps[1] = { name: 'Build archive', status: 'error', message: 'Network error: ' + err.message };
        render();
        return;
      }
      if (!startResp.ok || !(startData && startData.success && startData.job_id)) {
        const msg = (startData && (startData.error || startData.message)) ? String(startData.error || startData.message) : 'HTTP ' + startResp.status;
        steps[1] = { name: 'Build archive', status: 'error', message: msg };
        render();
        return;
      }
      const jobId = String(startData.job_id);

      // ── Step 3: Poll worker progress ───────────────────────────────────────
      const buildResult = await new Promise(function (resolve) {
        pollJobStatus(jobId, 'export_media_status.php', null, function (state, data) {
          resolve({ state: state, data: data });
        }, 1500, null, function (data) {
          if (data && Array.isArray(data.steps) && data.steps.length > 0) {
            steps[1] = data.steps[0];
          }
          render();
        });
      });

      if (buildResult.state === 'error') {
        if (buildResult.data && Array.isArray(buildResult.data.steps) && buildResult.data.steps.length > 0) {
          steps[1] = buildResult.data.steps[0];
        } else {
          const errMsg = (buildResult.data && buildResult.data.error_message) ? String(buildResult.data.error_message) : 'Worker error';
          steps[1] = { name: 'Build archive', status: 'error', message: errMsg };
        }
        render();
        return;
      }

      if (buildResult.data && Array.isArray(buildResult.data.steps) && buildResult.data.steps.length > 0) {
        steps[1] = buildResult.data.steps[0];
      }
      steps[2] = { name: 'Download', status: 'running', message: 'Requesting archive\u2026', progress: null };
      render();

      // ── Step 4: Download pre-built ZIP ─────────────────────────────────────
      let dlResp;
      try {
        dlResp = await fetch('export_media_download.php?job_id=' + encodeURIComponent(jobId));
      } catch (err) {
        steps[2] = { name: 'Download', status: 'error', message: 'Network error: ' + err.message };
        render();
        return;
      }
      if (!dlResp.ok || !(dlResp.headers.get('Content-Type') || '').startsWith('application/zip')) {
        const errData = await dlResp.json().catch(() => null);
        const msg = (errData && (errData.error || errData.message)) ? String(errData.error || errData.message) : 'HTTP ' + dlResp.status;
        steps[2] = { name: 'Download', status: 'error', message: msg };
        render();
        return;
      }
      const contentLength = parseInt(dlResp.headers.get('Content-Length') || '0', 10) || 0;
      const cd    = dlResp.headers.get('Content-Disposition') || '';
      const match = cd.match(/filename="([^"]+)"/);
      const fname = match ? match[1] : 'gighive_export.zip';

      steps[2] = { name: 'Download', status: 'running',
                   message: contentLength > 0 ? '0 B / ' + fmtBytes(contentLength) : 'Receiving\u2026',
                   progress: contentLength > 0 ? { processed: 0, total: contentLength } : null };
      render();

      // Yield one frame so the browser paints the initial state before the loop starts
      await new Promise(resolve => setTimeout(resolve, 16));

      const reader = dlResp.body.getReader();
      const chunks = [];
      let received     = 0;
      let lastYieldPct = -1;

      while (true) {
        const { done, value } = await reader.read();
        if (done) break;
        chunks.push(value);
        received += value.length;
        if (contentLength > 0) {
          const pct = received / contentLength;
          // Yield to the browser every 1% so the progress bar repaints
          if (pct - lastYieldPct >= 0.01) {
            lastYieldPct = pct;
            steps[2] = { name: 'Download', status: 'running',
                         message: fmtBytes(received) + ' / ' + fmtBytes(contentLength),
                         progress: { processed: received, total: contentLength } };
            render();
            await new Promise(resolve => setTimeout(resolve, 0));
          }
        }
      }

      const blob = new Blob(chunks);
      const url  = URL.createObjectURL(blob);
      const a    = document.createElement('a');
      a.href = url; a.download = fname;
      document.body.appendChild(a); a.click();
      document.body.removeChild(a);
      URL.revokeObjectURL(url);

      steps[2] = { name: 'Download', status: 'ok',
                   message: fname + ' (' + fmtBytes(received) + ')',
                   progress: { processed: received || contentLength, total: contentLength || received } };
      render();
    }

    exportRun().finally(() => {
      btn.disabled = false;
      btn.textContent = 'Download ZIP';
    });
  }

  function doImportMediaZip() {
    const fileInput = document.getElementById('import_zip_file');
    const btn       = document.getElementById('importZipBtn');
    const statusEl  = document.getElementById('importZipStatus');

    // no-file guard
    if (!fileInput.files || !fileInput.files[0]) {
      statusEl.innerHTML = '<div class="alert-error">Please select a ZIP file first.</div>';
      return;
    }

    btn.disabled = true;
    btn.textContent = 'Uploading ZIP\u2026';

    function fmtBytes(n) {
      if (n < 1024)        return n + ' B';
      if (n < 1048576)     return (n / 1024).toFixed(1) + ' KB';
      if (n < 1073741824)  return (n / 1048576).toFixed(1) + ' MB';
      return (n / 1073741824).toFixed(1) + ' GB';
    }

    const fileSize = fileInput.files[0].size;

    const steps = [
      { name: 'Upload ZIP',   status: 'running', message: 'Uploading\u2026',
        progress: { processed: 0, total: fileSize || 1 } },
      { name: 'Inspect ZIP',  status: 'pending', message: '', progress: null },
      { name: 'Import files', status: 'pending', message: '', progress: null },
    ];

    function render() {
      if (typeof renderImportStepsShared === 'function') {
        statusEl.innerHTML = renderImportStepsShared(steps, { showProgressBar: true, label: 'Import:', statusIndentPx: 80 });
      }
    }

    async function importRun() {
      render();

      // ── Step 1: Upload + inspect ZIP (prepare) ─────────────────────────────
      let prepData, prepStatus;
      try {
        ({ data: prepData, status: prepStatus } = await new Promise(function (resolve, reject) {
          const xhr = new XMLHttpRequest();
          xhr.open('POST', 'import_media_zip.php');
          xhr.upload.onprogress = function (e) {
            if (e.lengthComputable) {
              steps[0] = { name: 'Upload ZIP', status: 'running',
                           message: fmtBytes(e.loaded) + ' / ' + fmtBytes(e.total) + ' uploaded',
                           progress: { processed: e.loaded, total: e.total } };
              render();
            }
          };
          xhr.upload.onload = function () {
            steps[0] = { name: 'Upload ZIP', status: 'ok',
                         message: fmtBytes(fileSize) + ' uploaded',
                         progress: { processed: fileSize, total: fileSize } };
            steps[1] = { name: 'Inspect ZIP', status: 'running', message: 'Scanning entries\u2026', progress: null };
            btn.textContent = 'Inspecting ZIP\u2026';
            render();
          };
          xhr.onload = function () {
            let data = null;
            try { data = JSON.parse(xhr.responseText); } catch (_e) {}
            resolve({ data: data, status: xhr.status });
          };
          xhr.onerror = function () { reject(new Error('Network error')); };
          const formData = new FormData();
          formData.append('mode', 'prepare');
          formData.append('zip_file', fileInput.files[0]);
          xhr.send(formData);
        }));
      } catch (err) {
        steps[0] = { name: 'Upload ZIP', status: 'error', message: String(err.message) };
        render();
        return;
      }
      if (prepStatus < 200 || prepStatus >= 300 || !(prepData && prepData.success)) {
        const msg = (prepData && (prepData.error || prepData.message)) ? String(prepData.error || prepData.message) : 'HTTP ' + prepStatus;
        steps[1] = { name: 'Inspect ZIP', status: 'error', message: msg };
        render();
        return;
      }

      const audioCount       = Number(prepData.audio_count)       || 0;
      const videoCount       = Number(prepData.video_count)       || 0;
      const unsupportedCount = Number(prepData.unsupported_count) || 0;
      const totalBytes       = Number(prepData.total_bytes)       || 0;
      const prepareToken     = String(prepData.prepare_token || '');

      steps[1] = { name: 'Inspect ZIP', status: 'ok',
                   message: audioCount + ' audio + ' + videoCount + ' video found (' + fmtBytes(totalBytes) + ')',
                   progress: null };
      render();

      // ── Step 2: Confirm ───────────────────────────────────────────────────
      const unsupportedNote = unsupportedCount > 0
        ? unsupportedCount + ' entries will be skipped (unsupported format).\n\n'
        : '';
      const confirmMsg = audioCount + ' audio + ' + videoCount + ' video files ready to import (' + fmtBytes(totalBytes) + ').\n\n'
        + unsupportedNote
        + 'Files already on disk are skipped safely.\n\nDo you wish to import?';

      if (!window.confirm(confirmMsg)) {
        steps[2] = { name: 'Import files', status: 'pending', message: 'Canceled.' };
        render();
        return;
      }

      steps[2] = { name: 'Import files', status: 'running', message: 'Starting\u2026',
                   progress: { processed: 0, total: audioCount + videoCount || 1 } };
      btn.textContent = 'Importing\u2026';
      render();

      // ── Step 3: Start — spawn worker ──────────────────────────────────────
      let startResp, startData;
      try {
        startResp = await fetch('import_media_zip.php', {
          method: 'POST',
          body: new URLSearchParams({ mode: 'start', prepare_token: prepareToken })
        });
        startData = await startResp.json().catch(() => null);
      } catch (err) {
        steps[2] = { name: 'Import files', status: 'error', message: 'Network error: ' + String(err.message) };
        render();
        return;
      }

      if (startResp.status === 410) {
        steps[2] = { name: 'Import files', status: 'error', message: 'Prepare token expired \u2014 please re-select the ZIP and try again.' };
        render();
        return;
      }
      if (!startResp.ok || !(startData && startData.success && startData.job_id)) {
        const msg = (startData && (startData.error || startData.message)) ? String(startData.error || startData.message) : 'HTTP ' + startResp.status;
        steps[2] = { name: 'Import files', status: 'error', message: msg };
        render();
        return;
      }

      const jobId = String(startData.job_id);

      // ── Step 4: Poll worker progress ──────────────────────────────────────
      if (typeof resetProgressLatch === 'function') resetProgressLatch();

      const result = await new Promise(function (resolve) {
        pollJobStatus(jobId, 'import_media_zip_status.php', null, function (state, data) {
          resolve({ state: state, data: data });
        }, 1500, null, function (data) {
          if (data && Array.isArray(data.steps) && data.steps.length > 0) {
            steps[2] = data.steps[0];
          }
          render();
        });
      });

      if (result.data && Array.isArray(result.data.steps) && result.data.steps.length > 0) {
        steps[2] = result.data.steps[0];
      } else if (result.state === 'error') {
        const errMsg = (result.data && result.data.error_message) ? String(result.data.error_message) : 'Worker error';
        steps[2] = { name: 'Import files', status: 'error', message: errMsg };
      }
      render();
    }

    importRun().finally(() => {
      btn.disabled = false;
      btn.textContent = 'Import ZIP';
    });
  }
  </script>
  <script>
  /* ── Live stats refresh ─────────────────────────────────────────────────── */
  (function () {
    function escHtml(s) {
      return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
    }
    function fmtGB(b)  { return (b/1073741824).toFixed(2)+' GB'; }
    function fmtMB(b)  { return (b/1048576).toFixed(1)+' MB'; }
    function fmtN(n)   { return Number(n).toLocaleString(); }
    function fmtBps(b) {
      if (b < 1024)    return b+' B/s';
      if (b < 1048576) return (b/1024).toFixed(1)+' KB/s';
      return (b/1048576).toFixed(1)+' MB/s';
    }
    function setStat(key, val) {
      var el = document.querySelector('[data-stat="'+key+'"]');
      if (el) el.textContent = val;
    }
    function applyStats(d) {
      if (d.db) {
        var el = document.getElementById('stat-db');
        if (el) {
          var h = 'MySQL '+escHtml(d.db.version)
              +' &nbsp;&middot;&nbsp; '+escHtml(d.db.db_name)
              +' &nbsp;&middot;&nbsp; '+fmtMB(d.db.size_bytes)+' on disk<br>';
          var l1=[], l2=[];
          Object.entries(d.db.counts).forEach(function(e) {
            var item = '<strong>'+fmtN(e[1])+'</strong> '+e[0].replace(/_/g,' ');
            if (e[0]==='tags'||e[0]==='taggings') l2.push(item); else l1.push(item);
          });
          h += l1.join(' &nbsp;&middot;&nbsp; ');
          if (l2.length) h += '<br>'+l2.join(' &nbsp;&middot;&nbsp; ');
          el.innerHTML = h;
        }
      }
      if (d.media) {
        ['audio','video','thumbnails','total'].forEach(function(k) {
          var v = d.media[k]; if (!v) return;
          var p = k==='thumbnails' ? 'm-thumb' : ('m-'+k);
          setStat(p+'-count', fmtN(v.count)+' files');
          setStat(p+'-bytes', fmtGB(v.bytes));
        });
      }
      if (d.disk && d.disk.length) {
        setStat('disk-0-free',  fmtGB(d.disk[0].free_bytes));
        setStat('disk-0-total', 'of '+fmtGB(d.disk[0].total_bytes));
      }
      if (d.memory) {
        setStat('mem-avail', fmtGB(d.memory.available_bytes));
        setStat('mem-total', 'of '+fmtGB(d.memory.total_bytes));
      }
      if (d.network) {
        d.network.forEach(function(n, i) {
          setStat('net-'+i+'-rx', fmtBps(n.rx_bps)+' rx');
          setStat('net-'+i+'-tx', fmtBps(n.tx_bps)+' tx');
        });
      }
    }
    var liveTimer = null;
    var countdownTimer = null;
    var countdownSecs = 0;
    function setCountdown(n) {
      countdownSecs = n;
      var el = document.getElementById('live-countdown');
      if (!el) return;
      el.textContent = n > 0 ? 'Stats will be refreshed in ' + n + 's' : '';
    }
    function startCountdown() {
      setCountdown(5);
      if (countdownTimer) clearInterval(countdownTimer);
      countdownTimer = setInterval(function() {
        countdownSecs--;
        if (countdownSecs <= 0) { setCountdown(0); } else { setCountdown(countdownSecs); }
      }, 1000);
    }
    function stopCountdown() {
      if (countdownTimer) { clearInterval(countdownTimer); countdownTimer = null; }
      var el = document.getElementById('live-countdown');
      if (el) el.textContent = '';
    }
    var LS_KEY = 'admin_system_live';
    function enableLive() {
      var btn = document.getElementById('live-btn');
      if (!btn) return;
      btn.textContent = 'Live Mode Enabled';
      btn.style.borderColor = '#22c55e';
      btn.style.color = '#22c55e';
      try { localStorage.setItem(LS_KEY, 'on'); } catch(e) {}
      if (liveTimer) { clearInterval(liveTimer); }
      pollStats();
      startCountdown();
      liveTimer = setInterval(function() { pollStats(); startCountdown(); }, 5000);
    }
    function disableLive(text) {
      if (liveTimer) { clearInterval(liveTimer); liveTimer = null; }
      stopCountdown();
      var btn = document.getElementById('live-btn');
      if (!btn) return;
      btn.textContent = text || 'Live Mode Disabled';
      btn.style.borderColor = '#ef4444';
      btn.style.color = '';
      try { localStorage.setItem(LS_KEY, 'off'); } catch(e) {}
    }
    window.toggleLive = function () {
      if (liveTimer) { disableLive('Live Mode Disabled'); } else { enableLive(); }
    };
    function pollStats() {
      fetch('/admin/admin_system_stats.php')
        .then(function(r) { return r.json(); })
        .then(function(d) { if (d.success) applyStats(d); })
        .catch(function() { /* silent — do not change button state on transient errors */ });
    }
    try {
      var __ls = localStorage.getItem(LS_KEY);
      if (__ls === 'on') { enableLive(); }
      else if (__ls === 'off') {
        var __b = document.getElementById('live-btn');
        if (__b) { __b.textContent = 'Live Mode Disabled'; __b.style.borderColor = '#ef4444'; }
      }
    } catch(e) {}
  })();
  </script>
</body>
</html>
