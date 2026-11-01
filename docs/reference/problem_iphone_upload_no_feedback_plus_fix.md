---
description: GuestUploadView shows no feedback during PHPicker iCloud download and allows concurrent file selections — root cause and fix
---

# Problem: GuestUploadView — No Feedback During File Load + Double-Selection Bug

**Date:** 2026-07-19
**Scope:** `GigHive/Sources/App/GuestUploadView.swift`
**Status:** Implemented 2026-07-19

---

## PHPicker Export Phase Sequence — Critical Background

> **Why this matters:** Understanding this sequence is essential for any future work on the video picker pipeline. It explains why a 25-minute video failed silently at 91% with a disk-full error, why file size validation cannot happen until the very end, and why a `loadItem` metadata probe cannot serve as an early gate in the current implementation.

### The sequence

1. **User selects video** in `PHPickerViewController` → `picker(_:didFinishPicking:)` fires in the coordinator.
2. **`loadItem` metadata probe fires** — `provider.loadItem(forTypeIdentifier:)` is called. For a locally-stored asset this completes in milliseconds and returns a URL pointing to the original Photos library file. The result is logged (`expectedSize`) but **the variable is never read by any downstream gate**. It runs concurrently with step 3 — not before it. This is effectively a dead debug probe in its current form.
3. **`loadFileRepresentation` begins** — iOS starts exporting the video from the Photos library to a system temp path. For on-device video this means **transcoding** (e.g., HEVC 4K 60fps → H.264 .mp4 wrapper). For iCloud-only video it also means downloading the asset first. **This is the long operation.** A 25-minute 4K 60fps HEVC video (11.83 GB) takes several minutes. The `Progress` object drives the progress bar (0–100%).
4. **Disk-full failure can occur mid-export** — iOS needs free scratch space equal to the full exported file size. If the device runs out during export, iOS surfaces `AVFoundationErrorDomain Code=-11807 "Disk Full"` and the `loadFileRepresentation` callback fires with `url = nil`. Before the fix landed on 2026-07-19, this silently reset the UI to the blank "Choose Video" state with no explanation. The 11.83 GB video failed at 91%, meaning ~10.8 GB was written successfully before space ran out.
5. **App reads file size only after full export completes** — the `onFileTooLarge` callback and the file size shown in the picker label are both determined at the moment `selectionHandler` would have fired. **There is no mechanism for earlier rejection mid-export.** A 11.83 GB file (1.8× the 6.44 GB limit) had to spend several minutes exporting before the app could tell the user it was too large.

### Implications

- **Disk full:** the user needs free space ≥ 1× the exported file size before retrying. A partial temp file from the failed attempt may still be on disk (cleared by reboot or iOS memory pressure), so a reboot before retrying is advisable. The safe rule of thumb: check Settings → General → iPhone Storage and ensure free space exceeds the video's estimated size.
- **File Too Large:** there is currently no way to warn the user before the multi-minute export completes. A future improvement is described in Appendix A5.
- **`loadItem` is not the bottleneck:** `loadItem` returns quickly for local assets. The slow operation is always `loadFileRepresentation`. If logs show no progress for an extended period at the start, that is the iCloud download phase inside `loadFileRepresentation` (before `totalUnitCount` becomes non-zero), not `loadItem`.

---

## Summary

`GuestUploadView` wires only one of five available `PHPickerView` callbacks, leaving out the progress and cancel hooks. For large iCloud-hosted videos this produces a multi-minute silent freeze and allows the user to launch a second concurrent file load.

---

## Impact

- Guest users uploading large videos (>500 MB, iCloud download required) see no feedback for several minutes and reasonably assume the tap did not register.
- A second tap opens a second `PHPickerView` session; both `loadFileRepresentation` calls run concurrently. The last one to complete wins `fileURL`, silently discarding the other.
- No cancel path exists, so an accidental selection of a very large file cannot be aborted.

---

## Symptoms

1. After choosing a video "From Photos", the "Video file" row stays as "Choose Video" with a paperclip icon and no animation. For a 2m 37s video (≈ 1.75 GB iCloud download) the silent wait was **several minutes**.

2. During that wait the user can tap "Choose Video" again and start a second `PHPickerView` session, causing two concurrent `loadFileRepresentation` downloads.

