# QR Code Guest Upload — Phase 1a Walkthrough Notes
**Date:** 2026-06-27  
**Scope:** Step-by-step walkthrough of the Phase 1a implementation plan (Step 5 in `feature_saas_model_changes.md`, full detail in `feature_iphone_qr_code_support.md`), covering server/PHP steps 1–10. Captures rationale, what each step does and does not do, gates, UI diagrams, and key notes per step.

---

## End-to-End Guest Upload Flow

This section describes the full feature from the organizer's perspective through to the guest's experience and the post-upload admin view. It is the authoritative narrative summary; implementation details are in the numbered steps below.

### The event organizer (admin)
1. Navigates to `admin/event_qr.php`, selects their event from the dropdown
2. Chooses an expiry window (4h / 24h / 7d; default from `QR_TOKEN_DEFAULT_TTL_HOURS`)
3. Clicks **Generate QR Code** — the server creates a CSPRNG base64url raw token (~43 chars), stores its SHA-256 hash in `event_upload_tokens` with an expiry, and returns the raw token to the browser once only (never stored in DB; not re-retrievable after page refresh)
4. A `<canvas>` QR code renders in the browser encoding `https://gighive.app/upload/<raw_token>`
5. Admin downloads as PNG and displays it at the venue — printed, projected, or shown on a device
6. Admin can revoke any token at any time from the Active Tokens table; revoked tokens are flagged in the Guest Uploads list for audit

### The guest at the event
1. Scans the QR code with their iPhone camera
2. **iOS app installed:** Universal Links (Associated Domains `applinks:gighive.app`) intercepts the URL; the app opens directly to `GuestUploadView` — no login, no account required
3. **No app / Android / web:** Safari or browser opens `https://gighive.app/upload/<token>` → Apache rewrites to `db/upload_form_single.php?token=<token>` → web upload form renders
4. In either path the guest sees:
   - Read-only event info (name, date, type) pre-populated from token validation — no event fields to fill in
   - Required ToS checkbox
   - Optional display name field (max 100 chars; HTML stripped server-side)
   - File picker (video only)
5. Guest taps Upload — the file is sent via TUS with `X-Upload-Token: <raw_token>` in headers instead of any Basic Auth credentials
6. On finalize, the server validates the token via `UploadTokenValidator`, creates an `anon_upload_attributions` row atomically (token ID + upload job ID + display name + ToS timestamp)
7. Guest sees a "Upload received — thank you!" confirmation; session clears; no re-navigation to the upload form

### After upload
- The upload appears immediately in the **Guest Uploads** section of `admin/event_qr.php` for the organizer
- Rows from revoked tokens are flagged with a warning indicator
- Data persists in `anon_upload_attributions` indefinitely (scoped by `event_id` via the token join)

### What this is NOT
- Not authenticated — no account, no password, no session cookie
- Not a general-purpose upload — video only, scoped to one event per token
- Not permanent access — tokens expire; guests cannot re-use credentials across events
- Not rate-limited per token in Phase 1a — any number of guests can use the same QR code (multi-use by design)

---

## Step 1 — DB Tables Existence Check

### What it does
- The `qr_code` Ansible smoke test role verifies that both `event_upload_tokens` and `anon_upload_attributions` exist in the MySQL container before any other smoke test runs
- Uses `community.docker.docker_container_exec` to run a `SHOW TABLES LIKE` query inside the MySQL container with `$MYSQL_ROOT_PASSWORD` from the container environment (same pattern as the `db_migrations` role)
- Fails fast with a clear human-readable message identifying which table is missing

### What it does NOT do
- Does not attempt to create the missing tables
- Does not suggest running `db_migrations` — operator must resolve the schema issue manually
- Does not continue with remaining smoke tests if either table is absent

### Gate
Runs as part of the `qr_code` Ansible role, which is tagged `qr_code` in `site.yml` and runs after `post_build_checks`, before `validate_app`.

### Key notes
- Both tables are already defined in `create_media_db.sql` — a fresh Ansible build from scratch will have them; this check primarily catches environments that were built before Phase 1 migration was applied
- The original plan said to suggest running `db_migrations` if tables are missing. This was changed during the walkthrough — a clean exit with an explanation is preferable to an automated remediation that could mask a wider schema problem

### Plan changes
Step 1 wording in Implementation Subtasks updated; `qr_code` role `fail_msg` strings updated to remove `db_migrations` reference.

---

## Step 2 — Apache Vhost Config (`default-ssl.conf.j2`)

### What it does
This step is a **prerequisite** — no guest-facing URL is testable until it is deployed.

Six changes to `default-ssl.conf.j2`, organized into four categories:

**1. Guest upload routing**
```apache
RewriteRule ^/upload/([A-Za-z0-9_-]+)$ /db/upload_form_single.php?token=$1 [L,QSA]
```
Must be placed *before* the existing MVC rewrite rules or it is swallowed.

**2. Auth exemptions (six changes)**

| Change | Directive | Reason |
|---|---|---|
| Mark token header | `SetEnvIf X-Upload-Token .+ upload_token_auth` | Apache sets env var when header is non-empty; PHP is the actual validator |
| Guest landing page | `<Location "/db/upload_form_single.php"> AuthMerging Off; Require all granted </Location>` | Overrides catch-all `/db/` Basic Auth; PHP gates admin sections separately |
| TUS chunks | Replace `Require user admin uploader` in `/files/` with `<RequireAny>` block | Allows guest TUS chunks through when token header is present |
| Finalize API | Same `<RequireAny>` on `/api/uploads/` | Allows token-auth finalize call to reach PHP |
| Token API | `<Location "/api/upload-token.php"> AuthMerging Off; Require all granted </Location>` | Fully public by design; PHP validates token |
| AASA Content-Type | `<Location "/.well-known/apple-app-site-association"> Header set Content-Type "application/json" </Location>` | Apple requires this exact header; no auth directive needed |

**3. Token API routing**
No rewrite needed — `api/upload-token.php` is accessed directly with `.php` extension, consistent with `api/tags.php` and other standalone API files.

**4. AASA file**
Jinja2 template at `ansible/roles/docker/templates/apple-app-site-association.j2`; rendered by a task in the docker role (`tasks/main.yml`) before `docker compose build`, using `{{ qr_aasa_app_id }}` and `{{ qr_guest_upload_prefix }}`; output written to `{{ docker_dir }}/apache/webroot/.well-known/apple-app-site-association` and baked into the Docker image. The former static file has been removed — the template is the single source of truth.

