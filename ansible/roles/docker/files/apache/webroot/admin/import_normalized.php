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

$steps = [];
$stepIndex = 0;
$startStep = function(string $name) use (&$steps, &$stepIndex): void {
    $steps[] = [
        'name' => $name,
        'status' => 'pending',
        'message' => '',
        'index' => $stepIndex,
    ];
    $stepIndex++;
};
$finishStep = function(int $i, string $status, string $message = '') use (&$steps): void {
    $steps[$i]['status'] = $status;
    $steps[$i]['message'] = $message;
};

$startStep('Upload received');
$startStep('Preprocess CSVs (mysqlPrep_normalized.py)');
$startStep('Validate generated CSVs');
$startStep('Truncate tables');
$startStep('Load sessions');
$startStep('Load musicians');
$startStep('Load songs');
$startStep('Load files');
$startStep('Load session_musicians');
$startStep('Load session_songs');
$startStep('Load song_files');
$startStep('Canonicalize to events/assets/event_items');

$lockPath = '/var/www/private/import_database.lock';
$lockFp = @fopen($lockPath, 'c');
if (!$lockFp) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'error' => 'Server Error',
        'message' => 'Failed to create lock file',
        'steps' => $steps,
    ]);
    exit;
}

if (!flock($lockFp, LOCK_EX | LOCK_NB)) {
    http_response_code(409);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'error' => 'Conflict',
        'message' => 'An import is already running. Please wait for it to finish.',
        'steps' => $steps,
    ]);
    fclose($lockFp);
    exit;
}

$jobRoot = '/var/www/private/import_jobs';
$jobId = date('Ymd-His') . '-' . bin2hex(random_bytes(6));
$jobDir = $jobRoot . '/' . $jobId;

