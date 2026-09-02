# Feature: Federated Authentication Migration (JWT + OIDC)

## Executive Summary

From a user perspective, this is a simple change: the three shared `.htpasswd` accounts (`admin`, `uploader`, `viewer`) are replaced by individual logins backed by Google or Microsoft as the identity provider. Each person who currently uses a shared password will instead sign in with their own Google or Microsoft account. Their role in GigHive (owner, contributor, or viewer) is determined by their IdP group membership — they never need a separate GigHive password.

The rest of the application — media playback, uploads, gallery, admin UI, and the QR guest system — is completely unchanged. The only thing that changes for an authenticated user is the login step.

One local `owner` account with a strong password remains in ansible-vault as an emergency backdoor if the IdP is unreachable. That is the only credential the operator manages going forward.

Internally, getting to this end state requires a five-phase migration (JWT infrastructure → PHP guards → iOS client cutover → Apache Basic Auth removal → OIDC federation), but that complexity is invisible to users. The QR-code guest system runs in a fully isolated code path and is untouched in every phase.

---

## Summary

GigHive's move to SaaS requires replacing shared Apache Basic Auth passwords with individual, auditable identities. This feature implements a clean, phased migration to JWT-based authentication with full OIDC federation (Google + Microsoft/AAD), while preserving the existing QR-code guest auth system unchanged.

**Architecture decision:** Two auth systems run in parallel and never share state or code paths:
- **QR Token auth** — event goers (existing, production-grade, untouched forever)
- **OIDC/JWT auth** — viewers, uploaders, and admins (this feature)

**The four customer journeys:**

| Journey | Auth Model | Status |
|---------|-----------|--------|
| QR Event Goer (no account) | QR token — event-scoped, accountless | Done — unchanged |
| Media Library Viewer (account) | OIDC/JWT — individual identity from IdP | To build |
| Media Uploader / Band Planner (account) | OIDC/JWT — uploader role from IdP group | To build |
| Administrator (account) | OIDC/JWT + MFA at IdP | To build |

**Migration phases (clean cutover — no tech debt, no customers to break):**

| Phase | What | Key constraint |
|-------|------|---------------|
| 1 | JWT core: `users` table alignment + `auth/jwt.php` + `api/login.php` | None — purely additive |
| 2 | PHP `requireRole()` guards on all pages | Dual-auth period; Basic Auth still active at Apache |
| 3 | iOS app: replace Basic Auth with JWT Bearer tokens | Server accepts both during this phase |
| 4 | Remove Apache Basic Auth | **Hard gate: Phase 3 must be live and verified first** |
| 5 | OIDC: Google + Microsoft/AAD; iOS PKCE flow | Additive alongside local JWT |

**Key decisions captured:**
- Clean JWT cutover — no dual-auth on iOS side
- OIDC primary targets: Google OAuth2/OIDC + Microsoft Entra ID (AAD)
- All roles (owner/contributor/viewer) move to OIDC simultaneously in Phase 5
- JWT algorithm: HS256 throughout all phases. GigHive-issued JWTs remain HS256 in Phase 5. The OIDC `id_token` received from the IdP uses RS256 and is validated server-side against the IdP JWKS; it is never forwarded to clients. (RS256 for GigHive-issued tokens is deferred — only needed if a third party must verify GigHive JWTs without the shared secret, which is not a Phase 5 requirement.)
- Token TTL: 30 days for both local-user and OIDC-user GigHive-issued JWTs. The IdP's short-lived access/refresh tokens are consumed server-side during the OIDC callback and never forwarded to clients.

---

## Context and Rationale

### Why Now

GigHive is moving toward a SaaS deployment model. The current authentication system — Apache HTTP Basic Auth with shared `admin`, `uploader`, `viewer`, and optional `guest` htpasswd accounts — was the correct pragmatic choice for a single-tenant, self-hosted appliance. It is the wrong model for SaaS, where each user is an individual with their own identity, where organizations use SSO for their tools, and where audit trails matter.

At the same time, the QR-code-based guest upload and gallery system is production-grade, deliberately accountless, and must remain exactly as it is. It serves a fundamentally different user: an event attendee who will never have a GigHive account and should not need one.

The key insight driving this plan: **two auth systems must coexist, and they already can**. The QR paths in Apache are already architected with `AuthMerging Off` + `Require all granted`, insulating them from whatever happens to Basic Auth. The migration from Basic Auth to OIDC/JWT happens around the QR system, not through it.

No existing customers means no compatibility constraints on the auth migration. We do it once, cleanly.

### Prior Plans This Supersedes (Partially)

- **`security_auth_jwt_token_migration.md`** — The role mapping table and phase structure remain accurate. Updated by: (a) QR guest endpoints now exist and are excluded from the migration; (b) `media-stream.php` already handles all media streaming, changing how Phase 3 (media proxy) applies; (c) TUS is now PHP-based (`api/tus-upload.php`), not a tusd container.
- **`refactor_security.md`** — The `GIGHIVE_AUTH_MODE` env var design and Keycloak realm export concept are carried forward unchanged.
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

All PHP role checks, JWT payloads, and API responses use the DB-side names (`owner`, `contributor`, `viewer`). The old Apache usernames are only referenced during the transitional dual-auth period (Phases 1–3) and are removed in Phase 4.

---

## The Four Customer Journeys

