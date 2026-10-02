# Research Plan: Cancel “Preparing video from Photos…”

## Goal
Allow the user to cancel the “Preparing video from Photos…” stage (the pre-upload file preparation step) using the same Upload/Cancel button UX used for upload cancellation.

This stage happens **before** any network upload. It is driven by Photos/PHPicker file export/copy work.

## Where the behavior lives
### UI text / progress indicator
- `GigHive/Sources/App/UploadView.swift`
  - Displays:
    - `Preparing video from Photos...`
    - `Converting video to H.264 ...`
  - Controlled by:
    - `@State isLoadingMedia`
    - `@State photoCopyProgress: Double?`

### Photos picker copy/export implementation
- `GigHive/Sources/App/PickerBridges.swift`
  - `PHPickerView.Coordinator.picker(_:didFinishPicking:)`
  - Key call:
    - `provider.loadFileRepresentation(forTypeIdentifier: UTType.movie.identifier) { ... }`
  - Cancellation-relevant detail:
    - `loadFileRepresentation(...)` returns a `Progress` object (`loadProgress`)
    - current code observes `loadProgress.fractionCompleted` and also uses a polling timer

## What is cancellable today
### Cancellable
- The **Photos export phase** initiated by `NSItemProvider.loadFileRepresentation(...)` is cancellable in principle via:
  - `loadProgress.cancel()`

This should stop the export/copy process managed by the framework and stop progress updates.

### Not (cleanly) cancellable in the current implementation
- The final persistence step uses a synchronous file copy:
  - `FileManager.default.copyItem(at: url, to: tmp)`

Once this starts, it is not easily interruptible without refactoring into a chunked, cancellable copy loop.

## What was missing (why Upload button couldn’t cancel it)
- The `Progress` object (`loadProgress`) exists only inside `PHPickerView.Coordinator`.
- `UploadView` has no reference to:
  - the coordinator
  - the `Progress`
  - any explicit cancellation closure

`PHPickerView` currently exposes:
- `onCopyStarted: (() -> Void)?`
- `onCopyProgress: ((Double) -> Void)?`

…but no cancel hook.

## What we implemented
### Files changed
- `GigHive/Sources/App/PickerBridges.swift`
- `GigHive/Sources/App/UploadView.swift`

### Picker layer: expose a cancel hook
- Added `onCopyCancelAvailable: (((() -> Void)?) -> Void)?` to `PHPickerView`.
- In `PHPickerView.Coordinator.picker(_:didFinishPicking:)`:
  - capture the `Progress` returned by `loadFileRepresentation` and expose a cancel closure to the parent.
  - cancel closure does:
    - set a `didCancelLoad` flag
    - call `loadProgress.cancel()`
    - invalidate/stop `progressObservation` and `progressTimer`
    - call `selectionHandler(nil)` on main to clear selection
  - clear the cancel hook (`onCopyCancelAvailable?(nil)`) on completion.

### UI layer: allow the Upload/Cancel button to cancel preparation
- Added `@State private var cancelPreparingMedia: (() -> Void)?`.
- When `isLoadingMedia == true`, the main button behaves as Cancel:
  - calls `cancelPreparingMedia?()`
  - resets `isLoadingMedia`, `photoCopyProgress`, and `mediaLoadingStartedAt` immediately.
- `UploadView` captures/clears the cancel hook via the `onCopyCancelAvailable` callback.

### Debugging instrumentation
- Added `logWithTimestamp(...)` markers for:
  - cancel hook install/clear
  - cancel button press
  - before/after calling `Progress.cancel()`
  - completion callback state (didCancel + isCancelled + counts)

## Observed behavior in testing
### Progress percent is not byte-based
- `loadFileRepresentation(...)` returns a `Progress`, but for large videos the observed `totalUnitCount` was `10000` (normalized units) rather than bytes.
- Because of this, `fractionCompleted` and the percent UI can:
  - jump suddenly (e.g. start at 47% or 72%)
  - repeat the same value for long periods

### Cancellation semantics
- In logs, cancellation reliably flips `isCancelled` to `true`.
- The completion callback often fires immediately after cancellation.
- We explicitly ignore completion when cancel was requested (`didCancelLoad == true` or `Progress.isCancelled == true`).

### Why percent can “resume” on next pick
- When selecting the same Photos asset again, the first KVO update can start at a high percent.
- This is consistent with iOS/Photos caching/resuming internal work (e.g. iCloud download / representation preparation) and does not necessarily mean our cancelled request is still active.

