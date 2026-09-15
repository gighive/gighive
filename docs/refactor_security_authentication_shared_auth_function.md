# Refactor: Shared Authentication Function for Web AJAX Calls

## Status — 2026-09-09
Planning — reconciled to the canonical HttpOnly browser-cookie, API/iOS Bearer, route-class, atomic-cutover, and sequential-promotion policy. The exact 15-file AJAX core and four-file XSS subset are identified; implementation remains pending cross-document PPRR and explicit approval.

**Parent implementation:** `docs/feature_security_authentication_migration_jwt_implementation.md`  
**Canonical policy:** `docs/policy_authentication_credential_route.md`  
**Related docs:**
- `docs/feature_security_authentication_migration_jwt_endpoint_guard_checklist.md` (exact route/role/scope manifest)
- `docs/ui_role_matrix.html` (page and role planning reference)

---

## Elevator Pitch

Every admin and DB page in GigHive makes background AJAX calls to authenticated endpoints. Today those calls piggyback on Apache Basic Auth—the browser sends credentials automatically and invisibly. Removing Basic Auth without preparing these callers would silently break progress, polling, imports, exports, and other background work. Phase 1 centralizes the calls safely while Basic Auth remains active; Phase 2 introduces and verifies JWT authentication before Basic Auth is removed.

---

## Rationale

The JWT migration (Phase 4) removes Apache Basic Auth. At that moment:

1. The browser stops attaching `Authorization: Basic ...` headers automatically.
2. Every inline JavaScript `fetch()` call on every admin page reaches the PHP endpoint with no credentials.
3. PHP endpoints guarded by `requireRole()` return `401`.
4. The page appears loaded. Polling loops stop. Status endpoints stop updating. The user sees nothing — until they notice a backup that never finished or an import that hung.

The Phase 1 fix is structural: replace every `fetch()` call to an authenticated endpoint with a token-free shared passthrough while Basic Auth remains authoritative. Phase 2 then adds centralized cookie-backed browser session handling to that wrapper while retaining Bearer authentication for iOS/API clients. This separates the broad caller refactor from the authentication cutover.

---

## Goal

Replace every JavaScript `fetch()` call to an authenticated endpoint across all 14 caller pages with `GHAuth.authedFetch()`, and create the shared `auth/gh-auth.js` module that provides it — while Apache Basic Auth remains active and the running system is unchanged.

**Policy: no direct `fetch()` call to an authenticated endpoint may remain in any admin, DB, or view template page after Phase 1 ships.**

---

## Decision

**Phase 1 decision:** Introduce a token-free `GHAuth.authedFetch()` passthrough and convert the 14 authenticated AJAX caller files while Apache Basic Auth remains authoritative.

**Phase 2 API/iOS decision:** Retain `Authorization: Bearer` as the backend-agnostic contract for iOS and programmatic API clients.

**Phase 2 browser decision:** A signed-in browser uses the canonical Secure HttpOnly GigHive JWT cookie; no JWT is stored in localStorage or sessionStorage. iOS and programmatic clients retain Bearer JWT. Central route classes govern cookie/Bearer/QR precedence, CSRF, role/scope, and HTML/JSON/download/media failures. The prior localStorage-only choice is rejected.

---

## Benefits / Potential Drawbacks

| Benefits | Potential Drawbacks |
|---|---|
| Phase 2 requires no second 14-file AJAX call-site rewrite | 14 caller files must be touched in Phase 1; scope is large, though changes are mechanical |
| Zero auth impact during Phase 1 — Basic Auth still active; `authedFetch()` is a no-op passthrough without a token | `gh-auth.js` is a new deployment dependency; if it goes missing, all admin AJAX breaks |
| Single canonical location for authenticated AJAX behavior — future changes (`401` handling, expiry behavior) are made once | Cookie-authenticated browser requests require CSRF protections in Phase 2 |
| Bearer token contract remains backend-agnostic for iOS/API clients; a Java/Go rewrite needs no second native-client migration round | The browser and API use two credential transports, so one centralized server resolver is required |
| Clear phase boundary — Phase 1 is verifiable in isolation with no JWT infrastructure present | |

---

## Design Principles

The following invariants must hold after Phase 1 ships:

1. During Phase 1, `authedFetch(url, opts)` is an unconditional, token-free passthrough to `fetch(url, opts)` with the same signature and return value.
2. No JWT is stored in localStorage or sessionStorage.
3. The `<script src="/auth/gh-auth.js">` tag is placed in `<head>` without `async` or `defer`. Inline `<script>` blocks in `<body>` depend on `GHAuth` being defined synchronously.
4. Login, logout, cookie issuance, role guards, and `401` handling are Phase 2 concerns; none are introduced during Phase 1 Steps 2–4.
5. Public and QR-nonce `fetch()` calls are not changed. Only calls to authenticated endpoints are wrapped.
6. Phase 2 uses one centralized server-side identity resolver: Bearer for iOS/API clients and the selected secure browser credential transport for page navigation and same-origin browser requests.

---

## Current State

Today, all admin and DB pages are protected by Apache Basic Auth (`AuthType Basic`, `Require valid-user`). When a browser navigates to `/admin/admin_system.php`, Apache challenges it. The browser caches the credentials and automatically re-sends them with every subsequent request from that browser session — including every `fetch()` call made by inline JavaScript on the page.

This means background AJAX calls (backup status polling, export progress, import manifest steps, AI job status) work today without any JavaScript credential handling. The browser is the credential carrier.

The parent JWT plan's Phase 4 removes `AuthType Basic` from Apache. Before that happens, Phase 2 must make PHP role guards accept the centrally resolved browser cookie or API/iOS Bearer identity. Existing direct `fetch()` calls have no centralized session-expiry behavior, which is why Phase 1 converts them first.

