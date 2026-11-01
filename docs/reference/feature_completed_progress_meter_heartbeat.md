# Feature: Progress Meter Heartbeat

## Goal
Add a tiny animated heartbeat indicator next to the active progress meter text on both admin import pages so the UI more clearly signals that long-running work is still actively progressing.

Target pages:

- `ansible/roles/docker/files/apache/webroot/admin/admin_database_load_import.php`
- `ansible/roles/docker/files/apache/webroot/admin/admin_database_load_import_media_from_folder.php`

## Desired behavior
When an import step has active progress data and is still in progress, show a very small pulsing heart inline with the progress meter text.

Examples:

- `PROGRESS METER: Processed 200 / 400 ♥`
- `512 / 2048 (25%) ♥`
- `PROGRESS METER: Processed 400 / 400 100%`
- `2048 / 2048 (100%) 100%`

The heartbeat should:

- be approximately text-height
- remain visually subtle
- animate continuously while the step is active
- be replaced by a tiny completed-state `100%` graphic when the step reaches completion
- not appear for `error` states
- not require any image assets

The completed-state `100%` graphic should:

- be approximately the same size as the heartbeat
- appear inline beside the final progress text
- visually resemble the Apple iPhone-style `100` emoji treatment
- remain static rather than animated
- disappear for non-complete states

## Why heartbeat instead of a bee icon
A heartbeat is preferable for this specific UI element because it is:

- simpler to implement
- easier to keep tiny and readable
- cleaner at inline font-height
- free of image scaling, transparency, and cropping issues
- easier to share across both PHP pages via one external CSS file and one external JS file

## Implementation approach
Use shared external assets rather than duplicating the same CSS and JS in both PHP pages.

### New shared files
Add:

- `ansible/roles/docker/files/apache/webroot/admin/assets/import_progress.css`
- `ansible/roles/docker/files/apache/webroot/admin/assets/import_progress.js`

### PHP page changes
Update both admin pages to reference the shared assets:

- `admin_database_load_import.php`
- `admin_database_load_import_media_from_folder.php`

Each page would include:

- one stylesheet link for the heartbeat styles
- one script tag for the shared rendering helper

## Shared CSS responsibilities
The shared CSS file should contain:

- the inline heartbeat class
- the inline completed-state `100%` badge class
- the pulse animation keyframes
- sizing tuned to roughly `1em`
- subtle glow styling that fits the current dark admin UI

Recommended visual traits:

- small pink/red heart
- slight scale pulse
- light text-shadow glow during the pulse peak
- vertical alignment adjusted to sit naturally with the progress text
- a tiny red `100%` completed badge with emoji-like energy lines or emphasis styling, but still restrained enough for an admin tool
- the `100%` badge should use the Unicode emoji `💯` directly so it matches the Apple/platform emoji rendering naturally at any size without custom CSS shapes
- the pulse animation must be suppressed via `@media (prefers-reduced-motion: reduce)` for accessibility

## Shared JS responsibilities
The shared JS file should contain the progress helper logic, including:

- detecting whether a step has structured progress data
- deciding whether the heartbeat should render
- deciding whether the completed `100%` badge should render
- generating the inline heartbeat HTML
- generating the inline completed badge HTML (`💯` emoji)
- rendering progress rows for both admin pages
- latching the completed badge once shown so stale poll responses cannot revert it back to the heartbeat

Suggested public API:

```js
renderImportStepsShared(steps, options = {})
```

Possible options:

- `tableCounts`
- `showProgressBar`
- `showCompletedBadge`
- `label`
- `statusIndentPx`

This keeps one shared renderer flexible enough for both pages, which currently have similar but not identical `renderImportSteps(...)` implementations.

## Render rules
Show the heartbeat only when all of the following are true:

- the step has a `progress` object
- `progress.processed` and `progress.total` are numeric
- `progress.total > 0`
- the step status is neither `ok` nor `error`
- `processed < total`

