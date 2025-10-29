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
    <h3>📚 Documentation</h3>
    <ul>
        <li><a href="feature_set.html">⭐ Feature Set</a></li>
        <li><a href="README.html">🚀 Setup Guide</a></li>
        <li><a href="PREREQS.html">📋 Prerequisites</a></li>
        <li><a href="SECURITY.html">🔒 Security</a></li>
    </ul>
    
    <h3>🗄️ Database</h3>
    <ul>
        <li><a href="database-import-process.html">📥 Database Import Process</a></li>
    </ul>
    
    <h3>🐳 Docker</h3>
    <ul>
        <li><a href="DOCKER_COMPOSE_BEHAVIOR.html">⚙️ Docker Behavior</a></li>
    </ul>
    
    <h3>🎥 Streaming</h3>
    <ul>
        <li><a href="CORE_UPLOAD_IMPLEMENTATION.html">📊 Upload Limits / Call Flow</a></li>
        <li><a href="howdoesstreamingwork_implementation.html">⚙️ Streaming Implementation</a></li>
        <li><a href="PICKER_TRANSCODING_METHOD.html">🎬 Picker Transcoding</a></li>
    </ul>
    
    <h3>📱 iPhone App</h3>
    <ul>
        <li><a href="FOUR_PAGE_REARCHITECTURE.html">🏗️ 4-Page Rearchitecture</a></li>
    </ul>
    
    <h3>🔮 Coming Soon</h3>
    <ul>
        <li><a href="migrate-bootstrap-to-ansible.html">🔄 Migrate Bootstrap</a></li>
        <li><a href="WHAT_IS_TUS.html">❓ What is TUS</a></li>
        <li><a href="TUS_IMPLEMENTATION_RATIONALE.html">📝 TUS Rationale</a></li>
        <li><a href="tusimplementationweek1.html">📤 TUS Implementation</a></li>
        <li><a href="security-upgrade.html">🔒 Security Upgrade</a></li>
    </ul>
    
    <h3>📄 Legal</h3>
    <ul>
        <li><a href="LICENSE_AGPLv3.html">📜 AGPL v3 License</a></li>
        <li><a href="LICENSE_COMMERCIAL.html">💼 Commercial License</a></li>
    </ul>
    
    <h3>🔗 Links</h3>
    <ul>
        <li><a href="mailto:contactus@gighive.app">✉️ Contact Us</a></li>
        <li><a href="https://github.com/gighive/gighive" target="_blank">🐙 GitHub</a></li>
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
<div class="custom-h2">Gighive is a web-accessible media database for you, your fans, or wedding guests.</div>
### If you're a musician
- You can use it as a library of your bands sessions, audio and video files.
- Have your fans upload videos from your gigs and utilize the footage from every conceivable angle.

### If you're a wedding photographer
- You can have your guests upload audio and video files from a wedding that you can incorporate into a compilation video.
- Collect media from everyone during the event, offload it and then spin down the compute instance after you're done, thus saving you money.

### If you are a media librarian or have a preexisting cache of media files
- You can edit the default csv file to create your own historical Gighive.

### What is it? 
- Gighive is an [open source website and database](https://github.com/gighive/gighive) that you, your fans or wedding guests can use as temporary or permanent storage for video and audio files. Based on Ubuntu, you spin up the website using bash scripts and host it either in your network or in Azure. It is fully automated through a combination of Ansible and Terraform.

### Why not just use YouTube?
- This application is for do-it-yourselfers who don't want to be beholden to Big Tech but be the masters of their own destiny.
- With build targets such as Azure, virtualbox or bare metal, you have your choice on how to deploy Gighive.
- Gighive frees you from content limitations on the major providers..but you'll need to size your vm properly.
- It is secure [by default](SECURITY.html) and was built from the ground up to live behind the [Cloudflare shield](https://www.cloudflare.com).
- Gighive is simple. There is one page for the media library and an upload page. That's it.
- Coming soon: an easy-to-use iphone app.

### Requirements
1. Control Machine: Tested on Ubuntu 22.04, so the requirements are **any flavor of Ubuntu 22.04 or Pop-OS, installed on bare metal.**
2. Target Machine: Your choice of virtualbox, Azure or bare metal deployment targets for the vm and containerized environment.
These are shown in this <a href="images/architecture.png">architecture diagram</a>.

### What comes with Gighive?
- Gighive includes a website with a searchable, sortable one-page listing of media files and common attributes (date, filename, etc) stored [in the database](images/databaseErd.png) along with an [upload utility](images/uploadutility.png).
- Common media formats for upload are supported (shown below).
- [Here are instructions](README.html) on how to standup and manage your own Gighive.

### Media formats supported
- Audio formats: MP3 (audio/mpeg, audio/mp3), WAV (audio/wav, audio/x-wav), AAC (audio/aac), FLAC (audio/flac), MP4 Audio (audio/mp4)
- Video formats: MP4 (video/mp4), QuickTime/MOV (video/quicktime), Matroska/MKV (video/x-matroska), WebM (video/webm), AVI (video/x-msvideo)
- Note that .MOV and .AVI don't autoplay in the browser

### For the future
- Eventually, we will develop more interesting features. But for now, we've keeping it simple and easy to manage.

### So give Gighive a try! For those with a bit of unix and command line experience, it will be a breeze to setup!
<a href="README.html" style="display: inline-block; background-color: #2196F3; color: white; padding: 12px 24px; text-decoration: none; border-radius: 6px; font-weight: bold; margin: 8px 4px; transition: background-color 0.3s;">View the README</a> <a href="PREREQS.html" style="display: inline-block; background-color: #4CAF50; color: white; padding: 12px 24px; text-decoration: none; border-radius: 6px; font-weight: bold; margin: 8px 4px; transition: background-color 0.3s;">Parts List</a>
### License
GigHive is dual-licensed:

- **[AGPL v3 License](LICENSE_AGPLv3.html)**: Open source, free for personal use with strong copyleft protection for use as a SaaS.
- **[Commercial License](LICENSE_COMMERCIAL.html)**: Required for SaaS, multi-tenant, or commercial use without AGPL obligations.

👉 [Contact us](mailto:contactus@gighive.app) for commercial licensing or for any other questions regarding Gighive. <img src="images/beelogo.png" alt="GigHive bee mascot" style="height: 1em; vertical-align: middle;">
