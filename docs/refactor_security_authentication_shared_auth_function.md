# Refactor: Shared Authentication Function for Web AJAX Calls

## Status — 2026-09-07
Planning — PPRR complete. Pending implementation approval.

**Parent doc:** `docs/feature_security_authentication_migration_jwt_implementation.md` (Phase 2 Companion)  
**Related docs:**
- `docs/feature_security_authentication_migration_jwt_endpoint_guard_checklist.md` (scope reference, AJAX audit Open Question)
- `docs/ui_role_matrix.html` (full page inventory and role assignments)

---

## Elevator Pitch

Every admin and DB page in GigHive makes background AJAX calls to authenticated endpoints. Today those calls piggyback on Apache Basic Auth — the browser sends credentials automatically and invisibly. When the JWT migration removes Basic Auth, those calls will silently fail with `401`: progress bars stop, jobs appear stuck, exports disappear mid-flight, and the page appears loaded while the backend has stopped responding. This refactor insulates the JWT cutover from that risk by replacing every `fetch()` call with a shared `GHAuth.authedFetch()` wrapper now, while Basic Auth is still active, so the cutover itself becomes a config flip rather than a broad code change.

---

## Rationale

The JWT migration (Phase 4) removes Apache Basic Auth. At that moment:

1. The browser stops attaching `Authorization: Basic ...` headers automatically.
2. Every inline JavaScript `fetch()` call on every admin page reaches the PHP endpoint with no credentials.
3. PHP endpoints guarded by `requireRole()` return `401`.
4. The page appears loaded. Polling loops stop. Status endpoints stop updating. The user sees nothing — until they notice a backup that never finished or an import that hung.

The fix is structural: replace every `fetch()` call to an authenticated endpoint with a wrapper that attaches a Bearer token when one is available, and behaves identically to native `fetch()` when one is not. The wrapper is deployed now, before the JWT migration, making the two changes independent of each other.

---

## Goal

Replace every JavaScript `fetch()` call to an authenticated endpoint across all 14 caller pages with `GHAuth.authedFetch()`, and create the shared `auth/gh-auth.js` module that provides it — while Apache Basic Auth remains active and the running system is unchanged.

**Policy: no direct `fetch()` call to an authenticated endpoint may remain in any admin, DB, or view template page after Stage 1 ships.**

---

## Decision

**Option B selected — localStorage + `Authorization: Bearer`.**

Evaluated against Option A (httpOnly JWT cookie). Option B was chosen because the `Authorization: Bearer` contract is backend-agnostic: a future Java service, Go service, or any other implementation validates the JWT signature without caring whether the client is a PHP page or a native app. A cookie-based contract ties the browser to session semantics that a Java service would need to replicate. Full rationale in `feature_security_authentication_migration_jwt_implementation.md` (Phase 2 Companion decision note).

---

## Benefits / Potential Drawbacks

| Benefits | Potential Drawbacks |
|---|---|
| JWT cutover (Stage 2) becomes a config flip — no AJAX call changes needed at that point | 14 PHP files must be touched; scope is large, though changes are mechanical |
| Zero auth impact during Stage 1 — Basic Auth still active; `authedFetch()` is a no-op passthrough without a token | `gh-auth.js` is a new deployment dependency; if it goes missing, all admin AJAX breaks |
| Single canonical location for authenticated request logic — future changes (token refresh, expiry handling) are made once | localStorage token is accessible to XSS if a vulnerability exists; mitigated by the same defenses already applied to the admin pages |
| Bearer token contract is backend-agnostic — Java/Go rewrite needs no second client-side migration round | |
| Clear stage boundary — Stage 1 is verifiable in isolation with no JWT infrastructure present | |

---

## Design Principles

The following invariants must hold after Stage 1 ships:

1. `authedFetch(url, opts)` is a drop-in replacement for `fetch(url, opts)` — identical call signature, identical behavior when no token is in `localStorage`.
2. The `gighive_jwt` localStorage key is defined exactly once, as a named constant inside `auth/gh-auth.js`. No other file uses the bare string.
3. The `<script src="/auth/gh-auth.js">` tag is placed in `<head>` without `async` or `defer`. Inline `<script>` blocks in `<body>` depend on `GHAuth` being defined synchronously.
4. `GHAuth.requireAuth()` and `auth/login.php` are **not** part of Stage 1. Both redirect or depend on a JWT login flow that does not exist until Stage 2.
5. Public and QR-nonce `fetch()` calls are not changed. Only calls to authenticated endpoints are wrapped.

