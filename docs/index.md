<style>
body {
    background-color: #121a33;
    color: white;
}

/* Remove default Markdown heading styles and simulate custom headings */
.custom-h1 {
    font-size: 2em;      /* same as h1 */
    font-weight: bold;
    margin: 0.5em 0;
}

.custom-h2 {
    font-size: 1.5em;    /* same as h2 */
    font-weight: normal;
    margin: 0.5em 0;
    color: #ccc;         /* lighter shade for contrast, optional */
}

img {
    background: transparent !important;
}

/* Hamburger Menu Styles */
.hamburger-menu {
    position: fixed;
    top: 20px;
    right: 20px;
    z-index: 1002;
}

.hamburger-icon {
    width: 30px;
    height: 30px;
    cursor: pointer;
    display: flex;
    flex-direction: column;
    justify-content: space-around;
    background: none;
    border: none;
    padding: 0;
}

.hamburger-line {
    width: 100%;
    height: 3px;
    background-color: white;
    transition: all 0.3s ease;
}

.hamburger-icon.active .hamburger-line:nth-child(1) {
    transform: rotate(45deg) translate(6px, 6px);
}

.hamburger-icon.active .hamburger-line:nth-child(2) {
    opacity: 0;
}

.hamburger-icon.active .hamburger-line:nth-child(3) {
    transform: rotate(-45deg) translate(8px, -8px);
}

.nav-menu {
    position: fixed;
    top: 0;
    right: -300px;
    width: 280px;
    height: 100vh;
    background-color: #1a2347;
    border-left: 2px solid #2196F3;
    transition: right 0.3s ease;
    padding: 15px 20px 20px;
    box-shadow: -2px 0 10px rgba(0,0,0,0.3);
    overflow-y: auto;
    z-index: 1001;
    pointer-events: auto;
}

.nav-menu.active {
    right: 0;
}

.nav-menu h3 {
    color: #2196F3 !important;
    margin-bottom: 20px;
    font-size: 1.0em;
    border-bottom: 1px solid #2196F3;
    padding-bottom: 10px;
    pointer-events: none;
}

.nav-menu h3 a.anchor,
.nav-menu h3 a[href^="#"] {
    display: none !important;
    visibility: hidden !important;
    width: 0 !important;
    height: 0 !important;
    opacity: 0 !important;
}

.nav-menu ul {
    list-style: none;
    padding: 0;
    margin: 0;
}

.nav-menu li {
    margin-bottom: 4px;
}

.nav-menu a:not([href^="#"]) {
    color: white !important;
    text-decoration: none;
    display: block;
    padding: 2px 6px;
    border-radius: 4px;
    transition: background-color 0.3s ease;
    cursor: pointer;
    pointer-events: auto;
    font-size: 0.85em;
}

.nav-menu a:hover {
    background-color: #2196F3 !important;
    color: white !important;
}

.nav-menu a:visited {
    color: white !important;
}

.nav-menu a:active {
    color: white !important;
}

.nav-overlay {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background-color: rgba(0,0,0,0.5);
    z-index: 999;
    opacity: 0;
    visibility: hidden;
    transition: all 0.3s ease;
}

.nav-overlay.active {
    opacity: 1;
    visibility: visible;
}

@media (max-width: 768px) {
    .nav-menu {
        width: 100%;
        right: -100%;
    }
}

/* Media card grid */
.media-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
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

.media-card:hover {
    border-color: #2196F3;
}

.media-card > a {
    display: block;
    text-decoration: none;
}

.media-card img {
    width: 100%;
    height: auto;
    display: block;
}

.media-card .card-caption {
    padding: 10px 12px;
    color: #ccc;
    font-size: 0.875em;
    line-height: 1.5;
}

.media-card:hover .card-caption {
    color: white;
}

/* Credentials box */
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

<!-- Hamburger Menu -->
<div class="hamburger-menu">
    <button class="hamburger-icon" onclick="toggleMenu()">
        <div class="hamburger-line"></div>
        <div class="hamburger-line"></div>
        <div class="hamburger-line"></div>
    </button>
