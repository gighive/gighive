<?php declare(strict_types=1);
header('Cache-Control: no-store');

require_once __DIR__ . '/vendor/autoload.php';

use Production\Api\Infrastructure\Database;

// --- Nonce validation (shared by GET and POST paths) ---
$rawNonce = $_POST['nonce'] ?? $_GET['nonce'] ?? '';
$nonceValid = preg_match('/^[A-Za-z0-9_\-]{30,43}$/', $rawNonce) === 1;
$safeNonce  = $nonceValid ? $rawNonce : '';

// --- Handle report POST (POST–Redirect–GET pattern) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'report') {
    if (!$nonceValid) {
        http_response_code(400);
        exit;
    }
    $targetJobId = filter_var($_POST['upload_job_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $reportResult = 'err';
    if ($targetJobId !== false) {
        try {
            $pdoR = Database::createFromEnv();
            $stmtR = $pdoR->prepare(
                'SELECT t.event_id
                 FROM anon_upload_attributions a
                 JOIN upload_jobs j_mine ON j_mine.job_id = a.upload_job_id
                 JOIN event_upload_tokens t ON t.token_id = a.token_id
                 WHERE a.status_nonce = ? AND j_mine.moderation_status = \'approved\''
            );
            $stmtR->execute([$safeNonce]);
            $rowR = $stmtR->fetch(\PDO::FETCH_ASSOC);
            if ($rowR !== false) {
                $eventIdR = (int)$rowR['event_id'];
            } else {
                $tokenHash = hash('sha256', $safeNonce);
                $stmtTok = $pdoR->prepare(
                    'SELECT t.event_id FROM event_upload_tokens t
                     WHERE t.token_hash = ? AND t.is_active = 1 AND t.expires_at > NOW()'
                );
                $stmtTok->execute([$tokenHash]);
                $rowTok   = $stmtTok->fetch(\PDO::FETCH_ASSOC);
                $eventIdR = $rowTok !== false ? (int)$rowTok['event_id'] : null;
            }
            if ($eventIdR !== null) {
                $stmtFlag = $pdoR->prepare(
                    'UPDATE upload_jobs j_target
                     JOIN anon_upload_attributions a2 ON a2.upload_job_id = j_target.job_id
                     JOIN event_upload_tokens t2 ON t2.token_id = a2.token_id
                     SET j_target.guest_flagged = 1, j_target.guest_flagged_at = NOW()
                     WHERE j_target.id = ? AND j_target.moderation_status = \'approved\'
                       AND t2.event_id = ?'
                );
                $stmtFlag->execute([$targetJobId, $eventIdR]);
                if ($stmtFlag->rowCount() > 0) {
                    $reportResult = 'ok';
                }
            }
        } catch (\Throwable $e) { /* ignore — redirect to err */ }
    }
    header('Location: /guest_event_view.php?nonce=' . urlencode($safeNonce) . '&report=' . $reportResult);
    exit;
}

// --- Validate nonce before DB use ---
if (!$nonceValid) {
    http_response_code(403);
    ?><!doctype html>
<html lang="en">
<head><meta charset="utf-8"><title>Access Denied</title>
<style>body{font-family:system-ui,sans-serif;background:#0b1020;color:#e9eef7;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0}
.box{text-align:center;padding:2rem}</style></head>
<body><div class="box"><h1>403 Forbidden</h1><p>This gallery link is invalid or has expired.</p></div></body>
</html>
<?php
    exit;
}

// --- DB connection ---
try {
    $pdo = Database::createFromEnv();
} catch (\Throwable $e) {
    http_response_code(500);
    exit;
}

// --- Step 1: verify nonce's own upload is approved and gallery is not expired ---
try {
    $stmt = $pdo->prepare(
        'SELECT t.event_id, t.expires_at, e.org_name, e.event_date
         FROM anon_upload_attributions a
         JOIN upload_jobs j ON j.job_id = a.upload_job_id
         JOIN event_upload_tokens t ON t.token_id = a.token_id
         JOIN events e ON e.event_id = t.event_id
         WHERE a.status_nonce = ? AND j.moderation_status = \'approved\''
    );
    $stmt->execute([$safeNonce]);
    $meta = $stmt->fetch(\PDO::FETCH_ASSOC);
} catch (\PDOException $e) {
    http_response_code(500);
    exit;
}

if ($meta === false) {
    try {
        $tokenHash = hash('sha256', $safeNonce);
        $stmtTok = $pdo->prepare(
            'SELECT t.event_id, t.expires_at,
                    e.org_name, e.event_date
             FROM event_upload_tokens t
             JOIN events e ON e.event_id = t.event_id
             WHERE t.token_hash = ? AND t.is_active = 1 AND t.expires_at > NOW()'
        );
        $stmtTok->execute([$tokenHash]);
        $meta = $stmtTok->fetch(\PDO::FETCH_ASSOC);
    } catch (\PDOException $e) {
        http_response_code(500);
        exit;
    }
}
if ($meta === false) {
    http_response_code(403);
    ?><!doctype html>
<html lang="en">
<head><meta charset="utf-8"><title>Access Denied</title>
<style>body{font-family:system-ui,sans-serif;background:#0b1020;color:#e9eef7;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0}
.box{text-align:center;padding:2rem}</style></head>
<body><div class="box"><h1>403 Forbidden</h1><p>Your video has not been approved yet, or this link is invalid.</p><p style="color:#a8b3cf;font-size:.9rem">Please return to the GigHive app to check your approval status.</p></div></body>
</html>
<?php
    exit;
}

$eventId       = (int)$meta['event_id'];
$tokenExpiry   = new \DateTime($meta['expires_at']);
$now           = new \DateTime('now');
$isExpired     = $tokenExpiry <= $now;
$diff          = $now->diff($tokenExpiry);
$daysRemaining = $isExpired ? 0 : ($diff->days > 3650 ? null : max(0, (int)$diff->days));
$eventTitle = htmlspecialchars($meta['org_name'] . ' \u2014 ' . $meta['event_date'], ENT_QUOTES);

$reportMsg = '';
if (isset($_GET['report'])) {
    $reportMsg = $_GET['report'] === 'ok'
        ? 'Your report has been submitted. Thank you.'
        : 'Report could not be submitted. Please try again.';
}

// --- Step 2: fetch approved videos ---
$videos = [];
if (!$isExpired) {
    try {
        $stmt = $pdo->prepare(
            'SELECT j.id AS upload_job_id, j.label, j.file_relpath, j.approved_at,
                    a.display_name
             FROM upload_jobs j
             JOIN anon_upload_attributions a ON a.upload_job_id = j.job_id
             JOIN event_upload_tokens t ON t.token_id = a.token_id
             WHERE t.event_id = ? AND j.moderation_status = \'approved\'
             ORDER BY j.started_at ASC'
        );
        $stmt->execute([$eventId]);
        $videos = $stmt->fetchAll(\PDO::FETCH_ASSOC);
    } catch (\PDOException $e) { /* render empty */ }
}

?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?= $eventTitle ?> &mdash; Event Gallery</title>
  <style>
    :root { font-family: system-ui, -apple-system, Segoe UI, Roboto, sans-serif; }
    body { margin: 0; background: #0b1020; color: #e9eef7; }
    .wrap { max-width: 900px; margin: 2rem auto; padding: 1rem; }
    h1 { margin: 0 0 .25rem; font-size: 1.5rem; }
    .subtitle { color: #a8b3cf; font-size: .9rem; margin: 0 0 1.5rem; }
    .gallery { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 1.25rem; }
    .clip { background: #121a33; border: 1px solid #1d2a55; border-radius: 12px; overflow: hidden; }
    .clip video { width: 100%; display: block; max-height: 280px; background: #000; }
    .clip-meta { padding: .75rem 1rem; }
    .clip-label { font-weight: 600; font-size: .95rem; margin: 0 0 .2rem; }
    .clip-by { color: #a8b3cf; font-size: .82rem; margin: 0 0 .5rem; }
    .btn-report { background: transparent; border: 1px solid #4b5563; color: #a8b3cf; border-radius: 8px;
                  padding: .3rem .75rem; font-size: .8rem; cursor: pointer; }
    .btn-report:hover { border-color: #f59e0b; color: #fbbf24; }
    .alert-ok  { background: #11331a; border: 1px solid #1f7a3b; padding: .75rem 1rem; border-radius: 8px; margin-bottom: 1rem; }
    .alert-err { background: #3b0d14; border: 1px solid #b4232a; padding: .75rem 1rem; border-radius: 8px; margin-bottom: 1rem; }
    .expired-msg { text-align: center; padding: 3rem 1rem; color: #a8b3cf; }
    .empty-msg { text-align: center; padding: 3rem 1rem; color: #a8b3cf; }
    .footer { text-align: center; margin-top: 2rem; color: #4b5563; font-size: .8rem; }
  </style>
</head>
<body>
<div class="wrap">
  <h1><?= $eventTitle ?></h1>
  <?php if ($daysRemaining !== null): ?>
    <p class="subtitle"><?= $daysRemaining > 0
        ? 'Gallery closes in ' . $daysRemaining . ' day' . ($daysRemaining === 1 ? '' : 's')
        : 'This gallery has closed.' ?></p>
  <?php else: ?>
    <p class="subtitle">Shared event gallery</p>
  <?php endif; ?>

  <?php if ($reportMsg !== ''): ?>
    <div class="<?= str_contains($reportMsg, 'submitted') ? 'alert-ok' : 'alert-err' ?>"><?= htmlspecialchars($reportMsg, ENT_QUOTES) ?></div>
  <?php endif; ?>

  <?php if ($isExpired): ?>
    <div class="expired-msg">
      <p style="font-size:2rem">&#128248;</p>
      <p>This gallery is no longer available.</p>
    </div>
  <?php elseif (empty($videos)): ?>
    <div class="empty-msg">
      <p style="font-size:2rem">&#8987;</p>
      <p>No videos have been approved yet &mdash; check back soon.</p>
    </div>
  <?php else: ?>
    <div class="gallery">
      <?php foreach ($videos as $v):
        $src      = '/' . htmlspecialchars((string)$v['file_relpath'], ENT_QUOTES) . '?nonce=' . urlencode($safeNonce);
        $label    = $v['label'] !== null ? htmlspecialchars((string)$v['label'], ENT_QUOTES) : 'Clip';
        $byLine   = $v['display_name'] !== null ? 'by ' . htmlspecialchars((string)$v['display_name'], ENT_QUOTES) : '';
        $jobId    = (int)$v['upload_job_id'];
      ?>
      <div class="clip">
        <video controls preload="metadata" src="<?= $src ?>"></video>
        <div class="clip-meta">
          <p class="clip-label"><?= $label ?></p>
          <?php if ($byLine !== ''): ?><p class="clip-by"><?= $byLine ?></p><?php endif; ?>
          <form method="POST" action="/guest_event_view.php">
            <input type="hidden" name="action" value="report">
            <input type="hidden" name="nonce" value="<?= htmlspecialchars($safeNonce, ENT_QUOTES) ?>">
            <input type="hidden" name="upload_job_id" value="<?= $jobId ?>">
            <button type="submit" class="btn-report"
                    onclick="return confirm('Report this video as inappropriate?')">&#9873; Report</button>
          </form>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <div class="footer">Powered by GigHive &mdash; <a href="https://gighive.app" style="color:#4b5563">gighive.app</a></div>
</div>
</body>
</html>
