# Feature Implementation: JWT Authentication Migration

## Status — 2026-09-09
Planning — rewritten to the canonical browser-cookie, API/iOS Bearer, centralized route-class, atomic-cutover, and sequential-promotion policy. No implementation is authorized by this document.

**Strategic plan:** `docs/feature_security_authentication_migration_jwt.md`  
**Canonical policy:** `docs/policy_authentication_credential_route.md`  
**Endpoint inventory:** `docs/feature_security_authentication_migration_jwt_endpoint_guard_checklist.md`  
**Web prerequisite:** `docs/refactored_security_authentication_shared_auth_function.md`  
**Completed iOS prerequisite:** `docs/feature_completed_security_authentication_migration_jwt_ios_auth_cred_type.md`  
**OIDC follow-on:** `docs/feature_security_authentication_migration_jwt_oidc_phase5.md`

---

## Elevator Pitch

GigHive will replace three shared installation passwords with individual identities without breaking its server-rendered pages, mobile app, protected media, downloads, or anonymous QR event access. Browser users authenticate through a secure HttpOnly GigHive JWT cookie; iOS and programmatic clients use Bearer JWTs; QR guests remain restricted to their event token or nonce. One route policy enforces credentials, roles, tenant scope, and client-appropriate errors before Apache Basic Auth is removed.

---

## Scope

This document implements JWT Migration Phases 0–4:

1. Pre-JWT browser and iOS prerequisites.
2. JWT core and schema alignment.
3. Central route policy, browser cookie, CSRF, role/tenant/event guards, and internal-route denial.
4. iOS Bearer and browser-session behavior.
5. Atomic Basic-to-JWT cutover in each environment.

JWT Migration Phase 5 OIDC and Phase 6 account management/audit remain separate documents.

### In scope

- Local API/iOS login returning Bearer JWT JSON.
- Browser login setting a Secure HttpOnly JWT cookie without exposing the token to JavaScript.
- Browser logout and session-expiry behavior.
- One normalized authentication context and route-class resolver.
- Role, tenant, event, and guest-capability scope.
- CSRF for cookie-authenticated unsafe requests.
- HTML, JSON, download, guest, and media response behavior.
- TUS, media Range, direct downloads, and QR credential precedence.
- Direct HTTP denial for internal workers, libraries, `src/` implementation code, and `vendor/`.
- iOS JWT storage and Bearer requests while preserving exclusive QR upload-token requests.
- Per-environment atomic cutover and sequential dev → lab → staging → production gates.

### Out of scope

- OIDC provider implementation (JWT Migration Phase 5).
- User-management UI, audit-log UI, and self-service deletion (Phase 6).
- Final Tenant Administrator versus Platform Admin surface split beyond the route/role assignments in the endpoint checklist.
- A PHP-to-Java rewrite; the contract is designed to remain portable.

---

## Confirmed Architecture

### Credential transport

| Client/flow | Credential |
|---|---|
| Browser account session | `__Host-gighive_session` Secure HttpOnly JWT cookie |
| iOS/programmatic API | `Authorization: Bearer <JWT>` |
| QR upload | Route token or `X-Upload-Token` |
| Guest gallery | Nonce in the endpoint's documented query/body/header position |
| Pre-cutover account access | Apache Basic Auth only |

No browser JWT is stored in localStorage or sessionStorage.

### Route classes

- `PUBLIC`
- `LOGIN`
- `HTML_PAGE`
- `AUTHENTICATED_API`
- `AUTHENTICATED_DOWNLOAD`
- `GUEST_UPLOAD`
- `GUEST_GALLERY`
- `MIXED_MEDIA`
- `DUAL_RESPONSE`
- `INTERNAL_WORKER`
- `INTERNAL_LIBRARY`

### Precedence invariants

1. An explicitly supplied invalid credential fails; it does not fall back to broader authority.
2. Guest upload token/nonce is authoritative when explicitly supplied on its guest/mixed route.
3. Authenticated API uses explicit Bearer when present; otherwise browser cookie.
4. Browser HTML uses cookie authentication.
5. Media and downloads never redirect to HTML login.
6. Public routes do not broaden output because an incidental cookie is present.
7. Route resolution occurs before role and tenant/event authorization.

---

## Open Decisions — Implementation Gates

Implementation must not begin past the named gate until the user approves:

1. **Revocation:** Request-time account/token-version validation, denylist, or expiry-only. Recommended for SaaS: request-time user `disabled`, role, tenant, and token-version validation so administrative changes take effect on the next request.
2. **JWT lifetime/renewal:** Exact TTL and browser renewal behavior.
3. **CSRF:** Exact server token derivation/rotation. Recommended: server-generated token bound to JWT `jti`, rendered to forms/meta without exposing the JWT, validated with `hash_equals`.
4. **Resolver API:** Exact PHP class/function names shown below are proposed, not approved source signatures.
5. **Unsafe HTML expiry:** Exact session-expired page and recovery UX; unsafe requests are never replayed.
6. **Stable error codes:** Final JSON/guest/media code vocabulary.
7. **Key rotation:** Active/previous key validation window.
8. **CSP:** Report collection and enforcement policy.
9. **Role name:** **Resolved.** `platform_admin` is canonical (DB enum renamed from `superadmin`, JWT payload, `requireRole()` calls, all docs). See BABRRR ALTER in the strategic plan.

---

## Implementation Index

### JWT Migration Phase 0 — Prerequisites

- [ ] **Phase 0, Step 1** — Verify completed iOS `AuthCredential` prerequisite
- [ ] **Phase 0, Step 2** — Complete Web Refactor Phase 1 under Basic Auth
- [ ] **Phase 0, Step 3** — Verify XSS/CSP prerequisite tests and documentation PPRR

### JWT Migration Phase 1 — JWT Core and Session Foundations

- [ ] **Phase 1, Step 1** — Add PHP JWT dependency
- [ ] **Phase 1, Step 2** — Apply users-table bootstrap and BABRR live schema alignment
- [ ] **Phase 1, Step 3** — Add auth/cookie/CSRF/cutover configuration to all environments
- [ ] **Phase 1, Step 4** — Implement JWT generation/validation and normalized claims
- [ ] **Phase 1, Step 5** — Implement API/iOS login and Bearer verification
- [ ] **Phase 1, Step 6** — Implement browser login/logout and HttpOnly cookie lifecycle
- [ ] **Phase 1, Step 7** — Add Phase 1 permanent tests

### JWT Migration Phase 2 — Route Policy and Server Enforcement

- [ ] **Phase 2, Step 1** — Implement route-policy constants and normalized authentication context
- [ ] **Phase 2, Step 2** — Implement centralized credential resolver and no-fallback semantics
- [ ] **Phase 2, Step 3** — Implement HTML/JSON/download/media response helpers
- [ ] **Phase 2, Step 4** — Implement centralized CSRF generation and validation
- [ ] **Phase 2, Step 5** — Classify and guard all HTTP pages/endpoints from the endpoint checklist
- [ ] **Phase 2, Step 6** — Deny direct HTTP to internal workers/libraries/source/vendor paths
- [ ] **Phase 2, Step 7** — Implement mixed upload, media, download, and dual-response behavior
- [ ] **Phase 2, Step 8** — Add Phase 2 permanent route-class tests

### JWT Migration Phase 3 — Client Readiness

- [ ] **Phase 3, Step 1** — Implement iOS Bearer login, storage, restoration, and expiry recovery
- [ ] **Phase 3, Step 2** — Extend `GHAuth.authedFetch()` for cookie-session `401` handling and CSRF header
- [ ] **Phase 3, Step 3** — Add browser login/logout/session-expiry behavior
- [ ] **Phase 3, Step 4** — Run static/unit/client tests while environment remains in Basic mode
- [ ] **Phase 3, Step 5** — Confirm rollback artifact and cutover readiness

### JWT Migration Phase 4 — Atomic Cutover and Promotion

- [ ] **Phase 4, Step 1** — Capture environment rollback state
- [ ] **Phase 4, Step 2** — Atomically activate JWT mode and remove Apache Basic account auth in dev
- [ ] **Phase 4, Step 3** — Run full dev post-build, browser, iOS, upload, media, QR, XSS, and rollback tests
- [ ] **Phase 4, Step 4** — Promote same release to lab and gate on tests
- [ ] **Phase 4, Step 5** — Promote same release to staging and gate on full regression tests
- [ ] **Phase 4, Step 6** — Promote same release to production and run safe checks
- [ ] **Phase 4, Step 7** — Accept cutover or execute coordinated rollback

---

## Files Under Change

Exact endpoint-by-endpoint assignments are owned by `feature_security_authentication_migration_jwt_endpoint_guard_checklist.md`. This list defines the implementation subsystems; Step 4.7 of the documentation reconciliation must populate the final endpoint manifest before code implementation approval.