### What it does NOT do
- Does not validate the token value — Apache only checks for a non-empty header
- Does not protect admin PHP sections — that remains PHP's responsibility via `$_SERVER['PHP_AUTH_USER']`

### Gate
Deployed as part of the Docker role on every Ansible run. Verified by the `qr_code` smoke test role.

### Security controls (raised as a question during walkthrough)

| Layer | Control |
|---|---|
| Token entropy | 32 CSPRNG bytes base64url-encoded = 256 bits; brute force infeasible |
| Token storage | Raw token never stored; only SHA-256 hash in DB — a DB breach yields no usable tokens |
| PHP gate | `UploadTokenValidator` checks hash, `is_active = 1`, and `expires_at > NOW()` on every request |
| Admin sections | PHP checks `$_SERVER['PHP_AUTH_USER'] === 'admin'`; Apache removing auth does not expose admin PHP functions |
| Token expiry/revocation | Enforced server-side on every validation call; expired and revoked both return `404` with no distinguishing message |

### Residual risks
- **TUS storage spam** — any non-empty `X-Upload-Token` value gets through Apache to tusd. Orphaned files can accumulate without a valid finalize call. Mitigated by `upload_max_bytes`; low-severity DoS vector acceptable for Phase 1a.
- **`upload_form_single.php` direct access without `?token=`** — PHP must handle the no-token case gracefully (error state, no information disclosure). Implementation correctness requirement.
- **Deferred:** The existing htpasswd `guest` user should be renamed (e.g., to `readonly`) to avoid conceptual confusion with the QR guest persona. No functional impact in Phase 1a.

### Plan changes
Security Controls section added to `feature_iphone_qr_code_support.md`; htpasswd deferred rename note added.

---

## Step 3 — `src/Services/UploadTokenValidator.php`

### What it does
- New shared service class at `src/Services/UploadTokenValidator.php`
- Namespace: `Production\Api\Services` — matches all existing services; autoloaded via `vendor/autoload.php`
- One public method: `validate(string $rawToken): TokenValidationResult|null`
  1. Computes `hash('sha256', $rawToken)`
  2. Queries `event_upload_tokens JOIN events WHERE token_hash = ? AND is_active = 1 AND expires_at > NOW()`
  3. Returns a typed `TokenValidationResult` value object (event_id, event_date, org_name, event_type) on success
  4. Returns `null` on any miss — expired, revoked, and not-found all look identical to the caller

### What it does NOT do
- Does not use `hash_equals()` — token validation is a DB lookup via prepared statement, not a PHP string comparison
- Does not distinguish between expired, revoked, and not-found — intentional; no oracle for attackers
- Does not set `is_active = 0` on use — tokens are multi-use (many guests per event)

### Gate
Must be built **before** steps 4, 5, and 6. All three token-consuming endpoints depend on it.

### Key notes
- Typed return (`TokenValidationResult` value object) instead of `?object` — callers need specific properties; untyped return has no static analysis and breaks silently on shape changes
- **Why `::` notation in the plan:** PHP scope resolution operator (`ClassName::method()`) used in documentation to reference an instance method on a class. Equivalent to Swift's dot notation (`ClassName.method()`). All files in `src/` are PHP, not Swift.

### Plan changes
None — this step was already correctly specified.

---

## Step 4 — `GET /api/upload-token` (`api/upload-token.php`)

### What it does
- New standalone PHP file following the existing direct-API pattern (`api/tags.php`, `api/uploads.php`)
- Unauthenticated — Apache exempts it with `AuthMerging Off; Require all granted`
- Reads `?token=` from query string
- Calls `UploadTokenValidator::validate($rawToken)`
- On success: returns JSON `{ event_id, event_date, org_name, event_type }`
- On null: returns `404` with no distinguishing error detail

### What it does NOT do
- Does not require any credentials
- Does not modify any data
- Does not reveal whether a token is expired vs. revoked vs. nonexistent

### Who calls it
`QRTokenAPIClient.swift` (iOS step 14) — called after parsing the raw token from the Universal Link, before `GuestUploadView` renders. Provides event details (name, date) to pre-populate the upload screen without embedding them in the QR code URL.

### Key notes
- No rewrite rule needed — accessed as `/api/upload-token.php` directly with `.php` extension
- iOS `QRTokenAPIClient` must use `.appendingPathComponent("upload-token.php")` — not `"upload-token"` — to match this path

### Plan changes
None — already correctly specified.

---

## Step 5 — `POST /api/uploads/finalize` (Token-Auth Path)

### Critical discovery
`api/uploads/finalize.php` does **not exist** as a standalone file. The plan had been referencing a non-existent file. Finalize is handled through the MVC layer:

```
POST /api/uploads/finalize
  → api/uploads.php          (thin router — includes src/index.php)
  → src/index.php            (route dispatch — matches POST /uploads/finalize)
  → src/Controllers/UploadController.php::finalize()
  → src/Services/UploadService.php::finalizeTusUpload()
```

Changes land in `UploadController.php` (auth mode detection) and `UploadService.php` (token-mode execution path and `anon_upload_attributions` INSERT).

### What it does
**`UploadController::finalize()`:**
- Reads `$_SERVER['HTTP_X_UPLOAD_TOKEN']`
- If present: calls `UploadTokenValidator::validate()`; passes `TokenValidationResult` into service
- If absent: falls through to existing Basic Auth behavior
- If neither: returns `401`

**`UploadService::finalizeTusUpload()` — token mode:**
- Decodes and validates JSON body before any key access (`json_last_error()` guard → `400`)
- Requires `label` (required, max 255 chars)
- Requires `tos_accepted === true` (strict JSON boolean — not `"true"`, not `1`)
- Accepts `display_name` (optional; `strip_tags(trim())` before storage; max 100 chars)
- Derives event context (date, org_name, event_type) from token result — never from client input
- Atomically INSERTs `anon_upload_attributions` in the same transaction as `upload_jobs`

### What it does NOT do
- Does not modify the existing Basic Auth path — the token path is purely additive
- Does not trust client-supplied event metadata — always derives from DB token result
- Does not accept `"true"` string or integer `1` for `tos_accepted` — strict JSON `true` only