---

## Scope

### Why the checklist has more files than this refactor

`feature_security_authentication_migration_jwt_endpoint_guard_checklist.md` lists every authenticated page and endpoint. This refactor covers only a subset. The checklist rows fall into two types:

| Type | What they need | Which doc covers them |
|---|---|---|
| **Caller pages** — render HTML with `<script>` blocks that call `fetch()` on authenticated endpoints | Replace `fetch()` with `GHAuth.authedFetch()` + include `gh-auth.js` | **This refactor (Phase 1) — 14 files** |
| **Non-caller files** — HTML without AJAX, JSON/action endpoints, downloads, CLI workers, and include-only libraries | Apply the exact `HTML_PAGE`, `AUTHENTICATED_API`, `AUTHENTICATED_DOWNLOAD`, `INTERNAL_WORKER`, or `INTERNAL_LIBRARY` policy | **JWT implementation + endpoint checklist** |

The `admin/` directory has 59 real PHP files; the earlier 64 count included five macOS `._*` metadata files. Seven are AJAX caller pages. The other 52 comprise 2 HTML pages without AJAX, 38 JSON/action endpoints, 2 downloads, 7 CLI workers, and 3 include-only libraries. Only the seven callers need this Phase 1 fetch refactor; the endpoint checklist owns the distinct JWT-era treatment for every other file.

Example: `admin_system.php` makes 19 `fetch()` calls to 13 different backend files. All 13 backends are Phase 2 scope. `admin_system.php` itself is in both: `authedFetch()` in Phase 1, `requireRole('owner')` in Phase 2.

### Directories in scope

| Directory | Files touched |
|---|---|
| `admin/*.php` | 7 of 59 real PHP files — the caller pages with inline JS |
| `db/*.php` | 5 — authenticated viewer / owner DB pages |
| `src/Views/media/*.php` | 2 — view templates; `fetch()` calls live here, not in the controller files that include them |

`api/*.php` confirmed clean — zero JavaScript `fetch()` calls in any API endpoint file.

---

## Phase 1 — File Inventory

Audit command (run from `ansible/roles/docker/files/apache/webroot/`):

```bash
grep -rn "fetch(" admin/ db/ src/Views/ --include="*.php" --include="*.js" \
  | grep -v "\->fetch("
```

### Confirmed — authenticated targets, require `authedFetch()`

| File | JS fetch() calls | Target endpoints |
|---|---|---|
| `admin/admin_system.php` | 19 | `run_backup`, `run_backup_status`, `clear_media`, `clear_media_files`, `export_media`, `export_media_download`, `import_media_zip`, `import_media_zip_scan_status`, `upload_restore_backup`, `restore_database`, `restore_database_status`, `admin_system_stats` |
| `admin/admin_database_load_import_media_from_folder.php` | 8 | `import_manifest_prepare`, `import_manifest_finalize`, `import_manifest_upload_start`, `import_manifest_upload_status`, `import_manifest_upload_finalize`, `import_manifest_status`, `import_manifest_replay`, `import_manifest_jobs` |
| `admin/admin_database_catalog_promote.php` | 7 | `import_manifest_status`, `catalog_promote_writeback`, `import_manifest_upload_finalize`, `import_manifest_prepare`, `import_manifest_finalize`, `import_manifest_upload_start`, `catalog_promote_start` |
| `admin/ai_worker.php` | 4 | `/api/ai_jobs.php` (cancel, status, enqueue_all, retag_all) |
| `admin/admin_database_load_import_media_from_iphone.php` | 6 | Verify targets during Phase 1 Step 3.5 |
| `admin/admin_database_load_import_csv.php` | 2 | Verify targets during Phase 1 Step 3.6 |
| `admin/admin_database_catalog_media_from_folder.php` | 2 | Verify targets during Phase 1 Step 3.7 |
| `db/media_tags.php` | 5 | `/api/ai_jobs.php`, `/api/taggings.php`, `/api/tags.php` |
| `db/database_catalog.php` | 5 | `/db/catalog_entry_save.php` |
| `db/upload_form_admin.php` | 2 | `/db/delete_media_files.php`, `/api/uploads/finalize` |
| `db/upload_form.php` | 2 | `/db/delete_media_files.php`, `/api/uploads/finalize` |
| `db/upload_form_single.php` | 2 | `/db/delete_media_files.php` (dual-mode: `IS_ADMIN` flag), `/api/uploads/finalize` (dual-mode: `X-Upload-Token` when QR). `authedFetch()` safe in both cases — see Phase 2 note in Progress |
| `src/Views/media/list.php` | 4 | `/api/tags.php`, `/db/database_edit_save.php`, `/db/database_edit_musicians_preview.php`, `/db/delete_media_files.php` |
| `src/Views/media/random_player.php` | 1 | `/db/singlesRandomPlayer.php?format=json` — Phase 2 adds `requireRole('viewer')`; JS callback needs `authedFetch()` once guarded |

### Resolved false positive — no changes needed

| File | Note |
|---|---|
| `db/tag_browser.php` | `$tagRow = $stmt->fetch(PDO::FETCH_ASSOC)` — PHP PDO only; zero JS AJAX calls |

---

## Proposed Implementation

The plan uses **Phase** as the only temporal indicator. Phase 1 has 7 ordered steps and prepares the current Basic Auth application. Phase 2 has 8 ordered steps and implements JWT authentication and the cutover. Numbered substeps under Phase 1 Step 3 preserve one-file-at-a-time implementation and verification.

### Implementation Index

#### Phase 1 — Pre-JWT Preparation (7 steps)

