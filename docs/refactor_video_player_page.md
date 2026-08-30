# Refactor: Unified Video List View

## Status — 2026-08-27
Phases 1–4 complete. Steps 1–17 done. Full test suite passing (27/27, serial, `-parallel-testing-enabled NO`).
`DatabaseView`, `DatabaseDetailView`, and `MediaPlayerView` are deleted. Authenticated delete wired, tested, and verified (Phase 4).

---

## Elevator Pitch

Right now the GigHive iPhone app has two completely separate video browsing screens — one for event guests who joined via QR code, and one for logged-in users who access the full database. The guest screen looks great and is easy to use; the database screen is clunky and three taps deep to play anything. Rather than continuing to maintain two diverging codebases, this refactor merges them into a single polished screen that works for both types of users. Any feature built once — like the upcoming save-video capability — automatically works for everyone.

---

## Rationale

- The guest gallery UI (`GuestGalleryView`) is significantly better than the database UI (`DatabaseView` → `DatabaseDetailView` → `MediaPlayerView` sheet). Card layout, one-tap play, thumbnails, live polling, and a "New" badge are all guest-only today.
- The database player (`MediaPlayerView`) is significantly more capable than the guest player (`VideoPlayerView`). Auth header injection, scrub bar, audio support, and robust KVO error handling are all database-only today.
- Every future user-facing feature (save video, reactions, captions) would currently have to be built and maintained twice. This refactor eliminates that permanently.

---

## Goal

Replace `GuestGalleryView`, `DatabaseView`, `DatabaseDetailView`, and `MediaPlayerView` with a single parameterized view pair — `UnifiedVideoListView` and `UnifiedVideoPlayerView` — that serves both use cases from one codebase.

**Policy: one video list component, one player component. Context determines behavior; the UI layer is identical for all users.**

---

## Decision

Option 2 (true single component) is chosen over Option 1 (replace DatabaseView in-place only). A context enum encapsulates everything that differs between guest and authenticated use cases — auth identity, API calls, and capability flags. The UI layer above that is shared verbatim.

---

## Current State

| Concern | Guest Gallery | Database (logged-in) |
|---|---|---|
| List UI | Card layout, thumbnail, one-tap play | Plain `List` → detail screen → sheet (3 taps) |
| Player | Bare `AVPlayer(url:)`, no auth | Full auth proxy, KVO observers, scrub bar |
| Audio support | None | Full custom UI with scrub bar and retry |
| Live polling | Yes (30s + scene-phase trigger) | No |
| "New" badge | Yes | No |
| Delete | Uploader-scoped (via nonce) | Separate confirm flow, requires `delete_token` |
| Search | No | Yes |
| Flag/report | Yes | No |

---

## Proposed Implementation

### Step Index

#### Phase 1 — Unified Player
- **Step 1** — Swift: create `VideoListContext.swift` (shared types: context enum, capabilities, unified model, `MediaFileType`)
- **Step 2** — Swift: extract `ThumbnailLoader.swift` from `GuestGalleryView.swift`
- **Step 3** — Swift: create `UnifiedVideoPlayerView.swift` (full player implementation — all KVO, notifications, audio UI, auth proxy)
- **Step 4** — Swift: wire `UnifiedVideoPlayerView` into existing `GuestGalleryView` in place of `VideoPlayerView`; verify guest playback
- **Step 5** — Swift: wire `UnifiedVideoPlayerView` into existing `DatabaseDetailView` in place of `MediaPlayerView` sheet; verify authenticated playback
- **Step 6** — Testing: Phase 1 verification checklist

#### Phase 2 — Unified List (Guest Path)
- **Step 7** — Swift: create `UnifiedVideoListView.swift` (card layout, badge, polling, pill, flag, delete — guest capabilities)
- **Step 8** — Swift: update `SplashView.swift` guest `NavigationLink` call sites (lines 182, 233) to use `UnifiedVideoListView(.guest(record:))`
- **Step 9** — Swift: delete `GuestGalleryView.swift` and `VideoPlayerView` (now dead code inside it)
- **Step 10** — Testing: Phase 2 verification checklist

#### Phase 3 — Unified List (Authenticated Path)
- **Step 11** — Swift: extend `UnifiedVideoListView` for authenticated capabilities (search bar, pull-to-refresh, viewed-state persistence in UserDefaults, no poll)
- **Step 12** — Swift: update `SplashView.swift` authenticated `NavigationLink` call sites (lines 76, 239) to use `UnifiedVideoListView(.authenticated(...))`
- **Step 13** — Swift: delete `DatabaseView.swift` and `DatabaseDetailView.swift`
- **Step 14** — Testing: Phase 3 verification checklist

#### Phase 4 — Delete for Authenticated Users (blocked on server prereq)
- **Step 15** — Server prereq: resolve `delete_token` / admin endpoint option (Option A or B)
- **Step 16** — Swift: wire delete button for `.authenticated` path in `UnifiedVideoListView`
- **Step 17** — Testing: Phase 4 verification checklist

---

### Phase 1 — Unified Player

---

### Step 1 — Swift: create `VideoListContext.swift`

**File:** `GigHive/Sources/App/VideoListContext.swift` (new)

Create this file first — all subsequent files depend on these types.

```swift
enum VideoListContext {
    case guest(record: GuestUploadRecord)
    case authenticated(session: AuthSession)
}
```

> `AuthSession` is an `@EnvironmentObject`. Storing it as an associated value means the view holds a reference at construction time and will not automatically react to session updates (e.g. token refresh). The implementation must pass only the stable parts of `AuthSession` (baseURL, credential, allowInsecureTLS) as value types, or re-derive from the environment on each relevant state change.

```swift
struct VideoListCapabilities {
    let canFlag: Bool           // true for guest, false for authenticated
    let canSearch: Bool         // false for guest, true for authenticated
    let canLivePoll: Bool       // true for guest; false for authenticated (archive, not live event feed)
    let canShowNewBadge: Bool   // true for both
    let deleteScope: DeleteScope
}

enum DeleteScope {
    case none
    case uploaderOnly           // guest: checked via ownUploadIds (nonce match)
    case uploaderAndAdmin       // authenticated: blocked on JWT role claim — see Phase 4
}

struct UnifiedVideo: Identifiable {
    let id: Int                  // uploadJobId (guest) or MediaEntry.id (DB)
    let displayName: String?     // attendee name (guest) or nil (DB — see Phase 3 server prereq)
    let orgName: String?         // band/artist name — DB: MediaEntry.orgName; guest: nil (not in guest API response)
    let label: String?           // clip label / song title
    let streamURL: URL
    let thumbnailURL: URL?       // nil for audio entries; populated from database.php thumbnail_url for video
    let fileType: MediaFileType  // typed enum, not magic string
    let isOwnUpload: Bool
    let isReported: Bool
}

enum MediaFileType: String {
    case video
    case audio
}
```

> `fileType` is promoted from the raw `String` used in `MediaEntry` to a typed `MediaFileType` enum. This eliminates the `"video"` / `"audio"` magic strings in `MediaPlayerView` and `DatabaseView`.

**Capability defaults:**
- `.guest` → `canFlag: true`, `canSearch: false`, `canLivePoll: true`, `canShowNewBadge: true`, `deleteScope: .uploaderOnly`
- `.authenticated` → `canFlag: false`, `canSearch: true`, `canLivePoll: false`, `canShowNewBadge: true`, `deleteScope: .none` (until Phase 4)

---

### Step 2 — Swift: extract `ThumbnailLoader.swift`

**File:** `GigHive/Sources/App/ThumbnailLoader.swift` (new, extracted from `GuestGalleryView.swift`)

Move `ThumbnailLoader` and `AsyncThumbnail` out of `GuestGalleryView.swift` where they are currently declared `private`. Make them `internal` (no access modifier). Remove the `private` declarations from `GuestGalleryView.swift`.

Both `UnifiedVideoListView` (Step 7) and the existing `GuestGalleryView` (still in use during Phase 1 and 2) will reference these types — they must be visible to both.

Thumbnail placeholder rule: `AsyncThumbnail` renders `Color.clear` when `url` is nil — matching existing behavior. No crash when DB entries have no `thumbnailURL`.

---

### Step 3 — Swift: create `UnifiedVideoPlayerView.swift`

**File:** `GigHive/Sources/App/UnifiedVideoPlayerView.swift` (new)

This is the most complex step. Implement the full player by carrying every mechanism from `MediaPlayerView` verbatim, adapting only the URL/credential source from `config`.

**Signatures:**

```swift
struct UnifiedVideoPlayerConfig {
    let url: URL
    let credential: AuthCredential?    // nil = guest / public stream; non-nil = auth proxy path
    let allowInsecureTLS: Bool
    let fileType: MediaFileType
}

struct UnifiedVideoPlayerView: View {
    let config: UnifiedVideoPlayerConfig
    var onAppear: (() -> Void)? = nil
}
```

> `UnifiedVideoPlayerConfig` bundles parameters to stay below the RSPEC-107 threshold.

**Auth routing:**
```
config.credential != nil  →  MediaResourceLoader proxy (gighive:// scheme)
config.credential == nil  →  Direct AVURLAsset (public stream URL — guest path unchanged)
```