### New — server

1. `ansible/roles/docker/files/apache/webroot/auth/jwt.php` — GigHive JWT issue/validate/reason API; issuer, audience, expiry, `jti`, subject, role, and tenant claims.
2. `ansible/roles/docker/files/apache/webroot/auth/helpers.php` — Route constants, normalized `AuthContext`, credential resolver, role/scope enforcement, and response dispatch.
3. `ansible/roles/docker/files/apache/webroot/auth/csrf.php` — Central browser CSRF issue/render/validate behavior.
4. `ansible/roles/docker/files/apache/webroot/auth/login.php` — Browser GET/POST login; sets HttpOnly JWT cookie; never returns JWT to JavaScript.
5. `ansible/roles/docker/files/apache/webroot/auth/logout.php` — CSRF-protected browser logout; clears cookie attributes exactly.
6. `ansible/roles/docker/files/apache/webroot/auth/gh-auth.js` — Phase 0 passthrough, later cookie-session `401` and CSRF request behavior; never reads a JWT.
7. `ansible/roles/docker/files/apache/webroot/api/login.php` — API/iOS login; returns Bearer JWT JSON and never sets browser cookie.
8. `ansible/roles/docker/files/apache/webroot/api/verify.php` — API/iOS Bearer validation with stable expired/invalid codes.

### Modified — server/configuration

9. `ansible/roles/docker/files/apache/webroot/config.php` — Auth mode, JWT, cookie, issuer/audience, and CSRF constants from environment.
10. `ansible/roles/docker/templates/.env.j2` — Inject approved auth variables.
11. `ansible/roles/docker/templates/default-ssl.conf.j2` — Preserve forwarding/routing/limits; add internal denials and login access; atomically remove Basic account auth at cutover; CSP policy.
12. `ansible/roles/docker/files/apache/webroot/composer.json` — Add vetted `firebase/php-jwt` dependency.
13. `ansible/roles/docker/files/apache/webroot/composer.lock` — Lock dependency graph.
14. `ansible/roles/docker/files/mysql/externalConfigs/create_media_db.sql` — Bootstrap `password_hash` and `disabled`; add any approved token-version field if revocation decision requires it.
15. `ansible/roles/post_build_checks/tasks/main.yml` — Permanent route, credential, cookie, CSRF, internal-denial, cutover, and rollback tests.
16. `ansible/roles/playwright_admin_tests/files/tests/admin-pages.spec.ts` — Browser login/session/AJAX/form/download/XSS flows.
17. Environment `group_vars` and Vault secret files — Values listed below; user manages Vault content.

### Existing webroot route surface requiring classification

18. `admin/` — 59 real PHP files: 9 HTML, 38 JSON/action, 2 downloads, 7 internal workers, 3 include-only libraries.
19. `db/` — 14 PHP files: public health, authenticated HTML/API, mixed upload, and dual-response routes.
20. `api/` — 12 PHP files: 4 authenticated API (`ai_jobs.php`, `tags.php`, `taggings.php`, `uploads.php`), 5 guest gallery, 1 guest-token validation, 1 mixed upload (`tus-upload.php`), and 1 mixed media. Current `api/uploads.php` has no QR upload-token handling.
21. `src/index.php` — Mixed authenticated/guest upload front controller.
22. `src/Jobs/*.php` — Internal workers; direct HTTP denied.
23. `src/` implementation classes and `vendor/` PHP — Internal libraries; direct HTTP denied.
24. `timeline/timeline-api.php`, root public pages, registration, and `.well-known` — Explicit public classifications.

### Proven XSS changes

25. `admin/admin_system.php` — Remove unescaped dynamic endpoint message/error HTML insertion.
26. `admin/ai_worker.php` — Render `ai_jobs.error_msg` as text/DOM, not raw HTML.
27. `admin/admin_database_load_import_csv.php` — Escape or DOM-render endpoint success/error values.
28. `db/media_tags.php` — Render AJAX job errors without raw `innerHTML` interpolation.

### Modified/new — iOS

29. `GigHive/Sources/App/JWTStore.swift` — Secure Bearer token storage.
30. `GigHive/Sources/App/LoginView.swift` — API/iOS login returning Bearer only; iOS 14-compatible networking.
31. `GigHive/Sources/App/SplashView.swift` — Restore/expire Bearer session.
32. `GigHive/Sources/App/AuthSession.swift` — Token expiry/role/session state as required by final implementation.