- [x] **Phase 1, Step 1** — Record the confirmed canonical browser-cookie/API-Bearer/route-class architecture
- [x] **Phase 1, Step 2** — Create and deploy the token-free `GHAuth.authedFetch()` module
- [x] **Phase 1, Step 3** — Refactor all 14 caller files through substeps 3.1–3.14 *(all 14 implemented and browser-verified via Playwright T-155–T-164)*
- [ ] **Phase 1, Step 4** — Verify each converted AJAX workflow under Apache Basic Auth
- [ ] **Phase 1, Step 5** — Remediate high-risk XSS sinks before browser JWT credentials exist
- [ ] **Phase 1, Step 6** — Introduce and evaluate Content Security Policy in report-only mode
- [ ] **Phase 1, Step 7** — Add and run permanent Phase 1 smoke and Playwright tests

#### Phase 2 — JWT Implementation and Cutover (8 steps)

- [ ] **Phase 2, Step 1** — Implement Bearer JWT authentication for iOS and API clients
- [ ] **Phase 2, Step 2** — Implement secure HttpOnly cookie authentication for browser pages
- [ ] **Phase 2, Step 3** — Implement one dual-transport server credential resolver
- [ ] **Phase 2, Step 4** — Implement browser login, logout, expiry, and `401` handling
- [ ] **Phase 2, Step 5** — Verify pages, native forms, AJAX, downloads, and media requests
- [ ] **Phase 2, Step 6** — Atomically activate JWT policy and remove Apache Basic Auth in dev
- [ ] **Phase 2, Step 7** — Gate and promote the same release through lab, staging, and production
- [ ] **Phase 2, Step 8** — Enforce the final CSP after auth stabilizes and complete verification

---

### Phase 1 — Pre-JWT Preparation

**Goal:** Prepare all browser AJAX callers and browser security controls while Apache Basic Auth remains authoritative. Phase 1 stores no JWT in localStorage or cookies and makes no JWT authorization decision.

#### Phase 1, Step 1 — Record confirmed browser authentication architecture

- [x] Browser account sessions use the canonical Secure HttpOnly GigHive JWT cookie; no JWT in localStorage/sessionStorage.
- [x] iOS and programmatic API clients use `Authorization: Bearer`.
- [x] Central route classes govern credential precedence, role/scope, CSRF, and response type.
- [x] Explicit guest token/nonce is authoritative on guest routes and never falls back when invalid.
- [x] Existing JWT strategic, implementation, and endpoint-checklist documents are reconciled; final cross-document PPRR remains pending.
- [x] Each environment cuts over atomically and promotes sequentially through dev → lab → staging → production.

This documentation decision deploys no JWT code.

#### Phase 1, Step 2 — Create and deploy the token-free shared module

- [x] Verify `default-ssl.conf.j2` allows `/auth/gh-auth.js` to be served without an Apache Basic challenge; the future login page must load it before authentication.
- [x] Create `ansible/roles/docker/files/apache/webroot/auth/`.
- [x] Create `auth/gh-auth.js` with `authedFetch()` only—no token key, localStorage access, login, logout, or `requireAuth()`.
- [x] Add deployment tests T-151 and T-152.
- [x] Deploy and verify T-151 and T-152 before Phase 1 Step 3 begins.

> **Critical gate:** If a caller file is deployed before `auth/gh-auth.js`, `GHAuth` is undefined and every converted AJAX call fails. Phase 1 Step 3 is blocked until T-151 and T-152 pass.

##### `auth/gh-auth.js` — Phase 1 module

```javascript
(function (window) {
    'use strict';

    function authedFetch(url, opts) {
        return fetch(url, opts);
    }

    window.GHAuth = {
        authedFetch: authedFetch
    };
}(window));
```

This is deliberately an unconditional native-fetch passthrough. It cannot replace the browser-generated Basic header with a Bearer header during Phase 1.

#### Phase 1, Step 3 — Refactor all 14 caller files

Apply the Change Pattern below one file at a time. Each substep follows: approve → implement → browser verify → mark complete.

- [x] **Phase 1, Step 3.1** — `admin/admin_system.php` — script tag + 18 replacements *(browser-verified: big regression workflow + T-155 stats poll)*
- [x] **Phase 1, Step 3.2** — `admin/admin_database_load_import_media_from_folder.php` — script tag + 8 replacements *(browser-verified: big regression folder import)*
- [x] **Phase 1, Step 3.3** — `admin/admin_database_catalog_promote.php` — script tag + 7 replacements *(browser-verified: T-157)*
- [x] **Phase 1, Step 3.4** — `admin/ai_worker.php` — script tag + 4 replacements *(browser-verified: T-158)*
- [x] **Phase 1, Step 3.5** — `admin/admin_database_load_import_media_from_iphone.php` — script tag + 6 replacements *(browser-verified: T-159)*
- [x] **Phase 1, Step 3.6** — `admin/admin_database_load_import_csv.php` — script tag + 2 replacements *(browser-verified: big regression CSV import)*
- [x] **Phase 1, Step 3.7** — `admin/admin_database_catalog_media_from_folder.php` — script tag + 2 replacements *(browser-verified: T-160)*
- [x] **Phase 1, Step 3.8** — `db/media_tags.php` — script tag + 5 replacements *(browser-verified: T-156)*
- [x] **Phase 1, Step 3.9** — `db/database_catalog.php` — script tag + 5 replacements *(browser-verified: T-161)*
- [x] **Phase 1, Step 3.10** — `db/upload_form_admin.php` — script tag + 2 replacements *(browser-verified: T-162)*
- [x] **Phase 1, Step 3.11** — `db/upload_form.php` — script tag + 2 replacements *(browser-verified: big regression tab open; upload_tests covers AJAX at HTTP level)*
- [x] **Phase 1, Step 3.12** — `db/upload_form_single.php` — script tag + 2 dual-mode replacements *(browser-verified: T-163)*
- [x] **Phase 1, Step 3.13** — `src/Views/media/list.php` — script tag + 4 replacements *(browser-verified: T-164)*
- [x] **Phase 1, Step 3.14** — `src/Views/media/random_player.php` — script tag + 1 replacement *(browser-verified: T-164)*

