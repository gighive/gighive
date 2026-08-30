---
description: Root cause analysis of authenticated video playback failure and XCUITest simulator hittability artifact encountered during Phase 1 of the unified media player refactor. Two separate defects: a SwiftUI gesture interaction bug that silently ate navigation taps, and an XCUITest multi-window artifact that made every UI element non-hittable in the simulator. Also documents credential exposure in media loader logs and a pre-existing duplicate-ID data issue.
---

# Problem: iOS Media Player Unification — Phase 1 Defects

## Executive Summary

After wiring the new unified video player into the authenticated database screen, tapping Play did nothing — no video appeared and no player screen opened for any user. The root cause was a one-line Swift modifier that intercepted every tap gesture on the Play button before the navigation system could see it. The fix was to remove that modifier and move the log call into the player's open event. A second, separate issue means two automated UI tests still fail in the simulator — the test runner reports the app's content as untouchable, even though the same flows work perfectly on a real device. Those tests are skipped with a documented note until the simulator artifact is understood.

---

## Summary

During Phase 1 of the `refactor_video_player_page.md` plan, `UnifiedVideoPlayerView` was wired into `DatabaseDetailView` as the replacement for `MediaPlayerView`. After wiring, authenticated playback was completely broken: tapping the Play row in `DatabaseDetailView` produced no navigation to the player. Extensive XCUITest automation was also added and run to verify behavior, but two of those tests failed in a way that was initially ambiguous — it was unclear whether the app was broken or the test tooling was at fault.

Root cause analysis established two independent defects:

1. **Navigation never activated** — `.simultaneousGesture(TapGesture().onEnded { ... })` was attached to the Play `NavigationLink` inside a SwiftUI `List`. Inside a `List`, a simultaneous gesture intercepts the touch event before the `NavigationLink` machinery can process it. The gesture callback fired on every tap (confirmed by log output), but the `NavigationLink` never activated, so the player was never pushed onto the navigation stack.

2. **XCUITest hittability artifact** — After the navigation fix, human taps worked on a physical iPhone 12 Pro, but the two authenticated XCUITest cases continued to fail. Investigation showed the simulator presented the app in a multi-window scene configuration where XCUITest considered the app's content window non-hittable and an empty "Main" window as the hittable target. This is an environmental XCUITest artifact, not an app-level defect.

Two additional issues were identified but not fixed during this session:

3. **Credential exposure in media loader logs** — `MediaResourceLoader` logs the full `Authorization: Basic <value>` header in plaintext. The development credential was visible in console logs during diagnostic testing.

4. **Duplicate `MediaEntry` IDs** — The server returned multiple entries with `id = 1`, triggering SwiftUI's `ForEach` duplicate-ID warning. This is a pre-existing server/data issue unrelated to the player refactor but capable of causing undefined list behavior.

---

## Impact

| Who | Impact |
|---|---|
| All authenticated users | Play button silently did nothing; no video or audio was accessible via the unified player until the fix was applied |
| Developers running the automated suite | Two tests failed on every run, creating ongoing false-negative noise |
| Developers reading console logs | Development credentials were visible in plaintext in Xcode console output |
| Authenticated users with duplicate-ID entries | Potential for undefined SwiftUI list behavior (row duplication, inconsistent state) |

---

## Symptoms

**Defect 1 — Navigation not activating:**
- Tapping the Play row in `DatabaseDetailView` produced no visible response — no push animation, no player screen, no `[Player]` log tags
- The `[Detail] Play tapped` log line appeared on every tap (up to 14 taps logged in one session)
- No `[Detail] Player opened` log line ever appeared
- No `[Player]` log tags appeared at any point
- The diagnostic HEAD request was never initiated
- Returning to `DatabaseView` and re-entering `DatabaseDetailView` produced identical results on every attempt
- The same playback flow worked correctly on the guest gallery path (which used the same player but no `simultaneousGesture`)

**Defect 2 — XCUITest hittability failure:**
- `testAuthPlayerOpensAndShowsOverlay` and `testAuthPlayerCloseButtonDismisses` failed every run in the iPhone 12 simulator (iOS 17.5, UUID `BC84B10A-07EF-4DAD-A91A-F48B991C5C16`)
- Failure screenshots showed the app fully visible with correct content in the simulator window
- XCUITest button dumps showed all buttons present in the accessibility tree with non-empty labels
- Despite presence, `isHittable` returned `false` for every app content element
- An empty window named "Main" was present and hittable; the content window was not
- Error message: `"Failed to get matching snapshot"` or tap attempts with no effect on hittable status

**Defect 3 — Credential exposure:**
- Xcode console output during authenticated playback contained lines of the form:
  `[Loader] GET https://dev.gighive.app/video/<hash>.mp4 headers=["Authorization": "Basic <base64value>"]`
- The full Base64-encoded credential was visible in the log output

**Defect 4 — Duplicate IDs:**
- Xcode console contained:
  `ForEach<Array<MediaEntry>, Int, NavigationLink<...>>: the ID 1 occurs multiple times within the collection, this will give undefined results!`
- The server was returning multiple `MediaEntry` records with `"id": 1`

---

## Problems Encountered

### Step 1 — Parallel test execution inflated failure times

**Observed:** Initial test runs used Xcode's default parallel test execution. Failures were slow to surface (30–35 seconds per failure) and causally ambiguous because multiple tests ran simultaneously.

**Evidence:** Test runner output showed interleaved log lines from different tests; failures occurred long after the last relevant test action due to broad timeout waits.

**Fix:** Added `-parallel-testing-enabled NO` to the `xcodebuild test` invocation:

```bash
xcodebuild test \
  -scheme GigHive \
  -destination "platform=iOS Simulator,id=BC84B10A-07EF-4DAD-A91A-F48B991C5C16" \
  -parallel-testing-enabled NO
```

**Why this mattered:** Serial execution makes it unambiguous which test caused which output, and which failure is a regression versus a pre-existing gap.

---

### Step 2 — Initial hypothesis: missing `NavigationStack` container

**Observed:** NavigationLink pushes appeared to not function. Initial hypothesis was that `SplashView` lacked a navigation container and child `NavigationLink`s could not push.

**Evidence check:** `GigHiveApp.swift` wraps `SplashView` in `NavigationStack` on iOS 16+ and `NavigationView` on older systems. The navigation container was present.

**Result:** Hypothesis rejected. Root cause was not a missing container.

---

### Step 3 — Isolating where the failure occurred

**Diagnostic sequence applied:**

1. Confirmed `DatabaseView` appeared after login — navigation from `SplashView` to `DatabaseView` worked
2. Confirmed `DatabaseDetailView` appeared after tapping a row — navigation from `DatabaseView` to `DatabaseDetailView` worked
3. Tapped Play in `DatabaseDetailView` — saw `[Detail] Play tapped` in console (gesture fired), but **no** `[Player]` log tags and no push
4. Confirmed `[Detail] Player opened` never appeared — NavigationLink destination never instantiated

**Conclusion from step 3:** The play gesture callback was being reached. The NavigationLink itself was not activating. These are two different things in SwiftUI when `.simultaneousGesture` is involved.

