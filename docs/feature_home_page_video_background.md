# Feature: Home Page Background Video

## Overview

Add a full-viewport looping, muted, auto-playing marketing video as the page background. The video fills the entire browser window (100vw × 100vh), extending beyond the `.card` and `.wrap` container boundaries. Existing page text and content float above the video via CSS z-index layering. A semi-transparent dark overlay sits between the video and the content to ensure text readability at all times.

This affects two files:
- `ansible/roles/docker/files/apache/overlays/gighive/index.php` — the live instance home page
- `docs/index.md` — the GitHub Pages marketing home page

---

## Visual Design

### Target area
The video fills the **entire browser viewport** (100vw × 100vh), extending edge-to-edge beyond the `.card` and `.wrap` container boundaries. It is fixed in place so it does not scroll with the page. All page content (card, text, nav) floats above it.

### Layer stack (bottom to top)

| z-index | Element | Positioning | Description |
|---------|---------|-------------|-------------|
| root background | `body` background color | — | Fallback color (`#0b1020`) painted at the very bottom; visible only if video fails to load |
| -2 | `<video>` | `position: fixed` | Looping background video, full viewport |
| -1 | `.video-overlay` | `position: fixed` | Semi-transparent dark overlay for text readability |
| auto (0) | in-flow content | normal flow | `.wrap`, `.card`, all page text — naturally above negative z-index elements, no explicit z-index needed |
| 999–1002 | `.nav-overlay`, `.nav-menu`, `.hamburger-menu` | `position: fixed` | Fixed nav elements — explicit high z-index; far above video and overlay |

**Why negative z-index works here:** `position: fixed` elements with `z-index: -2`/`-1` sit above the root element background but below all in-flow content (z-index: auto). No explicit z-index is needed on `.wrap`, `.card`, or any content elements.

### Overlay
- Color: `rgba(0, 0, 0, 0.55)` — tunable; dark enough for white text legibility, light enough that video motion is visible
- Covers 100% of the **viewport** (not just the card area)

---

## Video Source Specs

| Property | Spec |
|----------|------|
| Source resolutions accepted | 720p, 1080p, 4K (all scale correctly via `object-fit: cover`) |
| Preferred source for production | 1080p H.264, target 2–4 Mbps |
| Max acceptable file size | ~15 MB for a 30-second loop |
| Container format | MP4 (H.264 baseline for broadest browser compat) |
| Aspect ratio | Any — `object-fit: cover` crops to fill; center of frame is preserved |
| Loop duration | 20–60 seconds recommended |

---

## CSS / HTML Implementation

### Container requirements

The `<video>` and `.video-overlay` are placed at **body level** — NOT inside `.card`. Both are `position: fixed`, full viewport. No wrapper div is needed for content. No z-index changes are required on any content element.

#### `index.php`

Place `<video>` and `.video-overlay` directly after `<body>`, before `.wrap`. The rest of the file is unchanged.

```html
<body>
  <video class="card-bg-video" autoplay muted loop playsinline
         poster="/images/home_bg_poster.jpg">
    <source src="/images/home_bg.mp4" type="video/mp4">
  </video>
  <div class="video-overlay"></div>

  <div class="wrap">
    <div class="card">
      ... existing content unchanged ...
    </div>
  </div>
</body>
```

#### `docs/index.md`

Place `<video>` and `.video-overlay` after `</script>` and **before** `<div class="card">`. The hamburger/nav/script elements remain as body children as before. Video `src` must be an absolute URL.

```html
<!-- style, hamburger-menu, nav-overlay, nav-menu, script — all unchanged -->

<video class="card-bg-video" autoplay muted loop playsinline
       poster="https://staging.gighive.app/images/home_bg_poster.jpg">
  <source src="https://staging.gighive.app/images/home_bg.mp4" type="video/mp4">
</video>
<div class="video-overlay"></div>

<div class="card" markdown="1">
... existing page content unchanged ...
</div>
```

### Required CSS

Applies to **both files**:

```css
/* Layer -2 — full-viewport background video */
.card-bg-video {
  position: fixed;
  top: 0;
  left: 0;
  width: 100vw;
  height: 100vh;
  object-fit: cover;
  object-position: center center;
  z-index: -2;
}

/* Layer -1 — full-viewport dark overlay */
.video-overlay {
  position: fixed;
  top: 0;
  left: 0;
  width: 100%;
  height: 100%;
  background: rgba(0, 0, 0, 0.55);
  z-index: -1;
}

/* Accessibility: hide both video AND overlay for reduced-motion users.
   Hiding only the video would leave a permanent dark overlay on screen.
   Also restore the card to a fully opaque background so it renders
   correctly against the body color with no video behind it. */
@media (prefers-reduced-motion: reduce) {
  .card-bg-video,
  .video-overlay { display: none; }
  .card { background: #121a33; }
}
```

**`.card` background — `index.php`**: change from solid `#121a33` to `rgba(18, 26, 51, 0.7)` so the video shows through the card area as well as the body margins. Tunable — increase opacity toward 1.0 to make the card more opaque. Remove `position: relative` and `overflow: hidden` (no longer needed).

**`.card` background — `docs/index.md`**: same semi-transparent value. The full `.card` rule (created from scratch in `docs/index.md` during the first implementation pass) becomes:

```css
.card {
  background: rgba(18, 26, 51, 0.7);
  border: 1px solid #1d2a55;
  border-radius: 16px;
  padding: 2rem;
}
```

**No other CSS changes needed.** The `.card > *:not()` rule is removed entirely. No z-index is set on `.wrap`, `.card`, or any content elements.

---

## Video Attributes

All four attributes are required for reliable cross-browser autoplay:

| Attribute | Purpose |
|-----------|---------|
| `autoplay` | Start playback immediately on page load |
| `muted` | Required by all browsers to permit autoplay |
| `loop` | **Endless loop** — video restarts seamlessly at end with no gap or user interaction required |
| `playsinline` | Required on iOS Safari to prevent fullscreen takeover |

---

## Fallback

The `poster` attribute on the `<video>` element provides a static image fallback:
- Displayed while the video buffers
- Displayed if the browser cannot play the video
- Should be a representative still frame from the video
- `index.php`: `/images/home_bg_poster.jpg` (relative path, served from container)
- `docs/index.md`: absolute URL, e.g. `https://staging.gighive.app/images/home_bg_poster.jpg`

---

## File Locations (when video is ready)

### `index.php` (served from the container)

| File | Path | `src` / `poster` value |
|------|------|------------------------|
| Background video | `/var/www/html/images/home_bg.mp4` | `/images/home_bg.mp4` |
| Poster image | `/var/www/html/images/home_bg_poster.jpg` | `/images/home_bg_poster.jpg` |

### `docs/index.md` (GitHub Pages)

GitHub Pages has a 100 MB file size limit and does not serve large binary assets efficiently. The video and poster must be hosted externally and referenced by absolute URL.

| File | Hosted at | `src` / `poster` value |
|------|-----------|------------------------|
| Background video | `staging.gighive.app` or CDN | `https://staging.gighive.app/images/home_bg.mp4` |
| Poster image | `staging.gighive.app` or CDN | `https://staging.gighive.app/images/home_bg_poster.jpg` |

---

## Test Video

**Source:** `~/videos/stormpigs/unusedJams/20060812/Cap0001(0023).m2t`

**Transcoded output (deployed):** `/images/home_bg.mp4` — placed in the `/images/` public directory to avoid BasicAuth on the `/video/` path, which is protected and requires authentication.

This is an HDV MPEG-2 Transport Stream (`.m2t`). Browsers cannot play `.m2t` natively — it must be transcoded to H.264 MP4 before use. Suggested ffmpeg command:

```bash
ffmpeg -i "Cap0001(0023).m2t" \
  -c:v libx264 -preset slow -crf 23 \
  -an \
  -movflags +faststart \
  home_bg_test.mp4
```

- `-an` strips audio (not needed for a muted background loop)
- `-movflags +faststart` moves the MOOV atom to the front so the browser can start playing before the full file downloads
- Trim to a suitable loop length before or after transcoding with `-ss` / `-t` flags

---

## Status

- [ ] Test transcode of `Cap0001(0023).m2t` → `home_bg_test.mp4`
- [ ] Marketing video clip produced/sourced (final)
- [ ] Poster image created (still frame)
- [x] CSS/HTML wired into `overlays/gighive/index.php`
- [x] CSS/HTML wired into `docs/index.md`
- [ ] Tested on mobile (iOS Safari autoplay + playsinline)
- [ ] Reduced-motion media query verified
- [ ] Overlay opacity tuned for readability