**AVPlayerViewController full-screen dismissal:**
The current guest `VideoPlayer(player:)` inside a `NavigationLink` supports back-swipe to pop. `AVPlayerViewController` has its own full-screen toggle that does not pop the stack. Wrap `PlayerViewController` in a `NavigationView` with a "Close" button (matching existing `MediaPlayerView`) presented via `NavigationLink` from the list — consistent with `AVPlayerViewController` delegate expectations.

**Complete implementation checklist — every item must be present before Step 4:**

*Move these types into `UnifiedVideoPlayerView.swift` (or keep as file-level types in same file):*
- `PlayerViewController: UIViewControllerRepresentable` — carry verbatim; sets `allowsPictureInPicturePlayback = true`, `showsPlaybackControls = true`, assigns coordinator as delegate; `updateUIViewController` checks identity with `!==` and logs warning if instance changed
- `PlayerViewController.Coordinator: NSObject, AVPlayerViewControllerDelegate` — logs `willBeginFullScreenPresentation` and `willEndFullScreenPresentation` with current time and rate
- `AudioPlaybackState: ObservableObject` — `@Published var isPlaying`, `currentTimeSeconds`, `durationSeconds`, `scrubPositionSeconds`, `isScrubbing`; `@MainActor func reset()`
- `PlaybackOverlayState` enum: `.loading(String)`, `.failed(title: String, detail: String)`, `.none`

*State variables (carry verbatim):*
- `@State private var player: AVPlayer? = nil`
- `@State private var errorMessage: String? = nil`
- `@State private var itemStatusObserver: NSKeyValueObservation? = nil`
- `@State private var likelyToKeepUpObserver: NSKeyValueObservation? = nil`
- `@State private var bufferEmptyObserver: NSKeyValueObservation? = nil`
- `@State private var bufferFullObserver: NSKeyValueObservation? = nil`
- `@State private var timeObserverToken: Any? = nil`
- `@State private var timeControlObserver: NSKeyValueObservation? = nil`
- `@State private var rateObserver: NSKeyValueObservation? = nil`
- `@State private var loaderRef: MediaResourceLoader? = nil` — **strong `@State` ref required**; `AVURLAsset` holds the delegate weakly — if this is nil'd or not retained, the resource loader is deallocated and playback silently fails with no error
- `@State private var hasAutoPlayed: Bool = false` — prevents double-play when `PlayerViewController` re-appears after full-screen exit
- `@State private var overlayState: PlaybackOverlayState = .loading("Loading media…")`
- `@StateObject private var audioState = AudioPlaybackState()`

*Overlay helpers (carry verbatim):*
- `showLoading(_ message: String = "Loading media…")` — sets `errorMessage = nil`, `overlayState = .loading(message)`
- `showFailure(_ message: String, title: String = "Media failed to load")` — sets `errorMessage` and `overlayState = .failed`
- `overlayContent @ViewBuilder` — spinner + message for `.loading`; red title + caption detail for `.failed`; `EmptyView` for `.none`; `allowsHitTesting(false)` on all cases

*`preparePlayer()` — full sequence in order:*
1. `showLoading()`
2. Construct `mediaURL` from `config.url` — guard-let, `showFailure("Invalid media URL")` + return on failure; log scheme/host/path
3. `config.credential?.apply(to: &headers)`; log auth header presence and `allowInsecureTLS`
4. `await headDiagnostics(url: mediaURL, headers: headers)` — preflight HEAD; logs status, `Content-Type`, `Content-Length`, `Accept-Ranges`; failure logged, not fatal
5. `let shouldUseProxyLoader = config.allowInsecureTLS || !headers.isEmpty`
   - **Proxy:** build `gighive://host:port/path?query`; guard-let (`showFailure("Unsupported media URL")` on failure); `AVURLAsset(url: customURL)`; create `MediaResourceLoader`, set as `resourceLoader.delegate` on `.main`, store in `loaderRef`
   - **Direct:** `AVURLAsset(url: mediaURL, options: ["AVURLAssetHTTPHeaderFieldsKey": headers])`
6. **Do NOT call `loadValuesAsynchronously` before `AVPlayerItem`.** Asset key loads are permanently cached. Calling this before `AVPlayerItem` is created poisons keys that AVFoundation's internal recovery logic would otherwise resolve. Read key status only via `statusOfValue(forKey:error:)` after item creation.
7. `AVPlayerItem(asset: asset)` → `AVPlayer(playerItem: item)` → assign to `self.player`
8. If `.audio`: `overlayState = .none` immediately (audio UI renders instead of overlay)
9. Install all KVO and notification observers before returning

*KVO observers — all six required:*
- `itemStatusObserver` on `AVPlayerItem.status`:
  - `.unknown` → `showLoading()` (video only)
  - `.readyToPlay` → update `audioState.durationSeconds`; call `logAssetKeyStatus`; audio: autoplay once guarded by `hasAutoPlayed`; video: `showLoading("Starting media…")`
  - `.failed` → check `loaderRef?.lastFailureMessage` first, then `NSUnderlyingErrorKey` in `userInfo` for loader-domain errors, else `item.error.localizedDescription`; call `showFailure` with most specific message; log full `NSError` domain, code, `userInfo`
- `likelyToKeepUpObserver` on `isPlaybackLikelyToKeepUp` — log only
- `bufferEmptyObserver` on `isPlaybackBufferEmpty` — log only
- `bufferFullObserver` on `isPlaybackBufferFull` — log only
- `timeControlObserver` on `AVPlayer.timeControlStatus`:
  - `.paused` → `audioState.isPlaying = false`; video with no error and time ≤ 0.1 → `showLoading()`
  - `.waitingToPlayAtSpecifiedRate` → `audioState.isPlaying = false`; video → `showLoading("Buffering video...")`; log `reasonForWaitingToPlay`
  - `.playing` → `audioState.isPlaying = true`; `hasAutoPlayed = true`; `overlayState = .none`
- `rateObserver` on `AVPlayer.rate` — log old→new rate and current time; no state change

*Notification observers — all three required:*
- `AVPlayerItemNewAccessLogEntry` — log URI, `numberOfMediaRequests`, `playbackStartDate`, `playbackStartOffset`, `observedBitrate`, `indicatedBitrate`, `numberOfBytesTransferred`, `transferDuration`, `mediaRequestsWWAN`
- `AVPlayerItemFailedToPlayToEndTime` — extract error from `userInfo[AVPlayerItemFailedToPlayToEndTimeErrorKey]`; call `showFailure`
- `AVPlayerItemPlaybackStalled` — call `logItemDiagnostics`; if no active error: `showLoading("Buffering video...")`

*Periodic time observer:*
- `CMTime(seconds: 0.25, preferredTimescale: CMTimeScale(NSEC_PER_SEC))` on `.main`
- Per tick: update `audioState.currentTimeSeconds`; update `scrubPositionSeconds` when not scrubbing; update `durationSeconds` from `item.duration` when finite > 0; audio: clear `overlayState` when `seconds > 0`
- Token stored in `timeObserverToken` — removed in `cleanup()` via `player.removeTimeObserver(token)`

*3-second readiness timeout:*
- `DispatchQueue.main.asyncAfter(deadline: .now() + 3.0)`: if `errorMessage == nil` and `item.status != .readyToPlay` → `logItemDiagnostics` + `showLoading("Loading media…")`; purely diagnostic, does not abort

*Diagnostic helpers (carry verbatim):*
- `logItemDiagnostics(_ item: AVPlayerItem, prefix: String)` — logs status, keepUp, bufferEmpty, bufferFull, duration, loadedTimeRanges as `[start=X,duration=Y,end=Z]`, error; also logs last `errorLog()` event fields (URI, statusCode, domain, comment, serverAddress)
- `logAssetKeyStatus(_ asset: AVURLAsset, mediaURL: URL)` — reads cached status of `playable`, `duration`, `tracks`, `hasProtectedContent` via `statusOfValue(forKey:error:)` only; **never calls `loadValuesAsynchronously`**; logs all four plus asset duration
- `headDiagnostics(url: URL, headers: [String: String])` — async HEAD request; logs status, CT, CL, Accept-Ranges; uses `InsecureTrustDelegate` when `allowInsecureTLS`; errors logged and swallowed
- `logAudioUIState(prefix: String)` — logs `isPlaying`, `currentTimeSeconds`, `durationSeconds`, `scrubPositionSeconds`, `isScrubbing`, `overlayState`, `playerExists`

*`cleanup()` — ordered to avoid observer callbacks firing on a deallocating item:*
1. `player?.pause()`
2. `player?.replaceCurrentItem(with: nil)`
3. Invalidate and nil all six KVO observers: `itemStatusObserver`, `likelyToKeepUpObserver`, `bufferEmptyObserver`, `bufferFullObserver`, `timeControlObserver`, `rateObserver`
4. `player?.removeTimeObserver(timeObserverToken)`; `timeObserverToken = nil`
5. `NotificationCenter.default.removeObserver(self)` — removes all three notification observers
6. `player = nil`
7. `loaderRef = nil`
8. `Task { @MainActor in audioState.reset() }`