**Key diagnostic log that isolated the defect:**

```
[Detail] Play tapped          ← gesture fired (up to 14 times)
                              ← no [Player] tags ever followed
```

---

### Step 4 — XCUITest navigation: `splash_view_database_button` unreliable

**Observed:** Initial authenticated tests tapped `splash_view_database_button` (the real visible NavigationLink on the splash screen) to navigate to `DatabaseView`. Failure screenshots showed the app still on `SplashView` after the tap attempt.

**Evidence:** The visible button existed in the accessibility tree and `waitForExistence` returned `true`, but the tap produced no navigation. This was attributed to the same XCUITest hittability artifact affecting the content window.

**Fix:** Retained the existing `--uitest-navigate-database` launch argument path (already implemented in `SplashView`) for all automated database-list navigation. This path bypasses the content-window hittability issue by triggering navigation programmatically on credential change.

---

### Step 5 — XCUITest row query instability

**Observed:** Queries such as:

```swift
app.buttons.matching(
    NSPredicate(format: "label != '' AND label != 'Back'")
).firstMatch
```

produced inconsistent results:
- Selected splash-screen buttons instead of database rows
- Triggered `"Failed to get matching snapshot"` errors
- Found multiple matching elements with identical labels (related to duplicate ID issue)
- Found elements that existed but were not hittable

**Approaches tried (in order):**
1. Excluded known splash labels from the predicate
2. Used exact row labels
3. Enumerated all matching elements to log what was found
4. Used coordinate taps instead of element taps
5. Added screenshots at failure point
6. Added full button dumps at failure point
7. Narrowed the predicate further
8. Added explicit state-transition diagnostics between each navigation step

**Outcome:** Every approach confirmed the same artifact — elements existed and were visible, but `isHittable = false` on the content window. No query or tap strategy resolved the underlying multi-window hittability issue in the simulator.

---

### Step 6 — Temporary diagnostic tests added then removed

**Added during diagnosis:**
- `testDiagnosticSplashToDatabase` — verified `SplashView` → `DatabaseView` navigation step in isolation
- `testDiagnosticDatabaseToDatabaseDetail` — verified `DatabaseView` → `DatabaseDetailView` navigation step in isolation
- `testDiagnosticDatabaseDetailHasPlayButton` — verified `detail_play_button` was present in the accessibility tree
- A `coordinateTap(element:)` helper to work around `isHittable = false`

**Removed after physical-device testing established root cause.** Keeping diagnostic tests in the suite permanently would misrepresent the test inventory. The underlying problem was in `DatabaseDetailView.swift`, not in the test navigation.

---

### Step 7 — Physical device testing as ground truth

**Decision:** Because XCUITest was reporting conflicting results (elements present but not hittable), physical-device testing was used to separate app behavior from test-tooling behavior.

**Test environment:** iPhone 12 Pro, iOS (physical device). App built and deployed via Xcode to the device. Manual taps observed in the Xcode console.

**Result before fix (Defect 1 still present):**
```
[Detail] Play tapped   ← gesture fires on device too
                       ← no [Player] tags; no navigation
```

This proved the defect was in the app code, not the simulator or test tooling.

**Result after fix:**
```
[Detail] Player opened; type=video
[Player][HEAD] status=200 CT=video/mp4 Accept-Ranges=bytes
[Player] Using proxy loader; custom URL=gighive://...
[Loader] HTTP 206 for /video/...
[Player] Item status: readyToPlay
[Player] ▶️ timeControlStatus=playing
[Player] Close tapped
[Player] Cleaning up player resources
```

This proved the fix was complete and the app path was correct.

---

## Root Cause

### Defect 1 — `.simultaneousGesture` on NavigationLink inside a List

**File:** `GigHive/Sources/App/DatabaseDetailView.swift`

**Pattern that caused the defect:**

```swift
NavigationLink(destination: UnifiedVideoPlayerView(config: playerConfig)) {
    // Play row UI
}
.simultaneousGesture(TapGesture().onEnded {
    logWithTimestamp("[Detail] Play tapped")
})
```

**Why this fails:**

SwiftUI's `List` uses its own internal gesture recognizer to handle row selection and `NavigationLink` activation. When `.simultaneousGesture(TapGesture())` is attached to an element inside a `List`, the gesture system delivers the touch event to both the explicit `TapGesture` and the implicit list selection gesture simultaneously — but the `TapGesture` wins the gesture competition and the `NavigationLink`'s activation sequence is never completed. The gesture callback fires (hence `[Detail] Play tapped` appearing 14 times), but the destination view is never pushed.

This failure mode is specific to `NavigationLink` inside `List`. The same `.simultaneousGesture` pattern does not cause problems when used on a `NavigationLink` outside a `List` (e.g., in a `VStack`), which is why the guest gallery path (which uses `ForEach` inside a `ScrollView` rather than a `List`) worked correctly with an `onAppear` callback and no gesture interference.

**Evidence establishing 100% certainty:**
- `[Detail] Play tapped` appeared on every tap (gesture callback reached)
- `[Detail] Player opened` never appeared (destination never instantiated)
- The player's `onAppear` never fired
- HEAD diagnostic never ran
- Removing `.simultaneousGesture` and moving the log to `onAppear` immediately produced correct behavior on the first tap

---

### Defect 2 — XCUITest multi-window hittability artifact

**Environment:** iPhone 12 simulator, iOS 17.5 (UUID `BC84B10A-07EF-4DAD-A91A-F48B991C5C16`)

**Observation:** The app presents in a multi-window scene configuration where XCUITest enumerates multiple windows. The content window (which contains all app UI) has `isHittable = false` from XCUITest's perspective. An additional empty window named "Main" is hittable. XCUITest's tap delivery goes to the hittable window, which is empty, so no taps reach app content.

**Why human taps work:** A human tapping the physical device or simulator screen bypasses XCUITest's window-routing logic entirely. Touch events go directly to the hit-tested view in the window hierarchy. The multi-window hittability categorization is an XCUITest layer artifact only.

**Why some tests pass despite this:** Tests that use launch arguments (`--uitest-navigate-database`) trigger navigation programmatically via `SplashView`'s `.onChange(of: session.credential)` handler — these do not require XCUITest to tap anything in the content window, so they succeed regardless of the hittability artifact.

**Root cause is not fully characterized** — it is not yet known why the app presents in this multi-window configuration that XCUITest considers differently from a single-window app. Candidates include:
- The `WindowGroup` + `NavigationStack` scene configuration in `GigHiveApp.swift`
- The iOS simulator's handling of multiple `UIWindow` instances created by SwiftUI
- An interaction between the `NavigationStack` and a system-generated auxiliary window

This requires further investigation and is tracked as a follow-on task.

---

### Defect 3 — Credential exposure in MediaResourceLoader logs

**File:** `GigHive/Sources/App/UnifiedVideoPlayerView.swift` (via `MediaResourceLoader`)

**Current log line (unsafe):**
```
[Loader] GET https://dev.gighive.app/video/<hash>.mp4 headers=["Authorization": "Basic <base64value>"]
```

