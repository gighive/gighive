# Refactor: Hide Login/Database Buttons for QR Code Guest Users on SplashView

## Status — 2026-08-15 — COMPLETE

Fully implemented and tested on device. `isGuestOnly` computed property added to `SplashView`; login section wrapped in `if !isGuestOnly { }`.

---

## Rationale

After a QR code guest uploads a video, they return to `SplashView`. Currently the
view always renders the orange login notification text and three action buttons
(Login, View the Database, Upload a File) regardless of whether the user has any
relationship to the GigHive database as an admin/viewer/uploader.

This creates confusion: the guest just submitted a video and is waiting for approval.
Seeing "Please login first" and a Login button implies there is something they must do
next — when in reality they have nothing to do but wait. The phrase "Login for full
database and upload access" is equally confusing to someone who has no GigHive
account and never will.

A QR code guest's only relationship with GigHive is through the token they received
by scanning the event QR code. They should see only the content relevant to that
relationship: the submission acknowledgment, any approval notifications, and their
event galleries.

---

## Goal

Remove the orange login-prompt text block and the three login/database/upload buttons
from `SplashView` whenever the device has at least one `GuestUploadRecord` on file
and the user is not authenticated. A QR code guest's post-upload view reduces to:

- Bee logo + "Gighive" title
- "Video submitted!" acknowledgment card (if applicable)
- "Your video has been accepted!" approval banner (if applicable)
- "Your Event Galleries" navigation rows (if applicable)
- Version string (always)

The buttons remain visible in two cases:
1. `session.credentials != nil` — an authenticated admin or viewer is using the app.
2. `uploadRecords.isEmpty` — a brand new user has never scanned a QR code, so the
   Login button must remain as their only path into the app.

---

## Industry Precedent

The "token-scoped UI" pattern — where a shared link or QR code grants a scoped role and
the interface contracts to that role — is standard in the event and media-sharing category:

| Product | Entry mechanism | What the recipient sees |
|---------|-----------------|------------------------|
| Eventbrite / Bizzabo | QR ticket scan | Attendee info; not the organizer dashboard |
| Fotaflo / Pixieset | Share link | Gallery; not uploader account settings |
| WeTransfer / Dropbox shared links | Recipient link | Download button; not the full account UI |
| Google Photos shared album | Link | Album photos; not the full Google account surface |

The invariant: **possession of a token grants a scoped role; the UI contracts to that scope.**
Exposing admin/login affordances to a token holder is a UX anti-pattern in this product category.

---

## Real World Use Cases

**Wedding guest.** Scans a QR code on a table card, uploads a clip of the couple's first
dance, and returns to the app the next day to check approval status. Seeing "Please login
first" and a "View the Database" button implies they did something wrong or have more steps
to take. They don't. They should see only the submission acknowledgment.

**Concert-goer.** Scans a QR code on the stage backdrop, uploads a fan clip mid-show.
Reopens the app after the show to see if it was approved. The GigHive login portal is
irrelevant noise — they have no account and never will.

**Corporate event videographer.** Submits footage via QR code on behalf of a client.
Checks back later for the gallery link. The login section suggests they need a GigHive
account to access their own submission. They don't.

In all three cases, the presence of the login section actively works against the user's
confidence that their submission was received correctly.

---

## Decision

Hide the orange login notification text block and all three action buttons (`Login`,
`View the Database`, `Upload a File`) when `session.credentials == nil` AND
`uploadRecords` is non-empty. Implement as a single `private var isGuestOnly: Bool`
computed property on `SplashView`, wrapping the four affected elements in
`if !isGuestOnly { }`. No backend, no DB, no Ansible changes.

---

## Design Principles

- No backend changes. This is pure presentation logic gated on existing local state.
- No new state variables. The condition is derivable from `session.credentials`
  and `uploadRecords`, both already loaded on `.onAppear`.
- iOS 14 compatible. The change is a conditional `if` block in a `@ViewBuilder`
  body — no new modifiers or APIs required.
- The four hidden `NavigationLink` stubs (`goToLogin`, `goToUpload`, `goToGuestUpload`,
  `goToBannerGallery`) remain in the hierarchy unconditionally; they are zero-size
  and hidden, and removing them could break programmatic navigation.

---

## Current State

`SplashView.swift` declares `@State private var uploadRecords: [GuestUploadRecord] = []`
and populates it in `.onAppear`. The login section — the orange notification `VStack` and
three buttons (`Login`, `View the Database`, `Upload a File`) — is rendered unconditionally
in the `@ViewBuilder` body with no guard based on whether the user is a QR guest or a
database user.

The two state sources relevant to this change:
- `session.credentials` — `nil` for any unauthenticated user (guests and fresh installs alike)
- `uploadRecords` — empty `[]` at init; loaded synchronously from UserDefaults in `.onAppear`

---

## Proposed Implementation

### Files Under Change (new/modified)

| File | Repo | Change |
|------|------|--------|
| `GigHive/Sources/App/SplashView.swift` | gighiveapp | Wrap login section in guest-detection guard |

No PHP, Ansible, or database changes.

---

### Gating condition

```swift
private var isGuestOnly: Bool {
    session.credentials == nil && !uploadRecords.isEmpty
}
```

Add this computed property to `SplashView`. It is `true` when the device has QR
guest records but no authenticated session — i.e., the user is purely a QR code
guest.

### SplashView body — wrap the login section

The four elements currently rendered unconditionally:

1. The `VStack(alignment: .leading, spacing: 4)` containing the orange notification
   text (`if let creds = session.credentials { … } else if … { … } else { … }`)
2. The `Button("Login") { … }` button
3. The `if session.credentials != nil { NavigationLink("View the Database") } else { Button("View the Database") }` block
4. The `Button("Upload a File") { … }` button

Wrap all four in:

```swift
if !isGuestOnly {
    // orange notification text block
    // Login button
    // View the Database button / NavigationLink
    // Upload a File button
}
```

No other structural changes. The `NavigationLink` stubs for `goToLogin`,
`goToUpload`, `goToGuestUpload`, and `goToBannerGallery` remain outside this guard
for structural integrity. The two stubs most relevant to this change (`goToLogin`,
`goToUpload`) will never fire during a guest-only session since all their call sites
are inside the guard — but removing any stub would break compilation and forward
compatibility.

### SonarQube / Swift Best-Practice Notes (iOS)

- **Computed property in a View struct** (`isGuestOnly`) evaluates on every SwiftUI render
  pass. The expression is two nil/empty checks — trivially cheap; no performance concern.
- **No force unwraps.** `session.credentials` is `Optional`; the `== nil` check is
  safe (RSPEC-6426).
- **`uploadRecords` initialization timing.** Declared `= []` and populated in `.onAppear`.
  On the first render frame before `.onAppear` fires, `isGuestOnly` is `false` — the login
  section briefly appears for a returning QR guest. In practice imperceptible, but if
  observed the fix is pre-loading: `@State private var uploadRecords = GuestUploadRecord.load()`
  (UserDefaults read is synchronous and main-thread safe). Deferred to a follow-on.
- **No new state, environment objects, or side effects introduced.** `isGuestOnly` is a
  pure read of already-observable state.

---

## Files to Change

| File | Repo | Change |
|------|------|--------|
| `GigHive/Sources/App/SplashView.swift` | gighiveapp | Wrap login section in guest-detection guard |

---

## Wireframe

### QR Guest View (post-upload, pending approval)

**Before**

```
🐝  Gighive

Login for full database and upload access     ← orange (confusing)

[ Login ]                                     ← confusing
[ View the Database ]                         ← confusing
[ Upload a File ]                             ← confusing

┌──────────────────────────────────────────┐
│  Video submitted!                         │
│  Your video is in the moderation queue…  │
│  [Got it]                                 │
└──────────────────────────────────────────┘
```

**After**

```
🐝  Gighive

┌──────────────────────────────────────────┐
│  Video submitted!                         │
│  Your video is in the moderation queue…  │
│  [Got it]                                 │
└──────────────────────────────────────────┘
```

---

### Authenticated Admin/Viewer (unchanged)

```
🐝  Gighive

User is logged into https://… as admin       ← unchanged

[ Login ]
[ View the Database ]
[ Upload a File ]
```

---

### Brand New User (no records, no login) — unchanged

```
🐝  Gighive

Please login first                           ← unchanged
You will be able to View the Database…

[ Login ]
[ View the Database ]
[ Upload a File ]
```

---

## Edge Cases

| Scenario | `isGuestOnly` | Result |
|----------|---------------|--------|
| No records, not logged in (brand new user) | `false` (`uploadRecords.isEmpty`) | Login section visible — correct |
| Has records, not logged in (QR guest) | `true` | Login section hidden — correct |
| Has records, logged in (admin who also scanned a QR) | `false` (`credentials != nil`) | Login section visible — correct |
| No records, logged in (admin, no QR activity) | `false` | Login section visible — correct |

---

## Testing Checklist

- [ ] Fresh install: Login/ViewDatabase/UploadFile buttons visible
- [ ] After QR scan + upload (guestSession cleared): buttons hidden; "Video submitted!" card visible
- [ ] After QR guest approval: buttons hidden; approval banner + gallery row visible
- [ ] After login as admin/viewer: buttons visible regardless of upload records
- [ ] Logout (session.credentials set to nil) with existing records: buttons hidden — **known edge case; see Known Limitations**
- [ ] NavigationLink stubs remain functional: test in a fresh-install or authenticated-user scenario (not guest-only — `goToLogin` is never set inside the guard, so it cannot be triggered in that state)

---

## Known Limitations

**Admin logout with QR records on device.** An admin or viewer who logs out while the
device also holds `GuestUploadRecord` entries (e.g., because they tested the guest upload
flow) will see `isGuestOnly` flip to `true` after logout, hiding the Login button. There
is then no in-app path to log back in.

**Assessment:** Accepted for v1. The overlap of "authenticated user who also has QR records"
is a developer/testing scenario; real-world QR guests never hold credentials. If this
becomes an observed UX problem, the mitigation is a small unconditional "Admin login" text
link at the bottom of `SplashView`, independent of `isGuestOnly`.

---

## Progress

### Completed
- [x] Add `isGuestOnly` computed property to `SplashView`
- [x] Wrap login section (orange text block + 3 buttons) in `if !isGuestOnly { }`

### Completed — This Feature
- [x] Run Testing Checklist on device
- [x] Update Status to Complete after verification

### Remaining — Follow-on Tasks
- Consider pre-loading `uploadRecords` in `@State` initializer to eliminate the one-frame
  flash (see SonarQube notes).
- Consider unconditional "Admin login" escape hatch if the admin-logout edge case is
  observed in practice.