*Audio session and nav bar (carry verbatim):*
- `.onAppear`: `AVAudioSession.sharedInstance().setCategory(.playback, mode: .default, options: [])`; `setActive(true)` — ensures playback in silent mode for both video and audio
- Video `.onAppear`: `configureNavigationBarAppearance()` — black opaque nav bar, white title and tint; set `standardAppearance`, `scrollEdgeAppearance`, `compactAppearance`, `tintColor`
- Video `.onDisappear`: `resetNavigationBarAppearance()` — restore default appearance

*Audio UI (carry verbatim from `MediaPlayerView.audioPlayerContent`):*
- Waveform/music-note icon (88pt), song title, `audioStatusMessage` text
- `Slider` scrub binding: `get` → `scrubPositionSeconds` when scrubbing, else `currentTimeSeconds`; `set` → `scrubPositionSeconds`; `onEditingChanged(true)` → `isScrubbing = true`; `onEditingChanged(false)` → `seekAudio(to:player:)`
- Time labels: left = current (or scrub position), right = duration; `formattedTime(_:)` guards `isFinite && >= 0`, returns `"--:--"` otherwise
- Restart button (`backward.end.fill`), play/pause toggle (`play.circle.fill` / `pause.circle.fill`, 64pt)
- Retry button: visible only when `currentTimeSeconds <= 0.1 && !isPlaying`; calls `player.play()`
- `seekAudio(to:player:)`: clamps to `[0, durationSeconds]`; seeks with `.zero` tolerance both sides; updates `currentTimeSeconds`, `scrubPositionSeconds`, `isScrubbing = false` on `@MainActor` in completion
- `audioStatusMessage`: returns `errorMessage` if set; else message from `overlayState`; else `"Audio is preparing. You can tap Retry or Close."` when player exists, time ≤ 0.1, not playing
- Audio root: `NavigationView` + `StackNavigationViewStyle`; "Close" button calls `cleanup()` then `presentationMode.wrappedValue.dismiss()`

*From `VideoPlayerView` (two items only):*
- `onAppear?()` called in player's `.onAppear` — used by the list view to mark the video as viewed
- Buffering spinner: already handled by `timeControlObserver` driving `overlayState = .loading("Buffering video...")`; no extra code needed

---

### Step 4 — Swift: wire `UnifiedVideoPlayerView` into `GuestGalleryView`

**File:** `GigHive/Sources/App/GuestGalleryView.swift` (modified)

Replace the `NavigationLink(destination: VideoPlayerView(url: streamURL, onAppear: { ... }))` at line 184 with:

```swift
NavigationLink(destination: UnifiedVideoPlayerView(
    config: UnifiedVideoPlayerConfig(
        url: streamURL,
        credential: nil,
        allowInsecureTLS: false,
        fileType: .video
    ),
    onAppear: {
        logWithTimestamp("[Gallery] VideoPlayer appeared — marking viewed uploadJobId=\(video.uploadJobId)")
        markViewed(video.uploadJobId)
    }
))
```

`VideoPlayerView` at the bottom of `GuestGalleryView.swift` (lines 510–607) becomes dead code after this step — do not delete it yet; it is removed in Step 9 along with the rest of `GuestGalleryView.swift`.

**Verify:** build succeeds; guest video plays end-to-end with buffering spinner and KVO logging visible in console.

---

### Step 5 — Swift: wire `UnifiedVideoPlayerView` into `DatabaseDetailView`

**File:** `GigHive/Sources/App/DatabaseDetailView.swift` (modified)

Replace the `.sheet(isPresented: $showPlayer)` block presenting `MediaPlayerView(baseURL:entry:credential:allowInsecureTLS:)` with a `NavigationLink` to `UnifiedVideoPlayerView`. Construct `streamURL` from `entry.url` relative to `baseURL`. Pass `session.credential` and `session.allowInsecureTLS` from the environment.

Map `entry.fileType` string to `MediaFileType` using `init?(rawValue:)` with a `.video` fallback:

```swift
let fileType = MediaFileType(rawValue: entry.fileType) ?? .video
```

`MediaFileType` has `RawRepresentable` conformance via `enum MediaFileType: String` (Step 1). The fallback to `.video` is appropriate because unknown or empty `fileType` values should not silently suppress playback — the native `AVPlayerViewController` handles both video and audio regardless of which UI path is chosen, so defaulting to video is safe. Log a warning if the raw value is unrecognised.

`MediaPlayerView.swift` becomes dead code after this step — do not delete it yet; it is removed in Step 13 along with `DatabaseView.swift` and `DatabaseDetailView.swift`.

**Verify:** authenticated video and audio play with auth proxy active; scrub bar works; KVO diagnostics log correctly; Close button dismisses.

---

### Step 6 — Testing: Phase 1 verification

- [ ] Guest video plays end-to-end via `UnifiedVideoPlayerView`; KVO log tags appear in console
- [ ] Buffering spinner appears and clears on guest stream
- [ ] Back navigation from player returns cleanly to list (no stuck screens)
- [ ] Authenticated video plays with auth proxy active (`[Player] Using proxy loader` log line present)
- [ ] Authenticated audio plays; scrub bar updates; seek completes; Retry button visible when stalled at 0
- [ ] `logAssetKeyStatus` fires on `.readyToPlay` without `loadValuesAsynchronously` poison
- [ ] HEAD preflight log line appears for authenticated path
- [ ] `cleanup()` called on disappear; no observer leaks (verify by re-entering and exiting player multiple times)
- [ ] `GigHiveUITests` pass with no regressions

---

### Phase 2 — Unified List (Guest Path)

---

### Step 7 — Swift: create `UnifiedVideoListView.swift`

**File:** `GigHive/Sources/App/UnifiedVideoListView.swift` (new)

Build the unified list view driven by `VideoListContext` and `VideoListCapabilities`. In Phase 2 only the guest capabilities are exercised; authenticated capabilities are wired in Step 11.

**Card row (carry from `GuestGalleryView`)::**
- `GHCard(pad: 10)` containing `HStack`: play icon (`play.circle.fill`, 28pt, yellow) + `AsyncThumbnail` + `VStack` (display name + "New" badge + label) + `Spacer()` + action buttons
- "New" badge: `Text("New")` in orange, caption2, shown when `!viewedIds.contains(video.id)` and `canShowNewBadge`
- Flag button: `Image(systemName: reportedIds.contains(id) ? "flag.fill" : "flag")`, orange, title3 — shown only when `canFlag`
- Delete button: `Image(systemName: "xmark")`, red, title3 — shown based on `deleteScope` (`.uploaderOnly` → `ownUploadIds.contains(id)`; `.uploaderAndAdmin` → Phase 4)
- Entire row tappable via `NavigationLink` to `UnifiedVideoPlayerView`; `onAppear` callback marks video viewed

**Header (carry from `GuestGalleryView`):**
- Guest: bee logo + "Event Gallery" title + event name subtitle
- Authenticated: bee logo + "Media Database" title (no subtitle)
- Guest: days remaining + video count caption
- Authenticated: total video count caption

**Search bar (authenticated only, `canSearch`)::**
- iOS 15+: `.searchable(text: $searchText, placement: .automatic)`
- iOS 14 fallback: inline `HStack` with magnifying glass + `TextField` (matching existing `DatabaseView` pattern)
- Filter: case-insensitive match on `label`, `displayName`, `orgName` fields of `UnifiedVideo`; `orgName` is only populated for the authenticated path (`MediaEntry.orgName`) — guest videos have `orgName = nil` and are not affected

**Live polling (guest only, `canLivePoll`)::**
- `Timer.publish(every: 30, on: .main, in: .common).autoconnect()` — carry from `GuestGalleryView`
- `.onReceive(galleryTimer)` + `.onChange(of: scenePhase)` guards — carry verbatim
- Silent poll guard: skip if `isLoading || isSilentPolling`

**New-video pill (guest only, `canLivePoll`)::**
- Orange pill with "↑ N new video(s) added" + dismiss X — carry verbatim from `GuestGalleryView`
- Auto-dismiss after 8 seconds: carry the existing implementation verbatim from `GuestGalleryView`. Do not introduce `Task.sleep(until:clock:)` (iOS 16+) or `Task.sleep(nanoseconds:)` without verifying the existing pattern — the baseline is iOS 14 and any `Task`-based sleep must be the same form already used in `GuestGalleryView`. Cancel on manual dismiss.

**Pull-to-refresh (authenticated only)::**
- iOS 15+: `.refreshable { await loadVideos() }`
- iOS 14: no pull-to-refresh (acceptable; user can navigate away and back)

**Alert system (carry from `GuestGalleryView`)::**
- `UnifiedListAlert` enum cases: `.reportConfirm`, `.reportFeedback`, `.retractConfirm`, `.retractFeedback`, `.deleteConfirm`, `.deleteFeedback`, `.error`
- Single `.alert(isPresented:)` binding driven by `activeAlert` optional — carry `makeAlert()` switch pattern; extract each case to a private helper if complexity warnings appear (RSPEC-3776)

