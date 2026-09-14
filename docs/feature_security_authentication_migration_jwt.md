# Feature: Federated Authentication Migration (JWT + OIDC)

## Status — 2026-09-09
Strategic plan — reconciled to the canonical browser-cookie, API/iOS Bearer, centralized route-class, atomic-cutover, and sequential-promotion policy. Implementation remains pending explicit approval.

**Canonical policy:** `docs/policy_authentication_credential_route.md`  
**Implementation:** `docs/feature_security_authentication_migration_jwt_implementation.md`  
**Endpoint inventory:** `docs/feature_security_authentication_migration_jwt_endpoint_guard_checklist.md`

---

## Elevator Pitch

GigHive is replacing three shared installation passwords with individual identities that can be assigned a role, restricted to a tenant or event, audited, and revoked without disrupting everyone else. Browser users receive a secure HttpOnly JWT cookie, while iOS and programmatic clients use Bearer JWTs. Anonymous QR upload and gallery access remain accountless and event-scoped under an explicit route policy.

---

## Summary

GigHive's move to SaaS requires replacing shared Apache Basic Auth accounts with individual, auditable identities while preserving self-hosted operation and QR guest access. The migration introduces one GigHive JWT claims model through client-appropriate transports:

- **Browser:** Secure HttpOnly GigHive JWT cookie; no JWT in localStorage or sessionStorage.
- **iOS/API:** `Authorization: Bearer <JWT>`.
- **QR guest:** Explicit upload token or gallery nonce, restricted to its event capability.

A centralized route-class policy determines accepted credentials, precedence, CSRF requirements, tenant/event scope, and HTML/JSON/download/media failure behavior. QR and account credentials are distinct authorities but can arrive on the same request; an explicit guest credential remains authoritative on guest routes and never silently falls back to broader account access.

**The four customer journeys:**

| Journey | Final authentication model | Status |
|---|---|---|
| QR Event Goer | Event-scoped upload token or gallery nonce; no account | Existing behavior preserved under explicit route class |
| Media Library Viewer | Individual OIDC/local identity; browser cookie or iOS/API Bearer | To build |
| Media Uploader / Event Planner | Individual contributor/owner identity plus separate QR guest capabilities | To build |
| Local/Platform Administrator | Individual owner/platform-admin identity; IdP MFA where available | To build |

**JWT Migration phases:**

| Phase | What | Gate |
|---:|---|---|
| 0 | Completed iOS `AuthCredential` refactor plus Web Refactor Phase 1 prerequisite | No wire-auth change; Basic remains authoritative |
| 1 | JWT core, users-table alignment, API/iOS token login, browser cookie-login foundation | Additive/inert while environment remains in Basic mode |
| 2 | Central route-class resolver, PHP guards, cookie/CSRF/response helpers, internal-route denial | Deploy inert under Basic; do not require JWT while Apache Basic is authoritative |
| 3 | iOS Bearer client and browser-session behavior; complete tests in dev | Must be ready before that environment's cutover |
| 4 | Atomic Basic-to-JWT cutover per environment | Dev passes first; then lab → staging → production, each gated by tests |
| 5 | OIDC: Google + Microsoft Entra ID; browser callback issues GigHive cookie, iOS uses PKCE/Bearer | Requires Phase 4 policy and routes |
| 6 | User management, audit, and account lifecycle | Requires identity and tenant scope |

**Confirmed decisions:**

- HttpOnly JWT cookie for browser authentication.
- Bearer JWT for iOS and programmatic API clients.
- Centralized route classes and no invalid-explicit-credential fallback.
- CSRF protection for cookie-authenticated unsafe requests.
- Atomic Basic-to-JWT cutover inside each environment; no Basic/JWT overlap period.
- Sequential promotion gates: dev → lab → staging → production.
- Google and Microsoft Entra ID as primary OIDC providers.
- HS256 for GigHive-issued JWTs; IdP tokens validated server-side against provider JWKS.
- Local break-glass owner retained.

**Implementation decisions still open:** JWT lifetime/renewal, immediate revocation mechanism, CSRF token design, exact resolver API, stable error codes, and key-rotation window. The canonical policy owns these decisions.

---

## Context and Rationale

### Why Now

GigHive is moving toward a SaaS deployment model. The current authentication system — Apache HTTP Basic Auth with shared `admin`, `uploader`, `viewer`, and optional `guest` htpasswd accounts — was the correct pragmatic choice for a single-tenant, self-hosted appliance. It is the wrong model for SaaS, where each user is an individual with their own identity, where organizations use SSO for their tools, and where audit trails matter.

At the same time, QR-code guest upload and gallery access is deliberately accountless. It serves an event attendee who should not need a GigHive account. Its product behavior remains, but its interaction with incidental browser cookies and authenticated media routes must be made explicit through the centralized route-class policy.

The key insight driving this plan is that account identity and guest capability are different authorities that can coexist on a request. Route intent determines which is authoritative: an explicit valid QR token/nonce controls a guest flow, while authenticated browser/API routes use the JWT identity and role.

No existing customers or sessions require preservation during migration, so each environment can switch atomically after dev validation rather than running incompatible Basic and JWT authorization simultaneously.

### Prior Plans This Supersedes (Partially)

- **`security_auth_jwt_token_migration.md`** — Historical and superseded. Its old role names, endpoint list, phases, and dual-auth transition must not be implemented.
- **`refactor_security.md`** *(deleted — superseded by this doc)* — The auth-mode concept remains, but its transition semantics are replaced by the canonical atomic-cutover policy.
- **`refactor_security_recommendations_20260530.md`** — Bundle D (JWT) and Bundle E (OIDC) from that document map directly to Phases 1–4 and Phase 5 of this feature respectively.

---

## Role Name Mapping

The Apache htpasswd layer and the existing `users` table in `create_media_db.sql` use different naming conventions. This feature resolves them to the `users.role` enum already defined in the DB schema:

| Apache htpasswd user | GigHive DB role (canonical) | Meaning |
|---------------------|----------------------------|---------|
| `admin` | `owner` | Full control: events, gallery, users, admin UI |
| `uploader` | `contributor` | Upload + view access |
| `viewer` | `viewer` | Read-only access |
| — | `superadmin` | Reserved for GigHive platform operators; not part of this migration |

All PHP role checks, JWT payloads, and API responses use the DB-side names (`owner`, `contributor`, `viewer`, and the SaaS extension `platform_admin`). Old Apache usernames are used only while an environment remains fully in pre-cutover Basic mode; they are removed from request authentication during that environment's atomic JWT Migration Phase 4 cutover.

---

## The Four Customer Journeys

### Journey 1 — QR Event Goer (upload + gallery, no account)

**Persona:** Concert attendee, wedding guest, corporate event participant. Scans a printed QR code at the venue.

**Auth today:** QR token (43-char URL-safe base64, SHA-256 hash stored in `event_upload_tokens`). Entirely accountless. Device-local identity via `GuestUploadRecord` in iOS UserDefaults.

