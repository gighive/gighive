# GigHive Security Recommendations — 2026-05-30

**Prepared by:** Cascade  
**Based on:** Review of all `docs/security*.md` and `docs/refactor_security*.md` files plus Auth/Cert Type × Use Case matrix

---

## 1. Where We Stand Today

| Layer | Current State | Key Gaps |
|---|---|---|
| **Authentication** | Apache HTTP Basic Auth (htpasswd) — `admin`, `uploader`, `viewer` | Matrix rates Basic Auth as **Never** for online video streaming use cases |
| **Authorization** | Apache `Require user` directives + three realms | No application-layer role enforcement (JWT phase not started) |
| **TLS** | TLS 1.3, strong ciphers; HSTS optional | HSTS not enforced in production yet |
| **Browser headers** | Bundle A done (X-Content-Type-Options, Permissions-Policy) | Cache-Control, HSTS, CSP still open from 2026-02-18 ZAP scan |
| **WAF** | ModSecurity + CRS in templates | CRS enforcement mode not confirmed for all envs |
| **Container runtime** | Apache container runs as root; no `cap_drop`; MySQL on `0.0.0.0:3306` | Runtime hardening gaps documented in `refactor_security_docker_hardened_images.md` |
| **Supply chain** | Community images (`ubuntu`, `mysql:8.4`, `httpd:2.4`, `alpine`) | DHI swaps for throwaway images (priority 1-3) not yet executed |
| **Password policy** | 12-char minimum enforced (Option C implemented) | Hash format divergence (`$apr1$` vs `$2y$`) accepted/deferred |

---

## 2. The Three GigHive Personas vs. The Auth Matrix

The Auth/Cert Type × Use Case matrix places GigHive squarely in the **Online Video Streaming** column (with **Mobile App** for uploaders on iOS). Key matrix ratings for those columns:

| Auth Type | Online Video Streaming | Mobile App | Notes |
|---|---|---|---|
| **None** | ✅ Browse-only / unauthenticated preview | — | Valid for public event highlight pages |
| **Token (OAuth2/JWT)** | ✅ **Primary** | ✅ **Primary** | Authorization Code + PKCE for mobile |
| **Token (API Key)** | Limited/internal only | Limited/internal only | Acceptable for machine-to-machine (tusd ↔ Apache) |
| **Certificate (mTLS)** | ✅ CDN → Origin | ✅ Service-to-service | Cloudflare authenticated origin pull |
| **Basic Auth** | ❌ **Never** | ❌ **Never** | Current state — this is the primary auth gap |
| **SAML** | ❌ Not applicable | ❌ Not applicable | Only relevant if/when enterprise IdP is required |
| **Kerberos** | ❌ Not applicable | ❌ Not applicable | — |

### Removal of Basic Auth

Bundles B and C make no changes to authentication. Bundle D removes Basic Auth incrementally across its phases — Basic Auth remains active as a safety net throughout Phases 1–3 and is only cut over in Phase 4.

| Phase | Basic Auth status | JWT status |
|---|---|---|
| **Bundle B** (ZAP headers) | ✅ Still active — no auth change | No JWT yet |
| **Bundle C** (DHI + runtime hardening) | ✅ Still active — no auth change | No JWT yet |
| **Bundle D — Phase 1** (DB + JWT core) | ✅ Still active | JWT deployed alongside — dual-auth period begins |
| **Bundle D — Phase 2** (protect PHP pages) | ✅ Still active at Apache layer | PHP pages now also check `requireRole()` — enforced at app layer |
| **Bundle D — Phase 3** (media proxy) | ⚠️ **Option A:** still active for `/video/*`, `/audio/*` — **Option B (recommended): removed for media** — PHP proxy takes over at this point | JWT used for media delivery via signed URLs |
| **Bundle D — Phase 5** (iOS app) | ✅ Still active as fallback during iOS rollout | JWT in iOS Keychain — **must be complete and validated before Phase 4** |
| **Bundle D — Phase 4** | ❌ **Removed** — remaining `AuthType Basic` directives stripped from `default-ssl.conf.j2`; if Option B was used, only PHP-page directives remain at this point | JWT is the sole auth mechanism; `rotate_basic_auth.sh` retired |

Phase 4 is the hard cutover and should be its own deliberate release window after all access patterns have been validated on JWT alone during the dual-auth period.

---

### Persona Mapping

#### Persona 1 — Admin (event organizer, band manager, wedding coordinator)

- **Matrix recommendation:** Token (OAuth2/JWT) as primary; SAML as secondary for enterprise
- **Current:** Basic Auth — admin realm on `/admin.php`, `/db/upload_form_admin.php`, `/admin/restore_database.php`
- **Next target:** JWT with `admin` role stored in `users` table; retain Apache-level deny for `/src`, `/vendor`, `/app`, `/app/cache` as defense-in-depth
- **Longer-term:** Optional OIDC (Google/Keycloak) for organizations running GigHive for recurring events