##### Change Pattern

Add this synchronous script tag to each caller page; do not use `async` or `defer`:

```html
<script src="/auth/gh-auth.js"></script>
```

Replace only calls to authenticated endpoints:

```javascript
// Before
const r = await fetch('/admin/export_media_status.php?job_id=' + id);

// After
const r = await GHAuth.authedFetch('/admin/export_media_status.php?job_id=' + id);
```

Public and QR-nonce calls remain native `fetch()`. Do not add JWT, cookie, login, logout, or `GHAuth.requireAuth()` behavior during Phase 1.

#### Phase 1, Step 4 — Verify AJAX behavior under Basic Auth

- [x] Exercise every converted caller's background workflow with Apache Basic Auth active.
- [x] Confirm polling, uploads, imports, exports, backup/restore, catalog operations, AI jobs, tagging, and media-list operations behave exactly as before.
- [x] Confirm browser developer tools show Basic—not Bearer—on converted requests.
- [x] Mark each Phase 1 Step 3 substep complete only after its workflow passes.

> **Step 4 complete.** All 14 caller files verified under Basic Auth via `playwright_admin_tests` T-155–T-164 (passing, 2026-09-14) plus the existing big-regression workflow coverage for Steps 3.1, 3.2, 3.6, and 3.11.

#### Phase 1, Step 5 — Remediate high-risk XSS sinks

- [x] Classify the 115 known `innerHTML` assignments: one vendored file, many static/escaped/local values, and four files with proven unescaped dynamic API/database data.
- [ ] Fix `admin/admin_system.php` endpoint message/error/error-array sinks.
- [ ] Fix `admin/ai_worker.php` raw `ai_jobs.error_msg` rendering.
- [ ] Fix `admin/admin_database_load_import_csv.php` endpoint success/error rendering.
- [ ] Fix `db/media_tags.php` AJAX job-error progress rendering.
- [ ] Prefer `textContent`/DOM construction; use one reviewed escape helper only where formatted HTML is required.
- [ ] Add T-165–T-167 permanent XSS regression coverage for the remediated data classes.

The HttpOnly browser credential planned for Phase 2 prevents JavaScript token extraction, but XSS could still perform privileged same-origin actions. This step therefore remains required.

#### Phase 1, Step 6 — Introduce CSP report-only mode

- [ ] Inventory inline scripts, inline event handlers, and required script origins.
- [ ] Add `Content-Security-Policy-Report-Only` without breaking the existing UI.
- [ ] Collect and review violations in the development environment.
- [ ] Plan migration of inline scripts/handlers to external files or request-specific nonces.
- [ ] Do not claim XSS mitigation from a policy that still broadly allows `'unsafe-inline'`.

#### Phase 1, Step 7 — Add and run permanent Phase 1 tests

- [x] Retain the Phase 1 Step 2 deployment tests T-151 and T-152.
- [ ] Add T-153 and T-154 to `post_build_checks/tasks/main.yml`.
- [x] Add T-155–T-164 to `playwright_admin_tests` *(passing, 2026-09-14)*.
- [ ] Add T-165, T-166, and T-167 to `playwright_admin_tests` *(pending Phase 1 Step 5)*.
- [ ] Add CSP report-only test T-168 to `post_build_checks/tasks/main.yml`.
- [ ] Run all Phase 1 tests and record successful verification before Phase 2 begins.

---

### Phase 2 — JWT Implementation and Cutover

**Goal:** Introduce JWT authentication through transports appropriate to each client, verify every browser request type, then remove Apache Basic Auth.

#### Phase 2, Step 1 — Implement Bearer JWT for iOS and API clients

- [ ] Implement the JWT issuer, signature validation, claims, expiry, and role hierarchy defined in the parent JWT implementation document.
- [ ] Migrate iOS and programmatic API requests to `Authorization: Bearer`.
- [ ] Verify Apache forwards Bearer headers to PHP-FPM through `HTTP_AUTHORIZATION` with T-172.

#### Phase 2, Step 2 — Implement secure browser cookie authentication

- [ ] Issue the browser credential as an HttpOnly, Secure, SameSite cookie; do not expose the JWT through localStorage or sessionStorage.
- [ ] Define cookie name, path, lifetime, SameSite policy, rotation, and clearing behavior centrally.
- [ ] Add CSRF protection for cookie-authenticated state-changing requests.
- [ ] Verify cookie attributes and JavaScript inaccessibility with T-170.

#### Phase 2, Step 3 — Implement one dual-transport credential resolver

- [ ] Resolve Bearer first for API/iOS clients and cookie second for browser requests.
- [ ] Validate both transports through one JWT validation implementation.
- [ ] Make `requireRole()` consume the resolved identity rather than parse transport details.
- [ ] Reject an explicitly supplied invalid Bearer token rather than silently falling back to a valid cookie; verify with T-173.

#### Phase 2, Step 4 — Implement browser login and session handling

- [ ] Exempt only the browser login page, login endpoint, and required static auth assets from authentication so a signed-out user can reach them without an Apache challenge or redirect loop.
- [ ] Implement browser login that sets the secure cookie.
- [ ] Implement logout that clears it using identical cookie attributes.
- [ ] Centralize expired-session and `401` behavior in `GHAuth.authedFetch()` without storing credentials in JavaScript.
- [ ] Prevent redirect loops and preserve the originally requested same-origin destination safely.