**Auth after this feature:** Unchanged. QR tokens are the correct, complete auth model for this persona.

**OIDC applicability:** None. Forcing an identity provider login on a concert attendee to submit a video clip is a non-starter UX and defeats the purpose of the feature.

**Invariant:** QR guest access remains accountless and event-scoped. Migration may centralize its credential resolution and tests, but must not require an account or allow an incidental authenticated cookie to broaden guest access.

---

### Journey 2 — Media Library Viewer (browse-only, with account)

**Persona:** Media librarian, band member, wedding videography client who needs ongoing access to the curated catalog.

**Auth today:** Shared `viewer` htpasswd credential. The iOS app's `LoginView` sends username + password. Role is derived from the string `"admin"` vs. anything else — acknowledged in the code as a temporary hack.

**Auth after this feature:** Individual identity. The IdP (Google or Microsoft) authenticates the user; GigHive maps their IdP group or email domain to the `viewer` DB role.

**Why OIDC matters here:** In SaaS, a viewer is an individual, not a shared account. Organizations using Google Workspace or Microsoft 365 can grant library access by adding a user to a group in their IdP — no GigHive admin interaction required.

---

### Journey 3 — Media Uploader / Band/Event Planner (upload + view, with account)

**Persona:** Videographer, band manager, musician ingesting media into the library.

**Auth today:** Shared `uploader` or `admin` htpasswd credential. iOS app sends Basic Auth. `TUSUploadClient` sends `Authorization: Basic ...` header (or `X-Upload-Token` for QR guest uploads — the distinction is already handled in the client and unchanged by this feature).

**Auth after this feature:** Individual identity with `contributor` DB role from IdP group membership. iOS app sends `Authorization: Bearer <jwt>` to both REST endpoints and the TUS upload endpoint.

---

### Journey 4 — Administrator (full control)

**Persona:** In the current single-tenant deployment: the platform operator. In the SaaS model: the tenant owner — the person who wants to send a QR code to their fans, manage their media library, and control who has access. Has access to `/admin/*`.

**Auth today:** Shared `admin` htpasswd credential. Highest-privilege account; one password shared by all operators.

**Auth after this feature:** Individual identity with `owner` DB role from IdP group. MFA enforced at the IdP level. Each admin's actions are attributable to a specific identity — critical for audit and incident response.

**Admin functions (all gated by `requireRole('owner')`):**

- **User management** (`admin/users.php`) — list all OIDC-provisioned users for the tenant; change a user's role; disable or re-enable a user; delete a user row. No local user creation in the UI — all users are provisioned via OIDC. The break-glass `owner` account is seeded by Ansible only.
- **QR code management** (`admin/event_qr.php`) — generate event-scoped QR tokens, set expiry, and view active tokens. Guest capability behavior remains accountless; route policy ensures an incidental admin cookie cannot broaden a QR request.
- **Media moderation** (`admin/admin.php` and related pages) — approve/reject uploaded media, manage the catalog, promote items, trigger AI jobs.
- **Database administration** (`admin/admin_system.php`, `admin/import_*.php`, etc.) — import/export, backup/restore, clear media.
- **Security audit log** (`admin/users.php`, audit tab) — owner can read the `security_audit_log` table for the tenant: login events, role changes, failed auth attempts, account disable/enable, user deletes. The audit log is a second tab within `admin/users.php` — no separate page.

**Admin → OIDC → QR chain:** The admin authenticates through OIDC or the local break-glass path and receives the browser HttpOnly GigHive JWT cookie. From the admin UI they generate event-scoped QR capabilities. A browser can later send both that cookie and a QR token/nonce; the centralized route class makes the explicit guest credential authoritative and preserves event scope.

---

## Architecture: One JWT Identity Model, Explicit Route Classes

```text
Browser ── HttpOnly JWT cookie ─────────────┐
iOS/API ─ Authorization: Bearer JWT ────────┼─> Central credential resolver
QR upload ─ X-Upload-Token / route token ───┤      ├─ route class
QR gallery ─ nonce ─────────────────────────┘      ├─ normalized identity/scope
                                                   └─ requireRole + tenant/event checks
```

The route classes are `PUBLIC`, `LOGIN`, `HTML_PAGE`, `AUTHENTICATED_API`, `AUTHENTICATED_DOWNLOAD`, `GUEST_UPLOAD`, `GUEST_GALLERY`, `MIXED_MEDIA`, `DUAL_RESPONSE`, `INTERNAL_WORKER`, and `INTERNAL_LIBRARY`.

A credential is not selected merely because it is more privileged. Route intent controls precedence. An explicit guest token/nonce remains authoritative on guest and mixed routes; an invalid explicit credential fails rather than falling back to a broader cookie or Bearer identity. Full policy: `docs/policy_authentication_credential_route.md`.

---

## 1. Architectural Impact

### Server (PHP / Apache)

**Current:** Apache Basic Auth protects most account routes. Guest exceptions and `media-stream.php` already contain endpoint-specific token/nonce handling.

**Before an environment cuts over:** JWT classes, route declarations, cookie/CSRF helpers, and client changes may be deployed inert while `GIGHIVE_AUTH_MODE=basic`; Apache remains the sole account-authentication gate.

**At that environment's JWT Migration Phase 4 cutover:** one reviewed deployment atomically activates application JWT policy and removes Apache Basic Auth. PHP becomes authoritative for browser-cookie, API/iOS Bearer, QR capability, role, tenant, event, response, and CSRF behavior. Apache retains direct denials, routing, limits, and Authorization forwarding.

**OIDC:** Browser OIDC callback issues the canonical GigHive HttpOnly JWT cookie. iOS uses OIDC authorization code + PKCE and receives Bearer JSON. OIDC does not create a second application-authorization layer.

### `GIGHIVE_AUTH_MODE` and Atomic Cutover

```text
GIGHIVE_AUTH_MODE=basic   # environment remains entirely on Apache Basic account auth
GIGHIVE_AUTH_MODE=local   # atomic cutover complete; local browser cookie + API/iOS Bearer active
GIGHIVE_AUTH_MODE=oidc    # OIDC login available; local break-glass login retained
```

The PHP route guards must remain inert in `basic` mode. An environment changes from `basic` to `local` in the same deployment that removes Apache Basic account-auth directives. It does not run Apache Basic and PHP JWT as simultaneous account-auth requirements.

### Media, Upload, Download, and Internal Routes