### Evidence from Xcode log (Jul 19 2026)

Two interleaved progress streams visible simultaneously — one climbing ~1→46%, a second at ~53→59%:

```
[2026-07-19T20:32:25.625Z] 📈 [PHPicker] Progress KVO update: 1%
[2026-07-19T20:32:28.511Z] 📈 [PHPicker] Progress KVO update: 1%   ← second stream begins
[2026-07-19T20:32:28.268Z] 📈 [PHPicker] Progress KVO update: 53%  ← first stream continues
...
[2026-07-19T20:35:34.509Z] ✅ [PHPicker] loadFileRepresentation completed ... size: 1.75 GB
[2026-07-19T20:37:12.270Z] ✅ [PHPicker] loadFileRepresentation completed ... size: 498.2 MB
```

---

## Root Cause

`PHPickerView` in `PickerBridges.swift` exposes five callbacks:

| Callback | Purpose |
|---|---|
| `selectionHandler` | Final URL (or nil on cancel/error) |
| `onFileTooLarge` | Fires before `selectionHandler` if file exceeds `AppConstants.MAX_UPLOAD_SIZE_BYTES` |
| `onCopyStarted` | Fires when `loadFileRepresentation` begins (iCloud download starts); bridge already dispatches to main |
| `onCopyProgress` | Fires on each `fractionCompleted` update 0.0→1.0; bridge already dispatches to main |
| `onCopyCancelAvailable` | Delivers a cancel closure once the `Progress` object is available |

`GuestUploadView` wires **only** `selectionHandler`:

```swift
// GuestUploadView.swift — current (incomplete)
PHPickerView(selectionHandler: { url in
    showPhotosPicker = false
    fileURL = url
})
```

`UploadView` wires all five, driving `isLoadingMedia`, `photoCopyProgress`, and `cancelPreparingMedia` state that powers a progress bar and a "Cancel" button. Because `GuestUploadView` skips the other four:

- `onCopyStarted` never fires → `isLoadingMedia` never set → no loading indicator
- `onCopyProgress` never fires → no progress bar
- `onCopyCancelAvailable` never fires → no cancel path
- The "Choose Video" `Menu` has no `.disabled` guard → second picker can be opened mid-load

`UploadView` is not affected because `isLoadingMedia = true` is set via `onCopyStarted`, showing a live progress bar and changing the Upload button to "Cancel", which prevents the user from selecting a second file in practice.

---

## Resolution

Transplant the loading-state machinery from `UploadView` into `GuestUploadView`.

### Step 1 — Add state vars

```swift
@State private var isLoadingMedia = false
@State private var photoCopyProgress: Double? = nil
@State private var cancelPreparingMedia: (() -> Void)? = nil
@State private var videoDisplayName: String? = nil
@State private var videoSource = ""
```

`videoSource` captures which picker was used ("From Photos" / "From Files") so the display name can be set after the sheet closes. `videoDisplayName` replaces `fileURL?.lastPathComponent` in the Menu label — see Step 6.

Note: `AppConstants` is declared in `AppConstants.swift` and is module-accessible.

### Step 2 — Wire all PHPickerView callbacks

The bridge dispatches **all five callbacks** to the main queue (verified in `PickerBridges.swift` — `onCopyStarted` line 53, `onCopyProgress` line 179, `onFileTooLarge` line 119, `selectionHandler` lines 83/89/126, `onCopyCancelAvailable` line 132) — do **not** add extra `DispatchQueue.main.async` wrappers inside any of these callbacks.

```swift
PHPickerView(
    selectionHandler: { url in
        // url is nil on cancel or too-large rejection; non-nil on success.
        // Note: setting fileURL = nil on cancel discards any previously loaded file — intentional, mirrors UploadView.
        showPhotosPicker = false
        fileURL = url
        isLoadingMedia = false
        photoCopyProgress = nil
        cancelPreparingMedia = nil
    },
    onFileTooLarge: { fileSize, maxSize in
        showPhotosPicker = false
        isLoadingMedia = false
        photoCopyProgress = nil
        cancelPreparingMedia = nil
        DispatchQueue.main.asyncAfter(deadline: .now() + 0.1) {
            alertTitle = "File Too Large"
            alertMessage = "The selected file (\(fileSize)) exceeds the maximum allowed upload size of \(maxSize). Please select a smaller file or compress the video before uploading."
            showResultAlert = true
        }
    },
    onCopyStarted: {
        isLoadingMedia = true
        photoCopyProgress = nil
    },
    onCopyProgress: { progress in
        photoCopyProgress = progress
    },
    onCopyCancelAvailable: { cancel in
        cancelPreparingMedia = cancel
    }
)
```