The `Authorization` header value contains a Base64-encoded string that decodes directly to `username:password`. Any developer with Xcode console access, a log file, or a crash report attachment can read the credential.

**Root cause:** The diagnostic log statement was written to print the full `headers` dictionary for completeness, without redacting sensitive key values.

---

### Defect 4 — Duplicate MediaEntry IDs

**Source:** `dev.gighive.app` database endpoint (`/db/database.php?format=json`)

The server returned multiple `MediaEntry` records with `"id": 1`. SwiftUI's `ForEach` uses `id` as the identity key for diffing. When two items share the same `id`, SwiftUI emits:

```
ForEach<Array<MediaEntry>, Int, NavigationLink<MediaEntryRow, DatabaseDetailView>>:
the ID 1 occurs multiple times within the collection, this will give undefined results!
```

**Root cause:** A data integrity issue on the server. Either the auto-increment primary key was reset at some point, or entries were inserted manually without respecting the key sequence.

This is unrelated to the player refactor but can cause undefined list behavior (duplicate rows, incorrect row updates, navigation to wrong detail view) if left unaddressed.

---

## Resolution

### Defect 1 — Fixed

**File:** `GigHive/Sources/App/DatabaseDetailView.swift`

Removed `.simultaneousGesture(TapGesture().onEnded { ... })` from the Play `NavigationLink`. Moved the diagnostic log into the player's `onAppear` callback, which fires only when the destination actually appears (i.e., only when navigation succeeds):

```swift
// Before (broken):
NavigationLink(destination: UnifiedVideoPlayerView(config: playerConfig)) {
    MediaEntryRow(entry: entry)
}
.simultaneousGesture(TapGesture().onEnded {
    logWithTimestamp("[Detail] Play tapped")
})
.accessibilityIdentifier("detail_play_button")

// After (correct):
NavigationLink(destination: UnifiedVideoPlayerView(
    config: playerConfig,
    onAppear: {
        logWithTimestamp("[Detail] Player opened; type=\(playerConfig.fileType.rawValue)")
    }
)) {
    MediaEntryRow(entry: entry)
}
// NOTE: Do not attach .simultaneousGesture(TapGesture()) to a NavigationLink
// inside a List. The gesture wins the gesture competition and the NavigationLink
// activation sequence never completes. Use onAppear on the destination instead.
.accessibilityIdentifier("detail_play_button")
```

The `detail_play_button` accessibility identifier was retained so the XCUITest suite can still locate the element once the hittability artifact is resolved.

---

### Defect 2 — Fully resolved (tests 13–14 pass)

The XCUITest multi-window hittability artifact was caused by keyboard input during login. The fix was to add a silent auto-login path that injects credentials programmatically (via `SplashView.onAppear`) without ever showing the keyboard. Five implementation defects were encountered and resolved along the way; see the dedicated "Phase 1b" section below.

**File:** `GigHive/Sources/App/SplashView.swift` — added `--uitest-auto-login` branch in `onAppear` that reads `GH_TEST_HOST`, `GH_TEST_USER`, `GH_TEST_PASS` from `ProcessInfo.processInfo.environment` and sets `session.baseURL`, `session.credential`, `session.allowInsecureTLS` without presenting `LoginView` or focusing any text field.

**File:** `GigHive/GigHiveUITests/GigHiveUITests.swift` — added `autoLogin()` and `navigateToPlayer()` helpers; removed `XCTSkip` from tests 13–14; added `--uitest-auto-login` to the launch args set for those tests.

Tests 13 and 14 now pass on every run (serial, iPhone 12 Pro simulator):

```
Test Case '-[GigHiveUITests testAuthPlayerCloseButtonDismisses]' passed (20.4 seconds).
Test Case '-[GigHiveUITests testAuthPlayerOpensAndShowsOverlay]'  passed (15.0 seconds).
```

---

### Phase 1b — Five implementation defects resolved during Defect 2 fix

#### 1. `allowInsecureTLS` mismatch

**Problem:** `LoginView.onAppear` under `--uitesting` unconditionally sets `disableCertChecking = true`. The auto-login path read `GH_TEST_INSECURE` from the environment; if that variable is not set, `session.allowInsecureTLS` remained `false`. The first `DatabaseView.loadData()` call then failed with a TLS error, showing the "Retry" error UI instead of data rows.

**Fix:** Auto-login now always sets `session.allowInsecureTLS = true` under `--uitesting`, matching `LoginView` behavior.

#### 2. Row predicate matched the Retry button

**Problem:** When `allowInsecureTLS` was wrong, `DatabaseView` displayed `Button("Retry")` on API failure. The `waitForExistence` predicate (`label != '' AND label != 'Back' AND ...`) matched "Retry", so `autoLogin()` returned "success" after finding the error button. The test then tapped Retry, which re-triggered the failing network call, not database-row navigation.

**Fix:** Added `AND label != 'Retry'` to the row predicate everywhere it is used.

#### 3. Programmatic NavigationLink blocking child navigation

**Problem:** Using `--uitest-navigate-database` (hidden `isActive: $goToDatabase` binding in `SplashView`) pushed `DatabaseView` programmatically. Child `NavigationLink` rows inside `DatabaseView`'s `List` did not activate when tapped — the push to `DatabaseDetailView` never happened even with a single window and correct credentials.

**Evidence:** `app.buttons.count == 1` ("Media Details" nav title) after row tap with 2-second sleep; XCUITest logged synthesize-event and idle for the row tap, but no navigation.

**Fix:** Tests 13–14 now call `autoLogin()` (no nav arg) to stay on SplashView, then call `navigateToPlayer()` which taps `splash_view_database_button` as a user-initiated push. A user-initiated NavigationLink push correctly enables child NavigationLink rows to activate.

#### 4. Play button off-screen in landscape

**Problem:** `DatabaseDetailView` uses an insetGrouped `List` with a "Media Info" section (6 detail rows × ~44 pt) above the play-button section. On the iPhone 12 Pro in landscape mode (390 pt viewport height), the play button section falls below the bottom edge of the screen. XCUITest does not traverse off-screen `UICollectionView` cells (SwiftUI `List` on iOS 16+ uses `UICollectionView`, not `UITableView`). `detail_play_button.waitForExistence(15)` polled for 15 seconds and found nothing because the element was never in the accessibility tree.

**Evidence:** After a 2-second sleep post-row-tap, only 1 button existed: label="Media Details", id="" (the navigation bar title). The list cells were present in the UI but not in XCUITest's snapshot.

**Fix:** Added `app.swipeUp()` in `navigateToPlayer()` after waiting for the "Date" cell label (the first rendered list cell, confirming the `List` has fully rendered), then `waitForExistence(15)` on `detail_play_button`. `app.swipeUp()` scrolls whatever scroll view is in focus; it does not require knowing whether the underlying view is a table or collection view.

#### 5. Duplicate play-button wait in `testAuthPlayerCloseButtonDismisses`

**Problem:** `navigateToPlayer()` ends by calling `playButton.tap()` (tapping `detail_play_button`), opening the player. `testAuthPlayerCloseButtonDismisses` then had a second `waitForExistence(15) + tap()` for `detail_play_button` in the test body. By that point the player was open and `detail_play_button` was behind the player view (not in the accessibility tree). The second wait ran for its full 15 seconds and failed.