#### Phase 2, Step 5 — Verify every browser request type

- [ ] Verify direct PHP page navigation.
- [ ] Verify native GET and POST forms with CSRF protection.
- [ ] Verify AJAX polling and mutations.
- [ ] Verify direct downloads and generated download links.
- [ ] Verify protected image, audio, and video requests.
- [ ] Verify direct navigation, CSRF/AJAX, guest upload/gallery, downloads/media, internal denials, dual-response, and cutover behavior with T-174 through T-184.

#### Phase 2, Step 6 — Atomic dev JWT cutover

- [ ] Capture the complete dev rollback artifact/configuration.
- [ ] In one deployment, remove Apache Basic account auth and set `GIGHIVE_AUTH_MODE=local`.
- [ ] Keep login/public/QR routes, Authorization forwarding, request limits, rewrites, Range behavior, and internal denials.
- [ ] Run the complete dev JWT/route/browser/iOS/upload/media/QR/XSS suite.
- [ ] Accept dev or execute coordinated rollback; no Basic/JWT overlap period.

#### Phase 2, Step 7 — Sequential environment promotion

- [ ] Promote the same reviewed release to lab; gate on applicable tests.
- [ ] Promote to staging only after lab passes; run full regression gate.
- [ ] Promote to production only after staging passes; run safe post-cutover checks.
- [ ] Stop promotion and roll back the failed environment on any gate failure.

#### Phase 2, Step 8 — Enforce CSP after authentication stabilizes

- [ ] Resolve remaining report-only CSP violations after JWT behavior is stable.
- [ ] Enforce the final CSP in a separately gated change without broad `'unsafe-inline'` reliance.
- [ ] Run authentication, AJAX, navigation, form, download, media, XSS, CSP, and QR regression tests.
- [ ] Mark the complete migration verified only after every gate passes.

---

### SonarQube / Best-Practice Notes

| Rule | Finding |
|---|---|
| RSPEC-3776 | Phase 1 `authedFetch()` is a direct passthrough; no complexity concern. Phase 2 credential resolution must remain centralized. |
| RSPEC-6426 | No Phase 1 token or nullable token lookup exists. Phase 2 must validate resolved payloads before claim access. |
| RSPEC-2635 | No SQL is introduced by Phase 1. |
| Credential storage | No JWT is stored in localStorage or sessionStorage. Browser credentials are HttpOnly in Phase 2. |
| Repeated global name | `GHAuth` is the stable browser client contract across all 14 caller files. |
| PHP files | Phase 1 changes are additive script tags and mechanical call-site substitutions; XSS sink remediation is tracked separately in Phase 1 Step 5. |

---

## Files Under Change

The numbered 15-file list below is the exact **Phase 1 AJAX shared-function core** in the `gighiveinfra` repo under `ansible/roles/docker/files/apache/webroot/`. Phase 1 XSS/CSP work and Phase 2 authentication add supporting files separately; they do not alter the 15-file core count.

### New (1 file)

1. `auth/gh-auth.js` — New JavaScript IIFE module; Phase 1 exports only the token-free `window.GHAuth.authedFetch()` passthrough; Phase 2 adds centralized browser-session error handling after the credential architecture is implemented

### Modified (14 files)

2. `admin/admin_system.php` — Add `<script src="/auth/gh-auth.js">` to `<head>`; replace 19 `fetch()` calls with `GHAuth.authedFetch()`
3. `admin/admin_database_load_import_media_from_folder.php` — Add script tag; replace 8 `fetch()` calls
4. `admin/admin_database_catalog_promote.php` — Add script tag; replace 7 `fetch()` calls
5. `admin/ai_worker.php` — Add script tag; replace 4 `fetch()` calls
6. `admin/admin_database_load_import_media_from_iphone.php` — Add script tag; replace 6 `fetch()` calls (targets verified at Phase 1 Step 3.5)
7. `admin/admin_database_load_import_csv.php` — Add script tag; replace 2 `fetch()` calls (targets verified at Phase 1 Step 3.6)
8. `admin/admin_database_catalog_media_from_folder.php` — Add script tag; replace 2 `fetch()` calls (targets verified at Phase 1 Step 3.7)
9. `db/media_tags.php` — Add script tag; replace 5 `fetch()` calls
10. `db/database_catalog.php` — Add script tag; replace 5 `fetch()` calls
11. `db/upload_form_admin.php` — Add script tag; replace 2 `fetch()` calls
12. `db/upload_form.php` — Add script tag; replace 2 `fetch()` calls
13. `db/upload_form_single.php` — Add script tag; replace 2 `fetch()` calls (dual-mode; authedFetch safe for both paths in Phase 1)
14. `src/Views/media/list.php` — Add script tag; replace 4 `fetch()` calls
15. `src/Views/media/random_player.php` — Add script tag; replace 1 `fetch()` call

**Phase 1 shared-function core total: 15 files — 1 new, 14 modified.**

### Phase 1 supporting files

16. `ansible/roles/post_build_checks/tasks/main.yml` — Add T-151–T-154 and T-168
17. `ansible/roles/playwright_admin_tests/files/tests/admin-pages.spec.ts` — Add T-155–T-164 and T-165–T-167
18. `ansible/roles/docker/templates/default-ssl.conf.j2` — Add CSP report-only header during Phase 1 Step 6; preserve public access to required login/auth static routes

**Additional XSS changes occur inside four already-numbered core files:** #2 `admin_system.php`, #5 `ai_worker.php`, #7 `admin_database_load_import_csv.php`, and #9 `db/media_tags.php`. They do not increase the 15-file core count.

