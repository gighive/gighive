<?php
// Load the cached file list
$cacheFilePath = '/tmp/stormpigs_cache.json';
if (!file_exists($cacheFilePath)) {
    die('Error: Cache file not found. Please run the randomizer first.');
}

// Retrieve and decode the cached file list
$mediaFiles = json_decode(file_get_contents($cacheFilePath), true);
if (empty($mediaFiles) || !is_array($mediaFiles)) {
    die('Error: Invalid or empty cache file.');
}

// Randomly select a media file
$randomIndex = array_rand($mediaFiles);
$selectedMedia = $mediaFiles[$randomIndex];

// Validate the selected media structure
if (!is_array($selectedMedia) || !isset($selectedMedia['file'])) {
    die('Error: Selected media is not valid.');
}

// Extract media information
$mediaPath = $selectedMedia['file'];
$crewMerged = $selectedMedia['crew_merged'] ?? 'Unknown Crew';
$dateOnly = $selectedMedia['d_date_only'] ?? 'Unknown Date';
$mediaFileName = basename($mediaPath);
$mediaHref = htmlspecialchars($mediaPath);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Playing Random Media</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            text-align: center;
            margin: 20px;
        }
        h1 {
            margin-bottom: 20px;
        }
        p {
            font-size: 16px;
            color: gray;
        }
        video, audio {
            margin-top: 20px;
            max-width: 100%;
        }
    </style>
    <script>
        function playNextMedia() {
            window.location.reload();
        }
    </script>
</head>
<body>
    <h1>Now Playing: <?php echo $mediaFileName; ?></h1>
    <p>Full HREF: <strong><?php echo $mediaHref; ?></strong></p>
    <p>Crew: <strong><?php echo htmlspecialchars($crewMerged); ?></strong></p>
    <p>Date: <strong><?php echo htmlspecialchars($dateOnly); ?></strong></p>

    <?php if (str_ends_with($mediaPath, '.mp4')): ?>
        <video controls autoplay onended="playNextMedia()">
            <source src="<?php echo $mediaHref; ?>" type="video/mp4">
            Your browser does not support the video tag.
        </video>
    <?php elseif (str_ends_with($mediaPath, '.mp3')): ?>
        <audio controls autoplay onended="playNextMedia()">
            <source src="<?php echo $mediaHref; ?>" type="audio/mpeg">
            Your browser does not support the audio tag.
        </audio>
    <?php else: ?>
        <p>Unsupported media format: <?php echo $mediaFileName; ?></p>
    <?php endif; ?>
</body>
</html>