#### Persona 2 — Uploader (musician, videographer, contributing fan)

- **Matrix recommendation:** Token (OAuth2/JWT) with PKCE for mobile; API Key acceptable for automated/machine integrations
- **Current:** Basic Auth — "GigHive Upload" realm on `/db/upload_form.php`, `/api/uploads.php`, `/files` (tusd proxy)
- **Next target:** JWT Bearer header on upload API endpoints; iOS app stores token in Keychain (Phase 5 of `security_auth_jwt_token_migration.md`)
- **API Key consideration:** Headless/automated upload clients (e.g., a capture rig posting directly) could use a scoped API key rather than interactive JWT — this is an additive option, not a replacement

#### Persona 3 — Viewer (concertgoer, wedding guest, general audience)

- **Matrix recommendation:** Token (OAuth2/JWT) for authenticated viewing; None is valid for browse-only / unauthenticated preview
- **Current:** Basic Auth — "GigHive Protected" realm on `/video/*`, `/audio/*`, `/db/*`
- **Next target:** JWT with `viewer` role; introduce a **public preview tier** where event highlights or designated public assets are accessible without authentication (matrix explicitly rates None as valid for video streaming preview)
- **Media proxy consideration:** PHP media proxy (`/media.php?file=...&token=...`) enables signed URL delivery for Cloudflare caching — eliminates the Cloudflare-vs-Basic-Auth caching conflict documented in `problem_cloudflare_cached_error_messages.md`

---

## 3. Recommended Work Packages — Prioritized

### Bundle B — ZAP Remediation (already queued, low risk)

From `security_remediations_20260218.md` — these are the remaining open ZAP warnings post-Bundle A:

1. **Cache-Control hardening** — classify routes: `no-store` for authenticated/admin, explicit policy for public HTML, long-lived for static assets. Align Cloudflare caching rules.
2. **HSTS staged rollout** — dev: `max-age=300` → staging: `max-age=86400` → prod: `max-age=31536000`. Do NOT enable `preload` or `includeSubDomains` until every subdomain is HTTPS-confirmed.
3. **CSP in Report-Only mode** — start with `Content-Security-Policy-Report-Only: default-src 'self'`; observe violations; iterate before enforcing.
4. **COEP** — defer unless cross-origin isolation is concretely needed.

### Bundle C — Container Runtime Hardening (from `refactor_security_docker_hardened_images.md`)

Self-contained, no auth system change required:

1. Swap `mysql:8.4` → `docker/mysql:8.4` (DHI — easiest win, lowest risk)
2. Swap `alpine` → `docker/alpine` in `install.sh.j2` (throwaway container for chown)
3. Swap `httpd:2.4` → `docker/httpd:2.4` in `install.sh.j2`, `install.ps1.j2`, `rotate_basic_auth.sh`
4. Add to `docker-compose.yml.j2`:
   - `cap_drop: [ALL]` on all services
   - `security_opt: ["no-new-privileges:true"]` on all services
   - `pids_limit` per service
   - MySQL port: `3306:3306` → `127.0.0.1:3306:3306`

### Bundle D — JWT Authentication Phases 1–5 (the major next security architecture step)

Follows the full plan in `security_auth_jwt_token_migration.md`:

**Phase 1 — Core auth infrastructure:**
- DB migration: `users` table (`id`, `sub`, `email`, `password_hash`, `created_at`, `disabled`) + `user_roles` table as defined in `refactor_security.md` *(file deleted — superseded by `feature_security_authentication_migration_jwt.md`)*
- `auth/jwt.php` — generation + validation (HS256 minimum; RS256 preferred for future OIDC interop)
- `api/login.php` + `auth/login.html` — credential exchange → JWT
- `requireRole()` / `hasRole()` helpers
- Token expiry: 30-day sliding or short-lived (15 min) + refresh token — **decision needed** (see open questions)
- Migrate existing `admin`/`uploader`/`viewer` htpasswd users to `users` table (passwords must be reset — htpasswd hashes are one-way)

**Phase 2 — Protect PHP pages:**
- `/db/database.php`, `/db/list.php` → `requireRole('viewer')`
- `/db/upload_form.php` → `requireRole('uploader')`
- `/db/upload_form_admin.php`, `/changethepasswords.php`, `/admin.php` → `requireRole('admin')`
- `/api/uploads.php` → `requireRole('uploader')`

