# Refactor: iOS `AuthCredential` Type — Unify Auth Header Construction

**Date:** 2026-08-12  
**Status:** Complete — `AuthCredential` enum implemented; all call sites updated  
**JWT narrative reconciled:** 2026-09-09  
**Related docs:**
- `policy_authentication_credential_route.md` — authoritative browser-cookie, API/iOS Bearer, QR precedence, response, and atomic-cutover policy
- `feature_security_authentication_migration_jwt.md` — strategic JWT Migration plan
- `feature_security_authentication_migration_jwt_implementation.md` — JWT Migration implementation blueprint

---

## Elevator Pitch

The iOS app currently constructs `Authorization: Basic <base64>` headers in **seven separate places** across six files. Every file that needs to make an authenticated request holds its own copy of the same `"\(user):\(pass)"` → base64 → `"Basic ..."` logic. JWT Migration Phase 3 of the JWT migration requires changing all of those to `Authorization: Bearer <token>` — and without this refactor, that means touching each of those seven sites individually, with a high risk of missing one. This refactor introduces a single `AuthCredential` type that encapsulates the current auth material and owns `Authorization` header production. JWT Migration Phase 3 then becomes: change `AuthSession` to publish `AuthCredential` instead of a credentials tuple, and the network clients pass it through unchanged — `LoginView`, `JWTStore`, and `SplashView` still change in JWT Migration Phase 3, but the five network-client files do not.

---

## Problem

### Seven independent `Basic` header constructions

Every authenticated network client holds its own `(user: String, pass: String)` tuple and encodes it identically:

```swift
let credentials = "\(auth.user):\(auth.pass)"
let base64 = Data(credentials.utf8).base64EncodedString()
request.setValue("Basic \(base64)", forHTTPHeaderField: "Authorization")
```

This pattern appears in:

| File | Location |
|------|----------|
| `DatabaseAPIClient.swift` | `fetchMediaList()` — line ~62 |
| `DatabaseAPIClient.swift` | `deleteMediaFile()` — line ~92 |
| `TUSUploadClient.swift` | `headersBlock` closure — line ~38 |
| `UploadClient.swift` | `buildRequest()` — line ~208 |
| `MediaResourceLoader.swift` | `loadingRequest` handler — line ~72 |
| `MediaPlayerView.swift` | AVURLAsset headers dict — line ~449 |
| `NetworkProgressUploadClient.swift` | request builder — line ~73 |

### `session.credentials` threads through the entire view hierarchy

`AuthSession.credentials: (user: String, pass: String)?` is read by:

- `SplashView` — guards on `credentials == nil`
- `DatabaseView` — passes to `DatabaseAPIClient`
- `DatabaseDetailView` — passes to `MediaPlayerView`
- `MediaPlayerView` — passes to `MediaResourceLoader`; builds AVURLAsset headers
- `UploadView` — passes to `UploadClient` and `DatabaseAPIClient`

Because the tuple is passed raw through the view hierarchy, JWT Migration Phase 3 requires updating each layer's init signature individually.

### Role derived from username string

`AuthSession.role` is currently set in `LoginView.signIn()` by checking `username.lowercased() == "admin"`. This is acknowledged in the code as a temporary hack. JWT Migration Phase 3 replaces this with role decoded from the JWT `role` claim — but this refactor is the right time to cleanly separate `UserRole` from username string matching.

---

## Goal

1. Introduce `AuthCredential` — a type that encapsulates the current auth material and produces the correct `Authorization` header value, regardless of auth scheme.
2. Replace all seven duplicate Basic header constructions with a single call site on `AuthCredential`.
3. Replace `AuthSession.credentials: (user: String, pass: String)?` with `AuthSession.credential: AuthCredential?`.
4. Replace `UserRole` `.admin` with `.owner` and `.contributor` to match the DB enum (JWT Migration Phase 3+ requirement, clean to do here).
5. Replace `KeychainStore`'s `(user, pass)` storage with `JWTStore`-ready groundwork — specifically, make `KeychainStore` aware that it stores a credential, not a raw tuple, so JWT Migration Phase 3's `JWTStore` introduction is additive rather than a rip-and-replace.