### Journey 1 — QR Event Goer (upload + gallery, no account)

**Persona:** Concert attendee, wedding guest, corporate event participant. Scans a printed QR code at the venue.

**Auth today:** QR token (43-char URL-safe base64, SHA-256 hash stored in `event_upload_tokens`). Entirely accountless. Device-local identity via `GuestUploadRecord` in iOS UserDefaults.

**Auth after this feature:** Unchanged. QR tokens are the correct, complete auth model for this persona.

**OIDC applicability:** None. Forcing an identity provider login on a concert attendee to submit a video clip is a non-starter UX and defeats the purpose of the feature.

**Invariant:** The QR guest system is never touched by this migration.

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
- **QR code management** (`admin/event_qr.php`) — generate event-scoped QR tokens; set expiry; view active tokens. This flow is unchanged by the auth migration — the QR token system remains independent.
- **Media moderation** (`admin/admin.php` and related pages) — approve/reject uploaded media, manage the catalog, promote items, trigger AI jobs.
- **Database administration** (`admin/admin_system.php`, `admin/import_*.php`, etc.) — import/export, backup/restore, clear media.
- **Security audit log** (`admin/users.php`, audit tab) — owner can read the `security_audit_log` table for the tenant: login events, role changes, failed auth attempts, account disable/enable, user deletes. The audit log is a second tab within `admin/users.php` — no separate page.

**Admin → OIDC → QR chain:** The admin authenticates via OIDC (browser) or local JWT fallback (break-glass), receives an `owner`-role GigHive JWT, and uses it to access all `/admin/*` pages. From within the admin UI they generate QR codes for events. Those QR codes are scanned by event goers who authenticate via the entirely separate QR token path — the two systems share no session state.

---

## Architecture: Two Auth Systems in Parallel

```
                    ┌─────────────────────────────────────┐
                    │           GigHive Server             │
                    │                                     │
  QR Event Goer ──▶ │  QR Token Auth (unchanged)          │
                    │  ├── /api/upload-token.php           │
                    │  ├── /api/guest-gallery.php          │
                    │  ├── /api/guest-stream.php           │
                    │  ├── /api/guest-status.php           │
                    │  └── /api/tus-upload.php (token)    │
                    │                                     │
  Viewer ──────────▶ │  OIDC/JWT Auth (this feature)       │
  Contributor ─────▶ │  ├── /api/login.php                 │
  Owner ───────────▶ │  ├── /api/oidc/callback.php         │
                    │  ├── /db/database.php                │
                    │  ├── /db/database_catalog.php        │
                    │  ├── /api/uploads.php                │
                    │  ├── /api/tus-upload.php (JWT)       │
                    │  ├── /api/media-stream.php (JWT)     │
                    │  └── /admin/*                        │
                    └─────────────────────────────────────┘
```

The two auth systems share no state. The QR token paths are `AuthMerging Off` + `Require all granted` in Apache — they bypass all Basic Auth directives and will bypass all OIDC directives. The JWT paths replace Basic Auth directives in Apache.

---

## 1. Architectural Impact

### Server (PHP / Apache)

**Current:** Apache enforces auth at the network layer for most paths. PHP trusts that Apache already validated the user. Two important exceptions:

1. **`media-stream.php`** — Its Apache location block uses `AuthType Basic` but `Require all granted`, meaning Apache validates the Basic credential if one is present but does not block requests that have no credential. PHP then enforces all three auth paths itself (Basic via `HTTP_AUTHORIZATION`, upload token via `X-Upload-Token`, gallery nonce via `?nonce=`). This is intentional — QR nonce and token requests must reach PHP without being blocked.

2. **`tus-upload.php`** — Unlike `media-stream.php`, this endpoint's comment says: *"Auth model enforced by Apache LocationMatch before PHP runs."* The `/files/` Apache location block uses `Require user admin uploader` (or `Require env upload_token_auth` for QR guests). PHP itself does no access control beyond branching on `X-Upload-Token` to set `$userId`. After Phase 4, when Apache Basic Auth is removed, PHP-side JWT validation must be added to `tus-upload.php` — mirroring the pattern already in `media-stream.php`.

**After Phase 1–2:** Dual-auth period. Apache still enforces Basic Auth on all non-QR paths; PHP pages additionally check JWT via `auth/helpers.php`. Both auth paths succeed simultaneously.

**After Phase 4:** Apache Basic Auth directives removed. PHP is the sole auth layer for all account-based paths. Apache keeps `Require all denied` for sensitive file paths (defense in depth). QR paths unchanged.

**After Phase 5 (OIDC):** Apache runs `mod_auth_openidc` for web browser flows. iOS uses the OIDC authorization code + PKCE flow directly via `ASWebAuthenticationSession`. Both paths deliver a JWT that PHP validates via `auth/jwt.php`.

### `GIGHIVE_AUTH_MODE` and Dual-Auth Period

New env var added to `.env.j2` and read by PHP:

```
GIGHIVE_AUTH_MODE=basic   # pre-Phase 2; Apache owns auth; PHP has no JWT layer
GIGHIVE_AUTH_MODE=local   # Phase 2 onward: PHP JWT layer active; Apache Basic Auth still in Apache config until Phase 4
GIGHIVE_AUTH_MODE=oidc    # Phase 5: OIDC active; local-user login still available
```