### Gate
Apache `<RequireAny>` on `/api/uploads/` allows requests through when `upload_token_auth` env var is set. PHP then validates the actual token value.

### Key notes
- The `::` method notation in the plan is PHP, not Swift — scope resolution operator for referencing instance methods in documentation
- **TODO:** After implementing this step, update `docs/database_schema.mermaidchart` to add `event_upload_tokens` and `anon_upload_attributions` with their FK relationships

### Plan changes
Step 5 subtask rewritten to reference correct MVC files; Files Changed table split into `UploadController.php` and `UploadService.php` rows; implementation notes heading updated; `UploadTokenValidator` preamble updated; MVC flow diagram embedded in the plan.

---

## Step 6 — `db/upload_form_single.php` — Guest Upload Form

### Discovery
Viewing the actual running page revealed it is already a complete admin upload form with editable metadata fields (event date, org name, event type, label, participants, keywords, etc.). The plan had been adding admin QR management sections (generator, token table, guest upload list) onto this page — which would make it confusing and overloaded.

**Decision:** Step 6 stays on `upload_form_single.php` but is scoped to the guest token mode only. Admin QR management moves to a new dedicated page (`admin/event_qr.php`, step 7).

### What it does
- Adds `require_once __DIR__ . '/../vendor/autoload.php'` and `use Production\Api\Services\UploadTokenValidator` at the top (currently absent from this file, unlike other `db/` files)
- Detects `?token=` in the query string (set by Apache rewrite from step 2)
- Calls `UploadTokenValidator::validate()` — returns error page on null (no upload form shown)
- **Replaces** the editable event metadata fields with read-only pre-populated values from the token result (event name, date, type — no user input accepted)
- Adds ToS checkbox (required — upload blocked until checked)
- Adds display name field (optional; `strip_tags(trim())` before storage; `htmlspecialchars()` at every render point; max 100 chars)
- Keeps existing file picker and Upload button
- Uses existing TUS JS infrastructure with `X-Upload-Token: <raw_token>` in TUS `headers` option and finalize `fetch` headers
- Omits `withCredentials: true` and Basic Auth header in token mode
- Passes `display_name` and `tos_accepted: true` in finalize body

### What it does NOT do
- No admin sections on this page — no QR generator, no token table, no guest upload list
- Does not accept editable event metadata from the guest — all event context is read-only from the token
- Does not show the "Advanced (Admin)" section visible to logged-in admin users

### Gate
No authentication required at the Apache layer (`AuthMerging Off`). PHP gates the token-mode path on `?token=` presence and `UploadTokenValidator` result. If no token and no htpasswd credentials: PHP shows a clean error state (no form, no PHP warnings, no information disclosure).

### Key notes
- Existing admin and authenticated-user modes on the page are completely unaffected — token mode is a third branch
- The existing "My uploads from this device" section may remain or be hidden in token mode — implementation detail

### Plan changes
Step 6 description rewritten; "No admin QR management on this page" note added explicitly.

---

## Step 7 — `admin/event_qr.php` (New Dedicated Page)

### Discovery
Steps 7, 8, and 9 were originally described as admin sections added to `upload_form_single.php`. After viewing the existing page, it was clear these belong on a separate dedicated admin page, consistent with the existing `admin/` convention (`admin/ai_worker.php`, `admin/admin_system.php`, etc.).

Steps 8 and 9 are absorbed into this step — all three admin functions are sections of the same page.

### What it does

**Page layout:**
```
admin/event_qr.php?event_id=X

Event: [ Soundwave Festival ▼ ]   ← event selector dropdown

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
QR Code Generator

  Expires in: [ 4 hours | ●24 hours | 7 days ]

  [ Generate QR Code ]

  ┌─────────────────┐
  │  ▓▓▓ ▓ ▓▓▓▓▓▓  │
  │  ▓   ▓   ▓  ▓  │   ← <canvas> rendered by qrcode@1.5.4 (jsDelivr CDN)
  │  ▓▓▓ ▓ ▓ ▓  ▓  │
  └─────────────────┘
  Encodes: https://gighive.app/upload/xK3mN9...Rp2

  [ Download PNG ]

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
Active Tokens for This Event

  Token          │ Expires          │ Status   │ Action
  ───────────────┼──────────────────┼──────────┼────────
  xK3mN9...Rp2  │ 2026-07-05 14:00 │ Active   │ [Revoke]
  aB7cD2...Wq8  │ 2026-07-02 09:00 │ Expired  │ —
  fG9hJ3...Ks5  │ 2026-07-01 10:00 │ Revoked  │ —

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
Guest Uploads for This Event

  Name           │ Uploaded         │ File            │ Token Status
  ───────────────┼──────────────────┼─────────────────┼──────────────
  Jake M.        │ 2026-07-04 22:15 │ clip_001.mp4    │ Active
  (anonymous)    │ 2026-07-04 22:43 │ IMG_4892.mov    │ Active
  Sarah T.       │ 2026-07-01 18:30 │ video_0034.mov  │ Revoked ⚠
```

**QR Code Generator section:**
- Event selector (`?event_id=X`) — dropdown of all events
- Expiry options: **4h / 24h / 7d / 14d**; pre-selected from `QR_TOKEN_DEFAULT_TTL_HOURS` env var (default: 168 = 7 days; changed from 24h — guests at events need more time)
- Token generation: `rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=')` → ~43-char base64url raw token
- SHA-256 hash stored in `event_upload_tokens`; raw token never stored in DB
- `expires_at = NOW() + INTERVAL <hours> HOUR` computed server-side; validated on INSERT
- QR rendered to `<canvas>` via `qrcode@1.5.4` (jsDelivr CDN — no Composer dependency, no build step)
- QR encodes `https://gighive.app/upload/<raw_token>`
- PNG download button exports canvas as data URL
- Raw token displayed once immediately after generation — not re-retrievable after page refresh

**Active Tokens section (absorbed from step 8):**
- Table of all tokens for the event: truncated hash, expiry datetime, status (Active / Expired / Revoked)
- [Revoke] button on active rows POSTs to same page; sets `is_active = 0`
- POST handler re-verifies `token_id` belongs to same `event_id` — prevents cross-event tampering via manipulated POST body
- Revoked tokens remain in the table for audit visibility