---

## Current vs Future Authentication Migration

### Today

Apache validates every account-authenticated request against the htpasswd file. The iOS app sends `Authorization: Basic <base64(user:pass)>`; QR upload requests select `X-Upload-Token` instead. PHP-FPM receives a forwarded Authorization value only on paths configured for application inspection.

### Completed iOS prerequisite (this refactor)

The iOS app still sends the same Basic or QR upload-token wire credential as before this refactor. The completed change replaced seven copies of header construction with the `AuthCredential` enum. It did not activate JWT authentication or change server behavior.

### Future iOS JWT change

The future JWT Migration changes `LoginView` to call the API/iOS login endpoint, which returns a GigHive JWT in JSON and does **not** set the browser HttpOnly JWT cookie. `LoginView` sets `session.credential = .bearer(token: jwt)`. Existing network clients continue calling `credential.apply(...)`, so account-authenticated requests switch centrally from Basic to Bearer while QR uploads continue selecting `.uploadToken` exclusively.

The old plan stated that Apache Basic and PHP Bearer authentication would run simultaneously. That is superseded. A single Authorization header cannot satisfy both schemes. Each environment instead performs an atomic Basic-to-JWT cutover after the server and iOS changes pass in dev, then promotes through sequential gates: dev → lab → staging → production.

### Dependency chain summary

```text
Completed AuthCredential refactor
  → JWT server + iOS Bearer implementation fully validated in dev
  → atomic Basic-to-JWT cutover per environment
  → sequential lab, staging, production promotion gates
```

| Step | iOS account credential | QR upload credential | Server expectation |
|---|---|---|---|
| Before environment cutover | `Basic` | `X-Upload-Token` | Apache Basic for accounts; QR token on guest upload routes |
| After environment cutover | `Bearer` | `X-Upload-Token` | Central JWT route policy; Bearer for iOS accounts; QR token remains authoritative when supplied |

The completed `AuthCredential` refactor remains a prerequisite: it makes the future Basic-to-Bearer switch centralized and preserves the existing exclusive QR upload-token path.

---

## Design

### `AuthCredential`

```swift
/// Encapsulates GigHive authentication material for a single session.
/// Owns Authorization header production — call sites never construct headers directly.
enum AuthCredential {
    /// Legacy HTTP Basic Auth — username + password.
    case basic(user: String, pass: String)
    /// JWT Bearer token issued by GigHive (local login or OIDC exchange).
    case bearer(token: String)
    /// QR event upload token — sent as X-Upload-Token, not Authorization.
    case uploadToken(String)

    /// Returns the value for the `Authorization` header, or nil for uploadToken
    /// (which uses a separate header — see `uploadTokenHeaderValue`).
    var authorizationHeaderValue: String? {
        switch self {
        case .basic(let user, let pass):
            let raw = "\(user):\(pass)"
            return "Basic \(Data(raw.utf8).base64EncodedString())"
        case .bearer(let token):
            return "Bearer \(token)"
        case .uploadToken:
            return nil
        }
    }

    /// Returns the value for the `X-Upload-Token` header, or nil for non-upload-token credentials.
    var uploadTokenHeaderValue: String? {
        if case .uploadToken(let t) = self { return t }
        return nil
    }

    /// Applies the appropriate auth header(s) to a URLRequest in place.
    func apply(to request: inout URLRequest) {
        if let value = authorizationHeaderValue {
            request.setValue(value, forHTTPHeaderField: "Authorization")
        }
        if let value = uploadTokenHeaderValue {
            request.setValue(value, forHTTPHeaderField: "X-Upload-Token")
        }
    }

    /// Applies the appropriate auth header(s) to an AVURLAsset / TUSUploadClient header dict.
    func apply(to headers: inout [String: String]) {
        if let value = authorizationHeaderValue {
            headers["Authorization"] = value
        }
        if let value = uploadTokenHeaderValue {
            headers["X-Upload-Token"] = value
        }
    }

    /// Human-readable identifier for display and logging — username for .basic, "token" for others.
    var displayUser: String? {
        switch self {
        case .basic(let user, _): return user
        case .bearer: return nil   // role/email will be decoded from JWT Migration Phase 3 token
        case .uploadToken: return nil
        }
    }
}
```

