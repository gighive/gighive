<?php /** @var array $media */ ?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Playing Random Media</title>
  <style>
    body{font-family:Arial,sans-serif;text-align:center;margin:20px}
    h1{margin-bottom:20px}
    p{font-size:16px;color:gray}
    video,audio{margin-top:20px;max-width:100%}
  </style>
  <script>
    function playNextMedia(){ window.location.reload(); }
  </script>
</head>
<body>
<?php
  $file = (string)($media['file_name'] ?? '');
  $url  = (string)($media['url'] ?? '');
  $crew = (string)($media['crew'] ?? '');
  $date = (string)($media['date'] ?? '');
  $ext  = strtolower(pathinfo($file, PATHINFO_EXTENSION));
?>
  <h1>Now Playing: <?= htmlspecialchars($file, ENT_QUOTES) ?></h1>
  <p>Full HREF: <strong><?= htmlspecialchars($url, ENT_QUOTES) ?></strong></p>
  <p>Crew: <strong><?= htmlspecialchars($crew, ENT_QUOTES) ?></strong></p>
  <p>Date: <strong><?= htmlspecialchars($date, ENT_QUOTES) ?></strong></p>

  <?php if ($ext === 'mp4'): ?>
    <video controls autoplay onended="playNextMedia()">
      <source src="<?= htmlspecialchars($url, ENT_QUOTES) ?>" type="video/mp4">
      Your browser does not support the video tag.
    </video>
  <?php elseif ($ext === 'mp3'): ?>
    <audio controls autoplay onended="playNextMedia()">
      <source src="<?= htmlspecialchars($url, ENT_QUOTES) ?>" type="audio/mpeg">
      Your browser does not support the audio tag.
    </audio>
  <?php else: ?>
    <p>Unsupported media format: <?= htmlspecialchars($file, ENT_QUOTES) ?></p>
  <?php endif; ?>
</body>
</html>