**Why `isLoadingMedia` is cleared in `selectionHandler` (not in `onCopyProgress`):** the bridge calls `onCopyProgress(1.0)` and then immediately calls `selectionHandler`. Clearing `isLoadingMedia` at progress 1.0 creates a brief frame where the progress bar disappears before `fileURL` is populated, causing a visual flicker. Clearing it in `selectionHandler` avoids this.

### Step 3 — Show progress UI in the Video file section

Insert below the "Choose Video" `Menu`, inside the `VStack` of the video file field:

```swift
if isLoadingMedia {
    HStack(spacing: 8) {
        if let progress = photoCopyProgress {
            ProgressView(value: progress)
                .progressViewStyle(LinearProgressViewStyle(tint: GHTheme.accent))
        } else {
            ProgressView()
                .progressViewStyle(CircularProgressViewStyle(tint: GHTheme.accent))
        }
        Text(photoCopyProgress.map { "Loading… \(Int($0 * 100))%" } ?? "Loading video…")
            .font(.caption)
            .ghForeground(GHTheme.muted)
        Spacer()
        Button("Cancel") {
            cancelPreparingMedia?()
            isLoadingMedia = false
            photoCopyProgress = nil
            cancelPreparingMedia = nil
            videoDisplayName = nil
        }
        .font(.caption)
        .ghForeground(GHTheme.accent)
    }
    .padding(.vertical, 4)
    Text("Do not navigate away from this screen or the file load will be cancelled.")
        .font(.caption2)
        .foregroundColor(.orange)
}
```

Note: when `cancelPreparingMedia?()` fires, the bridge also asynchronously calls `selectionHandler(nil)`, which will clear these vars again — harmless double-clear.

Also guard the `missingFields()` warning display with `!isLoadingMedia` so "Please select a video file" is suppressed while the file is loading:

```swift
if !isUploading && !isLoadingMedia {
    // missingFields() display
}
```

### Step 4 — Disable the picker Menu and Upload button while loading

```swift
Menu { ... } label: { ... }
    .disabled(isLoadingMedia)
```

Also add `|| isLoadingMedia` to the Upload button's existing `.disabled` condition:

```swift
.disabled(isUploading || isLoadingMedia || fileURL == nil || !guestSession.tosAccepted || ...)
```

### Step 5 — Wire the Files picker (DocumentPickerView)

The Files picker is fast (local files), but should be consistent. Set `isLoadingMedia = true` when the "From Files" menu item is tapped, clear it in `onPick`, and wire `onFileTooLarge`:

```swift
Button("From Files") {
    isLoadingMedia = true
    showFilesPicker = true
}
```

```swift
DocumentPickerView(
    allowedTypes: [UTType.movie, UTType.mpeg4Movie],
    onPick: { url in
        showFilesPicker = false
        fileURL = url
        isLoadingMedia = false
    },
    onFileTooLarge: { fileSize, maxSize in
        showFilesPicker = false
        isLoadingMedia = false
        DispatchQueue.main.asyncAfter(deadline: .now() + 0.1) {
            alertTitle = "File Too Large"
            alertMessage = "The selected file (\(fileSize)) exceeds the maximum allowed upload size of \(maxSize). Please select a smaller file or compress the video before uploading."
            showResultAlert = true
        }
    }
)
```

### SonarQube / Best-Practice Notes

- **RSPEC-107 (too many parameters):** `PHPickerView` is now called with 5 arguments. This already exists in `UploadView`; no new violation introduced.
- **Closure in `@State`:** `cancelPreparingMedia: (() -> Void)?` stored as `@State` is unusual and may trigger a capture-cycle lint warning. This pattern is already used in `UploadView` via `UploadStateStore`; acceptable here.