**Why `uploadToken` is included:** `TUSUploadClient` and `UploadClient` already have a two-branch `if uploadToken / else basicAuth` pattern. Making `uploadToken` a case on `AuthCredential` means both clients hold a single `credential: AuthCredential?` and call `credential?.apply(to: &request)` — no branching at the call site.

**`UploadClient` / `TUSUploadClient` — credential precedence design decision:** `UploadClient` currently stores **both** `basicAuth` and `uploadToken` as separate fields and applies nil-suppression logic when forwarding to `TUSUploadClient`: `basicAuth: uploadToken == nil ? basicAuth : nil`. This is correct — upload token wins over session credentials; they are orthogonal authorities. When collapsing to `AuthCredential`, `UploadClient` **retains two separate init parameters** rather than a single `AuthCredential`:

```swift
init(baseURL: URL, sessionCredential: AuthCredential?, uploadToken: String? = nil, ...)
```

Internally, `UploadClient` resolves precedence at the point of forwarding to `TUSUploadClient`:

```swift
let effectiveCredential: AuthCredential? = uploadToken.map { .uploadToken($0) } ?? sessionCredential
let tusClient = try TUSUploadClient(tusBaseURL: ..., credential: effectiveCredential, ...)
```

`TUSUploadClient` and `NetworkProgressUploadClient` each receive a single `credential: AuthCredential?` — they never see the original two-parameter split. `UploadView` passes `session.credential` as `sessionCredential` and, for QR guest uploads, passes the raw upload token string from the `GuestUploadRecord` as `uploadToken`. This preserves the existing precedence behaviour and keeps session identity separate from per-upload QR token authority.

**Why not a protocol:** The credential type is a closed set of three cases. An enum is safer than a protocol here — exhaustive switch catches any missed case at compile time when JWT Migration Phase 3 adds `.bearer`.

### `AuthSession` after refactor

```swift
final class AuthSession: ObservableObject {
    @Published var baseURL: URL?
    @Published var credential: AuthCredential?   // replaces credentials: (user:pass:)?
    @Published var allowInsecureTLS: Bool = false
    @Published var role: UserRole = .unknown
    @Published var intendedRoute: AppRoute? = nil
}

enum UserRole { case unknown, viewer, contributor, owner }  // .admin removed, .contributor + .owner added
enum AppRoute { case viewDatabase, upload }
```

### `KeychainStore` after refactor

`KeychainStore` currently stores `{"user": ..., "pass": ...}` JSON. This refactor does not change the on-disk format — that would force a migration. Instead, `KeychainStore.load(host:)` is given a new return type wrapper:

```swift
// New convenience — returns AuthCredential directly
static func loadCredential(host: String) throws -> AuthCredential? {
    guard let pair = try load(host: host) else { return nil }
    return .basic(user: pair.user, pass: pair.pass)
}
```

JWT Migration Phase 3 adds `JWTStore` alongside `KeychainStore` — the two coexist. `KeychainStore` is deprecated for credential storage after JWT Migration Phase 3 ships, but not removed in this refactor.

---

## Files Under Change