---

## Current State

Today, all admin and DB pages are protected by Apache Basic Auth (`AuthType Basic`, `Require valid-user`). When a browser navigates to `/admin/admin_system.php`, Apache challenges it. The browser caches the credentials and automatically re-sends them with every subsequent request from that browser session — including every `fetch()` call made by inline JavaScript on the page.

This means background AJAX calls (backup status polling, export progress, import manifest steps, AI job status) work today without any JavaScript credential handling. The browser is the credential carrier.

Phase 4 of the JWT migration removes `AuthType Basic` from the Apache config. At that point, the browser stops carrying credentials. The PHP endpoints gain `requireRole()` guards and expect `Authorization: Bearer <token>` headers. Existing `fetch()` calls send nothing — and receive `401`.

---

## Scope

### Why the checklist has more files than this refactor

`feature_security_authentication_migration_jwt_endpoint_guard_checklist.md` lists every authenticated page and endpoint. This refactor covers only a subset. The checklist rows fall into two types:

| Type | What they need | Which doc covers them |
|---|---|---|
| **Caller pages** — render HTML with `<script>` blocks that call `fetch()` on authenticated endpoints | Replace `fetch()` with `GHAuth.authedFetch()` + include `gh-auth.js` | **This refactor (Stage 1) — 14 files** |
| **Endpoint files** — pure PHP backends that receive and respond to those calls; no JS of their own | Add `requireRole()` PHP guard | **JWT Phase 2 — separate work** |

The `admin/` directory has 64 PHP files. Only 7 are caller pages with inline JS. The other 57 are endpoint files — `run_backup.php`, `restore_database.php`, `export_media_worker.php`, all status endpoints and import workers. They are the targets of `fetch()` calls inside the caller pages. They need Phase 2 guards but have zero JavaScript to change in Stage 1.

Example: `admin_system.php` makes 19 `fetch()` calls to 13 different backend files. All 13 backends are Phase 2 scope. `admin_system.php` itself is in both: `authedFetch()` in Stage 1, `requireRole('owner')` in Phase 2.

### Directories in scope

| Directory | Files touched |
|---|---|
| `admin/*.php` | 7 of 64 — the caller pages with inline JS |
| `db/*.php` | 5 — authenticated viewer / owner DB pages |
| `src/Views/media/*.php` | 2 — view templates; `fetch()` calls live here, not in the controller files that include them |

`api/*.php` confirmed clean — zero JavaScript `fetch()` calls in any API endpoint file.

---

## Stage 1 — File Inventory

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
| `admin/admin_database_load_import_media_from_iphone.php` | 6 | Verify targets during implementation (Step 9) |
| `admin/admin_database_load_import_csv.php` | 2 | Verify targets during implementation (Step 10) |
| `admin/admin_database_catalog_media_from_folder.php` | 2 | Verify targets during implementation (Step 11) |
| `db/media_tags.php` | 5 | `/api/ai_jobs.php`, `/api/taggings.php`, `/api/tags.php` |
| `db/database_catalog.php` | 5 | `/db/catalog_entry_save.php` |
| `db/upload_form_admin.php` | 2 | `/db/delete_media_files.php`, `/api/uploads/finalize` |
| `db/upload_form.php` | 2 | `/db/delete_media_files.php`, `/api/uploads/finalize` |
| `db/upload_form_single.php` | 2 | `/db/delete_media_files.php` (dual-mode: `IS_ADMIN` flag), `/api/uploads/finalize` (dual-mode: `X-Upload-Token` when QR). `authedFetch()` safe in both cases — see Stage 2 note in Progress |
| `src/Views/media/list.php` | 4 | `/api/tags.php`, `/db/database_edit_save.php`, `/db/database_edit_musicians_preview.php`, `/db/delete_media_files.php` |
| `src/Views/media/random_player.php` | 1 | `/db/singlesRandomPlayer.php?format=json` — Phase 2 adds `requireRole('viewer')`; JS callback needs `authedFetch()` once guarded |

### Resolved false positive — no changes needed

| File | Note |
|---|---|
| `db/tag_browser.php` | `$tagRow = $stmt->fetch(PDO::FETCH_ASSOC)` — PHP PDO only; zero JS AJAX calls |

---

## Proposed Implementation

**Implementation index:**

