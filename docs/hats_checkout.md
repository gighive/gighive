---
permalink: /hats/
---
<style>
/* ── Base dark theme ──────────────────────────────────── */
body {
    background-color: #121a33;
    color: white;
}
img { background: transparent !important; }

/* ── Hamburger menu (matches site-wide nav) ───────────── */
.hamburger-menu {
    position: fixed;
    top: 20px;
    right: 20px;
    z-index: 1002;
}
.hamburger-icon {
    width: 30px; height: 30px;
    cursor: pointer;
    display: flex; flex-direction: column; justify-content: space-around;
    background: none; border: none; padding: 0;
}
.hamburger-line {
    width: 100%; height: 3px;
    background-color: white;
    transition: all 0.3s ease;
}
.hamburger-icon.active .hamburger-line:nth-child(1) { transform: rotate(45deg) translate(6px, 6px); }
.hamburger-icon.active .hamburger-line:nth-child(2) { opacity: 0; }
.hamburger-icon.active .hamburger-line:nth-child(3) { transform: rotate(-45deg) translate(8px, -8px); }

.nav-menu {
    position: fixed; top: 0; right: -300px;
    width: 280px; height: 100vh;
    background-color: #1a2347;
    border-left: 2px solid #2196F3;
    transition: right 0.3s ease;
    padding: 15px 20px 20px;
    box-shadow: -2px 0 10px rgba(0,0,0,0.3);
    overflow-y: auto; z-index: 1001;
    pointer-events: auto;
}
.nav-menu.active { right: 0; }
.nav-menu h3 {
    color: #2196F3 !important;
    margin-bottom: 20px; font-size: 1.0em;
    border-bottom: 1px solid #2196F3;
    padding-bottom: 10px;
    pointer-events: none;
}
.nav-menu h3 a.anchor,
.nav-menu h3 a[href^="#"] { display: none !important; }
.nav-menu ul { list-style: none; padding: 0; margin: 0; }
.nav-menu li { margin-bottom: 4px; }
.nav-menu a:not([href^="#"]) {
    color: white !important; text-decoration: none;
    display: block; padding: 2px 6px; border-radius: 4px;
    transition: background-color 0.3s ease; cursor: pointer;
    pointer-events: auto; font-size: 0.85em;
}
.nav-menu a:hover  { background-color: #2196F3 !important; color: white !important; }
.nav-menu a:visited { color: white !important; }
.nav-menu a:active  { color: white !important; }

.nav-overlay {
    position: fixed; top: 0; left: 0; width: 100%; height: 100%;
    background-color: rgba(0,0,0,0.5);
    z-index: 999; opacity: 0; visibility: hidden; transition: all 0.3s ease;
}
.nav-overlay.active { opacity: 1; visibility: visible; }

@media (max-width: 768px) { .nav-menu { width: 100%; right: -100%; } }

/* ── Page wrapper ─────────────────────────────────────── */
.hat-shop {
    max-width: 1080px;
    margin: 0 auto;
    padding: 2rem 1.25rem 4rem;
}

/* ── Page header ──────────────────────────────────────── */
.hat-shop-header {
    text-align: center;
    padding: 3rem 0 2.5rem;
    border-bottom: 1px solid #2a3560;
    margin-bottom: 2.5rem;
}
.hat-shop-header h1 {
    font-size: 2.4rem;
    font-weight: bold;
    margin: 0.6rem 0 0.5rem;
    color: white;
}
.hat-shop-header p {
    font-size: 1.05rem;
    color: #aab;
    margin: 0;
}

/* ── Product grid ─────────────────────────────────────── */
.hat-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 1.75rem;
    align-items: start;
}
@media (max-width: 860px) {
    .hat-grid {
        grid-template-columns: 1fr;
        max-width: 460px;
        margin-left: auto;
        margin-right: auto;
    }
}

/* ── Product card ─────────────────────────────────────── */
.hat-card {
    background: #1a2347;
    border: 1px solid #2a3560;
    border-radius: 12px;
    overflow: hidden;
    display: flex;
    flex-direction: column;
    transition: border-color 0.25s, box-shadow 0.25s;
}
.hat-card:not(.coming-soon-card):hover {
    border-color: #2196F3;
    box-shadow: 0 4px 24px rgba(33,150,243,0.12);
}
.hat-card.coming-soon-card { opacity: 0.6; }

