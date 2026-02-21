# GA4 Tag + Media Tracking Feature Modification

## Summary

This change introduces **configurable Google Analytics 4 (GA4) tagging** and lays out how to enable **media engagement tracking** on the `db/database.php` (Media Library) page.

Key goals:

- Keep the current production behavior for **defaultcodebase**: GA tag is present (currently `G-MX1FQZ3H0W`).
- Default behavior for **GigHive / gighive**: GA tag is **blank / disabled** unless explicitly configured.
- Enable `db/database.php` to emit GA4 events:
  - `file_download`
  - `media_play`

This document covers configuration and the intended implementation approach.

---

## Current State (Baseline)

- The GA4 `gtag.js` snippet exists in `ansible/roles/docker/files/apache/webroot/header.php`.
- That header is part of the legacy defaultcodebase layout and is not consistently included across newer standalone pages.
- The Media Library page served by `db/database.php` renders HTML via:
  - `Production\Api\Controllers\MediaController::list()`
  - `ansible/roles/docker/files/apache/webroot/src/Views/media/list.php`
- `src/Views/media/list.php` is a **fully standalone HTML document** and currently does **not** include `header.php`.

Implication:

- Even if `header.php` includes GA, `db/database.php` may not be tagged at all.
- Without GA available on the page, `file_download`/`media_play` events cannot be emitted.

---

## Feature Modification: Configurable GA4 Tag

### Requirements

- **Configurable measurement ID** so the platform can be deployed for multiple tenants/brands.
- Preserve the legacy behavior:
  - When `APP_FLAVOR=defaultcodebase`: GA is enabled by default.
  - When `APP_FLAVOR=gighive`: GA is disabled by default.

### Proposed Configuration

Introduce an environment-driven measurement ID with flavor-based defaults.

Recommended env vars:

- `GA4_MEASUREMENT_ID_DEFAULTCODEBASE`
  - Default: `G-MX1FQZ3H0W` (to replicate the current defaultcodebase behavior)
- `GA4_MEASUREMENT_ID_GIGHIVE`
  - Default: *(blank)*

Selection logic:

- If `APP_FLAVOR` resolves to `defaultcodebase`:
  - Use `GA4_MEASUREMENT_ID_DEFAULTCODEBASE`.
  - If unset/blank, fall back to `G-MX1FQZ3H0W`.
- If `APP_FLAVOR=gighive`:
  - Use `GA4_MEASUREMENT_ID_GIGHIVE`.
  - If unset/blank, **do not emit GA tag**.

Notes:

- This keeps `gighive` deployments privacy-safe by default.
- This avoids forcing a single measurement ID across all flavors.

---

## Where the GA Tag Should Live

### Target pages

At minimum, to support tracking on the Media Library:

- `ansible/roles/docker/files/apache/webroot/src/Views/media/list.php`

The Media Library view includes the GA tag via:

- `include __DIR__ . '/../../../includes/ga_tag.php';`

### Preferred implementation detail

- Render the GA snippet *only when a measurement ID is present*.
- Keep the snippet in one reusable literal/function (PHP helper) or a single include.

Two viable structures:

- **Inline conditional** inside each standalone view needing GA
- **Shared include** (preferred) such as `webroot/includes/ga_tag.php`

This doc does not mandate the structure; it documents the feature intent.

---

## Enabling Event Tracking on `db/database.php`

### `file_download`

The Media Library renders downloadable/viewable assets as `<a>` links in the `Download / View` column.

Implementation:

- Add a click listener via event delegation.
- When an anchor `href` matches media files (or paths like `/audio/...` and `/video/...`), emit:

Event:

- `file_download`

Suggested parameters:

- `file_url`: full URL (or path)
- `file_name`: served filename
- `file_type`: `audio` or `video`
- `media_id`: database `id`
- `org_name`, `date`, `song_name`
- `checksum_sha256`, `source_relpath`

### `media_play`

Important: the Media Library page currently does **not** embed `<audio>` or `<video>` elements.

Therefore, there are two acceptable semantic strategies:

- **Strict strategy (recommended for accuracy)**
  - Introduce a lightweight “player page” that embeds HTML5 `<audio>`/`<video>`.
  - Emit `media_play` on the media element’s `play` event.

- **Proxy strategy (acceptable if you only need intent)**
  - Emit `media_play` when the user clicks `Download / View`.
  - Include a parameter like `play_proxy: true` to distinguish it from true playback.

The implementation should follow whichever semantic strategy is chosen for reporting.

---

## Verification Checklist

- Confirm `gtag()` exists on `db/database.php` page:
  - View page source and ensure the GA snippet is present when configured.
- Validate events in real time:
  - GA4 DebugView / Tag Assistant
  - Click “Download / View” and confirm `file_download` fires.
  - Confirm `media_play` fires according to chosen strategy.
- GA4 reporting:
  - Reports → Engagement → Events
  - Register any custom parameters you want visible in standard reports.

---

## Files In Scope

- Existing:
  - `ansible/roles/docker/files/apache/webroot/header.php` (contains legacy GA tag)
  - `ansible/roles/docker/files/apache/webroot/db/database.php` (entrypoint)
  - `ansible/roles/docker/files/apache/webroot/src/Views/media/list.php` (HTML output for Media Library)

- New/modified as part of implementation (to be approved separately):
  - Add configurable measurement ID + conditional GA snippet injection
  - Add JS listeners for `file_download` and `media_play`

---

## Implementation Decisions (Confirmed)

- The GA tag will be centralized in `ansible/roles/docker/files/apache/webroot/includes/ga_tag.php`.
- The hardcoded GA snippet will be removed from `ansible/roles/docker/files/apache/webroot/header.php` and replaced with an include of `includes/ga_tag.php`.
- The Media Library page (`db/database.php` -> `src/Views/media/list.php`) includes `includes/ga_tag.php` directly because it renders as a standalone HTML document.
- GA measurement IDs will be configured via Ansible group vars in `ansible/inventories/group_vars/gighive2/gighive2.yml` and rendered into the Apache container env file via `ansible/roles/docker/templates/.env.j2`.
- Any PHP fallback logic using `APP_FLAVOR` default `'stormpigs'` will be normalized to default `'defaultcodebase'`.
- The default org name literal currently set to `StormPigs` should be changed to `Band`.
