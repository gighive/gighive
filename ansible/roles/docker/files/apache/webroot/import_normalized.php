<?php declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

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
$startStep('Seed genres/styles');
$startStep('Load sessions');
$startStep('Load musicians');
$startStep('Load songs');
$startStep('Load files');
$startStep('Load session_musicians');
$startStep('Load session_songs');
$startStep('Load song_files');

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

    $prepScript = __DIR__ . '/tools/mysqlPrep_normalized.py';
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
    $pdo->exec('TRUNCATE TABLE session_musicians');
    $pdo->exec('TRUNCATE TABLE session_songs');
    $pdo->exec('TRUNCATE TABLE song_files');
    $pdo->exec('TRUNCATE TABLE files');
    $pdo->exec('TRUNCATE TABLE songs');
    $pdo->exec('TRUNCATE TABLE sessions');
    $pdo->exec('TRUNCATE TABLE musicians');
    $pdo->exec('TRUNCATE TABLE genres');
    $pdo->exec('TRUNCATE TABLE styles');
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

    $finishStep(3, 'ok', 'Tables truncated');

    $pdo->exec("INSERT IGNORE INTO genres (name) VALUES
 ('Rock'),('Jazz'),('Blues'),('Funk'),('Hip-Hop'),
 ('Classical'),('Metal'),('Pop'),('Folk'),
 ('Electronic'),('Reggae'),('Country'),
 ('Latin'),('R&B'),('Alternative'),('Experimental');");

    $pdo->exec("INSERT IGNORE INTO styles (name) VALUES
 ('Acoustic'),('Electric'),('Fusion'),('Improvised'),
 ('Progressive'),('Psychedelic'),('Hard'),
 ('Soft'),('Instrumental'),('Vocal');");

    $finishStep(4, 'ok', 'Seeded genres/styles');

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

    $sql = "LOAD DATA LOCAL INFILE '{$sessions}'\n" .
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
"(song_id, file_id);\n";

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

    $finishStep(5, 'ok', 'Sessions loaded');
    $finishStep(6, 'ok', 'Musicians loaded');
    $finishStep(7, 'ok', 'Songs loaded');
    $finishStep(8, 'ok', 'Files loaded');
    $finishStep(9, 'ok', 'session_musicians loaded');
    $finishStep(10, 'ok', 'session_songs loaded');
    $finishStep(11, 'ok', 'song_files loaded');

    $fileCount = null;
    $tableCounts = [];
    try {
        $fileCount = (int)$pdo->query('SELECT COUNT(*) FROM files')->fetchColumn();
        $tableCounts = [
            'sessions' => (int)$pdo->query('SELECT COUNT(*) FROM sessions')->fetchColumn(),
            'musicians' => (int)$pdo->query('SELECT COUNT(*) FROM musicians')->fetchColumn(),
            'songs' => (int)$pdo->query('SELECT COUNT(*) FROM songs')->fetchColumn(),
            'files' => $fileCount,
            'session_musicians' => (int)$pdo->query('SELECT COUNT(*) FROM session_musicians')->fetchColumn(),
            'session_songs' => (int)$pdo->query('SELECT COUNT(*) FROM session_songs')->fetchColumn(),
            'song_files' => (int)$pdo->query('SELECT COUNT(*) FROM song_files')->fetchColumn(),
        ];
    } catch (Throwable $e) {
        $fileCount = null;
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