**Guest Uploads section (absorbed from step 9):**
- Query: `anon_upload_attributions JOIN event_upload_tokens ON token_id WHERE event_upload_tokens.event_id = ? JOIN upload_jobs ON upload_job_id`
- Columns: display name (or "(anonymous)"), uploaded timestamp, filename (`upload_jobs` label), token status
- Ordered by `anon_upload_attributions.created_at DESC`
- Rows from revoked tokens flagged with warning indicator

### What it does NOT do
- Does not re-display the raw token after the generation POST — it is shown once inline and not stored
- Does not delete token rows — revocation sets `is_active = 0` only; audit trail preserved
- Does not interrupt in-flight uploads on revocation — any TUS chunk already in progress at revocation time will complete; revocation takes effect on the *next* `UploadTokenValidator::validate()` call
- Does not limit the number of guests who can use a token — QR tokens are multi-use

### Gate
`$user === 'admin'` — Basic Auth username check via `$_SERVER['PHP_AUTH_USER']`. Acceptable for Phase 1a; revisited when OIDC/RBAC ships in step 7 of the SaaS model.

### Key notes
- Follows `admin/` page convention — no rewrite rules needed; accessed as `/admin/event_qr.php`
- Token expiry default (`QR_TOKEN_DEFAULT_TTL_HOURS=24`) already flows through group_vars → `.env.j2` → container env — no additional Ansible wiring needed for this page
- Steps 8 and 9 are fully absorbed — the plan marks them as `*(absorbed into item 7)*`
- **Nav link:** `event_qr.php` must be added as the **last/bottom link** on every `admin_*.php` page that already has a navigation block. Two patterns in the codebase:
  - `admin_system.php` pattern — upper-right `<div>` with stacked `<button>` links; append: `<a href="/admin/event_qr.php"><button type="button" style="border-color:#22c55e;font-size:.8rem;padding:.4rem .8rem">Guest QR Upload</button></a>`
  - `ai_worker.php` pattern — `<p>` inline link row; append: `&nbsp;|&nbsp; <a href="/admin/event_qr.php">Guest QR Upload</a>`
- `event_qr.php` itself includes a `← System` back-link to `/admin/admin_system.php` (same convention as `ai_worker.php`)

### Design decisions (confirmed Jun 28 2026)

**Event selector — `event_date` input + `org_name` text input with datalist:**
- `event_qr.php` is standalone with no upstream context; the event card lives on the page itself
- `event_date`: `<input type="date">` — admin picks the event date directly
- `org_name`: `<input type="text">` with a native HTML5 `<datalist>` populated from `SELECT DISTINCT org_name FROM events ORDER BY org_name ASC` — shows all known orgs as autocomplete suggestions; free-form entry allowed for new orgs. Helper text: *"Choose an existing organization or add new"*
- Single dropdown of all events rejected — an org_name + date pair is the natural human identifier (and matches the `events` unique key); a dropdown of pre-existing event rows would break for new customers with no media yet
- Shows **all events** with no date filter — admin may need to manage tokens for past events (late uploads); filter can be added later if needed
- Sections only render once both fields are submitted; section headers show `(org_name — event_date)` for context
- **No schema change** — application logic resolves org+date to `event_id` via look-up-or-create: query `events WHERE tenant_id=1 AND event_date=? AND org_name=?`; if not found, INSERT minimal event row (`tenant_id`, `UUID()` event_key, `event_date`, `org_name` — all other columns nullable). The `events` unique key `(tenant_id, event_date, org_name)` prevents duplicates. `event_id` is never exposed to the admin.

**Initial load — blank state, no default event:**
- On first load with no params, only the Event card renders; the three sections (QR Generator, Active Tokens, Guest Uploads) are hidden until a valid event is loaded
- No defaulting to most recent event — a new customer with a blank database has no events yet; `event_qr.php` may be their very first interaction with the system (generate a QR before any media is uploaded)
- New customer path: blank form → type org name + date → Load Event → event row created on-demand → QR generated → guest uploads arrive → media populates the system
- Existing customer path: blank form → datalist autocompletes known org names → pick date → Load Event → existing event row found → sections render
- "No recent event to default to" is not an edge case — it is the primary new-customer onboarding path for this feature
- Explicit `[ Load Event ]` submit button preferred over auto-submit on change — prevents accidental event row creation from a partial org name mid-typing

**Display name — optional, not required:**
- Guest uploads are anonymous by design; no login, no account
- `display_name` is an optional self-reported field on the upload form (web and iOS); leaving it blank stores `NULL` in `anon_upload_attributions`; admin sees `(anonymous)` in the Guest Uploads table
- The optional nature is intentional — some guests may want attribution ("Jane Smith uploaded this"), most won't; it adds a social element without imposing an identity requirement
- Column label in the admin table: "Display Name" (not renamed — the optional nature is implicit from seeing `(anonymous)` rows)

**ToS text — two distinct audiences, two distinct documents (Phase 1a blocker):**
- The ToS checkbox on the upload form is required (Upload button disabled until checked); `tos_accepted_at` stored in `anon_upload_attributions`; finalize rejects `tos_accepted !== true` with 400
- **Guest (end-user) ToS** — shown on `db/upload_form_single.php` and `GuestUploadView`. Minimum required content: (1) uploaded content is accessible to the event organizer; (2) uploader confirms right to upload their content; (3) participation is anonymous — no account created; (4) optional display name is self-reported and unverified; (5) content may be retained by the organizer indefinitely
- **Operator (commercial) license** — entirely separate from the guest ToS. GigHive is dual-licensed (AGPL v3 for personal/self-hosted/non-commercial use; commercial license required for hosted services, paid products, or client deployments — `contactus@gighive.app`). The admin UI should link to the license or `https://gighive.app` licensing page so operators are clearly informed.
- These two documents are independent — a guest checking the upload ToS box is not agreeing to the operator license. Do not conflate them.
- Wording for both is a legal/product decision — flagged as a blocker for Phase 1a go-live

