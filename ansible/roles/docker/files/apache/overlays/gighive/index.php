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
  <title>Welcome</title>
  <!-- Favicons -->
  <link rel="icon" href="/images/icons/favicon.ico" sizes="any">
  <link rel="icon" type="image/png" href="/images/icons/favicon-16.png" sizes="16x16">
  <link rel="icon" type="image/png" href="/images/icons/favicon-32.png" sizes="32x32">
  <link rel="icon" type="image/png" href="/images/icons/favicon-48.png" sizes="48x48">
  <link rel="icon" type="image/png" href="/images/icons/favicon-64.png" sizes="64x64">
  <link rel="icon" type="image/png" href="/images/icons/favicon-128.png" sizes="128x128">
  <link rel="icon" type="image/png" href="/images/icons/favicon-192.png" sizes="192x192">
  <link rel="apple-touch-icon" href="/images/icons/apple-touch-icon.png" sizes="180x180">
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

      <h2>Gighive is a web-accessible media database for you, your fans, or wedding guests.</h2>
      <h3>If you're a musician</h3>
      <ul>
      <li>You can use it as a library of your bands sessions, audio and video files.</li>
      <li>Have your fans upload videos from your gigs and utilize the footage from every conceivable angle.</li> 
      </ul>

      <h3>If you're a wedding photographer</h3>
      <ul>
      <li>You can have your guests upload audio and video files from a wedding that you can incorporate into a compilation video.</li>
      <li>Collect media from everyone during the event, offload it and then spin down the compute instance after you're done, thus saving you money.</li>
      </ul>

      <h3>If you are a media librarian or have a preesisting cache of media files</h3>
      <ul>
      <li>You can edit the default csv file to create your own historical Gighive.</li>
      </ul>

      <h3>Or you just need a web server with basic authentication and security to host files in your own network </h3>
      <ul>
      <li>You can plop php files or static content in the default web root and off you go. </li>
      </ul>

      <h3>What is it?</h3>
      <ul>
      <li>Gighive is an <a href="https://github.com/gighive/gighive">open source website and database</a> that you, your fans or wedding guests can use as temporary or permanent storage for video and audio files. Based on Ubuntu, you spin up the website via bash scripts and host it either in your network or in Azure. It is fully automated through a combination of Ansible and Terraform.</li>
      </ul>

      <h3>Why not just use YouTube?</h3>
      <ul>
      <li>This site is for do-it-yourselfers who don’t want to be beholden to Big Tech but be the masters of their own destiny.</li>
      <li>With build targets such as Azure or virtualbox, you have your choice on how to deploy Gighive.</li>
      <li>Gighive frees you from content limitations on the major providers..but you’ll need to size your vm properly.</li>
      <li>It is <a href="https://gighive.app/SECURITY.html">secure by default</a> and was built from the ground up to live behind the <a href="https://www.cloudflare.com">Cloudflare shield</a>.</li>
      <li>Last but not least, Gighive is simple.  There is one page for the home page, a page for the <a href="db/database.php">media library</a> and a page for the <a href="db/upload_form.php">upload utility</a>..that's all.</li>
      </ul>

      <h3>Requirements</h3>
      <ul>
      <li>Control Machine: Tested on Ubuntu 24.04 and 22.04, so the requirements are any flavor of those versions or Pop-OS, installed on bare metal.</li>
      <li>Target Machine: Your choice of virtualbox or Azure deployment targets for the vm and containerized environment.  These are shown in this <a href="images/architecture.png">architecture diagram</a>.</li>
      </ul>

      <h3>What comes with Gighive?</h3>
      <ul>
      <li>Gighive includes a searchable, sortable one-page listing of media files and common attributes (date, location, people, rating, etc) stored <a href="images/databaseErd.png">in the database</a> along with an <a href="db/upload_form.php">upload utility</a>.</li> 
      <li>Common media formats for upload are supported (shown below).</li>
      <li>Please read and be informed about your responsibilities via <a href="https://gighive.app/gighive_content_policy.html">our content policy</a>.</li>
      </ul>

      <h3>Media formats supported</h3>
      <ul>
      <li>Audio formats: MP3 (audio/mpeg, audio/mp3), WAV (audio/wav, audio/x-wav), AAC (audio/aac), FLAC (audio/flac), MP4 Audio (audio/mp4)</li>
      <li>Video formats: MP4 (video/mp4), QuickTime/MOV (video/quicktime), Matroska/MKV (video/x-matroska), WebM (video/webm), AVI (video/x-msvideo)</li>
      <li>Note that HEVC, .MOV and .AVI don't autoplay in the browser</li>
      </ul>

      <h3>So give Gighive a try! For those with a bit of unix and command line experience, it will be a breeze to setup!</h3>
      <p><a href="https://gighive.app/README.html" style="display: inline-block; background-color: #2196F3; color: white; padding: 12px 24px; text-decoration: none; border-radius: 6px; font-weight: bold; margin: 8px 4px; transition: background-color 0.3s;">View the README</a> <a href="https://gighive.app/PREREQS.html" style="display: inline-block; background-color: #4CAF50; color: white; padding: 12px 24px; text-decoration: none; border-radius: 6px; font-weight: bold; margin: 8px 4px; transition: background-color 0.3s;">Parts List</a></p>
      <p><a class="btn" href="/db/database.php">Go to the Database (login required)</a></p>
      <p>Note the database is pre-populated with sample media that you can delete using the link below.</p>
      <p><a class="btn" href="/admin.php">Change Passwords / Remove Sample Data (Admin Only)</a></p>

      <h3>For the future</h3>
      <ul>
      <li>Eventually, we will develop more interesting features. But for now, we've keeping it simple and easy to manage.</li>
      </ul>

<h3>License</h3>
GigHive is dual-licensed:
<ul>
<li><b><a href="https://gighive.app/LICENSE_AGPLv3.html">AGPL v3 License</a></b>: Open source, free for personal use with strong copyleft protection for use as a SaaS.</li>
<li><b><a href="https://gighive.app/LICENSE_COMMERCIAL.html">Commercial License</b>: Required for SaaS, multi-tenant, or commercial use.</li>
</ul>
👉 <a href="mailto:contactus@gighive.app">Contact Us</a> for commercial licensing or for any other questions regarding Gighive. <img src="images/beelogo.png" alt="GigHive bee mascot" style="height: 1em; vertical-align: middle;">
    </div>
  </div>
</body>
</html>

