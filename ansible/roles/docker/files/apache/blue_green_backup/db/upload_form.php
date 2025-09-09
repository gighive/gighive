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
  </style>
  <!-- This page is under /db/, protected by Basic Auth via Apache LocationMatch -->
</head>
<body>
  <h1>Upload Media</h1>
  <form action="/api/uploads.php" method="POST" enctype="multipart/form-data">
    <label for="file">Media file (audio/video) *</label>
    <input id="file" name="file" type="file" accept="audio/*,video/*" required />

    <label for="event_date">Event date *</label>
    <input id="event_date" name="event_date" type="date" required value="<?= htmlspecialchars(date('Y-m-d'), ENT_QUOTES) ?>" />

    <label for="org_name">Organization name *</label>
    <input id="org_name" name="org_name" type="text" value="StormPigs" />
    <div class="hint">Band name or wedding short name</div>

    <label for="event_type">Event type *</label>
    <select id="event_type" name="event_type">
      <option value="band" selected>band</option>
      <option value="wedding">wedding</option>
    </select>

    <label for="label">Label</label>
    <input id="label" name="label" type="text" placeholder="Song title or wedding table label" />

    <label for="participants">Participants</label>
    <input id="participants" name="participants" type="text" placeholder="Comma-separated names" />

    <label for="keywords">Keywords</label>
    <input id="keywords" name="keywords" type="text" placeholder="Comma-separated keywords" />

    <label for="location">Location</label>
    <input id="location" name="location" type="text" />

    <label for="rating">Rating</label>
    <input id="rating" name="rating" type="text" placeholder="1-5 or text" />

    <label for="notes">Notes</label>
    <textarea id="notes" name="notes" rows="3"></textarea>

    <button type="submit">Upload</button>
  </form>
</body>
</html>
