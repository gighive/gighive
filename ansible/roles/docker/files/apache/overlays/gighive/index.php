<?php
// index.php — public home page for GigHive (or your project name)
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
  </style>
</head>
<body>
  <div class="wrap">
    <div class="card">
      <h1 class="site-title">
        Welcome to GigHive!
        <img src="images/beelogo.png"
             alt="GigHive bee mascot holding a camera and microphone"
             style="height:224px; vertical-align:middle; margin-left:.5rem;">
      </h1>

      <h2>Gighive is a media database for you, your fans, or wedding guests.</h2>
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

      <h3>Why not just use YouTube?</h3>
      <ul>
      <li>This site is for do-it-yourselfers who don't want to be beholden to Big Tech but be the masters of their own destiny.</li>
      <li>Also, using Gighive frees you up from content limitations on the major providers..but plan out how you size your VM.</li>
      <li>With build targets such as Azure, virtualbox or bare metal, you have your choice on how to deploy Gighive.</li> 
      <li>And although it is <a href="SECURITY.html">secure by default</a>, Gighive was built from the ground up to live behind the <a href="https://www.cloudflare.com">Cloudflare shield</a>.</li>
      <li>Last but not least, Gighive is simple.  There is one page for the <a href="db/database.php">media library</a> and one <a href="db/upload_form.php">upload utility</a>..that's all.</li>
      </ul>

      <h3>How do I get started?</h3>
      <ul>
      <li>Gighive includes a searchable, sortable one-page listing of media files and common attributes (date, location, people, rating, etc) stored <a href="images/databaseErd.png">in the database</a> along with an <a href="db/upload_form.php">upload utility</a>.</li> 
      </ul>

      <h3>Media formats supported</h3>
      <ul>
      <li>Audio formats: MP3 (audio/mpeg, audio/mp3), WAV (audio/wav, audio/x-wav), AAC (audio/aac), FLAC (audio/flac), MP4 Audio (audio/mp4)</li>
      <li>Video formats: MP4 (video/mp4), QuickTime/MOV (video/quicktime), Matroska/MKV (video/x-matroska), WebM (video/webm), AVI (video/x-msvideo)</li>
      </ul>

      <h3>So give Gighive a try! For those with a bit of unix and command line experience, it will be a breeze to setup!</h3>
      <p><a href="README.html" style="display: inline-block; background-color: #2196F3; color: white; padding: 12px 24px; text-decoration: none; border-radius: 6px; font-weight: bold; margin: 8px 4px; transition: background-color 0.3s;">View the README</a> <a href="PREREQS.html" style="display: inline-block; background-color: #4CAF50; color: white; padding: 12px 24px; text-decoration: none; border-radius: 6px; font-weight: bold; margin: 8px 4px; transition: background-color 0.3s;">Parts List</a></p>
      <p><a class="btn" href="/db/database.php">Go to the Database (login required)</a></p>
      <p><a class="btn" href="/changethepasswords.php">Change Passwords (Admin Only)</a></p>
      <p>Note the database is currently populated with sample data that <a href="comingsoon.html">you can delete</a>.</p>

      <h3>For the future</h3>
      <ul>
      <li>Eventually, we will develop more interesting features. But for now, we've keeping it simple and easy to manage.</li>
      </ul>

<h3>License</h3>
GigHive is dual-licensed:
<ul>
<li><b>[MIT License](LICENSE_MIT.md)</b>: Free for personal, single-instance, non-commercial use.</li>
<li><b>[Commercial License](LICENSE_COMMERCIAL.md)</b>: Required for SaaS, multi-tenant, or commercial use.</li>
</ul>
👉 [Contact us](mailto:contactus@gighive.app) for commercial licensing or for any other questions regarding Gighive. <img src="images/beelogo.png" alt="GigHive bee mascot" style="height: 1em; vertical-align: middle;">
    </div>
  </div>
</body>
</html>