**Mode transition sequence:**
- **Phase 1** (JWT core deployed, guards not yet added): mode stays `basic`.
- **Phase 2** (PHP `requireRole()` guards deployed): change mode to `local`. Apache Basic Auth continues to enforce auth at the network layer; PHP additionally validates JWT Bearer tokens. Both auth paths succeed simultaneously during Phases 2–3.
- **Phase 4** (Apache Basic Auth removed): mode stays `local`. Now PHP is the sole auth layer.
- **Phase 5** (OIDC active): change mode to `oidc`.

Setting `GIGHIVE_AUTH_MODE=local` activates the PHP JWT guards but does **not** remove Apache Basic Auth directives — that is Phase 4's job. The Apache config and the PHP mode are independent levers changed at different phases.

### `media-stream.php` Auth Path Change

Currently path 1 trusts Basic Auth because the `HTTP_AUTHORIZATION` env var is only set by the `SetEnvIf Authorization` directive, and Apache validates the password before that fires. After Phase 4, this path changes:

```php
// BEFORE Phase 4 cutover: Trust Basic Auth forwarded by Apache
if (str_starts_with($authHeader, 'Basic ')) { return true; }

// AFTER Phase 4 cutover: Validate JWT Bearer token
if (str_starts_with($authHeader, 'Bearer ')) {
    $token = substr($authHeader, 7);
    return JwtAuth::validate($token) !== null;
}
```

Paths 2 (upload token via `X-Upload-Token`) and 3 (gallery nonce via `?nonce=`) are unchanged.

### `tus-upload.php` Auth Path Addition (Phase 4)

Currently `tus-upload.php` relies entirely on Apache's `<LocationMatch "^/files(?:/|$)">` block to enforce `Require user admin uploader` for Basic Auth sessions. After Phase 4 removes that Apache block, PHP must take over:

```php
// ADD after Phase 4 (mirrors media-stream.php pattern):
// Path 1: JWT Bearer — account-based uploads (owner / contributor)
$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
$rawToken   = $_SERVER['HTTP_X_UPLOAD_TOKEN'] ?? '';

if ($rawToken === '') {
    // No QR token present — require a valid JWT Bearer
    if (!str_starts_with($authHeader, 'Bearer ')) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'unauthenticated']);
        exit;
    }
    $token   = substr($authHeader, 7);
    $payload = JwtAuth::validate($token);
    if ($payload === null) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'invalid_token']);
        exit;
    }
    if (!in_array($payload['role'] ?? '', ['owner', 'contributor'], true)) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'forbidden']);
        exit;
    }
    // userId remains 0 for account-based uploads (same as current Basic Auth behavior)
}

// Path 2: QR upload token (X-Upload-Token) — existing code, unchanged
```

### iOS App — Full Call-Site Chain

> **Phase 0 prerequisite:** Before starting Phase 3, complete the `AuthCredential` type refactor documented in `feature_security_authentication_migration_jwt_ios_auth_cred_type.md`. That refactor replaces the `(user: String, pass: String)?` tuple with `AuthCredential` across all call sites and eliminates the seven duplicate Basic header constructions. After Phase 0, Phase 3 reduces to changing `LoginView`, `JWTStore`, and `SplashView` only — the five network-client files pass `AuthCredential` through unchanged.

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
| `auth/helpers.php` | `requireRole(string $minRole): void` — validates JWT, checks role hierarchy, sends 401 or 403 and exits. `hasRole(string $minRole): bool` — non-exiting variant. Role hierarchy: `owner` (3) > `contributor` (2) > `viewer` (1). |
| `api/login.php` | `POST /api/login.php` — local-user credential exchange. Accepts `{email, password}` JSON; validates against `users` table (`idp_provider='local'`); returns `{token, role, expires_at}`. |
| `api/verify.php` | `GET /api/verify.php` — validates a stored JWT; returns `{valid, role, email, expires_at}` on success, or `{valid: false, error: "token_expired"}` / `{valid: false, error: "invalid_token"}` on distinct failure modes. |
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
| `api/media-stream.php` | Auth path 1: replace Basic Auth trust with JWT Bearer validation after Phase 4 cutover. Paths 2 and 3 (upload token, gallery nonce) unchanged. |
| `api/tus-upload.php` | **Add PHP-side JWT Bearer validation** (Phase 4 cutover). Currently auth is enforced entirely by Apache; after Apache Basic Auth is removed, PHP must validate the Bearer token and enforce `owner`/`contributor` role before allowing the upload. QR `X-Upload-Token` path unchanged. |
| `api/uploads.php` | Add `requireRole('contributor')` call at top (after Phase 2). |
| `api/ai_jobs.php` | Add `requireRole('viewer')` (read endpoints) or `requireRole('owner')` (write). |
| `db/database.php` | Add `requireRole('viewer')` at top (after Phase 2). |
| `db/database_catalog.php` | Add `requireRole('viewer')` at top. |
| `db/upload_form.php` | Add `requireRole('contributor')` at top. |
| `db/upload_form_admin.php` | Add `requireRole('owner')` at top. |
| `db/upload_form_single.php` | No change — QR guest path, already `Require all granted`. |
| `db/delete_media_files.php` | Add `requireRole('contributor')` at top. |
| `admin/*.php` (~42 files) | Add `requireRole('owner')` at top (or rely on Apache `/admin/` location block during Phases 1–3 transition). |
| `config.php` | Add `GIGHIVE_AUTH_MODE` constant reading from env. |
| `ansible/roles/docker/templates/default-ssl.conf.j2` | Phase 4: Remove all `AuthType Basic` / `Require user ...` / `Require valid-user` blocks. Retain all `Require all denied` blocks, all QR `AuthMerging Off` blocks, all `SetEnvIf` directives. Phase 5: Add `mod_auth_openidc` config. |
| `ansible/roles/docker/templates/.env.j2` | Add `GIGHIVE_AUTH_MODE`, `JWT_SECRET`, `JWT_TTL_SECONDS`; Phase 5 adds `OIDC_GOOGLE_CLIENT_ID`, `OIDC_GOOGLE_CLIENT_SECRET`, `OIDC_MS_CLIENT_ID`, `OIDC_MS_CLIENT_SECRET`, `OIDC_MS_TENANT_ID`, `OIDC_REDIRECT_URI`, `OIDC_CRYPTO_PASSPHRASE`, `OIDC_ROLE_MAP_JSON`, `OIDC_DEFAULT_ROLE`, `OIDC_GROUPS_CLAIM`. See `feature_security_authentication_migration_jwt_oidc_phase5.md` for the full Phase 5 env var spec. |

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

