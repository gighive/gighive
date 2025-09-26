<?php
/**
 * changethepasswords.php — updates 'admin', 'viewer', and 'uploader' in an Apache .htpasswd.
 * Point to the target file with env var GIGHIVE_HTPASSWD_PATH (recommended).
 * Default matches your vhost variable path for Option 1:
 *   /var/www/private/gighive.htpasswd
 */

$HTPASSWD_FILE = getenv('GIGHIVE_HTPASSWD_PATH') ?: '/var/www/private/gighive.htpasswd';

/** ---- Access Gate: allow only Basic-Auth user 'admin' ----
 * Different PHP setups surface the authenticated user differently.
 */
$user = $_SERVER['PHP_AUTH_USER']
     ?? $_SERVER['REMOTE_USER']
     ?? $_SERVER['REDIRECT_REMOTE_USER']
     ?? null;

if ($user !== 'admin') {
    http_response_code(403);
    echo "<h1>Forbidden</h1><p>Admin access required.</p>";
    exit;
}

/** ---- Helpers ---- */
function load_htpasswd(string $path): array {
    if (!is_readable($path)) {
        throw new RuntimeException("Cannot read .htpasswd at $path");
    }
    $map = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if ($line === '' || ltrim($line)[0] === '#') continue;
        [$u, $h] = array_pad(explode(':', $line, 2), 2, '');
        if ($u !== '') $map[$u] = $h;
    }
    return $map;
}

/**
 * Write the htpasswd file safely.
 * - Creates a timestamped backup first.
 * - Uses true atomic replace when possible.
 * - Falls back to in-place write when the target is a bind-mount (EXDEV) or dir not writable.
 */
function write_htpasswd_atomic(string $path, array $map): void {
    $dir      = dirname($path);
    $can_dir  = is_writable($dir);
    $can_file = file_exists($path) ? is_writable($path) : $can_dir;

    if (!$can_dir && !$can_file) {
        throw new RuntimeException("Target not writable by web user (need dir or file writable): $path");
    }

    // Always make a backup if the file exists
    if (file_exists($path)) {
        $backup = $path . '.bak.' . date('Ymd-His');
        if (!@copy($path, $backup)) {
            throw new RuntimeException("Backup failed: $backup");
        }
        @chmod($backup, 0600);
    }

    // Detect cross-filesystem (bind mount) which breaks atomic rename()
    $dirStat  = @stat($dir);
    $fileStat = @stat($path);
    $crossFs  = ($dirStat !== false && $fileStat !== false && $dirStat['dev'] !== $fileStat['dev']);

    // If directory isn't writable or we're crossing FS boundary -> in-place write
    if (!$can_dir || $crossFs) {
        $fh = @fopen($path, 'wb');
        if (!$fh) {
            throw new RuntimeException("Open for write failed (bind-mount or perms?): $path");
        }
        foreach ($map as $u => $hash) {
            if (preg_match('/[:\r\n]/', $u)) { fclose($fh); throw new RuntimeException("Illegal username."); }
            fwrite($fh, $u . ':' . $hash . "\n");
        }
        fclose($fh);
        @chmod($path, 0664);
        return;
    }

    // Happy path: same FS and dir writable → atomic replace
    $tmp = tempnam($dir, 'htp_');
    if ($tmp === false) {
        throw new RuntimeException("Failed to create temp file in $dir");
    }
    $fh = fopen($tmp, 'wb');
    if (!$fh) {
        @unlink($tmp);
        throw new RuntimeException("Failed to open temp file for write.");
    }
    foreach ($map as $u => $hash) {
        if (preg_match('/[:\r\n]/', $u)) { fclose($fh); @unlink($tmp); throw new RuntimeException("Illegal username."); }
        fwrite($fh, $u . ':' . $hash . "\n");
    }
    fclose($fh);

    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        throw new RuntimeException("Atomic replace failed.");
    }

    @chmod($path, 0664);
}

function validate_password(string $label, string $pw, string $confirm): array {
    $e = [];
    if ($pw !== $confirm) $e[] = "$label passwords do not match.";
    if (strlen($pw) < 8)   $e[] = "$label password must be at least 8 characters.";
    return $e;
}