</div>

<!-- Navigation Overlay -->
<div class="nav-overlay" onclick="closeMenu()"></div>

<!-- Navigation Menu -->
<nav class="nav-menu">
    <h3>📚 Setup</h3>
    <ul>
        <li><a href="PREREQS.html">📋 Prerequisites</a></li>
        <li><a href="README.html">🚀 Setup Guide</a></li>
        <li><a href="setup_instructions_quickstart.html">⚡ Quickstart Setup</a></li>
    </ul>
    
    <h3>� iPhone App</h3>
    <ul>
        <li><a href="iphone_app.html">� GigHive iPhone App</a></li>
    </ul>
    
    <h3>🎥 Streaming</h3>
    <ul>
        <li><a href="CORE_UPLOAD_IMPLEMENTATION.html">📊 Upload Limits</a></li>
    </ul>
    
    <h3>�️ Database</h3>
    <ul>
        <li><a href="database_load_options.html">� Database Load Options</a></li>
    </ul>
    
    <h3>📄 Legal & Policies</h3>
    <ul>
        <li><a href="gighive_content_policy.html">📋 Content Policy</a></li>
        <li><a href="privacy.html">🔒 Privacy Policy</a></li>
        <li><a href="LICENSE.html">📜 Licenses</a></li>
        <li><a href="APP_TERMS_OF_SERVICE.html">📜 App Terms of Service</a></li>
    </ul>
    
    <h3>🔗 Links</h3>
    <ul>
        <li><a href="mailto:contactus@gighive.app">✉️ Contact Us</a></li>
        <li><a href="https://github.com/gighive/gighive" target="_blank">🐙 GitHub</a></li>
    </ul>

    <h3>🔮 API Reference</h3>
    <ul>
        <li><a href="API_CURRENT_STATE.html">📊 API Current State</a></li>
        <li><a href="images/requestFlowBasic.png">🔄 Request Flow</a></li>
        <li><a href="https://staging.gighive.app/docs/api-docs.html" target="_blank">📋 Swagger</a></li>
    </ul>
    
    <h3>🔮 Advanced / Internals</h3>
    <ul>
        <li><a href="ANSIBLE_FILE_INTERACTION.html">📋 Ansible Core Files</a></li>
        <li><a href="docker_versions_mixing_hostvm.html">🔄 Mixing Host/Container Versions</a></li>
        <li><a href="SECURITY.html">🔒 Security</a></li>
        <li><a href="UPLOAD_OPTIONS.html">⭐ SSL Certs and Upload Options</a></li>
    </ul>
    
</nav>

<script>
function toggleMenu() {
    const hamburger = document.querySelector('.hamburger-icon');
    const menu = document.querySelector('.nav-menu');
    const overlay = document.querySelector('.nav-overlay');
    
    hamburger.classList.toggle('active');
    menu.classList.toggle('active');
    overlay.classList.toggle('active');
}

function closeMenu() {
    const hamburger = document.querySelector('.hamburger-icon');
    const menu = document.querySelector('.nav-menu');
    const overlay = document.querySelector('.nav-overlay');
    
    hamburger.classList.remove('active');
    menu.classList.remove('active');
    overlay.classList.remove('active');
}

// Close menu when pressing Escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeMenu();
    }
});

// Remove anchor links from nav menu headings
document.addEventListener('DOMContentLoaded', function() {
    const navMenu = document.querySelector('.nav-menu');
    if (navMenu) {
        const anchorLinks = navMenu.querySelectorAll('h3 a.anchor, h3 a[href^="#"]');
        anchorLinks.forEach(link => link.remove());
    }
});
</script>

