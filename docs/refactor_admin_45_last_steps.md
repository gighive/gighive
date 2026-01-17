# Refactor follow-ups for Admin Sections 4/5 (Deferred)

This document captures the two refactor steps that were discussed but intentionally deferred after completing the “minimal dedupe” and helper extraction work for Sections 4/5 in `admin.php`.

Scope reference:
- `ansible/roles/docker/files/apache/webroot/admin.php`
- Sections:
  - Section 4: “Scan Folder and Update DB” (destructive reload)
  - Section 5: “Scan Folder and Add to DB” (non-destructive add)

## Step 4 (Deferred): UI controller abstraction

### Goal
Create a small abstraction that centralizes the DOM wiring and UI transitions for Section 4 and Section 5 so the runner logic no longer manually tweaks button text/styles and status HTML in multiple places.

Instead of each mode (reload/add) hand-editing UI elements (button disable states, textContent updates, changing colors, etc.), the runner would call a consistent interface.

### Proposed shape
Introduce a controller factory:

- `createImportUiController({
    statusEl,
    previewEl,
    mainBtn,
    stopBtn,
    clearCacheBtn,
    setUiState
  })`

And have it expose methods like:
- `setIdle(html)`
- `setHashing(html)`
- `setUploading(html)`
- `setStopping(html)`
- `renderSuccess(data)`
- `renderError(data)`

Reload and Add remain separate configurations (different confirm text, endpoint, success banner text, and optional extra renderer such as `renderAddReport`).

### Why it’s helpful
- Reduces the risk of Section 4 and Section 5 drifting in behavior/UI.
- Makes it easier to tweak the UX (e.g., button styling, enabling/disabling rules) in one place.
- Makes the import runner easier to read.

### Risk level
Medium to Medium/High.

The UI is the easiest place to introduce subtle behavioral regressions. Common failure modes:
- Stop button stays enabled/disabled at the wrong time.
- Clear-cache button becomes enabled incorrectly.
- Success path doesn’t “lock” the primary button properly (pointerEvents/cursor/styles).
- Different status messages appear depending on timing/state.

### Recommended validation
- Section 4:
  - Pick folder with supported files → verify preview, run hashing, verify upload, verify post-success button lock.
  - Hit Stop mid-hash → verify “stop requested” behavior and upload of partial items.
- Section 5:
  - Same tests, plus confirm `renderAddReport` output still appears.
- Cache behavior:
  - Run twice and confirm cached hashes are used.

## Step 5 (Deferred): Move JS out of `admin.php` into a separate file

### Goal
Extract the large inline `<script>` block into a standalone file (e.g., `admin_scan_import.js`) and include it from `admin.php`.

### Why it’s helpful
- Improves maintainability/readability of `admin.php`.
- Makes it easier to lint/test JS in isolation.
- Reduces merge conflicts by separating markup from logic.

### Risk level
Medium (mostly operational/integration).

The core code can remain the same, but you can break runtime behavior through:
- Incorrect script load ordering (functions not defined when handlers run).
- Path mistakes (relative URL differences depending on the page location).
- Caching issues during deployment.
- Ansible deployment not copying the new JS file.

### Implementation notes
- Keep all existing DOM lookups after the DOM is ready:
  - Either place `<script src="...">` at the end of `<body>`, or use `defer`.
- Keep endpoint fetch paths relative to `admin.php` location (as they are now).
- Ensure Ansible role copies the new `.js` file to the same webroot.

### Recommended validation
- Load `admin.php` in browser devtools:
  - Confirm no 404 for the JS file.
  - Confirm no JS errors.
- Exercise Section 4 and Section 5 flows (hashing, stop, clear-cache, success banners).

## Suggested order if/when you resume
1. Step 4 (UI controller) — only if you’re confident you want to further normalize UI behavior.
2. Step 5 (external JS file) — after UI changes are stable, since it adds deployment concerns.
