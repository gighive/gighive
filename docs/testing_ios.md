# iOS UI Testing — GigHive XCUITest Harness

## What is tested

**Phase 0 — Auth / Database (original)**

1. I will launch the app without credentials to verify that the splash screen starts in a logged-out state.
2. I will tap the Login button to verify that the login form opens correctly.
3. I will enter valid credentials and sign in to verify that the session is established and the username banner appears on the splash screen.
4. I will sign in with valid credentials to verify that the "View the Database" button becomes visible after login.
5. I will sign in and navigate to the database to verify that the `Authorization: Basic` header reaches the server and entries load.
6. I will open the login form and tap Cancel to verify that the app returns to a logged-out splash state.
7. I will enter a wrong password and attempt to sign in to verify that an error is shown and no session is established.
8. I will measure app cold-launch time to establish a performance baseline.
9. I will sign in, open the database, and tap the entry for "Flesh Machine" by StormPigs to verify that the video detail screen opens and playback can be initiated for a known file (`3ed8bbc4….mp4`).
10. I will sign in, navigate to the Upload page, and attempt to upload `3ed8bbc4….mp4` (located at `gighiveinfra/assets/video/`) to verify the server response for a duplicate file. *(Automated via `testUploadDuplicateFileShowsError` — the file is injected via `--uitest-upload-file` launch arg rather than through the system picker. **Observed result:** server returns HTTP 200 `success` but omits the delete token, indicating silent sha256-based deduplication. The app correctly displays a "no delete token" warning with instructions to contact support.)*

**Phase 1 — Unified Player (`refactor_video_player_page.md`)**

11. I will tap a guest gallery video card to verify that `UnifiedVideoPlayerView` opens and the Close button is visible.
12. I will tap Close on the guest player to verify it dismisses cleanly and returns to the list with no stuck screens.
13. I will sign in, navigate to the unified list, and tap an entry to verify that the loading overlay appears for the authenticated player path.
14. I will tap Close on the authenticated player to verify it returns to the unified list.

**Phase 2 — Unified List, Guest Path (`refactor_video_player_page.md`)**

15. I will open a known approved guest gallery to verify that `UnifiedVideoListView` renders at least one video card.
16. I will open a fresh guest gallery to verify that the "New" badge is visible on unviewed cards.
17. I will tap a card and close the player to verify the "New" badge is gone for that row.
18. I will open a guest gallery to verify that the flag button is present on cells and no search bar is rendered.

**Phase 3 — Unified List, Authenticated Path (`refactor_video_player_page.md`)**

19. I will sign in and navigate to the unified list to verify the card layout renders and entries load.
20. I will sign in and verify a search bar is visible for the authenticated context.
21. I will sign in and verify no flag button appears on any cell in the authenticated context.
22. I will view a video, restart the app, and verify the "New" badge is absent for the viewed video and present for unviewed ones.
23. I will verify `--uitest-navigate-database` still triggers navigation to `UnifiedVideoListView` after Step 12 (regression guard).

**Phase 4 — Authenticated Delete (`refactor_video_player_page.md`)**

24. I will sign in and navigate to the unified list with no stored delete tokens to verify no delete (✕) button appears on any card.
25. I will inject a synthetic delete token for a known file ID (`GH_TEST_DELETE_FILE_ID`), sign in, and navigate to the unified list to verify the delete button appears on the matching card and is absent on all others.
26. I will tap the delete button for an injected card to verify the confirm dialog appears; I will then cancel, leaving server state unchanged.
27. I will inject a deliberately invalid token for a real file ID, confirm the delete dialog, and verify the server's 403 response produces an error alert; then relaunch without injection to verify the delete button is absent (confirming the stale token was removed from the Keychain by the 403 handler).
28. I will open a guest gallery and verify the guest delete (✕) button still appears for own uploads (regression guard against Phase 4 breaking the guest path).

> **Phase 4 tests 24–27 superseded by Phase 5 (Phase 3 of `refactor_video_player_page_delete_eligibility.md` has shipped).** Delete button visibility is now driven by the server's `can_delete` field, not Keychain token injection. Applied changes: tests 24 (`testAuthDeleteButtonAbsentWithoutToken`), 25 (`testAuthDeleteButtonVisibleForOwnUpload`), and 27 (`testAuthDelete403ClearsToken`) replaced with `XCTSkip` bodies explaining the supersession. Test 26 (`testAuthDeleteConfirmDialogAppears`) updated to remove token injection — admin credential receives `can_delete: true` for all entries from the server and sees delete buttons without injection.