### New Endpoint: `POST /api/login.php`

**Purpose:** Local-user credential exchange. Returns a GigHive JWT.

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

No change to existing endpoints during Phases 1–3. Basic Auth continues to work. JWT is an additive auth path. Only in Phase 4 is Basic Auth removed.

---

### New Endpoint: `GET /api/verify.php`

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
iOS behavior: silently re-login using stored credentials (Phase 3) or re-run the OIDC flow to obtain a new GigHive JWT (Phase 5). There is no client-side refresh token — GigHive issues a 30-day JWT on each successful OIDC login.

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

### Existing Endpoints — Auth Header Change Only

All existing endpoints change only in their auth header expectation:

- **Phase 1–3 (dual):** Accept `Authorization: Basic ...` OR `Authorization: Bearer ...`
- **Phase 4+ (JWT only):** Accept only `Authorization: Bearer ...`

No response shape changes. No query parameter changes. No URL changes.

### QR Guest Endpoints — No Change

`/api/upload-token.php`, `/api/guest-gallery.php`, `/api/guest-stream.php`, `/api/guest-status.php`, `/api/guest-report.php`, `/api/guest-delete.php`, `/db/upload_form_single.php` — all unchanged in every phase.

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

### Phase Sequence

| Phase | Name | Estimated Effort | Deployable independently? |
|-------|------|-----------------|--------------------------|
| 0 | Already done (QR auth, media-stream.php) | — | N/A |
| 1 | JWT Core (ALTER TABLE `users` + PHP auth helpers + `api/login.php` + `api/verify.php`) | 1–2 days | Yes — purely additive; `GIGHIVE_AUTH_MODE` stays `basic` |
| 2 | PHP `requireRole()` guards on all pages; set `GIGHIVE_AUTH_MODE=local` | 1 day | Yes — dual-auth; Basic Auth still active at Apache |
| 0 | iOS `AuthCredential` type refactor — prerequisite for Phase 3 | 1–2 days | Yes — pure iOS refactor; no server change; see `feature_security_authentication_migration_jwt_ios_auth_cred_type.md` |
| 3 | iOS JWT login (replace Basic Auth credentials with Bearer tokens throughout call chain) | 1–2 days (reduced from 2–3 by Phase 0) | Yes — server accepts both during this phase |
| 4 | Remove Apache Basic Auth + add PHP-side JWT to `tus-upload.php` | 0.5 days | **Hard gate: Phase 3 verified first** |
| 5 | OIDC: Google + Microsoft/AAD; iOS PKCE flow | 3–5 days | Yes — additive alongside local JWT |
| 6 | User management UI (`admin/users.php`) + `security_audit_log` table + self-service account deletion (`api/account/delete.php` + iOS settings screen) | 2–3 days | Yes — additive; requires Phase 5 OIDC provisioning to be live |

**Critical dependency:** Phase 4 must not deploy until Phase 3 is live and verified. The `auth_mode_phase4_confirmed: true` flag (a new Ansible group var, default `false`) must be set explicitly before the Phase 4 playbook tasks run. After Phase 4, `tus-upload.php` has its own PHP auth layer, so removing the Apache `/files/` location block is safe.

### Apache Config Transition

**Phase 1–3:** No Apache changes. Basic Auth blocks remain. PHP pages add JWT-checking guards in addition to Apache auth. Both pass simultaneously.

**Phase 4:** Remove all `AuthType Basic` / `AuthName` / `AuthBasicProvider` / `AuthUserFile` / `Require user ...` / `Require valid-user` directives. Retain:
- All `Require all denied` blocks (sensitive paths)
- All QR guest `AuthMerging Off` + `Require all granted` blocks
- All `SetEnvIf` directives (`upload_token_auth`, `gallery_nonce_auth`, `HTTP_AUTHORIZATION`)

**Phase 5:** Add `mod_auth_openidc` at VirtualHost level for browser-based OIDC flows.

### Ansible Changes Summary

