# StormPigs Modern Timeline

Self-contained timeline widget integrated directly into the PHP site. No npm/Angular build is required.

## Files
- `modern-timeline-enhanced.css` – Styles for the timeline, markers, cards, modal.
- `modern-timeline-enhanced.js` – Timeline class, event rendering, zoom/drag, modal, data loading.
- `timeline-api.php` – PHP endpoint that returns jam events as JSON from MySQL.

Optional (fallback):
- `timeline.xml` – Legacy XML data file. Only used if the API fails. Place it in this `timeline/` directory as `timeline.xml` if you want the fallback enabled.

## Where it is included
- `header.php` (conditional for `index.php`):
  - `<link rel="stylesheet" href="timeline/modern-timeline-enhanced.css">`
  - `<script src="timeline/modern-timeline-enhanced.js"></script>`
- `index.php` contains the container:
  - `<div id="my-timeline" style="height: 340px; border: 1px solid #EB0"></div>`

## Initialization
The timeline initializes on page load via `initStormPigsTimeline()` in `header.php` (only for `index.php`).

## Data flow
- JS attempts `fetch('timeline/timeline-api.php')` first.
- If the API fails, it attempts `fetch('timeline/timeline.xml')`.
- If both fail, it renders embedded sample data.

## Configuration
- Default zoom (in JS constructor): `this.zoomLevel = 0.63` (63%).
- Wrapper/container height (CSS): set in `.modern-timeline-wrapper` and `.modern-timeline-container`.
- Container height on page: inline style on `#my-timeline` in `index.php`.

### Changing heights
- `timeline/modern-timeline-enhanced.css`:
  - `.modern-timeline-wrapper { height: 340px; }`
  - `.modern-timeline-container { height: 340px; }`
- `index.php`:
  - `#my-timeline` inline style height should match the above to keep borders tight.

### Changing default zoom
- `timeline/modern-timeline-enhanced.js`:
  - Set `this.zoomLevel` in the constructor.
  - Update the label in `render()` if you prefer a static label (it’s also refreshed dynamically).
  - `fitAll()` resets to the same default.

## Interaction details
- Drag-to-scroll with mouse and touch.
- Mouse wheel zoom (Ctrl not required).
- Click an event card to open modal; click overlay or press `Esc` to close.
- Drag-click suppression prevents accidental modal opens after dragging.

## Troubleshooting
- If modal seems stuck: press `Esc` to close. Drag-click suppression is enabled; ensure you release the mouse before clicking an event.
- If data doesn’t load:
  - Check `timeline/timeline-api.php` (server logs / PHP errors).
  - Temporarily place `timeline/timeline.xml` to validate fallback path.

## Archival notes
Legacy assets were moved to `../_archive/`:
- Angular demo: `angular-timeline_YYYYMMDD_HHMMSS/`
- Original Simile timeline: `timeline_2.3.0_YYYYMMDD_HHMMSS/`
- Legacy XML data: `timeline_YYYYMMDD_HHMMSS.xml`

## Development tips
- Keep all timeline assets inside this `timeline/` directory for neatness.
- When moving/renaming, update paths in `header.php` and the fetch URLs in `modern-timeline-enhanced.js`.
- No build step is required. Edit files and refresh.
