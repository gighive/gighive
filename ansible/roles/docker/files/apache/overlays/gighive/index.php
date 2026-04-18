<?php
// index.php — public home page for GigHive (or your project name)
$user = $_SERVER['PHP_AUTH_USER']
    ?? $_SERVER['REMOTE_USER']
    ?? $_SERVER['REDIRECT_REMOTE_USER']
    ?? null;
$passwordsChanged = isset($_GET['passwords_changed']) && $_GET['passwords_changed'] === '1';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>Welcome Gighivers!</title>
  <!-- Favicons -->
  <link rel="icon" href="/images/icons/favicon.ico" sizes="any">
  <link rel="icon" type="image/png" href="/images/icons/favicon-16.png" sizes="16x16">
  <link rel="icon" type="image/png" href="/images/icons/favicon-32.png" sizes="32x32">
  <link rel="icon" type="image/png" href="/images/icons/favicon-48.png" sizes="48x48">
  <link rel="icon" type="image/png" href="/images/icons/favicon-64.png" sizes="64x64">
  <link rel="icon" type="image/png" href="/images/icons/favicon-128.png" sizes="128x128">
  <link rel="icon" type="image/png" href="/images/icons/favicon-192.png" sizes="192x192">
  <link rel="apple-touch-icon" href="/images/icons/apple-touch-icon.png" sizes="180x180">
  <?php include __DIR__ . '/includes/ga_tag.php'; ?>
  <style>
    :root { font-family: system-ui, -apple-system, Segoe UI, Roboto, sans-serif; }
    body { margin:0; background:#0b1020; color:#e9eef7; }
    .wrap { max-width: 1350px; margin: 0 auto; padding: 2rem; }
    .card { background: #121a33; border: 1px solid #1d2a55; border-radius: 16px; padding: 2rem; }
    a.btn { display: inline-block; padding: .9rem 1.2rem; border-radius: 10px; text-decoration: none; border: 1px solid #3b82f6; }

    /* Fix spacing issues */
    h1, h2, h3 { margin-top: 0; margin-bottom: 0.5rem; line-height: 1.2; }
    .site-title { display: flex; align-items: center; gap: 0.5rem; }
    .site-title img { height: 200px; margin: 0; display: block; }

    /* hyperlink colors */
    a:link    { color: cyan; }   /* unvisited */
    a:visited { color: #00BFFF; }   /* visited */
    
    /* User indicator styling */
    .user-indicator { font-size: 12px; color: #666; margin: 0.5rem 0; padding-left: 2rem; }

    /* Media card grid */
    .media-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(130px, 300px));
      gap: 16px;
      margin: 1.2rem 0 1.5rem;
    }
    .media-card {
      background-color: #1a2347;
      border: 1px solid #2a3560;
      border-radius: 8px;
      overflow: hidden;
      text-align: center;
      transition: border-color 0.3s ease;
    }
    .media-card:hover { border-color: #2196F3; }
    .media-card > a { display: block; text-decoration: none; }
    .media-card img { width: 100%; height: auto; display: block; }
    .media-card .card-caption {
      padding: 10px 12px;
      color: #ccc;
      font-size: 0.875em;
      line-height: 1.5;
    }
    .media-card:hover .card-caption { color: white; }
    .credentials-box {
      display: inline-block;
      background-color: #1a2347;
      border: 1px solid #2a3560;
      border-radius: 6px;
      padding: 6px 14px;
      margin: 0.4rem 0 0.8rem;
      font-family: monospace;
      font-size: 0.9em;
      color: #ccc;
    }
  </style>
</head>
<body>
  <?php if ($user): ?>
  <div class="user-indicator">User is logged in as <?= htmlspecialchars($user, ENT_QUOTES) ?></div>
  <?php endif; ?>
  <?php if ($passwordsChanged): ?>
  <div class="user-indicator">Passwords changed</div>
  <?php endif; ?>
  <div class="wrap">
    <div class="card">
      <h1 class="site-title">
        Welcome to GigHive!
        <img src="images/beelogo.png"
             alt="GigHive bee mascot holding a camera and microphone"
             style="height:224px; vertical-align:middle; margin-left:.5rem;">
      </h1>

      <h2>Upload, organize, and stream your media.</h2>
      <ul>
        <li><strong>If you're a musician:</strong> Use GigHive as a library for your band's sessions (audio and video) and let your fans upload footage from every angle of the gig.</li>
        <li><strong>If you're a wedding photographer/videographer:</strong> Have guests upload their audio and video during the event, incorporate it into a compilation, then spin down the instance when you're done to save money.</li>
        <li><strong>If you're a media librarian or have a preexisting cache of files:</strong> Use the <a href="https://gighive.app/images/adminUtilities.png">Admin Utilities</a> to import your videos and build your own historical GigHive.</li>
        <li><strong>If you just need a simple self-hosted web server:</strong> Drop PHP files or static content into the default web root and off you go.</li>
        <li>GigHive is <a href="https://github.com/gighive/gighive">open source</a> — a self-hosted media library with a searchable <a href="https://gighive.app/images/databaseErd.png">database</a>, an <a href="db/upload_form.php">upload utility</a>, and an <a href="https://apps.apple.com/us/app/gighive-upload-music-video/id6753146513">iPhone app</a>.</li>
      </ul>

      <div class="media-grid">
        <div class="media-card">
          <a href="https://gighive.app/images/mediaLibraryCustom.png">
            <img src="https://gighive.app/images/mediaLibraryCustom.png" alt="Customer database example">
            <div class="card-caption">Customer Example</div>
          </a>
        </div>
        <div class="media-card">
          <a href="https://staging.gighive.app/db/database.php">
            <img src="https://gighive.app/images/gighiveMediaLibrary.png" alt="Interactive media library example">
            <div class="card-caption">Interactive Example ↗</div>
          </a>
        </div>
      </div>

      <ul>
        <li>Live example: <a href="https://www.stormpigs.com">stormpigs.com</a></li>
      </ul>
      <div class="credentials-box">u: guest &nbsp;&nbsp;|&nbsp;&nbsp; p: stormpigsguestuser1234!</div>

      <h3>Get Started</h3>
      <ul>
        <li>Based on Ubuntu, you spin up the website using bash scripts and host it either in your network or in Azure, fully automated through Ansible (and Terraform for Azure). See the <a href="https://gighive.app/README.html">Setup Guide</a> or watch the quickstart videos below:</li>
      </ul>

      <div class="media-grid">
        <div class="media-card">
          <a href="https://staging.gighive.app/video/7a5bc7d5d22c7f2778656c5a8880a2c11595901665196fa8f89dfbfe10ec6f98.mp4">
            <img src="https://staging.gighive.app/video/thumbnails/7a5bc7d5d22c7f2778656c5a8880a2c11595901665196fa8f89dfbfe10ec6f98.png" alt="GigHive quickstart installation video for Linux">
            <div class="card-caption">Quickstart Installation (Linux)</div>
          </a>
        </div>
        <div class="media-card">
          <a href="https://staging.gighive.app/video/1755278dbc8240fe9e2ff502c0ef4d5d9cd662ca581f6c59511b1f4fce9b07b8.mp4">
            <img src="https://staging.gighive.app/video/thumbnails/1755278dbc8240fe9e2ff502c0ef4d5d9cd662ca581f6c59511b1f4fce9b07b8.png" alt="GigHive quickstart installation video for Mac">
            <div class="card-caption">Quickstart Installation (Mac)<br><a href="https://docs.docker.com/desktop/setup/install/mac-install/" style="color: #2196F3; font-size: 0.85em;">How to install Docker Desktop on Mac</a></div>
          </a>
        </div>
        <div class="media-card">
          <div style="aspect-ratio: 16/9; background-color: #0d1530; display: flex; align-items: center; justify-content: center; color: #555; font-size: 0.9em;">Video Coming Soon</div>
          <div class="card-caption">Quickstart Installation (Windows)<br><a href="https://docs.docker.com/desktop/setup/install/windows-install/" style="color: #2196F3; font-size: 0.85em;">How to install Docker Desktop on Windows</a></div>
        </div>
      </div>

      <h3>Requirements for Quickstart</h3>
      <ul>
        <li>Most flavors of Linux x86/64 (Tested on Ubuntu 24.04, 22.04 and Mac Sequoia 15.6.1) and Windows 10 or above running Docker.</li>
        <li><a href="https://gighive.app/setup_instructions_quickstart.html">Quickstart Instructions</a></li>
      </ul>

      <h3>Why self-host?</h3>
      <ul>
        <li>This site is for do-it-yourselfers who don't want to be beholden to Big Tech but be the masters of their own destiny.</li>
        <li>GigHive frees you from the content limitations that the major providers set..but make sure you have enough disk for all your media files.</li>
      </ul>

      <h3>iPhone App</h3>
      <ul>
        <li>We have an easy-to-use iPhone app for fans and wedding guests. <a href="https://apps.apple.com/us/app/gighive-upload-music-video/id6753146513">Download it here</a></li>
      </ul>

      <h3>Features</h3>
      <ul>
        <li><a href="https://gighive.app/mediaFormatsSupported.html">Supported media formats.</a></li>
        <li>GigHive is simple. There is a home page, a page for the <a href="db/database.php">media library</a> and batch or single file <a href="db/upload_form.php">upload utilities</a>.</li>
        <li>It is <a href="https://gighive.app/SECURITY.html">secure by default</a> and was built from the ground up to live behind the <a href="https://www.cloudflare.com">Cloudflare shield</a>.</li>
      </ul>

      <p><a href="https://gighive.app/README.html" style="display: inline-block; background-color: #2196F3; color: white; padding: 12px 24px; text-decoration: none; border-radius: 6px; font-weight: bold; margin: 8px 4px; transition: background-color 0.3s;">View the README</a> <a href="https://gighive.app/PREREQS.html" style="display: inline-block; background-color: #4CAF50; color: white; padding: 12px 24px; text-decoration: none; border-radius: 6px; font-weight: bold; margin: 8px 4px; transition: background-color 0.3s;">Parts List</a></p>
      <p><a class="btn" href="/db/database.php">Database View (login required)</a></p>
      <p>Note the database is pre-populated with sample media that you can delete using the link below.</p>
      <p><a class="btn" href="/admin/admin.php">Admin Functions (Change Passwords / Data Loading)</a></p>

      <h3>License / Policy</h3>
      GigHive is dual-licensed:
      <ul>
        <li><a href="https://gighive.app/LICENSE_AGPLv3.html">AGPL v3 License</a>: Open source, free for personal use with strong copyleft protection for use as a SaaS.</li>
        <li><a href="https://gighive.app/LICENSE_COMMERCIAL.html">Commercial License</a>: Required for SaaS, multi-tenant, or commercial use.</li>
        <li><a href="https://gighive.app/gighive_content_policy.html">Content Policy</a>: Please read and understand your responsibilities as an operator.</li>
      </ul>

      <h3>Contact Us</h3>
      <p>👉 <a href="mailto:contactus@gighive.app">Contact us</a> for commercial licensing or for any other questions regarding GigHive. <img src="images/beelogo.png" alt="GigHive bee mascot" style="height: 4em; vertical-align: middle;"></p>
    </div>
  </div>
</body>
</html>