**Phase 5 — Server-Authoritative Delete Eligibility (`refactor_video_player_page_delete_eligibility.md`)**

29. I will sign in as `uploader` and navigate to the unified list to verify no ✕ button appears on a cell that the server identifies as a guest-uploaded asset (`GH_TEST_GUEST_UPLOAD_FILE_ID`, where `can_delete: false`). This confirms the iOS app no longer uses Keychain token presence to show ✕.
30. I will sign in as `admin` and navigate to the unified list to verify at least one ✕ button is visible — the server returns `can_delete: true` for admin on all entries.
31. I will decode a synthetic `MediaEntry` JSON payload that omits the `can_delete` field and verify the decoded `canDelete` value is `false` with no crash or decode error (backward compatibility with pre-Phase-2 server). *(Unit test — no server required.)*
32. I will call the guest upload finalize path in isolation and assert that `UploaderDeleteTokenStore.load(host:)` returns no entry for the uploaded asset ID — confirming guest tokens are never written to the authenticated token store. *(Unit test — no server required.)*

## Purpose

Repeatable smoke tests that exercise the iOS app end-to-end on an iPhone 12 simulator.
The initial test suite covers **Phase 0** of the JWT authentication migration: verifying that the
`AuthCredential` abstraction works correctly in the full UI flow (login → session banner →
database list load).

These tests are not a replacement for unit tests.  They exist to catch regressions that only
show up when the full stack is running: SwiftUI state propagation, Keychain reads, and real
HTTP calls to a GigHive server.

---

## Tool

**XCTest / XCUITest** — Apple's native iOS UI test framework, built into Xcode.
No third-party dependencies.  No Playwright.  Playwright drives browsers; it cannot drive a
native iOS app.

Test files live at:

```
GigHive/GigHiveUITests/
  GigHiveUITests.swift          ← Phase 0 smoke tests (main file)
  GigHiveUITestsLaunchTests.swift  ← launch screenshot (boilerplate, leave as-is)
```

---

## Environment Setup

### Xcode version

Tested with **Xcode 26.2** (Build 17C52).  The test target deployment target is set to
**iOS 14.0** to match the app's minimum.

### Simulator

The test suite targets the **iPhone 12 simulator running iOS 17.5**:

```
UDID: BC84B10A-07EF-4DAD-A91A-F48B991C5C16
```

Confirm available simulators:

```bash
xcrun simctl list devices available | grep 'iPhone 12'
```

### Test target deployment target

The `GigHiveUITests` target must have `IPHONEOS_DEPLOYMENT_TARGET = 14.0` in both Debug and
Release configurations in `project.pbxproj`.  When Xcode creates a UI test target it defaults
to the current SDK version (e.g. 26.2), which causes:

```
error: iPhone 12's iOS Simulator 17.5 doesn't match GigHiveUITests's iOS Simulator 26.2 deployment target
```

This was fixed by editing the two `GigHiveUITests` build configurations in `project.pbxproj`
(UUIDs `D828984B` and `D828984C`) and setting `IPHONEOS_DEPLOYMENT_TARGET = 14.0` in each.

---

## Credentials

The tests that hit a real server require environment variables:

| Variable | Example | Description |
|---|---|---|
| `GH_TEST_HOST` | `devvm.gighive.internal` | Hostname only — no `https://`, no trailing slash |
| `GH_TEST_USER` | `admin` | GigHive username |
| `GH_TEST_PASS` | `Sn012Trust@!` | GigHive password |
| `GH_TEST_INSECURE` | `1` | Set to `1` if the server uses a self-signed certificate (enables Disable Certificate Checking toggle). Required for dev VM. |
| `GH_TEST_GUEST_NONCE` | `abc123…` | An approved guest gallery nonce on the dev server. Required for Phase 2 guest list tests. Set alongside `GH_TEST_HOST`. |
| `GH_TEST_DELETE_FILE_ID` | `2` | `asset_id` of a video that exists in the dev server's media list and has a non-empty `checksum_sha256` so the card URL is valid. `asset_id = 2` (StormPigs, 19 min, 2002-10-24) is the confirmed stable value: single-event (no duplicate-ID issue), `delete_token_hash IS NULL` (any token → 403 guaranteed from the `uploader` path), foundational test data that will not be deleted. |
| `GH_TEST_UPLOADER_USER` | `uploader` | HTTP username for the uploader account. ~~Required only for `testAuthDelete403ClearsToken`~~ — that test is now `XCTSkip` (superseded by Phase 5; uploader `showDeleteButton` always returns `false`, making the 403 path unreachable from the list UI). Now required for Phase 5 test 29 (`testDelEligUploaderNoDeleteButtonForGuestUpload`). |
| `GH_TEST_UPLOADER_PASS` | *(set in scheme)* | Password for the uploader account. Kept separate from `GH_TEST_PASS` so all other tests continue to run as admin. Set via `requireUploaderCredentials()` which throws `XCTSkip` (not `XCTFail`) when absent. |
| `GH_TEST_GUEST_UPLOAD_FILE_ID` | *(set in scheme)* | `asset_id` of a file uploaded via QR code (guest path) — must have a corresponding `upload_jobs` row. The dev server returns `can_delete: false` for `uploader` on this file. Required only for Phase 5 test 29 (`testDelEligUploaderNoDeleteButtonForGuestUpload`). Confirm the `upload_jobs` row exists before setting. |