- [ ] **Step 1** — Verify Apache config: `/auth/` path is served without Basic Auth
- [ ] **Step 2** — Create `ansible/roles/docker/files/apache/webroot/auth/` directory
- [ ] **Step 3** — Write `auth/gh-auth.js` (Stage 1 module: `TOKEN_KEY`, `getToken`, `authedFetch` only)
- [ ] **Step 4** — Add T-151, T-152, T-153, T-154 to `post_build_checks/tasks/main.yml`
- [ ] **Step 5** — Deploy Phase A; verify T-151 and T-152 pass — **GATE before Steps 6–17**
- [ ] **Step 6** — `admin/admin_system.php` — script tag + 19 authedFetch replacements
- [ ] **Step 7** — `admin/admin_database_load_import_media_from_folder.php` — script tag + 8
- [ ] **Step 8** — `admin/admin_database_catalog_promote.php` — script tag + 7
- [ ] **Step 9** — `admin/ai_worker.php` — script tag + 4
- [ ] **Step 10** — `admin/admin_database_load_import_media_from_iphone.php` — verify targets, script tag + 6
- [ ] **Step 11** — `admin/admin_database_load_import_csv.php` — verify targets, script tag + 2
- [ ] **Step 12** — `admin/admin_database_catalog_media_from_folder.php` — verify targets, script tag + 2
- [ ] **Step 13** — `db/media_tags.php` — script tag + 5
- [ ] **Step 14** — `db/database_catalog.php` — script tag + 5
- [ ] **Step 15** — `db/upload_form_admin.php` — script tag + 2
- [ ] **Step 16** — `db/upload_form.php` — script tag + 2
- [ ] **Step 17** — `db/upload_form_single.php` — script tag + 2 (dual-mode; note Stage 2 concern)
- [ ] **Step 18** — `src/Views/media/list.php` — script tag + 4
- [ ] **Step 19** — `src/Views/media/random_player.php` — script tag + 1
- [ ] **Step 20** — Add Playwright tests T-155, T-156 to `playwright_admin_tests` role

---

### Phase A — Create shared module (Steps 1–5)

**Goal:** Deploy `auth/gh-auth.js` and verify it is served before any PHP file is modified. Steps 6–19 must not begin until Step 5 (the gate) is verified.

> **Critical deployment order:** If files modified in Steps 6–19 are deployed before `auth/gh-auth.js` exists, every admin page throws `TypeError: Cannot read properties of undefined (reading 'authedFetch')` and all AJAX functionality breaks. `auth/gh-auth.js` is a hard prerequisite.

- [ ] **Step 1** — Verify Apache config (`default-ssl.conf.j2`): confirm `/auth/` does not fall inside a `Require valid-user` `<Directory>` block. `/auth/gh-auth.js` must be publicly served — the Stage 2 login page will load it before a JWT exists. If a Basic Auth block covers it, add a `<Location "/auth/gh-auth.js">` exemption now.

- [ ] **Step 2** — Create directory `ansible/roles/docker/files/apache/webroot/auth/`. The `auth/` directory does not yet exist in the webroot. The docker Ansible role will deploy it alongside the webroot on the next playbook run.

- [ ] **Step 3** — Write `auth/gh-auth.js` — Stage 1 module only. `login()`, `logout()`, and `requireAuth()` are Stage 2 additions; do not include them now. See module skeleton below.

- [ ] **Step 4** — Add T-151, T-152, T-153, T-154 to `post_build_checks/tasks/main.yml` using the `uri` module. Credentials for T-153/T-154 come from `{{ admin_htpasswd_user }}` and `{{ admin_htpasswd_password }}` group_vars.

- [ ] **Step 5 (GATE)** — Deploy and verify T-151 and T-152 pass in the target environment before any PHP page is modified. Do not proceed to Phase B until this gate clears.

#### `auth/gh-auth.js` — Stage 1 module skeleton

```javascript
(function (window) {
    'use strict';

    var TOKEN_KEY = 'gighive_jwt';   // single authoritative definition of the key name

    function getToken() {
        try { return localStorage.getItem(TOKEN_KEY); } catch (e) { return null; }
    }

    function authedFetch(url, opts) {
        opts = opts || {};
        opts.headers = Object.assign({}, opts.headers);
        var token = getToken();
        if (token) {
            opts.headers['Authorization'] = 'Bearer ' + token;
        }
        return fetch(url, opts);
    }

    // login(), logout(), requireAuth() are Stage 2 additions — not present here.

    window.GHAuth = {
        getToken:     getToken,
        authedFetch:  authedFetch
    };

}(window));
```