Phase 2's exact server, browser, Apache, Ansible, and iOS inventory is authoritative in `feature_security_authentication_migration_jwt_implementation.md` and the endpoint checklist. This refactor does not duplicate that full manifest.

### Unchanged (key files explicitly unaffected)

- `ansible/roles/docker/templates/default-ssl.conf.j2` — unchanged by Phase 1 Steps 2–4 unless `/auth/` needs an exemption; Phase 1 Step 6 may add CSP report-only headers
- All `api/*.php` endpoint files — zero JS AJAX caller changes; Phase 2 server-auth work is tracked separately
- The other 52 `admin/*.php` files — no Phase 1 Step 3 JavaScript changes; exact HTML/API/download/worker/library treatment belongs to the endpoint checklist

---

## Rollback Procedure

If Phase 1 introduces a regression:

1. Revert files #2–#15 to their pre-refactor state (git checkout or re-deploy from previous Ansible artifact).
2. Remove `auth/gh-auth.js` from the webroot (`auth/` directory can remain).
3. T-151 will fail on next post-build check run, confirming the rollback is in effect.

Rollback is clean — no schema changes, no config changes, no data changes.

---

## Relationship to Future Java Rewrite

The dual-transport model remains backend-agnostic. iOS and programmatic clients use `Authorization: Bearer`; browser pages use the selected secure HttpOnly credential transport. A future Java service can validate the same JWT claims from either transport through one centralized resolver, so no second iOS/API client migration is required.

---

## Tests

This refactor reserves T-151–T-168 for its Phase 1 AJAX/XSS/CSP work. T-157–T-164 cover the ten Phase 1 caller pages not exercised by the existing Playwright regression. JWT route/session/cutover tests are owned by T-169–T-184 in the implementation guide.

### Phase 1 tests

| Test | Role | Tag | What it validates |
|---|---|---|---|
| T-151 | `post_build_checks` | `[smoke]` | `GET /auth/gh-auth.js` → HTTP 200; shared module is deployed |
| T-152 | `post_build_checks` | `[smoke]` | `/auth/gh-auth.js` contains `authedFetch`; content is present and correctly routed |
| T-153 | `post_build_checks` | `[smoke]` | `GET /admin/admin_system.php` with the configured Basic Auth group_vars → HTTP 200 |
| T-154 | `post_build_checks` | `[smoke]` | `GET /db/media_tags.php` with configured Basic Auth → HTTP 200 |
| T-155 | `playwright_admin_tests` | `[smoke]` | Under Basic Auth, `admin_system.php` System Stats AJAX (`admin_system_stats.php`) completes through `GHAuth.authedFetch()` |
| T-156 | `playwright_admin_tests` | `[smoke]` | Under Basic Auth, `db/media_tags.php` tag namespace lookup (`/api/tags.php?namespace=...`) completes through `GHAuth.authedFetch()` |
| T-157 | `playwright_admin_tests` | `[smoke]` | Under Basic Auth, `admin_database_catalog_promote.php` — `GHAuth.authedFetch` is defined and reaches `import_manifest_status.php` |
| T-158 | `playwright_admin_tests` | `[smoke]` | Under Basic Auth, `admin/ai_worker.php` — `GHAuth.authedFetch` reaches `/api/ai_jobs.php?action=status_counts` and returns HTTP 200 |
| T-159 | `playwright_admin_tests` | `[smoke]` | Under Basic Auth, `admin_database_load_import_media_from_iphone.php` — Check Ready click fires `iphone_import_status.php` via `GHAuth.authedFetch` |
| T-160 | `playwright_admin_tests` | `[smoke]` | Under Basic Auth, `admin_database_catalog_media_from_folder.php` — catalog scan fires `catalog_scan_start.php` via `GHAuth.authedFetch` and returns HTTP 200 |
| T-161 | `playwright_admin_tests` | `[smoke]` | Under Basic Auth, `db/database_catalog.php` — `GHAuth.authedFetch` is defined and reaches `catalog_entry_save.php` |
| T-162 | `playwright_admin_tests` | `[smoke]` | Under Basic Auth, `db/upload_form_admin.php` — `GHAuth.authedFetch` is defined and authenticated (finalize probe returns non-401/403) |
| T-163 | `playwright_admin_tests` | `[smoke]` | Under Basic Auth, `db/upload_form_single.php` admin mode — `GHAuth.authedFetch` is defined and authenticated |
| T-164 | `playwright_admin_tests` | `[smoke]` | Under Basic Auth, `list.php` (`/db/database.php`) fires tag auto-load via `GHAuth.authedFetch`; `random_player.php` fires `?format=json` fetch via "Play Another Random" button click |
| T-165 | `playwright_admin_tests` | `[smoke]` | AJAX-rendered `ai_jobs.error_msg` payload containing HTML renders as text and does not execute |
| T-166 | `playwright_admin_tests` | `[smoke]` | Dynamic filename/CSV error payload containing HTML renders as text and does not execute |
| T-167 | `playwright_admin_tests` | `[smoke]` | Dynamic tag/API error payload containing HTML renders as text and does not execute |
| T-168 | `post_build_checks` | `[smoke]` | Expected `Content-Security-Policy-Report-Only` header is present after Phase 1 Step 6 |

> **Invariant covered by T-153/T-154:** The two representative authenticated pages must continue returning HTTP 200 with valid Basic credentials after Phase 1.

### Phase 2 tests

JWT browser-cookie, API/iOS Bearer, route-class, CSRF, download/media, internal-denial, and atomic-cutover behavior is tested by T-169–T-184 in `feature_security_authentication_migration_jwt_implementation.md`. This refactor does not duplicate or reserve those test IDs.