| File | Change |
|------|--------|
| `AuthCredential.swift` *(new)* | Introduce `AuthCredential` enum with `authorizationHeaderValue`, `uploadTokenHeaderValue`, `apply(to:)`. |
| `AuthSession.swift` | Replace `credentials: (user: String, pass: String)?` with `credential: AuthCredential?`. Update `UserRole`: remove `.admin`, add `.contributor`, `.owner`. |
| `LoginView.swift` | Replace `session.credentials = (username, password)` with `session.credential = .basic(user: username, pass: password)`. Replace `username == "admin"` role derivation with `.viewer` (role will be server-derived in JWT Migration Phase 3; `.basic` credentials carry no role information). **`onAppear` pre-fill:** `KeychainStore.load(host:)` still returns `(user, pass)` tuple; pre-filling `username` / `password` text fields from the tuple is unchanged — `KeychainStore.load(host:)` is not removed. `loadCredential(host:)` is used where an `AuthCredential` value is needed (e.g. session restore in `SplashView`), not in `LoginView`. |
| `SplashView.swift` | Replace `session.credentials == nil` / `!= nil` guards with `session.credential == nil` / `!= nil`. Replace `creds.user` display string with `session.credential?.displayUser ?? "<unknown>"`. Use `KeychainStore.loadCredential(host:)` for session restore on launch. |
| `DatabaseView.swift` | Replace `basicAuth: session.credentials` with `credential: session.credential`. Replace `session.credentials?.user ?? "<none>"` log string with `session.credential?.displayUser ?? "<none>"`. |
| `DatabaseDetailView.swift` | Replace `credentials: session.credentials` with `credential: session.credential`. |
| `MediaPlayerView.swift` | Replace `let credentials: (user: String, pass: String)?` with `let credential: AuthCredential?`. Two auth paths require changes: (1) **proxy-loader path** — replace `MediaResourceLoader(credentials:)` with `MediaResourceLoader(credential:)`; (2) **direct AVURLAsset path** — replace manual Base64 `headers["Authorization"] = "Basic \(token)"` with `credential?.apply(to: &headers)` using the dict-form overload. Both paths must be updated — missing the AVURLAsset dict path would leave Basic headers on the direct asset route. |
| `MediaResourceLoader.swift` | Replace `init(credentials: (user: String, pass: String)?)` with `init(credential: AuthCredential?)`. Replace manual Base64 construction with `credential?.apply(to: &request)`. |
| `DatabaseAPIClient.swift` | Replace `basicAuth: (user: String, pass: String)?` with `credential: AuthCredential?`. Replace both manual Basic constructions with `credential?.apply(to: &request)`. |
| `TUSUploadClient.swift` | Replace `basicAuth` + `uploadToken` pair with single `credential: AuthCredential?`. In `headersBlock`, replace branching `if uploadToken / else basicAuth` with `credential?.apply(to: &headers)` (adapted for dict form). |
| `UploadClient.swift` | Rename `basicAuth` parameter to `sessionCredential: AuthCredential?`; retain `uploadToken: String?` as a separate parameter (precedence: upload token wins — see design note above). Internally resolve to `effectiveCredential: AuthCredential?` before forwarding to `TUSUploadClient`. Replace manual Basic construction in finalize request with `effectiveCredential?.apply(to: &request)`. |
| `NetworkProgressUploadClient.swift` | Replace `basicAuth: (user: String, pass: String)?` with `credential: AuthCredential?`. Replace manual Basic construction with `credential?.apply(to: &request)`. |
| `UploadView.swift` | Replace `session.credentials` reads with `session.credential`. Pass `sessionCredential: session.credential` to `UploadClient`; continue passing `uploadToken:` from `GuestUploadRecord` as the separate string parameter. Replace `creds.user` display strings with `session.credential?.displayUser ?? "<unknown>"`. |
| `KeychainStore.swift` | Add `loadCredential(host:) -> AuthCredential?` convenience. No change to on-disk format. |

**Unchanged:**
- `QRTokenAPIClient.swift` — does not use Basic Auth; uses QR token path only. No change.
- `GuestUploadView.swift` — QR guest path. No change.
- `JWTStore.swift` — not yet introduced; that is JWT Migration Phase 3.
- All server-side files — this is a pure iOS refactor.

---

## Future iOS JWT Impact After This Refactor

With `AuthCredential` in place, the future iOS JWT change reduces to:

1. `LoginView`: call the API/iOS login endpoint; on success, set `session.credential = .bearer(token: jwt)` and `session.role` from the validated JWT payload. The endpoint returns Bearer JSON and does not set the browser cookie.
2. `JWTStore`: introduced alongside `KeychainStore`; stores `StoredToken`. `LoginView` persists through `JWTStore` instead of `KeychainStore`.
3. `SplashView`: load a non-expired stored JWT and set `session.credential = .bearer(token:)`.
4. `DatabaseAPIClient`, `TUSUploadClient`, `UploadClient`, `MediaResourceLoader`, and `MediaPlayerView`: no authentication-header redesign. They already call `credential.apply(...)`.

This is the core value of the completed refactor: the future iOS JWT diff remains concentrated in `LoginView`, `JWTStore` (new), and `SplashView`. The network clients retain the shared `AuthCredential` path, and `UploadClient` continues making an explicit QR upload token authoritative over an account session credential.

---

## `UserRole` Migration

| Old value | New value | Reason |
|-----------|-----------|--------|
| `.unknown` | `.unknown` | Unchanged |
| `.viewer` | `.viewer` | Unchanged |
| `.admin` | `.owner` | Matches DB enum `owner`; `admin` was a legacy Apache htpasswd concept |
| *(missing)* | `.contributor` | New role from the future JWT payload |

`LoginView` currently sets `.admin` when `username == "admin"`. After this refactor, all `.basic` logins default to `.viewer` — role is not derivable from a username/password pair. The future JWT login flow overwrites `session.role` from the validated JWT `role` claim after a successful API/iOS login response.

Any existing `switch session.role` in the UI that handled `.admin` must be updated to handle `.owner` (and optionally `.contributor`). A compile-time exhaustive switch catches every site.

---

## iOS 14 Compatibility Note

`DatabaseAPIClient` currently uses `URLSession.data(for:)` (async/await), which is iOS 15+. This is a pre-existing issue, not introduced by this refactor. It must be addressed as part of the future iOS JWT work under the iOS 14 constraint documented in `feature_security_authentication_migration_jwt.md` §Modified Files (iOS). This refactor does not add new async URLSession calls and does not make the iOS 14 situation worse.

---

## Test Strategy

| Test | Method |
|------|--------|
| `AuthCredential.basic` produces correct `Authorization: Basic` header | Unit test: `XCTAssertEqual(AuthCredential.basic(user: "a", pass: "b").authorizationHeaderValue, "Basic \(Data("a:b".utf8).base64EncodedString())")` |
| `AuthCredential.bearer` produces correct `Authorization: Bearer` header | Unit test: `XCTAssertEqual(AuthCredential.bearer(token: "tok").authorizationHeaderValue, "Bearer tok")` |
| `AuthCredential.uploadToken` produces nil `authorizationHeaderValue` and non-nil `uploadTokenHeaderValue` | Unit test |
| `apply(to:)` sets correct headers on a `URLRequest` | Unit test for each case |
| `SplashView` guards on `session.credential == nil` correctly | Existing smoke test: unauthenticated app start shows login screen |
| Login with valid credentials → `session.credential == .basic(...)` → DB list loads | Manual / existing smoke test |
| Login with valid credentials → `DatabaseAPIClient` sends `Authorization: Basic ...` | Proxy or network log inspection |
| `UserRole.owner` displayed in UI where `.admin` was previously | UI inspection after login as `admin` username |
| Existing `KeychainStore.load(host:)` callers still compile and return correct tuple | Compile-time verification; no on-disk format change |
| QR upload flow (no credential) — `UploadClient` sends `X-Upload-Token`, no `Authorization` | Existing QR upload smoke test |

**Ansible smoke test:** No server-side change. Existing `post_build_checks` Basic Auth tests are unchanged. No new Ansible tasks required for this refactor.

---

## Risks and Rollback