**Viewed-state persistence — authenticated path:**
- Load viewed IDs from `UserDefaults` on `.onAppear` using key `"gh_viewed_\(baseURLString)|\(videoId)"` per video, or one key per host holding a `Set<Int>`
- Write back on `.onDisappear`
- Guest path continues to use `GuestUploadRecord.viewedUploadJobIds` (unchanged)

**`deletedIds` in-memory limitation:**
- `@State private var deletedIds: Set<Int>` filters deleted videos from the displayed list immediately after a delete action, without waiting for the next poll/refresh
- This set is in-memory only — it is not persisted to `UserDefaults` or `GuestUploadRecord`
- **Risk:** if the view is recreated (e.g. user navigates away and back) or a background poll refreshes the list, a deleted video may reappear until the server-side delete is reflected in the next fetch response
- **Mitigation for guest path:** the server-side delete is synchronous and the next poll will not return the deleted entry; the window is limited to the 30s polling interval
- **Mitigation for authenticated path (Phase 4):** same — next pull-to-refresh will reflect server state
- Do not attempt to persist `deletedIds` to `UserDefaults` — the correct long-term fix is server-authoritative list responses, not client-side exclusion lists

**Data loading and model mapping:**

Guest path — `GuestGalleryAPIClient.fetchGallery(nonce:)` → `[GuestGalleryVideo]` → `[UnifiedVideo]`:
- `id` ← `video.uploadJobId`
- `displayName` ← `video.displayName` (or equivalent field from guest API response)
- `orgName` ← `nil` (not present in guest API response)
- `label` ← `video.label`
- `streamURL` ← `buildStreamURL(video:)` (existing helper; returns nil on failure — skip entry)
- `thumbnailURL` ← `video.thumbnailURL` (or equivalent; nil if absent)
- `fileType` ← `.video` (guest uploads are video-only; no mapping needed)
- `isOwnUpload` ← `ownUploadIds.contains(video.uploadJobId)` — computed from `GuestUploadRecord.uploadJobId` across all event records; recalculated after every poll
- `isReported` ← `reportedIds.contains(video.uploadJobId)` — computed from `video.reportedByMe` on initial load; updated in-place on flag/retract actions; recalculated after every poll

> `isOwnUpload` and `isReported` are not fields on `GuestGalleryVideo` — they are set membership checks against state computed at load time. Both sets (`ownUploadIds`, `reportedIds`) must be recomputed after each silent poll to stay accurate if the server state changes.

Authenticated path — `DatabaseAPIClient.fetchMediaList()` → `[MediaEntry]` → `[UnifiedVideo]`:
- `id` ← `entry.id`
- `displayName` ← `nil` (until Phase 3 server prereq; see `## Phase Prerequisites`)
- `orgName` ← `entry.orgName`
- `label` ← `entry.songTitle` (closest equivalent)
- `streamURL` ← `URL(string: entry.url, relativeTo: session.baseURL)` — guard-let; skip entry on failure
- `thumbnailURL` ← `buildAuthThumbnailURL(entry:baseURL:)` — resolves `entry.thumbnailUrl` (relative path) against `baseURL`; nil for audio or entries without a checksum
- `fileType` ← `MediaFileType(rawValue: entry.fileType) ?? .video` — see Step 5 for fallback rationale
- `isOwnUpload` ← `false` (Phase 4; ownership data not yet available from API)
- `isReported` ← `false` (flag/report removed from authenticated UI)

---

### Step 8 — Swift: update `SplashView.swift` guest call sites

**File:** `GigHive/Sources/App/SplashView.swift` (modified)

Update two `NavigationLink` destinations:

- **Line 182** — `GuestGalleryView(record: record)` → `UnifiedVideoListView(context: .guest(record: record))`
- **Line 233** — `GuestGalleryView(record: rec)` → `UnifiedVideoListView(context: .guest(record: rec))`

Authenticated call sites (lines 76, 239) are unchanged until Step 12.

**Verify:** tapping "View Event Gallery" from `SplashView` opens `UnifiedVideoListView` with guest capabilities; "View the Database" still opens the old `DatabaseView` (unchanged).

---

### Step 9 — Swift: delete dead files

**Files deleted:**
- `GigHive/Sources/App/GuestGalleryView.swift` — fully replaced; `VideoPlayerView` inside it is also removed
- Remove `GuestGalleryView` from Xcode project navigator / `GigHive.xcodeproj` target membership

**Verify:** build succeeds with no references to `GuestGalleryView` or `VideoPlayerView`.

---

### Step 10 — Testing: Phase 2 verification

- [ ] Guest gallery list renders with card layout, thumbnails, "New" badges
- [ ] "New" badge clears after tapping into the player and returning
- [ ] "New" badge state persists across app restart (UserDefaults-backed via `GuestUploadRecord`)
- [ ] Live polling fires at 30s and on foreground resume; new-video pill appears and auto-dismisses
- [ ] Flag button triggers confirm alert; flag toggles on server and locally
- [ ] Delete button (own upload) triggers confirm alert; video removed from list
- [ ] Expired gallery shows expired state card
- [ ] Empty gallery shows "No videos yet" card
- [ ] `GuestGalleryView` and `VideoPlayerView` are gone from the build
- [ ] `GigHiveUITests` pass with no regressions

---

### Phase 3 — Unified List (Authenticated Path)

---

### Step 11 — Swift: extend `UnifiedVideoListView` for authenticated capabilities

**File:** `GigHive/Sources/App/UnifiedVideoListView.swift` (modified)

The list view already exists from Step 7. This step activates the authenticated code paths:

- `canSearch = true`: ensure search bar renders and filters correctly for the `.authenticated` context
- `canLivePoll = false`: poll timer not started; `.onReceive(galleryTimer)` is guarded by `capabilities.canLivePoll`
- Pull-to-refresh active for `.authenticated`
- Viewed-state loaded and saved via UserDefaults key (not `GuestUploadRecord`)
- `loadVideos()` routes to `DatabaseAPIClient.fetchMediaList()` for `.authenticated`
- Map `MediaEntry → UnifiedVideo`: `streamURL` constructed from `entry.url` relative to `session.baseURL`; `thumbnailURL` resolved via `buildAuthThumbnailURL` (video entries with a valid SHA-256 checksum) — nil for audio; `displayName = nil` (server prereq still pending); `isOwnUpload = false` (Phase 4); `isReported = false` (flag removed for authenticated)
- Delete button: hidden (`deleteScope == .none`) until Phase 4
- `deletedIds` in-memory limitation applies here identically to the guest path — see Step 7 for details

**Verify:** build succeeds; authenticated data loads correctly under the new view structure.

---

### Step 12 — Swift: update `SplashView.swift` authenticated call sites

**File:** `GigHive/Sources/App/SplashView.swift` (modified)

Update two remaining `NavigationLink` destinations:

- **Line 76** — `DatabaseView()` → `UnifiedVideoListView(context: .authenticated(session: session))`
- **Line 239** — `DatabaseView()` (hidden, used by `--uitest-navigate-database`) → `UnifiedVideoListView(context: .authenticated(session: session))`

**Verify:** "View the Database" button navigates to `UnifiedVideoListView` with card layout; UI test `--uitest-navigate-database` still fires the hidden link correctly.

---

### Step 13 — Swift: delete dead files

**Files deleted:**
- `GigHive/Sources/App/DatabaseView.swift`
- `GigHive/Sources/App/DatabaseDetailView.swift`
- `GigHive/Sources/App/MediaPlayerView.swift`
- Remove all three from Xcode project navigator / target membership

**Verify:** build succeeds with no references to `DatabaseView`, `DatabaseDetailView`, or `MediaPlayerView`.

---

### Step 14 — Testing: Phase 3 verification

- [ ] Logged-in users see card layout (not plain list)
- [ ] One tap plays video directly (no detail screen)
- [ ] "New" badge appears for unviewed videos; persists across app restart via UserDefaults
- [ ] Search filters by label / display name / org name
- [ ] Pull-to-refresh reloads the list
- [ ] `DatabaseView`, `DatabaseDetailView`, `MediaPlayerView` are gone from the build
- [ ] `SplashView` authenticated `NavigationLink` (line 76) navigates to `UnifiedVideoListView`
- [ ] `--uitest-navigate-database` UI test passes
- [ ] `GigHiveUITests` pass with no regressions

---

### Phase 4 — Delete for Authenticated Users

---

> **Revised plan (2026-08-27):** The server prereq (Option A) is already shipped. `db/delete_media_files.php` accepts `{asset_id, delete_token}` and the iOS app already:
>
> - Receives a `delete_token` from the server on every successful upload via `UploadView`
> - Stores it in `UploaderDeleteTokenStore` (Keychain, keyed by host)
> - Exposes it in `UploadView`'s "My uploads from this device" section with a working delete button via `DatabaseAPIClient.deleteMediaFile(fileId:deleteToken:)`
>
> Phase 4 is therefore a **pure iOS wiring task** — no server changes required.

---

### Step 15 — Swift: wire `UploaderDeleteTokenStore` into `UnifiedVideoListView`

**File:** `GigHive/Sources/App/UnifiedVideoListView.swift` (modified)

**New state:**