**Evidence:** XCUITest trace showed two consecutive `Waiting 15.0s for "detail_play_button" Button to exist` log entries for test 14 but only one for test 13 (which had no duplicate).

**Fix:** Removed the duplicate lines from `testAuthPlayerCloseButtonDismisses`. `navigateToPlayer()` is the single canonical path to the player.

---

### Defect 3 — Not yet fixed

The fix is to redact the `Authorization` header value before logging, replacing it with a safe presence indicator:

```swift
// Current (unsafe):
print("[Loader] GET \(url) headers=\(headers)")

// Required (safe):
let safeHeaders = headers.mapValues { key, _ in
    key.lowercased() == "authorization" ? "<redacted>" : value
}
print("[Loader] GET \(url) authPresent=\(headers["Authorization"] != nil)")
```

The development password that appeared in console logs during this session should be rotated.

**Tracked as follow-on work** — not blocking Phase 1 verification, but must be fixed before any log output is shared externally (crash reports, support logs, screen recordings).

---

### Defect 4 — Not yet fixed

The server must enforce uniqueness on the `id` field for `MediaEntry` records. The specific correction depends on whether the primary key was reset (requires reseed of auto-increment value) or whether duplicate rows were inserted (requires deduplication and a `UNIQUE` constraint).

**Tracked as follow-on work** — not caused by the refactor and not blocking Phase 1. Should be addressed before Phase 3 ships, because Phase 3 introduces card-layout list rendering that depends heavily on stable SwiftUI identity for correct row diffing.

---

## Verification

### Defect 1 — Verified on physical device (iPhone 12 Pro, 2026-08-26)

Full playback verification log (video path):
```
[Detail] Player opened; type=video
[Player][HEAD] status=200 CT=video/mp4 Accept-Ranges=bytes
[Player] Using proxy loader; custom URL=gighive://dev.gighive.app:443/video/<hash>.mp4
[Loader] HTTP 206 for /video/<hash>.mp4
[Player] Item status: readyToPlay
[Player] ▶️ timeControlStatus=playing
[Player] Close tapped
[Player] Cleaning up player resources
```

Full playback verification log (audio path):
```
[Detail] Player opened; type=audio
[Player][HEAD] status=200 CT=audio/mpeg Accept-Ranges=bytes
[Player] Using proxy loader; custom URL=gighive://dev.gighive.app:443/audio/<hash>.mp3
[Audio] Item ready; attempting autoplay
[Player] ▶️ timeControlStatus=playing
[Audio] Started scrubbing
[Audio] Seeking to time=44.079...
[Audio] Seek completed
[Audio] Pause tapped
```

All Phase 1 verification checklist items confirmed on device:
- Guest video plays end-to-end via `UnifiedVideoPlayerView`; KVO log tags present
- Authenticated video plays with auth proxy active
- Authenticated audio plays; scrub bar updates; seek completes
- HEAD preflight fires for authenticated path
- `cleanup()` called on disappear; no observer leaks observed on repeated entry/exit cycles

### Defect 2 — Verified: full suite passes

Suite result (serial, `-parallel-testing-enabled NO`, iPhone 12 Pro simulator):

```
Test Suite 'GigHiveUITests' passed
Test Suite 'GigHiveUITestsLaunchTests' passed
```

- Tests 1–12: pass (login, logout, database load, guest player, performance, upload duplicate)
- Tests 13–14: **pass** (authenticated player open and close/dismiss — fully re-enabled)
- Tests 15–17: pass (launch performance)
- Guest player tests: skipped (expected — `GH_TEST_GUEST_NONCE` not set in this environment)

### Defects 3 and 4 — Not yet verified

Both are documented here and tracked; verification steps will be added to the relevant follow-on tickets when work begins.

---

## Preventative Actions

### P1 — Do not attach `.simultaneousGesture(TapGesture())` to a `NavigationLink` inside a `List`

This combination reliably prevents `NavigationLink` activation. The tap gesture wins the gesture competition and the link never fires. If diagnostic logging is needed at the moment of a navigation tap, use the destination view's `onAppear` callback instead — `onAppear` fires if and only if the navigation succeeded, which is also the correct logical placement for a "navigation occurred" log line.

**Documentation added:** A comment was placed directly above the Play `NavigationLink` in `DatabaseDetailView.swift` documenting this behavior so future developers do not re-introduce the pattern.

---

### P2 — Never log credential values in any log path

Any log statement that prints an HTTP header dictionary must explicitly redact `Authorization`, `Cookie`, `X-API-Key`, and any other security-sensitive headers. Replace the value with `<redacted>` or a boolean presence indicator (`authPresent=true`).

This applies to all diagnostic logging in:
- `MediaResourceLoader`
- `UnifiedVideoPlayerView`
- Any future networking helper

**Rule:** A log line may indicate that an auth header is present, but must never reveal its value. Treating diagnostics as a safe output channel for credentials is incorrect — crash reports, support sessions, and screen recordings all capture console output.

---

### P3 — Use physical device as ground truth when XCUITest reports non-hittable elements in the simulator

When XCUITest fails with elements visible-but-not-hittable and failure screenshots confirm the app UI is correct, run the same flow manually on a physical device before concluding the app is broken. The XCUITest hittability model in the simulator does not always match human touch delivery, especially in multi-window configurations.

If the physical device flow succeeds and the simulator test continues to fail, the test failure is an environmental artifact and should be skipped with an explicit comment — not worked around by changing production app architecture.

---

### P4 — Add `--uitest-navigate-*` launch arguments for every new navigable destination

Programmatic navigation via launch arguments bypasses the XCUITest hittability artifact entirely (navigation fires in `onChange` without XCUITest needing to tap anything). Any screen that requires authenticated or multi-step navigation to reach should have a corresponding `--uitest-navigate-<destination>` launch argument in `SplashView` following the established pattern at lines 296–302.

The pattern costs one `if` branch in `SplashView` and makes the entire destination independently testable regardless of how XCUITest handles intermediate navigation steps.

---

### P5 — Enforce database primary key uniqueness before Phase 3

SwiftUI's `ForEach` uses `id` as the identity key for list diffing. Duplicate IDs produce undefined behavior — rows may be duplicated, updates may go to the wrong row, and navigation may open the wrong detail. The card-layout list introduced in Phase 3 is more sensitive to this than the current plain `List` because it renders more state per row.

Before Phase 3 ships:
- Confirm a `UNIQUE` constraint exists on the `id` column of the media table
- Deduplicate any existing records with `id = 1`
- Verify the auto-increment sequence is set correctly so new inserts cannot produce duplicates

---

## Follow-on Tasks

| Task | Priority | Blocking |
|---|---|---|
| Redact `Authorization` value from `MediaResourceLoader` log output | High | Phase 2 |
| Rotate dev.gighive.app development password exposed in logs | High | Immediate |
| ~~Investigate XCUITest multi-window hittability artifact; re-enable tests 13–14~~ | ~~Medium~~ | **Done** |
| Investigate and fix duplicate `MediaEntry` IDs on dev server | Medium | Phase 3 |
| ~~Add `--uitest-navigate-guest-list` launch argument to `SplashView`~~ | ~~Low~~ | **Done** |