try {
    if (!is_dir($jobRoot) && !@mkdir($jobRoot, 0775, true)) {
        throw new RuntimeException('Failed to create import root directory');
    }
    if (!@mkdir($jobDir, 0775, true)) {
        throw new RuntimeException('Failed to create job directory');
    }

    if (!isset($_FILES['sessions_csv']) || !is_array($_FILES['sessions_csv'])) {
        throw new RuntimeException('No file uploaded (expected field name sessions_csv)');
    }
    if (!isset($_FILES['session_files_csv']) || !is_array($_FILES['session_files_csv'])) {
        throw new RuntimeException('No file uploaded (expected field name session_files_csv)');
    }

    $sessionsFile = $_FILES['sessions_csv'];
    if (($sessionsFile['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Upload failed for sessions_csv (error code ' . ($sessionsFile['error'] ?? -1) . ')');
    }

    $sessionFilesFile = $_FILES['session_files_csv'];
    if (($sessionFilesFile['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Upload failed for session_files_csv (error code ' . ($sessionFilesFile['error'] ?? -1) . ')');
    }

    $sessionsTmp = $sessionsFile['tmp_name'] ?? '';
    if ($sessionsTmp === '' || !is_uploaded_file($sessionsTmp)) {
        throw new RuntimeException('Invalid upload for sessions_csv');
    }

    $sessionFilesTmp = $sessionFilesFile['tmp_name'] ?? '';
    if ($sessionFilesTmp === '' || !is_uploaded_file($sessionFilesTmp)) {
        throw new RuntimeException('Invalid upload for session_files_csv');
    }

    $targetSessionsCsv = $jobDir . '/sessions.csv';
    if (!@move_uploaded_file($sessionsTmp, $targetSessionsCsv)) {
        throw new RuntimeException('Failed to store uploaded sessions.csv');
    }

    $targetSessionFilesCsv = $jobDir . '/session_files.csv';
    if (!@move_uploaded_file($sessionFilesTmp, $targetSessionFilesCsv)) {
        throw new RuntimeException('Failed to store uploaded session_files.csv');
    }

    $finishStep(0, 'ok', 'Saved to ' . $jobId . '/sessions.csv and session_files.csv');

    $prepScript = dirname(__DIR__) . '/tools/mysqlPrep_normalized.py';
    if (!is_file($prepScript)) {
        throw new RuntimeException('Preprocess script not found: ' . $prepScript);
    }

    $prepOut = [];
    $prepCode = 0;
    $cmd = 'python3 ' . escapeshellarg($prepScript) . ' 2>&1';
    $oldCwd = getcwd();
    chdir($jobDir);
    exec($cmd, $prepOut, $prepCode);
    chdir($oldCwd ?: __DIR__);

    if ($prepCode !== 0) {
        throw new RuntimeException("Preprocess failed: \n" . implode("\n", $prepOut));
    }
    $finishStep(1, 'ok', 'Preprocess complete');

    $expected = [
        'sessions.csv',
        'musicians.csv',
        'songs.csv',
        'files.csv',
        'session_musicians.csv',
        'session_songs.csv',
        'song_files.csv',
    ];
    $preppedDir = $jobDir . '/prepped_csvs';
    foreach ($expected as $f) {
        $p = $preppedDir . '/' . $f;
        if (!is_file($p) || filesize($p) === 0) {
            throw new RuntimeException('Missing or empty generated file: ' . $f);
        }
    }
    $finishStep(2, 'ok', 'All generated CSVs present');

    $pdo = Database::createFromEnv();

    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    $pdo->exec('TRUNCATE TABLE event_participants');
    $pdo->exec('TRUNCATE TABLE event_items');
    $pdo->exec('TRUNCATE TABLE events');
    $pdo->exec('TRUNCATE TABLE assets');
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

    $finishStep(3, 'ok', 'Tables truncated');

    $host = getenv('DB_HOST') ?: 'localhost';
    $db = getenv('MYSQL_DATABASE') ?: 'music_db';
    $dbUser = getenv('MYSQL_USER') ?: 'appuser';
    $dbPass = getenv('MYSQL_PASSWORD') ?: '';

    $sqlFile = $jobDir . '/import_local.sql';

    $sessions = addslashes($preppedDir . '/sessions.csv');
    $musicians = addslashes($preppedDir . '/musicians.csv');
    $sessionMusicians = addslashes($preppedDir . '/session_musicians.csv');
    $songs = addslashes($preppedDir . '/songs.csv');
    $sessionSongs = addslashes($preppedDir . '/session_songs.csv');
    $files = addslashes($preppedDir . '/files.csv');
    $songFiles = addslashes($preppedDir . '/song_files.csv');

    $sql =
"CREATE TEMPORARY TABLE IF NOT EXISTS sessions (
    session_id INT NOT NULL, title VARCHAR(255), date DATE,
    org_name VARCHAR(128) DEFAULT 'default', event_type VARCHAR(64),
    description TEXT, cover_image_url TEXT, location VARCHAR(255),
    rating DECIMAL(2,1), summary TEXT, published_at VARCHAR(64),
    explicit TINYINT(1), duration_seconds INT, keywords TEXT
);
CREATE TEMPORARY TABLE IF NOT EXISTS musicians (
    musician_id INT NOT NULL, name VARCHAR(255)
);
CREATE TEMPORARY TABLE IF NOT EXISTS session_musicians (
    session_id INT NOT NULL, musician_id INT NOT NULL
);
CREATE TEMPORARY TABLE IF NOT EXISTS songs (
    song_id INT NOT NULL, title VARCHAR(255), type VARCHAR(64),
    duration_seconds INT, genre_id INT, style_id INT
);
CREATE TEMPORARY TABLE IF NOT EXISTS session_songs (
    session_id INT NOT NULL, song_id INT NOT NULL,
    position INT NULL DEFAULT NULL
);
CREATE TEMPORARY TABLE IF NOT EXISTS files (
    file_id INT NOT NULL, file_name VARCHAR(512),
    source_relpath VARCHAR(512), checksum_sha256 CHAR(64),
    file_type ENUM('audio','video'),
    duration_seconds INT, media_info TEXT, media_info_tool VARCHAR(64)
);
CREATE TEMPORARY TABLE IF NOT EXISTS song_files (
    song_id INT NOT NULL, file_id INT NOT NULL
);
" .
"LOAD DATA LOCAL INFILE '{$sessions}'\n" .
"INTO TABLE sessions\n" .
"FIELDS TERMINATED BY ',' OPTIONALLY ENCLOSED BY '\"' ESCAPED BY '\\\\'\n" .
"LINES TERMINATED BY '\\r\\n'\n" .
"IGNORE 1 LINES\n" .
"(\n" .
"  session_id,\n" .
"  @name,\n" .
"  date,\n" .
"  @org_name,\n" .
"  @event_type,\n" .
"  description,\n" .
"  @image_path,\n" .
"  @crew,\n" .
"  location,\n" .
"  @rating_raw,\n" .
"  summary,\n" .
"  @pub_date,\n" .
"  explicit,\n" .
"  @duration,\n" .
"  keywords\n" .
")\n" .
"SET\n" .
"  title = NULLIF(@name, ''),\n" .
"  cover_image_url = NULLIF(@image_path, ''),\n" .
"  published_at = NULLIF(@pub_date, ''),\n" .
"  org_name = COALESCE(NULLIF(@org_name, ''), 'default'),\n" .
"  event_type = NULLIF(@event_type, ''),\n" .
"  rating = CASE\n" .
"    WHEN @rating_raw IS NULL OR @rating_raw = '' THEN NULL\n" .
"    ELSE CAST(\n" .
"      LEAST(\n" .
"        5,\n" .
"        GREATEST(\n" .
"          1,\n" .
"          COALESCE(\n" .
"            CASE\n" .
"              WHEN REPLACE(REPLACE(LOWER(@rating_raw),' ',''),'★','*') REGEXP '^[0-9]+(\\\\.[0-9]+)?$'\n" .
"                THEN CAST(REPLACE(REPLACE(LOWER(@rating_raw),' ',''),'★','*') AS DECIMAL(3,1))\n" .
"              ELSE NULL\n" .
"            END,\n" .
"            (LENGTH(REPLACE(REPLACE(LOWER(@rating_raw),' ',''),'★','*'))\n" .
"             - LENGTH(REPLACE(REPLACE(REPLACE(LOWER(@rating_raw),' ',''),'★','*'),'*','')))\n" .
"            + CASE\n" .
"                WHEN REPLACE(REPLACE(LOWER(@rating_raw),' ',''),'★','*') REGEXP '(^|[^0-9])1/2([^0-9]|$)|half'\n" .
"                  THEN 0.5\n" .
"                ELSE 0\n" .
"              END\n" .
"          )\n" .
"        )\n" .
"      ) AS DECIMAL(2,1)\n" .
"    )\n" .
"  END,\n" .
"  duration_seconds = CASE\n" .
"    WHEN @duration REGEXP '^[0-9]{1,2}:[0-9]{2}:[0-9]{2}$' THEN TIME_TO_SEC(STR_TO_DATE(@duration, '%H:%i:%s'))\n" .
"    WHEN @duration REGEXP '^[0-9]{1,2}:[0-9]{2}$' THEN TIME_TO_SEC(STR_TO_DATE(CONCAT('00:', @duration), '%H:%i:%s'))\n" .
"    WHEN @duration REGEXP '^[0-9]+$' THEN CAST(@duration AS UNSIGNED)\n" .
"    ELSE NULL\n" .
"  END;\n\n" .
"LOAD DATA LOCAL INFILE '{$musicians}'\n" .
"INTO TABLE musicians\n" .
"FIELDS TERMINATED BY ',' OPTIONALLY ENCLOSED BY '\"' ESCAPED BY '\\\\'\n" .
"LINES TERMINATED BY '\\r\\n'\n" .
"IGNORE 1 LINES\n" .
"(musician_id, name);\n\n" .
"LOAD DATA LOCAL INFILE '{$sessionMusicians}'\n" .
"INTO TABLE session_musicians\n" .
"FIELDS TERMINATED BY ','\n" .
"LINES TERMINATED BY '\\r\\n'\n" .
"IGNORE 1 LINES\n" .
"(session_id, musician_id);\n\n" .
"LOAD DATA LOCAL INFILE '{$songs}'\n" .
"INTO TABLE songs\n" .
"FIELDS TERMINATED BY ',' OPTIONALLY ENCLOSED BY '\"' ESCAPED BY '\\\\'\n" .
"LINES TERMINATED BY '\\r\\n'\n" .
"IGNORE 1 LINES\n" .
"(song_id, title, type, @duration, @genre_id, @style_id)\n" .
"SET\n" .
"  duration_seconds = CASE\n" .
"    WHEN @duration IS NULL OR @duration = '' THEN NULL\n" .
"    WHEN @duration REGEXP '^[0-9]{1,2}:[0-9]{2}:[0-9]{2}$' THEN TIME_TO_SEC(STR_TO_DATE(@duration, '%H:%i:%s'))\n" .
"    WHEN @duration REGEXP '^[0-9]{1,2}:[0-9]{2}$' THEN TIME_TO_SEC(STR_TO_DATE(CONCAT('00:', @duration), '%H:%i:%s'))\n" .
"    WHEN @duration REGEXP '^[0-9]+$' THEN CAST(@duration AS UNSIGNED)\n" .
"    ELSE NULL\n" .
"  END,\n" .
"  genre_id = NULLIF(@genre_id, ''),\n" .
"  style_id = NULLIF(@style_id, '');\n\n" .
"LOAD DATA LOCAL INFILE '{$sessionSongs}'\n" .
"INTO TABLE session_songs\n" .
"FIELDS TERMINATED BY ','\n" .
"LINES TERMINATED BY '\\r\\n'\n" .
"IGNORE 1 LINES\n" .
"(session_id, song_id);\n\n" .
"LOAD DATA LOCAL INFILE '{$files}'\n" .
"INTO TABLE files\n" .
"FIELDS TERMINATED BY ',' OPTIONALLY ENCLOSED BY '\"' ESCAPED BY ''\n" .
"LINES TERMINATED BY '\\r\\n'\n" .
"IGNORE 1 LINES\n" .
"(file_id, file_name, @source_relpath, @checksum_sha256, file_type, @duration_seconds, @media_info, @media_info_tool)\n" .
"SET\n" .
"  source_relpath = NULLIF(@source_relpath, ''),\n" .
"  checksum_sha256 = NULLIF(@checksum_sha256, ''),\n" .
"  duration_seconds = NULLIF(@duration_seconds, ''),\n" .
"  media_info = NULLIF(@media_info, ''),\n" .
"  media_info_tool = NULLIF(@media_info_tool, '');\n\n" .
"LOAD DATA LOCAL INFILE '{$songFiles}'\n" .
"INTO TABLE song_files\n" .
"FIELDS TERMINATED BY ','\n" .
"LINES TERMINATED BY '\\r\\n'\n" .
"IGNORE 1 LINES\n" .
"(song_id, file_id);\n" .

"-- Canonicalize events from sessions\n" .
"INSERT INTO events (event_date, org_name, event_type)\n" .
"SELECT date, COALESCE(NULLIF(org_name,''), 'default'), COALESCE(NULLIF(event_type,''), 'band')\n" .
"FROM sessions;\n\n" .

"-- Canonicalize assets from files (only rows with checksum)\n" .
"INSERT INTO assets (checksum_sha256, file_ext, file_type, source_relpath, duration_seconds, media_info, media_info_tool)\n" .
"SELECT\n" .
"  f.checksum_sha256,\n" .
"  LOWER(IF(LOCATE('.', f.file_name) > 0, SUBSTRING_INDEX(f.file_name, '.', -1), '')),\n" .
"  f.file_type,\n" .
"  f.source_relpath,\n" .
"  f.duration_seconds,\n" .
"  f.media_info,\n" .
"  f.media_info_tool\n" .
"FROM files f\n" .
"WHERE f.checksum_sha256 IS NOT NULL AND f.checksum_sha256 != '';\n\n" .

"-- Canonicalize event_items via legacy junction tables\n" .
"INSERT INTO event_items (event_id, asset_id, item_type, label, position)\n" .
"SELECT\n" .
"  e.event_id,\n" .
"  a.asset_id,\n" .
"  sg.type,\n" .
"  sg.title,\n" .
"  ROW_NUMBER() OVER (PARTITION BY e.event_id ORDER BY ss.position, sf.file_id)\n" .
"FROM sessions sess\n" .
"JOIN events e ON e.event_date = sess.date AND e.org_name = sess.org_name\n" .
"JOIN session_songs ss ON ss.session_id = sess.session_id\n" .
"JOIN songs sg ON sg.song_id = ss.song_id\n" .
"JOIN song_files sf ON sf.song_id = sg.song_id\n" .
"JOIN files f ON f.file_id = sf.file_id AND f.checksum_sha256 IS NOT NULL AND f.checksum_sha256 != ''\n" .
"JOIN assets a ON a.checksum_sha256 = f.checksum_sha256;\n\n" .

"-- Canonicalize participants from musicians\n" .
"INSERT INTO participants (name)\n" .
"SELECT DISTINCT name FROM musicians WHERE name IS NOT NULL AND name != ''\n" .
"ON DUPLICATE KEY UPDATE participant_id = participant_id;\n\n" .

"-- Canonicalize event_participants from session_musicians\n" .
"INSERT INTO event_participants (event_id, participant_id)\n" .
"SELECT DISTINCT e.event_id, p.participant_id\n" .
"FROM session_musicians sm\n" .
"JOIN sessions s ON sm.session_id = s.session_id\n" .
"JOIN events e ON e.event_date = s.date AND e.org_name = COALESCE(NULLIF(s.org_name,''), 'default')\n" .
"JOIN musicians m ON sm.musician_id = m.musician_id\n" .
"JOIN participants p ON p.name = m.name\n" .
"ON DUPLICATE KEY UPDATE participant_id = participant_id;\n";

    if (@file_put_contents($sqlFile, $sql) === false) {
        throw new RuntimeException('Failed to write SQL import file');
    }

    $mysqlCmd = 'mysql --local-infile=1'
        . ' -h ' . escapeshellarg($host)
        . ' -u ' . escapeshellarg($dbUser)
        . ' --database=' . escapeshellarg($db)
        . ' < ' . escapeshellarg($sqlFile)
        . ' 2>&1';

    $oldCwd = getcwd();
    chdir($jobDir);

    $env = $_ENV;
    $env['MYSQL_PWD'] = $dbPass;

    $proc = proc_open(['/bin/sh', '-lc', $mysqlCmd], [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes, $jobDir, $env);

    if (!is_resource($proc)) {
        chdir($oldCwd ?: __DIR__);
        throw new RuntimeException('Failed to start mysql client');
    }

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $mysqlCode = proc_close($proc);

    chdir($oldCwd ?: __DIR__);

    if ($mysqlCode !== 0) {
        throw new RuntimeException("MySQL load failed:\n" . trim($stdout . "\n" . $stderr));
    }

    $finishStep(4, 'ok', 'Sessions loaded');
    $finishStep(5, 'ok', 'Musicians loaded');
    $finishStep(6, 'ok', 'Songs loaded');
    $finishStep(7, 'ok', 'Files loaded');
    $finishStep(8, 'ok', 'session_musicians loaded');
    $finishStep(9, 'ok', 'session_songs loaded');
    $finishStep(10, 'ok', 'song_files loaded');
    $finishStep(11, 'ok', 'Canonicalized to events/assets/event_items');

    $tableCounts = [];
    try {
        $tableCounts = [
            'events'       => (int)$pdo->query('SELECT COUNT(*) FROM events')->fetchColumn(),
            'assets'       => (int)$pdo->query('SELECT COUNT(*) FROM assets')->fetchColumn(),
            'event_items'  => (int)$pdo->query('SELECT COUNT(*) FROM event_items')->fetchColumn(),
        ];
    } catch (Throwable $e) {
        $tableCounts = [];
    }

    http_response_code(200);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'message' => 'Database import completed successfully.',
        'job_id' => $jobId,
        'steps' => $steps,
        'file_count' => $fileCount,
        'table_counts' => $tableCounts,
    ]);

} catch (Throwable $e) {
    $failedAt = null;
    for ($i = 0; $i < count($steps); $i++) {
        if ($steps[$i]['status'] === 'pending') {
            $failedAt = $i;
            break;
        }
    }
    if ($failedAt !== null) {
        $finishStep($failedAt, 'error', $e->getMessage());
    }

    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'error' => 'Import failed',
        'message' => $e->getMessage(),
        'steps' => $steps,
        'job_id' => $jobId,
    ]);

} finally {
    flock($lockFp, LOCK_UN);
    fclose($lockFp);
}