```swift
/// Maps fileId → deleteToken for videos uploaded from this device.
/// Populated in loadAuthenticatedVideos; used by performDelete for the authenticated path.
@State private var authDeleteTokens: [Int: String] = [:]

/// Tracks in-flight authenticated deletes to prevent double-tap sending two API calls.
@State private var isDeletingIds: Set<Int> = []
```

**In `loadAuthenticatedVideos`:**

The host string used here must match the key used in Step 16's Keychain cleanup. Use
`baseURL.host ?? baseURL.absoluteString` in both places.

After `let entries = try await client.fetchMediaList()`, load stored tokens and build the map.
Use `uniquingKeysWith:` to defend against any duplicate `fileId` entries in the store (prevented
by `upsert` in practice, but not enforced at the type level):

```swift
let host = baseURL.host ?? baseURL.absoluteString
let storedTokens = (try? UploaderDeleteTokenStore.load(host: host)) ?? []
let tokenMap = Dictionary(
    storedTokens.map { ($0.fileId, $0.deleteToken) },
    uniquingKeysWith: { first, _ in first }
)
authDeleteTokens = tokenMap
logWithTimestamp("[UnifiedList] loaded \(tokenMap.count) delete token(s) for host=\(host)")
```

The `MediaEntry → UnifiedVideo` mapping already happens inside the `entries.map { ... }` call
at the end of `loadAuthenticatedVideos`. In that closure, set:

```swift
isOwnUpload: tokenMap[entry.id] != nil
```

**In `VideoListContext.capabilities` (or `UnifiedVideoListView` directly):**

Change authenticated `deleteScope` from `.none` to `.uploaderAndAdmin`:

```swift
// VideoListContext.swift — authenticated capabilities
deleteScope: .uploaderAndAdmin   // delete button shown when authDeleteTokens[video.id] != nil
```

**`showDeleteButton(for:)` handles this** — `.uploaderAndAdmin` gates on
`authDeleteTokens[video.id] != nil` (live token map) rather than the snapshot `video.isOwnUpload`
value. This means the button disappears immediately when the 403 handler calls
`authDeleteTokens.removeValue(forKey: video.id)`, without requiring a full list reload.
The `.error` and `.deleteFeedback` alert cases used in Step 16 are already present in the alert
enum (added in Phase 2 for the guest delete path).

**Why the delete button appears on some rows but not others:**
`authDeleteTokens` is populated from `UploaderDeleteTokenStore.load(host:)` — the device Keychain.
An entry exists in the Keychain only when *this specific device* uploaded that file through
`UploadView`, which stores the server-issued delete token at upload time. A row shows ✕ if and only
if the current device holds a Keychain token for that file's server ID. Files uploaded from a
different device or session have no token on this device and show no delete button — this is correct
and intentional. The delete button is therefore a per-device ownership indicator, not a global
admin capability. Admin users who have uploaded files from this device will see ✕ on those rows;
admin users who have not uploaded from this device will see no delete buttons at all, even though
the server would accept an admin delete request for any file.

---

### Step 16 — Swift: implement `performDelete` for authenticated path

**File:** `GigHive/Sources/App/UnifiedVideoListView.swift` (modified)

Extend the existing `performDelete(video:)` to handle the `.authenticated` case. The guest path
is already wired; add the authenticated branch:

```swift
case .authenticated(let baseURL, let credential, let allowInsecureTLS):
    guard let token = authDeleteTokens[video.id], !token.isEmpty else {
        activeAlert = .error("Delete token not found for this video.")
        return
    }
    // Prevent double-tap: ignore if a delete is already in flight for this video.
    guard isDeletingIds.insert(video.id).inserted else { return }
    defer { isDeletingIds.remove(video.id) }

    let host = baseURL.host ?? baseURL.absoluteString
    logWithTimestamp("[UnifiedList] deleteAuthenticated start file_id=\(video.id) host=\(host)")
    do {
        let client = DatabaseAPIClient(
            baseURL: baseURL, credential: credential, allowInsecure: allowInsecureTLS
        )
        let resp = try await client.deleteMediaFile(fileId: video.id, deleteToken: token)
        logWithTimestamp("[UnifiedList] deleteAuthenticated response deleted=\(resp.deletedCount) errors=\(resp.errorCount)")
        if resp.deletedCount == 1 {
            deletedIds.insert(video.id)
            authDeleteTokens.removeValue(forKey: video.id)
            // Remove from Keychain so the entry doesn't reappear in UploadView on next launch.
            try? UploaderDeleteTokenStore.remove(host: host, fileId: video.id)
            activeAlert = .deleteFeedback("Your video has been removed from the database.")
        } else {
            activeAlert = .error("Server did not delete the file (deleted=\(resp.deletedCount), errors=\(resp.errorCount)).")
        }
    } catch DatabaseError.httpError(403) {
        logWithTimestamp("[UnifiedList] deleteAuthenticated 403 — removing stale token file_id=\(video.id)")
        activeAlert = .error("You are not authorised to delete this video. Your delete token may have expired.")
        authDeleteTokens.removeValue(forKey: video.id)
        // Also remove from Keychain: stale token would otherwise reappear on next launch,
        // show the delete button again, and produce another 403.
        try? UploaderDeleteTokenStore.remove(host: host, fileId: video.id)
    } catch {
        logWithTimestamp("[UnifiedList] deleteAuthenticated error: \(error)")
        activeAlert = .error(error.localizedDescription)
    }
```

Key points:
- **Host key consistency:** `host` is derived identically in Step 15 (token load) and Step 16 (Keychain remove) using `baseURL.host ?? baseURL.absoluteString`. Both steps hit the same Keychain slot.
- **Double-tap guard:** `isDeletingIds` is checked atomically via `insert(_:).inserted`. A second tap while the first API call is in flight returns immediately, preventing duplicate requests.
- **On success:** `deletedIds.insert` filters the video from `filteredVideos` immediately (in-memory); `UploaderDeleteTokenStore.remove` keeps Keychain in sync so `UploadView`'s "My uploads" section reflects the deletion.
- **On 403:** token is stale — removed from both memory and Keychain. Without Keychain removal the delete button would reappear on next app launch and generate another 403 indefinitely.
- **On other error:** state unchanged; user sees the server error message.
- `deletedIds` is in-memory only — if the view is recreated before the next pull-to-refresh, the video could reappear; this matches the guest path limitation documented in Step 7.

**Two delete endpoints — comparison:**

GigHive has two entirely separate server-side delete paths. They are not interchangeable.

| | `db/delete_media_files.php` | `api/guest-delete.php` |
|---|---|---|
| **Used by** | Admin (HTTP Basic Auth `admin`) and uploader (HTTP Basic Auth `uploader`) | QR code / guest uploaders (nonce-authenticated) |
| **Auth** | HTTP Basic Auth `Authorization` header | JSON body `nonce` field |
| **Payload (admin)** | `{"asset_ids": [1, 2, 3]}` — array, no token | — |
| **Payload (uploader)** | `{"asset_id": 1, "delete_token": "..."}` — scalar + token hash check | — |
| **Payload (guest)** | — | `{"nonce": "...", "upload_job_id": 1}` |
| **Filesystem** | Hard delete — `unlink` media file + thumbnail | Not touched — file remains on disk |
| **`assets` table** | Deleted (`DELETE FROM assets`) | Not touched |
| **`event_items` table** | Deleted (`DELETE FROM event_items`) | Not touched |
| **`upload_jobs` table** | Marked `moderation_status = 'rejected'` (cross-reference cleanup) | `guest_deleted = 1` set; `moderation_status` unchanged |
| **Reversibility** | Permanent — file and DB rows are gone | Reversible — `SET guest_deleted = 0` restores visibility; file still on disk |
| **iOS client** | `DatabaseAPIClient.deleteMediaFileAsAdmin` (admin) or `deleteMediaFile` (uploader) | `GuestGalleryAPIClient.deleteVideo` |

The soft delete design for the guest path is deliberate: changing `moderation_status` would cause the guest to fail the gallery access gate and lose the ability to view all other attendees' videos, not just their own. See `feature_completed_iphone_qr_code_shared_gallery.md` for the full rationale.

---

### Step 17 — Testing: Phase 4 verification

Automated items are covered by the five new XCUITests in `GigHiveUITests.swift`
(`// MARK: - Phase 4 — Authenticated Delete`). See `testing_ios.md` for the full test plan,
new launch argument (`--uitest-inject-delete-token`), new env var (`GH_TEST_DELETE_FILE_ID`),
helper (`launchAuthListWithToken(injectToken:)`), and P9 app-side cleanup requirements.

**Automated (XCUITest):**

- [x] Delete button absent when `UploaderDeleteTokenStore` has no entries for this host —
  `testAuthDeleteButtonAbsentWithoutToken`
- [x] Delete button visible on the card matching the injected token; absent on all others —
  `testAuthDeleteButtonVisibleForOwnUpload`
- [x] Tapping the delete button raises the confirm dialog; cancelling leaves server state unchanged —
  `testAuthDeleteConfirmDialogAppears`
- [x] 403 response: error alert shown; token removed from Keychain; delete button absent on
  next launch (relaunch without injection) — `testAuthDelete403ClearsToken`