**Token re-use / re-activation — always generate new:**
- Expired tokens are never re-activated; [Revoke] is the only action on Active rows; Expired and Revoked rows are audit-only with no action button
- Rationale: the raw token is never stored — only the SHA-256 hash. Once expired, the server cannot reconstruct the original QR image. Re-activation would require the admin to still have the old PNG and would re-extend a token that may be in unknown hands.
- Clean security boundary: expired means expired. New distribution event = new token = new QR.
- Recurring-event use case (e.g. weekly residency wanting a permanent QR sign): correct answer is 7-day TTL + regenerate weekly, not re-activating stale tokens. Deferred to Phase 2 if a scheduled-renewal UX is ever warranted.

### Plan changes
Steps 7, 8, 9 restructured; `admin/event_qr.php` added to Files Changed table as New; `upload_form_single.php` table entry updated to remove admin section references.

---

## Step 8 — Token Revocation UI

**Absorbed into step 7.** See the "Active Tokens" section under step 7.

**Key details preserved here for reference:**
- Sets `is_active = 0` — does not delete the row
- Does not interrupt in-flight uploads
- POST handler verifies `token_id` belongs to the same `event_id` (cross-event tamper prevention)
- `anon_upload_attributions` rows from revoked tokens remain; flagged in the guest upload list

---

## Step 9 — Guest Upload List

**Absorbed into step 7.** See the "Guest Uploads" section under step 7.

**Key details preserved here for reference:**
- Source query: `anon_upload_attributions JOIN event_upload_tokens ON token_id WHERE event_upload_tokens.event_id = ? JOIN upload_jobs ON upload_job_id`
- Ordered `DESC` by `created_at` — most recent first
- Revoked-token rows are shown (not hidden) — flagged with a visual indicator

---

## Step 10 — `SAAS_MODE` Env Flag

### What it does (already implemented in Ansible)
- `saas_mode: false` added to all three group_vars files (`gighive2`, `gighive`, `prod`)
- `.env.j2` renders `SAAS_MODE={{ (saas_mode | default(false)) | ternary('true', 'false') }}`
- `qr_code` smoke role verifies `SAAS_MODE` is exactly `true` or `false` in the container env

### What remains (implementation time)
- Read `SAAS_MODE` in PHP bootstrap (`config.php`) — one line; expose as a constant or `getenv()` accessor

### What it does NOT do in Phase 1a
- **No conditional behavior is wired** — the flag is read at bootstrap but nothing branches on it yet
- Implementers must not look for a place to conditionally wire it in Phase 1a — there is no such place
- The flag is purely infrastructure groundwork for Step 7 of the SaaS model (OIDC gate: `SAAS_MODE=false` → Basic Auth, `SAAS_MODE=true` → OIDC)

### Key notes
- Having the flag flow through the env pipeline in Phase 1a means Step 7 of the SaaS model just reads an existing variable rather than adding a new deployment dependency mid-feature
- Value is always `false` in all environments in Phase 1a

### Plan changes
None — Ansible work was already complete from previous sessions.

---

## Terminology Changes Made During Walkthrough

**`fan` → `guest`** — raised during step 6 when reviewing the existing page.

**Rationale:**
- "fan" implies a music/band context; "guest" covers both band concert attendees and wedding guests
- "guest" was already the Swift convention (`GuestUploadSession`, `GuestUploadView`, `QRTokenAPIClient`)
- A single neutral word that fits both event personas

**What changed:**
- All conceptual "fan" references in `feature_iphone_qr_code_support.md` → "guest"
- `feature_saas_model_changes.md` step 5 description updated
- `qr_fan_upload_prefix` → `qr_guest_upload_prefix` in all three group_vars files and in `ansible/roles/qr_code/tasks/main.yml`
- PHP/Apache code symbols (not yet implemented) will be written with "guest" from the start

**What did NOT change:**
- Internal Ansible register variables like `_qr_fan_url_probe` — internal only, no user-facing impact
- Line 17 of the feature doc: "fans at a concert, guests at a wedding" — kept intentionally to distinguish the two personas

**Deferred:** Rename the existing htpasswd `guest` user (e.g., to `readonly`) to avoid conceptual confusion with the QR guest persona. Note added to Security Controls section. No functional impact in Phase 1a.

---

## iOS Steps — Steps 11–23

---

## Steps 11 & 12 — `Configs/GigHive.entitlements` + `project.yml`

### What they do
These two steps are effectively one atomic change and must be committed together.

**Step 11 — `Configs/GigHive.entitlements`** *(Modified)*  
Adds the Associated Domains entitlement that enables Universal Links:
```xml
<key>com.apple.developer.associated-domains</key>
<array>
    <string>applinks:gighive.app</string>
</array>
```
This tells iOS that `gighive.app` is an associated domain for the GigHive app. When a user taps `https://gighive.app/upload/<token>`, iOS checks the AASA file on the server and routes directly into the app instead of Safari.

**Step 12 — `project.yml`** *(Modified)*  
Wires the entitlements file into XcodeGen so the next `xcodegen generate` run includes it:
```yaml
targets:
  GigHive:
    entitlements: Configs/GigHive.entitlements
```
Without this, `xcodegen generate` overwrites `.xcodeproj` and drops the entitlement silently.

### What they do NOT do
- Do not by themselves make Universal Links work — the AASA file on the server (rendered from `ansible/roles/docker/templates/apple-app-site-association.j2` by the docker role before build) and `Content-Type: application/json` from Apache (step 2) must both be present
- Do not affect any other URL patterns — only `/upload/*` on `gighive.app` is registered

### Gate
Requires Apple Developer Program membership. Team ID (`WB7D4FC7XU`) and Bundle ID (`com.gighive.GigHive`) already confirmed. The AASA `appIDs` entry must match exactly: `WB7D4FC7XU.com.gighive.GigHive`.

### Key notes
- AASA template verified correct — `appIDs: ["{{ qr_aasa_app_id }}"]`, `components: [{ "/": "{{ qr_guest_upload_prefix }}/*" }]`; rendered before docker build by docker role task
- Steps 11 and 12 are one commit — never push one without the other

### Plan changes
None.

---

## Step 13 — `Sources/App/GuestUploadSession.swift` *(New)*

### What it does
New `@MainActor ObservableObject` that holds all state for a guest QR upload session — analogous to `AuthSession` but for the credential-free token path.