### Step 6 — Display name and max upload size hint

Replace `fileURL?.lastPathComponent` in the Menu label with a user-friendly string, and add a max-size hint above the picker.

```swift
// Menu label text
Text(videoDisplayName ?? "Choose Video")
```

Set `videoSource` in each menu button before opening the picker, then build `videoDisplayName` in `selectionHandler` / `onPick` after the file is available:

```swift
videoDisplayName = url.map { u in
    let size = formattedFileSize(u)
    return size.isEmpty ? videoSource : "\(videoSource) · \(size)"
}
```

```swift
private func formattedFileSize(_ url: URL) -> String {
    guard let size = (try? FileManager.default.attributesOfItem(atPath: url.path)[.size]) as? Int64,
          size > 0 else { return "" }
    let formatter = ByteCountFormatter()
    formatter.countStyle = .file
    return formatter.string(fromByteCount: size)
}
```

Add a max-size hint between the honor-system warning and the Video file picker:

```swift
Text("Max upload size: \(AppConstants.MAX_UPLOAD_SIZE_FORMATTED)")
    .font(.caption2)
    .ghForeground(GHTheme.muted)
```

### Navigation-away behavior

`GuestUploadView` uses local `@State`. If the user pops back within the app while `isLoadingMedia` is true, the view is torn down and the `Progress` object is orphaned (the iCloud download continues on the background thread but `selectionHandler` fires into a deallocated coordinator — no-op). Backgrounding the app (pressing Home / switching apps) **pauses** `loadFileRepresentation` rather than cancelling it; the download resumes automatically when the app returns to the foreground (confirmed in live testing Jul 19 2026 — progress froze at 62%, UUID filename appeared in the picker after returning). This is acceptable for the current scope — `UploadView` handles navigation-away via `UploadStateStore` persistence but `GuestUploadView` does not need that level of robustness.

---

## Files to Change

1. `GigHive/Sources/App/GuestUploadView.swift` (gighiveapp) — add `isLoadingMedia`, `photoCopyProgress`, `cancelPreparingMedia`, `videoDisplayName`, `videoSource` `@State` vars; wire all five `PHPickerView` callbacks; add progress bar + "Do not navigate away" warning + Cancel UI below the video picker Menu; disable Menu and Upload button while `isLoadingMedia` is true; guard `missingFields()` display with `!isLoadingMedia`; set `isLoadingMedia = true` on "From Files" tap; wire `DocumentPickerView.onFileTooLarge`; add `formattedFileSize` helper; show `videoDisplayName` (source + size) in picker label; add max upload size hint above picker.
2. `GigHive/Sources/App/PickerBridges.swift` (gighiveapp) — no changes; all callbacks already exist.

---

## Verification

1. Pick a large video (>500 MB) "From Photos" — verify progress bar appears immediately and percentage climbs.
2. While loading, attempt to tap "Choose Video" — verify the `Menu` is disabled.
3. While loading, tap Cancel — verify loading stops, progress bar disappears, and "Choose Video" re-enables.
4. Pick a video that exceeds the 6 GB limit — verify the "File Too Large" alert fires and the picker resets cleanly.
5. Pick a small video from Files — verify `isLoadingMedia` activates briefly and clears on pick.
6. Complete a full guest upload happy path (small video, name filled, ToS accepted) — verify upload succeeds end-to-end.
7. Verify Xcode log shows only a **single** PHPicker progress stream at a time.

---

## Preventative Actions

- The `UploadView` PHPicker wiring should be treated as the reference implementation. Any future view that adds a video picker should wire all five callbacks from the start.
- Consider adding a lint note or code comment to `PHPickerView` in `PickerBridges.swift` enumerating all callbacks that callers are expected to wire.

---

## Appendix — Observations from Live Testing (Jul 19 2026)

### A1. `loadFileRepresentation` gives UUID-named temp files

For Photos-sourced videos, `loadFileRepresentation` copies the asset to a system temp directory and assigns a UUID filename (e.g. `64C29F96-3FAF-4B06-91E4-21860….mov`). The original Photos asset name is not preserved. Displaying `fileURL?.lastPathComponent` in the picker label therefore shows a meaningless UUID — replaced by `videoDisplayName` (source + size) in this fix.