**Phase 3 — Media file strategy (decision point):**
- **Option A (simpler):** Keep Apache Basic Auth for `/video/*` and `/audio/*`; PHP pages use JWT. Dual-auth during transition.
- **Option B (enables CF caching, aligns matrix):** PHP media proxy at `/media.php` validates JWT and streams file. Signed URL pattern with short-lived media tokens. Eliminates the Cloudflare Basic Auth conflict entirely.
- **Recommendation: Option B** — it aligns with the matrix (Token/JWT primary for streaming), resolves the Cloudflare caching issue, and enables the viewer public-preview tier.

**Phase 5 — iOS app (must complete before Phase 4 activates):**
- Login screen + Keychain storage + `Authorization: Bearer` header
- Role-conditional UI (hide upload button for viewers)
- Validate all iOS access patterns against JWT before Basic Auth is pulled

**Phase 4 — Remove Apache Basic Auth (final cutover — iOS JWT must be live first):**
- Remove remaining `AuthType Basic` directives from `default-ssl.conf.j2` (keep all `Require all denied` blocks); if Option B was used in Phase 3, only PHP-page directives remain
- Retire `rotate_basic_auth.sh` — htpasswd is no longer the auth store; replace with a DB user-provisioning equivalent or remove
- Update install scripts to provision users in the `users` table instead of htpasswd

### Bundle E — OIDC / Federated Auth (longer-term)

From `refactor_security.md` *(file deleted — superseded by `feature_security_authentication_migration_jwt.md`)*:
- `mod_auth_openidc` in the Dockerfile
- `GIGHIVE_AUTH_MODE` env var: `local | oidc` (note: `basic` mode is deprecated and removed as part of Bundle D Phase 4; new installs default to `local`)
- Keycloak realm export (`infra/keycloak/realm-gighive.json`) for self-hosted operators
- Group-to-role mapping via `OIDC_ROLE_MAP`
- This is the path for team/corporate deployments; quickstart/local installs stay on `local` mode

### Bundle F — mTLS for CDN → Origin (Cloudflare Authenticated Origin Pull)

From the matrix: Certificate (mTLS) is valid for CDN→Origin in streaming use cases:
- Configure Cloudflare Authenticated Origin Pull (client cert validation at Apache)
- Add `SSLVerifyClient require` / `SSLCACertificateFile` for Cloudflare CA cert
- Prevents origin from being hit directly, bypassing Cloudflare WAF and DDoS protection

---

## 4. Sequenced Roadmap

```
Now → Bundle B (ZAP: Cache-Control + HSTS + CSP report-only)     low risk, queued
      Bundle C (DHI swaps + runtime hardening)                     low-medium risk, self-contained

Next → Bundle D Phase 1+2 (JWT core + PHP page protection)        high value, medium risk
       Bundle D Phase 3 (Media proxy / signed URLs)                enables Cloudflare alignment

Later → Bundle D Phase 5 (iOS JWT) first, then Phase 4 (Remove Basic Auth)  breaking change; Phase 5 must be live before Phase 4 activates
         Bundle E (OIDC/federated auth)                            additive, operator opt-in
         Bundle F (mTLS origin pull)                               infrastructure, low app impact
         Ubuntu → docker/debian base (after entrypoint redesign)   high effort, low urgency
```

---

## 5. Open Decisions Before Phase 1 Implementation

Per the open questions at the end of `security_auth_jwt_token_migration.md`:

1. **Media file protection** (Option A or B) — **Recommendation: Option B** (PHP proxy + signed URLs)
2. **Session tracking** — stateless JWT sufficient vs. server-side session table for audit/revocation. Recommendation: start stateless, add revocation table if audit logging becomes a requirement.
3. **Token refresh** — short-lived access token (15 min) + longer-lived refresh token (7 days) vs. 30-day expiry. Recommendation: short-lived + refresh for production; 30-day acceptable for initial local-install use case.
4. **Audit logging** — log auth events (login, logout, token refresh, failed auth) to a dedicated table? Deferred unless a compliance requirement arises.
5. **Public preview tier** — which events/assets are designated public? Admin-controlled flag on the Event record vs. a separate public link with a signed token? Needs product decision.

---

## 6. Cross-Reference to Existing Docs

| This recommendation | Prior art |
|---|---|
| ZAP Bundle B | `docs/security_remediations_20260218.md` §5 remaining items |
| DHI + runtime hardening | `docs/refactor_security_docker_hardened_images.md` recommended order 1–4 |
| JWT Phase 1–5 | `docs/security_auth_jwt_token_migration.md` implementation checklist |
| Local users + OIDC modes | `docs/refactor_security.md` §2, §3, §5 *(file deleted — see `feature_security_authentication_migration_jwt.md`)* |
| Auth realms (current) | `docs/security_apache_realms.md` |
| Password unification | `docs/refactor_security_password_unification.md` (Option C done; A deferred) |
| Three-persona/three-realm setup | `docs/codingChanges/20250926securityauthchanges.md` |