<div class="custom-h1">Welcome to GigHive!</div>
<div class="custom-h2">Upload, organize, and stream your media.</div>
- **If you're a musician:** Use Gighive as a library for your band's sessions (audio and video) and let your fans upload footage from every angle of the gig.
- **If you're a wedding photographer/videographer:** Have guests upload their audio and video during the event, incorporate it into a compilation, then spin down the instance when you're done to save money.
- **If you're a media librarian or have a preexisting cache of files:** Use the [Admin Utilities](/images/adminUtilities.png) to import your videos and build your own historical Gighive.
- **If you just need a simple self-hosted web server:** Drop PHP files or static content into the default web root and off you go.

- Gighive is [open source](https://github.com/gighive/gighive) — a self-hosted media library with a searchable [database](images/databaseErd.png), an [upload utility](images/uploadutility.png), and an [iPhone app](https://apps.apple.com/us/app/gighive-upload-music-video/id6753146513).

<div class="media-grid">
  <div class="media-card">
    <a href="/images/mediaLibraryCustom.png">
      <img src="/images/mediaLibraryCustom.png" alt="Customer database example">
      <div class="card-caption">Customer Example</div>
    </a>
  </div>
  <div class="media-card">
    <a href="https://staging.gighive.app/db/database.php">
      <img src="/images/gighiveMediaLibrary.png" alt="Interactive media library example">
      <div class="card-caption">Interactive Example ↗</div>
    </a>
  </div>
</div>

- Live example: [stormpigs.com](https://www.stormpigs.com)

<div class="credentials-box">u: guest &nbsp;&nbsp;|&nbsp;&nbsp; p: stormpigsguestuser1234!</div>

### Get Started
- Based on Ubuntu, you spin up the website using bash scripts and host it either in your network or in Azure, fully automated through Ansible (and Terraform for Azure). See the [Setup Guide](README.html) or watch the quickstart videos below:

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
      <div class="card-caption">Quickstart Installation (Mac)<br><a href="https://share.google/aimode/h3XkjXbeJuDx0ztgI" style="color: #2196F3; font-size: 0.85em;">How to install Docker Desktop on Mac</a></div>
    </a>
  </div>
</div>

### Requirements for Quickstart
1. Target Machine: Most flavors of Linux x86/64 (Tested on Ubuntu 24.04, 22.04 and Mac Sequoia 15.6.1) running Docker.
2. [Quickstart Instructions](setup_instructions_quickstart.md)

### Why self-host?
- This application is for do-it-yourselfers who don't want to be beholden to Big Tech but be the masters of their own destiny.
- Gighive frees you from content limitations on the major providers..but you'll need to size your vm properly.

### iPhone App
- We have an easy-to-use iPhone app for fans and wedding guests.  [Download it here](https://apps.apple.com/us/app/gighive-upload-music-video/id6753146513)

### Features
- [Supported Media Formats](mediaFormatsSupported.html)
- Gighive is simple. There is a home page, a page for the media library and batch or single file upload utilities.
- It is secure [by default](SECURITY.html) and was built from the ground up to live behind the [Cloudflare shield](https://www.cloudflare.com).

<a href="README.html" style="display: inline-block; background-color: #2196F3; color: white; padding: 12px 24px; text-decoration: none; border-radius: 6px; font-weight: bold; margin: 8px 4px; transition: background-color 0.3s;">View the README</a> <a href="PREREQS.html" style="display: inline-block; background-color: #4CAF50; color: white; padding: 12px 24px; text-decoration: none; border-radius: 6px; font-weight: bold; margin: 8px 4px; transition: background-color 0.3s;">Parts List</a>

### License / Policy
GigHive is dual-licensed:

- **[Licenses](LICENSE.html)**: Covers both the AGPL v3 license and the commercial license model.
- **[Content Policy](gighive_content_policy.html)**: Please read and understand your responsibilities as an operator.

### Contact Us

 👉 [Contact us](mailto:contactus@gighive.app) for commercial licensing or for any other questions regarding Gighive. <img src="images/beelogo.png" alt="GigHive bee mascot" style="height: 2em; vertical-align: middle;">