Key points:
- `TOKEN_KEY` is a named constant — the string `'gighive_jwt'` appears exactly once in the entire codebase.
- `getToken()` wraps `localStorage` access in a try/catch to guard against private-browsing restrictions.
- `authedFetch()` is a drop-in for native `fetch()` — identical signature and return value.
- The IIFE prevents global variable pollution except for the intentional `window.GHAuth` export.

#### Failure mode: `auth/gh-auth.js` fails to load

If `gh-auth.js` returns a non-200 (e.g., the file was deleted, or the Ansible deploy failed), the `GHAuth` global is never defined. Every subsequent `GHAuth.authedFetch()` call throws `TypeError: Cannot read properties of undefined`. Admin pages render but all AJAX functionality breaks silently — no `fetch()` error is visible to the user.

Detection: T-151 catches this in the next post-build check run. Rollback: see Rollback Procedure below.

---

### Phase B — Update `admin/` pages (Steps 6–12)

**Goal:** Apply the Change Pattern to all 7 admin caller pages. Verify each page in a browser before marking the step complete.

Per-step cycle (SKILL.md): request approval → implement → browser verify → mark step complete.

- [ ] **Step 6** — `admin/admin_system.php`
- [ ] **Step 7** — `admin/admin_database_load_import_media_from_folder.php`
- [ ] **Step 8** — `admin/admin_database_catalog_promote.php`
- [ ] **Step 9** — `admin/ai_worker.php`
- [ ] **Step 10** — `admin/admin_database_load_import_media_from_iphone.php` *(confirm all 6 targets are authenticated endpoints before replacing)*
- [ ] **Step 11** — `admin/admin_database_load_import_csv.php` *(confirm 2 targets)*
- [ ] **Step 12** — `admin/admin_database_catalog_media_from_folder.php` *(confirm 2 targets)*

---

### Phase C — Update `db/` pages (Steps 13–17)

**Goal:** Apply the Change Pattern to all 5 DB caller pages.

- [ ] **Step 13** — `db/media_tags.php`
- [ ] **Step 14** — `db/database_catalog.php`
- [ ] **Step 15** — `db/upload_form_admin.php`
- [ ] **Step 16** — `db/upload_form.php`
- [ ] **Step 17** — `db/upload_form_single.php` *(dual-mode page — authedFetch() safe for both IS_ADMIN paths in Stage 1; Stage 2 concern logged in Progress)*

---

### Phase D — Update `src/Views/` templates (Steps 18–19)

**Goal:** Apply the Change Pattern to the 2 view templates.

- [ ] **Step 18** — `src/Views/media/list.php`
- [ ] **Step 19** — `src/Views/media/random_player.php`

---

### Phase E — Playwright tests (Step 20)

**Goal:** Add functional regression tests proving AJAX calls still succeed after the refactor.

- [ ] **Step 20** — Add T-155 and T-156 to `playwright_admin_tests` role (see Tests section)

---

### Change Pattern (applied per file in Steps 6–19)

**1. Add script tag to `<head>` — no `async` or `defer`**

Every page owns its own `<head>` block (no shared template). Add exactly this line:

```html
<script src="/auth/gh-auth.js"></script>
```

> The tag must not carry `async` or `defer`. Inline `<script>` blocks in `<body>` execute immediately after parse and depend on `GHAuth` already being defined. An `async` or `defer` tag would allow body scripts to run before `gh-auth.js` loads, causing the same `TypeError` as a missing file.

**2. Replace `fetch()` with `GHAuth.authedFetch()`**

```javascript
// Before
const r = await fetch('/admin/export_media_status.php?job_id=' + id);
const r = await fetch('/admin/run_backup.php', { method: 'POST', body: JSON.stringify(payload) });

// After
const r = await GHAuth.authedFetch('/admin/export_media_status.php?job_id=' + id);
const r = await GHAuth.authedFetch('/admin/run_backup.php', { method: 'POST', body: JSON.stringify(payload) });
```

The function signature is identical to native `fetch()`. Replacement is mechanical. Do NOT add `GHAuth.requireAuth()` calls during Stage 1.

**3. Browser verify**

- Load the page with Basic Auth active.
- Exercise the AJAX functionality (trigger an export, run an import, open the AI worker page, etc.).
- Confirm behaviour is identical to before.
- Mark the step complete in this doc.

---

### SonarQube / Best-Practice Notes