## Potential improvements (if this becomes an issue)
### UX improvements (minimal)
- Use an indeterminate progress indicator during Photos preparation instead of showing percent.
- Alternatively, treat the first observed `fractionCompleted` as a baseline and display delta progress (still not byte-accurate).

### More reliable cancellation / progress control (larger refactor)
- Move away from `NSItemProvider.loadFileRepresentation` and use Photos APIs with explicit cancellable request IDs (e.g. PHAsset/PHImageManager-based requests) so the app can cancel the system request more directly.
- Replace `FileManager.copyItem` with a cancellable stream copy loop (InputStream/OutputStream in chunks) to make the final persistence step interruptible.

## Proposed approach (implementation-level plan)
1. **Expose a cancel hook from the Photos picker layer**
   - Add a callback or binding such as:
     - `onCopyCancelAvailable: (((() -> Void)) -> Void)?`
     - or store a cancel closure in a shared `@State`/`@Binding` in `UploadView`.
   - When the coordinator receives `loadProgress`, set the cancel closure to call:
     - `loadProgress.cancel()`
     - invalidate `progressObservation`
     - stop `progressTimer`
     - invoke `selectionHandler(nil)` (or equivalent) to clear the chosen media state

2. **Wire Upload button “Cancel” to cancel pre-upload preparation**
   - When `isLoadingMedia == true` (preparation in progress), the button’s cancel behavior should:
     - call the stored cancel closure (if available)
     - update the same UI messaging pattern you already use (e.g. “cancelled”)
     - reset `isLoadingMedia` / `photoCopyProgress`

3. **Decide what to do about the final `copyItem` step**
   - Accept that cancel may not interrupt a synchronous `copyItem` already in progress.
   - If needed later, refactor persistence copy to a cancellable stream copy:
     - open `InputStream` on source, `OutputStream` on destination
     - copy in chunks
     - check `Task.isCancelled` between chunks

## Minimal-change plan (files 1-3)
### 1) `GigHive/Sources/App/PickerBridges.swift`
- Add a new optional callback on `PHPickerView` to surface cancellation to the parent view. Minimal API shape options:
  - `var onCopyCancelAvailable: (((() -> Void)?) -> Void)? = nil`
  - or `var setCancelCopy: (((() -> Void)?) -> Void)? = nil`

- In `PHPickerView.Coordinator.picker(_:didFinishPicking:)`:
  - Immediately after acquiring `loadProgress`, call the new callback with a cancel closure.
  - The cancel closure should:
    - call `loadProgress.cancel()`
    - invalidate/stop:
      - `progressObservation`
      - `progressTimer`
    - and then notify the UI that selection is cleared (e.g. `parent.selectionHandler(nil)` on main)

- When the load completes successfully (or fails), clear the cancel closure by calling the callback with `nil` so stale cancel hooks aren’t retained.

### 2) `GigHive/Sources/App/UploadView.swift`
- Add a `@State` property to store the “cancel Photos preparation” hook, e.g.:
  - `@State private var cancelPreparingMedia: (() -> Void)? = nil`

- When constructing `PHPickerView`, pass the new callback so `UploadView` can capture the cancel closure:
  - set `cancelPreparingMedia = cancelClosure` when provided
  - set `cancelPreparingMedia = nil` when cleared

- Update the Upload/Cancel button behavior:
  - If `isLoadingMedia == true` and `cancelPreparingMedia != nil`, treat Cancel as “cancel preparation” and call `cancelPreparingMedia?()`.
  - After cancelling, reset:
    - `isLoadingMedia`
    - `photoCopyProgress`
    - `fileURL` (if appropriate)
    - `cancelPreparingMedia`

### 3) `gighiveinfra/docs/rearch_prepare_video_cancel.md`
- Keep this doc updated with:
  - the final chosen callback name/signature
  - the exact UI state fields used to decide “Cancel means cancel preparation”
  - any edge cases discovered (e.g. cancel after `copyItem` begins)

## Validation checklist
- Start picking a large video from Photos and observe progress.
- Tap cancel during “Preparing video from Photos…” and verify:
  - progress indicator stops
  - UI returns to idle state
  - no file is selected
  - a subsequent selection+upload still works
- Cancel during/after persistence copy and confirm no crash.

## Notes
- The UI string includes “Converting video to H.264 …” but the current code path in `PickerBridges.swift` does not explicitly run an `AVAssetExportSession`. The work appears to be driven by `loadFileRepresentation` + a file copy. If there is an additional H.264 conversion path elsewhere, it should be treated separately (and would likely be cancellable via `AVAssetExportSession.cancelExport()`).