/* ── Hero image ───────────────────────────────────────── */
.hat-hero {
    width: 100%;
    height: 270px;
    object-fit: cover;
    display: block;
    cursor: zoom-in;
}
.hat-hero.contain-fit {
    object-fit: contain;
    background: #111827;
    padding: 1rem;
    cursor: default;
    box-sizing: border-box;
}

/* ── Thumbnail strip ──────────────────────────────────── */
.thumb-strip {
    display: flex;
    gap: 6px;
    padding: 8px 10px;
    background: #111827;
}
.thumb {
    width: 68px;
    height: 50px;
    object-fit: cover;
    border-radius: 4px;
    cursor: pointer;
    border: 2px solid transparent;
    transition: border-color 0.2s, opacity 0.2s;
    flex-shrink: 0;
    opacity: 0.7;
}
.thumb:hover { opacity: 1; }
.thumb.thumb-active { border-color: #2196F3; opacity: 1; }

/* ── Card body ────────────────────────────────────────── */
.hat-card-body {
    padding: 1.25rem 1.4rem 1.5rem;
    display: flex;
    flex-direction: column;
    flex: 1;
}
.hat-card-body h2 {
    font-size: 1.2rem;
    font-weight: bold;
    margin: 0 0 0.45rem;
    color: white;
}
.hat-desc {
    color: #aab;
    font-size: 0.875rem;
    line-height: 1.65;
    flex: 1;
    margin-bottom: 1rem;
}
.hat-desc ul { margin: 0.35rem 0 0 1rem; padding: 0; }
.hat-desc li { margin-bottom: 0.15rem; }

/* ── Price row ────────────────────────────────────────── */
.price-row {
    display: flex;
    align-items: center;
    gap: 0.7rem;
    margin-bottom: 1.1rem;
    flex-wrap: wrap;
}
.hat-price { font-size: 1.65rem; font-weight: bold; color: white; }
.hat-price.muted { color: #556; }
.shipping-badge {
    background: #1b4332;
    color: #6ee7b7;
    padding: 3px 10px;
    border-radius: 20px;
    font-size: 0.7rem;
    font-weight: 700;
    letter-spacing: 0.05em;
    text-transform: uppercase;
    white-space: nowrap;
}
.shipping-badge.muted { background: #1a2347; color: #556; }

/* ── Buy / Coming-soon button ─────────────────────────── */
.buy-btn {
    display: block;
    width: 100%;
    box-sizing: border-box;
    padding: 13px;
    text-align: center;
    border-radius: 8px;
    font-weight: bold;
    font-size: 1rem;
    text-decoration: none;
    transition: background-color 0.2s;
}
.buy-btn-active { background: #2196F3; color: white !important; }
.buy-btn-active:hover { background: #1565C0; }
.buy-btn-soon { background: #1e2a50; color: #556 !important; cursor: default; pointer-events: none; }

/* ── About section ────────────────────────────────────── */
.hat-about {
    background: #1a2347;
    border: 1px solid #2a3560;
    border-radius: 12px;
    padding: 2rem 2.5rem;
    margin-top: 2.5rem;
    text-align: center;
    color: #aab;
    line-height: 1.8;
    font-size: 0.95rem;
}
.hat-about a { color: #2196F3; text-decoration: none; }
.hat-about a:hover { text-decoration: underline; }
</style>

<!-- Hamburger button -->
<div class="hamburger-menu">
  <button class="hamburger-icon" onclick="toggleMenu()">
    <div class="hamburger-line"></div>
    <div class="hamburger-line"></div>
    <div class="hamburger-line"></div>
  </button>
</div>
<div class="nav-overlay" onclick="closeMenu()"></div>

<!-- Navigation menu -->
<nav class="nav-menu">
  <h3>📚 Setup</h3>
  <ul>
    <li><a href="PREREQS.html">📋 Prerequisites</a></li>
    <li><a href="README.html">🚀 Setup Guide</a></li>
    <li><a href="setup_instructions_quickstart.html">⚡ Quickstart Setup</a></li>
  </ul>
  <h3>📱 iPhone App</h3>
  <ul>
    <li><a href="iphone_app.html">📱 GigHive iPhone App</a></li>
  </ul>
  <h3>🔗 Links</h3>
  <ul>
    <li><a href="/">🏠 Home</a></li>
    <li><a href="/hats">🧢 Hats</a></li>
    <li><a href="mailto:contact@gighive.app">✉️ Contact Us</a></li>
    <li><a href="https://github.com/gighive/gighive" target="_blank" rel="noopener noreferrer">🐙 GitHub</a></li>
  </ul>
  <h3>🔒 Advanced / Internals</h3>
  <ul>
    <li><a href="how_users_connect.html">⭐ How Users Connect</a></li>
    <li><a href="azure_setup.html">☁️ Azure Setup</a></li>
    <li><a href="SECURITY.html">🔒 Security</a></li>
    <li><a href="database_load_options.html">📊 Database Load Options</a></li>
  </ul>
  <h3>📄 Legal &amp; Policies</h3>
  <ul>
    <li><a href="gighive_content_policy.html">📋 Content Policy</a></li>
    <li><a href="privacy.html">🔒 Privacy Policy</a></li>
    <li><a href="LICENSE.html">📜 Licenses</a></li>
    <li><a href="APP_TERMS_OF_SERVICE.html">📋 App Terms of Service</a></li>
  </ul>
</nav>

<!-- Page content -->
<div class="hat-shop">

  <div class="hat-shop-header">
    <img src="/images/beelogo.png" alt="GigHive bee" style="height:60px; vertical-align:middle;">
    <h1>GigHive Hats</h1>
    <p>Support GigHive and help preserve shared experiences.</p>
  </div>

  <div class="hat-grid">

    <!-- ════════════════════════════════════════════════
         CARD 1 — Modern Bee Hat
    ═════════════════════════════════════════════════ -->
    <div class="hat-card">
      <img id="hero-modern" class="hat-hero"
           src="/images/hat_modern_left_side.jpeg"
           alt="GigHive Modern Bee Hat">
      <div class="thumb-strip">
        <img class="thumb thumb-active"
             src="/images/hat_modern_left_side.jpeg"
             onclick="setHero('modern', this)"
             alt="Left side">
        <img class="thumb"
             src="/images/hat_modern_right_side.jpeg"
             onclick="setHero('modern', this)"
             alt="Right side">
        <img class="thumb"
             src="/images/hat_both_back.jpeg"
             onclick="setHero('modern', this)"
             alt="Back">
      </div>
      <div class="hat-card-body">
        <h2>Modern Bee Hat</h2>
        <div class="hat-desc">
          Embroidered cartoon bee mascot — camera in one hand, mic in the other.
          <em>FOUNDED —2026—</em> stitched on the side panel.
          <ul>
            <li>Premium washed cotton dad cap</li>
            <li>Khaki crown / navy bill</li>
            <li>Adjustable leather strap</li>
            <li>One size fits most</li>
          </ul>
        </div>
        <div class="price-row">
          <span class="hat-price">$39.95</span>
          <span class="shipping-badge">FREE U.S. SHIPPING</span>
        </div>
        <a href="https://buy.stripe.com/test_fZu14hg3R6bm6cWdbia7C01"
           class="buy-btn buy-btn-active">Buy Now →</a>
      </div>
    </div>

    <!-- ════════════════════════════════════════════════
         CARD 2 — Futuristic Hat
    ═════════════════════════════════════════════════ -->
    <div class="hat-card">
      <img id="hero-futuristic" class="hat-hero"
           src="/images/hat_futuristic_left_side.jpeg"
           alt="GigHive Futuristic Hat">
      <div class="thumb-strip">
        <img class="thumb thumb-active"
             src="/images/hat_futuristic_left_side.jpeg"
             onclick="setHero('futuristic', this)"
             alt="Left side">
        <img class="thumb"
             src="/images/hat_futuristic_right_side.jpeg"
             onclick="setHero('futuristic', this)"
             alt="Right side">
        <img class="thumb"
             src="/images/hat_both_back.jpeg"
             onclick="setHero('futuristic', this)"
             alt="Back">
      </div>
      <div class="hat-card-body">
        <h2>Futuristic Hat</h2>
        <div class="hat-desc">
          Clean wireframe bee with <em>GIGHIVE</em> text. Minimalist embroidery
          on both the front crown and right side panel.
          <em>FOUNDED —2026—</em> stitched on the left panel.
          <ul>
            <li>Premium washed cotton dad cap</li>
            <li>Khaki crown / navy bill</li>
            <li>Adjustable leather strap</li>
            <li>One size fits most</li>
          </ul>
        </div>
        <div class="price-row">
          <span class="hat-price">$39.95</span>
          <span class="shipping-badge">FREE U.S. SHIPPING</span>
        </div>
        <a href="https://buy.stripe.com/test_fZu6oB9Ft2Za9p8b3aa7C00"
           class="buy-btn buy-btn-active">Buy Now →</a>
      </div>
    </div>

    <!-- ════════════════════════════════════════════════
         CARD 3 — Retro Bee Hat (Coming Soon)
    ═════════════════════════════════════════════════ -->
    <div class="hat-card coming-soon-card">
      <img class="hat-hero contain-fit"
           src="/images/hat_retro_design_sheet.png"
           alt="GigHive Retro Bee Hat — design preview">
      <div class="hat-card-body">
        <h2>Retro Bee Hat
          <span style="font-size:0.7em; color:#667; font-weight:normal; margin-left:4px;">Coming Soon</span>
        </h2>
        <div class="hat-desc">
          Classic retro-style bee mascot. Design preview shown above —
          production photos coming when inventory is ready.
          <ul>
            <li>Premium washed cotton dad cap</li>
            <li>Khaki crown / navy bill</li>
            <li>Adjustable leather strap</li>
            <li>One size fits most</li>
          </ul>
        </div>
        <div class="price-row">
          <span class="hat-price muted">$39.95</span>
          <span class="shipping-badge muted">FREE U.S. SHIPPING</span>
        </div>
        <span class="buy-btn buy-btn-soon">Coming Soon</span>
      </div>
    </div>

  </div><!-- /.hat-grid -->

  <!-- About section -->
  <div class="hat-about">
    <img src="/images/beelogo.png" alt="GigHive bee"
         style="height:40px; display:block; margin: 0 auto 0.75rem;">
    <strong style="color:white; font-size:1.05rem;">Every hat helps fund GigHive.</strong><br>
    GigHive is an open-source platform dedicated to preserving shared experiences —
    concerts, weddings, and every moment worth keeping.
    Your purchase directly supports continued development.<br><br>
    Questions? <a href="mailto:contact@gighive.app">contact@gighive.app</a>
  </div>

</div><!-- /.hat-shop -->

<script>
/* Hamburger nav */
function toggleMenu() {
    document.querySelector('.hamburger-icon').classList.toggle('active');
    document.querySelector('.nav-menu').classList.toggle('active');
    document.querySelector('.nav-overlay').classList.toggle('active');
}
function closeMenu() {
    document.querySelector('.hamburger-icon').classList.remove('active');
    document.querySelector('.nav-menu').classList.remove('active');
    document.querySelector('.nav-overlay').classList.remove('active');
}
document.addEventListener('keydown', function(e) { if (e.key === 'Escape') closeMenu(); });
document.addEventListener('DOMContentLoaded', function() {
    var navMenu = document.querySelector('.nav-menu');
    if (navMenu) {
        navMenu.querySelectorAll('h3 a.anchor, h3 a[href^="#"]').forEach(function(a) { a.remove(); });
    }
});

/* Photo gallery — swap hero image on thumbnail click */
function setHero(cardId, thumbEl) {
    document.getElementById('hero-' + cardId).src = thumbEl.src;
    thumbEl.parentElement.querySelectorAll('.thumb').forEach(function(t) {
        t.classList.remove('thumb-active');
    });
    thumbEl.classList.add('thumb-active');
}
</script>
