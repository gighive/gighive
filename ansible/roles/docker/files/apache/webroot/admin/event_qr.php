<?php declare(strict_types=1);
session_start();
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

$user = $_SERVER['PHP_AUTH_USER']
    ?? $_SERVER['REMOTE_USER']
    ?? $_SERVER['REDIRECT_REMOTE_USER']
    ?? null;

if ($user !== 'admin') {
    http_response_code(403);
    echo '<h1>Forbidden</h1><p>Admin access required.</p>';
    exit;
}

require_once __DIR__ . '/../vendor/autoload.php';
use Production\Api\Infrastructure\Database;

$pdo     = null;
$dbError = '';
try {
    $pdo = Database::createFromEnv();
} catch (\Throwable $e) {
    $dbError = $e->getMessage();
}

$orgs = [];
if ($pdo) {
    try {
        $orgs = $pdo->query(
            'SELECT DISTINCT org_name FROM events ORDER BY org_name ASC'
        )->fetchAll(PDO::FETCH_COLUMN);
    } catch (\Throwable $e) { /* ignore */ }
}

$orgName     = trim($_GET['org_name']   ?? '');
$eventDate   = trim($_GET['event_date'] ?? '');
$eventId     = null;
$loadError   = '';
$revokedMsg  = trim($_GET['msg'] ?? '') === 'revoked' ? 'Token revoked.' : '';

if ($orgName !== '' || $eventDate !== '') {
    if ($orgName === '' || strlen($orgName) > 255) {
        $loadError = 'Org name is required (max 255 characters).';
    } elseif ($eventDate === '') {
        $loadError = 'Event date is required.';
    } else {
        $dt = \DateTime::createFromFormat('Y-m-d', $eventDate);
        if ($dt === false || $dt->format('Y-m-d') !== $eventDate) {
            $loadError = 'Invalid date — use YYYY-MM-DD format.';
        }
    }

    if ($loadError === '' && $pdo) {
        try {
            $stmt = $pdo->prepare(
                'SELECT event_id FROM events WHERE tenant_id = 1 AND event_date = ? AND org_name = ? LIMIT 1'
            );
            $stmt->execute([$eventDate, $orgName]);
            $eventId = $stmt->fetchColumn();

            if (!$eventId) {
                $ins = $pdo->prepare(
                    'INSERT INTO events (tenant_id, event_key, event_date, org_name) VALUES (1, UUID(), ?, ?)'
                );
                $ins->execute([$eventDate, $orgName]);
                $eventId = (int)$pdo->lastInsertId();
            }
            $eventId = (int)$eventId;
        } catch (\Throwable $e) {
            $loadError = 'Database error: ' . $e->getMessage();
        }
    }
}