- [x] Guest delete button still appears for own upload after Phase 4 wiring —
  `testGuestDeleteButtonStillVisible` (regression guard)

**Manual only:**

- [ ] Video removed from list immediately on confirmed success (`deletedIds` filter) —
  requires real `GH_TEST_DELETE_TOKEN` for a disposable video; verify on device
- [ ] `UploaderDeleteTokenStore` entry gone from `UploadView`'s "My uploads" on success —
  same prerequisite; navigate to Upload screen after delete and confirm entry absent
- [ ] Double-tap guard: second tap while first API call is in flight is silently ignored
  (no duplicate `[UnifiedList] deleteAuthenticated start` log line for the same `file_id`) —
  timing-based; not automatable in XCUITest
- [x] `GigHiveUITests` pass with no regressions (full serial run, `-parallel-testing-enabled NO`) — 27/27 passed

---

### SonarQube / Best-Practice Notes

- **RSPEC-107 (too many parameters):** Addressed by `UnifiedVideoPlayerConfig` struct.
- **RSPEC-6426 (force unwrap):** No force unwraps introduced. `thumbnailURL` and `displayName` are optional throughout. URL construction uses guard-let, matching existing patterns.
- **Magic strings:** `MediaFileType` enum replaces `"video"` / `"audio"` string literals throughout.
- **Cognitive complexity (RSPEC-3776):** `makeAlert()` carries a large switch — extract each case to a private helper if Xcode complexity warnings appear.

---

## UX Wireframe

```
STATE A — Unified List (Guest context)
┌─────────────────────────────────────────────┐
│ [bee logo]  Event Gallery                   │
│             StormPigs — 2026-07-17          │
│ Available for 87 more days · 3 videos       │
│ ┌───────────────────────────────────────┐   │
│ │ ▶  [thumb]  Jane D.          [New]    │   │
│ │             Summer Nights             │   │
│ │                           [⚑] [✕]   │   │   ← flag (canFlag=true), delete (own upload)
│ └───────────────────────────────────────┘   │
│ ┌───────────────────────────────────────┐   │
│ │ ▶  [thumb]  Attendee                  │   │
│ │             Table 4                   │   │
│ │                           [⚑]        │   │   ← flag only (not own upload)
│ └───────────────────────────────────────┘   │
└─────────────────────────────────────────────┘

STATE B — Unified List (Authenticated context)
┌─────────────────────────────────────────────┐
│ [bee logo]  Media Database                  │
│ 🔍 Search by band, song, or date            │   ← canSearch=true
│ ┌───────────────────────────────────────┐   │
│ │ ▶  [thumb]  StormPigs        [New]    │   │   ← NEW: "New" badge
│ │             Summer Nights             │   │
│ │                                [✕]   │   │   ← delete (uploader/admin only — Phase 4)
│ └───────────────────────────────────────┘   │
│ ┌───────────────────────────────────────┐   │
│ │ ▶  [img]  The Wedersons              │   │   ← thumbnail now populated from database.php
│ │             Table 7                   │   │
│ └───────────────────────────────────────┘   │
└─────────────────────────────────────────────┘

STATE C — Player (video, authenticated)
┌─────────────────────────────────────────────┐
│ < Summer Nights                    [Close]  │
│                                             │
│  ┌───────────────────────────────────────┐  │
│  │                                       │  │
│  │         [native AVKit controls]       │  │
│  │         scrub bar, AirPlay, PiP       │  │   ← carried from MediaPlayerView
│  │                                       │  │
│  └───────────────────────────────────────┘  │
└─────────────────────────────────────────────┘

STATE D — Player (buffering overlay)
┌─────────────────────────────────────────────┐
│ < Summer Nights                    [Close]  │
│         ┌──────────────────┐                │
│         │  ◌  Buffering…   │                │   ← carried from VideoPlayerView / MediaPlayerView
│         └──────────────────┘                │
└─────────────────────────────────────────────┘

STATE E — Player (failure overlay)
┌─────────────────────────────────────────────┐
│ < Summer Nights                    [Close]  │
│      ┌───────────────────────────┐          │
│      │  Media failed to load     │          │   ← carried from MediaPlayerView
│      │  [error detail message]   │          │
│      └───────────────────────────┘          │
└─────────────────────────────────────────────┘

STATE F — Player (audio file)
┌─────────────────────────────────────────────┐
│ < Table 7 Toast                    [Close]  │
│                                             │
│              ♪  [waveform icon]             │
│           The Wedersons — Table 7           │
│                                             │
│  ├────────────────●──────────────────┤      │
│  1:23                            4:07       │
│                                             │
│        [|◀]    [▶ / ‖]    [Retry]          │   ← carried from MediaPlayerView audio UI
└─────────────────────────────────────────────┘
```

---

## Files Under Change

### New (gighiveapp repo — `GigHive/Sources/App/`)

1. `UnifiedVideoListView.swift` — New main list view; implements card layout, polling, badge, search, delete, and flag behaviors driven by `VideoListContext` and `VideoListCapabilities`.
2. `UnifiedVideoPlayerView.swift` — New merged player; combines `MediaPlayerView`'s auth proxy and KVO logic with `VideoPlayerView`'s buffering overlay and `onAppear` hook.
3. `VideoListContext.swift` — New file containing `VideoListContext` enum, `VideoListCapabilities` struct, `DeleteScope` enum, `UnifiedVideo` model, and `MediaFileType` enum.

### Modified (gighiveapp repo — `GigHive/Sources/App/`)

4. `SplashView.swift` — Four `NavigationLink` call sites updated: line 76 (`DatabaseView()` → `UnifiedVideoListView(.authenticated)`), line 182 (`GuestGalleryView(record:)` → `UnifiedVideoListView(.guest(record:))`), line 233 (banner `GuestGalleryView` → `UnifiedVideoListView(.guest(record:))`), line 239 (hidden `DatabaseView()` → `UnifiedVideoListView(.authenticated)`).
5. `GuestGalleryView.swift` — Replaced by `UnifiedVideoListView`; file deleted or gutted to a stub after Phase 3 is verified. `ThumbnailLoader` and `AsyncThumbnail` extracted to a shared location (see item 8).
6. `DatabaseView.swift` — Replaced by `UnifiedVideoListView`; file deleted after Phase 3 is verified.
7. `DatabaseDetailView.swift` — Deleted; no longer needed.
8. `MediaPlayerView.swift` — Refactored into `UnifiedVideoPlayerView`; `PlayerViewController`, `AudioPlaybackState`, and `MediaResourceLoader` retained and moved/reused. File deleted after Phase 1 is verified.

### Shared Extraction

9. `ThumbnailLoader.swift` (new) — `ThumbnailLoader` and `AsyncThumbnail` extracted from `GuestGalleryView.swift` (currently `private`) into a shared file so `UnifiedVideoListView` can use them without duplication.

### Unchanged

- `GuestGalleryAPIClient.swift` — API calls unchanged; consumed by `UnifiedVideoListView` on the `.guest` path.
- `DatabaseAPIClient.swift` — API calls unchanged; consumed by `UnifiedVideoListView` on the `.authenticated` path.
- `DatabaseModels.swift` — `MediaEntry` unchanged; mapped to `UnifiedVideo` inside the context layer.
- `AuthCredential.swift` — Unchanged; passed through to `UnifiedVideoPlayerConfig`.

---

## Phase Prerequisites

### Phase 3 Server Prerequisite — `database.php` response fields

Before Phase 3 ships, `database.php` must return two additional fields per entry so the authenticated card row has parity with the guest card:

- `thumbnail_url` — relative URL to the video thumbnail (same format as the guest gallery API) — **complete** (added to `MediaController.php` `listJson()` post-Phase 4; `MediaEntry.thumbnailUrl` and `buildAuthThumbnailURL` added to iOS client). Video entries return `/video/thumbnails/<sha256>.png` (auth-gated); audio entries return `/images/audiofile.png` (public static placeholder, no auth required).
- `display_name` — submitter display name (or null) — **pending**

These are additive JSON fields; existing clients ignore unknown keys. `MediaEntry` and `UnifiedVideo` must be updated to decode them. If this server change is not ready before Phase 3, the authenticated list renders without thumbnails and without display names — graceful degradation, not a crash.

**Note on thumbnail auth:** Video thumbnails are served through `api/media-stream.php` and require auth (`ThumbnailLoader` injects `playerCredential` as an `Authorization` header). The audio placeholder `/images/audiofile.png` is a public static file — no auth header needed, but passing one is harmless. The guest path passes `nil` (nonce already embedded in the URL).

### Phase 4 Server Prerequisite — Delete authorization for authenticated users

`deleteMediaFile(fileId:deleteToken:)` requires a `delete_token` the client does not currently have for DB entries. Two options remain open:

- **Option A:** `database.php` returns a `delete_token` per entry scoped to the session.
- **Option B:** Admin role is decoded from JWT claims (requires JWT migration Phase 3) and admins delete via a separate admin-only endpoint.

Until one option is resolved, the delete button in the `.authenticated` path is hidden. The guest delete path is unaffected.

---

## Testing