- **`media-stream.php`:** `MIXED_MEDIA`. Explicit gallery nonce/upload token is authoritative when supplied; otherwise explicit Bearer, then browser cookie. Invalid explicit credentials do not fall back. Range and media error behavior are preserved.
- **`tus-upload.php` and upload finalization:** `GUEST_UPLOAD` when an upload token is supplied; otherwise authenticated Bearer/cookie upload with contributor-or-higher role and CSRF where cookie-authenticated.
- **Downloads:** `AUTHENTICATED_DOWNLOAD`; browser cookie or explicit Bearer, with no HTML login substitution in a binary response.
- **CLI workers:** `INTERNAL_WORKER`; direct HTTP denied rather than requiring a browser JWT. `import_manifest_worker.php` needs the explicit CLI guard used by the other workers.
- **Include-only code:** `INTERNAL_LIBRARY`; direct HTTP denied or moved outside the webroot.

Endpoint code must call the shared route resolver rather than duplicate Bearer/token/nonce parsing.

### iOS App — Full Call-Site Chain

> **Completed Phase 0 prerequisite:** The `AuthCredential` refactor is complete and documented in `feature_completed_security_authentication_migration_jwt_ios_auth_cred_type.md`. It centralizes Basic/Bearer/upload-token header construction. Future iOS JWT work remains concentrated in login, token storage, and session restoration; network clients retain `AuthCredential.apply(...)` and QR upload-token exclusivity.

The `credentials: (user: String, pass: String)?` tuple flows through multiple files. All must change (Phase 0 reduces this to ~3 files):

| File | Current | After Phase 3 |
|------|---------|--------------|
| `AuthSession.swift` | `@Published var credentials: (user: String, pass: String)?` | `@Published var token: String?`; `@Published var expiresAt: Date?`; role decoded from JWT |
| `LoginView.swift` | Sets `session.credentials`; derives role from username | Calls `POST /api/login.php`; sets `session.token` via `JWTStore` |
| `SplashView.swift` | Guards on `session.credentials == nil` | Guards on `session.token == nil` |
| `DatabaseView.swift` | Passes `session.credentials` to `DatabaseAPIClient` | Passes `session.token` as Bearer header |
| `DatabaseDetailView.swift` | Passes `session.credentials` to `MediaPlayerView` | Passes `session.token` |
| `MediaPlayerView.swift` | Holds `let credentials: (user: String, pass: String)?`; sets `Authorization: Basic ...` directly; instantiates `MediaResourceLoader(credentials:)` | Holds `let token: String?`; sets `Authorization: Bearer ...`; instantiates `MediaResourceLoader(token:)` |
| `MediaResourceLoader.swift` | `init(credentials: (user: String, pass: String)?)`, sets `Basic` header | `init(token: String?)`, sets `Bearer` header |
| `DatabaseAPIClient.swift` | `init(basicAuth:)` builds `Authorization: Basic ...` | `init(bearerToken:)` builds `Authorization: Bearer ...` |
| `TUSUploadClient.swift` | `basicAuth` branch builds `Authorization: Basic ...`; `uploadToken` branch unchanged | `bearerToken` branch builds `Authorization: Bearer ...`; `uploadToken` branch unchanged |
| `KeychainStore.swift` | Stores `{"user":..., "pass":...}` | Deprecated for credential storage; replaced by `JWTStore.swift` |

---

## 2. Files and Modules Likely to Change

### New Files (Server — `ansible/roles/docker/files/apache/webroot/`)

| File | Purpose |
|------|---------|
| `auth/jwt.php` | JWT generation and validation. `JwtAuth::generate(int $userId, string $role, string $email, int $ttl = 0): string`. `JwtAuth::validate(string $token): ?array` returns payload array or null. `JwtAuth::validateWithReason(string $token): array` returns `[$payload, null]` or `[null, 'token_expired'|'invalid_token']`. Algorithm: HS256 throughout all phases. Role values in payload: `owner`, `contributor`, `viewer`. |
| `auth/helpers.php` | Central route-class resolver and authorization helpers. Resolves browser cookie, explicit Bearer, QR upload token, or gallery nonce according to declared route policy; returns normalized identity/scope; applies role/tenant/event checks and response-type behavior. |
| `api/login.php` | API/iOS local-user credential exchange. Returns Bearer JWT JSON and does not set the browser cookie. |
| `api/verify.php` | API/iOS Bearer verification with stable expired/invalid failure codes. |
| `auth/login.php` | Browser login page; never receives or stores JWT in JavaScript. |
| Browser session endpoint *(exact path finalized in implementation guide)* | Validates local browser credentials and sets the Secure HttpOnly GigHive JWT cookie; does not return JWT to JavaScript. |
| Browser logout endpoint *(exact path finalized in implementation guide)* | CSRF-protected cookie clearing using identical cookie attributes. |
| CSRF helper *(exact file finalized in implementation guide)* | Central token generation/validation for cookie-authenticated unsafe browser requests. |
| `auth/oidc.php` | Phase 5. `OidcProvider` class: discovery, JWKS fetch, `id_token` validation. `OidcRoleMapper` class: maps IdP group claims to `owner`/`contributor`/`viewer` using `OIDC_ROLE_MAP_JSON`. Used by both callback paths. |
| `api/oidc/callback.php` | Phase 5. Browser OIDC authorization code callback. Apache `mod_auth_openidc` exchanges the code and exposes claims as `OIDC_CLAIM_*` env vars; this PHP script reads those claims, upserts the `users` row with `idp_provider` + `idp_subject`, generates a GigHive JWT, and redirects the browser. |
| `api/oidc/token-exchange.php` | Phase 5. iOS PKCE token exchange — accepts `{code, code_verifier, redirect_uri, provider}`; exchanges code with the IdP, validates the `id_token` against JWKS, upserts `users`, returns a GigHive JWT. Keeps OIDC client secrets server-side. `provider` is `"google"` or `"microsoft"`. |
| `api/oidc/config.php` | Phase 5. Public endpoint returning OIDC client IDs and the redirect URI for iOS. Allows the app to fetch provider configuration at runtime rather than embedding it in the bundle. |
| `admin/users.php` | Phase 6 (post-OIDC). Owner-only user management UI. Lists all OIDC-provisioned users for the tenant; allows role change, disable/enable, and user row delete. Audit log displayed as a second tab. No local-user creation — all users are provisioned via OIDC. Uses existing `db/database.php` PDO helper and `config.php` constants — no new DB connection logic. See `feature_security_admin_user_management.md` (planned). |
| `api/account/delete.php` *(new)* | Phase 6. Self-service account deletion endpoint. Accepts `DELETE` with a valid JWT (any role except `superadmin`). Immediately hard-deletes the caller's `users` row. Writes a `self_account_deleted` event to `security_audit_log`. Special case: if the caller is the tenant's last `owner`, returns 409 with `last_owner_cannot_delete` — they must transfer ownership first or contact the platform superadmin. Owner self-delete (not last owner) is allowed after a confirmation step in the UI; a `superadmin_notified` detail is recorded in the audit log. No `Authorization` header → 401. |

### Database — Schema Change

No new migration file is created. This project uses the BABRRR process: the bootstrap file is updated in-place for fresh environments, and the equivalent `ALTER TABLE` is run manually on live environments.