$postMsg    = (string)($_SESSION['flash_msg'] ?? '');
$postOk     = isset($_SESSION['flash_ok'])  ? (bool)$_SESSION['flash_ok']  : null;
$newQrUrl   = ($_SESSION['flash_url'] ?? '') ?: null;
unset($_SESSION['flash_msg'], $_SESSION['flash_ok'], $_SESSION['flash_url']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $pdo && $eventId) {
    $action = $_POST['action'] ?? '';

    if ($action === 'generate') {
        $defaultTtlEnv = (int)(getenv('QR_TOKEN_DEFAULT_TTL_HOURS') ?: 168);
        $expiryHours   = (int)($_POST['ttl_hours'] ?? $defaultTtlEnv);
        if (!in_array($expiryHours, [4, 24, 168, 336], true)) {
            $postMsg = 'Invalid expiry value.';
            $postOk  = false;
        } else {
            try {
                $rawToken  = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
                $tokenHash = hash('sha256', $rawToken);
                $stmt = $pdo->prepare(
                    'INSERT INTO event_upload_tokens (event_id, token_hash, expires_at, created_by_user_id)
                     VALUES (?, ?, DATE_ADD(NOW(), INTERVAL ? HOUR), NULL)'
                );
                $stmt->execute([$eventId, $tokenHash, $expiryHours]);
                $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                $host   = $_SERVER['HTTP_HOST'] ?? 'gighive.app';
                $_SESSION['flash_url'] = $scheme . '://' . $host . '/upload/' . $rawToken;
                $_SESSION['flash_msg'] = 'QR code generated.';
                $_SESSION['flash_ok']  = true;
                header('Location: /admin/event_qr.php?org_name=' . urlencode($orgName)
                     . '&event_date=' . urlencode($eventDate));
                exit;
            } catch (\Throwable $e) {
                $postMsg = 'Error generating token: ' . $e->getMessage();
                $postOk  = false;
            }
        }

    } elseif ($action === 'revoke') {
        $tokenId = (int)($_POST['token_id'] ?? 0);
        if ($tokenId <= 0) {
            $postMsg = 'Invalid token ID.';
            $postOk  = false;
        } else {
            try {
                $stmt = $pdo->prepare(
                    'UPDATE event_upload_tokens SET is_active = 0 WHERE token_id = ? AND event_id = ?'
                );
                $stmt->execute([$tokenId, $eventId]);
                header('Location: /admin/event_qr.php?org_name=' . urlencode($orgName)
                     . '&event_date=' . urlencode($eventDate) . '&msg=revoked');
                exit;
            } catch (\Throwable $e) {
                $postMsg = 'Error revoking token: ' . $e->getMessage();
                $postOk  = false;
            }
        }

    } elseif ($action === 'save_event_settings') {
        $n = filter_var($_POST['gallery_lifespan_days'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if ($n === false) {
            $postMsg = 'Gallery lifespan must be a non-negative integer (0 = indefinite).';
            $postOk  = false;
        } else {
            $isMultiDayPost = isset($_POST['is_multi_day']) ? 1 : 0;
            $tenantIdSave   = (int)(getenv('QR_GUEST_UPLOAD_TENANT_ID') ?: 1);
            try {
                $stmt = $pdo->prepare(
                    'UPDATE events
                     SET gallery_expires_at = CASE WHEN ? > 0 THEN DATE_ADD(event_date, INTERVAL ? DAY) ELSE NULL END,
                         is_multi_day = ?
                     WHERE event_id = ? AND tenant_id = ?'
                );
                $stmt->execute([$n, $n, $isMultiDayPost, $eventId, $tenantIdSave]);
                header('Location: /admin/event_qr.php?org_name=' . urlencode($orgName)
                     . '&event_date=' . urlencode($eventDate));
                exit;
            } catch (\Throwable $e) {
                $postMsg = 'Error saving event settings: ' . $e->getMessage();
                $postOk  = false;
            }
        }

    } elseif ($action === 'approve_upload' || $action === 'reject_upload') {
        $jobId = trim((string)($_POST['job_id'] ?? ''));
        if ($jobId === '' || preg_match('/^[A-Za-z0-9_-]+$/', $jobId) !== 1) {
            $postMsg = 'Invalid job ID.';
            $postOk  = false;
        } else {
            $moderationStatus = ($action === 'approve_upload') ? 'approved' : 'rejected';
            try {
                $stmt = $pdo->prepare(
                    'UPDATE upload_jobs j
                     JOIN anon_upload_attributions a ON a.upload_job_id = j.job_id
                     JOIN event_upload_tokens t ON t.token_id = a.token_id
                     SET j.moderation_status = ?,
                         j.approved_at = CASE WHEN ? = \'approved\' THEN NOW() ELSE NULL END
                     WHERE j.job_id = ? AND t.event_id = ?'
                );
                $stmt->execute([$moderationStatus, $moderationStatus, $jobId, $eventId]);
                header('Location: /admin/event_qr.php?org_name=' . urlencode($orgName)
                     . '&event_date=' . urlencode($eventDate));
                exit;
            } catch (\Throwable $e) {
                $postMsg = 'Error moderating upload: ' . $e->getMessage();
                $postOk  = false;
            }
        }
    }
}

$tokens              = [];
$guestUploads        = [];
$galleryExpiresAt    = null;
$isMultiDay          = 0;
$galleryLifespanDays = null;
if ($pdo && $eventId) {
    try {
        $stmt = $pdo->prepare(
            'SELECT token_id, token_hash, expires_at, is_active, created_at
             FROM event_upload_tokens
             WHERE event_id = ?
             ORDER BY created_at DESC'
        );
        $stmt->execute([$eventId]);
        $tokens = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (\Throwable $e) { /* ignore */ }

    // Detect whether any active (not revoked, not expired) token already exists
    $activeTokenCount = 0;
    foreach ($tokens as $t) {
        if ((int)$t['is_active'] === 1 && new \DateTime($t['expires_at']) > $now) {
            $activeTokenCount++;
        }
    }
    $plural = $activeTokenCount > 1 ? 's' : '';
    $activeTokenWarning = $activeTokenCount > 0
        ? htmlspecialchars(
            "\u{26A0}\u{FE0F} This event already has {$activeTokenCount} active QR code{$plural}. "
            . "Generating a new one will create an additional upload link \u{2014} "
            . "the existing one{$plural} will remain active until revoked or expired. Continue anyway?",
            ENT_QUOTES)
        : '';

    try {
        $stmt = $pdo->prepare(
            'SELECT a.display_name, a.tos_accepted_at, a.created_at,
                    j.job_id, j.id AS upload_job_row_id,
                    j.label, j.file_relpath,
                    j.moderation_status, j.approved_at,
                    j.guest_flagged, j.guest_flagged_at,
                    t.is_active AS token_active, t.expires_at
             FROM anon_upload_attributions a
             JOIN event_upload_tokens t ON t.token_id = a.token_id
             JOIN upload_jobs j ON j.job_id = a.upload_job_id
             WHERE t.event_id = ?
             ORDER BY a.created_at DESC'
        );
        $stmt->execute([$eventId]);
        $guestUploads = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (\Throwable $e) { /* ignore */ }

    try {
        $stmt = $pdo->prepare(
            'SELECT gallery_expires_at, is_multi_day,
                    DATEDIFF(gallery_expires_at, event_date) AS lifespan
             FROM events WHERE event_id = ? LIMIT 1'
        );
        $stmt->execute([$eventId]);
        $evtRow = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($evtRow) {
            $galleryExpiresAt    = $evtRow['gallery_expires_at'];
            $isMultiDay          = (int)$evtRow['is_multi_day'];
            $galleryLifespanDays = $galleryExpiresAt !== null
                ? max(0, (int)$evtRow['lifespan'])
                : 0;
        }
    } catch (\Throwable $e) { /* ignore */ }
}

$defaultTtl = (int)(getenv('QR_TOKEN_DEFAULT_TTL_HOURS') ?: 168);
if (!in_array($defaultTtl, [4, 24, 168, 336], true)) {
    $defaultTtl = 168;
}
$qrJsVersion            = htmlspecialchars(getenv('QR_CODE_JS_VERSION') ?: '1.5.4', ENT_QUOTES);
$galleryDefaultLifespan = (int)(getenv('QR_GALLERY_DEFAULT_LIFESPAN_DAYS') ?: 90);
$ttlWarningHours        = [];
if ($galleryExpiresAt !== null) {
    $galleryExpDt = new \DateTime($galleryExpiresAt);
    $nowCheck     = new \DateTime('now');
    foreach ([4, 24, 168, 336] as $h) {
        $windowEnd = (clone $nowCheck)->modify("+{$h} hours");
        if ($windowEnd > $galleryExpDt) {
            $ttlWarningHours[] = $h;
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Admin: Guest QR Upload</title>
  <style>
    :root { font-family: system-ui, -apple-system, Segoe UI, Roboto, sans-serif; }
    body { margin:0; background:#0b1020; color:#e9eef7; }
    .wrap { max-width:880px; margin:3rem auto; padding:1rem; }
    h1 { margin:0 0 1.5rem; }
    h2 { margin:0 0 .75rem; font-size:1.1rem; }
    a { color:#60a5fa; }
    .card { background:#121a33; border:1px solid #1d2a55; border-radius:12px; padding:1.25rem; margin-bottom:1.25rem; }
    label { font-weight:600; display:block; margin-bottom:.3rem; }
    input[type=text], input[type=date] { padding:.65rem; border-radius:8px; border:1px solid #33427a; background:#0e1530; color:#e9eef7; font-size:.95rem; }
    button { padding:.7rem 1.2rem; border-radius:10px; border:1px solid #3b82f6; background:transparent; color:#e9eef7; cursor:pointer; font-size:.95rem; }
    button:hover:not(:disabled) { background:#1e40af; }
    button:disabled { opacity:.5; cursor:not-allowed; }
    .btn-danger { border-color:#dc2626; }
    .btn-danger:hover:not(:disabled) { background:#991b1b; }
    .btn-sm { padding:.3rem .75rem; font-size:.82rem; }
    .alert-ok  { background:#11331a; border:1px solid #1f7a3b; padding:.75rem 1rem; border-radius:8px; margin-bottom:.75rem; }
    .alert-err { background:#3b0d14; border:1px solid #b4232a; padding:.75rem 1rem; border-radius:8px; margin-bottom:.75rem; }
    .muted { color:#a8b3cf; font-size:.9rem; }
    .table-scroll { overflow-x:auto; -webkit-overflow-scrolling:touch; }
    table { width:100%; border-collapse:collapse; font-size:.88rem; }
    th,td { border:1px solid #1d2a55; padding:7px 10px; text-align:left; vertical-align:middle; }
    th { background:#0e1530; }
    .badge { display:inline-block; padding:2px 8px; border-radius:20px; font-size:.78rem; font-weight:600; }
    .badge-active  { background:#1a3320; color:#4ade80; }
    .badge-expired { background:#3b2700; color:#fbbf24; }
    .badge-revoked { background:#3b1a1a; color:#f87171; }
    .badge-mod-pending  { background:#3b2900; color:#fbbf24; }
    .badge-mod-approved { background:#1a3320; color:#4ade80; }
    .alert-warn { background:#3b2900; border:1px solid #f59e0b; padding:.75rem 1rem; border-radius:8px; color:#fbbf24; }
    .warn-row td { color:#fbbf24; }
    .form-row { display:flex; gap:.75rem; align-items:flex-end; flex-wrap:wrap; margin-bottom:.75rem; }
    .form-row .field { display:flex; flex-direction:column; gap:.3rem; }
    .ttl-group { display:flex; gap:.75rem; flex-wrap:wrap; margin:.5rem 0 .75rem; }
    .ttl-group label { font-weight:400; display:flex; align-items:center; gap:.35rem; }
    #qr-wrap { display:none; text-align:center; margin:.75rem 0 .25rem; }
    #qr-canvas { display:block; margin:.5rem auto; image-rendering:pixelated; }
    .license-footer { text-align:center; margin-top:2rem; }
  </style>
  <script src="/admin/assets/qrcode.min.js"></script>
</head>
<body>
<div class="wrap">
  <h1>Guest QR Upload</h1>
  <p><a href="/admin/admin_system.php">← System</a></p>

  <?php if ($dbError): ?>
    <div class="alert-err">DB error: <?= htmlspecialchars($dbError, ENT_QUOTES) ?></div>
  <?php endif; ?>

  <!-- Event Selector -->
  <div class="card">
    <h2>Event Selector</h2>
    <form method="GET" action="/admin/event_qr.php">
      <div style="display:grid;grid-template-columns:auto 1fr auto;gap:.75rem;align-items:end;margin-bottom:.4rem">
        <div>
          <label for="event_date">Event date *</label>
          <input id="event_date" type="date" name="event_date" required
                 value="<?= htmlspecialchars($eventDate, ENT_QUOTES) ?>" style="display:block;width:100%;box-sizing:border-box">
        </div>
        <div>
          <label for="org_name">Organization *</label>
          <input id="org_name" type="text" name="org_name" list="org-list" required
                 value="<?= htmlspecialchars($orgName, ENT_QUOTES) ?>" style="display:block;width:100%;box-sizing:border-box">
          <datalist id="org-list">
            <?php foreach ($orgs as $o): ?>
              <option value="<?= htmlspecialchars($o, ENT_QUOTES) ?>">
            <?php endforeach; ?>
          </datalist>
        </div>
        <button type="submit">Load Event</button>
      </div>
      <small class="muted">Choose an existing organization or add new</small>
    </form>
    <?php if ($loadError): ?>
      <div class="alert-err"><?= htmlspecialchars($loadError, ENT_QUOTES) ?></div>
    <?php elseif ($eventId): ?>
      <div class="alert-ok">Event loaded: <strong><?= htmlspecialchars($orgName, ENT_QUOTES) ?></strong> &mdash; <?= htmlspecialchars($eventDate, ENT_QUOTES) ?></div>
      <form method="POST" action="/admin/event_qr.php?org_name=<?= urlencode($orgName) ?>&event_date=<?= urlencode($eventDate) ?>" style="margin-top:.75rem">
        <input type="hidden" name="action" value="save_event_settings">
        <input type="hidden" name="event_id" value="<?= (int)$eventId ?>">
        <div class="form-row" style="align-items:flex-end;gap:.75rem;flex-wrap:wrap">
          <div class="field">
            <label for="gallery_lifespan_days">Gallery lifespan (days; 0&nbsp;=&nbsp;indefinite)</label>
            <input id="gallery_lifespan_days" name="gallery_lifespan_days" type="number" min="0"
                   value="<?= (int)($galleryLifespanDays ?? $galleryDefaultLifespan) ?>"
                   style="width:110px;padding:.5rem;border-radius:8px;border:1px solid #33427a;background:#0e1530;color:#e9eef7;font-size:.9rem">
          </div>
          <div class="field" style="flex-direction:row;align-items:center;gap:.4rem">
            <input id="is_multi_day" name="is_multi_day" type="checkbox" value="1"
                   <?= $isMultiDay ? 'checked' : '' ?>>
            <label for="is_multi_day" style="font-weight:400;margin-bottom:0">Multi-day event</label>
          </div>
          <button type="submit" class="btn-sm">Save</button>
        </div>
      </form>
    <?php else: ?>
      <p class="muted">Enter a date and org name, then click Load Event.</p>
      <p class="muted">This is your starting point — no existing media required.</p>
    <?php endif; ?>
  </div>

  <?php if ($eventId): ?>

  <?php if ($revokedMsg): ?>
    <div class="alert-ok"><?= htmlspecialchars($revokedMsg, ENT_QUOTES) ?></div>
  <?php endif; ?>

  <!-- Section 1: QR Generator -->
  <div class="card">
    <h2>QR Code Generator (<?= htmlspecialchars($orgName, ENT_QUOTES) ?> &mdash; <?= htmlspecialchars($eventDate, ENT_QUOTES) ?>)</h2>
    <?php if ($postMsg && $postOk !== null): ?>
      <div class="<?= $postOk ? 'alert-ok' : 'alert-err' ?>"><?= htmlspecialchars($postMsg, ENT_QUOTES) ?></div>
    <?php endif; ?>

    <form method="POST" action="/admin/event_qr.php?org_name=<?= urlencode($orgName) ?>&event_date=<?= urlencode($eventDate) ?>">
      <input type="hidden" name="action" value="generate">
      <label>Link expiry</label>
      <div class="ttl-group">
        <?php foreach ([4 => '4 hours', 24 => '24 hours', 168 => '7 days', 336 => '14 days'] as $h => $lbl): ?>
          <label>
            <input type="radio" name="ttl_hours" value="<?= $h ?>" <?= $defaultTtl === $h ? 'checked' : '' ?>>
            <?= htmlspecialchars($lbl, ENT_QUOTES) ?>
          </label>
        <?php endforeach; ?>
      </div>
      <?php if (!empty($ttlWarningHours)): ?>
      <div id="ttl-mismatch-warning" class="alert-warn" style="display:none;margin:.5rem 0 .75rem">
        &#9888; Upload window extends beyond gallery expiry &mdash; guests who upload near the end of the window may be approved after the gallery has already closed.
      </div>
      <?php endif; ?>
      <button type="submit"<?php if (!empty($activeTokenWarning)): ?> onclick="return confirm('<?= $activeTokenWarning ?>')"<?php endif; ?>>Generate QR</button>
    </form>
    <?php if (!empty($ttlWarningHours)): ?>
    <script>
    (function() {
      var warningTtls = <?= json_encode($ttlWarningHours) ?>;
      var radios  = document.querySelectorAll('input[name="ttl_hours"]');
      var warning = document.getElementById('ttl-mismatch-warning');
      function update() {
        var sel = document.querySelector('input[name="ttl_hours"]:checked');
        var h   = sel ? parseInt(sel.value, 10) : null;
        if (warning) warning.style.display = (h !== null && warningTtls.indexOf(h) !== -1) ? 'block' : 'none';
      }
      radios.forEach(function(r) { r.addEventListener('change', update); });
      update();
    })();
    </script>
    <?php endif; ?>

    <div id="qr-wrap">
      <div id="qr-inner" style="display:inline-block;margin:.5rem auto"></div>
      <p class="muted" id="qr-url-text" style="word-break:break-all"></p>
      <button type="button" id="qr-download-btn">Download PNG</button>
    </div>
  </div>

  <!-- Section 2: Upload Tokens -->
  <div class="card">
    <h2>Upload Tokens (<?= htmlspecialchars($orgName, ENT_QUOTES) ?> &mdash; <?= htmlspecialchars($eventDate, ENT_QUOTES) ?>)</h2>
    <?php if (empty($tokens)): ?>
      <p class="muted">No tokens generated yet.</p>
    <?php else: ?>
      <div class="table-scroll">
      <table>
        <thead>
          <tr><th>Token (hash prefix)</th><th>Expires</th><th>Status</th><th>Created</th><th>Action</th></tr>
        </thead>
        <tbody>
          <?php
          $now = new \DateTime('now');
          foreach ($tokens as $tok):
            $isActive  = (bool)$tok['is_active'];
            $expiry    = new \DateTime($tok['expires_at']);
            $isExpired = $expiry <= $now;
            if (!$isActive) {
                $status = 'revoked';
            } elseif ($isExpired) {
                $status = 'expired';
            } else {
                $status = 'active';
            }
            $isWarn = ($status !== 'active');
          ?>
          <tr <?= $isWarn ? 'class="warn-row"' : '' ?>>
            <td><code><?= htmlspecialchars(substr($tok['token_hash'], 0, 8), ENT_QUOTES) ?>…</code></td>
            <td><?= htmlspecialchars($tok['expires_at'], ENT_QUOTES) ?></td>
            <td><span class="badge badge-<?= $status ?>"><?= ucfirst($status) ?></span></td>
            <td><?= htmlspecialchars($tok['created_at'], ENT_QUOTES) ?></td>
            <td>
              <?php if ($status === 'active'): ?>
                <form method="POST"
                      action="/admin/event_qr.php?org_name=<?= urlencode($orgName) ?>&event_date=<?= urlencode($eventDate) ?>"
                      style="display:inline">
                  <input type="hidden" name="action" value="revoke">
                  <input type="hidden" name="token_id" value="<?= (int)$tok['token_id'] ?>">
                  <button type="submit" class="btn-danger btn-sm"
                          onclick="return confirm('Revoke this token? Guests with this QR link will no longer be able to upload.')">Revoke</button>
                </form>
              <?php else: ?>
                &mdash;
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      </div>
    <?php endif; ?>
  </div>

  <!-- Section 3: Guest Uploads — Moderation Queue -->
  <div class="card">
    <h2>Guest Uploads &mdash; Moderation Queue (<?= htmlspecialchars($orgName, ENT_QUOTES) ?> &mdash; <?= htmlspecialchars($eventDate, ENT_QUOTES) ?>)</h2>
    <?php if ($postMsg && $postOk !== null && ($action === 'approve_upload' || $action === 'reject_upload')): ?>
      <div class="<?= $postOk ? 'alert-ok' : 'alert-err' ?>"><?= htmlspecialchars($postMsg, ENT_QUOTES) ?></div>
    <?php endif; ?>
    <?php if (empty($guestUploads)): ?>
      <p class="muted">No guest uploads yet.</p>
    <?php else: ?>
      <div class="table-scroll">
      <table>
        <thead>
          <tr><th>Display name</th><th>Uploaded</th><th>Label</th><th>Token</th><th>Moderation</th><th>Preview</th><th>Action</th></tr>
        </thead>
        <tbody>
          <?php foreach ($guestUploads as $gu):
            $tokenActive  = (bool)$gu['token_active'];
            $tokenExpiry  = new \DateTime($gu['expires_at']);
            $tokenExpired = $tokenExpiry <= $now;
            $tokenStatus  = !$tokenActive ? 'revoked' : ($tokenExpired ? 'expired' : 'active');
            $isWarnRow    = ($tokenStatus !== 'active');
            $displayName  = $gu['display_name'] !== null
                ? htmlspecialchars($gu['display_name'], ENT_QUOTES)
                : '<em>(anonymous)</em>';
            $modStatus    = $gu['moderation_status'] ?? null;
            $modClass     = $modStatus === 'approved' ? 'badge-mod-approved'
                          : ($modStatus === 'rejected' ? 'badge-revoked' : 'badge-mod-pending');
            $modLabel     = $modStatus !== null ? ucfirst($modStatus) : 'Pending';
            $flagged      = !empty($gu['guest_flagged']);
            $fileRelpath  = $gu['file_relpath'] !== null ? $gu['file_relpath'] : null;
          ?>
          <tr <?= $isWarnRow ? 'class="warn-row"' : '' ?>>
            <td><?= $displayName ?></td>
            <td><?= htmlspecialchars((string)($gu['created_at'] ?? ''), ENT_QUOTES) ?></td>
            <td><?= $gu['label'] !== null ? htmlspecialchars((string)$gu['label'], ENT_QUOTES) : '<em class="muted">&mdash;</em>' ?></td>
            <td><span class="badge badge-<?= $tokenStatus ?>"><?= ucfirst($tokenStatus) ?></span></td>
            <td>
              <span class="badge <?= $modClass ?>"><?= $modLabel ?></span>
              <?php if ($flagged): ?><br><span style="color:#f59e0b;font-size:.78rem">&#9873; Guest report</span><?php endif; ?>
            </td>
            <td>
              <?php if ($fileRelpath !== null): ?>
                <a href="/<?= htmlspecialchars($fileRelpath, ENT_QUOTES) ?>" target="_blank">&#9654; Preview</a>
              <?php else: ?>
                &mdash;
              <?php endif; ?>
            </td>
            <td>
              <?php if ($modStatus === 'pending' || $modStatus === null): ?>
                <form method="POST"
                      action="/admin/event_qr.php?org_name=<?= urlencode($orgName) ?>&event_date=<?= urlencode($eventDate) ?>"
                      style="display:inline">
                  <input type="hidden" name="action" value="approve_upload">
                  <input type="hidden" name="job_id" value="<?= htmlspecialchars((string)($gu['job_id'] ?? ''), ENT_QUOTES) ?>">
                  <button type="submit" class="btn-sm" style="border-color:#1f7a3b">Approve</button>
                </form>
                <form method="POST"
                      action="/admin/event_qr.php?org_name=<?= urlencode($orgName) ?>&event_date=<?= urlencode($eventDate) ?>"
                      style="display:inline;margin-left:.25rem">
                  <input type="hidden" name="action" value="reject_upload">
                  <input type="hidden" name="job_id" value="<?= htmlspecialchars((string)($gu['job_id'] ?? ''), ENT_QUOTES) ?>">
                  <button type="submit" class="btn-sm btn-danger"
                          onclick="return confirm('Reject this upload?')">Reject</button>
                </form>
              <?php else: ?>
                &mdash;
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      </div>
    <?php endif; ?>
  </div>

  <?php endif; // eventId ?>

  <div class="license-footer">
    <p class="muted" style="font-size:.8rem">
      GigHive is dual-licensed. <a href="https://gighive.app" target="_blank">Licensing info</a>
    </p>
  </div>
</div>

<script>
(function() {
  const qrUrl = <?= json_encode($newQrUrl) ?>;
  if (!qrUrl) return;
  const wrap    = document.getElementById('qr-wrap');
  const inner   = document.getElementById('qr-inner');
  const urlText = document.getElementById('qr-url-text');
  if (!wrap || !inner) return;
  wrap.style.display = 'block';
  if (urlText) urlText.textContent = qrUrl;
  new QRCode(inner, {
    text: qrUrl,
    width: 256,
    height: 256,
    colorDark: '#000000',
    colorLight: '#ffffff',
    correctLevel: QRCode.CorrectLevel.H
  });
  const dlBtn = document.getElementById('qr-download-btn');
  if (dlBtn) {
    dlBtn.addEventListener('click', function() {
      const img = inner.querySelector('img');
      if (!img) return;
      const a = document.createElement('a');
      a.download = 'guest-upload-qr.png';
      a.href = img.src;
      a.click();
    });
  }
})();
</script>
</body>
</html>