| Rule | Finding |
|---|---|
| RSPEC-3776 (cognitive complexity) | `authedFetch()` is a trivial wrapper; complexity = 1. No concern. |
| RSPEC-6426 (null dereference) | `getToken()` wraps `localStorage` in try/catch; `null` return is guarded by `if (token)`. No concern. |
| RSPEC-2635 (sensitive data in SQL) | No SQL. N/A. |
| Magic strings | `'gighive_jwt'` is defined once as `TOKEN_KEY` inside the IIFE. No other file references the bare string. |
| Repeated global name | `GHAuth` is the canonical global name. It appears 14+ times across PHP files. If the name changes, all 14 files must change — document this as a stable, intentional contract. |
| PHP files | Changes are additive only: one script tag + mechanical call-site substitutions. No new PHP logic, no SQL, no new endpoints. No PHP SonarQube concerns. |

---

## Files Under Change

All files are in the `gighiveinfra` repo under `ansible/roles/docker/files/apache/webroot/`.

### New (1 file)

1. `auth/gh-auth.js` — New JavaScript IIFE module; exports `window.GHAuth = { getToken, authedFetch }`; Stage 1 only; `login`, `logout`, `requireAuth` deferred to Stage 2

### Modified (14 files)

2. `admin/admin_system.php` — Add `<script src="/auth/gh-auth.js">` to `<head>`; replace 19 `fetch()` calls with `GHAuth.authedFetch()`
3. `admin/admin_database_load_import_media_from_folder.php` — Add script tag; replace 8 `fetch()` calls
4. `admin/admin_database_catalog_promote.php` — Add script tag; replace 7 `fetch()` calls
5. `admin/ai_worker.php` — Add script tag; replace 4 `fetch()` calls
6. `admin/admin_database_load_import_media_from_iphone.php` — Add script tag; replace 6 `fetch()` calls (targets verified at Step 10)
7. `admin/admin_database_load_import_csv.php` — Add script tag; replace 2 `fetch()` calls (targets verified at Step 11)
8. `admin/admin_database_catalog_media_from_folder.php` — Add script tag; replace 2 `fetch()` calls (targets verified at Step 12)
9. `db/media_tags.php` — Add script tag; replace 5 `fetch()` calls
10. `db/database_catalog.php` — Add script tag; replace 5 `fetch()` calls
11. `db/upload_form_admin.php` — Add script tag; replace 2 `fetch()` calls
12. `db/upload_form.php` — Add script tag; replace 2 `fetch()` calls
13. `db/upload_form_single.php` — Add script tag; replace 2 `fetch()` calls (dual-mode; authedFetch safe for both paths in Stage 1)
14. `src/Views/media/list.php` — Add script tag; replace 4 `fetch()` calls
15. `src/Views/media/random_player.php` — Add script tag; replace 1 `fetch()` call

**Total: 15 files — 1 new, 14 modified**

### Unchanged (key files explicitly unaffected)

- `ansible/roles/docker/templates/default-ssl.conf.j2` — Apache config unchanged in Stage 1 (unless Step 1 reveals `/auth/` needs an exemption block)
- All `api/*.php` endpoint files — zero JS AJAX calls confirmed
- All `admin/*.php` endpoint files (the 57 non-caller pages) — Phase 2 work only

---

## Rollback Procedure

If Stage 1 introduces a regression:

1. Revert files #2–#15 to their pre-refactor state (git checkout or re-deploy from previous Ansible artifact).
2. Remove `auth/gh-auth.js` from the webroot (`auth/` directory can remain).
3. T-151 will fail on next post-build check run, confirming the rollback is in effect.

Rollback is clean — no schema changes, no config changes, no data changes.

---

## Stage 2 — JWT Cutover Additions (not this refactor)

Stage 2 is part of JWT Phase 4, documented in `feature_security_authentication_migration_jwt_implementation.md`. For reference, Stage 2 adds the following on top of Stage 1:

- Expand `auth/gh-auth.js` with `login()`, `logout()`, `requireAuth()`
- Deploy `auth/login.php` — web login form; calls `GHAuth.login()`; stores JWT in `localStorage`
- Add `GHAuth.requireAuth()` to each admin page's `DOMContentLoaded` handler
- Set `GIGHIVE_AUTH_MODE=local` in each environment's Ansible vars
- Remove Apache `AuthType Basic` blocks from `default-ssl.conf.j2`

Because Stage 1 is complete by this point, Stage 2 has no AJAX call changes to make — only the login flow and the auth mode flip.

---

## Relationship to Future Java Rewrite

The `Authorization: Bearer <token>` contract is backend-agnostic. When the PHP backend is replaced by a Java service, the web client already uses the correct protocol — the Java service validates the JWT signature with the same secret and claims. No second round of client-side changes is required. An httpOnly cookie alternative was evaluated and rejected for this reason.