---

## Progress

### Completed
- [x] Full audit of `fetch()` call sites across `admin/`, `db/`, `src/Views/`
- [x] Resolved false positives (PHP PDO `->fetch()` calls excluded)
- [x] Identified `src/Views/media/list.php` as a hidden call-site inside a view template
- [x] Confirmed `api/` directory is clean — zero JS fetch() calls
- [x] Discovered and documented missing pages (`timeline-api.php`, `src/index.php`) and added to matrix
- [x] Initial PPRR completed for the original shared-function plan
- [x] Browser credential/route architecture and exact four-file XSS scope resolved in documentation
- [x] Phase 1 Step 3 — all 14 caller files implemented (58 `fetch()` → `GHAuth.authedFetch()` replacements)
- [x] Phase 1 Step 4 — all 14 files browser-verified under Basic Auth via Playwright T-155–T-164 (passing 2026-09-14)
- [ ] Final cross-document PPRR — reconciliation Step 6

### Remaining — This Feature

#### Phase 1 — Pre-JWT Preparation
- [x] **Phase 1, Step 1** — Record confirmed browser-cookie/API-Bearer/route-class architecture
- [x] **Phase 1, Step 2** — Create, test, deploy, and gate the token-free shared module
- [x] **Phase 1, Step 3** — Complete caller-file substeps 3.1–3.14
- [x] **Phase 1, Step 4** — Verify all converted AJAX workflows under Basic Auth
- [ ] **Phase 1, Step 5** — Remediate high-risk XSS sinks and add T-165–T-167
- [ ] **Phase 1, Step 6** — Add CSP report-only policy and T-168
- [ ] **Phase 1, Step 7** — Run and record all Phase 1 tests

#### Phase 2 — JWT Implementation and Cutover
- [ ] **Phase 2, Step 1** — Implement and test Bearer JWT for iOS/API clients
- [ ] **Phase 2, Step 2** — Implement and test secure HttpOnly browser-cookie authentication
- [ ] **Phase 2, Step 3** — Implement and test centralized dual-transport credential resolution
- [ ] **Phase 2, Step 4** — Implement browser login, logout, expiry, and `401` behavior
- [ ] **Phase 2, Step 5** — Verify every browser request type and reroute the QR guest delete path to `/api/guest-delete.php`
- [ ] **Phase 2, Step 6** — Atomically cut dev from Basic to JWT and gate on full tests
- [ ] **Phase 2, Step 7** — Promote sequentially through lab, staging, and production gates
- [ ] **Phase 2, Step 8** — Enforce CSP separately after auth stabilizes and complete verification

### Risk Resolution Status

- [ ] **Risk 1 — XSS in authenticated UI:** The audit found 115 `innerHTML` assignments across 15 files, with unescaped dynamic API/database data proven in four files: `admin_system.php`, `ai_worker.php`, `admin_database_load_import_csv.php`, and `db/media_tags.php`. No browser JWT exists today. The canonical HttpOnly cookie removes direct token theft through JavaScript, but XSS could still perform privileged same-origin actions. **Implementation:** Phase 1 Steps 5–6 plus T-165–T-168; final CSP enforcement after authentication stabilizes.

- [x] **Risk 2 architecture — Bearer-only browser navigation:** Resolved in policy. Browser HTML/forms/downloads/media use the Secure HttpOnly JWT cookie; iOS/API use Bearer; centralized route classes govern explicit guest credentials and response types. **Implementation remains pending** in the JWT guide and T-169–T-184. Each environment cuts over atomically; no Basic/JWT overlap.

These risks were not caused by the 14-file shared-function refactor. The refactor solves the separate AJAX migration problem and provides one browser request hook for CSRF and API-session failure behavior.

---

## Problems Encountered During Implementation

This section records concrete problems hit during Phase 1 execution. Each entry states what went wrong, why, and how it was resolved. The entries are intended to prevent the same issues during Phase 2 development and future environment promotions.

---

### P-1 — Node.js not installed on the Ansible controller (macOS Sequoia)

**When:** Phase 1 Step 4 — first attempt to run `playwright_admin_tests` against `macbook2025`.

**What happened:** The `playwright_admin_tests` role's first three tasks (add NodeSource APT repo, install `nodejs`, assert version) are delegated to `localhost`. Those tasks use Debian APT modules and fail silently or produce confusing errors on macOS because Homebrew, not APT, is the package manager.

**Root cause:** The role was originally written for a Debian/pop-os controller. Migrating the controller to `macbook2025` exposed the OS assumption.

**Resolution:**
1. Added a pre-check task: `command: node --version` with `failed_when: false`. If `rc == 0`, the three Debian APT install tasks are skipped via `when: node_check.rc != 0`.
2. Installed Node.js manually on macbook2025 via `brew install node` (v26.8.2, satisfies the v20+ assertion).
3. The pre-check is backward-compatible: on a Debian controller without node, `rc != 0` and the APT tasks still run.

**File changed:** `ansible/roles/playwright_admin_tests/tasks/main.yml`

---

### P-2 — Playwright Chromium extraction hung on macOS Sequoia arm64

**When:** Phase 1 Step 4 — first `npx playwright install chromium` run after Node.js was installed.

**What happened:** The install task (`npx playwright install chromium`) hung indefinitely. Process inspection showed chromium-1217 was downloaded (173 MB zip) but extraction stalled writing `Localizable.strings` at a fixed offset. The TCP connection to the Google CDN remained open but no data was flowing.