| Artifact | Change |
|----------|--------|
| `ansible/roles/docker/files/mysql/externalConfigs/create_media_db.sql` | Add `password_hash varchar(255) DEFAULT NULL` and `disabled tinyint(1) NOT NULL DEFAULT 0` columns to the existing `users` table definition. |
| Live ALTER command (BABRRR Step 2) | `docker exec -i mysqlServer bash -c 'mysql -u root -p"$MYSQL_ROOT_PASSWORD" media_db -e "ALTER TABLE users ADD COLUMN password_hash varchar(255) DEFAULT NULL AFTER idp_subject, ADD COLUMN disabled tinyint(1) NOT NULL DEFAULT 0 AFTER password_hash;"'` |

See §4 Database Implications for the full DDL and seeding instructions.

### New Files (Ansible / Configuration)

| File | Purpose |
|------|---------|
| `infra/oidc/realm-google.json` | **Optional operator reference** — Google OIDC app registration guidance. Not a required Phase 5 deliverable; IdP registration is done in the Google Cloud Console. |
| `infra/oidc/realm-microsoft.json` | **Optional operator reference** — Microsoft Entra ID app registration guidance. Not a required Phase 5 deliverable. |
| `infra/keycloak/realm-gighive.json` | **Optional operator reference** — Keycloak realm export for self-hosted operators wanting a Keycloak intermediary. Keycloak is explicitly out of scope for Phase 5 (see `feature_security_authentication_migration_jwt_oidc_phase5.md`). |

### New Files (iOS — `GigHive/Sources/App/`)

| File | Purpose |
|------|---------|
| `JWTStore.swift` | Token-centric Keychain API replacing `KeychainStore`'s credential storage. `JWTStore.save(token:host:expiresAt:role:)`, `JWTStore.load(host:) -> StoredToken?`, `JWTStore.delete(host:)`. `StoredToken`: `token: String`, `role: UserRole`, `expiresAt: Date`. |
| `PKCEHelper.swift` | Phase 5. Generates PKCE `code_verifier`, `code_challenge` (S256), `state`, and `nonce` values for the OIDC authorization request. Used by `OIDCLoginView`. |
| `OIDCLoginView.swift` | Phase 5. `ASWebAuthenticationSession`-based OIDC login. Constructs PKCE authorization URL via `PKCEHelper`, launches session via `WindowAnchorProvider`, handles `gighive://oidc/callback`, exchanges code via `api/oidc/token-exchange.php`. Requires `CFBundleURLSchemes` entry `gighive` in `AppInfo.plist` (Phase 5 prerequisite). |

### Modified Files (Server)

| File | Change |
|------|--------|
| `api/media-stream.php` | Adopt `MIXED_MEDIA`: explicit guest credential when supplied; otherwise Bearer then browser cookie; no invalid-credential fallback; preserve Range and non-HTML failures. |
| `api/tus-upload.php`, `src/index.php` upload routes | Adopt mixed `GUEST_UPLOAD`/authenticated policy: explicit upload token authoritative; otherwise Bearer or browser cookie with contributor role and CSRF/Origin controls for cookie-authenticated upload. |
| `api/uploads.php` | `AUTHENTICATED_API` only: Bearer or browser cookie with contributor role; cookie-authenticated unsafe requests require CSRF. Current source contains no QR upload-token handling. |
| Other authenticated `api/*.php` and `db/*.php` | Declare `AUTHENTICATED_API`, `HTML_PAGE`, `DUAL_RESPONSE`, or the applicable mixed class; enforce role plus tenant/event scope through shared helpers. |
| `db/upload_form_single.php` | Preserve dual guest/authenticated page purpose; explicit QR credential controls guest mode even when browser cookie exists. |
| `admin/` — 59 real PHP files | Classify exactly: 9 HTML pages, 38 JSON/action endpoints, 2 authenticated downloads, 7 internal workers, 3 include-only libraries. Do not apply one blanket page guard. |
| `import_manifest_worker.php` | Add the explicit CLI-only guard already used by the other six admin workers. |
| `src/`, `vendor/`, include-only libraries | Deny direct HTTP access or move outside webroot; do not treat implementation code as JWT web endpoints. |
| `config.php` | Add auth-mode, JWT, browser-cookie, route-policy, and CSRF configuration constants sourced from environment variables. |
| `ansible/roles/docker/templates/default-ssl.conf.j2` | At atomic cutover remove Basic account-auth directives; retain/add direct denials, public/login/guest routing, request limits, and Authorization forwarding. OIDC callback configuration remains scoped to login/callback behavior. |
| `ansible/roles/docker/templates/.env.j2` and group vars | Add all JWT, cookie, CSRF, cutover, and later OIDC settings to every environment; secrets remain in Ansible Vault. |

### Modified Files (iOS)

> **iOS 14 minimum deployment target constraint:** The project minimum is iOS 14.0. `URLSession.data(for:)` and `URLSession.data(from:)` with async/await are available from **iOS 15** only. Every new `async` URLSession call introduced by this feature must use a `withCheckedThrowingContinuation` bridge over the completion-handler form `dataTask(with:completionHandler:)`. No `@available(iOS 15, *)` guard is acceptable here — the code must run on iOS 14 unconditionally.

| File | Change |
|------|--------|
| `AuthSession.swift` | Replace `credentials: (user: String, pass: String)?` with `token: String?` and `expiresAt: Date?`. Remove role derivation from username; decode role from JWT `role` claim. Update `UserRole` enum: remove existing `.admin` case; add `.contributor` and `.owner` cases matching the DB role enum (`owner`, `contributor`, `viewer`). Add `fromLegacyUsername()` bridge for one-time Keychain migration. |
| `LoginView.swift` | Phase 3: Call `POST /api/login.php`; store JWT via `JWTStore`. Phase 5: Add OIDC button launching `OIDCLoginView`. **iOS 14 constraint:** `URLSession.data(for:)` (async/await) is iOS 15+ only. All new async URLSession calls must use a `withCheckedThrowingContinuation` bridge over `dataTask(with:completionHandler:)`. |
| `SplashView.swift` | Replace all `session.credentials == nil` / `session.credentials != nil` guards with `session.token == nil` / `session.token != nil`. |
| `DatabaseView.swift` | Replace `session.credentials` argument to `DatabaseAPIClient` with `session.token` as Bearer header. |
| `DatabaseDetailView.swift` | Replace `credentials: session.credentials` argument to `MediaPlayerView` with `token: session.token`. |
| `MediaPlayerView.swift` | Replace `let credentials: (user: String, pass: String)?` with `let token: String?`. Replace `Authorization: Basic ...` header construction with `Authorization: Bearer ...`. Replace `MediaResourceLoader(credentials:)` call with `MediaResourceLoader(token:)`. |
| `MediaResourceLoader.swift` | Replace `init(credentials: (user: String, pass: String)?)` with `init(token: String?)`. Replace `Authorization: Basic ...` header with `Authorization: Bearer ...`. |
| `DatabaseAPIClient.swift` | Replace `basicAuth:` parameter and `Authorization: Basic ...` construction with `bearerToken:` and `Authorization: Bearer ...`. |
| `TUSUploadClient.swift` | Replace `basicAuth` branch (`Authorization: Basic ...`) with `bearerToken` branch (`Authorization: Bearer ...`). Keep `uploadToken` branch (`X-Upload-Token`) unchanged. |
| `KeychainStore.swift` | Deprecate credential-specific API. Retain for one-time migration read on first launch (detect old `{user, pass}` entry → prompt re-login). Remove after migration period. |
| `GuestUploadSession.swift` | No change — QR flow is independent. |
| `QRTokenAPIClient.swift` | No change — QR flow is independent. |