---

## Tests

T-numbers verified against all `docs/*.md` — highest existing is T-150. New assignments: T-151 through T-156.

### Smoke tests — `post_build_checks/tasks/main.yml`

| Test | Tag | What it validates |
|---|---|---|
| T-151 | `[smoke]` | `GET /auth/gh-auth.js` → HTTP 200; module is deployed and served |
| T-152 | `[smoke]` | Response body of `/auth/gh-auth.js` contains `authedFetch`; module content is present, not empty or misrouted |
| T-153 | `[smoke]` | `GET /admin/admin_system.php` with `{{ admin_htpasswd_user }}` / `{{ admin_htpasswd_password }}` → HTTP 200; representative admin page loads without regression |
| T-154 | `[smoke]` | `GET /db/media_tags.php` with valid Basic Auth → HTTP 200; representative DB page loads without regression |

> **must never:** An admin or DB page that previously returned HTTP 200 with valid credentials must never return a non-200 after Stage 1 is deployed. T-153 and T-154 enforce this for representative pages; expand coverage during implementation if regressions are suspected on other pages.

### Playwright tests — `playwright_admin_tests` role

| Test | Tag | What it validates |
|---|---|---|
| T-155 | `[smoke]` | Log in with Basic Auth, navigate to `admin/admin_system.php`, trigger the System Stats AJAX call (`/admin/admin_system_stats.php`), confirm JSON response appears in the UI — proves `authedFetch()` correctly calls an authenticated endpoint |
| T-156 | `[smoke]` | Log in with Basic Auth, navigate to `db/media_tags.php`, verify the AI jobs status fetch to `/api/ai_jobs.php` returns without error — proves a DB page AJAX call works end-to-end |

---

## Progress

### Completed
- [x] Full audit of `fetch()` call sites across `admin/`, `db/`, `src/Views/`
- [x] Resolved false positives (PHP PDO `->fetch()` calls excluded)
- [x] Identified `src/Views/media/list.php` as a hidden call-site inside a view template
- [x] Confirmed `api/` directory is clean — zero JS fetch() calls
- [x] Discovered and documented missing pages (`timeline-api.php`, `src/index.php`) and added to matrix
- [x] PPRR complete

### Remaining — This Feature
- [ ] **Phase A, Step 1** — Verify Apache config for `/auth/` directory access
- [ ] **Phase A, Step 2** — Create `auth/` directory in webroot
- [ ] **Phase A, Step 3** — Write `auth/gh-auth.js` (Stage 1 module)
- [ ] **Phase A, Step 4** — Add T-151 through T-154 to `post_build_checks/tasks/main.yml`
- [ ] **Phase A, Step 5 (GATE)** — Deploy and verify T-151, T-152 pass
- [ ] **Phase B, Step 6** — `admin/admin_system.php`
- [ ] **Phase B, Step 7** — `admin/admin_database_load_import_media_from_folder.php`
- [ ] **Phase B, Step 8** — `admin/admin_database_catalog_promote.php`
- [ ] **Phase B, Step 9** — `admin/ai_worker.php`
- [ ] **Phase B, Step 10** — `admin/admin_database_load_import_media_from_iphone.php` (verify targets)
- [ ] **Phase B, Step 11** — `admin/admin_database_load_import_csv.php` (verify targets)
- [ ] **Phase B, Step 12** — `admin/admin_database_catalog_media_from_folder.php` (verify targets)
- [ ] **Phase C, Step 13** — `db/media_tags.php`
- [ ] **Phase C, Step 14** — `db/database_catalog.php`
- [ ] **Phase C, Step 15** — `db/upload_form_admin.php`
- [ ] **Phase C, Step 16** — `db/upload_form.php`
- [ ] **Phase C, Step 17** — `db/upload_form_single.php`
- [ ] **Phase D, Step 18** — `src/Views/media/list.php`
- [ ] **Phase D, Step 19** — `src/Views/media/random_player.php`
- [ ] **Phase E, Step 20** — Add T-155, T-156 to `playwright_admin_tests` role

### Remaining — Follow-on Tasks
- [ ] **Stage 2:** Reroute the `IS_ADMIN=false` delete path in `upload_form_single.php` from `delete_media_files.php` to `/api/guest-delete.php` before Phase 4 ships — without this, QR guest delete returns `401` after Basic Auth is removed
- [ ] **Stage 2:** Expand `auth/gh-auth.js` with `login()`, `logout()`, `requireAuth()` when `auth/login.php` is ready