---

## Phase 2 — Guest Test Defects (2026-08-27)

Three defects were encountered when running the seven guest XCUITests for the first time after Phase 2 completion. All three are now resolved.

---

### Phase 2 Defect 1 — Fresh simulator clone has no stored `GuestUploadRecord`

**Symptom:** All 7 guest tests failed after approximately 26 seconds. `navigateToGuestGallery` timed out waiting for a button with label `CONTAINS 'Event'` on the splash screen.

**Root cause:** The guest gallery row only appears in `SplashView` when `GuestUploadRecord.load()` returns at least one approved record from `UserDefaults`. `xcodebuild test` provisions a fresh simulator clone for each test run. That clone has empty `UserDefaults` — no record is stored, so the gallery row never renders.

The `GH_TEST_GUEST_NONCE` environment variable (configured in the Xcode scheme) only gates the `XCTSkip` decision at the start of each test. It is not used to create or seed a `GuestUploadRecord` on the device. Having the nonce in the scheme is a necessary but not sufficient condition for the tests to pass.

**Database verification performed:** MCP `execute_select` confirmed the nonce `fa_cRRhV…` exists in `anon_upload_attributions` with `moderation_status = approved`. The nonce is valid; the issue was entirely on the simulator side.

**Fix — `--uitest-inject-guest-nonce` launch argument:**

Added a new branch to `SplashView.onAppear` (in `GigHive/Sources/App/SplashView.swift`) that fires when both `--uitesting` and `--uitest-inject-guest-nonce` are present:

```swift
if isUITesting &&
   ProcessInfo.processInfo.arguments.contains("--uitest-inject-guest-nonce") {
    let env = ProcessInfo.processInfo.environment
    if let nonce = env["GH_TEST_GUEST_NONCE"], !nonce.isEmpty,
       let host  = env["GH_TEST_HOST"],  !host.isEmpty {
        let jobId = Int(env["GH_TEST_GUEST_JOB_ID"] ?? "") ?? 1
        let synthetic = GuestUploadRecord(
            statusNonce:        nonce,
            uploadJobId:        jobId,
            eventName:          "Test Event",
            submittedAt:        Date(),
            baseURLString:      "https://\(host)",
            approvalStatus:     "approved",
            lastSeenVideoCount: 1,
            viewedUploadJobIds: [],
            daysRemaining:      nil
        )
        GuestUploadRecord.upsert(synthetic)
    }
}
```

This runs synchronously before `GuestUploadRecord.load()` so the gallery row is available on the first SwiftUI render frame after launch.

---

### Phase 2 Defect 2 — `app.launchEnvironment` replaces the app's environment; scheme vars are not forwarded

**Symptom:** After adding `--uitest-inject-guest-nonce` and re-running, `testGuestFlagButtonVisible` passed (9.8 s) but the remaining 6 tests still timed out at 26 seconds.

**Root cause:** Each guest test calls:

```swift
app.terminate()
app.launchEnvironment["GH_TEST_HOST"] = host
app.launch()
```

`app.launchEnvironment` completely replaces the environment delivered to the app under test — it does not merge with or inherit from the scheme's test environment. `GH_TEST_GUEST_NONCE` was configured in the Xcode scheme's `TestAction` environment, which is visible to the XCUITest runner process via `ProcessInfo.processInfo.environment`, but is **not** automatically forwarded to the app process when the test relaunches via `app.launch()`.

The injection in `SplashView.onAppear` reads `GH_TEST_GUEST_NONCE` from `ProcessInfo.processInfo.environment` inside the app process. That variable was absent from the app's env, so the injection branch silently did nothing.

The reason only `testGuestFlagButtonVisible` passed is that it runs first (alphabetically), and at that point the simulator clone's `UserDefaults` were truly empty — so the injection correctly wrote the record. Later tests ran after `testGuestFlagButtonVisible` had already stored the record via `pollGuestRecords`/`updateAllGuestEventRecords`, which left state that happened to work for the first re-launch but then diverged.

**Fix:** Explicitly include `GH_TEST_GUEST_NONCE` in `app.launchEnvironment` in each guest test:

```swift
app.launchEnvironment["GH_TEST_HOST"]         = host
app.launchEnvironment["GH_TEST_GUEST_NONCE"] = nonce
```

The nonce value is obtained from the test runner's environment via `requireGuestNonce()`, which reads the scheme variable correctly (since the runner inherits the scheme env).

---

### Phase 2 Defect 3 — Inter-test state pollution via `UnifiedVideoListView`

**Symptom:** After both fixes above, `testGuestFlagButtonVisible` still passed but `testGuestGalleryListRenders` (and all remaining tests) timed out at ~28 seconds. Running `testGuestGalleryListRenders` alone (with `-only-testing:`) passed in 8.9 seconds on the first attempt.

**Root cause:** The failure was state-dependent on test ordering. `testGuestFlagButtonVisible` (alphabetically first) navigated into `UnifiedVideoListView` and the test ended with the app still inside that view. The next test's `setUpWithError` called `app.launch()`, which terminated the prior instance and relaunched. During this setUp launch, `UnifiedVideoListView`'s `loadGuestVideos` and `updateAllGuestEventRecords` tasks may have modified `UserDefaults` in a way that caused the subsequent injection-then-poll sequence to produce an inconsistent `uploadRecords` state — or the timing of the `pollGuestRecords` async task interacted with the setUp/terminate cycle in a way that left the gallery row absent from the accessibility tree.

Regardless of the exact mechanism, the fix is to eliminate the dependency on `navigateToGuestGallery` (which required tapping a splash button) entirely.

**Fix — `--uitest-navigate-guest-list` launch argument:**

Added a second launch argument handler inside the injection block in `SplashView.onAppear`. When `--uitest-navigate-guest-list` is also present, `SplashView` auto-navigates to `UnifiedVideoListView` via the existing `goToBannerGallery` mechanism — the same pattern as `--uitest-navigate-database`:

```swift
if ProcessInfo.processInfo.arguments.contains("--uitest-navigate-guest-list") {
    DispatchQueue.main.asyncAfter(deadline: .now() + 0.6) {
        bannerRecord      = synthetic
        goToBannerGallery = true
    }
}
```

All 7 guest tests now launch with `["--uitesting", "--uitest-inject-guest-nonce", "--uitest-navigate-guest-list"]`. The app injects the record and auto-pushes to `UnifiedVideoListView` without requiring XCUITest to tap any splash button. Tests wait for `unified_list_video_cell` to confirm the list has loaded.

**Key difference from the authenticated path:** `--uitest-navigate-database` navigates programmatically on `onChange(of: session.credential)`. `--uitest-navigate-guest-list` navigates on the `onAppear` dispatch, inside the injection block, after the record is written to `UserDefaults`.

---

### Phase 2 Defect 3 — `navigateToGuestGallery` removed from call sites; `launchGuestList()` helper extracted

All 7 guest tests previously repeated a 10-line boilerplate block:

```swift
let (nonce, host) = try requireGuestNonce()
_ = nonce

app.terminate()
app.launchEnvironment["GH_TEST_HOST"]         = host
app.launchEnvironment["GH_TEST_GUEST_NONCE"] = nonce
app.launchArguments = ["--uitesting", "--uitest-inject-guest-nonce", "--uitest-navigate-guest-list"]
app.launch()

// App auto-navigates to UnifiedVideoListView via --uitest-navigate-guest-list
```

This was extracted into a `launchGuestList()` helper in `GigHiveUITests.swift`:

```swift
@discardableResult
private func launchGuestList(file: StaticString = #file, line: UInt = #line)
    throws -> (nonce: String, host: String)
{
    let (nonce, host) = try requireGuestNonce(file: file, line: line)
    app.terminate()
    app.launchEnvironment["GH_TEST_HOST"]         = host
    app.launchEnvironment["GH_TEST_GUEST_NONCE"] = nonce
    app.launchArguments = ["--uitesting", "--uitest-inject-guest-nonce", "--uitest-navigate-guest-list"]
    app.launch()
    return (nonce, host)
}
```

Each guest test now opens with `try launchGuestList()`. The `@discardableResult` annotation allows future tests that need the nonce or host values to capture them without the discard idiom.

---

### Phase 2 Verification — All 7 guest tests pass

Final run result (serial, `-disable-concurrent-destination-testing`, iPhone 12 simulator BC84B10A):

```
Test case 'GigHiveUITests.testGuestFlagButtonVisible()'       passed (8.1 s)
Test case 'GigHiveUITests.testGuestGalleryListRenders()'      passed (7.5 s)
Test case 'GigHiveUITests.testGuestNewBadgeClearsAfterPlay()' passed (11.1 s)
Test case 'GigHiveUITests.testGuestNewBadgeVisible()'         passed (7.5 s)
Test case 'GigHiveUITests.testGuestPlayerCloseButtonDismisses()' passed (13.9 s)
Test case 'GigHiveUITests.testGuestPlayerOpensFromGallery()'  passed (8.2 s)
Test case 'GigHiveUITests.testGuestSearchBarAbsent()'         passed (8.5 s)
** TEST SUCCEEDED **
```

---

### Phase 2 Preventative Actions

#### P6 — Always forward required env vars explicitly in `app.launchEnvironment`

When a test calls `app.terminate()` + `app.launch()`, `app.launchEnvironment` completely replaces the app's environment. Variables configured in the Xcode scheme's `TestAction` are available to the XCUITest runner (`ProcessInfo.processInfo.environment` in the test code) but are **not** automatically passed to the relaunched app. Any env var that the app under test needs to read must be set explicitly in `app.launchEnvironment` before `app.launch()`.

#### P7 — Use `--uitest-navigate-*` for every view that XCUITest must enter reliably

Tapping splash-screen buttons introduces test-order dependencies: the first test navigates in and leaves state; subsequent tests may find a different rendered state on relaunch. Programmatic navigation via `--uitest-navigate-*` launch arguments avoids this by having the app push to the target view itself, independent of how the view happens to render after a prior test.

The established pattern (add one `if`-branch in `SplashView.onAppear`, dispatch with 0.6 s delay) adds minimal production code and makes each destination independently reachable in a single test launch without inter-test coordination.

#### P8 — Extract per-destination launch helpers in the test file

When multiple tests target the same view, extract a helper (e.g., `launchGuestList()`, `autoLogin()`) that encodes the canonical launch sequence for that destination. This makes `requires` (env var gates) and `launchArguments` configuration a single point of truth and prevents the set from drifting between tests.

---

## Phase 3 — Test Defects After Authenticated List Migration (2026-08-27)

Two defects surfaced when the full suite was run after Phase 3 (`DatabaseView`, `DatabaseDetailView`, `MediaPlayerView` deleted; `SplashView` wired to `UnifiedVideoListView` for the authenticated path).

---

### Phase 3 Defect 1 — `navigateToPlayer()` still navigated through the deleted `DatabaseDetailView`

**Symptom:** `testAuthPlayerOpensAndShowsOverlay` and `testAuthPlayerCloseButtonDismisses` both timed out after ~33 seconds.

**Root cause:** The `navigateToPlayer()` helper in `GigHiveUITests.swift` was written for the Phase 1 architecture:

1. Tap `splash_view_database_button` → `DatabaseView`
2. Wait for and tap a row → `DatabaseDetailView`
3. Wait for `app.staticTexts["Date"]` (first detail cell label, 8 s timeout)
4. `app.swipeUp()`
5. Wait for `detail_play_button` (15 s timeout), then tap it → `UnifiedVideoPlayerView`

After Phase 3, steps 2–5 were wrong:
- `DatabaseView` no longer exists; the button navigates to `UnifiedVideoListView`
- `DatabaseDetailView` no longer exists; tapping a video card opens `UnifiedVideoPlayerView` directly
- `detail_play_button` no longer exists

The test was spending 8 + 15 = 23 extra seconds waiting for elements that would never appear, then failing on the `detail_play_button` assertion.

**Evidence:** Both tests ran for ~33 s (8 s wait for "Date" + 15 s wait for `detail_play_button` + setup overhead). Before Phase 3 they ran in 15–20 s.

**Fix — rewrite `navigateToPlayer()` for the Phase 3 architecture:**

```swift
private func navigateToPlayer() throws {
    let dbButton = app.buttons["splash_view_database_button"]
    XCTAssert(dbButton.waitForExistence(timeout: 10), "splash_view_database_button not found")
    dbButton.tap()

    // Phase 3: UnifiedVideoListView opens directly. Tap a video card → UnifiedVideoPlayerView.
    let cell = app.buttons.matching(identifier: "unified_list_video_cell").firstMatch
    XCTAssert(cell.waitForExistence(timeout: 40), "unified_list_video_cell not found")
    cell.tap()
    // Player is now open on return.
}
```

The `DatabaseDetailView` intermediate screen no longer exists, so the `"Date"` wait, `swipeUp()`, and `detail_play_button` tap are removed entirely. The function now matches the actual two-screen flow: SplashView → UnifiedVideoListView → UnifiedVideoPlayerView.

**Lesson:** Test helper navigation code must be updated whenever a view is removed from the navigation stack. The old helper compiled fine (identifiers are strings, not symbols), so no compiler error surfaced the regression.

---

### Phase 3 Defect 2 — Guest `UserDefaults` records left by injection tests caused `isGuestOnly = true` in subsequent non-injection tests

**Symptom:** With `-parallel-testing-enabled NO`, all five login-related tests run after `testLaunchPerformance` failed immediately:

- `testSplashStartsLoggedOut` — failed at 7.8 s; "Login button should be visible on a fresh launch"
- `testLoginScreenOpens` — failed at 4.9 s; `splash_login_button` not found
- `testLoginSetsCredentialAndShowsSplashBanner` — failed at 4.9 s; `splash_login_button` not found
- `testLoginFailureShowsError` — failed at 4.9 s; `splash_login_button` not found
- `testUploadDuplicateFileShowsError` — failed at 15.4 s; `splash_login_button` not found