---

## 3. API Contract Changes

### API/iOS Endpoint: `POST /api/login.php`

**Purpose:** Explicit API/iOS local-user credential exchange. Returns a GigHive Bearer JWT in JSON and never sets the browser JWT cookie.

**Request:**
```http
POST /api/login.php HTTP/1.1
Content-Type: application/json

{
  "email": "admin@example.com",
  "password": "correct-horse-battery-staple"
}
```

**Response (200 OK):**
```json
{
  "token": "eyJhbGciOiJIUzI1NiJ9...",
  "role": "owner",
  "email": "admin@example.com",
  "expires_at": "2026-09-21T12:00:00Z"
}
```

**Response (401 Unauthorized):**
```json
{ "error": "invalid_credentials" }
```

**Response (403 Forbidden):**
```json
{ "error": "account_disabled" }
```

The API contract is intentionally separate from browser login so iOS `URLSession` does not acquire a browser cookie alongside its Bearer token.

### Browser Session Contract

The browser login page submits credentials to a dedicated browser-session endpoint. On success the endpoint sets the canonical Secure HttpOnly GigHive JWT cookie and does not return the JWT to JavaScript. Browser logout clears that cookie and is CSRF-protected. Exact endpoint paths and response bodies are finalized in the implementation guide.

While an environment remains in `basic` mode, these JWT routes are inert/test-only and existing protected pages remain Apache Basic-authenticated. At atomic cutover the environment switches fully to application JWT policy.

---

### API/iOS Endpoint: `GET /api/verify.php`

**Purpose:** iOS app uses this to validate a stored JWT when the local expiry check shows the token is expired or borderline. On launch, if the stored `expiresAt` is still in the future the app navigates directly without a network round-trip. Only when the local check shows expired does the app call `verify.php` — receiving `token_expired` or `invalid_token` — so it can act differently for each case.

**Request:**
```http
GET /api/verify.php HTTP/1.1
Authorization: Bearer eyJhbGciOiJIUzI1NiJ9...
```

**Response (200 OK):**
```json
{
  "valid": true,
  "role": "owner",
  "email": "admin@example.com",
  "expires_at": "2026-09-21T12:00:00Z"
}
```

**Response (401 — expired but otherwise valid token):**
```json
{ "valid": false, "error": "token_expired" }
```
iOS behavior: clear the expired token and run the approved API/local or OIDC login flow again. The final JWT lifetime and renewal behavior are open implementation decisions in the canonical policy; the app must not retain a local password for silent re-authentication.

**Response (401 — tampered, malformed, or unknown token):**
```json
{ "valid": false, "error": "invalid_token" }
```
iOS behavior: clear Keychain, present full login screen.

---

### New Endpoint: `POST /api/oidc/token-exchange.php` (Phase 5)

**Purpose:** iOS PKCE flow. The iOS app obtains an authorization code from the IdP via `ASWebAuthenticationSession`, then calls this endpoint to exchange it for a GigHive JWT without embedding the OIDC client secret in the app bundle.

**Request:**
```http
POST /api/oidc/token-exchange.php HTTP/1.1
Content-Type: application/json

{
  "code": "SplxlOBeZQQYbYS6WxSbIA",
  "code_verifier": "dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk",
  "redirect_uri": "gighive://oidc/callback",
  "provider": "google"
}
```

**Response (200 OK):**
```json
{
  "token": "eyJhbGciOiJIUzI1NiJ9...",
  "role": "owner",
  "email": "admin@organization.com",
  "expires_at": "2026-09-21T12:00:00Z"
}
```

---

### JWT Payload Structure

```json
{
  "sub": "42",
  "email": "admin@example.com",
  "role": "owner",
  "iat": 1756000000,
  "exp": 1758592000,
  "iss": "gighive"
}
```

`sub` is the `users.id` (as a string). `role` is the canonical DB role value: `owner`, `contributor`, or `viewer`. For OIDC users, `idp_provider` and `idp_subject` are looked up from the `users` table to find the row; `sub` in the JWT still refers to `users.id`.

---

### Existing Endpoints — Route-Class Contract

Existing URLs are classified rather than given one universal header rule:

- Browser HTML uses the HttpOnly JWT cookie and HTML redirect/403 behavior.
- JSON APIs accept explicit Bearer or browser cookie; cookie-authenticated unsafe methods require CSRF.
- Authenticated downloads accept Bearer or cookie and never substitute login HTML for a file.
- Mixed media accepts explicit guest capability, otherwise Bearer or cookie, and preserves Range behavior.
- Internal workers/libraries deny direct HTTP.

Response shapes remain stable where compatible; authentication failures become deliberately HTML, JSON, guest, download, or media-specific.

### QR Guest Endpoints — Product Behavior Preserved, Precedence Explicit

`/api/upload-token.php`, `/api/guest-gallery.php`, `/api/guest-stream.php`, `/api/guest-status.php`, `/api/guest-report.php`, `/api/guest-delete.php`, and `/db/upload_form_single.php` retain accountless event-scoped behavior. They are not “untouched”: route policy explicitly makes a supplied valid token/nonce authoritative and prevents an incidental authenticated cookie from broadening access or hiding an invalid guest credential.

---

## 4. Database Implications

### Existing `users` Table (from `create_media_db.sql`)

The `users` table already exists with this schema:

```sql
CREATE TABLE users (
  id              int unsigned  NOT NULL AUTO_INCREMENT,
  tenant_id       int unsigned  NOT NULL,                        -- SaaS tenant FK
  idp_provider    varchar(32)   NOT NULL DEFAULT 'local',        -- 'google'|'microsoft'|'apple'|'local'
  idp_subject     varchar(255)  DEFAULT NULL,                    -- IdP sub/oid claim
  role            enum('owner','contributor','viewer','superadmin') NOT NULL DEFAULT 'viewer',
  email           varchar(255)  DEFAULT NULL,
  display_name    varchar(255)  DEFAULT NULL,
  avatar_url      varchar(1024) DEFAULT NULL,
  tos_version     varchar(32)   DEFAULT NULL,
  tos_accepted_at datetime      DEFAULT NULL,
  created_at      datetime      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      datetime      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_idp (idp_provider, idp_subject),
  KEY idx_users_tenant (tenant_id),
  CONSTRAINT fk_users_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id)
)
```