/** ---- Handle POST ---- */
$messages = [];
$errors   = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $admin_pw     = $_POST['admin_password']           ?? '';
    $admin_cfm    = $_POST['admin_password_confirm']   ?? '';
    $viewer_pw    = $_POST['viewer_password']          ?? '';
    $viewer_cfm   = $_POST['viewer_password_confirm']  ?? '';
    $uploader_pw  = $_POST['uploader_password']        ?? '';
    $uploader_cfm = $_POST['uploader_password_confirm']?? '';

    $errors = array_merge(
        validate_password('Admin',    $admin_pw,    $admin_cfm),
        validate_password('Viewer',   $viewer_pw,   $viewer_cfm),
        validate_password('Uploader', $uploader_pw, $uploader_cfm)
    );

    if (!$errors) {
        try {
            if (file_exists($HTPASSWD_FILE)) {
                if (!is_writable($HTPASSWD_FILE) && !is_writable(dirname($HTPASSWD_FILE))) {
                    throw new RuntimeException("The .htpasswd path is not writable by the web server user. Adjust perms or mount RW: $HTPASSWD_FILE");
                }
            } else {
                if (!is_writable(dirname($HTPASSWD_FILE))) {
                    throw new RuntimeException("Target directory is not writable to create .htpasswd: " . dirname($HTPASSWD_FILE));
                }
            }

            $map = file_exists($HTPASSWD_FILE) ? load_htpasswd($HTPASSWD_FILE) : [];

            if (!array_key_exists('admin',    $map)) $map['admin']    = '';
            if (!array_key_exists('viewer',   $map)) $map['viewer']   = '';
            if (!array_key_exists('uploader', $map)) $map['uploader'] = '';

            $map['admin']    = password_hash($admin_pw,    PASSWORD_BCRYPT);
            $map['viewer']   = password_hash($viewer_pw,   PASSWORD_BCRYPT);
            $map['uploader'] = password_hash($uploader_pw, PASSWORD_BCRYPT);
            if ($map['admin'] === false || $map['viewer'] === false || $map['uploader'] === false) {
                throw new RuntimeException("Failed to generate bcrypt hashes.");
            }

            write_htpasswd_atomic($HTPASSWD_FILE, $map);

            // ✅ Add redirect here
            header("Location: /db/database.php", true, 302);
            exit;

        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>Change Passwords</title>
  <style>
    :root { font-family: system-ui, -apple-system, Segoe UI, Roboto, sans-serif; }
    body { margin:0; background:#0b1020; color:#e9eef7; }
    .wrap { max-width: 880px; margin: 3rem auto; padding: 1rem; }
    .card { background:#121a33; border:1px solid #1d2a55; border-radius:16px; padding:1.5rem; }
    .row { display:grid; gap:.5rem; margin-bottom:1rem; }
    label { font-weight:600; }
    input[type=password] { width:100%; padding:.7rem; border-radius:10px; border:1px solid #33427a; background:#0e1530; color:#e9eef7; }
    button { padding:.8rem 1.1rem; border-radius:10px; border:1px solid #3b82f6; background:transparent; color:#e9eef7; cursor:pointer; }
    button:hover { background:#1e40af; color:#fff; }
    .alert-ok { background:#11331a; border:1px solid #1f7a3b; padding:.8rem 1rem; border-radius:10px; margin-bottom:1rem;}
    .alert-err{ background:#3b0d14; border:1px solid #b4232a; padding:.8rem 1rem; border-radius:10px; margin-bottom:1rem;}
    .muted { color:#a8b3cf; font-size:.95rem; }
    code.path { word-break: break-all; }
  </style>
</head>
<body>
  <div class="wrap">
    <div class="card">
      <h1>Change Passwords</h1>
      <p class="muted">
        Signed in as <code><?= htmlspecialchars($user) ?></code>. 
        Updating file: <code class="path"><?= htmlspecialchars($HTPASSWD_FILE) ?></code>
      </p>

      <?php foreach ($messages as $m): ?>
        <div class="alert-ok"><?= htmlspecialchars($m) ?></div>
      <?php endforeach; ?>
      <?php foreach ($errors as $e): ?>
        <div class="alert-err"><?= htmlspecialchars($e) ?></div>
      <?php endforeach; ?>

      <form method="post" autocomplete="off">
        <div class="row">
          <h2>Admin</h2>
          <label for="admin_password">New admin password</label>
          <input type="password" id="admin_password" name="admin_password" required minlength="8" />
          <label for="admin_password_confirm">Confirm admin password</label>
          <input type="password" id="admin_password_confirm" name="admin_password_confirm" required minlength="8" />
        </div>

        <div class="row">
          <h2>Viewer</h2>
          <label for="viewer_password">New viewer password</label>
          <input type="password" id="viewer_password" name="viewer_password" required minlength="8" />
          <label for="viewer_password_confirm">Confirm viewer password</label>
          <input type="password" id="viewer_password_confirm" name="viewer_password_confirm" required minlength="8" />
        </div>

        <div class="row">
          <h2>Uploader</h2>
          <label for="uploader_password">New uploader password</label>
          <input type="password" id="uploader_password" name="uploader_password" required minlength="8" />
          <label for="uploader_password_confirm">Confirm uploader password</label>
          <input type="password" id="uploader_password_confirm" name="uploader_password_confirm" required minlength="8" />
        </div>

        <p class="muted">A timestamped backup of the current file will be created before updating.</p>
        <button type="submit">Update Passwords</button>
      </form>
    </div>
  </div>
</body>
</html>