### A2. File size and `onFileTooLarge` are only knowable after full download

`loadFileRepresentation` must complete the entire iCloud download before the bridge can read the file size. There is no mechanism for early rejection of an oversized file mid-download. `onFileTooLarge` and the file size shown in `videoDisplayName` are both determined at the same moment `selectionHandler` would have fired.

### A3. Backgrounding pauses (not kills) the download

When the app is sent to background during a `loadFileRepresentation` download, iOS suspends all app threads. The Xcode log stops updating (progress froze at 62% in testing). When the user returns to the app, execution resumes and the download continues from where it paused. The UUID filename appeared in the picker label after returning — confirming the download completed and `selectionHandler` fired successfully.

### A4. In-app back-navigation orphans the coordinator

If the user pops `GuestUploadView` off the navigation stack while loading, the `@State` is destroyed and the `PHPickerView` coordinator loses its parent reference. The background download thread continues until it completes or the app backgrounds, then `selectionHandler` fires into a nil parent — a no-op. The downloaded temp file is cleaned up by the system. No crash; no data written.

### A5. `loadItem` as a future early-rejection gate

Key facts about the current state:

- `loadItem` is **fast** — milliseconds for a locally-stored asset; it returns a URL into the Photos library.
- `loadFileRepresentation` is **the slow/heavy operation** — it is the export/transcode, full stop.
- The current `loadItem` call is a **dead debug probe** — `expectedSize` is set in its completion closure but no code ever reads it to decide anything. It fires concurrently with `loadFileRepresentation`, not before it, so it cannot gate anything.
- The limitation in A2 ("no early rejection possible") is true for the exact *exported* size. But the Photos library size that `loadItem` returns **could** serve as a conservative proxy gate — described below.

`provider.loadItem(forTypeIdentifier:)` in `PickerBridges.swift` (lines 61–66) is a **dead debug probe** — `expectedSize` is captured in its completion closure but is never read by any downstream gate. It runs concurrently with `loadFileRepresentation`, not sequentially before it, so it cannot currently prevent an oversized export from starting.

**What `loadItem` actually returns:** For a locally-stored asset, `loadItem` completes in milliseconds and returns a URL pointing to the original file in the Photos library. Reading `.fileSizeKey` from that URL gives the Photos library size (the original HEVC size), not the eventual exported size. For an iCloud-only asset, even `loadItem` may trigger a partial download.

**Why this isn't a complete early rejection gate:** `loadFileRepresentation` may transcode the video (e.g., HEVC → H.264), producing an output that is larger or different in size from the Photos library original. A `loadItem` size check is a *conservative proxy* — if the Photos library size exceeds the limit, the exported size almost certainly will too, so it is safe to reject early. It would not catch the case where the original is under the limit but the transcoded output exceeds it (unlikely in practice for HEVC→H.264 at the same resolution).

**Future improvement:** Make `loadItem` sequential — await its completion before calling `loadFileRepresentation`, then call `onFileTooLarge` immediately and skip the multi-minute export if `expectedSize > AppConstants.MAX_UPLOAD_SIZE_BYTES`. This would save the user from waiting several minutes only to get rejected. For the 11.83 GB video in this incident, the export would never have started: 11.83 GB > 6.44 GB limit is unambiguous at the Photos library size level.

---

## Planned Improvement: iCloud Asset Detection + Confirmation Dialog

**Date planned:** 2026-07-20  
**Status:** Pending implementation  
**Trigger:** Live testing confirmed `loadItem` returns `Zero KB` for iCloud-only HEVC assets. The 0-byte result is not a bug — it is Apple's documented behavior: iOS returns a 0-byte local stub file for any Photos asset not yet downloaded from iCloud. A real video is never 0 bytes, so `size == 0` is a reliable iCloud-only signal without requiring Photos library authorization.

### What is changing and why

Two complementary changes:

**Option C — Extend iCloud hint to cover Photos path**  
The orange tip added on 2026-07-20 ("Tip: Files stored in iCloud will download fully...") is specific to the Files picker. The same problem exists for the Photos picker: an iCloud-only video must download from iCloud *and* transcode before size is checked. Add coverage for Photos to the existing hint text in both `GuestUploadView.swift` and `UploadView.swift`.