Tests that need credentials call `requireCredentials()`.  If the variables are not set, those
tests emit `XCTSkip` and the run still passes.  The two navigation-only tests
(`testSplashStartsLoggedOut`, `testLoginScreenOpens`) always run without credentials.

**Never commit real credentials.**  Set them in the Xcode scheme — they are stored in
`xcscheme` files which are typically gitignored or kept out of the repo.

---

## How to Set Credentials

### Option A — Xcode scheme UI (recommended for local development)

1. `Product → Scheme → Edit Scheme` (or `Cmd+Shift+,`)
2. Select **Test** in the left sidebar
3. Click the **Arguments** tab
4. Under **Environment Variables** click `+` and add:
   - `GH_TEST_HOST` = `devvm.gighive.internal`
   - `GH_TEST_USER` = `admin`
   - `GH_TEST_PASS` = `<password>`
   - `GH_TEST_INSECURE` = `1`  *(if server uses self-signed cert — required for dev VM)*
5. Close the scheme editor

Run with `Cmd+U` in Xcode, or from the command line:

```bash
xcodebuild test \
  -scheme GigHive \
  -destination 'platform=iOS Simulator,id=BC84B10A-07EF-4DAD-A91A-F48B991C5C16'
```

### Option B — xcodebuild command line (Xcode 26+ only, once `-testenv` is supported)

Xcode 26.2 does **not** yet support `-testenv`.  The flag exists in documentation but returns
`invalid option` at runtime.  Passing `KEY=VALUE` pairs after the destination is interpreted as
**build settings**, not test runner environment variables — the test process never sees them.

When `-testenv` is available in a future Xcode release, the command will be:

```bash
xcodebuild test \
  -scheme GigHive \
  -destination 'platform=iOS Simulator,id=BC84B10A-07EF-4DAD-A91A-F48B991C5C16' \
  -testenv GH_TEST_HOST=devvm.gighive.internal \
  -testenv GH_TEST_USER=admin \
  -testenv "GH_TEST_PASS=Sn012Trust@!"
```

Until then, use Option A.

---

## Running the Tests

### From Xcode

`Cmd+U` — builds and runs all tests in the scheme against the active simulator.

### From the command line

```bash
cd /Users/sodo/gighiveapp/GigHive

xcodebuild test \
  -scheme GigHive \
  -destination 'platform=iOS Simulator,id=BC84B10A-07EF-4DAD-A91A-F48B991C5C16'
```

Build and test separately (faster iteration after the first build):

```bash
# Build only
xcodebuild build-for-testing \
  -scheme GigHive \
  -destination 'platform=iOS Simulator,id=BC84B10A-07EF-4DAD-A91A-F48B991C5C16'

# Run only (no recompile)
xcodebuild test-without-building \
  -scheme GigHive \
  -destination 'platform=iOS Simulator,id=BC84B10A-07EF-4DAD-A91A-F48B991C5C16'
```

### Filter to a single test

```bash
xcodebuild test \
  -scheme GigHive \
  -destination 'platform=iOS Simulator,id=BC84B10A-07EF-4DAD-A91A-F48B991C5C16' \
  -only-testing:GigHiveUITests/GigHiveUITests/testLoginSetsCredentialAndShowsSplashBanner
```

---

## Test Inventory — Phase 0