**Key design points:**
- IdP identity is a composite key `(idp_provider, idp_subject)` — not a single `sub` column. This correctly handles the same email address appearing in both Google and Microsoft IdPs with different subjects.
- `role` is inline on the `users` row — no separate `user_roles` table.
- `tenant_id` is NOT NULL and FK-enforced — required for SaaS multi-tenancy.
- There is no `password_hash` or `disabled` column yet.

### Schema Migration (BABRRR process)

No standalone migration file is created. The bootstrap file `create_media_db.sql` is updated in-place for fresh environments. For live environments the equivalent `ALTER TABLE` is run manually using the BABRRR process before deploying Phase 1 code:

```sql
-- Add password_hash for local-user login (idp_provider = 'local').
-- NULL for all OIDC users; only populated for local accounts.
ALTER TABLE users
  ADD COLUMN password_hash varchar(255) DEFAULT NULL
      COMMENT 'bcrypt hash for local (non-OIDC) users; NULL for IdP-authenticated users'
      AFTER idp_subject,
  ADD COLUMN disabled tinyint(1) NOT NULL DEFAULT 0
      COMMENT '1 = account suspended'
      AFTER password_hash;
```

Full live `docker exec` command with quoting:

```bash
docker exec -i mysqlServer bash -c 'mysql -u root -p"$MYSQL_ROOT_PASSWORD" media_db -e "
ALTER TABLE users
  ADD COLUMN password_hash varchar(255) DEFAULT NULL
      COMMENT '"'"'bcrypt hash; NULL for OIDC-only users'"'"'
      AFTER idp_subject,
  ADD COLUMN disabled tinyint(1) NOT NULL DEFAULT 0
      COMMENT '"'"'1 = account suspended'"'"'
      AFTER password_hash;
"'
```

No new tables are needed. Role assignment remains inline on the `users` row using the existing `role` enum.

### Seeding Initial Users (replaces htpasswd provisioning)

Passwords cannot be migrated from htpasswd (bcrypt is one-way). Operators set new passwords:

```sql
-- Generate hash: php -r "echo password_hash('newpass', PASSWORD_BCRYPT, ['cost'=>12]);"
-- Store resulting hash in secrets.yml under ansible-vault.
-- idp_subject is NULL for local accounts — it holds the IdP sub/oid claim and has no meaning here.
-- The UNIQUE KEY on (idp_provider, idp_subject) allows multiple NULLs in MySQL, so
-- multiple local accounts work correctly with idp_subject = NULL.
INSERT INTO users (tenant_id, idp_provider, idp_subject, role, email, password_hash)
  VALUES (1, 'local', NULL, 'owner', 'admin@gighive.local', '$2y$12$...')
  ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash), role = VALUES(role);
```

For OIDC users: `password_hash` is NULL; `idp_provider` and `idp_subject` are populated on first OIDC login by `api/oidc/callback.php` via upsert.

### Role Hierarchy Enforced in PHP

```
owner       (level 3) — inherits contributor + viewer permissions
contributor (level 2) — inherits viewer permissions
viewer      (level 1) — read-only
```

`requireRole('contributor')` passes for both `contributor` and `owner` users.

### No Changes to Other Existing Tables

`event_upload_tokens`, `anon_upload_attributions`, `upload_jobs`, `events`, `assets`, `event_items`, `participants` — all unchanged. The migration is a two-column `ALTER TABLE` on `users`.

---

### New Table: `security_audit_log` (Phase 6)

A dedicated security audit log separate from application-level logging. Captures every security-relevant event. Application events (media approve/reject, QR generation, catalog changes) are logged separately in a future feature.

**Scope — events captured:**

| `event_type` value | Trigger |
|--------------------|---------|
| `login_success` | Local or OIDC login succeeded; JWT issued |
| `login_failure` | Bad password or unknown email at login — credential mismatch only |
| `token_invalid` | JWT validation failure on any guarded endpoint (bad signature, malformed payload) |
| `token_expired` | Expired JWT presented at any guarded endpoint, including the login flow |
| `account_disabled_attempt` | Auth attempt by a `disabled=1` user — distinct from `login_failure`; credential may be correct |
| `role_changed` | Owner changes another user's role |
| `account_disabled` | Owner disables a user account |
| `account_enabled` | Owner re-enables a user account |
| `user_deleted` | Owner deletes another user's row via `admin/users.php` |
| `self_account_deleted` | Authenticated user deletes their own account via `api/account/delete.php` |
| `jwt_issued` | Any JWT issuance (local login, OIDC callback, OIDC token exchange) |

**DDL:**

