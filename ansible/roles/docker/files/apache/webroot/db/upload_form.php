<?php declare(strict_types=1);
// Minimal manual UI for testing Upload API (StormPigs)
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>StormPigs Upload</title>
  <style>
    body { font-family: system-ui, Arial, sans-serif; margin: 2rem; }
    form { max-width: 640px; }
    label { display: block; margin-top: 12px; font-weight: 600; }
    input[type="text"], input[type="date"], input[type="number"], textarea, select { width: 100%; padding: 8px; box-sizing: border-box; }
    input[type="file"] { margin-top: 8px; }
    button { margin-top: 16px; padding: 10px 16px; font-weight: 700; cursor: pointer; }
    .hint { color: #666; font-size: 12px; }
    .legend { color: #666; font-size: 12px; margin-top: 8px; }
    .row { display: flex; align-items: center; gap: 8px; }
    .row label.inline { display: inline; font-weight: 400; margin-top: 0; }
    .admin-link { margin-top: 16px; display: inline-block; font-size: 14px; text-decoration: underline; }
    .user-indicator { font-size: 12px; color: #666; margin-bottom: 8px; }
    /* simple in-flight indicator (no XHR, no percent) */
    #status { margin-top: 12px; font-size: 14px; color: #333; }
    .spinner { display:inline-block; width:14px; height:14px; border:2px solid #999; border-top-color: transparent; border-radius:50%; animation: spin 0.8s linear infinite; margin-left:8px; }
    @keyframes spin { to { transform: rotate(360deg); } }
  </style>
  <!-- This page is under /db/, protected by Basic Auth via Apache LocationMatch -->
</head>
<body>
  <?php
  $user = $_SERVER['PHP_AUTH_USER']
      ?? $_SERVER['REMOTE_USER']
      ?? $_SERVER['REDIRECT_REMOTE_USER']
      ?? 'Unknown';
  ?>
  <div class="user-indicator">User is logged in as <?= htmlspecialchars($user, ENT_QUOTES) ?></div>
  <h1>Upload Media</h1>
  <form id="uploadForm" action="/api/uploads.php" method="POST" enctype="multipart/form-data">
    <label for="file">Media file (audio/video) *</label>
    <input id="file" name="file" type="file" accept="audio/*,video/*" required />

    <label for="event_date">Event date *</label>
    <input id="event_date" name="event_date" type="date" required value="<?= htmlspecialchars(date('Y-m-d'), ENT_QUOTES) ?>" />

    <label for="org_name">Band or wedding party name *</label>
    <input id="org_name" name="org_name" type="text" value="StormPigs" />
    <div class="hint">Band name or wedding short name</div>

    <label for="event_type">Event type *</label>
    <select id="event_type" name="event_type">
      <option value="band" selected>band</option>
      <option value="wedding">wedding</option>
    </select>

    <label for="label">Song title or wedding table / identifier *</label>
    <input id="label" name="label" type="text" placeholder="Song title or wedding table label" required />
    <div class="row">
      <input id="auto_label" type="checkbox" />
      <label class="inline" for="auto_label">Autogenerate label?</label>
    </div>
    <div class="hint">If checked, the label will be set to "Auto YYYY-MM-DD" based on the Event date.</div>

    <button id="btnUpload" type="submit">Upload</button>
    <div class="legend">* = mandatory</div>
    <a class="admin-link" href="/db/upload_form_admin.php">For Admins</a>
    <div id="status" style="display:none;">Uploading…<span class="spinner"></span></div>
  </form>

  <script>
    (function() {
      const autoBox = document.getElementById('auto_label');
      const labelInput = document.getElementById('label');
      const dateInput = document.getElementById('event_date');
      const form = document.getElementById('uploadForm');

      // rolled back: no localStorage persistence

      function ymd(d) {
        const dt = new Date(d);
        if (isNaN(dt.getTime())) return '';
        const m = (dt.getMonth() + 1).toString().padStart(2, '0');
        const day = dt.getDate().toString().padStart(2, '0');
        return dt.getFullYear() + '-' + m + '-' + day;
      }

      function maybeSetAuto() {
        if (autoBox.checked) {
          const v = ymd(dateInput.value || new Date());
          if (v) labelInput.value = 'Auto ' + v;
        }
      }

      autoBox.addEventListener('change', function() {
        if (this.checked) {
          maybeSetAuto();
        }
      });
      dateInput.addEventListener('change', function() {
        if (autoBox.checked) maybeSetAuto();
      });

      form.addEventListener('submit', function(e) {
        e.preventDefault();
        const lbl = (labelInput.value || '').trim();
        if (!lbl) {
          alert('Label is required.');
          labelInput.focus();
          return;
        }
        // show simple in-flight indicator and disable upload button
        try {
          document.getElementById('status').style.display = 'block';
          const btn = document.getElementById('btnUpload');
          if (btn) { btn.disabled = true; btn.textContent = 'Uploading…'; }
        } catch(_) {}
        // submit the form normally
        form.submit();
      });
    })();
  </script>
</body>
</html>