- **`.env.j2`:** Add `GIGHIVE_AUTH_MODE`, `JWT_SECRET` (vault), `JWT_TTL_SECONDS`; Phase 5 adds OIDC vars.
- **`group_vars/<env>/secrets.yml`:** Add `jwt_secret` (per-environment, ansible-vault encrypted); Phase 5 adds `oidc_google_client_id`, `oidc_google_client_secret`, `oidc_ms_client_id`, `oidc_ms_client_secret`, `oidc_crypto_passphrase`. See `feature_security_authentication_migration_jwt_oidc_phase5.md` for the full Phase 5 secrets spec.
- **`group_vars/<env>/<env>.yml`** (e.g. `gighive2.yml`, `gighive.yml`, `prod.yml`)**:** Add `gighive_auth_mode: "basic"` (changes to `"local"` when Phase 2 activates), `jwt_ttl_seconds: 2592000`, and `auth_mode_phase4_confirmed: false` (must be set to `true` explicitly before Phase 4 runs). These are per-environment so each can be promoted independently — do **not** place them in `all.yml`.
- **`security_basic_auth` role:** Retained for `GIGHIVE_AUTH_MODE=basic`. Add parallel DB user-seeding task for `local` and `oidc` modes.

### Docker / Container Changes

- **Phase 1–4:** No Docker changes. JWT validation is pure PHP.
- **Phase 5:** `mod_auth_openidc` must be installed in the Apache container:
  ```dockerfile
  RUN apt-get install -y libapache2-mod-auth-openidc && a2enmod auth_openidc
  ```
  This requires a container rebuild and restart.

---

## 6. Backward Compatibility

### Phases 1–3: Full Backward Compatibility

- Apache Basic Auth remains active on all protected paths.
- PHP pages add JWT guards; existing Basic Auth sessions continue to work at the Apache layer.
- No client (iOS app, web browser, curl) needs to change.
- QR guest flows: completely unaffected in every phase.

### Phase 4: Controlled Breaking Change

- `Authorization: Basic ...` on protected paths → 401.
- `Authorization: Bearer <jwt>` required for all account-based paths.
- `tus-upload.php`: PHP-side JWT validation is active; the Apache `/files/` Basic Auth block is removed simultaneously.
- **Web browser:** Users prompted to re-login via new login page.
- **iOS app:** Must be on the JWT-based version before Phase 4 deploys.
- **QR flows:** Unchanged — they use `X-Upload-Token` or `?nonce=` headers/params, not Authorization headers.

### Phase 5: Additive

- OIDC login is an additional path alongside local-user JWT.
- Existing local-user accounts still work via `POST /api/login.php`.
- Web browser users see new "Sign in with Google" / "Sign in with Microsoft" buttons.
- iOS app users see a new OIDC login option alongside username/password.

### What Breaks if Phase 4 Deploys Without Phase 3

- iOS app fails all API calls (sends Basic Auth, receives 401).
- Recovery: revert `default-ssl.conf.j2` to restore `AuthType Basic` blocks, run Ansible. One playbook run.
- The `auth_mode_phase4_confirmed: false` default gate prevents accidental early promotion.

---

## 7. Test Strategy

### Phase 1 — JWT Core

| Test | Method |
|------|--------|
| `POST /api/login.php` valid credentials → 200 + JWT with `role: "owner"` | `curl -X POST -H "Content-Type: application/json" -d '{"email":"...","password":"..."}' https://dev.gighive.app/api/login.php` |
| `POST /api/login.php` wrong password → 401 `invalid_credentials` | Same with bad password |
| `POST /api/login.php` disabled user → 403 `account_disabled` | Set `disabled=1` in DB |
| `GET /api/verify.php` valid token → 200 `{valid: true, role: "owner"}` | Bearer header |
| `GET /api/verify.php` expired token → 401 `{valid: false, error: "token_expired"}` | 1-second TTL token |
| `GET /api/verify.php` tampered token → 401 `{valid: false, error: "invalid_token"}` | Flip one byte in payload |
| Role hierarchy: `owner` JWT passes `requireRole('viewer')` | Call viewer-guarded endpoint → 200 |
| Role hierarchy: `viewer` JWT fails `requireRole('contributor')` | Call upload endpoint with viewer JWT → 403 |

### Phase 2 — PHP Page Guards

| Test | Method |
|------|--------|
| `GET /db/database.php` with viewer JWT → 200 | Bearer header |
| `GET /db/database.php` with no auth → 401 | No header |
| `GET /db/database.php` with Basic Auth (dual-auth) → 200 | Apache still accepts it |
| `POST /api/uploads.php` with viewer JWT → 403 | Viewer cannot upload |
| `POST /api/uploads.php` with contributor JWT → 200/201 | Contributor can upload |
| `/admin/admin.php` with viewer JWT → 403 | Viewer cannot admin |
| `/admin/admin.php` with owner JWT → 200 | Owner can admin |
| All `/api/guest-*.php` with no Authorization header → unchanged responses | QR paths unaffected |

### Phase 3 — iOS App JWT

| Test | Method |
|------|--------|
| Login → JWT in Keychain (not password) | Open app, log in, verify `JWTStore` entry (not `KeychainStore`) |
| `SplashView` guards use `session.token` correctly | Authenticated and unauthenticated states render correctly |
| DatabaseView loads with stored JWT | Navigate; data loads |
| Media playback works with Bearer token | Tap a media item; AVPlayer streams via `MediaResourceLoader` |
| TUS upload succeeds with Bearer token | Upload a video file as owner/contributor |
| Token expiry → `token_expired` response → re-login prompt | Short TTL; app prompts gracefully |
| Invalid/tampered token → `invalid_token` → Keychain cleared, login screen shown | Verify distinct UI behavior from expiry |
| QR guest upload works independently | Scan QR code, upload; no JWT involved |
| Viewer JWT: Upload button hidden | Login as viewer; Upload button not visible |
| Owner JWT: Upload button visible | Login as owner; Upload button visible |

