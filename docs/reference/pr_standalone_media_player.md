# PR: Standalone Media Player (Milestone B)

## Rationale

The Media Library (`db/database.php` -> `src/Views/media/list.php`) provides links to audio/video files but does not embed HTML5 media elements. As a result:

- `file_download` tracking can measure click intent (user clicked a link).
- True `media_play` tracking cannot be reliably captured on the list page because there is no `<audio>` / `<video>` element to attach playback listeners to.

This PR proposes a dedicated, lightweight player page that embeds a single media asset using HTML5 audio/video controls. This enables **accurate and semantically correct** GA4 `media_play` events that fire when the user actually presses play.

This work is intentionally separated from Milestone A (GA tag centralization + `file_download`) so Milestone A can ship as a smaller, safer change.

---

## Milestone B Implementation Plan

### B1. Add a standalone player endpoint

Create a new entrypoint under `webroot/db/` (example name):

- `ansible/roles/docker/files/apache/webroot/db/mediaPlayer.php`

Responsibilities:

- Accept an identifier for the media item to play.
- Fetch that media item’s metadata (type, URL, identifying fields).
- Render a dedicated view template.

Notes:

- This should be a simple, standalone page (like other `db/*.php` entrypoints) that delegates to a controller.

### B2. Add controller support for fetching a single media item

Add a controller method (likely in `MediaController`) to:

- Validate request inputs (ID / checksum / relpath).
- Fetch a single media row from the database.
- Prepare the view-model fields needed by the template.

Notes:

- Reuse the same canonical fields currently used in `MediaController::list()` (e.g. `id`, `type`, `url`, `songTitle`, `org_name`, `date`, `checksumSha256`, `sourceRelpath`) so analytics parameters remain consistent.

### B3. Add a new view template for the player

Add a view template (example):

- `ansible/roles/docker/files/apache/webroot/src/Views/media/player.php`

Responsibilities:

- Render a minimal HTML page.
- Include `includes/ga_tag.php` in the `<head>` so GA is present when configured.
- Embed exactly one media item using:
  - `<audio controls>` for audio
  - `<video controls>` for video

Notes:

- The existing `src/Views/media/random_player.php` can be used as a reference for HTML5 playback patterns.

### B4. Emit GA4 `media_play` on actual playback

Add JavaScript to the player template to:

- Attach `play` event listeners to the `<audio>` / `<video>` element.
- Emit a GA4 event only when GA is loaded:
  - `typeof gtag === 'function'`

Event:

- `media_play`

Suggested parameters (align with Milestone A data model where possible):

- `file_url`
- `file_name`
- `file_type`
- `media_id`
- `org_name`, `date`, `song_name`
- `checksum_sha256`, `source_relpath`

Implementation notes:

- Guard against double-firing if the user presses play multiple times (decide whether to allow multiple `media_play` events or add a `playedOnce` boolean).

### B5. Wire the Media Library to the player page

Update the Media Library view (`src/Views/media/list.php`) so users can open the player page from the list.

Options:

- Change thumbnail click to open the player page instead of linking directly to the raw media file.
- Add a dedicated “Play” link/button per row that opens the player page.

Notes:

- Do not remove the existing direct download/view behavior unless explicitly desired.
- Keep `file_download` intent tracking for direct file links.

### B6. Ensure analytics consistency with Milestone A

Ensure that the player page uses the same analytics field naming as the list page so downstream reporting can join/compare:

- Same identifiers (`media_id`, `checksum_sha256`, `source_relpath`)
- Same human-readable context (`org_name`, `song_name`, `date`)

### B7. Verification

Validate in GA4:

- Confirm GA tag loads on the player page only when configured.
- Confirm `media_play` appears in:
  - Realtime reports
  - DebugView / Tag Assistant

Test cases:

- Audio file:
  - Open player
  - Press play
  - Confirm `media_play`

- Video file:
  - Open player
  - Press play
  - Confirm `media_play`

---

## Notes / Non-Goals

- This milestone does not attempt to infer “play” intent from `file_download` clicks on the Media Library.
- This milestone does not attempt to track low-level streaming progress (e.g. quartiles). Those can be added later if needed.