The completed `AuthCredential` and network-client refactor should avoid further header-construction changes unless source inspection proves otherwise.

---

## Phase 0 — Prerequisites

### Phase 0, Step 1 — Completed iOS credential abstraction

Verify `feature_completed_security_authentication_migration_jwt_ios_auth_cred_type.md` remains complete and current source still has mutually exclusive `.basic`, `.bearer`, and `.uploadToken` behavior. QR upload token must continue winning over account session credentials.

### Phase 0, Step 2 — Web shared-auth/XSS/CSP preparation

Complete `refactored_security_authentication_shared_auth_function.md` under Apache Basic Auth:

- Deploy token-free `GHAuth.authedFetch()` first.
- Convert the 14 authenticated AJAX caller files.
- Verify Basic remains the wire credential.
- Fix the four proven XSS files.
- Introduce/evaluate CSP report-only mode.
- Pass T-151–T-168.

### Phase 0, Step 3 — Gate

Do not begin Phase 1 code until Phase 0 tests and the web-refactor PPRR pass.

---

## Phase 1 — JWT Core and Session Foundations

### PHP dependency

Use Composer to add a vetted `firebase/php-jwt` 6.x release published at least seven days earlier. Commit `composer.json` and `composer.lock`; do not use a floating version.

### JWT claims contract

Every GigHive JWT contains:

```json
{
  "sub": "42",
  "email": "owner@example.com",
  "role": "owner",
  "tenant_id": 7,
  "jti": "cryptographically-random-id",
  "iat": 1756000000,
  "exp": 1756003600,
  "iss": "configured-gighive-issuer",
  "aud": "configured-gighive-audience"
}
```

Rules:

- HS256 GigHive-issued tokens; per-environment secret in Vault.
- Validate algorithm, signature, issuer, audience, expiry, required claims, and claim types.
- Never log raw token or cookie.
- Do not trust role/tenant from request input outside validated claims/current-account checks.
- Final revocation decision controls whether current user state/token version is queried per request.

### Browser cookie

Recommended approved-policy defaults:

- Name: `__Host-gighive_session`.
- `Secure`, `HttpOnly`, `SameSite=Lax`, `Path=/`, no Domain.
- Expiry no later than JWT expiry.
- Rotate on login and privilege change.
- Clear with identical attributes.
- HTTPS required in every JWT-enabled environment; never weaken Secure/`__Host-` for HTTP.

### Separate login contracts

**API/iOS `POST /api/login.php`:** Returns Bearer JWT JSON; must not emit `Set-Cookie`.

**Browser `GET/POST /auth/login.php`:** GET renders login; POST validates credentials, rotates and sets browser cookie, then redirects only to a validated same-origin relative destination. The JWT is never returned to browser JavaScript.

**Browser `POST /auth/logout.php`:** Requires valid CSRF, clears cookie, and redirects to login.

### Configuration

Proposed group vars (exact values per environment; secrets in Vault):

```yaml
gighive_auth_mode: basic
jwt_ttl_seconds: <approved value>
jwt_issuer: <approved issuer>
jwt_audience: <approved audience>
gighive_session_cookie_name: __Host-gighive_session
gighive_session_cookie_samesite: Lax
gighive_auth_cutover_confirmed: false
gighive_csp_report_only: <approved policy>
gighive_csp_enforced: <approved policy>
```

Vault:

```yaml
jwt_secret: <per-environment secret>
csrf_secret: <per-environment secret>
```

All variables must exist in dev, lab, staging, and production group vars before templates reference them.

---

## Phase 2 — Central Route Policy and Server Enforcement

### Proposed normalized context

```php
final readonly class AuthContext
{
    public function __construct(
        public string $credentialType,
        public ?int $userId,
        public ?string $role,
        public ?int $tenantId,
        public ?int $eventId,
        public ?string $jwtId,
        public ?int $expiresAt
    ) {}
}
```

Final constructor/API requires approval and RSPEC-107 review before implementation.

### Resolver contract

The shared resolver receives an explicit route class and returns `AuthContext` or dispatches the class-appropriate failure. It distinguishes absent credentials from present-invalid credentials.