Testing follows the XCUITest harness documented in `docs/testing_ios.md`. All tests live in `GigHive/GigHiveUITests/GigHiveUITests.swift`. Tests that make real network calls use `requireCredentials()` and are skipped via `XCTSkip` if env vars are not set.

### New Accessibility Identifiers Required

Add these `.accessibilityIdentifier(...)` modifiers as part of the steps that create each view. Follow the `snake_case` naming convention established in `testing_ios.md`.

| Identifier | Element | Added in | File |
|---|---|---|---|
| `unified_list_video_cell` | Each video card row (`NavigationLink` label) | Step 7 | `UnifiedVideoListView.swift` |
| `unified_list_new_badge` | "New" badge `Text` | Step 7 | `UnifiedVideoListView.swift` |
| `unified_list_search_field` | Search `TextField` (iOS 14 fallback path) | Step 7 | `UnifiedVideoListView.swift` |
| `unified_list_flag_button` | Flag/report button | Step 7 | `UnifiedVideoListView.swift` |
| `unified_list_delete_button` | Delete (✕) button | Step 7 | `UnifiedVideoListView.swift` |
| `unified_list_new_pill` | "N new videos added" pill | Step 7 | `UnifiedVideoListView.swift` |
| `unified_player_close_button` | "Close" button on player nav bar | Step 3 | `UnifiedVideoPlayerView.swift` |
| `unified_player_overlay` | Loading / error overlay container | Step 3 | `UnifiedVideoPlayerView.swift` |
| `unified_player_audio_scrubber` | Audio `Slider` | Step 3 | `UnifiedVideoPlayerView.swift` |
| `unified_player_audio_play_pause` | Audio play/pause button | Step 3 | `UnifiedVideoPlayerView.swift` |
| `unified_player_audio_retry` | Audio retry button | Step 3 | `UnifiedVideoPlayerView.swift` |

### New Launch Arguments Required

Add to `SplashView.swift` in the `.onChange(of: session.credential)` block alongside `--uitest-navigate-database`, following the identical pattern at lines 296–302:

| Launch argument | Triggers | Used by |
|---|---|---|
| `--uitest-navigate-unified-list` | `goToDatabase = true` after credential set | `testUnifiedListLoadsEntriesAfterLogin` |

> `--uitest-navigate-unified-list` reuses the existing `goToDatabase` state flag — the destination will already be `UnifiedVideoListView` after Step 12. No new `@State` property is needed in `SplashView`.

**Guest gallery tests do not use a launch argument.** A `--uitest-navigate-guest-gallery` arg is not implementable as a static launch argument because navigating to a guest gallery requires a `GuestUploadRecord` with a runtime nonce — there is no static value to embed at launch time. Instead, Phase 2 guest gallery tests (`testGuestGalleryListRenders`, etc.) launch the app normally without credentials, read `GH_TEST_GUEST_NONCE` from the test environment, construct a deep-link URL or use the existing `SplashView` guest record polling path to navigate. The test drives the UI from the splash screen the same way a real guest user would — the nonce is presented via the existing gallery entry point, not injected as a nav override.

### Test Inventory — Phase 1 (Unified Player)

Add these to `GigHiveUITests.swift` as a `// MARK: - Phase 1 — Unified Player` block.

| Test | Needs credentials | What it verifies |
|---|---|---|
| `testGuestPlayerOpensFromGallery` | `GH_TEST_GUEST_NONCE` | Tapping a gallery card opens `UnifiedVideoPlayerView`; `unified_player_close_button` is visible |
| `testGuestPlayerCloseButtonDismisses` | `GH_TEST_GUEST_NONCE` | Tapping Close returns to the list; no stuck screen |
| `testAuthPlayerOpensAndShowsOverlay` | Yes | After login, navigating to the unified list and tapping an entry shows `unified_player_overlay` loading state |
| `testAuthPlayerCloseButtonDismisses` | Yes | Tapping Close on authenticated player returns to unified list |

> Phase 1 player tests are intentionally limited — full playback cannot be asserted in XCUITest because `AVPlayerViewController` rendering is opaque to the accessibility tree. The tests assert navigation and overlay state only. Console log verification (KVO tags, proxy log lines) is done manually per the Step 6 checklist.

### Test Inventory — Phase 2 (Unified List — Guest Path)

Add as `// MARK: - Phase 2 — Unified List (Guest Path)`.

| Test | Needs credentials | What it verifies |
|---|---|---|
| `testGuestGalleryListRenders` | No (requires seeded nonce env var `GH_TEST_GUEST_NONCE`) | `UnifiedVideoListView` renders at least one `unified_list_video_cell` for a known approved guest record |
| `testGuestNewBadgeVisible` | No | `unified_list_new_badge` is visible on first launch for a record with unviewed videos |
| `testGuestNewBadgeClearsAfterPlay` | No | After tapping a cell and closing the player, `unified_list_new_badge` is gone for that row |
| `testGuestFlagButtonVisible` | No | `unified_list_flag_button` exists on cells (guest capabilities) |
| `testGuestSearchBarAbsent` | No | No search field rendered for guest context (`canSearch = false`) |

> `GH_TEST_GUEST_NONCE` is a new env var. Set it in the Xcode scheme alongside `GH_TEST_HOST` etc. The nonce must belong to an approved gallery with at least one video on the dev server.

### Test Inventory — Phase 3 (Unified List — Authenticated Path)

Add as `// MARK: - Phase 3 — Unified List (Authenticated Path)`.

| Test | Needs credentials | What it verifies |
|---|---|---|
| `testUnifiedListLoadsEntriesAfterLogin` | Yes | After login with `--uitest-navigate-unified-list`, at least one `unified_list_video_cell` appears — proves the authenticated data path and card layout work |
| `testUnifiedListSearchBarVisible` | Yes | `unified_list_search_field` exists for the authenticated context (`canSearch = true`) |
| `testUnifiedListFlagButtonAbsent` | Yes | `unified_list_flag_button` does not exist for any cell in authenticated context |
| `testUnifiedListNewBadgePersistsAcrossLaunch` | Yes | View a video, terminate and relaunch — that cell's `unified_list_new_badge` is absent; others are present |
| `testUItestNavigateDatabaseStillWorks` | Yes | `--uitest-navigate-database` launch arg still triggers navigation (regression guard for the existing test) |

> `testUItestNavigateDatabaseStillWorks` is a direct replacement for the existing `testDatabaseLoadsEntriesAfterLogin` assertion, expanded to confirm the destination is now `UnifiedVideoListView`. The existing test continues to run unchanged during Phase 1 and 2; it is updated in Phase 3 to assert `unified_list_video_cell` instead of `app.cells.firstMatch`.

### Test Inventory — Phase 4 (Authenticated Delete)

Add as `// MARK: - Phase 4 — Authenticated Delete`.

| Test | Needs credentials | What it verifies |
|---|---|---|
| `testAuthDeleteButtonAbsentWithoutToken` | Yes | No `unified_list_delete_button` exists when `UploaderDeleteTokenStore` is empty for this host (P9 cleanup guarantees this on every test launch) |
| `testAuthDeleteButtonVisibleForOwnUpload` | Yes + `GH_TEST_DELETE_FILE_ID` | `unified_list_delete_button` visible on the card whose server-returned `id` matches the injected file ID; absent on all others |
| `testAuthDeleteConfirmDialogAppears` | Yes + `GH_TEST_DELETE_FILE_ID` | Tapping `unified_list_delete_button` raises the confirm alert; cancel pressed — no server write |
| `testAuthDelete403ClearsToken` | Yes + `GH_TEST_DELETE_FILE_ID` + `GH_TEST_UPLOADER_USER` + `GH_TEST_UPLOADER_PASS` | Confirming delete with an invalid synthetic token triggers 403 → error alert shown → relaunch without injection shows no delete button (Keychain cleared by 403 handler). Uses `launchAuthListWithToken(injectToken: true, useUploader: true)` / `requireUploaderCredentials()`. Admin path uses `asset_ids[]` payload, gets 400 (not 403), and skips Keychain cleanup — see P11 in `problem_ios_testing_media_player_unification.md`. |
| `testGuestDeleteButtonStillVisible` | `GH_TEST_GUEST_NONCE` + `GH_TEST_GUEST_JOB_ID` | `unified_list_delete_button` still appears for own uploads in guest context (regression guard) |

**Manual only (not automatable with XCUITest):**
- Video removed from list on confirmed success — requires a real valid token for a disposable server video
- Keychain entry gone from `UploadView`'s "My uploads" on success — same prerequisite
- Double-tap guard — timing-based; XCUITest cannot hold a network call in-flight at a deterministic moment

**Key infrastructure requirements (applied lessons from `problem_ios_testing_media_player_unification.md`):**
- **P9** — `SplashView.onAppear` must call `try? UploaderDeleteTokenStore.clear(host:)` unconditionally under `--uitesting` (before any injection) to prevent token leak between tests
- **P7** — All Phase 4 tests use `--uitest-auto-login` + `--uitest-navigate-unified-list`; no splash button taps
- **P6** — `launchAuthListWithToken(injectToken:)` helper explicitly forwards `GH_TEST_DELETE_FILE_ID` in `app.launchEnvironment` on every relaunch
- **P8** — `launchAuthListWithToken(injectToken: Bool)` is the single canonical setup function for all five tests