| Test | Needs credentials | What it verifies |
|---|---|---|
| `testSplashStartsLoggedOut` | No | App starts logged-out; splash banner absent; Login button visible |
| `testLoginScreenOpens` | No | Login button navigates to the login form |
| `testLoginSetsCredentialAndShowsSplashBanner` | Yes | `session.credential.displayUser` surfaces correctly in the splash banner after login |
| `testDatabaseButtonVisibleAfterLogin` | Yes | `session.credential != nil` branch in `SplashView` shows the View Database button |
| `testDatabaseLoadsEntriesAfterLogin` | Yes | `Authorization: Basic` header reaches the server; database list contains at least one entry. **Updated in Phase 3** to assert `unified_list_video_cell` instead of `app.cells.firstMatch`. |
| `testCancelLoginKeepsLoggedOutState` | No | Cancel on the login screen returns to a still-logged-out splash |
| `testLoginFailureShowsError` | Yes (host + user only) | Wrong password does not set `session.credential`; login screen stays visible |
| `testLaunchPerformance` | No | Measures app cold-launch time (baseline) |

## Test Inventory — Phase 1 (Unified Player)

Add as `// MARK: - Phase 1 — Unified Player` in `GigHiveUITests.swift`.

| Test | Needs credentials | What it verifies |
|---|---|---|
| `testGuestPlayerOpensFromGallery` | `GH_TEST_GUEST_NONCE` | Tapping a `unified_list_video_cell` opens `UnifiedVideoPlayerView`; `unified_player_close_button` visible |
| `testGuestPlayerCloseButtonDismisses` | `GH_TEST_GUEST_NONCE` | Tapping `unified_player_close_button` returns to the list; no stuck screen |
| `testAuthPlayerOpensAndShowsOverlay` | Yes | After login + `--uitest-navigate-unified-list`, tapping a cell shows `unified_player_overlay` |
| `testAuthPlayerCloseButtonDismisses` | Yes | `unified_player_close_button` dismisses authenticated player back to the unified list |

> Full playback cannot be asserted in XCUITest because `AVPlayerViewController` rendering is opaque to the accessibility tree. These tests assert navigation and overlay state only. KVO log-tag verification is done manually per the Step 6 checklist in `refactor_video_player_page.md`.

## Test Inventory — Phase 2 (Unified List — Guest Path)

Add as `// MARK: - Phase 2 — Unified List (Guest Path)` in `GigHiveUITests.swift`.

| Test | Needs credentials | What it verifies |
|---|---|---|
| `testGuestGalleryListRenders` | `GH_TEST_GUEST_NONCE` | `UnifiedVideoListView` renders ≥ 1 `unified_list_video_cell` for an approved guest record |
| `testGuestNewBadgeVisible` | `GH_TEST_GUEST_NONCE` | `unified_list_new_badge` visible on first launch for a record with unviewed videos |
| `testGuestNewBadgeClearsAfterPlay` | `GH_TEST_GUEST_NONCE` | After tapping a cell and closing the player, `unified_list_new_badge` is gone for that row |
| `testGuestFlagButtonVisible` | `GH_TEST_GUEST_NONCE` | `unified_list_flag_button` exists on cells |
| `testGuestSearchBarAbsent` | `GH_TEST_GUEST_NONCE` | `unified_list_search_field` does not exist in guest context (`canSearch = false`) |

## Test Inventory — Phase 3 (Unified List — Authenticated Path)

Add as `// MARK: - Phase 3 — Unified List (Authenticated Path)` in `GigHiveUITests.swift`.

| Test | Needs credentials | What it verifies |
|---|---|---|
| `testUnifiedListLoadsEntriesAfterLogin` | Yes | `--uitest-navigate-unified-list` triggers navigation; ≥ 1 `unified_list_video_cell` appears |
| `testUnifiedListSearchBarVisible` | Yes | `unified_list_search_field` exists for the authenticated context |
| `testUnifiedListFlagButtonAbsent` | Yes | `unified_list_flag_button` does not exist on any cell in authenticated context |
| `testUnifiedListNewBadgePersistsAcrossLaunch` | Yes | View a video, terminate and relaunch — `unified_list_new_badge` absent for viewed video, present for others |
| `testUItestNavigateDatabaseStillWorks` | Yes | `--uitest-navigate-database` still navigates to `UnifiedVideoListView` after Step 12 (regression guard) |

## Test Inventory — Phase 4 (Authenticated Delete)

Add as `// MARK: - Phase 4 — Authenticated Delete` in `GigHiveUITests.swift`.