### Phase 4 — Basic Auth Removal

| Test | Method |
|------|--------|
| `curl -u admin:password .../db/database.php` → 401 | Basic Auth rejected |
| `curl -H "Authorization: Bearer <jwt>" .../db/database.php` → 200 | JWT accepted |
| TUS upload `POST /files/` with Bearer token → 201 | PHP-side auth in `tus-upload.php` accepts owner/contributor JWT |
| TUS upload `POST /files/` with QR upload token → 201 | `X-Upload-Token` path unchanged |
| TUS upload `POST /files/` with no auth → 401 | PHP rejects unauthenticated request |
| `/api/guest-gallery.php?nonce=<nonce>` → 200 | QR endpoint still works |
| `/upload/<token>` → 200 | QR landing page loads |
| iOS app (Phase 3 version): all flows end-to-end | Smoke test on dev + staging |

### Phase 5 — OIDC

| Test | Method |
|------|--------|
| Google OIDC login → `users` row with `idp_provider='google'`, `idp_subject` populated | Login via Google; check DB |
| Microsoft/AAD OIDC login → `idp_provider='microsoft'` | Login via Microsoft; check DB |
| OIDC user in "gighive-owners" group → `owner` role in JWT | Configure group mapping; verify JWT claim |
| OIDC user in no mapped group → `viewer` default role | Ungrouped user; verify role |
| iOS PKCE flow → JWT in `JWTStore` | Tap "Sign in with Google"; authorize; verify token |
| Bad `code_verifier` → 401 | Send wrong verifier to token-exchange endpoint |
| Existing local-user accounts still work | Login with email+password form |
| Same email in both Google and Microsoft → two separate `users` rows (different `idp_provider`) | Account-linking edge case; verify no collision |

### Phase 6 — User Management and Audit Log

| Test | Method |
|------|--------|
| `GET /admin/users.php` with no auth → 401 | Unauthenticated request |
| `GET /admin/users.php` with viewer JWT → 403 | `requireRole('owner')` rejects viewer |
| `GET /admin/users.php` with contributor JWT → 403 | `requireRole('owner')` rejects contributor |
| `GET /admin/users.php` with owner JWT → 200, user list rendered | Valid owner token |
| Owner changes user role via UI → `security_audit_log` row with `event_type='role_changed'`, correct `actor_user_id`, `target_user_id`, `detail` JSON | DB inspection after action |
| Owner disables user → `security_audit_log` row `event_type='account_disabled'` → subsequent auth by that user returns 403 `account_disabled` | DB inspection + auth attempt |
| Owner re-enables user → `security_audit_log` row `event_type='account_enabled'` → auth succeeds | DB inspection + auth attempt |
| Owner deletes user row → `security_audit_log` row `event_type='user_deleted'` **survives** the delete (no FK cascade) | DB inspection — log row must persist |
| `DELETE /api/account/delete.php` with valid viewer JWT → 200, `users` row deleted, `self_account_deleted` audit log row present | DB inspection |
| `DELETE /api/account/delete.php` with valid contributor JWT → 200, row deleted, audit row present | DB inspection |
| `DELETE /api/account/delete.php` with no auth → 401 | Unauthenticated request |
| `DELETE /api/account/delete.php` as the tenant's last owner → 409 `last_owner_cannot_delete` | Attempt from sole owner account; verify row survives |
| `DELETE /api/account/delete.php` as a non-last owner → 200, row deleted, `self_account_deleted` audit row with `superadmin_notified` in `detail` | DB inspection after action |
| iOS Settings screen "Delete my account" → confirmation alert → DELETE call → session cleared, login screen shown | Manual end-to-end on device |
| iOS Settings screen "Delete my account" as last owner → confirmation alert → DELETE call → 409 → error alert displayed, session preserved | Manual on device |
| Web settings page `account/delete.php` → confirmation form → DELETE call → redirect to login | Manual browser flow |
| Failed login (bad password) → `security_audit_log` row `event_type='login_failure'`, `actor_user_id=NULL` | Check log after bad login |
| Disabled user auth attempt → `security_audit_log` row `event_type='account_disabled_attempt'` | Check log after disabled-user login |
| `security_audit_log` audit tab loads for tenant owner → shows only rows for that `tenant_id` | Multi-tenant isolation check |

### Regression Checklist (run after each phase on dev → staging → prod)

- [ ] QR code scan → upload → moderation → gallery approval flow works end-to-end
- [ ] `/db/health.php` returns 200 with no auth
- [ ] `/.well-known/apple-app-site-association` returns 200 with no auth
- [ ] Media streaming (video, audio, thumbnails) works for authenticated user via Bearer token
- [ ] Media streaming via gallery nonce works for QR guest
- [ ] Admin moderation queue (approve/reject) works
- [ ] TUS upload works for owner/contributor role
- [ ] TUS upload works for QR guest via upload token

---

## 8. Risks and Rollback Plan

### Risk Matrix

