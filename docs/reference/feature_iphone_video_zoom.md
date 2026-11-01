# iPhone Video Zoom Feature Plan

## Goal

Add a simple but effective zoom-and-pan experience for iPhone video playback so users can inspect musicians and activity within jam videos more closely.

The target interaction model is:

- Double-tap to increase zoom level toward the tap location
- Pinch to freeform zoom between levels
- Pan while zoomed in
- Support zoom levels from `1x` through `4x`

## Why this feature is needed

The current implementation uses `AVPlayerViewController` for video playback. That gives reliable Apple-native playback controls, but it does not expose configurable zoom behavior. The built-in player cannot be tuned to provide deeper zoom levels, fixed zoom steps, zoom-to-tap behavior, or freeform pinch zoom.

To support the desired interaction model, video presentation must move from the stock `AVPlayerViewController` surface to a custom zoomable video surface while keeping the existing `AVPlayer` playback engine.

## Product requirements

### Core interactions

- Default zoom starts at `1x`
- Double-tap zooms in stepwise
- Double-tap zoom is centered toward the tap location, not just the center of the screen
- Pinch supports freeform zoom between `1.0` and `4.0`
- Pan is enabled automatically when zoom is greater than `1x`
- Video playback continues normally while zooming and panning

### Double-tap behavior

Recommended step logic:

- If current zoom is less than `1.5`, zoom to `2x`
- If current zoom is less than `2.5`, zoom to `3x`
- If current zoom is less than `3.5`, zoom to `4x`
- If current zoom is at or near `4x`, the next double-tap resets to `1x`

This makes double-tap usable both from exact zoom levels and from arbitrary pinch-derived zoom scales.

### Pinch behavior

- Minimum zoom scale: `1.0`
- Maximum zoom scale: `4.0`
- No snap-to-step required after pinch ends
- Preserve the exact user-selected zoom scale after pinch

### Pan behavior

- Panning is active only when content is zoomed beyond `1x`
- Content should remain centered when zoomed back out to `1x`
- Bounds should prevent excessive blank space around the video

## Technical approach

## Overview

Keep the existing `AVPlayer`, `AVPlayerItem`, diagnostics, loading state, authentication, and resource loading flow. Replace only the video presentation layer.

Current video path:

- `MediaPlayerView`
- `PlayerViewController : UIViewControllerRepresentable`
- `AVPlayerViewController`

Target video path:

- `MediaPlayerView`
- `ZoomableVideoPlayerView : UIViewRepresentable`
- `UIScrollView` host for zooming/panning
- embedded custom video rendering view backed by `AVPlayerLayer`

## Proposed components

### 1. `ZoomableVideoPlayerView`

Create a SwiftUI bridge responsible for rendering zoomable video.

Responsibilities:

- Accept an `AVPlayer`
- Host the UIKit zoom/pan surface
- Coordinate gesture handling and zoom state updates
- Expose callbacks if needed for current zoom scale changes

Suggested API shape:

- input: `player: AVPlayer`
- input: `minZoomScale: CGFloat = 1.0`
- input: `maxZoomScale: CGFloat = 4.0`
- optional input: callbacks for zoom state changes

### 2. `ZoomableVideoContainerView`

Create a UIKit container view that owns:

- `UIScrollView`
- inner content view
- `PlayerLayerView` containing the `AVPlayerLayer`
- double-tap gesture recognizer

Responsibilities:

- Implement `UIScrollViewDelegate`
- Return the video view from `viewForZooming(in:)`
- Maintain correct centering during zoom changes
- Convert double-tap touch points into zoom rects
- Preserve aspect ratio and fit-to-screen layout at `1x`

### 3. `PlayerLayerView`

Create a `UIView` subclass whose backing layer is `AVPlayerLayer`.

Responsibilities:

- Expose `player` assignment
- Use `AVLayerVideoGravity.resizeAspect`
- Resize correctly with parent layout changes

This keeps the video rendering path simple and avoids rebuilding playback behavior.

## Gesture design

### Double-tap zoom to tap location

Implementation strategy:

- Add a double-tap recognizer to the zoom container
- Read the tap location in the zoomable content coordinate space
- Compute the next zoom target using the step logic above
- If the target zoom is `1x`, zoom out to the full visible rect
- Otherwise compute a zoom rect centered on the tap point
- Animate the zoom transition

Important UX detail:

- The zoom rect should bias toward the tap location so the selected musician or visual area stays under focus during zoom-in

### Pinch zoom

Use `UIScrollView` native zoom support.

Benefits:

- smooth freeform zoom
- natural pan/zoom behavior
- lower custom gesture complexity

## Media controls strategy

Replacing `AVPlayerViewController` means losing Apple’s built-in playback chrome for the video surface. A minimal first version should restore only the essentials.

### Phase 1 controls

- Tap-to-show overlay controls
- Play/Pause button
- Close button remains in the navigation bar or top overlay

### Phase 2 controls if needed

- Scrubber/timeline
- Elapsed time / duration
- Skip forward/back
- Explicit zoom badge showing current zoom level

For the first version, the smallest workable implementation is:

- keep existing playback state observation in `MediaPlayerView`
- add a lightweight play/pause overlay
- keep current error/loading overlays

## Files likely to change

### Existing files

- `GigHive/Sources/App/MediaPlayerView.swift`
  - replace the video-only `PlayerViewController` usage
  - keep audio path unchanged
  - integrate custom zoomable video surface
  - preserve existing loading/failure overlays and player lifecycle logic

### New files recommended

- `GigHive/Sources/App/ZoomableVideoPlayerView.swift`
- `GigHive/Sources/App/PlayerLayerView.swift`

Depending on implementation preference, `PlayerLayerView` can also be nested inside `ZoomableVideoPlayerView.swift`.

## Implementation phases

## Phase 1: Custom video surface

Deliverables:

- Replace `AVPlayerViewController` with `AVPlayerLayer`-based rendering for video only
- Keep existing `AVPlayer` setup and diagnostics intact
- Preserve current load/failure overlays
- Add play/pause overlay if needed for usability

Validation:

- Video still loads and plays for small and large files
- Existing buffering and failure diagnostics still appear
- Close and reopen behavior remains stable

## Phase 2: Zoom and pan foundation

Deliverables:

- `UIScrollView` zoom host
- Pinch-to-zoom from `1x` to `4x`
- Pan while zoomed
- Correct centering when returning to `1x`

Validation:

- Smooth pinch zoom
- No layout drift or clipping
- No blank margins beyond expected bounds

## Phase 3: Double-tap step zoom

Deliverables:

- Double-tap increments zoom stepwise
- Zoom targets the tap location
- `4x` double-tap resets to `1x`

Validation:

- Repeated double-taps cycle predictably
- Zoom lands where the user tapped
- Transition animation feels smooth and controlled

## Phase 4: Playback usability polish

Deliverables:

- Play/pause overlay behavior refinement
- Optional current zoom indicator
- Optional single-tap show/hide controls

Validation:

- Users can zoom, pan, and still operate playback easily
- Controls do not interfere with gestures

## Risks and tradeoffs

### Loss of stock player conveniences

By moving away from `AVPlayerViewController` for video presentation, the app may lose or need to re-create:

- Apple-native playback controls
- system-managed fullscreen behavior
- PiP convenience for video

If PiP or full stock controls are required long-term, they will need separate follow-up design work.

### Gesture/control conflicts

Potential conflicts:

- single tap vs double tap
- pinch vs overlay interactions
- pan vs scrubbing if a custom timeline is later added

These should be managed in the UIKit container and kept minimal in the first release.

### Performance considerations

- `4x` zoom is digital zoom only
- it enlarges existing pixels rather than creating new detail
- very large videos should still render acceptably, but testing is needed on older iPhones

## Recommended first release scope

Implement the smallest version that meets the use case:

- custom `AVPlayerLayer` video surface
- `UIScrollView` zoom/pan
- pinch zoom `1x`-`4x`
- double-tap step zoom toward tap location
- play/pause overlay
- keep existing load/failure overlays and close behavior

Do not attempt in the first pass:

- full custom scrubber
- PiP parity
- advanced fullscreen transitions
- zoom persistence across playback sessions

## Test plan

### Functional tests

- Open video and verify playback starts normally
- Double-tap repeatedly and confirm `1x -> 2x -> 3x -> 4x -> 1x`
- Confirm zoom targets the double-tap location
- Pinch to arbitrary values between `1x` and `4x`
- Pan while zoomed in
- Return to `1x` and verify recenters correctly

### Playback stability tests

- Large uploaded video playback
- Quick close/reopen during startup
- Mid-playback buffering and recovery
- Multiple consecutive play/close cycles

### Device tests

- iPhone portrait
- iPhone landscape
- Different screen sizes if available

## Success criteria

The feature is successful when:

- users can reliably zoom in on musicians during video playback
- double-tap focuses on the chosen area of the frame
- pinch and pan feel natural and smooth
- existing video playback stability is preserved
- no regression is introduced for large-file playback or close/reopen behavior