Show the completed `100%` badge only when all of the following are true:

- the step has a `progress` object
- `progress.processed` and `progress.total` are numeric
- `progress.total > 0`
- `processed >= total` or the step status is `ok`
- the step status is **not** `error` (error always takes precedence)

Hide the heartbeat when:

- the step finishes successfully
- the step errors
- there is no structured progress object
- the total is zero or invalid

Hide the completed `100%` badge when:

- the step is still in progress
- the step errors
- there is no structured progress object
- the total is zero or invalid

## Compatibility with existing pages
### `admin_database_load_import.php`
This page already renders a proper progress bar, so the heartbeat should appear alongside the numeric progress text while active, and the `100%` badge should replace it when complete, without otherwise changing the current page behavior.

### `admin_database_load_import_media_from_folder.php`
This page currently shows simpler step text, including `PROGRESS METER: Processed X / Y` messages. The shared helper can support either:

- minimal mode: heartbeat beside the existing text only
- enhanced mode: heartbeat plus a small progress bar and completed `100%` badge

For the first implementation, minimal mode is the safest and least invasive choice.

## Example visual outcome
Inline text example:

```text
PROGRESS METER: Processed 600 / 2400 ♥
```

Completed inline text example:

```text
PROGRESS METER: Processed 2400 / 2400 100%
```

With bar example:

```text
600 / 2400 (25%) ♥
[██████░░░░░░░░░░░░░░░░]
```

Completed with bar example:

```text
2400 / 2400 (100%) 100%
[████████████████████████]
```

The heart should feel like a subtle liveness cue rather than a decorative element. The `100%` badge should feel like a tiny completion stamp.

## Accessibility notes
The heartbeat and completed `100%` badge should be decorative only.

Recommended behavior:

- mark the heart `aria-hidden="true"`
- mark the completed `100%` badge `aria-hidden="true"`
- keep the numeric progress text (e.g. `600 / 2400 (25%)`) as the real accessible status signal; screen readers reading that text is sufficient
- avoid flashing too quickly
- the pulse animation must respect `@media (prefers-reduced-motion: reduce)` by disabling or minimizing the scale and glow effect

## Risks
- Adding shared assets introduces one more dependency for both pages to load.
- If the two pages diverge further in the future, the shared JS helper may need one or two page-specific options.
- Overly strong animation or glow could become distracting, so the effect should stay restrained.

## Recommendation
Proceed with:

1. shared external CSS and JS assets
2. heartbeat while active and tiny `100%` completed badge when finished
3. minimal first pass on the folder import page
4. reuse the same helper on both import admin pages

This gives a cleaner implementation than duplicating inline code in both PHP files and is much simpler than trying to animate a tiny bee icon at text height.

## Addendum: ETA helper

`import_progress.js` also exports a second helper:

```js
getImportProgressEtaText(steps, elapsedMs)
```

### Purpose
Produces a short ETA string (e.g. `ETA: 13m 08s`) for display beside the live elapsed-time line on import pages that poll job status.

### Behaviour
- Scans `steps` for the first active step with valid `progress.processed` and `progress.total`.
- Uses simple linear extrapolation: `(elapsed / processed) * remaining`.
- Returns `''` (empty string) when there is insufficient data — no progress yet, no active step, or the job is already done or errored — so the caller can safely omit it from the UI with a single truthiness check.
- Once the ETA string is available the caller appends it to the existing elapsed display: `(elapsed: 4m 27s — ETA: 13m 08s)`.

### Where it is used
Currently wired into `pollManifestJob()` in `admin_database_load_import_media_from_folder.php` only, since that is the only page with a live polling status line showing elapsed time. The CSV import page (`admin_database_load_import.php`) does not poll live during the request so there is no equivalent line to append ETA to.

### Format
| Remaining time | Example output |
|---|---|
| Under 1 hour | `ETA: 3m 42s` |
| 1 hour or more | `ETA: 1h 04m 17s` |