| Risk | Mitigation |
|------|-----------|
| Missed call site — some file still holds `basicAuth` tuple | Swift compiler enforces it: `session.credentials` no longer exists. Any remaining reference is a compile error. |
| `UserRole.admin` removed — existing `switch` statements break | Exhaustive switch is a compile error. All sites are found at build time. |
| `KeychainStore` on-disk format change causing credential loss on upgrade | No format change. `load(host:)` is unchanged; `loadCredential(host:)` is additive. |
| `TUSUploadClient` `headersBlock` and `MediaPlayerView` AVURLAsset path use `[String: String]` dicts, not `URLRequest` | Resolved in design: `AuthCredential` has a `func apply(to headers: inout [String: String])` overload alongside the `URLRequest` form. Both `TUSUploadClient` and `MediaPlayerView`'s direct AVURLAsset path use the dict overload. |

**Rollback:** This completed change is a pure iOS refactor with no server-side changes. Before an environment's JWT cutover, rollback means reverting the iOS refactor release while that environment remains on Basic Auth. After atomic JWT cutover, this refactor must not be rolled back independently because the server no longer accepts account Basic credentials; use the coordinated environment rollback defined by the canonical route policy and JWT implementation plan.

---

## Remaining Checklist

- [ ] Create `AuthCredential.swift` with enum + `authorizationHeaderValue` + `uploadTokenHeaderValue` + `displayUser` + `apply(to:)` for both `URLRequest` and `[String: String]` dict forms
- [ ] Update `AuthSession.swift` — `credential: AuthCredential?`, `UserRole` enum (remove `.admin`, add `.contributor`, `.owner`)
- [ ] Update `LoginView.swift` — set `session.credential = .basic(...)`, default role to `.viewer`; `onAppear` pre-fill still uses `KeychainStore.load(host:)` tuple directly
- [ ] Update `SplashView.swift` — `credential == nil` guards; `displayUser` for logged-in banner; use `loadCredential(host:)` for session restore
- [ ] Update `DatabaseView.swift` — `credential:` parameter; `displayUser` in log string
- [ ] Update `DatabaseDetailView.swift` — `credential:` parameter
- [ ] Update `MediaPlayerView.swift` — both the proxy-loader path **and** the direct AVURLAsset dict path
- [ ] Update `MediaResourceLoader.swift` — `init(credential:)`; `apply(to: &request)` in request builder
- [ ] Update `DatabaseAPIClient.swift` — both auth sites (`fetchMediaList` and `deleteMediaFile`)
- [ ] Update `TUSUploadClient.swift` — single `credential: AuthCredential?`; `apply(to: &headers)` dict-form in `headersBlock`
- [ ] Update `UploadClient.swift` — `sessionCredential: AuthCredential?` + retain `uploadToken: String?`; internal precedence logic; `apply(to: &request)` for finalize
- [ ] Update `NetworkProgressUploadClient.swift` — `credential: AuthCredential?`; `apply(to: &request)`
- [ ] Update `UploadView.swift` — `session.credential`; `displayUser` for logged-in banner; `sessionCredential:` + `uploadToken:` separate args to `UploadClient`
- [ ] Add `KeychainStore.loadCredential(host:)` convenience
- [ ] Fix all `UserRole.admin` → `.owner` switch arms across UI files (compile-time exhaustive switch will surface all)
- [ ] Unit tests for `AuthCredential`: `basic` header value, `bearer` header value, `uploadToken` nil auth + non-nil X-Upload-Token, `apply(to: URLRequest)`, `apply(to: [String:String])`, `displayUser`
- [ ] Build on device (iPhone 12, iOS 14 simulator minimum) — zero compile errors
- [ ] Run existing QR upload smoke test — `UploadClient` sends `X-Upload-Token`, no `Authorization`
- [ ] Run existing login + DB view smoke test — `DatabaseAPIClient` sends `Authorization: Basic ...`
- [ ] Verify `MediaPlayerView` proxy-loader AND direct AVURLAsset paths both send correct auth header

---

## PPRR Findings and Corrections

