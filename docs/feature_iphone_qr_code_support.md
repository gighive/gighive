# Feature: QR Code Fan Upload (Phase 1a, Step 5)

**Status:** Not started  
**Phase:** 1a (pre-release; ships with self-hosted)  
**Prerequisite:** Phase 1 schema changes complete (`tenants` table, `tenant_id` FKs on `events` and `upload_jobs`)  
**Reference:** `docs/feature_saas_model_changes.md` step 5  
**Apple Team ID:** `WB7D4FC7XU` (Gighive Labs, LLC)  
**Production domain:** `gighive.app`  
**App Bundle ID:** `com.gighive.GigHive`  
**AASA app entry:** `WB7D4FC7XU.com.gighive.GigHive`  
**PHP runtime:** php-fpm 8.3 — `readonly class`, named arguments, fibers, and all 8.2/8.3 features available

---

## Overview

QR Code Fan Upload enables anonymous, credential-free media contribution from venue attendees — fans at a concert, guests at a wedding. The event owner generates a per-event QR code from the admin page. When a fan scans it, **iPhone with the app installed** is routed to the native GigHive app via Universal Links, landing on a pre-filled upload screen that requires only ToS acceptance. **Android and iPhone without the app** go to a web form. All uploads are attributed to the event and the QR token with an optional self-reported display name; no account is created.

This replaces the current model where fan uploads share a single set of htpasswd credentials with zero per-upload attribution.

---

## Customer Journey

1. Promoter (band manager, wedding coordinator) opens the event admin page and generates a QR code for the event.
2. The QR code is displayed on-screen at the venue — on a projector, printed flyer, or handout.
3. A fan or guest scans the QR code with their phone camera.
4. **iPhone with app installed** → iOS opens the native GigHive app directly via Universal Links and routes to the upload screen for that event. No login required.
5. **Android, or iPhone without the app** → phone opens the URL in the browser and lands on `db/upload_form_single.php`.
6. User accepts ToS (one checkbox), optionally enters a display name, selects file(s), and uploads.
7. Upload is attributed to the event and the QR token. The owner can see all fan-contributed uploads in the event admin view.

---

## Token URL Mechanism

The QR code encodes an HTTPS URL containing an opaque random token. The URL structure:

```
https://<host>/upload/<raw_token>
```

Where `raw_token` is a URL-safe random string (e.g. 32 bytes of CSPRNG output, base64url-encoded). The server stores `SHA-256(raw_token)` as `token_hash` in `event_upload_tokens` — the raw token is never stored.

**Validation on every request:**
1. Hash the presented `raw_token` → look up `token_hash` in `event_upload_tokens`
2. Check `is_active = 1`
3. Check `expires_at > NOW()`
4. If all pass → grant upload access to `event_id` on that row

Security comes from the cryptographic entropy of the token (256 bits of CSPRNG randomness is computationally unguessable) — no HMAC signing key is needed. The token is validated by DB lookup, not signature verification.

---

## Database Schema

Both tables are created in this step. Neither needs a direct `tenant_id` — tenant scope is derived through their FK chains.

```sql
CREATE TABLE event_upload_tokens (
  token_id            bigint unsigned NOT NULL AUTO_INCREMENT,
  event_id            INT             NOT NULL,
  token_hash          char(64)        NOT NULL  COMMENT 'SHA-256 hex of the raw token; raw token is never stored',

  expires_at          datetime        NOT NULL,
  is_active           tinyint(1)      NOT NULL DEFAULT 1,
  created_by_user_id  int unsigned    DEFAULT NULL  COMMENT 'user_id of owner who generated the token; NULL pre-step-7 (Basic Auth era)',
  created_at          datetime        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (token_id),
  UNIQUE KEY uq_event_upload_tokens_hash (token_hash),
  KEY idx_event_upload_tokens_event (event_id),
  KEY idx_event_upload_tokens_creator (created_by_user_id),
  CONSTRAINT fk_eut_event FOREIGN KEY (event_id)
    REFERENCES events (event_id) ON DELETE CASCADE
  -- fk_eut_created_by (created_by_user_id → users.id) deferred to step 7 — no users table in Phase 1a
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE anon_upload_attributions (
  attribution_id  bigint unsigned NOT NULL AUTO_INCREMENT,
  token_id        bigint unsigned NOT NULL,
  upload_job_id   varchar(64)     NOT NULL,
  display_name    varchar(255)    DEFAULT NULL  COMMENT 'Self-reported fan display name',
  tos_accepted_at datetime        NOT NULL      COMMENT 'Timestamp of anonymous ToS acceptance',
  created_at      datetime        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (attribution_id),
  KEY idx_anon_upload_token (token_id),
  KEY idx_anon_upload_job (upload_job_id),
  CONSTRAINT fk_aua_token FOREIGN KEY (token_id)
    REFERENCES event_upload_tokens (token_id) ON DELETE CASCADE,
  CONSTRAINT fk_aua_job FOREIGN KEY (upload_job_id)
    REFERENCES upload_jobs (job_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

**Tenant derivation chain:**
- `event_upload_tokens` → `events.tenant_id` (via `event_id` FK)
- `anon_upload_attributions` → `upload_jobs.tenant_id` (via `upload_job_id` FK)

---

## Platform Routing

```
Scan QR → https://gighive.app/upload/<token>
  ├── iPhone + app installed  →  iOS Universal Link → GuestUploadView        (items 12–24)
  └── Everyone else           →  HTTPS in browser  → upload_form_single.php  (item 5)
        ├── Android
        └── iPhone without app (Safari fallback)