```sql
CREATE TABLE security_audit_log (
  id            bigint unsigned  NOT NULL AUTO_INCREMENT,
  occurred_at   datetime         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  event_type    varchar(64)      NOT NULL
                                 COMMENT 'login_success | login_failure | token_invalid | token_expired | account_disabled_attempt | role_changed | account_disabled | account_enabled | user_deleted | self_account_deleted | jwt_issued',
  actor_user_id int unsigned     DEFAULT NULL
                                 COMMENT 'users.id of the authenticated user performing the action; NULL for unauthenticated attempts',
  target_user_id int unsigned    DEFAULT NULL
                                 COMMENT 'users.id of the user being acted upon (role_changed, account_disabled, user_deleted); NULL for self-auth events including self_account_deleted',
  tenant_id     int unsigned     DEFAULT NULL
                                 COMMENT 'tenants.tenant_id; NULL for pre-provisioning failures',
  idp_provider  varchar(32)      DEFAULT NULL
                                 COMMENT 'google | microsoft | local — the provider involved in the event',
  ip_address    varchar(45)      DEFAULT NULL
                                 COMMENT 'IPv4 or IPv6 of the originating request',
  user_agent    varchar(512)     DEFAULT NULL,
  detail        json             DEFAULT NULL
                                 COMMENT 'Event-specific detail: old_role/new_role for role_changed; error reason for failures; provider sub for OIDC events',
  PRIMARY KEY (id),
  KEY idx_sal_occurred     (occurred_at),
  KEY idx_sal_actor        (actor_user_id),
  KEY idx_sal_target       (target_user_id),
  KEY idx_sal_tenant       (tenant_id),
  KEY idx_sal_tenant_time  (tenant_id, occurred_at),
  KEY idx_sal_type         (event_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

**Design notes:**

- `actor_user_id` is NULL for unauthenticated failures (no valid JWT at time of event).
- `target_user_id` is only populated for admin actions on another user (role change, disable, delete).
- `detail` JSON stores event-specific context: `{"old_role":"viewer","new_role":"contributor"}` for role changes; `{"reason":"token_expired"}` for validation failures; `{"idp_sub":"...","provider":"google"}` for OIDC issuance.
- No foreign key constraints on `actor_user_id` / `target_user_id` — audit rows must survive user deletion.
- Retention: indefinite (no purge policy in v1). A future purge policy can be added as a scheduled Ansible task.
- Consumer: the tenant `owner` reads this via `admin/users.php` (or a dedicated audit view). The `superadmin` (platform operator) can query directly.
- Future: the structured `event_type` and `occurred_at` columns are designed to support alerting rules (e.g. N `login_failure` events in a window from the same IP) without schema changes.

**Live ALTER command (BABRRR Step 2 — apply on existing environments):**

```sql
docker exec -i mysqlServer bash -c 'mysql -u root -p"$MYSQL_ROOT_PASSWORD" media_db -e "
CREATE TABLE IF NOT EXISTS security_audit_log (
  id            bigint unsigned  NOT NULL AUTO_INCREMENT,
  occurred_at   datetime         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  event_type    varchar(64)      NOT NULL,
  actor_user_id int unsigned     DEFAULT NULL,
  target_user_id int unsigned    DEFAULT NULL,
  tenant_id     int unsigned     DEFAULT NULL,
  idp_provider  varchar(32)      DEFAULT NULL,
  ip_address    varchar(45)      DEFAULT NULL,
  user_agent    varchar(512)     DEFAULT NULL,
  detail        json             DEFAULT NULL,
  PRIMARY KEY (id),
  KEY idx_sal_occurred     (occurred_at),
  KEY idx_sal_actor        (actor_user_id),
  KEY idx_sal_target       (target_user_id),
  KEY idx_sal_tenant       (tenant_id),
  KEY idx_sal_tenant_time  (tenant_id, occurred_at),
  KEY idx_sal_type         (event_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
"'
```

---

## 5. Deployment Considerations

### Implementation and Promotion Sequence

1. Complete the Web Refactor Phase 1 prerequisite under Basic Auth, including shared AJAX wrapper, proven XSS fixes, CSP report-only evaluation, and tests.
2. Implement JWT core, browser cookie, API/iOS Bearer, route resolver, CSRF, response helpers, and exact endpoint classes in dev while Basic remains authoritative until cutover.
3. Complete iOS Bearer and browser-session behavior in dev.
4. Capture rollback state.
5. Atomically remove Apache Basic account auth and activate `GIGHIVE_AUTH_MODE=local` in dev.
6. Run the complete route-class, browser, iOS, upload, media, QR, security, and rollback test suite.
7. Promote the same reviewed release sequentially to lab, staging, and production; each environment must pass before the next.
8. Add OIDC only after the JWT cutover policy is stable.

### Apache Config Transition

Before cutover, an environment remains fully in Basic account-auth mode and PHP JWT guards remain inert. At cutover, one reviewed deployment removes Basic account-auth directives and activates PHP JWT policy. Retain or add:

- Direct denials for sensitive files, internal workers, include-only code, `src/` implementation paths, and `vendor/`.
- Public/login/QR route access required to establish or exercise the intended credential.
- Request/body/range controls.
- Authorization forwarding to PHP-FPM.
- Explicit media/upload rewrites.

OIDC configuration is limited to login/initiation/callback duties; the resulting GigHive browser cookie remains the application credential.

### Ansible and Configuration

All auth mode, JWT, cookie, CSRF, CSP, cutover, and OIDC values are declared in every applicable environment group vars file and injected through templates. Secrets remain in Ansible Vault. No production cutover flag is enabled until the preceding environment passes. The user runs all Ansible playbooks.

### Docker / Container

JWT validation is PHP-library based. OIDC later adds `mod_auth_openidc` and requires a rebuilt Apache image; its version and startup prerequisites must be smoke-tested before promotion.

---

## 6. Compatibility and Cutover Boundary

### Before an environment cuts over

- Apache Basic remains authoritative for account routes.
- Additive JWT code and route declarations remain inert.
- The completed iOS `AuthCredential` and Web Refactor Phase 1 changes preserve Basic wire behavior.
- QR product behavior remains accountless and event-scoped.

### After that environment cuts over

- Browser account access uses the HttpOnly JWT cookie.
- iOS/API account access uses Bearer JWT.
- Basic account credentials are rejected.
- Explicit QR token/nonce remains authoritative on guest routes even when a cookie is present.
- Media, downloads, HTML, and APIs use route-specific response behavior.

### OIDC addition

OIDC adds Google/Microsoft identity proofing but still results in the same GigHive credentials: browser callback issues the cookie; iOS PKCE exchange returns Bearer JSON; local break-glass login remains.

### Failed-cutover recovery

Rollback restores the complete reviewed pre-cutover artifact/configuration for that environment—Apache rules, PHP auth mode, clients, and supporting configuration—not only one variable or one template. Promotion stops until the failed environment is clean.

---

## 7. Test Strategy

Permanent tests are assigned in the implementation, endpoint-checklist, iOS, upload, and OIDC documents after checking the shared T-number namespace. At minimum the strategic suite covers:

### JWT and role core

- API/iOS login success, wrong password, disabled account, valid/expired/tampered token.
- One authoritative role hierarchy across viewer, contributor, owner, and platform admin.
- Tenant and event isolation.
- Raw credentials absent from logs.

### Browser cookie and CSRF

- HttpOnly, Secure, host-only, Path `/`, selected SameSite, and expiry attributes.
- JWT absent from browser JavaScript, localStorage, and sessionStorage.
- Safe browser login destination validation and logout clearing.
- Cookie-authenticated unsafe request succeeds with valid CSRF and fails without mutation when missing/invalid.
- HTML navigation receives login/HTML errors rather than JSON.

### Route-class matrix

- `AUTHENTICATED_API`: Bearer and cookie succeed independently; invalid explicit Bearer does not fall back.
- `AUTHENTICATED_DOWNLOAD`: cookie/Bearer download works; failure never substitutes login HTML or partial file.
- `GUEST_UPLOAD`: valid token works with incidental cookie; invalid token + valid cookie fails.
- `GUEST_GALLERY`: valid nonce works with incidental cookie; invalid nonce + valid cookie fails.
- `MIXED_MEDIA`: nonce/upload-token/Bearer/cookie paths work independently; invalid explicit credential does not fall back; Range preserved.
- `DUAL_RESPONSE`: explicit HTML mode gets HTML failures; explicit JSON mode gets JSON failures.
- `INTERNAL_WORKER` and `INTERNAL_LIBRARY`: direct HTTP denied; intended CLI/include behavior preserved.
- `PUBLIC`: response remains public and does not broaden when a cookie is present.

### iOS

- API login stores Bearer JWT in secure storage and receives no browser cookie.
- Database, media, Range, and TUS flows work with Bearer.
- QR upload token remains exclusive and authoritative.
- Expired/invalid tokens reach clean login recovery.
- iOS 14 compatibility tests follow `testing_ios.md`.

### OIDC

- Google and Microsoft browser callback issue canonical browser cookie.
- iOS PKCE exchange returns Bearer JSON without browser cookie.
- Group-to-role and tenant mapping, disabled account, callback state/nonce, and local break-glass login.

### Environment gates

Run the full applicable suite in dev. Promote the same release only after success: lab, then staging, then production. Each environment must pass its safe applicable checks before the next deploy.

---

## 8. Risks and Rollback Plan

### Risk Matrix

| Risk | Impact | Mitigation |
|---|---|---|
| Environment cuts over before browser/iOS/server routes are ready | Account access fails | Atomic cutover gate; full dev suite; sequential environment promotion |
| Browser JWT exposed to JavaScript/XSS | Privileged credential theft | HttpOnly cookie; no local/session storage; four proven XSS sink fixes; CSP rollout |
| Cookie-authenticated mutation lacks CSRF | Cross-site privileged action | Central CSRF policy and route-class tests |
| Invalid QR credential falls back to admin cookie | Guest scope broadens or broken links appear valid | Explicit guest credential authoritative; present-invalid never falls back |
| Worker/library becomes public after Basic removal | Internal code executed directly | Exact `INTERNAL_WORKER`/`INTERNAL_LIBRARY` denial inventory and tests |
| Download/media receives login HTML | Corrupt download or playback failure | Download/media response classes never redirect |
| JWT secret compromise | All tokens untrustworthy | Per-environment Vault secret, rotation runbook, bounded lifetime |
| IdP outage | OIDC users cannot establish new sessions | Local break-glass owner remains available |
| Existing JWT survives disable/role change | Access persists until expiry | Finalize request-time account/token-version validation or denylist decision |
| Environment-specific deployment drift | Later environment fails despite dev success | Lab, staging, and production gates each run applicable checks |

### Rollback Policy

- Capture the exact pre-cutover application/configuration state for each environment.
- Before cutover, additive JWT code can be reverted while Basic remains authoritative.
- After cutover, rollback restores Apache Basic rules, PHP auth mode, compatible clients, and supporting configuration as one coordinated release.
- Do not roll back only `GIGHIVE_AUTH_MODE` or only `default-ssl.conf.j2`.
- The user runs Ansible and verifies the failed environment before any further promotion.
- OIDC-only rollback returns to local JWT/browser-cookie login, not directly to Basic, unless the full environment rollback is intentionally executed.
- Irreversible Phase 6 data operations require their own backup/audit/recovery controls.

---

## Implementation Sequence

- [ ] **JWT Migration Phase 0** — Keep the completed iOS `AuthCredential` refactor; complete and verify Web Refactor Phase 1 under Basic Auth.
- [ ] **JWT Migration Phase 1** — Add schema alignment, JWT core, API/iOS login, browser-session foundation, and tests while auth mode remains Basic.
- [ ] **JWT Migration Phase 2** — Add centralized route declarations/resolver, cookie/CSRF/response helpers, exact guards, worker/library denials, and tests; keep them inert in Basic mode.
- [ ] **JWT Migration Phase 3** — Complete iOS Bearer login/storage/session behavior and browser login/logout/session behavior in dev.
- [ ] **JWT Migration Phase 4** — Capture rollback state; atomically switch dev from Basic to JWT; run full suite; then promote sequentially to lab, staging, and production with a gate after each.
- [ ] **JWT Migration Phase 5** — Add Google/Microsoft OIDC using browser-cookie callback and iOS PKCE/Bearer paths.
- [ ] **JWT Migration Phase 6** — Add user management, security audit, and account lifecycle after role/tenant/revocation policies are finalized.

Detailed executable steps belong in the implementation and phase-specific documents; this strategic plan does not authorize implementation.

---

## Open Decisions Captured

| Decision | Answer |
|----------|--------|
| Clean JWT cutover | Yes — atomic inside each environment; fully validate dev, then gate lab → staging → production |
| Primary OIDC targets | Google OAuth2/OIDC + Microsoft Entra ID (AAD) |
| OIDC scope — all roles or owner first? | All roles at once (owner, contributor, viewer) |
| OIDC provider for self-hosted operators | Keycloak realm export bundled as an option |
| JWT algorithm | HS256 throughout all phases. IdP `id_token` (RS256) is validated server-side only and never forwarded to clients. |
| Token TTL / renewal | Open implementation decision in the canonical route policy; IdP tokens remain server-side and are not used as GigHive application credentials. |
| Role naming | `owner`, `contributor`, and `viewer` remain canonical for this migration; reconcile DB `superadmin` with SaaS `platform_admin` before platform-role implementation; htpasswd names retire at each environment's JWT Migration Phase 4 cutover |
| Separate `user_roles` table? | No — role is inline on `users.role` as per existing schema |
| `password_hash` storage | `ALTER TABLE users ADD COLUMN password_hash` — additive to existing schema |
| Account linking (same email, two IdPs) | Not in scope for v1; two separate `users` rows, documented edge case |
| Browser/API credential transport | Browser HttpOnly JWT cookie; iOS/API Bearer JWT; browser and API login responses remain separate |
| Credential precedence | Central route-class policy; explicit invalid Bearer/token/nonce never falls back to broader authority |
| Session tracking / revocation | Open: finalize request-time account/token-version validation, denylist, or expiry-only behavior before implementation |
| `superadmin` role | Reserved in DB schema for GigHive platform operators; not part of this migration |
| Local user creation via admin UI | **No.** Wholesale cutover to federated (OIDC) logins only. No customers to migrate; clean break is the right call. The break-glass `owner` account is seeded by Ansible only and is not visible or creatable in `admin/users.php`. |
| Local users after OIDC cutover | `password_hash` column remains in schema for the break-glass account. All other `users` rows are OIDC-provisioned (`idp_provider != 'local'`). The admin UI does not expose local user management. |
| Security audit log | **Yes — `security_audit_log` table (Phase 6).** Captures all security-relevant events: login success/failure, JWT issuance, token validation failure, role changes, account disable/enable, user delete. Per-attempt logging (no threshold). Retention: indefinite. Consumer: tenant `owner` via admin UI; `superadmin` via direct DB access. Separate from application-level audit (media, QR) — that is a future feature. |
| Admin user management | **`admin/users.php` (Phase 6).** Owner-only. List, role-change, disable/enable, delete OIDC-provisioned users. No local user creation. Reads `security_audit_log` for the tenant. |
| Self-service account deletion | **Yes — `api/account/delete.php` (Phase 6).** Any authenticated non-superadmin user may delete their own account immediately. Required for Apple App Store compliance (guideline 5.1.1) and GDPR/CCPA right-to-erasure. Tenant's last owner is blocked (409 `last_owner_cannot_delete`). Owner self-delete (non-last) is permitted with a UI confirmation step; a `superadmin_notified` detail is written to the audit log. Contributed media is orphaned, not deleted. Surface: iOS settings screen + web settings page (`account/delete.php`). |