```swift
@MainActor
final class GuestUploadSession: ObservableObject {
    @Published var rawToken: String?
    @Published var baseURL: String?
    @Published var eventDetails: QREventDetails?
    @Published var displayName: String = ""
    @Published var tosAccepted: Bool = false

    func clear() {
        rawToken = nil
        baseURL = nil
        eventDetails = nil
        displayName = ""
        tosAccepted = false
    }
}

struct QREventDetails: Codable {
    let eventId: Int
    let eventDate: String
    let orgName: String
    let eventType: String
}
```

`clear()` is called by `GuestUploadView` on successful upload — resets all fields so navigation pops cleanly back to `SplashView`.

`QREventDetails` is defined here and shared with steps 14 (`QRTokenAPIClient`) and 21 (`GuestUploadView`).

### What it does NOT do
- Does not hold credentials — no `user`/`pass` fields; mixing with `AuthSession` would corrupt the auth model
- Does not validate the token — `QRTokenAPIClient` (step 14) owns validation
- Does not persist across app restarts — in-memory only

### Gate
Injected as `@StateObject private var guestSession = GuestUploadSession()` in `GigHiveApp` (step 20) and provided via `.environmentObject(guestSession)` to the entire view hierarchy.

### Key notes
- `@MainActor` required — `@Published` properties must be mutated on the main thread; SwiftUI view update cycles read them on the main thread
- **Must be built before steps 14, 20, 21, 22** — all depend on this type

### Plan changes
None.

---

## Step 14 — `Sources/App/QRTokenAPIClient.swift` *(New)*

### What it does
New unauthenticated API client. Calls `GET /api/upload-token.php?token=<rawToken>` and decodes the response into a `QREventDetails`.

```swift
@MainActor
final class QRTokenAPIClient {
    func validate(rawToken: String, baseURL: String) async throws -> QREventDetails {
        var components = URLComponents(string: baseURL)!
        components.path = "/api/upload-token.php"
        components.queryItems = [URLQueryItem(name: "token", value: rawToken)]

        let (data, response) = try await URLSession.shared.data(from: components.url!)
        guard (response as? HTTPURLResponse)?.statusCode == 200 else {
            throw QRTokenError.invalidOrExpired
        }
        return try JSONDecoder().decode(QREventDetails.self, from: data)
    }
}

enum QRTokenError: Error {
    case invalidOrExpired
    case networkFailure(Error)
}
```

### What it does NOT do
- Does not attach any `Authorization` header — intentionally unauthenticated; the raw token is the credential
- Does not distinguish expired vs. revoked vs. nonexistent — all non-200 responses become `invalidOrExpired`; the server gives no distinguishing detail and the client must not either
- Does not create any attribution record — that is the token-auth finalize call's job (step 5 / step 18)

### Why it is unauthenticated
The guest scanning the QR code has no credentials — no username, no password, no session. The raw token encoded in the QR *is* the proof of access (256 bits CSPRNG entropy, computationally unguessable). Requiring auth would block the guest before they even see the upload form.

### Token flow recap
```
Admin generates token → raw token embedded in QR image URL
Guest camera scans QR → iOS parses https://gighive.app/upload/<rawToken>
.onOpenURL fires → guestSession.rawToken = rawToken
GuestUploadView .onAppear → QRTokenAPIClient.validate(rawToken:baseURL:)
Server: hash(rawToken) → DB lookup → returns event details or 404
```

### Who calls it
`GuestUploadView` (step 21) in `.onAppear` via a `Task`. Shows spinner while running; on success populates `guestSession.eventDetails`; on failure shows error state with "Open in Safari".

### Key notes
- **Must use `/api/upload-token.php`** (with `.php` extension) — not `/api/upload-token`; Apache serves it as a direct PHP file, no rewrite rule
- JSON keys are `snake_case` from the server — `JSONDecoder` must use `.convertFromSnakeCase` or `QREventDetails` must use explicit `CodingKeys`
- **Must be built before step 21**

### Plan changes
None.

---

## Steps 15 & 16 — Extraction Refactors from `UploadView.swift`

These are pure refactors — no new behavior. They move `private` types out of `UploadView.swift` to `internal` so `GuestUploadView` can share them.

### Step 15 — `FinalizeResponse.swift` + `FinalizeResponseHandler.swift` *(Extracted from `UploadView.swift`)*

**What it does:**
- Moves `FinalizeResponse` struct (currently `private` inside `UploadView.swift`) to its own file; access changed to `internal`
- Moves `handleFinalizeResponse(status:data:host:)` and `extractJSONCandidate` functions to their own file; access changed to `internal`
- Both `UploadView` and `GuestUploadView` call `handleFinalizeResponse` and decode `FinalizeResponse`

**What it does NOT do:** No logic changes — identical behavior, different file and access level.

### Step 16 — `PHPickerView.swift` + `DocumentPickerView.swift` *(Extracted from `UploadView.swift`)*

**What it does:**
- Moves `PHPickerView` (`UIViewControllerRepresentable`) to its own file; `internal`
- Moves `DocumentPickerView` (`UIViewControllerRepresentable`) to its own file; `internal`
- `GuestUploadView` uses both pickers

**What it does NOT do:** No behavior changes.

### Will these break existing app functionality?
No — purely mechanical refactors. `UploadView.swift` still references all the same symbols by name; they now resolve from separate files instead of inline. The compiler treats this identically — no behavioral difference at runtime. The only risk is a merge conflict if `UploadView.swift` is modified simultaneously.

### Key note — guest video-only filter
`GuestUploadView` uses `PHPickerFilter.videos` — video-only. Guests at events capture video clips, not audio. This restriction is applied in `GuestUploadView`'s configuration of the extracted `PHPickerView`, not inside `PHPickerView` itself — the picker remains general-purpose.

### Gate
Both must be done before step 21. After extraction, `UploadView.swift` is a pure view with no embedded utilities.

### Plan changes
None.

---

## Step 17 — `Sources/App/UploadPayload+GuestUpload.swift` *(New)*

### What it does
Extension on `UploadPayload` adding a factory method for guest uploads. Keeps date formatting, display name trimming, and label derivation out of `GuestUploadView`.

```swift
extension UploadPayload {
    static func forGuestUpload(
        fileURL: URL,
        eventDetails: QREventDetails,
        displayName: String
    ) -> UploadPayload {
        UploadPayload(
            label: fileURL.deletingPathExtension().lastPathComponent,
            eventDate: eventDetails.eventDate,
            orgName: eventDetails.orgName,
            eventType: eventDetails.eventType,
            displayName: String(displayName.prefix(100)).trimmingCharacters(in: .whitespaces)
        )
    }
}
```