```

### Android / iPhone without app (Web Path)

Landing page: `db/upload_form_single.php`

Responsibilities:
1. Extract `raw_token` from URL
2. Validate token (hash → DB lookup → `is_active` → `expires_at`)
3. Fetch event details (`event_date`, `org_name`, `title`) for display
4. Render upload form: ToS checkbox (required), display name field (optional), file picker
5. On submit: create `upload_jobs` row, create `anon_upload_attributions` row (recording `token_id`, `upload_job_id`, `display_name`, `tos_accepted_at = NOW()`), hand off file to existing upload pipeline
6. On invalid/expired token: show a friendly error — do not reveal whether the token exists

**ToS acceptance is written to `anon_upload_attributions.tos_accepted_at`** — it is a DB record, not just a UI check.

### iPhone with App Installed (Universal Links Path)

The same HTTPS URL routes to the native iOS app when Universal Links is configured.

**Server-side requirements:**
- Serve `/.well-known/apple-app-site-association` (AASA) from the domain root
- AASA must list the app's Team ID + Bundle ID and the URL pattern for upload paths (e.g. `/upload/*`)
- The AASA file must be served with `Content-Type: application/json` and must NOT be redirected

**iOS app requirements (all greenfield — see Current iOS Codebase State below):**

| File | Change | Notes |
|---|---|---|
| `Configs/GigHive.entitlements` | Add `com.apple.developer.associated-domains` key with `applinks:<host>` | Currently empty `<dict/>` |
| `project.yml` | Add `entitlements` Associated Domains key so XcodeGen picks it up | No URL scheme or Associated Domains today |
| `Sources/App/GigHiveApp.swift` | Add `.onOpenURL { url in }` on the `WindowGroup` | No URL handler exists today |
| `Sources/App/GuestUploadSession.swift` | New `ObservableObject` holding `rawToken`, resolved event details, ToS/display name state | `AuthSession` is Basic Auth only — wrong object to reuse |
| `Sources/App/QRTokenAPIClient.swift` | New: unauthenticated `GET /api/upload-token`; attribution created by the token-auth finalize call — no separate submit endpoint | No unauthenticated API client exists |
| `Sources/App/GuestUploadView.swift` | New view: event pre-populated from token, ToS checkbox, display name field, file picker | Entirely new — no guest upload view exists |
| `Sources/App/UploadClient.swift` | Add token-auth mode: pass `X-Upload-Token: <raw_token>` header; omit Basic Auth | Currently hardwires Basic auth on every request |
| `Sources/App/SplashView.swift` | Add third route: if `GuestUploadSession.rawToken` is set, navigate to `GuestUploadView` bypassing `LoginView` | Only two routes today (Login → DB, Login → Upload) |

**Fallback:** If the app is not installed, iOS falls back to opening the URL in Safari, which hits the web path above. No special handling needed — the URL must work as a plain HTTPS page.

---

## Current iOS Codebase State

Reviewed against `/mnt/scottsfiles/gighive/GigHive-iPhone/GigHive` (snapshot 2026-06-26).

| Component | Current State | Gap |
|---|---|---|
| `Configs/GigHive.entitlements` | Empty `<dict/>` | Associated Domains not configured |
| `project.yml` | No URL schemes, no Associated Domains | Universal Links cannot activate |
| `GigHiveApp.swift` | No `.onOpenURL` or `onContinueUserActivity` | Incoming URLs silently ignored |
| `AuthSession.swift` | `(user: String, pass: String)?` only | No token-based session concept |
| `LoginView.swift` | Server URL + username + password form | No token/guest path |
| `SplashView.swift` | Two routes: Login→DB, Login→Upload | No QR/guest route |
| `UploadClient.swift` | Always sends `Authorization: Basic …` | No token-auth upload mode |
| `UploadPayload` struct | Requires `eventDate`, `orgName`, `eventType`, `label` from user input | For QR path, these come from the server |

**Net assessment:** QR upload is entirely greenfield iOS work. The existing TUS upload infrastructure (`UploadClient`, `TUSUploadClient`) can be reused but needs a token-auth mode added.

---

## API Endpoints

Two endpoints needed — one new (token validation), one extension of the existing finalize:

### `GET /api/upload-token`
Validates a raw token and returns event details for display in the app upload screen.

```
GET /api/upload-token?token=<raw_token>
```

Response (200 OK):
```json
{
  "event_id": 12,
  "event_date": "2026-08-15",
  "org_name": "The Midnight",
  "event_type": "band",
  "title": "Summer Tour — Chicago",
  "token_id": 7
}
```

`event_type` is included so `GuestUploadView` can populate TUS metadata accurately and display context-appropriate labels (e.g. "Band" vs "Wedding"). The finalize endpoint still derives `event_type` authoritatively from the DB regardless of what the client sends.

Error responses:
- `404` — token not found, expired, or inactive (do not distinguish between cases)

### `POST /api/uploads/finalize` — token-auth variant

The existing finalize endpoint requires Basic Auth and accepts event metadata (`event_date`, `org_name`, `event_type`, `label`) from the client. For QR uploads, the token IS the auth credential and event context comes from the DB (not the client). The endpoint supports a second authentication path:

- If `Authorization: Basic` header present → existing behaviour (authenticated admin upload)
- If `X-Upload-Token: <raw_token>` header present → validate token → look up `event_id` from `event_upload_tokens` → derive `event_date`/`org_name`/`event_type` from `events` row → ignore any client-supplied event fields; accept `label` (required, auto-derived from filename by iOS), `display_name` (optional), `tos_accepted` (required, must be `true`) from request body → create `anon_upload_attributions` row atomically with the upload job

The iOS app sends **one finalize request** that serves as both upload completion and attribution record creation — no separate submit endpoint needed. The web path (`upload_form_single.php`) does the same in a single PHP request.

---

## QR Code Generation (Admin Side)

The owner generates a QR code from the event admin page. Implementation:

1. PHP generates a CSPRNG `raw_token` (32 bytes → base64url): `rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=')` — standard `base64_encode()` alone is NOT sufficient; `+`, `/`, `=` are not URL-safe
2. Inserts a row into `event_upload_tokens` (`token_hash = SHA2(raw_token, 256)`, `event_id`, `expires_at`, `created_by_user_id` — NULL in Phase 1a, set once step 7 RBAC ships)
3. Constructs the upload URL: `https://<host>/upload/<raw_token>`
4. Renders a QR code from the URL — client-side JS using `qrcode` (node-qrcode v1.5.x) via jsDelivr CDN (`qrcode@1.5.4/build/qrcode.min.js`); no Composer dependency required
5. Owner can download the QR image or display it full-screen for projection

**Token expiry:** Default to 24 hours for event-day use. Configurable per token. The owner can also revoke (`is_active = 0`) without waiting for expiry.

---

## Security

### SEC-6 — Token entropy and CSPRNG requirement (High)
Tokens must be generated with a CSPRNG: `random_bytes(32)` in PHP; `SecRandomCopyBytes` on iOS. A 256-bit random token is computationally unguessable without any signing key. Do **not** use `rand()`, `mt_rand()`, `uniqid()`, or any other non-CSPRNG source. Store only `SHA-256(raw_token)` in the DB — the raw token must never be persisted.

### SEC-9 — Stored XSS via display name (Medium)
`anon_upload_attributions.display_name` is self-reported by an unauthenticated user.
- Strip/reject HTML at write time (both PHP form handler and iOS API endpoint)
- Enforce max 100 characters in form validation, DB insert, and iOS app field
- Always escape on output: `htmlspecialchars($name, ENT_QUOTES, 'UTF-8')`
- CSP required — see SEC-16

### SEC-15 — CSRF protection on `upload_form_single.php` (Medium)
The anonymous upload form is a POST form accessed by unauthenticated users. The raw token in the URL path (`/upload/<token>`) functions as a per-session nonce: an attacker cannot forge a valid POST without knowing a live token (256-bit entropy). This is an accepted CSRF-mitigation pattern for token-gated anonymous forms. To satisfy SonarQube S5145:
- Carry the raw token as a hidden form field: `<input type="hidden" name="_token" value="<?= htmlspecialchars($rawToken) ?>">`
- On POST, verify that `$_POST['_token'] === $rawToken` (same-request consistency check) before processing
- This is not a substitute for token validation — validation (hash → DB lookup) runs regardless

### SEC-16 — Security response headers (Medium)
All new endpoints and pages must set the following headers. Add to Apache vhost or `.htaccess`:
```apache
Header always set X-Content-Type-Options "nosniff"
Header always set X-Frame-Options "DENY"
Header always set Referrer-Policy "strict-origin-when-cross-origin"
```
The admin QR generator page must additionally set:
```apache
Header always set Content-Security-Policy "default-src 'self'; script-src 'self' https://cdn.jsdelivr.net; img-src 'self' data:; style-src 'self' 'unsafe-inline'"
```
(`unsafe-inline` for styles only; `script-src` explicitly allows jsDelivr for the `qrcode` CDN script; `data:` allows the canvas-to-PNG data URL download.)

### SEC-17 — File upload safety in `upload_form_single.php` (Medium / SonarQube S2083)
`upload_form_single.php` calls `$_FILES` and hands the file to the existing upload pipeline. The pipeline is responsible for:
- Sanitizing the original filename (strip directory components: `basename($_FILES['file']['name'])`)
- Enforcing max file size (reject at PHP layer before pipeline, aligned with `upload_max_filesize`)
- Storing the file outside the web root (existing pipeline already does this)
- Not trusting `$_FILES['type']` for MIME validation — use `finfo_file()` on the tmp file
The plan notes this explicitly so the implementation does not re-implement file handling inline — all of the above must be verified as already present in `UploadService.php` before this form is wired up.

### Token validation hardening
- **No `hash_equals()` call is needed in the token validation path.** The raw token is hashed in PHP (`hash('sha256', $rawToken)`) and looked up via `WHERE token_hash = ?` using a prepared statement. PHP never compares hash strings directly; the DB handles the equality check. `hash_equals()` is only relevant when comparing two hash strings in PHP (e.g., HMAC verification) — it must not be added here or it signals a misunderstood flow to future developers.
- Do not distinguish "token not found" from "token expired" or "token revoked" in error responses — all return the same 404
- **Do NOT set `is_active = 0` on token use** — QR tokens are multi-use (many fans per event). Owner revocation is a manual `UPDATE event_upload_tokens SET is_active = 0`. See saas doc SEC-11 for the contrast with single-use invite links.
- Raw token appears in Apache access logs (`/upload/<token>` path). With a 24-hour default expiry this is accepted risk. If expiry is extended beyond 7 days, consider configuring Apache `LogFormat` to redact the token component of `/upload/*` paths.

---

## Design Decisions Log

| Decision | Choice Made | Rationale |
|---|---|---|
| Token security model | Opaque CSPRNG token (32 bytes); SHA-256 hash stored in DB | Tokens are revocable, auditable, and require no signing key to manage. 256-bit randomness is computationally unguessable. HMAC-signed URLs (the alternative) require a signing key and rotation infrastructure with no additional security benefit over a CSPRNG token |
| Attribution record creation | Single `POST /api/uploads/finalize` call with `X-Upload-Token` header; creates `anon_upload_attributions` row atomically | Eliminates a separate submit endpoint. Two-step attribution (upload → separate submit) creates a window where upload completes without an attribution record. Mirrors web path which does both in one PHP request |
| `label` source for fan uploads | Auto-derived from uploaded filename by iOS app | Fans are not metadata authors; prompting for a label adds friction. Filename is always available and sufficient for owner identification in the event admin view |
| Web path upload mechanism | Reuse existing `tus-js-client` in `upload_form_single.php` with `X-Upload-Token` header — same TUS infrastructure as admin path | `upload_form_single.php` already uses tus-js-client. Adding a second non-TUS multipart upload mechanism would require a new server-side file handler and duplicate pipeline logic. Passing `X-Upload-Token` in tus-js-client `headers` option requires zero library changes; the finalize endpoint already plans an X-Upload-Token path (item 4). Simpler and consistent. |
| iOS session object for QR path | New `GuestUploadSession` — `AuthSession` not reused | `AuthSession` is tightly coupled to Basic Auth credentials `(user: String, pass: String)?`. A guest upload has no credentials — mixing these would corrupt the auth model |
| `POST /api/upload-token/submit` endpoint | Eliminated | Redundant once the token-auth finalize variant creates the `anon_upload_attributions` row atomically |
| Port in `baseURL` extraction | Omitted — production is HTTPS port 443 only | Apache only exposes 443. Universal Links require HTTPS. No port handling needed. |
| “Open in Safari” URL | Reconstruct from `guestSession.baseURL + /upload/ + rawToken` | Opens `https://gighive.app/upload/<token>` in Safari; lands on the web fallback form which shows a friendly expired-token error page. |
| `event_type` in token API response | Included in `GET /api/upload-token` response | New endpoint so no breakage risk. iOS needs it for TUS metadata accuracy and to display correct UI labels (Band vs Wedding). Finalize still derives it from DB. |
| Token validation timing in `GuestUploadView` | Spinner inside `GuestUploadView` on `.onAppear`; view owns its loading/error state | Standard iOS deep link pattern (Spotify, Twitter, etc.). Option of validating in `onOpenURL` has a race condition — `Task` may not finish before view appears. Option of validating in `SplashView` puts data-fetching in the wrong layer. |
| QR code library | `qrcode` (node-qrcode v1.5.x) via jsDelivr CDN, client-side JS | No Composer dependency, no build system. Renders to canvas for venue display and outputs data URL for PNG download. 100M+ monthly npm downloads — most widely used QR library. |
| `FinalizeResponse`, `PHPickerView`, `DocumentPickerView` scope | Extracted to individual files, `internal` access | Both `UploadView` and `GuestUploadView` need all three. Extracting to own files is cleaner than making `UploadView.swift` larger. |
| TUSKit custom header injection | Modify existing `generateHeaders` closure in `TUSUploadClient.swift` | TUSKit already exposes a `HeaderGenerationHandler` block (`generateHeaders:` init parameter) for per-request header mutation — no library changes, no deprecated APIs, minimal diff |
| `created_by_user_id` FK in Phase 1a | Column present (`DEFAULT NULL`); FK constraint (`fk_eut_created_by`) deferred to step 7 | No `users` table exists in Phase 1a. The FK is added via `ALTER TABLE` when step 7 (OIDC/RBAC) ships. Column stays nullable with no constraint in the interim — no code change required. |
| Per-event subdomains | Apex `gighive.app/upload/TOKEN` for Phase 1a; per-event subdomains deferred to Phase 2 | Cloudflare already handles TLS automatically (no wildcard cert complexity). `patcioffi.gighive.app` exists as a static test route. However, dynamically provisioning a subdomain per event requires Cloudflare API automation — out of scope for Phase 1a. |

---

## Implementation Subtasks

> This is the canonical task list for Step 5. Ordered by dependency — each item can be started once its predecessors are complete. See the sections below for full implementation detail on each item.

**Server / PHP**

1. DB tables — `event_upload_tokens` and `anon_upload_attributions` already added to `create_media_db.sql` and the combined Phase 1 migration (steps 1–4); confirm tables exist before proceeding
2. Apache vhost config — two additions; **must be deployed before item 6 is testable**:
   - **Fan upload routing:** `RewriteRule ^/upload/([A-Za-z0-9_-]+)$ /db/upload_form_single.php?token=$1 [L,QSA]` — routes fan QR URLs to the PHP handler; without this rule the fan URL 404s
   - **AASA:** create static file at `ansible/roles/docker/files/apache/webroot/.well-known/apple-app-site-association` — deployed by Ansible alongside all other webroot files; no `.php` extension, no DB hit; add `<Location /.well-known/apple-app-site-association>` Apache block — `Content-Type: application/json`, no redirect, no auth; app entry `WB7D4FC7XU.com.gighive.GigHive`; pattern `/upload/*`
3. `src/Services/UploadTokenValidator.php` — shared class, `namespace Production\Api\Services` (matches all existing services); `validate(string $rawToken): TokenValidationResult|null`; used by items 4, 5, and 6 to prevent validation logic from drifting; build this before any token-consuming endpoint
4. `GET /api/upload-token` (`api/upload-token.php`) — unauthenticated token validation endpoint; returns event details for iOS; calls `UploadTokenValidator`
5. `POST /api/uploads/finalize` (`api/uploads/finalize.php`) — add `X-Upload-Token` auth path alongside existing Basic Auth; calls `UploadTokenValidator`; derives event context from DB; accepts `label` (required), `display_name` (optional, strip HTML, max 100 chars — **implement sanitization here, do not defer**), `tos_accepted` (required, must be JSON `true`); creates `anon_upload_attributions` row atomically; see implementation notes below
6. `db/upload_form_single.php` — add `require_once __DIR__ . '/../vendor/autoload.php';` and `use Production\Api\Services\UploadTokenValidator;` at the top (same pattern as `db/delete_media_files.php` — currently absent from this file); add QR token mode (third mode alongside existing admin/fan modes): detect `?token=` from URL (set by Apache rewrite in item 2); validate via `UploadTokenValidator`; pre-populate event fields from DB (read-only); show ToS checkbox (required) + display name field (optional, `strip_tags(trim($displayName))` before storing, `htmlspecialchars()` at every render, max 100 chars — **implement sanitization here, do not defer**); use **existing TUS infrastructure** with `X-Upload-Token: <raw_token>` in TUS `headers` option and finalize `fetch` headers; omit `withCredentials: true` and Basic Auth in token mode; pass `display_name` and `tos_accepted: true` in finalize body; finalize endpoint (item 5) creates `anon_upload_attributions` row atomically
7. `db/upload_form_single.php` — add admin QR generator section (visible only when `$user === 'admin'`, scoped by `?event_id=X`; **note:** this Basic Auth username check is acceptable for Phase 1a — revisit when OIDC/RBAC ships in step 7): CSPRNG token via `bin2hex(random_bytes(32))` → 64-char hex raw token; SHA-256 hash stored in DB; `event_upload_tokens` INSERT; QR render via `qrcode@1.5.4` CDN (jsDelivr), canvas display + PNG download button
   - **Expiry:** Owner selects from a dropdown at generation time: **4h / 24h / 7d**; pre-selected value comes from `QR_TOKEN_DEFAULT_TTL_HOURS` env var; PHP computes `expires_at = NOW() + INTERVAL <hours> HOUR`; server validates `expires_at > NOW()` on INSERT regardless of client input
   - **Default:** `QR_TOKEN_DEFAULT_TTL_HOURS=24` — add `qr_token_default_ttl_hours: 24` to Ansible `group_vars` and add `QR_TOKEN_DEFAULT_TTL_HOURS={{ qr_token_default_ttl_hours }}` to `.env.j2` (same pattern as other env vars in that template); overridable per environment without a code deploy
8. `db/upload_form_single.php` — add token revocation UI in the admin section (`$user === 'admin'` gate — same Phase 1a caveat as item 7): list active tokens for the event (`?event_id=X`); POST to toggle `is_active = 0` on a token row
9. `db/upload_form_single.php` — add fan-upload list in the admin section (`$user === 'admin'` gate — same Phase 1a caveat as item 7; same `?event_id=X` scope): query `anon_upload_attributions JOIN event_upload_tokens ON token_id WHERE event_upload_tokens.event_id = ? JOIN upload_jobs ON upload_job_id`; display columns: display name, upload timestamp, filename (`upload_jobs` label), token `is_active` status; flat list ordered by `anon_upload_attributions.created_at DESC`; show a note on rows where the token has since been revoked (`is_active = 0`)
10. `SAAS_MODE` env flag — add `saas_mode` boolean to Ansible `group_vars` (false for self-hosted, true for SaaS); render into `.env` via `.env.j2`; add `SAAS_MODE` read in PHP bootstrap; gates OIDC requirement vs. Basic Auth preservation

**iOS App**

11. `Configs/GigHive.entitlements` — add `com.apple.developer.associated-domains: applinks:gighive.app`
12. `project.yml` — wire Associated Domains entitlement so XcodeGen generates it
13. `Sources/App/GuestUploadSession.swift` — new `@MainActor ObservableObject`: `rawToken`, `baseURL`, `eventDetails`, `displayName`, `tosAccepted`; `clear()` resets after successful upload; also defines `QREventDetails` Codable struct used by items 14 and 21
14. `Sources/App/QRTokenAPIClient.swift` — unauthenticated `GET /api/upload-token`; uses `QREventDetails` from item 13; no separate submit endpoint (attribution created by token-auth finalize in item 5)
15. `Sources/App/FinalizeResponse.swift` + `FinalizeResponseHandler.swift` — extract from `UploadView.swift`; make `internal`; **do this before item 21** — `GuestUploadView` calls `handleFinalizeResponse` and decodes `FinalizeResponse`
16. `Sources/App/PHPickerView.swift` + `DocumentPickerView.swift` — extract from `UploadView.swift`; make `internal`; **do this before item 21** — `GuestUploadView` uses both pickers
17. `Sources/App/UploadPayload+GuestUpload.swift` — `UploadPayload.forGuestUpload(fileURL:eventDetails:displayName:)` factory; uses `QREventDetails` from item 13; **do this before item 21**
18. `Sources/App/UploadClient.swift` — add `uploadToken: String?` to `init()`; omit `Authorization: Basic` header when token present; pass `X-Upload-Token` in finalize request; **do this before item 21** — `GuestUploadView.doUpload()` instantiates `UploadClient(uploadToken:)`
19. `Sources/App/TUSUploadClient.swift` — add `uploadToken: String?` to `init()`; in `generateHeaders` closure replace `if let basicAuth { … }` with `if let uploadToken { mutated["X-Upload-Token"] = uploadToken } else if let basicAuth { … }`
20. `Sources/App/GigHiveApp.swift` — add `@StateObject guestSession`; add `.environmentObject(guestSession)`; add `.onOpenURL` to parse `/upload/<raw_token>` and assign to `guestSession.rawToken`
21. `Sources/App/GuestUploadView.swift` — new view: event info pre-populated from token, ToS checkbox (required), display name field (optional), file picker (**video-only filter** — `PHPickerFilter.videos`; fans at events capture video clips, not audio); `label` auto-derived from filename; no credentials; **post-upload state:** on success `guestSession.clear()` is called — the view must handle `eventDetails == nil` + `rawToken == nil` gracefully by showing a "Upload received — thank you!" confirmation with a Dismiss button that pops the view; **depends on items 13–20 all being complete**
22. `Sources/App/SplashView.swift` — add third `NavigationLink` route: if `guestSession.rawToken != nil`, navigate directly to `GuestUploadView` bypassing `LoginView`; **depends on item 21**
23. iOS fallback/error screen — shown within `GuestUploadView` (item 21) when token validation fails; offer "Open in Safari" button reconstructing `guestSession.baseURL + /upload/ + rawToken`

---

## Phase 1a Scope and Boundary

**In scope for Phase 1a:**
- Both new DB tables (`event_upload_tokens`, `anon_upload_attributions`) and migration script
- QR code generator on the event admin page (token generation, QR image render, revocation toggle)
- Web upload form (`db/upload_form_single.php`) — Android + web fallback path
- `GET /api/upload-token` token validation endpoint
- Token-auth variant of `POST /api/uploads/finalize`
- AASA file + Apache config — Team ID confirmed (`WB7D4FC7XU`); no blocker
- All iOS app greenfield files (`GuestUploadSession`, `QRTokenAPIClient`, `GuestUploadView`)
- iOS app modifications to existing files (`GigHiveApp`, `TUSUploadClient`, `UploadClient`, `SplashView`, `project.yml`, entitlements)

**Deferred (not Phase 1a):**
- Owner UI: browsable fan-contributed upload list per event — the data exists in `anon_upload_attributions` from day one; the admin view is a follow-on feature
- `created_by_user_id` population — automatically populated when step 7 (OIDC/RBAC) ships; NULL in the interim
- Rate limiting per QR token (e.g., max N uploads per token) — not designed; deferred
- Storage quota measurement for fan uploads — addressed in Phase 2 step 13

---

## Files Changed

### Server / PHP / Config

| File | Type | Change |
|---|---|---|
| `create_media_db.sql` | Modified | Add `event_upload_tokens` and `anon_upload_attributions` DDLs |
| `db/upload_form_single.php` | New | Token validation, event display, ToS + display name form, standard multipart upload handler (Android + web fallback path) |
| `admin/<event-admin-page>.php` | New or Modified (filename TBD) | QR code generator: CSPRNG token generation, `event_upload_tokens` INSERT, QR render via `qrcode` JS CDN (`qrcode@1.5.4/build/qrcode.min.js`), PNG download button, revocation UI |
| `src/Services/UploadTokenValidator.php` | New | Shared `validate(string $rawToken): ?TokenValidationResult`; called by `upload_form_single.php`, `api/upload-token.php`, and `api/uploads/finalize.php` to prevent drift |
| `api/upload-token.php` | New | `GET /api/upload-token` — unauthenticated token validation via `UploadTokenValidator`; returns event details for iOS app |
| `api/uploads/finalize.php` | Modified | Add `X-Upload-Token` auth path alongside existing Basic Auth; derive event context from DB; accept `label`, `display_name`, `tos_accepted` in body; create `anon_upload_attributions` row atomically |
| `.well-known/apple-app-site-association` | New | AASA JSON: app entry `WB7D4FC7XU.com.gighive.GigHive`, pattern `/upload/*` |
| Apache vhost config | Modified | Add `<Location /.well-known/apple-app-site-association>` block: `Content-Type: application/json`, no auth |

### iOS App

| File | Type | Change |
|---|---|---|
| `Configs/GigHive.entitlements` | Modified | Add `com.apple.developer.associated-domains` with `applinks:gighive.app` |
| `project.yml` | Modified | Add `entitlements` path with Associated Domains key so XcodeGen generates the entitlement |
| `Sources/App/GigHiveApp.swift` | Modified | Add `.onOpenURL { url in }` on `WindowGroup`; add `@StateObject private var guestSession = GuestUploadSession()` and `.environmentObject(guestSession)` alongside existing `session` and `uploadState` |
| `Sources/App/GuestUploadSession.swift` | New | `@MainActor ObservableObject`: `rawToken`, `baseURL`, `eventDetails`, `displayName`, `tosAccepted`; `clear()` resets all fields after successful upload |
| `Sources/App/QRTokenAPIClient.swift` | New | Unauthenticated `GET /api/upload-token` call; attribution record created by the token-auth finalize call — no separate submit endpoint |
| `Sources/App/GuestUploadView.swift` | New | Fan upload screen: event name/date pre-populated from token, ToS checkbox (required), display name field (optional), file picker; no credentials |
| `Sources/App/TUSUploadClient.swift` | Modified | Add `uploadToken: String?` to `init()`; replace the existing `if let basicAuth { ... }` block in `generateHeaders` with `if let uploadToken { mutated["X-Upload-Token"] = uploadToken } else if let basicAuth { ... }` — token auth takes priority; Basic Auth header omitted entirely when a token is present |
| `Sources/App/UploadClient.swift` | Modified | Add `uploadToken: String?` to `init()`; pass `X-Upload-Token` header in finalize `URLRequest`; omit `Authorization: Basic` header when token present |
| `Sources/App/SplashView.swift` | Modified | Add third navigation route: if `guestSession.rawToken != nil`, navigate directly to `GuestUploadView` bypassing `LoginView` |
| `Sources/App/FinalizeResponse.swift` | Extracted | Move `FinalizeResponse` struct out of `UploadView.swift` (currently `private`); make `internal` so both `UploadView` and `GuestUploadView` can decode the finalize response |
| `Sources/App/FinalizeResponseHandler.swift` | Extracted | Extract `handleFinalizeResponse(status:data:host:)` + `extractJSONCandidate` from `UploadView.swift`; shared by both upload views |
| `Sources/App/UploadPayload+GuestUpload.swift` | New | `UploadPayload.forGuestUpload(fileURL:eventDetails:displayName:)` factory; keeps date formatting and 100-char display name trim out of `GuestUploadView` |
| `Sources/App/PHPickerView.swift` | Extracted | Move `PHPickerView` UIViewControllerRepresentable out of `UploadView.swift`; make `internal` for reuse in `GuestUploadView` |
| `Sources/App/DocumentPickerView.swift` | Extracted | Move `DocumentPickerView` UIViewControllerRepresentable out of `UploadView.swift`; make `internal` for reuse in `GuestUploadView` |
| `Sources/App/UploadView.swift` | Modified | Remove `private struct FinalizeResponse`, `private func handleFinalizeResponse`, `private func extractJSONCandidate`, `PHPickerView`, and `DocumentPickerView` definitions (all moved to their own files above); file becomes a pure view with no embedded utilities |

### `api/uploads/finalize.php` — implementation notes

1. **Detect auth mode**: if `X-Upload-Token` header present and non-empty, set `$mode = 'token'`; if `Authorization: Basic` header present, set `$mode = 'basic'`; otherwise return `401`.
2. **Token-mode validation**: compute `$tokenHash = hash('sha256', $rawToken)`; query `SELECT t.token_id, e.event_id, e.event_date, e.org_name, e.event_type FROM event_upload_tokens t JOIN events e ON e.event_id = t.event_id WHERE t.token_hash = ? AND t.is_active = 1 AND t.expires_at > NOW()`. Return `404` if zero rows — do not distinguish not-found from expired/revoked.
2a. **JSON body guard**: `$body = json_decode(file_get_contents('php://input'), true); if (json_last_error() !== JSON_ERROR_NONE || !is_array($body)) { http_response_code(400); exit; }` — must precede any key access.
3. **Request body validation**: extract `label` (required, max 255 chars matching `upload_jobs` column width), `display_name` (optional, strip HTML, max 100 chars), `tos_accepted` (required). Strict type check: `$body['tos_accepted'] === true` — literal JSON `true` only; the string `"true"` or integer `1` must be rejected with `400`. (`$body` is already the decoded array from step 2a — do not call `json_decode()` again.) Return `400` if `label` is absent/empty or `tos_accepted !== true`.
4. **Event context**: use the DB-resolved `event_date`, `org_name`, `event_type` from the token join. Ignore any client-supplied values for these fields.
5. **Atomic write**: after the `upload_jobs` INSERT, run `INSERT INTO anon_upload_attributions (token_id, upload_job_id, display_name, tos_accepted_at) VALUES (?, ?, ?, NOW())` inside the same transaction.
6. **No `hash_equals()` call** — token validation is a DB lookup (`WHERE token_hash = ?` via prepared statement). PHP never compares hash strings directly. See the "Token validation hardening" section in the Security section above.

### `Sources/App/TUSUploadClient.swift` — implementation notes

Targeted change to the `generateHeaders` closure (current file lines 31–38):

1. Add `private let uploadToken: String?` stored property.
2. Add `uploadToken: String? = nil` parameter to `init(tusBaseURL:basicAuth:allowInsecure:chunkSize:)`.
3. Capture `uploadToken` in the closure capture list alongside `basicAuth`: `{ [basicAuth, uploadToken] _, headers, completion in`.
4. In the closure body, replace the `if let basicAuth { ... }` block with: `if let uploadToken { mutated["X-Upload-Token"] = uploadToken } else if let basicAuth { ... }`. Token auth takes priority; `Authorization: Basic` is omitted entirely when a token is present.

### `Sources/App/GigHiveApp.swift` — implementation notes

1. Add `@StateObject private var guestSession = GuestUploadSession()` alongside the existing `session` and `uploadState` properties.
2. Add `.environmentObject(guestSession)` to both the `NavigationStack` branch (iOS 16+) and the `NavigationView` branch (iOS 14–15).
3. Add `.onOpenURL { url in ... }` modifier on the `WindowGroup`. URL path components for `https://host/upload/<token>` decompose as `["/", "upload", "<token>"]`. Guard: `url.pathComponents.count >= 3 && url.pathComponents[1] == "upload"`, then assign `guestSession.rawToken = url.pathComponents[2]`.

---

## Database Migration (Existing Installations)

The two new tables are added to `create_media_db.sql` for fresh installs. For an existing `media_db` already running, execute from the **docker host**. Both `CREATE TABLE IF NOT EXISTS` statements are safe to re-run.

**Rollback (child table first, to satisfy FK):**
```bash
docker exec -i mysqlServer sh -lc 'mysql -h 127.0.0.1 -u root -p"$MYSQL_ROOT_PASSWORD" -D "$MYSQL_DATABASE"' << 'SQL'
DROP TABLE IF EXISTS anon_upload_attributions;
DROP TABLE IF EXISTS event_upload_tokens;
SQL
```

**Apply:**
```bash
docker exec -i mysqlServer sh -lc 'mysql -h 127.0.0.1 -u root -p"$MYSQL_ROOT_PASSWORD" -D "$MYSQL_DATABASE"' << 'MIGRATION'
CREATE TABLE IF NOT EXISTS event_upload_tokens (
  token_id            bigint unsigned NOT NULL AUTO_INCREMENT,
  event_id            int unsigned    NOT NULL,
  token_hash          char(64)        NOT NULL  COMMENT 'SHA-256 hex of the raw token; raw token is never stored',
  expires_at          datetime        NOT NULL,
  is_active           tinyint(1)      NOT NULL DEFAULT 1,
  created_by_user_id  int unsigned    DEFAULT NULL  COMMENT 'user_id of owner who generated the token; NULL pre-step-7 (Basic Auth era)',
  created_at          datetime        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (token_id),
  UNIQUE KEY uq_event_upload_tokens_hash (token_hash),
  KEY idx_event_upload_tokens_event (event_id),
  KEY idx_event_upload_tokens_creator (created_by_user_id),
  CONSTRAINT fk_eut_event FOREIGN KEY (event_id)
    REFERENCES events (event_id) ON DELETE CASCADE
  -- fk_eut_created_by added in step 7: ALTER TABLE event_upload_tokens
  --   ADD CONSTRAINT fk_eut_created_by FOREIGN KEY (created_by_user_id)
  --   REFERENCES users (id) ON DELETE SET NULL;
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS anon_upload_attributions (
  attribution_id  bigint unsigned NOT NULL AUTO_INCREMENT,
  token_id        bigint unsigned NOT NULL,
  upload_job_id   varchar(64)     NOT NULL,
  display_name    varchar(255)    DEFAULT NULL  COMMENT 'Self-reported fan display name',
  tos_accepted_at datetime        NOT NULL      COMMENT 'Timestamp of anonymous ToS acceptance',
  created_at      datetime        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (attribution_id),
  KEY idx_anon_upload_token (token_id),
  KEY idx_anon_upload_job (upload_job_id),
  CONSTRAINT fk_aua_token FOREIGN KEY (token_id)
    REFERENCES event_upload_tokens (token_id) ON DELETE CASCADE,
  CONSTRAINT fk_aua_job FOREIGN KEY (upload_job_id)
    REFERENCES upload_jobs (job_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
MIGRATION
```

---

## Concrete iOS Code Changes

> All diffs are against the snapshot at `/mnt/scottsfiles/gighive/GigHive-iPhone/GigHive` (2026-06-26).

### Inconsistencies and Logic Errors Found During Deep Dive

The following issues were identified by reading the source and are corrected in the change specs below:

| # | Finding | Fix |
|---|---|---|
| 1 | `UploadPayload` has no `displayName` or `tosAccepted` fields — the finalize body cannot carry attribution data for the guest path | Add both fields to `UploadPayload`; `finalizeTusUpload` includes them in the JSON body only when `uploadToken != nil` |
| 2 | `GuestUploadSession` was defined with `rawToken`, `eventDetails`, `displayName`, `tosAccepted` but no `baseURL` — `QRTokenAPIClient` and `UploadClient` both need the server base URL, and `UploaderDeleteTokenStore` is keyed by host | Add `baseURL: URL?` to `GuestUploadSession`; extract from the incoming Universal Link in `onOpenURL` |
| 3 | `UploadClient.uploadWithMultipartInputStream` line 119 has `guard let label … !label.isEmpty else { throw … }` — if `GuestUploadView` does not pre-populate `payload.label`, the upload throws before TUS starts | `GuestUploadView` must set `payload.label = fileURL.lastPathComponent` when building `UploadPayload` |
| 4 | `UploadClient.finalizeTusUpload` always sends `Authorization: Basic` when `basicAuth != nil`; for guest path both `basicAuth` and `uploadToken` could be present if not guarded | `UploadClient` should pass `basicAuth: nil` to `TUSUploadClient` when `uploadToken != nil`, and in `finalizeTusUpload` the `Authorization` block must be guarded `if uploadToken == nil` |
| 5 | `QRTokenAPIClient` description in the iOS app requirements table still mentioned the eliminated `POST /api/upload-token/submit` endpoint | Fixed in the table above |
| 6 | `SplashView` navigation uses `NavigationLink(destination:isActive:)` (iOS 15-compat pattern) — the guest route must follow the same pattern, not a programmatic `NavigationPath` push | Guest `NavigationLink` added with `isActive: $goToGuestUpload`; triggered from both `.onAppear` and `.onChange(of: guestSession.rawToken)` |
| 7 | After a successful guest upload, `UploadView` stores a delete token keyed on `session.baseURL.host` — guest uploads have no `session.baseURL`; they use `guestSession.baseURL` | `GuestUploadView` must use `guestSession.baseURL?.host` as the `UploaderDeleteTokenStore` host key |
| 8 | `GuestUploadSession` has no `@MainActor` — `@Published` mutations from inside `Task { }` run off the main actor and produce "Publishing changes from background threads" warnings/crashes in iOS 17+ | Annotate `GuestUploadSession` with `@MainActor`; mark `clear()` as `@MainActor` |
| 9 | `defer { isUploading = false; uploadProgress = nil }` inside `Task { }` mutates `@State` off the main actor | Replace with `await MainActor.run { isUploading = false; uploadProgress = nil }` inside the task; annotate `doUpload()` as `@MainActor` |
| 10 | `guestSession.eventDetails!` force-unwrap after `guard guestSession.eventDetails != nil` | Replace guard with `guard let eventDetails = guestSession.eventDetails else { return }` and use `eventDetails` directly in `forGuestUpload()` call |
| 11 | `selectedPhotoItem: PhotosPickerItem?` is the `PhotosPicker` SwiftUI API (iOS 16+) — `GuestUploadView` uses `PHPickerView` (UIKit, iOS 14+); the two approaches are mutually exclusive | Remove `selectedPhotoItem` — dead state not used with the `PHPickerView` / sheet-trigger approach |
| 12 | PHP `base64_encode()` produces standard base64 (`+`, `/`, `=`); URL path requires base64url (`-`, `_`, no padding) | Token generation must use: `rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=')` |
| 13 | `json_decode($body, true)` returns `null` on malformed JSON; accessing `['tos_accepted']` on `null` is a PHP 8 `TypeError` | After `json_decode()`, check `json_last_error() === JSON_ERROR_NONE` and `is_array($body)` before accessing keys; return `400` on failure |
| 14 | `UploadTokenValidator::validate()` return type is `?object` — callers cannot statically verify the shape | Return a typed `TokenValidationResult` value object (or `readonly class`) with documented properties; or at minimum add `@return` PHPDoc |
| 15 | `DateFormatter` instantiation in `UploadPayload.forGuestUpload()` is expensive per call | Declare `private static let eventDateFormatter: DateFormatter` on the factory type; reuse it across calls |
| 16 | `QRTokenError.invalidOrExpired(-1)` used for URL construction failure conflates a programming error with an HTTP status | Add `case malformedBaseURL` to `QRTokenError`; throw it when `URLComponents` or `.url` returns nil |

---

### `UploadClient.swift` — `UploadPayload` struct

Add two optional fields. Place them after the existing `notes` field. **These default to nil/false so all existing callsites are unaffected.**

```swift
// After:  var notes: String?
    var displayName: String? = nil
    var tosAccepted: Bool = false
```

---

### `UploadClient.swift` — `UploadClient` class

**1. Add stored property and `init` parameter:**

```swift
// After:  let allowInsecure: Bool
    let uploadToken: String?
```

```swift
// Change init signature from:
init(baseURL: URL, basicAuth: (String,String)? = nil, useBackgroundSession: Bool = false, allowInsecure: Bool = false)
// To:
init(baseURL: URL, basicAuth: (String,String)? = nil, uploadToken: String? = nil, useBackgroundSession: Bool = false, allowInsecure: Bool = false)
```

Add `self.uploadToken = uploadToken` in the init body (before the `if useBackgroundSession` block).

**2. In `uploadWithMultipartInputStream` — pass token to `TUSUploadClient` and guard `basicAuth`:**

```swift
// Change the TUSUploadClient construction from:
let tusClient = try TUSUploadClient(
    tusBaseURL: tusBaseURL,
    basicAuth: basicAuth.map { (user: $0.user, pass: $0.pass) },
    allowInsecure: allowInsecure
)
// To:
let tusClient = try TUSUploadClient(
    tusBaseURL: tusBaseURL,
    basicAuth: uploadToken == nil ? basicAuth.map { (user: $0.user, pass: $0.pass) } : nil,
    uploadToken: uploadToken,
    allowInsecure: allowInsecure
)
```

**3. In `finalizeTusUpload` — add token header, guard Basic Auth, add attribution fields to body:**

```swift
// After:  request.setValue("application/json,text/html;q=0.9", forHTTPHeaderField: "Accept")

        if let uploadToken {
            request.setValue(uploadToken, forHTTPHeaderField: "X-Upload-Token")
        }

        if uploadToken == nil, let auth = basicAuth {
            let credentials = "\(auth.user):\(auth.pass)"
            let encoded = Data(credentials.utf8).base64EncodedString()
            request.setValue("Basic \(encoded)", forHTTPHeaderField: "Authorization")
        }
```

Replace the existing `if let auth = basicAuth { … }` block (currently lines 191–195) with the guarded version above.

Add attribution fields to the JSON body dictionary:

```swift
// After the optional-field if-let blocks (participants, keywords, etc.), add:
        if uploadToken != nil {
            if let displayName = payload.displayName { body["display_name"] = displayName }
            body["tos_accepted"] = payload.tosAccepted
        }
```

---

### `TUSUploadClient.swift` — add `uploadToken` support

**1. Add stored property** (after `private let basicAuth: (user: String, pass: String)?`):

```swift
    private let uploadToken: String?
```

**2. Extend `init` signature** — add `uploadToken: String? = nil` after `basicAuth`:

```swift
init(tusBaseURL: URL, basicAuth: (user: String, pass: String)?, uploadToken: String? = nil, allowInsecure: Bool, chunkSize: Int = 5 * 1024 * 1024) throws {
```

Add `self.uploadToken = uploadToken` immediately after the existing `self.basicAuth = basicAuth` assignment.

**3. Replace the `generateHeaders` closure** (current lines 31–39) — capture `uploadToken` alongside `basicAuth` and make the token branch exclusive:

```swift
        let headersBlock: HeaderGenerationHandler = { [basicAuth, uploadToken] _, headers, completion in
            var mutated = headers
            if let uploadToken {
                mutated["X-Upload-Token"] = uploadToken
            } else if let basicAuth {
                let credentials = "\(basicAuth.user):\(basicAuth.pass)"
                let encoded = Data(credentials.utf8).base64EncodedString()
                mutated["Authorization"] = "Basic \(encoded)"
            }
            completion(mutated)
        }
```

---

### `GuestUploadSession.swift` — new file

Create at `Sources/App/GuestUploadSession.swift`.

```swift
import Foundation
import SwiftUI

struct QREventDetails: Codable {
    let eventDate: String
    let orgName: String
    let eventType: String
    let title: String?
    let tokenId: Int

    enum CodingKeys: String, CodingKey {
        case eventDate = "event_date"
        case orgName = "org_name"
        case eventType = "event_type"
        case title
        case tokenId = "token_id"
    }
}

@MainActor
final class GuestUploadSession: ObservableObject {
    @Published var rawToken: String?
    @Published var baseURL: URL?
    @Published var eventDetails: QREventDetails?
    @Published var displayName: String = ""
    @Published var tosAccepted: Bool = false

    @MainActor
    func clear() {
        rawToken = nil
        baseURL = nil
        eventDetails = nil
        displayName = ""
        tosAccepted = false
    }
}
```

---

### `QRTokenAPIClient.swift` — new file

Create at `Sources/App/QRTokenAPIClient.swift`.

```swift
import Foundation

enum QRTokenError: Error, LocalizedError {
    case invalidOrExpired(Int)
    case malformedBaseURL
    case networkError(Error)

    var errorDescription: String? {
        switch self {
        case .invalidOrExpired: return "This upload link is invalid or has expired."
        case .malformedBaseURL: return "Could not construct the validation URL."
        case .networkError(let e): return e.localizedDescription
        }
    }
}

final class QRTokenAPIClient {
    let baseURL: URL

    init(baseURL: URL) {
        self.baseURL = baseURL
    }

    func validateToken(_ rawToken: String) async throws -> QREventDetails {
        let url = baseURL
            .appendingPathComponent("api")
            .appendingPathComponent("upload-token")
        guard var comps = URLComponents(url: url, resolvingAgainstBaseURL: false) else {
            throw QRTokenError.malformedBaseURL
        }
        comps.queryItems = [URLQueryItem(name: "token", value: rawToken)]
        guard let requestURL = comps.url else {
            throw QRTokenError.malformedBaseURL
        }
        var request = URLRequest(url: requestURL)
        request.httpMethod = "GET"
        request.timeoutInterval = 10
        request.setValue("application/json", forHTTPHeaderField: "Accept")
        let (data, response) = try await URLSession.shared.data(for: request)
        let status = (response as? HTTPURLResponse)?.statusCode ?? -1
        guard status == 200 else {
            throw QRTokenError.invalidOrExpired(status)
        }
        return try JSONDecoder().decode(QREventDetails.self, from: data)
    }
}
```

---

### `GigHiveApp.swift` — three changes

**1. Add `@StateObject` for `guestSession`** alongside existing `session` and `uploadState`:

```swift
    @StateObject private var guestSession = GuestUploadSession()
```

**2. Add `.environmentObject(guestSession)` to both nav branches.** The file already chains `.environmentObject(session)` and `.environmentObject(uploadState)`; add `.environmentObject(guestSession)` immediately after each of those chains.

**3. Add `.onOpenURL` on the `WindowGroup`** (after the closing brace of the `WindowGroup` content block, before the final closing brace of `var body`):

```swift
        .onOpenURL { url in
            guard url.pathComponents.count >= 3,
                  url.pathComponents[1] == "upload",
                  let host = url.host,
                  let scheme = url.scheme,
                  let baseURL = URL(string: "\(scheme)://\(host)") else { return }
            guestSession.baseURL = baseURL  // e.g. https://gighive.app
            guestSession.rawToken = url.pathComponents[2]
        }
```

---

### `SplashView.swift` — add guest upload route

**1. Add environment object** (after the existing `@EnvironmentObject var session: AuthSession` line):

```swift
    @EnvironmentObject var guestSession: GuestUploadSession
```

**2. Add navigation state** (after the existing `@State private var goToUpload = false`):

```swift
    @State private var goToGuestUpload = false
```

**3. Add the hidden `NavigationLink`** inside the `VStack`, directly after the existing `goToUpload` link:

```swift
            NavigationLink(destination: GuestUploadView(), isActive: $goToGuestUpload) { EmptyView() }
                .frame(width: 0, height: 0)
                .hidden()
```

**4. In `.onAppear`** — add a guest-route check after the existing `session.credentials` check:

```swift
            if guestSession.rawToken != nil {
                goToGuestUpload = true
            }
```

**5. Add `.onChange` modifier** — react to a token being set while the app is already open:

```swift
        .onChange(of: guestSession.rawToken) { token in
            if token != nil { goToGuestUpload = true }
        }
```

> **iOS 17+ note:** The single-parameter `.onChange(of:_:)` form is deprecated in iOS 17. Will produce a compiler warning but not a crash. Acceptable for Phase 1a; update to two-parameter form (`.onChange(of:initial:_:)`) before the next iOS SDK bump.

**6. Update the `SplashView_Previews`** to inject a `GuestUploadSession`:

```swift
struct SplashView_Previews: PreviewProvider {
    static var previews: some View {
        SplashView()
            .environmentObject(AuthSession())
            .environmentObject(UploadStateStore())
            .environmentObject(GuestUploadSession())
    }
}
```

---

### `GuestUploadView.swift` — new file

Create at `Sources/App/GuestUploadView.swift`. Key state and logic outline:

```swift
import SwiftUI
// No import PhotosUI needed here — PHPickerView lives in PHPickerView.swift (same module).

struct GuestUploadView: View {
    @EnvironmentObject var guestSession: GuestUploadSession

    @State private var fileURL: URL?
    @State private var isLoadingToken = false
    @State private var tokenError: String?
    @State private var isUploading = false
    @State private var uploadProgress: Double?
    @State private var showResultAlert = false
    @State private var alertTitle = ""
    @State private var alertMessage = ""
    @State private var showPhotosPicker = false
    @State private var showFilesPicker = false
    // Note: do NOT add selectedPhotoItem: PhotosPickerItem? here.
    // GuestUploadView uses PHPickerView (UIViewControllerRepresentable, iOS 14+),
    // not the SwiftUI PhotosPicker API (iOS 16+ only).

    var body: some View {
        // Token validation on appear — shows spinner, then reveals form or error
        // .task {
        //     guard let token = guestSession.rawToken, let base = guestSession.baseURL else { return }
        //     isLoadingToken = true
        //     do {
        //         guestSession.eventDetails = try await QRTokenAPIClient(baseURL: base).validateToken(token)
        //     } catch {
        //         tokenError = error.localizedDescription   // triggers "Open in Safari" error view
        //     }
        //     isLoadingToken = false
        // }
        //
        // If isLoadingToken: show ProgressView
        // If tokenError != nil: show error + "Open in Safari" button
        //   (URL = guestSession.baseURL + "/upload/" + guestSession.rawToken)
        // Otherwise (eventDetails populated):
        //   Pre-populated fields (read-only): orgName, eventDate, title
        //   User-editable: guestSession.displayName (optional, max 100 chars)
        //   guestSession.tosAccepted (required toggle/checkbox)
        //   File picker: PHPickerView / DocumentPickerView (from extracted files)
        //   Upload button: disabled until tosAccepted && fileURL != nil && eventDetails != nil
    }

    private func doUpload() {
        guard let fileURL,
              let baseURL = guestSession.baseURL,
              let rawToken = guestSession.rawToken,
              let eventDetails = guestSession.eventDetails,
              guestSession.tosAccepted else { return }

        // Use the UploadPayload.forGuestUpload() factory (from UploadPayload+GuestUpload.swift)
        // to keep date formatting, display name trimming, and field mapping out of this view.
        let payload = UploadPayload.forGuestUpload(
            fileURL: fileURL,
            eventDetails: eventDetails,      // no force-unwrap needed — bound above
            displayName: guestSession.displayName
        )

        let client = UploadClient(
            baseURL: baseURL,
            basicAuth: nil,
            uploadToken: rawToken,
            useBackgroundSession: false,
            allowInsecure: false   // Universal Links require valid HTTPS
        )

        isUploading = true
        Task { @MainActor in
            defer {
                isUploading = false
                uploadProgress = nil
            }
            do {
                let (status, data, _) = try await client.uploadWithMultipartInputStream(payload, progress: { done, total in
                    guard total > 0 else { return }
                    Task { @MainActor in uploadProgress = Double(done) / Double(total) }
                })
                handleFinalizeResponse(status: status, data: data, host: baseURL.host ?? "")
            } catch {
                alertTitle = "Upload Failed"
                alertMessage = error.localizedDescription
                showResultAlert = true
            }
        }
    }

    private func handleFinalizeResponse(status: Int, data: Data, host: String) {
        switch status {
        case 200, 201:
            alertTitle = "Upload Received"
            alertMessage = "Thank you! Your video has been submitted."
            // Persist delete token if returned
            if let resp = try? JSONDecoder().decode(FinalizeResponse.self, from: data),
               let token = resp.deleteToken, !token.isEmpty, !host.isEmpty {
                let entry = UploadedFileTokenEntry(
                    fileId: resp.id, deleteToken: token, createdAt: Date(),
                    eventDate: resp.eventDate ?? "", orgName: resp.orgName ?? "",
                    eventType: resp.eventType ?? "", label: resp.label,
                    fileName: resp.fileName, fileType: resp.fileType
                )
                try? UploaderDeleteTokenStore.upsert(host: host, entry: entry)
            }
            guestSession.clear()
        case 404:
            alertTitle = "Link Expired"
            alertMessage = "This upload link is no longer valid. Please ask the event organiser for a new QR code."
        case 400:
            alertTitle = "Invalid Request"
            alertMessage = String(data: data, encoding: .utf8) ?? "Bad request"
        default:
            alertTitle = "HTTP \(status)"
            alertMessage = String(data: data, encoding: .utf8) ?? ""
        }
        showResultAlert = true
    }
}
```

**Key notes:**
- `FinalizeResponse` is extracted to `Sources/App/FinalizeResponse.swift` (internal). `handleFinalizeResponse(status:data:host:)` and `extractJSONCandidate` are extracted to `Sources/App/FinalizeResponseHandler.swift`. `GuestUploadView` calls the shared function — the `private func handleFinalizeResponse` shown inline in this sketch is the **pre-extraction reference**; replace it with a call to the shared free function when implementing.
- File picker integration: reuse the existing `PHPickerView` and `DocumentPickerView` found in `UploadView.swift`; they are `UIViewControllerRepresentable` wrappers and can be called from any view.
- The `guestSession.clear()` on success resets all published properties, which via `.onChange` in `SplashView` will **not** re-trigger navigation since `goToGuestUpload` is already true and the view is already on the stack.

---

### `Configs/GigHive.entitlements` — add Associated Domains

Currently the file is an empty `<dict/>`. Replace with:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
    <key>com.apple.developer.associated-domains</key>
    <array>
        <string>applinks:gighive.app</string>
    </array>
</dict>
</plist>
```

Both values are now confirmed — no blocker.

---

### `.well-known/apple-app-site-association` — AASA file (new)

All QR URLs use the apex domain: `https://gighive.app/upload/<token>`. The AASA file is served from `https://gighive.app/.well-known/apple-app-site-association` only — no wildcard vhost needed.

```json
{
  "applinks": {
    "apps": [],
    "details": [
      {
        "appID": "WB7D4FC7XU.com.gighive.GigHive",
        "paths": [ "/upload/*" ]
      }
    ]
  }
}
```

**Apache config** — add to the `gighive.app` apex vhost so the AASA is served without auth:

```apache
<Location /.well-known/apple-app-site-association>
    Satisfy Any
    Allow from all
    Header set Content-Type "application/json"
    Options -Indexes
</Location>
```

Apple's CDN caches the AASA aggressively. After first deploy, verify:
`https://app-site-association.cdn-apple.com/a/v1/gighive.app`

---

### `FinalizeResponse.swift` — extract from `UploadView.swift`

`FinalizeResponse` is currently a `private struct` inside `UploadView.swift`. Extract it to `Sources/App/FinalizeResponse.swift` with `internal` access (Swift default). Remove the `private struct FinalizeResponse` definition from `UploadView.swift`. Both `UploadView` and `GuestUploadView` reference it without any import.

---

### `PHPickerView.swift` / `DocumentPickerView.swift` — extract from `UploadView.swift`

Both `UIViewControllerRepresentable` wrappers are currently defined inside `UploadView.swift`. Extract each to its own file (`Sources/App/PHPickerView.swift`, `Sources/App/DocumentPickerView.swift`) with no access modifier (Swift `internal` default). Remove the originals from `UploadView.swift`. Both `UploadView` and `GuestUploadView` reference them directly.

---

### `UploadPayload+GuestUpload.swift` — new file

Create at `Sources/App/UploadPayload+GuestUpload.swift`. Keeps date formatting, display name trimming, and field defaults out of `GuestUploadView`.

```swift
import Foundation

extension UploadPayload {
    /// Shared static formatter — DateFormatter allocation is expensive; reuse across calls.
    private static let eventDateFormatter: DateFormatter = {
        let df = DateFormatter()
        df.dateFormat = "yyyy-MM-dd"
        df.locale = Locale(identifier: "en_US_POSIX")
        return df
    }()

    static func forGuestUpload(
        fileURL: URL,
        eventDetails: QREventDetails,
        displayName: String
    ) -> UploadPayload {
        let eventDate = eventDateFormatter.date(from: eventDetails.eventDate) ?? Date()
        let trimmedName = displayName.trimmingCharacters(in: .whitespacesAndNewlines)
        return UploadPayload(
            fileURL: fileURL,
            eventDate: eventDate,
            orgName: eventDetails.orgName,
            eventType: eventDetails.eventType,
            label: fileURL.lastPathComponent,
            displayName: trimmedName.isEmpty ? nil : String(trimmedName.prefix(100)),
            tosAccepted: true
        )
    }
}
```

---

### `src/Services/UploadTokenValidator.php` — new file

Create at `src/Services/UploadTokenValidator.php`. All three token-consuming endpoints (`upload_form_single.php`, `api/upload-token.php`, `api/uploads/finalize.php`) call this class to prevent validation logic from drifting.

```php
<?php
declare(strict_types=1);

namespace Production\Api\Services;

/**
 * Typed result returned by UploadTokenValidator::validate().
 */