**Option B — iCloud detection log (dialog removed)**  
When `loadItem` returns `size == 0`, log the detection and fall through to `startExport`. A confirmation dialog was implemented and tested but removed: the user explicitly tapped the video (their intent is clear), the tip text already set expectations before the picker opened, and the progress bar + Cancel button provide the escape hatch. The dialog added friction without new information.

### Exact changes

#### 1. `PickerBridges.swift` — new callback + detection branch

Add to `PHPickerView` struct:
```swift
var onICloudAsset: ((@escaping () -> Void, @escaping () -> Void) -> Void)? = nil
// First closure  = proceed  → calls startExport
// Second closure = cancel   → calls selectionHandler(nil)
```

In `picker(_:didFinishPicking:)`, inside the `loadItem` completion closure, insert a new branch **before** the size > limit check:

```swift
// New: iCloud-only detection — loadItem returns 0 bytes for undownloaded iCloud assets
if size == 0, let onICloudAsset = self.parent.onICloudAsset {
    logWithTimestamp("☁️ [PHPicker] iCloud-only asset detected — prompting user before export")
    let proceed: () -> Void = { [weak self] in _ = self?.startExport(provider: provider, typeId: typeId) }
    let cancel  = { [weak self] in DispatchQueue.main.async { self?.parent.selectionHandler(nil) } }
    DispatchQueue.main.async { onICloudAsset(proceed, cancel) }
    return
}
// Existing: size > limit → onFileTooLarge
// Existing: size OK      → startExport
// Note: if loadItem returns nil (not a URL at all), the else branch calls startExport directly.
// This is intentional — a nil result is a transient/unknown error, not a confirmed iCloud-only signal.
```

If `onICloudAsset` is not wired (nil), falls through to `startExport` as today — no regression for callers that don't wire it.

#### 2. `GuestUploadView.swift` — state + alert wiring

Add three `@State` vars:
```swift
@State private var iCloudProceedAction: (() -> Void)? = nil
@State private var iCloudCancelAction: (() -> Void)? = nil
@State private var showICloudConfirm = false
```

Wire `onICloudAsset` in the `PHPickerView` call. The 0.1s delay matches the existing `onFileTooLarge` pattern — gives the picker sheet time to fully dismiss before the alert presents:
```swift
onICloudAsset: { proceed, cancel in
    DispatchQueue.main.asyncAfter(deadline: .now() + 0.1) {  // matches existing onFileTooLarge delay
        iCloudProceedAction = proceed
        iCloudCancelAction = cancel
        showICloudConfirm = true
    }
}
```

Add `.alert` (iOS 14 compatible — `.confirmationDialog` is iOS 15+ and must not be used):
```swift
.alert(isPresented: $showICloudConfirm) {
    Alert(
        title: Text("Video Not Downloaded"),
        message: Text("This video is stored in iCloud and must download and export before uploading. For a 12-minute 4K video this may take 5–10 minutes. Continue?"),
        primaryButton: .default(Text("Continue")) {
            iCloudProceedAction?()
            iCloudProceedAction = nil
            iCloudCancelAction = nil
        },
        secondaryButton: .cancel {
            iCloudCancelAction?()   // calls selectionHandler(nil) in bridge to clean up bridge state
            iCloudProceedAction = nil
            iCloudCancelAction = nil
        }
    )
}
```

#### 3. `UploadView.swift` — same pattern

Add the same three `@State` vars after `pendingLoadError` (line ~46):
```swift
@State private var iCloudProceedAction: (() -> Void)? = nil
@State private var iCloudCancelAction: (() -> Void)? = nil
@State private var showICloudConfirm = false
```

Wire `onICloudAsset` in the `PHPickerView` call (after `onLoadError` closure, before `)`). Includes a `logWithTimestamp` call to match `UploadView`'s existing debug-log style:
```swift
}, onICloudAsset: { proceed, cancel in
    logWithTimestamp("☁️ [PHPicker] iCloud-only asset detected, prompting user")
    DispatchQueue.main.asyncAfter(deadline: .now() + 0.1) {
        self.iCloudProceedAction = proceed
        self.iCloudCancelAction = cancel
        self.showICloudConfirm = true
    }
})
```