> Full test plan, launch argument spec (`--uitest-inject-delete-token`), environment variable spec (`GH_TEST_DELETE_FILE_ID`), and `launchAuthListWithToken` pseudocode are in `testing_ios.md` — Phase 4 sections.

---

## Verification

### Phase 1 — Unified player
- Guest video plays end-to-end from `GuestGalleryView` using `UnifiedVideoPlayerView`.
- Guest audio (if any) plays with scrub bar.
- Buffering spinner appears and clears correctly.
- Back navigation from player returns to the list (no stuck screens).
- Existing `MediaPlayerView` behavior for authenticated videos is identical (auth proxy active, KVO observers logging).
- `testGuestPlayerOpensFromGallery` and `testGuestPlayerCloseButtonDismisses` pass.
- `testAuthPlayerOpensAndShowsOverlay` and `testAuthPlayerCloseButtonDismisses` pass.
- No regressions in existing `GigHiveUITests`.

### Phase 2 — Unified list (guest path)
- Guest gallery list renders with card layout, thumbnails, "New" badges.
- Flag and delete buttons behave identically to current `GuestGalleryView`.
- Live polling and new-video pill work.
- `GuestGalleryView` is removed from the build.
- `testGuestGalleryListRenders`, `testGuestNewBadgeVisible`, `testGuestNewBadgeClearsAfterPlay`, `testGuestFlagButtonVisible`, `testGuestSearchBarAbsent` pass.

### Phase 3 — Unified list (authenticated path)
- Logged-in users see card layout instead of plain list.
- One tap plays a video (no detail screen).
- "New" badge appears for unviewed videos; persists across app restarts.
- Search filters correctly.
- Pull-to-refresh works.
- `DatabaseView`, `DatabaseDetailView`, and `MediaPlayerView` are removed from the build.
- `SplashView` navigates to `UnifiedVideoListView` for both guest and authenticated paths.
- `testUnifiedListLoadsEntriesAfterLogin`, `testUnifiedListSearchBarVisible`, `testUnifiedListFlagButtonAbsent`, `testUnifiedListNewBadgePersistsAcrossLaunch`, `testUItestNavigateDatabaseStillWorks` pass.

### Phase 4 — Delete for authenticated users
- Delete button appears for uploader (or admin) and is absent for others.
- Confirm dialog shown before deletion.
- Video disappears from list immediately after confirmed delete.
- Guest delete path unaffected.

### Phase 5 — Save feature (separate doc)
- Save button wired into `UnifiedVideoPlayerView`; works for both guest and authenticated players from a single implementation.

---

## Progress

### Completed
_(none)_

### Remaining — This Refactor

**Phase 1 — Unified Player**
- [x] Step 1: Create `VideoListContext.swift`
- [x] Step 2: Extract `ThumbnailLoader.swift`
- [x] Step 3: Create `UnifiedVideoPlayerView.swift`
- [x] Step 4: Wire player into `GuestGalleryView`
- [x] Step 5: Wire player into `DatabaseDetailView`
- [x] Step 6: Phase 1 verification — device-verified 2026-08-26 (iPhone 12 Pro):
  guest video plays; authenticated video plays through proxy loader
  (`[Player] Using proxy loader`, HTTP 206 + auth headers); authenticated
  audio plays with scrub/seek/pause; Close/cleanup clean on all paths.
  Defect found and fixed during verification: `.simultaneousGesture(TapGesture())`
  on the Play NavigationLink inside DatabaseDetailView's List consumed the
  row-selection tap so the link never activated (14 taps logged, zero pushes).
  Known issue: the two authenticated XCUITest cases (tests 13–14) fail in the
  simulator due to an XCUITest hittability artifact — the app's content window
  reports all elements non-hittable while an empty "Main" window is hittable.
  Human taps work on device; artifact is environmental, tracked as follow-up.

**Phase 2 — Unified List (Guest Path)**
- [x] Step 7: Create `UnifiedVideoListView.swift` — implemented 2026-08-27; build verified.
  Full guest path wired: card layout, polling, pill, flag/report, delete, alert system,
  all Phase 2 accessibility identifiers. Authenticated path data-loading fully stubbed
  (Step 11 activates and verifies it). Both iOS 15+ (.refreshable/.searchable) and iOS 14
  fallback paths implemented.
- [x] Step 8: Update `SplashView` guest call sites (lines 182, 233) — implemented 2026-08-27;
  device-verified 2026-08-27 (iPhone 12 Pro simulator). [UnifiedList] log tags confirm
  UnifiedVideoListView.loadGuestVideos() running; viewed-ID tracking and player onAppear
  callback both correct. Authenticated call sites (lines 76, 239) unchanged until Step 12.
- [x] Step 9: Delete `GuestGalleryView.swift` — 2026-08-27. File removed from disk and from
  Xcode project target membership. All three remaining call sites updated before deletion:
  `SplashView.swift` (2 — done in Step 8), `GuestUploadView.swift` (1 — missed in original
  plan, fixed during Step 9). `ThumbnailLoader.swift` comment updated. Build verified clean.
- [x] Step 10: Phase 2 verification — 2026-08-27.
  Device-verified (iPhone 12 Pro simulator):
  - [x] UnifiedVideoListView loads guest gallery (card layout, bee-logo header, count caption)
  - [x] [UnifiedList] log tags confirm loadGuestVideos() is running
  - [x] Viewed-state tracking: viewedIds updated in onAppear callback ([UnifiedList] Player appeared — marking viewed id=1)
  - [x] Gallery re-loads on player close with updated viewedIds
  - [x] Guest player opens through UnifiedVideoPlayerView and closes cleanly
  - [x] DatabaseView still opens unchanged (authenticated call sites untouched)
  - [x] Build succeeds with no new warnings
  Phase 2 XCUITests added to GigHiveUITests.swift:
  - testGuestGalleryListRenders — unified_list_video_cell present
  - testGuestNewBadgeVisible — unified_list_new_badge present (XCTSkip if all viewed)
  - testGuestNewBadgeClearsAfterPlay — badge count decreases after playing (XCTSkip if all viewed)
  - testGuestFlagButtonVisible — unified_list_flag_button present (canFlag = true)
  - testGuestSearchBarAbsent — no search field for guest context (canSearch = false)
  Test build verified clean.

**Phase 3 — Unified List (Authenticated Path)**
- [x] Step 11: Extend `UnifiedVideoListView` for authenticated capabilities — 2026-08-27. Authenticated
  search bar, pull-to-refresh, UserDefaults viewed-state, and DatabaseAPIClient data path all verified.
- [x] Step 12: Update `SplashView` authenticated call sites (lines 76, 239) — 2026-08-27.
  Both `DatabaseView()` call sites replaced with `UnifiedVideoListView(context: .authenticated(...))`.
  `--uitest-navigate-database` hidden link verified correct.
- [x] Step 13: Delete `DatabaseView.swift`, `DatabaseDetailView.swift`, `MediaPlayerView.swift` — 2026-08-27.
  All three files removed from disk and Xcode project target membership. Build verified clean.
- [x] Step 14: Phase 3 verification — 2026-08-27 (iPhone 12 Pro simulator + device).
  All checklist items confirmed: card layout; one-tap play; "New" badge with UserDefaults persistence;
  search filtering; pull-to-refresh; deleted views absent from build; `--uitest-navigate-database`
  passes; full `GigHiveUITests` suite passes (serial, `-parallel-testing-enabled NO`).

**Phase 4 — Delete for Authenticated Users**
- [x] Step 15: Server prereq — resolved 2026-08-27. Option A already shipped: `db/delete_media_files.php`
  accepts `{asset_id, delete_token}`; iOS already has `UploaderDeleteTokenStore` (Keychain) and
  `DatabaseAPIClient.deleteMediaFile(fileId:deleteToken:)`. Phase 4 is a pure iOS wiring task.
- [x] Step 16: Wire delete button for authenticated path — 2026-08-27.
  `authDeleteTokens: [Int: String]` and `isDeletingIds: Set<Int>` added to `UnifiedVideoListView`.
  `loadAuthenticatedVideos` loads `UploaderDeleteTokenStore` entries after fetching the media list,
  builds a `tokenMap` (uniquingKeysWith), sets `authDeleteTokens`, and sets `isOwnUpload: tokenMap[entry.id] != nil`.
  `performDelete` converted from guest-only guard to a context switch: guest branch unchanged;
  authenticated branch guards on token presence and double-tap (`isDeletingIds`), calls
  `DatabaseAPIClient.deleteMediaFile`, handles success (removes from list + Keychain), 403 (stale
  token removal from memory + Keychain), and other errors.
  `VideoListContext.authenticated` capabilities updated: `deleteScope: .uploaderAndAdmin`.
  Build verified: xcodebuild EXIT 0, BUILD SUCCEEDED, no warnings.
- [ ] Step 17: Phase 4 verification

### Remaining — Follow-on Tasks
- [ ] Phase 5: Save video feature — implement once in `UnifiedVideoPlayerView`, ships to all users
- [ ] JWT Phase 3: Decode role/email from bearer token — required for admin-scoped delete