**Root cause:** `SplashView` has a computed property:

```swift
private var isGuestOnly: Bool {
    session.credential == nil && !uploadRecords.isEmpty
}
```

When `isGuestOnly` is true, the entire `if !isGuestOnly { ... }` block — which contains `splash_login_button`, `splash_logged_in_banner`, and every authenticated button — is hidden.

The guest XCUITests (tests 6–12 alphabetically) use `--uitest-inject-guest-nonce`, which calls `GuestUploadRecord.upsert(synthetic)`. This writes a `GuestUploadRecord` to `UserDefaults` in the simulator. When `-parallel-testing-enabled NO` keeps all tests on the same simulator clone, that record persists into the next test's `setUpWithError` launch.

Tests 14–18 launch with only `["--uitesting"]` (no injection). `SplashView.onAppear` calls `GuestUploadRecord.load()` which finds the leftover record. `uploadRecords` is non-empty → `isGuestOnly = true` → the login button block is hidden → all login-related assertions fail immediately.

**Why the parallel run (default) also failed:** The default run assigned all `GigHiveUITests` to one clone. The auth player tests ran in parallel with the login tests; `autoLogin()` called `app.terminate()` + `app.launch()` which terminated the app under the concurrently running login tests, causing their fast failures for a different but compounding reason.

**Why `testCancelLoginKeepsLoggedOutState` (test 3 alphabetically) passed:** It runs BEFORE the guest tests (alphabetical order). At that point `UserDefaults` still has no leftover guest record from injection, so `uploadRecords` is empty and `isGuestOnly = false`.

**Fix — clear `GuestUploadRecord` at the start of every `--uitesting` launch:**

```swift
// SplashView.onAppear
let isUITesting = ProcessInfo.processInfo.arguments.contains("--uitesting")
// Clear any guest records left from prior tests; injection tests repopulate immediately.
if isUITesting { GuestUploadRecord.save([]) }
```

Adding this before the injection block means:
- Every non-injection test starts with `uploadRecords = []` → `isGuestOnly = false` → login button visible ✓
- Injection tests clear first, then write one fresh record → same net result as before ✓

The `--uitest-inject-guest-nonce` block still runs and calls `GuestUploadRecord.upsert(synthetic)` after the clear, so injection tests are unaffected.

---

### Phase 3 Verification

After both fixes, full suite with `-parallel-testing-enabled NO` (single simulator clone, same runner process, sequential execution):

```
testAuthPlayerCloseButtonDismisses      passed (17.4 s)
testAuthPlayerOpensAndShowsOverlay      passed (11.0 s)
testCancelLoginKeepsLoggedOutState      passed (6.2 s)
testDatabaseButtonVisibleAfterLogin     passed (14.9 s)
testDatabaseLoadsEntriesAfterLogin      passed (19.5 s)
testGuestFlagButtonVisible              passed (7.5 s)
testGuestGalleryListRenders             passed (6.5 s)
testGuestNewBadgeClearsAfterPlay        passed (10.9 s)
testGuestNewBadgeVisible                passed (7.5 s)
testGuestPlayerCloseButtonDismisses     passed (13.9 s)
testGuestPlayerOpensFromGallery         passed (8.1 s)
testGuestSearchBarAbsent                passed (8.5 s)
testLaunchPerformance                   passed (18.4 s)
testLoginFailureShowsError              passed (24.0 s)
testLoginScreenOpens                    passed (4.6 s)
testLoginSetsCredentialAndShowsSplashBanner  passed (14.8 s)
testSplashStartsLoggedOut               passed (3.9 s)
testUploadDuplicateFileShowsError       passed (59.7 s)
** TEST SUCCEEDED **
```

---

### Phase 3 Preventative Actions

#### P9 — Clear all UI-test-injected state at the start of every `--uitesting` launch

Any data written to `UserDefaults` or the keychain by a test (via launch arguments or side effects) persists on the simulator clone for the remainder of the test session. If a later test does not explicitly inject that data, it may find leftover state and behave incorrectly.

The fix pattern: add a cleanup block at the top of `SplashView.onAppear` (or `GigHiveApp` init) that runs when `--uitesting` is present and resets all injectable state to its factory defaults. Injection tests then write their required state immediately after the reset, producing the correct net state for that test.

Apply this pattern to any new state category injected via `--uitest-*` flags: if the injection is optional (test A injects it, test B does not), the reset must run unconditionally on every `--uitesting` launch.

#### P10 — Update test navigation helpers whenever a view is removed from the navigation stack

Test helper methods that navigate by identifier (`"Date"`, `"detail_play_button"`, etc.) will compile without error even after the view they target is deleted. The regression is only detected at runtime, typically as a long timeout failure.

When deleting a view from the navigation stack, immediately search the test file for:
- Accessibility identifiers set in that view
- Static text labels rendered by that view
- Navigation calls that pushed through that view (e.g., tapping a row that previously opened a detail screen)

Update every affected helper before the delete is committed.

---

### Phase 4 Preventative Actions

#### P11 — Verify server routing before writing a 403-path test

`delete_media_files.php` branches on the HTTP auth username before reaching the token-hash check:

- `admin` user → expects `asset_ids[]` (array) → app sends `asset_id` (scalar) → **400 Bad Request** — the 403 path is never reached; Keychain cleanup does not fire.
- `uploader` user → expects `asset_id` (scalar) + `delete_token` → validates hash → invalid token → **403 Forbidden** — Keychain cleanup fires correctly.

A test for the 403 path written while `GH_TEST_USER = admin` will see an error alert (from the 400) but Keychain cleanup will not run, causing the relaunch assertion ("delete button absent") to fail. The test appears to verify something it does not.

**Fix:** Before writing any test that depends on a specific HTTP error code from the server, inspect the server endpoint to confirm which auth user reaches that code path. Use separate credential env vars (`GH_TEST_UPLOADER_USER` / `GH_TEST_UPLOADER_PASS`) rather than re-using `GH_TEST_USER` / `GH_TEST_PASS` so tests for different roles can coexist without requiring scheme reconfiguration between runs.

#### P12 — Use separate credential variables per role; never re-use a shared password variable across roles

When a test suite covers multiple server roles (admin, uploader), sharing one `GH_TEST_PASS` variable requires editing the scheme to switch roles between test runs. This invites accidental cross-contamination: a test meant for the uploader silently runs as admin (or vice versa) if the operator forgets to update the variable.

**Pattern used in Phase 4:**

| Variable | Role | Used by |
|---|---|---|
| `GH_TEST_USER` / `GH_TEST_PASS` | admin | all general authenticated tests |
| `GH_TEST_UPLOADER_USER` / `GH_TEST_UPLOADER_PASS` | uploader | `testAuthDelete403ClearsToken` only |

`requireCredentials()` reads admin vars; `requireUploaderCredentials()` reads uploader vars. Both throw `XCTSkip` (not `XCTFail`) when their vars are absent, so the suite passes in CI configurations that only supply admin credentials.

#### P13 — Admin delete requires a different payload format than uploader delete