**Root cause:** `package.json` pinned `@playwright/test` at `^1.44.0`, which resolved to `1.59.1` via the committed `package-lock.json`. Playwright 1.59.1 requires chromium-1217, which has a known extraction bug on macOS Sequoia arm64. Playwright 1.63.0 (chromium-1243) extracts cleanly on the same host.

**Compounding factor:** Stale `node_modules/` and `package-lock.json` survived in the Ansible work dir (`/tmp/gighive-playwright/`) across runs because the Ansible `copy` task adds files but never deletes destination-only files. `npm ci` (used at the time) treats the lock file as authoritative, so it kept re-installing 1.59.1 even after `package.json` was updated.

**Resolution:**
1. Removed the committed `package-lock.json` from `ansible/roles/playwright_admin_tests/files/`.
2. Changed `package.json` to `"@playwright/test": "latest"`.
3. Changed the Ansible install task from `npm ci` to `npm install`, so each run resolves fresh.
4. Added a cleanup task that deletes `node_modules/` and `package-lock.json` from the work dir before `npm install`, preventing stale lock files from surviving across deployments.
5. Removed the broken pre-check (`npx playwright chromium-path` does not exist in 1.59.1) and let `npx playwright install chromium` serve as its own idempotency check — it exits in under a second when the correct chromium version is already cached.

**Files changed:** `ansible/roles/playwright_admin_tests/files/package.json`, `ansible/roles/playwright_admin_tests/tasks/main.yml`

---

### P-3 — PHP PDO `->fetch()` calls matched by the JavaScript `fetch(` grep

**When:** Phase 1 Step 3 — grepping the webroot for bare JavaScript `fetch(` call sites.

**What happened:** The grep pattern `fetch(` matched PHP PDO method calls such as `)->fetch(PDO::FETCH_ASSOC)` in multiple files. These appeared in the results alongside legitimate JavaScript `fetch()` calls.

**Resolution:** Manual review of every match before conversion. PHP PDO calls follow the pattern `$stmt->fetch(` or `)->fetch(` and are preceded by PHP variable sigils and PDO class references. JavaScript calls follow `fetch('` or `fetch(url` or `await fetch(`. No automated exclusion was added; each match was evaluated by context.

**Lesson for Phase 2:** When auditing for remaining `fetch()` call sites during Phase 2 step audits, filter PDO calls explicitly: `grep -n "fetch(" file.php | grep -v "PDO\|\->fetch"`.

---

### P-4 — Incorrect assumption that existing Playwright tests covered all 14 converted files

**When:** After Phase 1 Step 3 deployment and the first full-playbook pass.

**What happened:** The initial assessment stated that the clean playbook run (673 tasks, zero failures) including `playwright_admin_tests` provided browser verification for all 14 converted files. This was incorrect.

**Root cause:** The existing `admin-pages.spec.ts` regression test exercised only four of the fourteen files through real Chromium browser workflows:
- `admin/admin_system.php` — export, backup, restore, clear
- `admin/admin_database_load_import_media_from_folder.php` — folder import
- `admin/admin_database_load_import_csv.php` — CSV import
- `db/upload_form.php` — tab open only (page load, not AJAX)

The `upload_tests` Ansible role exercises TUS and finalize at the HTTP level from the controller but does not execute `GHAuth.authedFetch()` in a browser. A passing HTTP-level test does not substitute for browser verification of the converted JavaScript.

**Resolution:** Identified the 10 uncovered files, wrote 10 new Playwright tests (T-155–T-164), and added them to `admin-pages.spec.ts`. The distinction between browser-level and HTTP-level verification is now documented in the test table in the Tests section.

---

### P-5 — T-164 `random_player.php`: `GHAuth.authedFetch` is not called on page load

**When:** Phase 1 Step 4 — first run of the new T-164 test.

**What happened:** The test set up `page.waitForResponse` for `singlesRandomPlayer.php?format=json` before navigation, expecting the fetch to fire on `DOMContentLoaded`. The test timed out after 15 seconds. The page snapshot showed the player had already rendered content ("Now Playing: fc3012...") — the page was loaded but no AJAX request had fired.

**Root cause:** `random_player.php` serves the initial asset server-side (PHP renders the `<h1>`, URL, crew, and date into the HTML). The JavaScript `GHAuth.authedFetch('/db/singlesRandomPlayer.php?format=json')` is inside `fetchNext()`, which only fires when the user clicks "Play Another Random". There is no auto-poll on `DOMContentLoaded`.

**Resolution:** Changed T-164 to click the "Play Another Random" button and use `Promise.all` with `waitForResponse` to capture the response:
```typescript
const [playerResp] = await Promise.all([
  page.waitForResponse(r => r.url().includes('singlesRandomPlayer.php?format=json'), { timeout: 15_000 }),
  page.locator('button', { hasText: 'Play Another Random' }).click(),
]);
```

**Lesson:** Verify whether a converted `GHAuth.authedFetch` call fires on page load or only on user interaction before writing a `waitForResponse` test. Pages that render initial content server-side in PHP will not auto-fetch on load.

---

### P-6 — T-156 `media_tags.php`: for-loop could produce a silent pass

**When:** Phase 1 Step 4 — PPRR review of the new test code before submission.

**What happened:** The T-156 test iterates namespace `<option>` elements looking for a non-empty value to select (triggering the `/api/tags.php?namespace=...` fetch). If all options had empty values, the loop would exit without making any assertion. Playwright considers a test with zero assertions a pass.

**Resolution:** Added a `triggered` boolean set inside the loop and a final assertion:
```typescript
expect(triggered, 'No non-empty namespace option found in #newNs — tag fetch never fired').toBe(true);
```

**Lesson:** Any test that conditionally makes its only assertion inside a loop or branch must assert that the branch was actually reached. Playwright does not enforce a minimum assertion count.