| Test | Needs credentials | What it verifies |
|---|---|---|
| `testAuthDeleteButtonAbsentWithoutToken` | Yes | ~~**SUPERSEDED by Phase 5.**~~ Now `XCTSkip` with explanation. Admin always gets `can_delete: true` and sees delete buttons; the "absent" assertion is invalid post–Phase 3. Replaced by `testDelEligAdminSeesDeleteButton` (admin) and `testDelEligUploaderNoDeleteButtonForGuestUpload` (uploader). |
| `testAuthDeleteButtonVisibleForOwnUpload` | Yes + `GH_TEST_DELETE_FILE_ID` | ~~**SUPERSEDED by Phase 5.**~~ Now `XCTSkip` with explanation. Token injection no longer drives ✕ visibility. Replaced by `testDelEligAdminSeesDeleteButton`. |
| `testAuthDeleteConfirmDialogAppears` | Yes | **Updated for Phase 5.** Token injection removed (`injectToken: false`). Admin caller receives `can_delete: true` for all entries; at least one delete button is visible without injection. Tap to verify confirm dialog appears, then cancel. |
| `testAuthDelete403ClearsToken` | Yes + `GH_TEST_UPLOADER_USER` + `GH_TEST_UPLOADER_PASS` | ~~**SUPERSEDED by Phase 5.**~~ Now `XCTSkip` with explanation. Uploader `showDeleteButton` always returns `false` post–Phase 3; no ✕ button is reachable, so the 403 path cannot be triggered from the list UI. Replaced by `testDelEligUploaderNoDeleteButtonForGuestUpload`. |
| `testGuestDeleteButtonStillVisible` | `GH_TEST_GUEST_NONCE` + `GH_TEST_GUEST_JOB_ID` | `unified_list_delete_button` still appears for own uploads in the guest context (regression guard; requires `GH_TEST_GUEST_JOB_ID` to match a real video's `uploadJobId` in the gallery). **P6 gap:** the existing `launchGuestList()` helper only forwards `GH_TEST_HOST` and `GH_TEST_GUEST_NONCE`; it must be updated to also forward `GH_TEST_GUEST_JOB_ID` in `app.launchEnvironment`, otherwise the injection defaults to `uploadJobId = 1` and the delete button only appears if a video with that ID exists. |

**Manual verification only — not automatable with XCUITest:**

| Item | Why manual |
|---|---|
| Video disappears from list immediately on confirmed success | Requires a real, valid delete token for a disposable server-side video; destructive server write |
| `UploaderDeleteTokenStore` Keychain entry gone from UploadView after success | Same prerequisite — real token, real delete |
| Double-tap guard: second in-flight tap ignored | Timing-based; XCUITest cannot hold a network call in-flight while issuing a second tap at a deterministic moment |

## Test Inventory — Phase 5 (Server-Authoritative Delete Eligibility)

UI tests 29–30 are implemented in `GigHiveUITests.swift` under `// MARK: - Phase 5 — Server-Authoritative Delete Eligibility`. Unit tests 31–32 require a `GigHiveTests` unit-test Xcode target (XCTest, not XCUITest) with `@testable import GigHive`. That target does not yet exist in `project.yml` — add it via XcodeGen before implementing 31–32.

> **Prerequisite:** Phase 2 of `refactor_video_player_page_delete_eligibility.md` must be deployed to the dev server before UI tests 29–30 will produce meaningful results. Unit tests 31–32 have no server dependency.

| Test | File | Status | Needs credentials | What it verifies |
|---|---|---|---|---|
| `testDelEligUploaderNoDeleteButtonForGuestUpload` | `GigHiveUITests.swift` | ✅ Implemented | `GH_TEST_HOST` + `GH_TEST_UPLOADER_USER` + `GH_TEST_UPLOADER_PASS` | Signs in as `uploader`, navigates to unified list, waits for ≥ 1 `unified_list_video_cell`, then asserts `unified_list_delete_button` does not exist anywhere in the list. **Fixture precondition:** the test dev server must contain only guest-uploaded assets for this uploader account — no authenticated uploads — so the server returns `can_delete: false` for every entry. If the uploader has any authenticated uploads on the test server, those would receive `can_delete: true` and produce a ✕, breaking the assertion. See precondition note in `refactor_video_player_page_delete_eligibility.md` Phase 3 tests. |
| `testDelEligAdminSeesDeleteButton` | `GigHiveUITests.swift` | ✅ Implemented | `GH_TEST_HOST` + `GH_TEST_USER` (admin) + `GH_TEST_PASS` | Signs in as `admin`, navigates to unified list, waits for ≥ 1 `unified_list_video_cell`, asserts `unified_list_delete_button` exists on at least one cell. Server returns `can_delete: true` for admin on all entries. |
| `testDelEligMissingCanDeleteDecodesAsFalse` | `GigHiveTests.swift` | ⏳ Blocked on GigHiveTests target | None — pure unit test | Decodes a synthetic `MediaEntry` JSON string that omits the `can_delete` field. Asserts the resulting `entry.canDelete == false` with no crash or decode error. Validates backward compatibility with pre-Phase-2 server responses. |
| `testDelEligGuestUploadDoesNotWriteTokenStore` | `GigHiveTests.swift` | ⏳ Blocked on GigHiveTests target | None — pure unit test | Calls the guest upload finalize code path in isolation (injecting a mock finalize response with a `delete_token`). Asserts `UploaderDeleteTokenStore.load(host: "test.host")` returns an empty array — confirming the guest path never writes to the authenticated token store. |

**Manual verification only — Phase 5:**

| Item | Why manual |
|---|---|
| After Phase 3 ships: confirmed authenticated delete succeeds and row disappears | Requires a real, valid `can_delete: true` entry with a valid token; destructive server write |
| Post-403 list reload hides ✕ for the affected entry | Requires a controllable 403 trigger; timing-based between reload and UI update |

---

## Accessibility Identifiers

The tests locate UI elements by `accessibilityIdentifier`. Use `snake_case` throughout. Set via the `accessibilityIdentifier` parameter on `NoAccessoryTextField` / `NoAccessorySecureField` in `Theme.swift`, or via `.accessibilityIdentifier(...)` on SwiftUI views.

**Phase 0 (existing)**

| Identifier | Element | File |
|---|---|---|
| `login_server_field` | Server hostname text field | `LoginView.swift` |
| `login_username_field` | Username text field | `LoginView.swift` |
| `login_password_field` | Password secure field | `LoginView.swift` |
| `login_sign_in_button` | Sign In button | `LoginView.swift` |
| `splash_login_button` | Login button on Splash | `SplashView.swift` |
| `splash_logged_in_banner` | Orange logged-in text (shows username) | `SplashView.swift` |
| `splash_view_database_button` | View the Database `NavigationLink` | `SplashView.swift` |

**Phase 1 — Unified Player (added in Step 3)**

| Identifier | Element | File |
|---|---|---|
| `unified_player_close_button` | "Close" button on player nav bar | `UnifiedVideoPlayerView.swift` |
| `unified_player_overlay` | Loading / error overlay container `VStack` | `UnifiedVideoPlayerView.swift` |
| `unified_player_audio_scrubber` | Audio `Slider` | `UnifiedVideoPlayerView.swift` |
| `unified_player_audio_play_pause` | Audio play/pause `Button` | `UnifiedVideoPlayerView.swift` |
| `unified_player_audio_retry` | Audio retry `Button` | `UnifiedVideoPlayerView.swift` |

**Phase 2 — Unified List (added in Step 7)**

| Identifier | Element | File |
|---|---|---|
| `unified_list_video_cell` | Each video card row (`NavigationLink`) | `UnifiedVideoListView.swift` |
| `unified_list_new_badge` | "New" badge `Text` per row | `UnifiedVideoListView.swift` |
| `unified_list_search_field` | Search `TextField` (iOS 14 fallback path) | `UnifiedVideoListView.swift` |
| `unified_list_flag_button` | Flag / report `Button` per row | `UnifiedVideoListView.swift` |
| `unified_list_delete_button` | Delete (✕) `Button` per row | `UnifiedVideoListView.swift` |
| `unified_list_new_pill` | "N new videos added" pill | `UnifiedVideoListView.swift` |

---

## Launch Arguments

### `--uitesting`

The test suite passes `--uitesting` in `app.launchArguments`.  The app checks for this flag in
`SplashView.onAppear` and skips the Keychain session-restore block:

```swift
let isUITesting = ProcessInfo.processInfo.arguments.contains("--uitesting")
if !isUITesting, session.credential == nil, ... {
    // restore from Keychain
}
```

This ensures every test starts from a clean logged-out state regardless of what is stored in
the simulator's Keychain from a previous manual run.

---

### `--uitest-navigate-unified-list` (Phase 1+)

Added to `SplashView.swift` alongside `--uitest-navigate-database` in the `.onChange(of: session.credential)` block. Reuses the existing `goToDatabase` state flag — after Step 12 the destination is already `UnifiedVideoListView`, so no new `@State` property is needed.

```swift
} else if args.contains("--uitest-navigate-unified-list") {
    logWithTimestamp("[Splash] UI-test auto-navigating to UnifiedVideoListView after credential set")
    DispatchQueue.main.asyncAfter(deadline: .now() + 0.6) { goToDatabase = true }
}
```

### `--uitest-navigate-database` (existing — updated in Phase 3)

Unchanged in Phase 1 and 2. In Phase 3 (Step 12), the `goToDatabase` destination changes to `UnifiedVideoListView`. `testDatabaseLoadsEntriesAfterLogin` is updated at that point to assert `unified_list_video_cell` instead of `app.cells.firstMatch`.

### `--uitest-inject-delete-token` (Phase 4)

Added to `SplashView.onAppear` in the same `isUITesting` block as `--uitest-inject-guest-nonce`. Requires `GH_TEST_HOST` and `GH_TEST_DELETE_FILE_ID` in the environment. Execution order within `SplashView.onAppear`:

1. P9 clear: `if let host = env["GH_TEST_HOST"] { try? UploaderDeleteTokenStore.clear(host: host) }` — runs under `--uitesting` when `GH_TEST_HOST` is set (before any injection); prevents token leak from prior tests. Guard required to avoid `clear(host: "")` in non-authenticated test launches.
2. Injection: creates a synthetic `UploadedFileTokenEntry(fileId: GH_TEST_DELETE_FILE_ID, deleteToken: GH_TEST_DELETE_TOKEN ?? "uitest-invalid-token", ...)` and calls `UploaderDeleteTokenStore.upsert(host:entry:)`

The `"uitest-invalid-token"` default is intentional: button-visibility and 403 tests do not need a real token. Only a future success-path test (manual for now) would set `GH_TEST_DELETE_TOKEN` to a real value.

**Important (P6):** When any test calls `app.terminate()` + `app.launch()`, `app.launchEnvironment` must explicitly include `GH_TEST_DELETE_FILE_ID` in the re-launch environment — scheme env vars are NOT forwarded automatically to relaunched app processes. The `launchAuthListWithToken()` helper (see below) handles this.

### `launchAuthListWithToken(injectToken:useUploader:)` helper

Add to `GigHiveUITests.swift` alongside `autoLogin()` and `launchGuestList()`. Encodes the canonical launch sequence for all Phase 4 authenticated-list delete tests (P8). The `useUploader` parameter selects between `requireCredentials()` (admin) and `requireUploaderCredentials()` (uploader) — required because the 403 path is only reachable via the uploader HTTP auth user (see P11):

```swift
/// Canonical launch for Phase 4 authenticated-list delete tests.
/// injectToken=true: adds --uitest-inject-delete-token and forwards GH_TEST_DELETE_FILE_ID.
/// injectToken=false: clean launch (no tokens); used for the "absent" and post-403 checks.
/// useUploader=true: authenticates as GH_TEST_UPLOADER_USER / GH_TEST_UPLOADER_PASS instead of
///   GH_TEST_USER / GH_TEST_PASS. Required for testAuthDelete403ClearsToken (see P11).
/// Skips (XCTSkip) if the required credentials or GH_TEST_DELETE_FILE_ID are absent.
@discardableResult
private func launchAuthListWithToken(
    injectToken: Bool = false,
    useUploader: Bool = false,
    file: StaticString = #file,
    line: UInt = #line
) throws -> (host: String, user: String, pass: String, insecure: Bool) {
    // requireUploaderCredentials / requireCredentials each throw XCTSkip if vars absent.
    let creds = useUploader
        ? try requireUploaderCredentials(file: file, line: line)
        : try requireCredentials(file: file, line: line)
    let env = ProcessInfo.processInfo.environment
    app.terminate()
    var args = ["--uitesting", "--uitest-auto-login", "--uitest-navigate-unified-list"]
    if injectToken { args.append("--uitest-inject-delete-token") }
    app.launchArguments = args
    // creds fields are non-optional (validated above). SplashView reads GH_TEST_USER /
    // GH_TEST_PASS regardless of account type, so we always map to those keys.
    app.launchEnvironment["GH_TEST_HOST"]     = creds.host
    app.launchEnvironment["GH_TEST_USER"]     = creds.user
    app.launchEnvironment["GH_TEST_PASS"]     = creds.pass
    app.launchEnvironment["GH_TEST_INSECURE"] = creds.insecure ? "1" : "0"
    if injectToken, let fileId = env["GH_TEST_DELETE_FILE_ID"] {
        app.launchEnvironment["GH_TEST_DELETE_FILE_ID"] = fileId  // P6: explicit forward
    }
    app.launch()
    // Wait for list load — prevents vacuous passes from failed auth or navigation.
    XCTAssert(
        app.buttons.matching(identifier: "unified_list_video_cell").firstMatch
            .waitForExistence(timeout: 40),
        "unified_list_video_cell not found — check credentials and server reachability",
        file: file, line: line
    )
    return creds
}
```

### Guest gallery tests — no launch argument

A `--uitest-navigate-guest-gallery` argument is not implementable as a static launch argument — navigating to a guest gallery requires a `GuestUploadRecord` with a runtime nonce, not a static value. Phase 2 guest tests read `GH_TEST_GUEST_NONCE` from the test environment and navigate through the normal splash screen guest entry point, the same way a real guest user would.

---

## App-Level Test Isolation (`--uitesting` behaviours)

Several app-side changes gate on `--uitesting` to make tests repeatable:

| Location | Behaviour under `--uitesting` |
|---|---|
| `SplashView.onAppear` | Skips Keychain session restore so every test starts logged out |
| `SplashView.onAppear` | Calls `GuestUploadRecord.save([])` — clears any guest records left by prior injection tests so `isGuestOnly` is never erroneously true (P9) |
| `SplashView.onAppear` | `if let host = env["GH_TEST_HOST"] { try? UploaderDeleteTokenStore.clear(host: host) }` — clears delete tokens left by prior injection tests so no spurious delete button appears (P9). Guard is required: tests that do not set `GH_TEST_HOST` in `launchEnvironment` would otherwise call `clear(host: "")` with an empty-string account key. Runs before `--uitest-inject-delete-token` injection. |
| `LoginView.onAppear` | Skips Keychain prefill of server/username/password; sets `disableCertChecking = true` |

The `disableCertChecking = true` side effect means the app will bypass TLS certificate validation for all network calls during the test run. This is required for `devvm.gighive.internal` which uses a self-signed certificate.

An ATS (App Transport Security) exception is also declared in `Configs/AppInfo.plist` for `devvm.gighive.internal`:

```xml
<key>NSAppTransportSecurity</key>
<dict>
    <key>NSExceptionDomains</key>
    <dict>
        <key>devvm.gighive.internal</key>
        <dict>
            <key>NSExceptionAllowsInsecureHTTPLoads</key>
            <true/>
            <key>NSIncludesSubdomains</key>
            <true/>
        </dict>
    </dict>
</dict>
```

This is required because `InsecureTrustDelegate` (which handles the TLS challenge at the `URLSession` delegate level) alone is not sufficient on iOS 17 simulator — ATS can block the connection before the delegate is invoked.

## Known Issues and Workarounds

### `xcodebuild: error: invalid option '-testenv'` (Xcode 26.2)

`-testenv` is documented but not yet functional in Xcode 26.2.  Use the Xcode scheme UI to set
environment variables instead (Option A above).

### `error: ... deployment target mismatch`

If Xcode recreates the `GigHiveUITests` target or you see this error again, check
`project.pbxproj` for the two `GigHiveUITests` `XCBuildConfiguration` entries and confirm both
have `IPHONEOS_DEPLOYMENT_TARGET = 14.0`.

### Test times out waiting for database entries

`testDatabaseLoadsEntriesAfterLogin` allows 20 seconds for the first cell to appear.  If the
dev server is slow to respond (cold Docker start, VPN latency), increase the timeout in the
test or ensure the server is warmed up before running.

---

## Adding More Tests

Follow this pattern for new Phase tests:

1. Add `accessibilityIdentifier` modifiers to any new UI elements that need to be targeted. Use `snake_case`. Add the identifier to the table in this doc before writing the test.
2. Add new `@MainActor func test...()` methods to `GigHiveUITests.swift` under the appropriate `// MARK: - Phase N` block.
3. Use `requireCredentials()` for any test that makes a real network call. Use `XCTSkip` (not `XCTFail`) when env vars are absent.
4. For guest gallery tests, read `GH_TEST_GUEST_NONCE` from `ProcessInfo.processInfo.environment` and skip if absent.
5. Build with `xcodebuild build-for-testing ...` to verify compilation before running.
6. If a new launch argument is needed in `SplashView`, add it to the Launch Arguments section in this doc and to the `.onChange(of: session.credential)` block following the existing pattern at lines 296–302.

Future test coverage planned:

- Phase 1–3: Unified player and list tests (see test inventories above — `refactor_video_player_page.md`)
- Bearer token login flow
- JWT expiry → re-login prompt
- Upload flow with session credential vs QR upload token
- `.owner` role UI display (replaces `.admin`)
- Keychain session restore on cold launch (requires a separate test that does NOT pass `--uitesting`)
- Phase 4 (success path): Authenticated delete — actual deletion confirmed; requires `GH_TEST_DELETE_TOKEN` set to a real valid token for a disposable server-side video
