# Feature: Guest Gallery Live Refresh & Push Notifications

**Status:** Planning  
**Related docs:**
- `docs/feature_completed_iphone_qr_code_shared_gallery.md`
- `docs/feature_completed_iphone_qr_code_shared_gallery_implementation.md`
- `docs/refactor_iphone_qr_code_gallery_notifications.md`
- `docs/refactor_iphone_qr_code_gallery_access_for_all.md`

---

## Problem Statement

Today the guest viewer has no live feedback loop. The flow is:

1. Guest uploads a video, submits, sees "Your video is in the moderation queue."
2. Guest must **close the app and reopen it** before `pollGuestRecords()` fires on `SplashView.onAppear`.
3. If the gallery is open, there is no polling — new videos approved while the guest is browsing never appear unless the guest navigates away and returns.
4. If the guest's phone is sitting in their pocket during an event, there is no signal that anything changed.

The desired UX: the app knows — in near real-time — when a video is approved or when the gallery gains new videos, and it surfaces that information to the guest without requiring a manual restart.

---

## Current Polling Architecture (Baseline)

| Trigger | Where | What it does |
|---------|-------|--------------|
| `SplashView.onAppear` | `SplashView` | Fires `pollGuestRecords()` once per app open/foreground return |
| `GuestGalleryView.onAppear` | `GuestGalleryView` | Calls `loadGallery()` once when the view appears |
| Neither | — | No polling while the app is running and the user stays on the same screen |

**Already in place but unused:**
- `anon_upload_attributions.apns_token VARCHAR(200)` — column exists in schema, labeled "future-use: APNs push device token; MVP uses polling via guest-status.php" (Phase 1 implementation notes, Step 3)
- `GuestStatusResponse.videoCount` / `GuestGalleryResponse.videoCount` — server already returns counts; logic to detect increases already implemented

---

## Proposed Solution — Three Phases

### Phase 1 (iOS only, no server changes) — Foreground Timer Polling

**Goal:** While the app is open, poll every N seconds so the guest sees changes without restarting.

**Scope:** `SplashView.swift`, `GuestGalleryView.swift`

#### Why No Server Changes

Phase 1 requires zero infrastructure changes for three reasons:

1. **Both endpoints already exist** — `/api/guest-status.php` and `/api/guest-gallery.php` are live in production and handle these exact requests today. Phase 1 simply calls them on a timer instead of once per app open.
2. **No new data sent to the server** — Phase 1 only reads; nothing new is written or transmitted to the server.
3. **The load increase is negligible** — ~750 B per status call, ~3 KB per gallery call at a typical event size. The server will not notice the difference.

Phase 2 is where infrastructure work begins: APNs key deployment, the PHP `ApnsService` class, new env vars, Ansible changes, and writing `apns_token` to the database.

#### How It Will Work

Once the guest has submitted a video, a background timer starts and silently checks in with the server every 60 seconds — no spinner, no visible activity — as long as the app is open and in the foreground. It checks every event the guest has a pending or approved record for. Three things can surface from each check:

**1. Video approved** — the server returns `"approved"` for a record that was `"pending"`. The approval banner that today only appears on app launch will now appear within 60 seconds, while the guest is still looking at the screen. No restart required.

**2. Video rejected** — the server returns `"rejected"`. The rejection alert appears within 60 seconds. After that, the record is dropped from the poll cycle — nothing left to check for that event.

**3. New videos added to the gallery** — for an already-approved record, the server returns a higher video count than what was last seen. The "New videos" badge appears on the gallery row in the splash screen within 60 seconds.

Once the guest opens the gallery, a second timer takes over and checks every 30 seconds for new videos. If new videos arrive while the guest is browsing, a floating pill appears at the top of the screen — always visible regardless of scroll position — announcing how many were added. The list updates quietly without moving the guest's scroll position. The pill auto-dismisses after 8 seconds or can be tapped away.

**When polling stops:**
- **Rejected** records exit the poll cycle immediately — no further server calls for that event.
- **Approved** records continue to be polled for gallery growth until the gallery expires, at which point the server returns `"expired"` and the record exits the cycle. If no pollable records remain, the timer still fires every 60 seconds but returns instantly with no network call.
- **Device locked / app backgrounded** — both timers pause. They resume and fire one immediate check the moment the app returns to the foreground.

#### UI Impact

Phase 1 introduces one genuinely new UI element: the floating pill in `GuestGalleryView`. Everything else is latency reduction — four existing UI surfaces that already render correctly today, but currently require the guest to close and reopen the app to reflect updated server state.

| UI element | Where | Today | After Phase 1 |
|------------|-------|-------|---------------|
| Approval banner | `SplashView` | Appears on next app open | Appears within 60 seconds, no restart |
| Rejection alert | `SplashView` | Appears on next app open | Appears within 60 seconds, no restart |
| "New videos" badge | `SplashView` gallery row | Updates on next app open | Updates within 60 seconds live |
| Video list content | `GuestGalleryView` | Loaded once on open, never refreshes | Silently refreshes every 30 seconds |

The banner, alert, and badge are all driven by the 60-second `SplashView` timer. The video list refresh and the new pill are both driven by the 30-second `GuestGalleryView` timer.

#### Observability

**No server changes are required for Phase 1.** Both endpoints polled by the timers already exist and serve production traffic today:

| Endpoint | Already called by | Phase 1 change |
|----------|------------------|----------------|
| `/api/guest-status.php` | `SplashView.onAppear` (once per launch) | Called every 60s while app is active |
| `/api/guest-gallery.php` | `GuestGalleryView.onAppear` (once per open) | Called every 30s while gallery is open |

Both are stateless reads with no side effects. The server receives more requests of a type it already handles — no new endpoints, no schema changes, no config changes needed.

**Network payload per call:**

| Endpoint | Request | Response (typical) | Per call total |
|----------|---------|-------------------|---------------|
| `guest-status.php` | ~450 B | ~300 B | **~750 B** |
| `guest-gallery.php` (5 videos) | ~450 B | ~650 B | **~1.1 KB** |
| `guest-gallery.php` (20 videos) | ~450 B | ~2.6 KB | **~3.1 KB** |
| `guest-gallery.php` (50 videos) | ~450 B | ~6.5 KB | **~7.0 KB** |

Per-video JSON entry ≈ 130 bytes (`upload_job_id`, `stream_url`, `display_name`, `approved_at`). With HTTP/2 header compression (HPACK — default for Apple's `URLSession` when the server supports it), repeated request headers compress from ~400 B to ~50 B, roughly halving the per-call overhead.

**Bytes per minute in practice:**

| Scenario | Calls/min | Bytes/min |
|----------|-----------|-----------|
| SplashView open, 1 pending record | 1/min | ~750 B |
| SplashView open, 3 records (multiple events) | 3/min (parallel) | ~2.3 KB |
| Gallery open, 20-video event | 2/min | ~6.2 KB |

A complete event session (30 min on SplashView + 10 min in gallery, 20 videos) totals roughly **~80 KB** — less than two low-resolution thumbnail images. No media bytes are transferred by polling; video data only flows when the guest taps play.

**No pollable records → zero network calls.** `pollGuestRecords()` filters for `pending` or `approved` records before making any network call. Once all records are rejected or expired, the 60-second timer fires but returns immediately with no HTTP activity.

**What to monitor in Apache access logs after Phase 1 ships:**
- Expect `guest-status.php` call rate to increase proportionally to DAU × avg active session minutes / 60
- Expect `guest-gallery.php` call rate to increase proportionally to gallery-open session minutes / 30
- Both are GET requests; a spike in 4xx/5xx on either endpoint would indicate a regression in the existing polling logic

**iOS changes:**

**`SplashView.swift`:**
- Add `@Environment(\.scenePhase) private var scenePhase`
- Add `private let splashTimer = Timer.publish(every: 60, on: .main, in: .common).autoconnect()` as a `let` constant on the view — **not** `@State`. `Publishers.Autoconnect<Timer.TimerPublisher>` is not `Equatable` and will not compile as `@State`.
- `.onReceive(splashTimer)` → call `Task { await pollGuestRecords() }` only when `scenePhase == .active`
- `.onChange(of: scenePhase)` → when `.active`, fire one immediate `pollGuestRecords()` call (currently only `.onAppear` does this; returning from background should also poll immediately)
- **No `onDisappear` cancel needed:** in SwiftUI `NavigationView`/`NavigationStack`, pushing a child view does *not* call `onDisappear` on `SplashView` — the view stays in the navigation hierarchy. The timer fires while gallery views are on stack, which is harmless: `pollGuestRecords()` and `loadGallery()` hit different endpoints, and the 60-second interval keeps server load negligible.

**`GuestGalleryView.swift`:**
- Add `@Environment(\.scenePhase) private var scenePhase`
- Add `private let galleryTimer = Timer.publish(every: 30, on: .main, in: .common).autoconnect()` as a `let` constant — same reason: not `@State`.
- `.onReceive(galleryTimer)` → call `Task { await loadGallery(silent: true) }` only when `scenePhase == .active`. Add a `silent: Bool = false` parameter to `loadGallery()` that skips setting `isLoading = true` — otherwise every background poll flashes the spinner. The initial `onAppear` call uses `silent: false`.
- `.onChange(of: scenePhase)` → immediate reload on return to `.active`, also `silent: true`
- **Smooth update:** add `withAnimation(.default)` around the `galleryResponse = resp` assignment so new video rows animate in via SwiftUI `ForEach` id diffing.
- **"New videos" pill:** track `@State private var previousVideoCount: Int` initialized to `record.lastSeenVideoCount` (the `GuestUploadRecord` is already passed into the view at init time). This ensures the pill is not triggered by videos already seen in a prior session. Update `previousVideoCount` **only on a successful fetch** — if `loadGallery(silent: true)` throws or returns an error, leave it unchanged so a subsequent successful poll doesn't incorrectly surface old videos as new. When a silent poll finds `resp.videos.count > previousVideoCount`, show a floating pill. The pill must live in a `ZStack` overlay wrapping the `ScrollView` — inside the scroll content it would scroll off-screen. Pill reads `"↑ N new video(s) added"` with a dismiss `×`. Auto-dismisses after 8 seconds via `Task.sleep`. Phase 3 adds scroll-to-top on tap.

**Wireframe — new videos detected during live poll:**

```
GuestGalleryView — background poll finds 2 new videos
──────────────────────────────────────────────────────
  🐝  Event Gallery                        ← nav bar
      StormPigs — 2026-07-17

  ┌────────────────────────────────────────────────┐
  │  ↑  2 new videos added              ×         │  ← ZStack overlay pill
  └────────────────────────────────────────────────┘    orange tint, always on top
    auto-dismisses in 8s or on × tap                   regardless of scroll position
    (Phase 3: tap also scrolls list to top)

  Available for 87 more days · 6 videos

  ┌────────────────────────────────────────────────┐
  │  Jane Smith       New  ▶  🚩                  │
  └────────────────────────────────────────────────┘
  ┌────────────────────────────────────────────────┐
  │  Bob Ruffino      New  ▶  🚩                  │
  └────────────────────────────────────────────────┘
  ┌────────────────────────────────────────────────┐
  │  Marcus Chen           ▶  🚩                  │
  └────────────────────────────────────────────────┘
     ⚠️  Your gallery access is stored on this device...
```

**No server changes needed. No new dependencies.**

---

### Phase 2 (iOS + PHP + APNs infrastructure) — Push Notifications

**Goal:** Notify the guest even when the app is not open. Two triggers:
1. Moderator approves the guest's video → push: *"Your video was accepted! The event gallery is now available."*
2. New videos are approved for an event the guest already has gallery access to → push: *"New videos added to [Event Name] gallery."*

> **Rejection not included as a push trigger.** If the guest's app is closed when their video is rejected, they learn about it only when they next open the app and Phase 1's foreground poll fires on foreground return. A rejection push can be added later as a third trigger with minimal additional work (same `ApnsService::send()` pattern), but is omitted here as a deliberate simplification — rejection is a less time-sensitive event than approval.

**Infrastructure context:**
- The `anon_upload_attributions.apns_token` column already exists — no schema migration needed.
- Apple Push Notification service (APNs) requires an **APNs Auth Key** (`.p8` file, generated in Apple Developer Portal under Certificates, Identifiers & Profiles → Keys). This is the modern HTTP/2 token-based auth approach — no certificates to rotate annually.
- Credentials needed: **Key ID** (10-char string), **Team ID** (`WB7D4FC7XU`), **Bundle ID** (`app.gighive.GigHive`), and the `.p8` private key file.
- The APNs auth token (JWT) is generated per-request in PHP and expires after 1 hour — no token rotation needed at the app level.

**Architecture choice: PHP → APNs HTTP/2 directly (no push queue)**

The approval action in `admin/event_qr.php` is already synchronous POST-redirect-GET. Adding a synchronous APNs call (< 1 second in practice) on the same request is the simplest approach — no worker queue, no new containers. If APNs is unreachable, the approval still succeeds (push is best-effort). Wrap in `try/catch` with error logged to `error_log()` — never surface APNs errors to the admin UI.

**Alternative considered and rejected:** queuing push jobs through the existing `ai-worker` container. Adds latency, complexity, and the queue is designed for compute-heavy video work. Overkill for a sub-kilobyte push payload.

---

#### Phase 2 — Step 1: iOS — Request permission and register for remote notifications

**Architecture note — `AppDelegate` required:**

`GigHiveApp.swift` is a pure SwiftUI `App` struct (`@main struct GigHiveApp: App`). There is no existing `UIApplicationDelegate`. The callbacks `didRegisterForRemoteNotificationsWithDeviceToken` and `UNUserNotificationCenterDelegate` are `UIApplicationDelegate`/`NSObject` protocol methods — they cannot be received by an `App` struct. A new `AppDelegate` class is required and wired via `@UIApplicationDelegateAdaptor`:

**`AppDelegate.swift` (NEW):**
```swift
final class AppDelegate: NSObject, UIApplicationDelegate, UNUserNotificationCenterDelegate {
    // Set by GigHiveApp after init so the delegate can publish push events
    var guestSession: GuestUploadSession?

    func application(_ application: UIApplication,
                     didFinishLaunchingWithOptions _: [UIApplication.LaunchOptionsKey: Any]?) -> Bool {
        UNUserNotificationCenter.current().delegate = self
        return true
    }
    func application(_ application: UIApplication,
                     didRegisterForRemoteNotificationsWithDeviceToken deviceToken: Data) {
        let hex = deviceToken.map { String(format: "%02x", $0) }.joined()
        UserDefaults.standard.set(hex, forKey: "apnsDeviceToken")
    }
    // Foreground delivery: show banner + play sound
    func userNotificationCenter(_ c: UNUserNotificationCenter,
                                willPresent n: UNNotification,
                                withCompletionHandler h: @escaping (UNNotificationPresentationOptions) -> Void) {
        h([.banner, .sound])
    }
    // Tap from lock screen / background
    func userNotificationCenter(_ c: UNUserNotificationCenter,
                                didReceive response: UNNotificationResponse,
                                withCompletionHandler h: @escaping () -> Void) {
        let info = response.notification.request.content.userInfo
        let type = info["type"] as? String
        let eventName = info["event_name"] as? String
        DispatchQueue.main.async {
            self.guestSession?.pendingPushType = type
            self.guestSession?.pendingPushEventName = eventName
        }
        h()
    }
}
```

**`GigHiveApp.swift`:** add `@UIApplicationDelegateAdaptor(AppDelegate.self) var appDelegate` and pass `guestSession` to `appDelegate.guestSession` in the `WindowGroup` body.

**`GuestUploadSession.swift`:** add `@Published var pendingPushType: String?` and `@Published var pendingPushEventName: String?`. `SplashView` reacts via `.onChange(of: guestSession.pendingPushType)` — this is the correct channel for `AppDelegate` → SwiftUI communication without touching private `@State`.

**Notification permission request (contextual):**
- Call `UNUserNotificationCenter.requestAuthorization` **only when a pending `GuestUploadRecord` exists** — not on cold launch.
- Pre-prompt `Alert`: *"Get notified when your video is approved or new videos are added to the gallery."* On Allow → call `requestAuthorization`. On "Not now" → store `UserDefaults` flag `guestNotificationPromptDismissed` and do not re-ask.
- On granted: `UIApplication.shared.registerForRemoteNotifications()` → `AppDelegate.didRegisterForRemoteNotificationsWithDeviceToken` fires → hex token stored.
- **iOS 14 compat:** `UNUserNotificationCenter.requestAuthorization` is iOS 10+. No concerns.

**`GuestUploadRecord.swift`:**
- Add `static func storedAPNsToken() -> String?` reading `UserDefaults.standard.string(forKey: "apnsDeviceToken")`

---

#### Phase 2 — Step 2: iOS — Send APNs token to server on upload finalize

**`UploadPayload+GuestUpload.swift` (or `GuestUploadView` finalize path):**
- Include `apns_token` in the `metadata` dict sent to the TUS finalize endpoint, alongside the existing `nonce`, `display_name`, `tos_accepted`, etc.
- Value: `GuestUploadRecord.storedAPNsToken() ?? ""` — empty string if not granted; server ignores blank values.

**`UploadService::finalizeTusUpload` (PHP, `src/Services/UploadService.php`):**
- Already reads `metadata['nonce']` to look up the `anon_upload_attributions` row.
- Add: if `metadata['apns_token']` is non-empty and matches pattern `/^[0-9a-f]{64}$/i` (64-char hex APNs token), update `anon_upload_attributions SET apns_token = ?` WHERE `attribution_id` = the just-inserted row.
- No new column needed — `apns_token` already exists.

---

#### Phase 2 — Step 3: PHP — APNs service class

**New file: `src/Services/ApnsService.php`**

Responsibilities:
- Hold APNs credentials (read from env vars — never hardcoded).
- Generate a signed JWT for APNs HTTP/2 Bearer auth (**ES256** using the `.p8` EC private key).
- Send a push notification payload to a device token via `curl` with HTTP/2.
- Return `bool` — `true` if APNs returned `200`, `false` otherwise (do not throw; caller logs).

**New env vars needed** (add to `.env.j2` and all group_vars):

| Env var | Value source | Notes |
|---------|-------------|-------|
| `APNS_KEY_ID` | Apple Developer Portal → Keys | 10-char alphanumeric |
| `APNS_TEAM_ID` | `WB7D4FC7XU` (already known) | Same for all envs |
| `APNS_BUNDLE_ID` | `app.gighive.GigHive` (already known) | Same for all envs |
| `APNS_AUTH_KEY_PATH` | `/run/secrets/apns_auth.p8` or Ansible-deployed path | `.p8` file on server; never in git |
| `APNS_ENVIRONMENT` | `production` or `sandbox` | `sandbox` for dev/staging, `production` for prod |

**JWT generation in PHP (no third-party library needed):**
```php
// Header + payload, base64url-encoded, signed with ES256 (APNs uses ES256, not HS256)
// Use openssl_sign() with OPENSSL_ALGO_SHA256 and the .p8 key loaded via openssl_pkey_get_private()
```
> **Note:** APNs uses **ES256** (ECDSA with P-256), not HS256. The `.p8` key is an EC private key. PHP's `openssl_sign()` with `OPENSSL_ALGO_SHA256` over an EC key produces an ASN.1 DER signature that must be converted to raw IEEE P1363 format (R||S, 64 bytes) before base64url-encoding into the JWT. This is a known PHP/APNs compatibility point — handle explicitly in `ApnsService`.

**`ApnsService` method signatures:**
```php
class ApnsService {
    public static function sendApproval(string $deviceToken, string $eventName): bool
    public static function sendNewVideos(string $deviceToken, string $eventName, int $newCount): bool
    private static function send(string $deviceToken, array $payload, string $topic): bool
    private static function buildJwt(): string
}
```

**Push payload — approval:**
```json
{
  "aps": {
    "alert": {
      "title": "Video approved!",
      "body": "Your video for [EventName] has been accepted. Tap to view the gallery."
    },
    "sound": "default",
    "badge": 1
  },
  "type": "approval"
}
```

**Push payload — new videos:**
```json
{
  "aps": {
    "alert": {
      "title": "New videos added",
      "body": "[N] new video(s) added to [EventName] gallery."
    },
    "sound": "default"
  },
  "type": "new_videos",
  "event_name": "[EventName]"
}
```

---

#### Phase 2 — Step 4: PHP — Trigger push on approval

**`admin/event_qr.php` — `approve` POST handler:**

After the existing `UPDATE upload_jobs SET moderation_status='approved'...` query succeeds:
1. Query `anon_upload_attributions` for `apns_token` and `display_name` where `upload_job_id = ?`
2. Query `events` for `org_name`, `event_date` (already available in the page context)
3. If `apns_token` is non-null and non-empty: call `ApnsService::sendApproval($token, $eventName)`
4. Wrap in `try/catch` — approval is committed regardless of push result

**For "new videos" push (triggered when another user's video is approved):**
When a video is approved, all other approved contributors for the same event should be notified. This requires:
1. After approving job `$jobId`, find the `event_id` for that job.
2. SELECT `apns_token` FROM `anon_upload_attributions` WHERE `upload_job_id` IN (SELECT `job_id` FROM `upload_jobs` WHERE `event_id = ? AND moderation_status = 'approved'`) AND `apns_token IS NOT NULL` AND `upload_job_id != $justApprovedJobId`
3. For each token: call `ApnsService::sendNewVideos($token, $eventName, 1)`
4. Deduplicate tokens (same device, multiple uploads) before sending — use a `SET` or `array_unique`.

**`admin/event_qr.php` — `approve_all_pending` bulk handler:**
Same logic, but batch: approve all, then collect unique `apns_token` values and send one push per device (not one per approved video — avoid notification storms). Single "X new videos added" push per device.

---

#### Phase 2 — Step 5: Ansible — Deploy APNs key and env vars

**New Ansible tasks:**
- Add `apns_key_id`, `apns_environment` to all group_vars (dev/staging/prod differ on `apns_environment`)
- Ansible secret for `.p8` file: store in `ansible/secrets/apns_auth.p8` (gitignored), deploy via `ansible.builtin.copy` to `/etc/gighive/apns_auth.p8` on the Docker host, mounted into the container as a bind mount or Docker secret
- Add `APNS_KEY_ID`, `APNS_TEAM_ID`, `APNS_BUNDLE_ID`, `APNS_AUTH_KEY_PATH`, `APNS_ENVIRONMENT` to `.env.j2`
- Add smoke test in `shared_gallery/tasks/main.yml`: verify `APNS_KEY_ID` env var is set and non-empty in the container

**Apple Developer Portal prerequisites (one-time, done by account holder):**
1. Create an APNs Auth Key (Keys → `+` → APNs capability checked) — download the `.p8` file once (cannot re-download)
2. Note the Key ID
3. Enable Push Notifications capability in the GigHive App ID (Identifiers → `app.gighive.GigHive` → Push Notifications → Configure)

---

### Phase 3 (iOS only, depends on Phase 1) — GuestGalleryView Deep Refresh UX

**Goal:** When live polling or a push notification triggers a gallery refresh while the user is actively viewing `GuestGalleryView`, the UX should be seamless — no jarring full-page reload.

**Changes to `GuestGalleryView.swift`:**

**Smooth new-video insertion (refinement of Phase 1 pill):**

`previousVideoCount`, the `ZStack` floating pill, and `withAnimation(.default)` row animation are all introduced in **Phase 1**. Phase 3 makes two targeted additions:
1. **Scroll-to-top on pill tap:** wrap the `ScrollView` in `ScrollViewReader`. Assign `.id("gallery-top")` to the first element in the scroll content. On pill tap, call `proxy.scrollTo("gallery-top", anchor: .top)` before dismissing. The 8-second auto-dismiss path does not scroll.
2. **Animation upgrade:** `withAnimation(.default)` → `withAnimation(.easeInOut(duration: 0.3))` for smoother row slide-in.

**Wireframe — user scrolled mid-list; pill floats above via ZStack; tap scrolls to top:**

```
GuestGalleryView — user mid-list, 1 new video added by background poll
──────────────────────────────────────────────────────────────────
  [Nav: StormPigs — 2026-07-17]          ← nav bar always visible

  ┌────────────────────────────────────────────────┐
  │  ↑  1 new video added               ×       │  ← ZStack overlay pill
  └────────────────────────────────────────────────┘    stays on top; header has
    tap → proxy.scrollTo("gallery-top")               scrolled off-screen
    then pill dismisses

  ┌────────────────────────────────────────────────┐  ← user is here mid-list
  │  Marcus Chen           ▶  🚩               │
  └────────────────────────────────────────────────┘
  ┌────────────────────────────────────────────────┐
  │  Alex Kim              ▶  🚩               │
  └────────────────────────────────────────────────┘

        ↕  pull down to refresh (iOS 15+ .refreshable)
    [Refresh] nav bar button — iOS 14 fallback

  ⚠️  Your gallery access is stored on this device...
```

**Scroll position preservation:**
- Use `ScrollViewReader` with a stable anchor ID of `"gallery-top"` assigned to the first element in scroll content — this must match the `proxy.scrollTo("gallery-top", anchor: .top)` call in the smooth insertion section above
- The guest's scroll position is not reset by background polls — only tapping the new-video pill scrolls to top

**"Refreshing" indicator:**
- Show a `ProgressView()` as a `refreshable` modifier (iOS 15+) for manual pull-to-refresh
- For iOS 14: add a small "Refresh" button in the nav bar area as a fallback (already targeting iOS 14 minimum)
- Background timer polls are fully silent (no spinner)

**Notification → gallery navigation:**
- When the app receives an `"approval"` or `"new_videos"` push tap, `AppDelegate.userNotificationCenter(_:didReceive:)` fires (see Phase 2 Step 1) and sets `guestSession.pendingPushType` + `guestSession.pendingPushEventName`.
- `SplashView` observes these via `.onChange(of: guestSession.pendingPushType)`. For `"new_videos"`: match `pendingPushEventName` against stored `GuestUploadRecord` entries to find the right record, then activate the corresponding `NavigationLink(isActive:)` to `GuestGalleryView`. For `"approval"`: trigger the existing approval banner flow.
- **Do not use `@State var goToGalleryFromPush` —** `@State` is private SwiftUI state that cannot be set from outside the view. All push → navigation communication must go through `GuestUploadSession` (already an environment object).

---

## Files to Change

### Phase 1 — iOS only

| File | Repo | Change |
|------|------|--------|
| `GigHive/Sources/App/SplashView.swift` | gighiveapp | Add 60s `Timer`, `scenePhase` gate, immediate poll on foreground return |
| `GigHive/Sources/App/GuestGalleryView.swift` | gighiveapp | Add 30s `Timer`, `scenePhase` gate, `withAnimation` on video list, "new videos" pill |

### Phase 2 — iOS + PHP + Ansible

| File | Repo | Change |
|------|------|--------|
| `GigHive/Sources/App/AppDelegate.swift` **(NEW)** | gighiveapp | `UIApplicationDelegate` + `UNUserNotificationCenterDelegate`; `didRegisterForRemoteNotificationsWithDeviceToken`; sets `guestSession.pendingPushType` + `pendingPushEventName` on tap |
| `GigHive/Sources/App/GigHiveApp.swift` | gighiveapp | Add `@UIApplicationDelegateAdaptor(AppDelegate.self) var appDelegate`; pass `guestSession` ref to delegate |
| `GigHive/Sources/App/GuestUploadSession.swift` | gighiveapp | Add `@Published var pendingPushType: String?` and `@Published var pendingPushEventName: String?` |
| `GigHive/Sources/App/GuestUploadRecord.swift` | gighiveapp | Add `storedAPNsToken()` static helper |
| `GigHive/Sources/App/GuestUploadView.swift` (finalize path) | gighiveapp | Include `apns_token` in finalize metadata |
| `GigHive/Sources/App/UploadClient.swift` | gighiveapp | Add `var apnsToken: String?` to `UploadPayload`; append to `finalizeTusUpload` body inside `uploadToken != nil` guard |
| `GigHive/Sources/App/SplashView.swift` | gighiveapp | React to `guestSession.pendingPushType` via `.onChange` to trigger approval banner or new-video badge; clear after handling |
| `src/Services/ApnsService.php` | gighiveinfra (NEW) | APNs JWT builder + HTTP/2 push sender |
| `src/Services/UploadService.php` | gighiveinfra | Save `apns_token` to `anon_upload_attributions` at finalize |
| `admin/event_qr.php` | gighiveinfra | Call `ApnsService` on approve / approve_all_pending |
| `ansible/roles/docker/templates/.env.j2` | gighiveinfra | Add 5 new APNS_* vars |
| `ansible/inventories/group_vars/gighive/gighive.yml` | gighiveinfra | Add `apns_*` vars |
| `ansible/inventories/group_vars/gighive2/gighive2.yml` | gighiveinfra | Add `apns_*` vars |
| `ansible/inventories/group_vars/prod/prod.yml` | gighiveinfra | Add `apns_*` vars |
| `ansible/roles/shared_gallery/tasks/main.yml` | gighiveinfra | APNs env var smoke test |
| `ansible/roles/docker/docker-compose.yml.j2` (or equivalent) | gighiveinfra | Mount `.p8` key file into container |

### Phase 3 — iOS only

| File | Repo | Change |
|------|------|--------|
| `GigHive/Sources/App/GuestGalleryView.swift` | gighiveapp | Add `ScrollViewReader` (scroll-to-top on pill tap); `refreshable` pull-to-refresh (iOS 15+); nav bar Refresh button (iOS 14 fallback) — pill + `previousVideoCount` introduced in Phase 1 |
| `GigHive/Sources/App/SplashView.swift` | gighiveapp | React to `guestSession.pendingPushType` + `pendingPushEventName` to navigate to the matching `GuestGalleryView` for `"new_videos"` pushes |

---

## Deployment Sequencing

**Phase 1** is fully self-contained and can ship in the next iOS build. Zero server changes.

**Phase 2** must be sequenced:
1. Apple Developer Portal: create APNs Auth Key, download `.p8`, enable Push Notifications on App ID → **one-time manual step before any code lands**
2. Ansible: deploy `.p8` key to all environments; add env vars to all group_vars + `.env.j2`; run playbook
3. PHP: `ApnsService.php` + `UploadService.php` + `event_qr.php` changes → deploy via Ansible (no DB migration needed — `apns_token` column already exists)
4. iOS: permission request flow + token registration + finalize payload change → App Store update
5. Phase 2 iOS and PHP can ship in either order — if the iOS update ships before PHP, `apns_token` is sent but not yet stored (no error, silently ignored). If PHP ships first, it simply never receives a token until the iOS update lands. Both orderings are safe.

**Phase 3** can ship alongside Phase 1 (no dependencies beyond Phase 1 timer work) or as a subsequent iOS update.

---

## What "Restart Required" Scenarios Remain After This Feature

After all three phases ship, the only case where the guest must still "do something active" is:
- **App terminated (not backgrounded):** APNs delivers the notification to the lock screen; tapping it opens the app, deep-links to the gallery, and the Phase 1 polling resumes. **No manual restart required** — the push notification is the restart trigger.
- **No notification permission granted:** guest falls back to Phase 1 foreground polling. Must have the app open, but no restart needed once it is open.
- **Notifications disabled in iOS Settings:** same as no permission. Push is best-effort; polling is the fallback.

---

## Open Questions

1. **`apns_token` staleness:** device tokens change when the user reinstalls the app or (rarely) when Apple rotates them. The current model stores the token at upload time and never updates it. Mitigation: on each foreground `pollGuestRecords()` call, if the locally stored APNs token differs from what's in the server-side record, send an update call to a new lightweight endpoint (`POST /api/guest-apns-update.php?nonce=…&apns_token=…`). Low priority for MVP — token rotation is rare in practice during the 30–90 day gallery window.

2. **Bulk approve notification storms:** if 20 videos are approved at once via "Approve All Pending", 20 push notifications could go to 20 different devices in the same HTTP request cycle. Each `curl` APNs call is synchronous in the proposed design. For 20 calls at ~100ms each: ~2 seconds added to the POST response time. Acceptable for an admin action. If it becomes a problem, queue the calls using PHP's `register_shutdown_function()` to fire after the redirect.

3. **`guest-apns-update.php` endpoint** (see Q1): if built, it must accept both `{30,43}` nonce formats (status nonce and raw token), following the same dual-auth pattern as `guest-gallery.php` and `guest-stream.php`.

4. **`UNUserNotificationCenter` vs. `UserNotifications` framework on iOS 14:** both are the same framework (`UserNotifications.framework`); no compat concern. `requestAuthorization` is iOS 10+.

5. **Sandbox vs. production APNs:** dev/staging environments use `api.sandbox.push.apple.com`; prod uses `api.push.apple.com`. Gate on `APNS_ENVIRONMENT` env var in `ApnsService`. The `.p8` key works for both environments — same key, different endpoint URL.

6. **APNs JWT per-request cost:** the plan generates a fresh ES256 JWT on every `ApnsService::send()` call. APNs JWTs are valid for 60 minutes; re-generating one per request is functional but wasteful. For single approve actions at small event scale this is fine. For `approve_all_pending` batches, cache the JWT in a PHP static property with a 55-minute expiry window so all pushes in a single HTTP request reuse the same token. APNs may rate-limit providers that issue a new JWT on every single call at high volume.

7. **Viewer-only attendees (raw QR token, no upload) cannot receive push notifications:** after the `refactor_iphone_qr_code_gallery_access_for_all` change, attendees who browse the gallery via raw QR token without uploading have no `anon_upload_attributions` row. There is no server-side record to associate an APNs token with — they receive no push notifications and fall back to foreground polling only. Acceptable limitation of the accountless model; document in UX copy if needed.

---

## Phase 1 Implementation Guide

**Files changed:** `SplashView.swift`, `GuestGalleryView.swift`  
**Server changes:** none  
**New dependencies:** none  
**iOS minimum:** 14 (no new APIs — `Timer.publish`, `scenePhase`, `Task.sleep` all available)

---

### Change 1 of 2 — `SplashView.swift`

Adds the 60-second background poll timer. Two additions only: two new properties and two new view modifiers.

**Add to the property block** (after the existing `@State` vars, before `var body`):

```swift
@Environment(\.scenePhase) private var scenePhase
private static let splashPollInterval: Double = 60
private let splashTimer = Timer.publish(every: Self.splashPollInterval, on: .main, in: .common).autoconnect()
```

**Add two modifiers** to the inner `VStack` chain, after the existing `.alert(isPresented: $showRejectionAlert) { ... }` block:

```swift
.onReceive(splashTimer) { _ in
    guard scenePhase == .active else { return }
    Task { await pollGuestRecords() }
}
.onChange(of: scenePhase) { phase in
    guard phase == .active else { return }
    Task { await pollGuestRecords() }
}
```

> **Note:** `.onChange(of:)` only fires on value *changes*, not on initial appearance, so there is no double-poll on first launch. The `onAppear` handler already calls `pollGuestRecords()` once — the timer then takes over at 60-second intervals.

That is the complete change to `SplashView.swift`.

---

### Change 2 of 2 — `GuestGalleryView.swift`

Four additions: new state/timer properties, a `pillView` computed property, a wrapped `body`, and an updated `loadGallery()`.

#### 2a. New properties

Add after the existing `@State private var deletedIds: Set<Int> = []` line:

```swift
// Phase 1 — live polling
@Environment(\.scenePhase) private var scenePhase
@State private var previousVideoCount: Int = 0
@State private var showNewVideosPill = false
@State private var newVideosPillCount = 0
@State private var isSilentPolling = false                    // concurrent-poll guard
@State private var pillDismissTask: Task<Void, Never>?        // cancellable auto-dismiss handle

private static let galleryPollInterval: Double = 30
private static let pillAutoDismissNanoseconds: UInt64 = 8_000_000_000

private let galleryTimer = Timer.publish(every: Self.galleryPollInterval, on: .main, in: .common).autoconnect()
```

#### 2b. New `pillView` computed property

Add this as a new `private var` anywhere before `var body` (e.g., after `alertBinding`):

```swift
private var pillView: some View {
    HStack(spacing: 8) {
        Text("↑ \(newVideosPillCount) new video\(newVideosPillCount == 1 ? "" : "s") added")
            .bold()             // Text.bold() is iOS 13+; View.bold() is iOS 16+ — must call on Text directly
            .font(.caption)
            .foregroundColor(.white)
        Spacer()
        Button(action: {
            pillDismissTask?.cancel()           // cancel the auto-dismiss — pill is already going away
            pillDismissTask = nil
            withAnimation { showNewVideosPill = false }
        }) {
            Image(systemName: "xmark")
                .font(.system(size: 11, weight: .bold))   // weight baked into Font — no View.bold() needed
                .foregroundColor(.white)
        }
    }
    .padding(.horizontal, 12)
    .padding(.vertical, 8)
    .background(Color.orange)
    .cornerRadius(8)
    .padding(.horizontal, 12)
    .padding(.top, 8)
    .transition(.move(edge: .top).combined(with: .opacity))
}
```

#### 2c. Updated `body`

Replace the current `var body: some View { ScrollView { ... } ... }` with the version below. The only structural change is wrapping `ScrollView` in `ZStack(alignment: .top)` for the floating pill, updating `.onAppear`, and adding two new modifiers. All scroll content inside the `VStack` is unchanged.

```swift
var body: some View {
    ZStack(alignment: .top) {
        ScrollView {
            VStack(alignment: .leading, spacing: 16) {
                // ── existing content unchanged ──
            }
        }
        if showNewVideosPill {
            pillView
        }
    }
    .dismissKeyboardOnScroll()
    .ghFullScreenBackground(GHTheme.bg)
    .navigationTitle(record.eventName)
    .navigationBarTitleDisplayMode(.inline)
    .onAppear {
        previousVideoCount = record.lastSeenVideoCount  // seed before first load
        Task { await loadGallery() }
    }
    .onReceive(galleryTimer) { _ in
        guard scenePhase == .active else { return }
        Task { await loadGallery(silent: true) }
    }
    .onChange(of: scenePhase) { phase in
        guard phase == .active else { return }
        Task { await loadGallery(silent: true) }
    }
    .onDisappear {
        pillDismissTask?.cancel()   // prevent orphaned task from mutating state after view is popped
        pillDismissTask = nil
    }
    .alert(isPresented: alertBinding) {
        makeAlert()
    }
}
```

#### 2d. Updated `loadGallery()`

Replace the existing `loadGallery()` function in full:

```swift
@MainActor
private func loadGallery(silent: Bool = false) async {
    guard let baseURL = URL(string: record.baseURLString) else {
        if !silent { errorMessage = "Invalid server URL." }
        return
    }
    // Guard: don't overlap a silent poll with any active load (foreground or background).
    if silent && (isLoading || isSilentPolling) { return }
    if !silent {
        isLoading = true
        errorMessage = nil
    } else {
        isSilentPolling = true
    }
    defer {
        if !silent { isLoading = false }
        else { isSilentPolling = false }
    }
    do {
        let resp = try await GuestGalleryAPIClient(baseURL: baseURL).fetchGallery(nonce: record.statusNonce)

        // Show pill if a background poll finds new videos.
        if silent && resp.videos.count > previousVideoCount {
            let diff = resp.videos.count - previousVideoCount
            newVideosPillCount = diff
            pillDismissTask?.cancel()           // cancel any in-flight auto-dismiss before re-arming
            withAnimation { showNewVideosPill = true }
            pillDismissTask = Task {
                try? await Task.sleep(nanoseconds: Self.pillAutoDismissNanoseconds)
                guard !Task.isCancelled else { return }  // manual × tap cancelled this task
                withAnimation { showNewVideosPill = false }
            }
        }

        withAnimation(.default) { galleryResponse = resp }
        previousVideoCount = resp.videos.count  // only updated on a successful fetch

        let vc = resp.videoCount ?? resp.videos.count
        var allRecords = GuestUploadRecord.load()
        let eventRecords = allRecords.filter {
            $0.baseURLString == record.baseURLString && $0.eventName == record.eventName
        }
        viewedIds = Set(eventRecords.flatMap { $0.viewedUploadJobIds })
        ownUploadIds = Set(eventRecords.map { $0.uploadJobId })
        // Verbose set logging only on foreground load — silent polls run every 30s and sort is wasteful.
        if silent {
            logWithTimestamp("[Gallery] poll silent: videoCount=\(vc)")
        } else {
            logWithTimestamp("[Gallery] loadGallery ownUploadIds=\(ownUploadIds.sorted()) viewedIds=\(viewedIds.sorted()) videoCount=\(vc)")
        }
        var needsSave = false
        for i in allRecords.indices
            where allRecords[i].baseURLString == record.baseURLString
               && allRecords[i].eventName == record.eventName
               && allRecords[i].lastSeenVideoCount != vc {
            allRecords[i].lastSeenVideoCount = vc
            needsSave = true
        }
        if needsSave { GuestUploadRecord.save(allRecords) }
    } catch GuestGalleryError.accessDenied {
        if !silent { errorMessage = "Gallery access could not be verified. The nonce may be invalid." }
    } catch {
        if !silent { errorMessage = error.localizedDescription }
    }
}
```

---

### Testing Checklist — Phase 1

| # | Test | Expected |
|---|------|----------|
| 1 | Open gallery, wait 30s | Console: `[Gallery] loadGallery silent=true` — no spinner visible |
| 2 | Open gallery, approve a second video via admin while watching | Pill appears within 30s without any user action |
| 3 | Pill auto-dismiss | Pill disappears after 8 seconds on its own |
| 4 | Pill `×` tap | Pill dismisses immediately |
| 5 | Scroll to bottom then wait for new video | Pill still visible at top (ZStack overlay, not inside scroll content) |
| 6 | Background app, approve a video, foreground | Approval banner appears on `SplashView` within a few seconds (immediate `.onChange(of: scenePhase)` poll) |
| 7 | Stay on `SplashView` with a pending record, wait 60s | `[Splash] pollGuestRecords` fires; if newly approved, banner appears live |
| 8 | Stay on `SplashView`, video gets rejected within 60s window | Rejection alert appears without restart |
| 9 | Lock phone while gallery is open, unlock | Timer was gated by `scenePhase`; `.onChange` fires one immediate `loadGallery(silent: true)` on unlock |
| 10 | Already-seen videos on gallery open | Pill does NOT appear on first `onAppear` load — `previousVideoCount` initialized from `record.lastSeenVideoCount` before load |