```text
PUBLIC: no identity required; incidental cookie does not alter response
HTML_PAGE: browser cookie; missing/invalid GET redirects safely; wrong role HTML 403
AUTHENTICATED_API: explicit Bearer, otherwise cookie; JSON failures; CSRF on cookie unsafe method
AUTHENTICATED_DOWNLOAD: explicit Bearer, otherwise cookie; never return login HTML as file
GUEST_UPLOAD: explicit upload token authoritative; otherwise Bearer, then cookie
GUEST_GALLERY: nonce authoritative; cookie ignored for guest authorization
MIXED_MEDIA: explicit nonce/token authoritative; otherwise Bearer, then cookie; preserve Range
DUAL_RESPONSE: choose explicit mode before auth; do not rely on Accept alone
INTERNAL_WORKER / INTERNAL_LIBRARY: direct HTTP denied
```

### Role and scope

After identity resolution:

1. Validate minimum role from one hierarchy.
2. Enforce `tenant_id` on every tenant-owned query/resource.
3. Enforce event/resource scope.
4. Apply guest capability limitations.
5. Allow platform scope only on explicitly platform-authorized routes.

### Response behavior

- HTML GET unauthenticated: safe login redirect.
- HTML unsafe request expired: no replay; clear session and return session-expired HTML behavior.
- API: stable JSON `401`/`403`.
- Download/media: status only; never login-page substitution.
- Guest: guest-contract error; no account login redirect.
- Public: remain public despite invalid incidental cookie.

### CSRF

- Cookie-authenticated POST/PUT/PATCH/DELETE require CSRF.
- Native forms carry hidden field.
- Browser AJAX carries `X-CSRF-Token` via `GHAuth.authedFetch()`.
- Bearer, QR token, and nonce requests do not inherit browser cookie authority.
- Missing/invalid CSRF returns `403` and performs no mutation.
- Validate Origin as defense in depth where reliable.

### Internal access

- Add Apache denials for exact worker/library/source/vendor paths.
- Retain/add explicit CLI checks in workers.
- Add missing CLI guard to `admin/import_manifest_worker.php`.
- Do not add `requireRole()` to CLI-only code.

---

## Phase 3 — Client Readiness

### Browser `GHAuth`

Phase 0 module is a native-fetch passthrough. Phase 3 extends it only for:

- Reading a CSRF token rendered in HTML (not the JWT).
- Adding `X-CSRF-Token` to cookie-authenticated unsafe AJAX.
- Detecting API `401` and initiating safe session-expiry UX.
- Never storing, reading, logging, or returning the JWT.

Native browser credentials use same-origin cookie behavior. Direct navigation, forms, downloads, and media do not depend on JavaScript headers.

### Browser login/logout

- Login page reachable while signed out.
- Valid relative `next` only; reject absolute/protocol-relative/control-character values.
- Expired safe navigation can redirect to login.
- Unsafe requests are never persisted/replayed.
- Logout is CSRF-protected.

### iOS

- API login returns Bearer JSON and no browser cookie.
- Store JWT in `JWTStore`/Keychain.
- Restore only non-expired token.
- `AuthCredential.bearer` supplies Authorization.
- `.uploadToken` remains exclusive for QR upload.
- Guest gallery client remains nonce-scoped.
- Use iOS 14-compatible networking and tests from `testing_ios.md`.

---

## Phase 4 — Atomic Cutover

### Pre-cutover gate

Before changing one environment:

- Phase 0–3 code and tests pass in dev.
- Exact endpoint checklist is complete.
- JWT/cookie/CSRF/CSP variables exist.
- HTTPS works.
- Login/logout and break-glass account work.
- Internal paths are denied.
- Rollback artifact/configuration is captured.
- `gighive_auth_cutover_confirmed` is explicitly approved for that environment.

### Atomic change

In one environment deployment:

1. Remove Apache Basic account-auth directives.
2. Set `GIGHIVE_AUTH_MODE=local`.
3. Activate PHP route-policy guards.
4. Keep login/public/QR routes reachable as classified.
5. Keep Authorization forwarding, rewrites, limits, direct denials, and media Range behavior.
6. Restart/reload required services.
7. Run applicable tests immediately.

There is no Basic/JWT overlap period inside an environment.

### Promotion

```text
Dev passes completely
  → Lab deploy and gate
  → Staging deploy and full regression gate
  → Production controlled cutover and safe gate
```

A failed gate stops promotion.

---

## DDL and BABRR

Fresh environments update `ansible/roles/docker/files/mysql/externalConfigs/create_media_db.sql`.

Baseline live alignment, after confirming both columns are absent:

```bash
docker exec -i mysqlServer bash -c 'mysql -u root -p"$MYSQL_ROOT_PASSWORD" media_db -e "ALTER TABLE users ADD COLUMN password_hash varchar(255) DEFAULT NULL AFTER idp_subject, ADD COLUMN disabled tinyint(1) NOT NULL DEFAULT 0 AFTER password_hash;"'
```

The user executes schema changes manually through `docs/process_backup_alter_backup_rebuild_restore.md`. Do not use the `db_migrations` role. If token-version revocation is approved and needs DDL, update bootstrap SQL and provide a separate exact BABRR live command before implementation.

---

## Tests

T-151–T-168 remain owned by the web-refactor prerequisite. T-169–T-184 were checked as unassigned when this guide was rewritten; final namespace verification occurs in documentation Step 5.

| Test | Owner | What it proves |
|---|---|---|
| T-169 | `post_build_checks` | API/iOS login returns Bearer JWT JSON and no `Set-Cookie` |
| T-170 | `playwright_admin_tests` | Browser login sets correct HttpOnly/Secure/host-only/Path/SameSite cookie and exposes no JWT to JS/storage |
| T-171 | `playwright_admin_tests` | Browser logout requires CSRF, clears cookie, and protected navigation no longer succeeds |
| T-172 | `post_build_checks` | Valid Bearer reaches PHP-FPM through `HTTP_AUTHORIZATION` and validates issuer/audience/claims |
| T-173 | `post_build_checks` | Invalid explicit Bearer plus valid cookie returns JSON `401`; no fallback |
| T-174 | `playwright_admin_tests` | HTML missing/expired cookie redirects safely; wrong role receives HTML `403`; unsafe action not replayed |
| T-175 | `playwright_admin_tests` | Cookie mutation succeeds with valid CSRF and fails with no mutation on missing/invalid CSRF |
| T-176 | `upload_tests` | Valid QR upload token works with incidental cookie; invalid token plus valid cookie fails |
| T-177 | `post_build_checks` | Valid gallery nonce works with incidental cookie; invalid nonce plus valid cookie fails |
| T-178 | `post_build_checks` | Mixed media supports nonce/token/Bearer/cookie independently and preserves Range/no-fallback |
| T-179 | `playwright_admin_tests` | Authenticated browser download succeeds; failure never returns login HTML or partial file |
| T-180 | `post_build_checks` | All internal workers deny HTTP; intended CLI execution remains functional |
| T-181 | `post_build_checks` | Admin libraries plus exact `src/`/`vendor/` implementation paths deny direct HTTP |
| T-182 | `post_build_checks` | `singlesRandomPlayer.php` explicit HTML/JSON modes use correct failure format and equal authorization scope |
| T-183 | `post_build_checks` | Role hierarchy and cross-tenant/event denial use normalized context |
| T-184 | `post_build_checks` | Post-cutover Basic rejected; browser cookie and API Bearer accepted; public/QR routes remain intended |

Additional requirements:

- Every new/modified protected `/admin/` or `/api/` endpoint receives the permanent unauthenticated `401` or direct-denial smoke check required by SKILL.md.
- Credential-log test triggers invalid Bearer/cookie/token/nonce and proves raw values absent from Apache, PHP, and audit logs.
- Tests creating DB rows or files define safe setup and cleanup; destructive production fixtures are prohibited.
- iOS tests follow `testing_ios.md` and are updated in the same change window.

---

## Rollback

### Before cutover

JWT code is additive/inert. Revert the application/configuration change while Apache Basic remains authoritative. Schema columns can remain inert; do not drop them without the user executing the documented BABRR process.

### After cutover

Restore the complete captured pre-cutover release for that environment:

- Apache Basic account-auth configuration.
- `GIGHIVE_AUTH_MODE=basic`.
- PHP code compatible with Basic enforcement.
- Compatible browser/iOS client state.
- Previous CSP/configuration as required.

Do not restore only one variable or template. The user runs Ansible, verifies the environment, and promotion remains stopped.

### OIDC outage after Phase 5

Use local JWT browser-cookie break-glass login. Reverting to Basic is a full environment rollback, not the first-line IdP outage response.

---

## SonarQube / Best-Practice Notes