| Risk | Likelihood | Impact | Mitigation |
|------|-----------|--------|-----------|
| Phase 4 deployed before iOS Phase 3 ships | Medium | High — iOS app breaks entirely | `auth_mode_phase4_confirmed: false` default gate; requires explicit `true` in `group_vars` before Phase 4 playbook runs. |
| `tus-upload.php` left unprotected after Phase 4 (if PHP auth not added simultaneously) | Medium | High — unauthenticated uploads possible | Phase 4 is a single atomic deployment: remove Apache Basic Auth block AND add PHP JWT check to `tus-upload.php` in the same release. |
| JWT secret compromise | Low | High — all tokens invalidated; all users must re-login | `JWT_SECRET` in ansible-vault only; per-environment secrets. Rotation: change vault secret, redeploy. |
| OIDC IdP outage during admin login | Low | High — no admin web access | Local fallback: `GIGHIVE_AUTH_MODE=local` keeps `api/login.php` active. Switch via Ansible in minutes. |
| QR flow disrupted by auth changes | Very Low | Very High — live event use case | QR paths are architecturally isolated: `AuthMerging Off` in Apache; PHP JWT code path never intersects with QR token code path. |
| OIDC: same email in Google + Microsoft creates two rows (no account linking) | Medium | Low (SaaS v1) | Document in operator guide. Future: add account-linking UI. Mitigation: `UNIQUE KEY uq_users_idp(idp_provider, idp_subject)` prevents duplicate IdP rows; only email collision is the risk. |
| Token TTL too short → frequent re-login UX friction | Low | Medium | Configurable via `JWT_TTL_SECONDS`. Default: 30 days for all GigHive-issued JWTs (local and OIDC). |
| Admin loses access after htpasswd removal (misconfiguration) | Low | High | Rollback is one Ansible run. Local-user JWT via `api/login.php` is also available immediately. |
| Phase 6: `admin/users.php` bug leaves owner unable to manage users | Low | Medium | DB access via `docker exec -i mysqlServer mysql ...` always available as operator fallback. |
| Phase 6: `security_audit_log` write failure silently drops audit events | Low | Medium | Wrap audit INSERTs in try/catch; log PHP error on failure but do not block the auth action — availability > audit completeness in v1. |
| Phase 6: owner uses delete function to destroy a user row irreversibly | Low | Medium | `user_deleted` audit row survives (no FK cascade). Add a confirmation prompt in the UI before delete. Consider soft-delete (`disabled=1`) as the default; hard-delete requires a second confirmation. |
| Phase 6: user self-deletes account, wiping their `users` row; contributed media is orphaned (no uploader FK) | Low | Low | Media remains in the tenant library; uploader attribution is lost. Owner is notified via audit log. No media is deleted. Acceptable for v1. |
| Phase 6: tenant's last owner self-deletes, leaving tenant with no owner | Low | High | `api/account/delete.php` checks `SELECT COUNT(*) FROM users WHERE tenant_id = ? AND role = 'owner' AND id != ?`. If count is 0 the delete is rejected with 409 `last_owner_cannot_delete`. |
| Phase 6: owner self-deletes (non-last) using a stolen JWT | Low | High | Deletion is immediate and irreversible once the JWT is valid. Mitigation: iOS confirmation alert; web confirmation form; `superadmin_notified` audit entry. Add explicit re-authentication step (password/OIDC re-auth) before delete in a future hardening pass. |

### Rollback by Phase

**Phase 1 (JWT Core):** Non-destructive. Reverse the `ALTER TABLE` (`DROP COLUMN password_hash, disabled`); delete `auth/jwt.php`, `auth/helpers.php`, `api/login.php`, `api/verify.php`. PHP pages revert to Apache-only auth.

**Phase 2 (PHP guards):** Non-destructive. Remove `requireRole()` calls from PHP files. Apache Basic Auth still protects everything.

**Phase 3 (iOS JWT):** Rollback = release iOS update reverting to Basic Auth. App Store review adds ~24h. During that window, server (pre-Phase 4) accepts both auth methods. No server change needed.

**Phase 4 (Basic Auth removal + `tus-upload.php` PHP auth):** Rollback = revert `default-ssl.conf.j2` to restore `AuthType Basic` blocks, revert `tus-upload.php` PHP auth addition, run Ansible. All Basic Auth resumes. One playbook run.

**Phase 5 (OIDC):** Rollback = set `GIGHIVE_AUTH_MODE=local`, run Ansible. OIDC login disabled; local-user JWT continues. OIDC-only users (no `password_hash`) cannot login until admin sets a local password — document this in the operator guide.

**Phase 6 (User management + audit log + self-delete):** Rollback = remove `admin/users.php` and `api/account/delete.php` from the webroot; revert the iOS build to the prior version. The `security_audit_log` table is inert without the UI — leave it in place. Any `users` rows already deleted by self-delete are unrecoverable; the `self_account_deleted` audit row survives and confirms the deletion was self-initiated. No Ansible change required.

---

## Implementation Schedule (Suggested Sequencing)