readonly class TokenValidationResult
{
    public function __construct(
        public int    $tokenId,
        public int    $eventId,
        public string $eventDate,
        public string $orgName,
        public string $eventType,
    ) {}
}

class UploadTokenValidator
{
    public function __construct(private \PDO $pdo) {}

    /**
     * Validate a raw CSPRNG token from an untrusted request.
     *
     * Returns a TokenValidationResult on success, or null if the token is
     * not found, expired, or revoked. Callers must treat null as a 404 and
     * must NOT distinguish between the three failure reasons.
     */
    public function validate(string $rawToken): ?TokenValidationResult
    {
        // Hash the presented token — the raw token is never stored.
        $tokenHash = hash('sha256', $rawToken);

        $stmt = $this->pdo->prepare(
            'SELECT t.token_id, e.event_id, e.event_date, e.org_name, e.event_type
               FROM event_upload_tokens t
               JOIN events e ON e.event_id = t.event_id
              WHERE t.token_hash = ?
                AND t.is_active = 1
                AND t.expires_at > NOW()'
        );
        $stmt->execute([$tokenHash]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($row === false) {
            return null;
        }

        return new TokenValidationResult(
            tokenId:   (int) $row['token_id'],
            eventId:   (int) $row['event_id'],
            eventDate: $row['event_date'],
            orgName:   $row['org_name'],
            eventType: $row['event_type'],
        );
    }
}
```

**Notes:**
- `readonly class` is valid — deployment target is PHP-FPM 8.3.
- No `hash_equals()` call — the hash is a DB lookup key, not a string compared in PHP. See "Token validation hardening" in the Security section.
- Callers must map `null` return to `http_response_code(404); exit;` without logging which failure condition triggered it.

---

### Changes NOT required

- **`AuthSession.swift`** — no change; guest path uses `GuestUploadSession` exclusively
- **`SettingsStore.swift`** — no change; it is unused by the main app flow (credentials come from `@AppStorage` / `KeychainStore`) and guest uploads do not need stored settings
- **`NetworkProgressUploadClient.swift`** — no change; it is dead code in the current main flow (TUS path replaces it) and is not used by guest uploads
- **`ShareViewController.swift`** — no change; Share Extension is a separate extension target and the QR flow is initiated from the main app only
- **`project.yml` `packages` / `dependencies`** — no change; `TUSKit` is already a dependency of the main target

---

## Test Matrix

> Minimum verification before shipping Phase 1a. Run against dev environment (`gighive2`).

### Token Validation (`UploadTokenValidator`)

| Case | Expected |
|---|---|
| Valid token, active, not expired | Returns `TokenValidationResult` with correct `event_id` |
| Token hash not in DB | Returns `null` |
| Token `is_active = 0` (revoked) | Returns `null` |
| Token `expires_at` in the past | Returns `null` |
| Empty string / malformed input | Returns `null` (no exception) |

### `GET /api/upload-token`

| Case | Expected |
|---|---|
| Valid raw token | `200` + JSON with `event_date`, `org_name`, `event_type`, `title`, `token_id` |
| Expired or revoked token | `404` — no detail distinguishing reason |
| Missing `?token=` param | `400` |

### `POST /api/uploads/finalize` — X-Upload-Token path

| Case | Expected |
|---|---|
| Valid token + valid TUS `upload_id` + `tos_accepted: true` | `200/201`; `anon_upload_attributions` row created; `upload_jobs` row updated |
| Valid token + `tos_accepted: false` | `400` |
| Valid token + `tos_accepted` missing | `400` |
| Valid token + `label` missing | `400` |
| Expired or revoked token | `404` |
| Valid token + duplicate SHA-256 | `409` |
| No `X-Upload-Token` + no `Authorization` header | `401` |
| Both `X-Upload-Token` + `Authorization` present | Token path takes precedence; `401` not triggered |

### `db/upload_form_single.php` — QR Token Mode (Web)

| Case | Expected |
|---|---|
| Valid token in URL (`/upload/<token>`) | Form renders with read-only event fields, ToS checkbox, display name input |
| Expired/revoked/invalid token | Error page rendered; no upload form shown |
| Fan completes upload | TUS upload succeeds; finalize creates `anon_upload_attributions` row; success message shown |
| Fan submits without checking ToS | Client-side block; form not submitted |

### Admin Sections (`upload_form_single.php`)

| Case | Expected |
|---|---|
| Admin visits `?event_id=X` | QR generator, revocation list, fan upload list all visible |
| Admin visits `?event_id=` nonexistent | Graceful 404 or error; no PHP warning |
| Admin generates QR code | Token row inserted in `event_upload_tokens`; QR renders on canvas; PNG download works |
| Admin revokes a token | `is_active` set to `0`; token no longer validates |
| Fan upload list | Rows from `anon_upload_attributions` displayed; revoked-token rows flagged |

### iOS — Universal Link + Guest Upload Flow

| Case | Expected |
|---|---|
| Tap QR link with app installed | App opens; `guestSession.rawToken` set; navigates to `GuestUploadView` |
| `GuestUploadView` loads with valid token | Spinner → event info displayed; form enabled |
| `GuestUploadView` loads with invalid/expired token | Error state shown; "Open in Safari" button reconstructs correct URL |
| Fan uploads successfully | Progress shown; on 200/201 response, `guestSession.clear()` called; success confirmation shown |
| Fan taps Dismiss after success | Navigation pops; `SplashView` shown without re-triggering guest navigation |
| App receives second QR scan while `GuestUploadView` on stack | `rawToken` updated; view re-validates new token |