| Concern | Requirement |
|---|---|
| RSPEC-3776 | Split route resolution, JWT validation, response dispatch, and CSRF into single-purpose functions |
| RSPEC-6426 | Validate nullable context/claims before access; no unchecked array keys |
| RSPEC-2635 | Prepared statements only; never place raw credential material in SQL/logs |
| RSPEC-107 | Review `AuthContext` constructor parameter count; use typed value objects/factories if needed |
| Duplicated auth logic | Endpoint declares route class and role; shared helpers parse credentials |
| Hardcoded config | Cookie/JWT/CSRF/CSP/TTL/issuer/audience/cutover values come from group vars/env |
| Secrets | Vault only; no token/cookie/nonce/password logging |
| PHP responses | Correct status plus `exit`; HTML/API/download/media behavior selected explicitly |
| Dependencies | Use Composer lock and minimum release-age rule |

---

## Full Execution Trace

### Browser HTML success

Login GET → pre-auth protection → credential POST → validate user → issue JWT/cookie → safe relative redirect → resolve cookie → role/tenant check → HTML 200.

### Browser API mutation

Page renders CSRF token → `GHAuth.authedFetch()` adds CSRF header → cookie auto-sent → API route resolves cookie → CSRF → role/tenant → mutation → JSON response.

### API/iOS success

API login → Bearer JSON/no cookie → Keychain → Authorization Bearer → API route → JWT/context → role/tenant → JSON/media/TUS response.

### QR upload with logged-in cookie

Explicit upload token + incidental cookie → `GUEST_UPLOAD` selects token → validates event capability → cookie ignored for guest authorization → upload/finalize remains event-scoped.

### Guest gallery/media with logged-in cookie

Explicit nonce + incidental cookie → guest/media policy selects nonce → invalid nonce fails without cookie fallback → valid nonce remains event-scoped.

### Invalid Bearer plus valid cookie

Explicit Bearer detected → validation fails → JSON/media `401` → cookie not evaluated.

### Expired browser session

HTML safe GET → clear cookie → safe login redirect. API AJAX → JSON `401` → centralized browser session-expiry UX. Unsafe request → no mutation and no replay.

### Download/media failure

No valid credential → HTTP status appropriate to route; no login HTML; media Range semantics preserved when authorized.

### Cutover failure

Tests fail → stop promotion → restore complete pre-cutover artifact/configuration → user runs Ansible → verify Basic behavior and public/QR paths → investigate in failed environment.

---

## Resiliency, Security, and Operability

### Resiliency

- Sequential environment gates and captured rollback state.
- Login/public/QR route smoke tests prevent lockout.
- No single stale client header controls browser navigation.
- Worker queues retain their existing retry/cleanup behavior; auth changes do not execute workers through HTTP.

### Security

- HttpOnly/Secure browser JWT cookie and HTTPS prerequisite.
- CSRF on cookie-authenticated unsafe requests.
- No invalid-explicit-credential fallback.
- Tenant/event scope after identity resolution.
- Four proven XSS sinks fixed before privileged browser sessions are accepted.
- Internal source/vendor/worker/library HTTP denial.

### Operability

Log route class, credential type, outcome, role result, tenant/event identifier, and correlation ID without credential material. Track `401`, `403`, expiry, CSRF rejection, and credential type by route class. Preserve diagnostics for rewritten media paths. Document key rotation, revocation, and cutover rollback before production.

---

## Progress

### Completed

- [x] Canonical browser-cookie/API-Bearer/route-class policy approved as reconciliation source.
- [x] Completed iOS `AuthCredential` prerequisite documented.
- [x] Exact current server counts identified.
- [x] Four proven XSS files identified.
- [x] Implementation guide rewritten to remove browser localStorage and Basic/JWT overlap.

### Remaining — This Feature

- [ ] **Documentation Step 4.7** — Rebuild exact endpoint guard/route-class checklist.
- [ ] **Documentation Step 4.8** — Reconcile OIDC Phase 5.
- [ ] **Documentation Step 4.9** — Align web shared-auth refactor.
- [ ] **Documentation Step 5** — Run stale-guidance and T-number search.
- [ ] **Documentation Step 6** — Run cross-document PPRR.
- [ ] Resolve the nine Open Decisions gates in this document.
- [ ] Obtain explicit implementation approval before changing source/configuration.

### Remaining — Follow-on

- [ ] JWT Migration Phase 5 OIDC implementation.
- [ ] JWT Migration Phase 6 user management/audit/account lifecycle.
- [ ] Tenant Administrator/Platform Admin route separation and tenant-scoping implementation.