```
Week 1:  Phase 1 — JWT Core
         - ALTER TABLE users: add password_hash + disabled columns
         - auth/jwt.php, auth/helpers.php (role hierarchy: owner/contributor/viewer)
         - api/login.php (local-user token exchange)
         - api/verify.php (token validation with token_expired vs invalid_token distinction)
         - Deploy to dev + lab; verify endpoints with curl

Week 1:  Phase 2 — PHP requireRole() guards
         - Add requireRole() to all PHP pages using canonical role names
         - Deploy to dev + lab; verify dual-auth (Basic + Bearer both accepted)
         - Deploy to staging; run regression checklist

Week 1b: Phase 0 — iOS AuthCredential refactor (prerequisite for Phase 3; independent of server work)
         - AuthCredential.swift (new): enum with apply(to: URLRequest) and apply(to: [String:String]) overloads
         - AuthSession.swift: credentials tuple → credential: AuthCredential?; UserRole .admin → .owner + .contributor
         - All seven Basic-header construction sites replaced with credential?.apply(to:)
         - UploadClient/TUSUploadClient: retain uploadToken: String? separately; precedence resolved internally
         - MediaPlayerView: both proxy-loader path and direct AVURLAsset dict path updated
         - KeychainStore: add loadCredential(host:) convenience
         - Build + smoke test (no server change needed)
         - Must merge before Phase 3 begins

Week 2:  Phase 3 — iOS app JWT (LoginView + JWTStore + SplashView only; network clients unchanged by Phase 0)
         - JWTStore.swift (new Keychain API for tokens)
         - LoginView.swift: calls api/login.php, sets session.credential = .bearer(token:), stores via JWTStore
         - SplashView.swift: restore session from JWTStore on launch
         - Test on dev + lab; full iOS smoke test including media playback and TUS upload
         - Submit to TestFlight

Week 3:  Phase 4 — Remove Apache Basic Auth (atomic deployment)
         - Set auth_mode_phase4_confirmed: true only after Phase 3 verified on all envs
         - Simultaneously: remove Apache Basic Auth blocks AND add PHP JWT to tus-upload.php
         - Deploy to dev; verify TUS, media-stream, admin, database, QR all work
         - Promote to lab → staging → prod
         - Run full regression checklist on each environment

Later:   Phase 5 — OIDC (Google + Microsoft/AAD)
         - Register app in Google Cloud Console + Azure Entra ID
         - Implement api/oidc/callback.php + api/oidc/token-exchange.php
         - iOS OIDCLoginView.swift (ASWebAuthenticationSession + PKCE)
         - Add mod_auth_openidc to Apache Dockerfile; rebuild container
         - Configure group → role mapping (OIDC_ROLE_MAP_JSON)
         - Deploy to dev; validate end-to-end OIDC flow for all three roles
         - Promote through environments
```

---

## Open Decisions Captured

| Decision | Answer |
|----------|--------|
| Clean JWT cutover (no dual-auth in iOS) | Yes — no tech debt, no customers to break |
| Primary OIDC targets | Google OAuth2/OIDC + Microsoft Entra ID (AAD) |
| OIDC scope — all roles or owner first? | All roles at once (owner, contributor, viewer) |
| OIDC provider for self-hosted operators | Keycloak realm export bundled as an option |
| JWT algorithm | HS256 throughout all phases. IdP `id_token` (RS256) is validated server-side only and never forwarded to clients. |
| Token TTL | 30 days for all GigHive-issued JWTs (local and OIDC). IdP tokens are consumed server-side only — no client-side refresh token. |
| Role naming | DB schema names canonical: `owner`, `contributor`, `viewer`; Apache htpasswd names retired in Phase 4 |
| Separate `user_roles` table? | No — role is inline on `users.role` as per existing schema |
| `password_hash` storage | `ALTER TABLE users ADD COLUMN password_hash` — additive to existing schema |
| Account linking (same email, two IdPs) | Not in scope for v1; two separate `users` rows, documented edge case |
| Session tracking | Stateless JWT; add server-side revocation table if audit requirement emerges |
| `superadmin` role | Reserved in DB schema for GigHive platform operators; not part of this migration |
| Local user creation via admin UI | **No.** Wholesale cutover to federated (OIDC) logins only. No customers to migrate; clean break is the right call. The break-glass `owner` account is seeded by Ansible only and is not visible or creatable in `admin/users.php`. |
| Local users after OIDC cutover | `password_hash` column remains in schema for the break-glass account. All other `users` rows are OIDC-provisioned (`idp_provider != 'local'`). The admin UI does not expose local user management. |
| Security audit log | **Yes — `security_audit_log` table (Phase 6).** Captures all security-relevant events: login success/failure, JWT issuance, token validation failure, role changes, account disable/enable, user delete. Per-attempt logging (no threshold). Retention: indefinite. Consumer: tenant `owner` via admin UI; `superadmin` via direct DB access. Separate from application-level audit (media, QR) — that is a future feature. |
| Admin user management | **`admin/users.php` (Phase 6).** Owner-only. List, role-change, disable/enable, delete OIDC-provisioned users. No local user creation. Reads `security_audit_log` for the tenant. |
| Self-service account deletion | **Yes — `api/account/delete.php` (Phase 6).** Any authenticated non-superadmin user may delete their own account immediately. Required for Apple App Store compliance (guideline 5.1.1) and GDPR/CCPA right-to-erasure. Tenant's last owner is blocked (409 `last_owner_cannot_delete`). Owner self-delete (non-last) is permitted with a UI confirmation step; a `superadmin_notified` detail is written to the audit log. Contributed media is orphaned, not deleted. Surface: iOS settings screen + web settings page (`account/delete.php`). |