Add `.alert` after the existing `showResultAlert` alert (line ~839). Uses direct `$showICloudConfirm` state — NOT the `onChange` pattern used for `pendingLoadError`/`pendingFileSizeError`, because those patterns exist to handle logging/timing quirks. The iCloud alert is a user confirmation, not an error signal:
```swift
.alert(isPresented: $showICloudConfirm) {
    Alert(
        title: Text("Video Not Downloaded"),
        message: Text("This video is stored in iCloud and must download and export before uploading. For a 12-minute 4K video this may take 5–10 minutes. Continue?"),
        primaryButton: .default(Text("Continue")) {
            iCloudProceedAction?()
            iCloudProceedAction = nil
            iCloudCancelAction = nil
        },
        secondaryButton: .cancel {
            iCloudCancelAction?()
            iCloudProceedAction = nil
            iCloudCancelAction = nil
        }
    )
}
```

#### 4. Hint text update — both views

Update the existing orange tip in both views from Files-specific language to cover both pickers:

> *Tip: Videos stored in iCloud must download and export before uploading. For a 12-minute 4K video this may take 5–10 minutes. Verify large video sizes before selecting.*

### SonarQube / Best-Practice Notes

- **RSPEC-6426 (force unwrap):** No force unwraps. `iCloudProceedAction?()` uses optional chaining. `[weak self]` on closures eliminates retain cycle risk. ✅
- **RSPEC-107 (too many parameters):** `PHPickerView` will have 7 named properties after adding `onICloudAsset` (`selectionHandler`, `onFileTooLarge`, `onCopyStarted`, `onCopyProgress`, `onCopyCancelAvailable`, `onLoadError`, `onICloudAsset`). RSPEC-107 flags >7 function parameters; at 7 we are at the boundary. All have defaults (`= nil`) so callers are unaffected. Note for future: consider consolidating callbacks into a delegate protocol if count grows further.
- **RSPEC-3776 (cognitive complexity):** The `loadItem` callback gains one `if` branch. The method is short (branch is 4 lines); complexity impact is negligible.

### What is NOT changing

- No Photos library authorization request — the 0-byte heuristic is sufficient
- No change to `DocumentPickerView` — the Files iCloud behavior is inherently not interceptable before the download (iCloud Drive downloads are managed by iOS before the delegate fires)
- No change to the `onFileTooLarge` path — it still fires after export completes as belt-and-suspenders for cases where `loadItem` returned a non-zero size that passed the gate but the transcoded output exceeded the limit
- `startExport` logic is unchanged

### Testing Checklist

| # | Scenario | Expected result |
|---|---|---|
| 1 | Select an iCloud-only video (cloud icon in Photos) | "Video Not Downloaded" alert appears |
| 2 | Alert: tap Continue | Progress bar starts; export runs; file loads normally |
| 3 | Alert: tap Cancel | No progress bar; `isLoadingMedia` stays false; `fileURL` stays nil |
| 4 | Select a locally-downloaded video (no cloud icon) | No alert; export starts immediately |
| 5 | Select a locally-downloaded video larger than 6.44 GB | "File Too Large" alert fires (belt-and-suspenders path intact) |
| 6 | App not wired (`onICloudAsset` nil) | Export starts directly — no regression for un-wired callers |
| 7 | Tap Continue, then Cancel upload mid-export | Cancel hook fires normally; bridge cleans up |

### Files to Change

1. `GigHive/Sources/App/PickerBridges.swift` (gighiveapp) — add `size == 0` log branch in `loadItem` callback (iCloud detection, falls through to `startExport`)
2. `GigHive/Sources/App/GuestUploadView.swift` (gighiveapp) — update hint text to cover both Photos and Files iCloud paths
3. `GigHive/Sources/App/UploadView.swift` (gighiveapp) — update hint text to cover both Photos and Files iCloud paths

### Future state (not in scope)

Add `config.photoLibrary = PHPhotoLibrary.shared()` to `PHPickerConfiguration` and silently query `PHAsset` + `PHAssetResource` for real file size when Photos authorization has already been granted by the user. This allows precise size gating (not just 0-byte heuristic) and enables early rejection for oversized iCloud videos without requiring the user to download them at all.