### What it does NOT do
- Does not prompt for a label — auto-derived from filename (last path component, extension stripped)
- Does not accept event metadata from the guest — all event fields come from `QREventDetails` (server-derived from the token)
- Does not modify `UploadPayload` itself — extension in a new file only

### Key notes
- `displayName.prefix(100)` enforces the 100-char max client-side before building the payload; server also enforces it, but the client trim avoids a needless round-trip rejection
- **Must be built before step 21**

### Plan changes
None.

---

## Steps 18 & 19 — `UploadClient.swift` + `TUSUploadClient.swift` *(Both Modified)*

Both are additive changes — existing Basic Auth path is completely unchanged.

### Step 18 — `UploadClient.swift`

**What it does:**
- Adds `uploadToken: String?` to `init()` — defaults to `nil`; existing callers unaffected
- In the finalize `URLRequest` builder, token takes exclusive priority over Basic Auth:

```swift
// After:
if let token = uploadToken {
    request.setValue(token, forHTTPHeaderField: "X-Upload-Token")
} else {
    request.setValue("Basic \(basicAuth)", forHTTPHeaderField: "Authorization")
}
```

`GuestUploadView` instantiates: `UploadClient(uploadToken: guestSession.rawToken)`

### Step 19 — `TUSUploadClient.swift`

**What it does:**
- Adds `uploadToken: String?` to `init()` — defaults to `nil`; existing callers unaffected
- In `generateHeaders` closure, token takes priority over Basic Auth:

```swift
// After:
if let uploadToken {
    mutated["X-Upload-Token"] = uploadToken
} else if let basicAuth {
    mutated["Authorization"] = "Basic \(basicAuth)"
}
```

Every TUS chunk for a guest upload carries `X-Upload-Token` — which triggers Apache's `SetEnvIf` → `upload_token_auth` → `<RequireAny>` bypass on `/files/`.

### What neither step does
- Does not change behavior for existing `UploadView` call sites — `uploadToken` is nil by default, `else` branch fires as before
- Does not send both headers simultaneously — token and Basic Auth are mutually exclusive

### Key notes
- Both are the **only** places that set HTTP auth headers — all header logic is centralized here, not scattered across views
- **Both must be done before step 21**

### Plan changes
None.

---

## Step 20 — `Sources/App/GigHiveApp.swift` *(Modified)*

### What it does
Three additions to the existing app entry point:

**1. `guestSession` state object:**
```swift
@StateObject private var guestSession = GuestUploadSession()
```

**2. Environment injection:**
```swift
.environmentObject(guestSession)  // alongside existing .environmentObject(session)
```

**3. Universal Link handler:**
```swift
.onOpenURL { url in
    guard url.host == "gighive.app",
          url.pathComponents.count == 3,
          url.pathComponents[1] == "upload",
          let token = url.pathComponents.last, !token.isEmpty
    else { return }
    guestSession.rawToken = token
    guestSession.baseURL = "\(url.scheme ?? "https")://\(url.host!)"
}
```

Setting `guestSession.rawToken` triggers `SplashView` navigation to `GuestUploadView` (step 22) via SwiftUI's reactive binding.

### URL structure
`https://gighive.app/upload/abc123` → `pathComponents` = `["/", "upload", "abc123"]`
- Index `[1]` = `"upload"` (guard check)
- Index `[2]` / `.last` = raw token

### What it does NOT do
- Does not validate the token — sets state only; validation happens in `GuestUploadView`
- Does not navigate directly — sets `rawToken`; SwiftUI reactive navigation in `SplashView` handles the transition
- Does not touch `AuthSession` or any existing state

### Key notes
- If app is **already running**: `.onOpenURL` fires immediately
- If app is **cold launching** from QR link: iOS delivers the URL after `@main` initializes — `.onOpenURL` still fires before first meaningful view render
- `baseURL` must be stored here — it is needed by the "Open in Safari" fallback in step 23 even if token validation later fails
- **Must be done before step 22**

### Plan changes
None.

---

## Step 21 — `Sources/App/GuestUploadView.swift` *(New)*

The most complex iOS step — depends on **all of steps 13–20** being complete first.

### What it does

**Loading state (`.onAppear`):**
- Shows spinner immediately
- Fires `Task { await validateToken() }` → `QRTokenAPIClient.validate(rawToken:baseURL:)`
- On success: populates `guestSession.eventDetails`, hides spinner, enables form
- On failure: shows error state (step 23)

**Form layout (token valid):**
```
Upload to [Soundwave Festival — July 4, 2026]
Type: Band

  [ Choose Video ]   ← PHPickerView with PHPickerFilter.videos only

  ☐ I accept the Terms of Service   (required — Upload button disabled until checked)

  Display name (optional):
  [________________________________]

  [ Upload ]
```

**Upload flow (`doUpload()`):**
```
1. UploadPayload.forGuestUpload(fileURL:eventDetails:displayName:)   ← step 17
2. TUSUploadClient(uploadToken: guestSession.rawToken)               ← step 19
3. Run TUS upload — every chunk carries X-Upload-Token
4. UploadClient(uploadToken: guestSession.rawToken)                  ← step 18
5. POST /api/uploads/finalize with X-Upload-Token + display_name + tos_accepted: true
6. handleFinalizeResponse(status:data:host:)                         ← step 15
7. On 200/201: guestSession.clear() → success state
```

**Post-upload state (after `guestSession.clear()`):**
```
  Upload received — thank you!

  [ Dismiss ]   ← pops view; SplashView shown
```
`eventDetails` and `rawToken` are both `nil` after `clear()` — view **must** handle this gracefully with nil checks; force-unwrapping crashes.

### What it does NOT do
- Does not prompt for a label — auto-derived from filename via `UploadPayload.forGuestUpload`
- Does not show audio file picker — `PHPickerFilter.videos` only
- Does not send `Authorization: Basic` header anywhere
- Does not navigate to `LoginView`

### Gate
Reached only via `SplashView` when `guestSession.rawToken != nil` (step 22). No direct nav from `LoginView` or elsewhere.