| ID | Severity | Category | Finding | Correction applied |
|----|----------|----------|---------|-------------------|
| R1 | High | Logic/Accuracy | Elevator pitch and goal §2 said "six separate places across five files." The problem table listed 7 rows across 6 files (`DatabaseAPIClient` ×2, `TUSUploadClient`, `UploadClient`, `MediaResourceLoader`, `MediaPlayerView`, `NetworkProgressUploadClient`). Numbers were internally inconsistent. | Updated elevator pitch, section heading, and goal §2 to "seven separate places across six files." |
| R2 | High | Logic | `UploadClient` holds **both** `basicAuth` and `uploadToken` as separate stored properties and applies precedence logic (`uploadToken == nil ? basicAuth : nil`) when forwarding to `TUSUploadClient`. Session identity and per-upload QR token authority are orthogonal; collapsing them into a single `AuthCredential` at the `UploadView` call site would entangle them. The plan said "Pass `.basic(...)` or `.uploadToken(...)` as appropriate" without explaining how the caller would know which to pass for an in-progress upload. | Added explicit design decision: `UploadClient` retains two separate parameters (`sessionCredential: AuthCredential?` + `uploadToken: String?`). Precedence is resolved internally to `effectiveCredential` before forwarding to `TUSUploadClient`. Swift code snippet added to design section. `UploadClient` and `UploadView` Files Under Change rows updated accordingly. |
| R3 | Medium | Completeness | `LoginView.onAppear` calls `KeychainStore.load(host:)` twice and extracts `.user` / `.pass` to pre-fill text fields. The `LoginView` row in Files Under Change said to use `loadCredential(host:)` for Keychain access — but `loadCredential` returns `AuthCredential`, not a `(user, pass)` tuple, and can't pre-fill the text fields. `KeychainStore.load(host:)` must remain available for this path. | Corrected `LoginView` row: `onAppear` pre-fill still uses `KeychainStore.load(host:)` returning the tuple directly. `loadCredential(host:)` is used only for session restore in `SplashView`. |
| R4 | Medium | Completeness | `SplashView` (line 50), `UploadView` (line 192), and `DatabaseView` (line 91 log string) all access `session.credentials?.user` for display or logging. The doc only mentioned a `displayUser` property for `SplashView`. `UploadView` had no mention of the display string change; `DatabaseView` listed only the `credential:` parameter change, not the log string. `AuthCredential` had no `displayUser` property defined. | Added `displayUser: String?` computed property to `AuthCredential` design snippet. Updated `SplashView`, `DatabaseView`, and `UploadView` rows in Files Under Change to cover `displayUser` usage. |
| R5 | Medium | Logic | `MediaPlayerView` has **two** auth paths: (1) proxy-loader path passes `credentials` to `MediaResourceLoader`; (2) direct `AVURLAsset` path builds `headers["Authorization"]` as a `[String: String]` dict. The Files Under Change row mentioned replacing the proxy-loader path but not the AVURLAsset dict path. The dict-form `apply(to: inout [String: String])` overload was noted in the Risks table but not connected to `MediaPlayerView` in the change description, creating a gap that could cause the AVURLAsset path to be missed during implementation. | Updated `MediaPlayerView` change description to explicitly name both paths. Moved dict-form overload from Risks to the `AuthCredential` design snippet. Risks table updated to reflect this is resolved in design, not deferred. Added verification step for both paths to the test checklist. |
| R6 | Low | Completeness | Remaining Checklist was missing `UploadView.swift`. Also missing: the `displayUser` property addition to `AuthCredential`, and the `MediaPlayerView` dual-path verification test. | Updated checklist: added `UploadView.swift` entry; added `displayUser` to `AuthCredential.swift` item; added explicit `MediaPlayerView` dual-path smoke test. |
| R7 | Low | Accuracy | Elevator pitch said "JWT Migration Phase 3 then becomes: change `AuthSession`… and every call site passes it through unchanged" — which overstated the benefit. `LoginView`, `JWTStore`, and `SplashView` still change in JWT Migration Phase 3. | Corrected elevator pitch to state that network clients pass `AuthCredential` through unchanged; `LoginView`, `JWTStore`, and `SplashView` still change. JWT Migration Phase 3 Impact section closing line updated to name the 3 remaining files explicitly. |