**Symptom:** Tapping the ✕ delete button as admin produces:
```
[UnifiedList] deleteAuthenticated error: serverMessage("{\"success\":false,\"error\":\"Bad Request\",\"message\":\"Expected JSON body with asset_ids array\"}")
```

**Root cause:** `delete_media_files.php` branches on the authenticated HTTP user before any other logic:

- `admin` path — expects `{"asset_ids": [1, 2, 3]}` (array); no delete token; skips token validation entirely; trusts the admin credential.
- `uploader` path — expects `{"asset_id": 1, "delete_token": "..."}` (scalar + token); validates the SHA-256 token hash against the stored value.

The iOS app was originally written only for the uploader path. `DatabaseAPIClient.deleteMediaFile(fileId:deleteToken:)` always sends the scalar `asset_id` payload. When the admin credential is used, the server hits the `asset_ids` array check immediately and returns 400 — it never reaches the delete logic.

**Fix:** Added `DatabaseAPIClient.deleteMediaFileAsAdmin(fileId:)` that sends `{"asset_ids": [fileId]}` with no delete token. `UnifiedVideoListView.performDelete` branches on `credential?.displayUser == "admin"` to call the correct method. Both paths share the same success/error handling and Keychain cleanup logic downstream.

**Key principle:** Inspect the server endpoint's user-routing logic before implementing any client-side API call. When the same endpoint serves multiple roles with structurally different payloads, the client must branch explicitly — a single shared implementation will silently use the wrong format for one role.

#### P14 — Thumbnails for authenticated path silently failed because ThumbnailLoader sent no auth header

**Symptom:** Thumbnails appeared correctly in the guest gallery but were blank (showed `Color.clear` placeholder) in the authenticated media database list, even after `database.php` was updated to return `thumbnail_url`.

**Root cause:** All media — video, audio, and thumbnails — is served through `api/media-stream.php`, which requires authentication via one of three paths: `Authorization: Basic` header, `X-Upload-Token` header, or `?nonce=` query parameter. `ThumbnailLoader.load(from:)` used `URLSession.shared.dataTask(with: url)` — a plain request with no headers. `media-stream.php` returned 401 for every authenticated thumbnail request, and the loader silently discarded the failure, leaving the image `nil`.

The guest path worked because the server embeds `?nonce=...` directly in the `thumbnail_url` field of the gallery response. `media-stream.php` path 3 validates the nonce from the query string — no header required.

**Fix:**
- `ThumbnailLoader.load(from:credential:)` — creates a `URLRequest` and calls `credential?.apply(to: &request)` before dispatching. Default is `nil` so the guest path is unchanged.
- `AsyncThumbnail(url:credential:)` — accepts the optional credential and forwards it to the loader.
- `UnifiedVideoListView.videoCard` — passes `credential: playerCredential` to `AsyncThumbnail` for the authenticated path. `playerCredential` is the existing computed property that extracts `AuthCredential` from the `.authenticated` context case.

**Key principle:** When a media-serving endpoint requires auth, any component that fetches media (player, thumbnail loader, download helper) must independently carry and inject the auth credential. It is not sufficient to verify that the primary media stream authenticates correctly — secondary assets such as thumbnails go through the same auth gate via separate HTTP requests and will fail silently if the credential is omitted.

#### P15 — Delete (✕) button appeared in the authenticated Media Database for a guest-uploaded file, and tapping it returned 403

**Symptom:** A video ("Test clip") uploaded via the QR code guest path showed an ✕ delete button in the authenticated Media Database list view when logged in as "uploader". Tapping ✕ produced the error "You are not authorised to delete this video. Your delete token may have expired." Xcode log confirmed:
```
[UnifiedList] deleteAuthenticated 403 — removing stale token file_id=18
```

**Root cause:** Two separate mechanisms combine to produce this:

**(1) `GuestUploadView` writes into `UploaderDeleteTokenStore` (`GuestUploadView.swift` line 535):**
```swift
try? UploaderDeleteTokenStore.upsert(host: host, entry: entry)
```
Every guest/QR code upload that receives a `delete_token` in the finalize response stores it in `UploaderDeleteTokenStore` under `fileId: resp.id` — where `resp.id` decodes from the server's `"id"` JSON key, which `UploadService.php` confirms is `$assetId` (the `assets` table primary key, the same ID used by the authenticated list).

**(2) The authenticated Media Database uses that same store to show the ✕ button (`UnifiedVideoListView.swift` `loadAuthenticatedVideos`):**
```swift
let storedTokens = (try? UploaderDeleteTokenStore.load(host: host)) ?? []
let tokenMap = Dictionary(storedTokens.map { ($0.fileId, $0.deleteToken) }, ...)
authDeleteTokens = tokenMap
// ...
isOwnUpload: tokenMap[entry.id] != nil,  // ✕ shows when this is true
```
`showDeleteButton(for:)` for `.uploaderAndAdmin` returns `authDeleteTokens[video.id] != nil`. Because the guest upload wrote a token for `asset_id=18`, `authDeleteTokens[18]` is non-nil, and the ✕ appears.

**(3) The delete attempt fails 403 because `delete_media_files.php` validates `hash('sha256', token)` against `assets.delete_token_hash`:**
```php
$storedHash = $assetsRepo->getDeleteTokenHashById($assetIdToDelete);
$providedHash = hash('sha256', (string)$deleteToken);
if (!is_string($storedHash) || $storedHash === '' || !hash_equals($storedHash, $providedHash)) {
    http_response_code(403);
```
`setDeleteTokenHashIfNull` (in `AssetRepository.php`) only writes the hash once (`WHERE delete_token_hash IS NULL`). If the file was previously uploaded via the authenticated iPhone uploader (setting the hash), a subsequent guest upload of the same content causes `setDeleteTokenHashIfNull` to return false — `$deleteToken` is nulled and not returned — but the old Keychain token is now stale. The server's stored hash no longer matches, producing 403.

**Fix:** Remove `UploaderDeleteTokenStore.upsert` from `GuestUploadView` (line 535). The guest delete flow uses `GuestUploadRecord.statusNonce` — it never reads from `UploaderDeleteTokenStore`. Only the authenticated iPhone uploader path (`UploadView.swift` line 1104) should write to `UploaderDeleteTokenStore`, because only those tokens are validated against `assets.delete_token_hash` by the authenticated delete endpoint. After this change, `tokenMap` in `loadAuthenticatedVideos` will only contain tokens from authenticated uploads, and the ✕ button will only appear for files uploaded via the authenticated iPhone upload path — which is the correct behavior.

Note: `SplashView.swift` also calls `UploaderDeleteTokenStore.upsert` but is behind an `isUITesting` guard and is only used to inject a deliberately invalid token for the `testAuthDelete403ClearsToken` UI test. It is unaffected by this fix.

**Key principle:** Do not share a Keychain store between two unrelated auth systems. `UploaderDeleteTokenStore` serves the authenticated delete API (token validated against `assets.delete_token_hash`). Guest uploads use a separate nonce-based delete flow (`GuestUploadRecord.statusNonce` → `GuestGalleryAPIClient.deleteVideo`). Writing guest tokens into the authenticated token store causes the authenticated UI to present affordances it cannot fulfill.