### Key notes
- `@MainActor` required on `doUpload()` — touches `@Published` properties
- The nil-check post-clear is mandatory — SwiftUI re-evaluates the view body after `clear()` fires; any force-unwrap on `eventDetails` crashes the app at the moment of success

### Plan changes
None.

---

## Step 22 — `Sources/App/SplashView.swift` *(Modified)*

### What it does
Adds a third `NavigationLink` route alongside the existing two (authenticated → main app, unauthenticated → `LoginView`):

```swift
// New third route:
NavigationLink(destination: GuestUploadView(), isActive: $isGuestUpload) { }
    .onChange(of: guestSession.rawToken) { token in
        isGuestUpload = token != nil
    }
```

When `guestSession.rawToken` becomes non-nil (set by `.onOpenURL` in step 20), `isGuestUpload` flips to `true` and navigation fires directly to `GuestUploadView` — bypassing `LoginView` entirely.

After `guestSession.clear()` fires (successful upload), `rawToken` → `nil`, `isGuestUpload` → `false`, navigation stack pops back to `SplashView` cleanly.

### What it does NOT do
- Does not affect the existing auth routes — both remain untouched
- Does not validate the token — that happens inside `GuestUploadView` on `.onAppear`
- Does not show any UI — `NavigationLink` with empty label is invisible

### Key notes
- `guestSession` is available here via `@EnvironmentObject` injected in step 20
- **Depends on step 21** — `GuestUploadView` must exist before `SplashView` can reference it

### Plan changes
None.

---

## Step 23 — iOS Fallback / Error Screen

### What it does
Not a separate file — this is the **error state inside `GuestUploadView`** shown when `QRTokenAPIClient` throws `QRTokenError.invalidOrExpired`.

```
  ⚠ This upload link is no longer valid.
    It may have expired or been revoked by the event organizer.

  [ Open in Safari ]
```

**"Open in Safari" button:**
```swift
let urlString = "\(guestSession.baseURL ?? "https://gighive.app")/upload/\(guestSession.rawToken ?? "")"
if let url = URL(string: urlString) {
    UIApplication.shared.open(url)
}
```

This opens `https://gighive.app/upload/<token>` in Safari → hits `upload_form_single.php` → `UploadTokenValidator` returns null → PHP renders a clean error page (no upload form shown, no PHP warnings, no information disclosure).

### What it does NOT do
- Does not reveal whether the token is expired vs. revoked vs. never existed — mirrors server behavior
- Does not retry automatically — guest must tap "Open in Safari" deliberately

### Key notes
- `baseURL` must be stored on `guestSession` before token validation fails (set in step 20's `.onOpenURL`) — needed here to reconstruct the Safari URL even though the token is invalid
- The web fallback (`upload_form_single.php`) handles the invalid token gracefully on its own — step 6 implementation requirement

### Plan changes
None.

---

## Summary of All Plan Documents Updated During Walkthrough

### Phase 1a Plan Doc + Related Files

| Document | Type | What Changed |
|---|---|---|
| `docs/feature_iphone_qr_code_support.md` | Modified | Step 1 wording (exit-on-missing); Step 5 (MVC file references + flow diagram); Steps 6–9 restructured (admin to new page, steps 8–9 absorbed); Security Controls section added; fan→guest throughout; htpasswd deferred note; mermaid chart TODO on step 5; Files Changed table updated |
| `docs/feature_saas_model_changes.md` | Modified | Step 5 description: fan→guest |
| `ansible/inventories/group_vars/gighive2/gighive2.yml` | Modified | `qr_fan_upload_prefix` → `qr_guest_upload_prefix`; section comment "QR Code Fan Upload" → "QR Code Guest Upload" |
| `ansible/inventories/group_vars/gighive/gighive.yml` | Modified | Same |
| `ansible/inventories/group_vars/prod/prod.yml` | Modified | Same |
| `ansible/roles/qr_code/tasks/main.yml` | Modified | Variable reference and task names updated to `qr_guest_upload_prefix` / "guest" |
| `ansible/roles/docker/templates/apple-app-site-association.j2` | New | Jinja2 template; uses `qr_aasa_app_id` and `qr_guest_upload_prefix`; replaces former static file |
| `ansible/roles/docker/tasks/main.yml` | Modified | New task renders AASA template to `{{ docker_dir }}/apache/webroot/.well-known/apple-app-site-association` before `docker compose build` |
| `ansible/roles/docker/files/mysql/externalConfigs/create_media_db.sql` | Deferred | `anon_upload_attributions.display_name` COMMENT: "fan display name" → "guest display name" — reverted; bundle with next DDL batch |

### Server / PHP Files (implementation — not yet written)

| File | Type | Step |
|---|---|---|
| `create_media_db.sql` | Modified | 1 |
| `ansible/roles/docker/templates/default-ssl.conf.j2` | Modified | 2 |
| `src/Services/UploadTokenValidator.php` | New | 3 |
| `api/upload-token.php` | New | 4 |
| `src/Controllers/UploadController.php` | Modified | 5 |
| `src/Services/UploadService.php` | Modified | 5 |
| `db/upload_form_single.php` | Modified | 6 |
| `admin/event_qr.php` | New | 7 |
| `config.php` | Modified | 10 |

### iOS App Files (implementation — not yet written)

| File | Type | Step |
|---|---|---|
| `Configs/GigHive.entitlements` | Modified | 11 |
| `project.yml` | Modified | 12 |
| `Sources/App/GuestUploadSession.swift` | New | 13 |
| `Sources/App/QRTokenAPIClient.swift` | New | 14 |
| `Sources/App/FinalizeResponse.swift` | Extracted (New) | 15 |
| `Sources/App/FinalizeResponseHandler.swift` | Extracted (New) | 15 |
| `Sources/App/PHPickerView.swift` | Extracted (New) | 16 |
| `Sources/App/DocumentPickerView.swift` | Extracted (New) | 16 |
| `Sources/App/UploadView.swift` | Modified | 15, 16 |
| `Sources/App/UploadPayload+GuestUpload.swift` | New | 17 |
| `Sources/App/UploadClient.swift` | Modified | 18 |
| `Sources/App/TUSUploadClient.swift` | Modified | 19 |
| `Sources/App/GigHiveApp.swift` | Modified | 20 |
| `Sources/App/GuestUploadView.swift` | New | 21 |
| `Sources/App/SplashView.swift` | Modified | 22 |
