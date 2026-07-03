# Shared Gallery — Implementation Plan

**High-level spec:** `docs/feature_iphone_qr_code_shared_gallery.md`
**Related docs:** `docs/feature_iphone_qr_code_support.md` · `docs/feature_iphone_qr_code_implementation.md`

This plan is split into two sequential **coding phases**. Complete Phase 1 and deploy to dev/staging before beginning Phase 2.

> ⚠️ **Phase naming note:** the spec (`feature_iphone_qr_code_shared_gallery.md` § "Release Gating") also uses Phase 1/2/3 to describe *feature release gates* (MVP → beta promotion → scale). Those are distinct from the coding phases here. Coding Phase 1 + 2 together correspond to the spec's Release Gate Phase 1 (MVP launch).

### Phase 1 — Infrastructure / Ansible / PHP
- **Step 1** — Ansible: add `QR_GALLERY_DEFAULT_LIFESPAN_DAYS` env var to all group_vars and `.env.j2`
- **Step 2** — Apache: update `default-ssl.conf.j2` (three guest API endpoints exempted from Basic Auth; `/video/` opened to gallery-nonce authenticated requests)
- **Step 3** — Schema: update `create_music_db.sql` with all 9 new columns across `anon_upload_attributions`, `events`, and `upload_jobs`; DDL applied to existing installs manually (see Database Migration section)
- **Step 4** — PHP: expand `UploadService::finalizeTusUpload` token-mode path (status_nonce, label, file_relpath, moderation_status, lastInsertId ordering, updated response)
- **Step 5** — PHP: add `save_event_settings` POST handler to `admin/event_qr.php` (gallery lifespan + multi-day flag)
- **Step 6** — PHP: add TTL vs. gallery lifespan mismatch warning to QR Generator card in `admin/event_qr.php`
- **Step 7** — PHP: expand `$guestUploads` SELECT query in `admin/event_qr.php` (moderation_status, file_relpath, label, guest_flagged)
- **Step 8** — PHP: add approve/reject POST handlers to `admin/event_qr.php` (multi-table UPDATE with cross-validation; sets approved_at)
- **Step 9** — PHP: add moderation queue UI to `admin/event_qr.php` (new badge CSS, ▶ Preview link, ⚑ Guest report badge, Approve/Reject buttons)
- **Step 10** — PHP: create `/api/guest-status.php` (nonce → pending/approved/rejected/expired + video_count + days_remaining)
- **Step 11** — PHP: create `/api/guest-gallery.php` (approved video list with stream_url; 403 for unapproved nonces)
- **Step 12** — PHP: create `/api/guest-report.php` (two-step validation; flag target video; idempotent)
- **Step 13** — PHP: create `guest_event_view.php` web fallback (shared query logic, `<video>` elements, `?nonce=` appended to each `stream_url`)
- **Step 14** — PHP: add honor system warning + post-upload message to `db/upload_form_single.php`; make display_name required
- **Step 15** — Ansible: add smoke tests for all new endpoints

### Phase 2 — iPhone / Mac
- **Step 1** — Swift: create `GuestUploadRecord` struct with UserDefaults persistence helpers
- **Step 2** — Swift: update `GuestUploadView` / `GuestUploadSession` finalize handling (parse nonce + upload_job_id, persist record, show post-upload message; display_name required)
- **Step 3** — Swift: implement status polling on app open (concurrent per-record polls, update local records, show approval banner, "New videos added" detection)
- **Step 4** — Swift: add approval banner + "Your Event Galleries" persistent section to `SplashView`
- **Step 5** — Swift: create `GuestGalleryView.swift` (fetch gallery, AVPlayer playback, report flow, expired/empty states, days_remaining subtitle)
- **Step 6** — iOS testing checklist (DB verification, admin approve/reject flow, report flag, expiry, web fallback, regression)

---

## Phase 1 — Infrastructure / Ansible / PHP

### Step 1 — Ansible: add `QR_GALLERY_DEFAULT_LIFESPAN_DAYS` env var

**Files:** `ansible/group_vars/gighive/vars.yml`, `ansible/group_vars/gighive2/vars.yml`, `ansible/group_vars/prod/vars.yml`, `ansible/roles/docker/templates/.env.j2`

- Add `qr_gallery_default_lifespan_days: 90` to all three group_vars files
- Add `QR_GALLERY_DEFAULT_LIFESPAN_DAYS={{ qr_gallery_default_lifespan_days }}` to `.env.j2`
- This value pre-populates the gallery lifespan field in `admin/event_qr.php` and is used by the `save_event_settings` handler as the default

---

### Step 2 — Apache: update `default-ssl.conf.j2`

**File:** `ansible/roles/docker/templates/default-ssl.conf.j2`

Two types of changes:

**A. Three guest API auth exemptions** — all three new endpoints fall under the `/api/` catch-all `LocationMatch` (requires `valid-user` Basic Auth); each needs `AuthMerging Off` + `Require all granted`, following the same pattern as `/api/upload-token.php`:

```apache
# --- GUEST GALLERY APIs: unauthenticated by design; PHP validates the nonce ---
<Location "/api/guest-status.php">
    AuthMerging Off
    Require all granted
</Location>

<Location "/api/guest-gallery.php">
    AuthMerging Off
    Require all granted
</Location>

<Location "/api/guest-report.php">
    AuthMerging Off
    Require all granted
</Location>
```

**B. `/video/` gallery-nonce gate** — guest uploads are **video only**; `/audio/` is unchanged. Add two `SetEnvIf` lines near the existing `SetEnvIf X-Upload-Token` line:

```apache
# iOS AVPlayer sends X-Gallery-Nonce header via AVURLAsset HTTPHeaderFields
SetEnvIf X-Gallery-Nonce .+ gallery_nonce_auth
# Web browser <video src="/video/...?nonce=..."> — query-string detection
SetEnvIf Request_URI "[?&]nonce=[A-Za-z0-9_\-]{30,40}" gallery_nonce_auth
```

Replace the existing `<LocationMatch "^/video(?:/|$)">` staging-only conditional block with:

```apache
# --- VIDEO DIRECTORY: Basic Auth for all roles; gallery nonce for approved guests ---
# Apache is a forward gate only; nonce validity was established in guest-gallery.php
# or guest_event_view.php before stream_url was issued. SHA-256 filenames provide a
# second layer — unguessable without possessing the original file.
<LocationMatch "^/video(?:/|$)">
    AuthMerging Off
    AuthType Basic
    AuthName "GigHive Protected"
    AuthBasicProvider file
    AuthUserFile {{ gighive_htpasswd_path | default('/etc/apache2/gighive.htpasswd') }}
    <RequireAny>
        Require valid-user
        Require env gallery_nonce_auth
    </RequireAny>
</LocationMatch>
```

The staging-only exception is superseded — all environments share the same `RequireAny` rule.

**`/video/podcasts/` note:** the outer catch-all `LocationMatch` (line 173 of the conf) already excludes `/video/podcasts/` from its Basic Auth block. However, this inner `^/video(?:/|$)` block still covers `/video/podcasts/` — meaning podcast files will now be accessible to gallery nonce holders. This is acceptable in practice (podcast filenames are not publicly enumerable), but if stricter isolation is required, change the pattern to `^/video/(?!podcasts(?:/|$))` and add a separate public `<Location "/video/podcasts/">` block.

**`guest_event_view.php` requires no exemption:** this file lives at the webroot root, not under `/api/` or `/db/`. The main Basic Auth `LocationMatch` at line 173 only covers specific subdirectory paths (`api`, `db/...`, `video`, etc.) and does not match root-level `.php` files. No `AuthMerging Off` block is needed for `guest_event_view.php`.

---

### Step 3 — Schema: update DDL source file

**File:** `ansible/roles/docker/files/mysql/externalConfigs/create_music_db.sql` — add the nine new columns so fresh installs include them automatically. For existing installs, the DDL is applied manually via the docker command in the Database Migration section below.

> ⚠️ **Rename dependency:** `refactored_database_rename_music_db.md` renames `create_music_db.sql` → `create_media_db.sql` (status: not started). If that refactor ships first, the target file here is `create_media_db.sql`. Confirm which feature ships first before editing.

Run in this order — `moderation_status AFTER label` requires `label` to exist first:

```sql
-- 1. anon_upload_attributions
ALTER TABLE anon_upload_attributions
  ADD COLUMN status_nonce VARCHAR(40)  NOT NULL AFTER tos_accepted_at,
  ADD COLUMN apns_token   VARCHAR(200) NULL     AFTER status_nonce,
  ADD UNIQUE KEY uq_status_nonce (status_nonce);

-- 2. events
ALTER TABLE events
  ADD COLUMN gallery_expires_at DATETIME   NULL      AFTER event_type,
  ADD COLUMN is_multi_day       TINYINT(1) NOT NULL DEFAULT 0 AFTER gallery_expires_at;

-- 3. upload_jobs — two statements: label/file_relpath first, then columns that reference AFTER label
ALTER TABLE upload_jobs
  ADD COLUMN label        VARCHAR(255) NULL AFTER completed_at,
  ADD COLUMN file_relpath VARCHAR(512) NULL AFTER label;
  -- file_relpath rationale: all TUS-ingested files are stored as {sha256}.{ext} on disk
  -- (UploadService line 109: "Stored filename is always {sha256}.{ext}"). handleUpload() returns
  -- the stored name as 'file_name'; file_relpath is constructed as file_type/file_name at finalize
  -- time. The path is not reconstructable from other DB columns after the fact. It is used in two places:
  --   1. Admin moderation queue preview link (Step 9): href="/<file_relpath>"
  --   2. Gallery API stream_url (Step 11): '/' . $row['file_relpath'] returned to iOS app and web fallback

ALTER TABLE upload_jobs
  ADD COLUMN moderation_status ENUM('pending','approved','rejected') NULL DEFAULT NULL AFTER file_relpath,
  ADD COLUMN approved_at       DATETIME   NULL                            AFTER moderation_status,
  ADD COLUMN guest_flagged     TINYINT(1) NOT NULL DEFAULT 0              AFTER approved_at,
  ADD COLUMN guest_flagged_at  DATETIME   NULL                            AFTER guest_flagged;

-- 4. Index for moderation queue lookups
ALTER TABLE upload_jobs
  ADD INDEX idx_upload_jobs_moderation (moderation_status);
```

**Verification:** after running, `DESCRIBE upload_jobs` and `DESCRIBE events` must show all new columns.

---

### Step 4 — PHP: `UploadService::finalizeTusUpload` — expand token-mode path

**File:** `src/Services/UploadService.php`

In the `if ($tokenResult !== null)` block, after `handleUpload` returns `$result`:

1. Generate `status_nonce`:
   ```php
   $statusNonce = rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
   ```
2. Compute `file_relpath`:
   ```php
   $fileRelpath = $result['file_type'] . '/' . $result['file_name'];
   ```
   `handleUpload()` always returns `'file_name'` as `{sha256}.{ext}` (see `UploadService.php` line 109 comment: *"Stored filename is always {sha256}.{ext}"*). `source_relpath` is a DB-only provenance column written to the `assets` table; it is **not** returned in the `$result` array.
3. Expand the `upload_jobs` INSERT to include `label`, `file_relpath`, `moderation_status`:
   ```sql
   INSERT INTO upload_jobs
     (tenant_id, job_id, job_type, status, total_files, started_at,
      label, file_relpath, moderation_status)
   VALUES (?, ?, 'qr_guest_upload', 'completed', 1, NOW(), ?, ?, 'pending')
   -- bind: $tokenResult['tenant_id'], $jobId (TUS UUID VARCHAR), $label, $fileRelpath
   ```
   Do **not** hardcode `tenant_id = 1`; use the tenant from `$tokenResult`.
4. **Call `lastInsertId()` immediately** after this INSERT and store as `$uploadJobsRowId` — before the attribution INSERT.
5. Expand `anon_upload_attributions` INSERT to include `status_nonce`:
   ```sql
   INSERT INTO anon_upload_attributions
     (token_id, upload_job_id, display_name, tos_accepted_at, status_nonce)
   VALUES (?, ?, ?, NOW(), ?)
   -- bind: $tokenId, $jobId (VARCHAR TUS UUID — NOT $uploadJobsRowId), $displayName, $statusNonce
   ```
   The second `?` is `$jobId` (the VARCHAR TUS UUID) — the FK references `upload_jobs.job_id`. `$uploadJobsRowId` is the INT returned in the API response only.
6. Expand finalize JSON response to include both new fields:
   ```php
   return ['status_nonce' => $statusNonce, 'upload_job_id' => $uploadJobsRowId, ...];
   ```
   `upload_job_id` is `upload_jobs.id` (INT auto-increment), NOT `job_id` (TUS UUID VARCHAR).

---

### Step 5 — PHP: `admin/event_qr.php` — `save_event_settings` POST handler

**File:** `ansible/roles/docker/files/apache/webroot/admin/event_qr.php`

Add a new POST action `save_event_settings` (alongside existing `revoke` action):

- Inputs: `event_id` (hidden field), `gallery_lifespan_days` (int), `is_multi_day` (checkbox → 0/1)
- Validate `gallery_lifespan_days` is a non-negative integer; 0 means indefinite (`gallery_expires_at = NULL`)
- Compute `gallery_expires_at` **inside the SQL expression**, not in PHP — use `DATE_ADD(event_date, INTERVAL ? DAY)` so the anchor is always `events.event_date`, not `NOW()`:
  ```sql
  UPDATE events
  SET gallery_expires_at = CASE WHEN ? > 0 THEN DATE_ADD(event_date, INTERVAL ? DAY) ELSE NULL END,
      is_multi_day = ?
  WHERE event_id = ? AND tenant_id = ?
  -- bind: $n, $n, $isMultiDay, $eventId, $tenantId  (bind $n twice — PDO requires each ? bound separately)
  ```
  Do **not** compute `gallery_expires_at` in PHP (e.g. `new DateTime($eventDate)->modify("+$n days")`). PHP does not have `event_date` in scope from the POST body (only `event_id` and `gallery_lifespan_days` are submitted); the SQL expression avoids an extra SELECT and eliminates any risk of anchoring to `NOW()` instead of `event_date`.
- Flash success/error message via `$_SESSION`
- The Event Selector card must have a **separate `<form method="POST">`** for these settings (distinct from the `<form method="GET">` Load Event form)
- Pre-populate `gallery_lifespan_days`:
  - **No event loaded:** `(int)(getenv('QR_GALLERY_DEFAULT_LIFESPAN_DAYS') ?: 90)` — `getenv()` returns `false` when absent; the cast with fallback prevents a PHP 8 `TypeError` (SonarQube RSPEC-3516)
  - **Event loaded:** back-compute from the loaded event row as `DATEDIFF(gallery_expires_at, event_date)` — both columns are present on the row already fetched for the page; `NULL` `gallery_expires_at` → 0 (indefinite)

---

### Step 6 — PHP: `admin/event_qr.php` — TTL mismatch warning

**File:** `ansible/roles/docker/files/apache/webroot/admin/event_qr.php`

In the QR Generator card, after the TTL radio buttons are rendered:

- Skip this check entirely if `gallery_expires_at IS NULL` (indefinite lifespan — no mismatch is possible)
- Otherwise compare: if the upload window end (`NOW() + TTL seconds`) > `gallery_expires_at`, render an inline amber warning:
  *"Upload window extends beyond gallery expiry — guests who upload near the end of the window may be approved after the gallery has already closed."*
- Warning is informational only; do not block form submission

---

### Step 7 — PHP: `admin/event_qr.php` — update `$guestUploads` query

**File:** `ansible/roles/docker/files/apache/webroot/admin/event_qr.php`

Extend the existing `$guestUploads` SELECT to include:

```sql
SELECT a.display_name, a.tos_accepted_at, a.created_at,
       j.job_id, j.id AS upload_job_row_id,
       j.label, j.file_relpath,
       j.moderation_status, j.approved_at,
       j.guest_flagged, j.guest_flagged_at,
       t.is_active AS token_active, t.expires_at
FROM anon_upload_attributions a
JOIN event_upload_tokens t ON t.token_id = a.token_id
JOIN upload_jobs j ON j.job_id = a.upload_job_id
WHERE t.event_id = ?
ORDER BY a.created_at DESC
```

---

### Step 8 — PHP: `admin/event_qr.php` — approve/reject POST handlers

**File:** `ansible/roles/docker/files/apache/webroot/admin/event_qr.php`

Add `approve_upload` and `reject_upload` actions. Cross-validation UPDATE (upload_jobs has no event_id — must JOIN through attributions → tokens):

```sql
UPDATE upload_jobs j
JOIN anon_upload_attributions a ON a.upload_job_id = j.job_id
JOIN event_upload_tokens t ON t.token_id = a.token_id
SET j.moderation_status = ?,          -- 'approved' or 'rejected'
    j.approved_at = CASE WHEN ? = 'approved' THEN NOW() ELSE NULL END
WHERE j.job_id = ? AND t.event_id = ?
```

- `job_id` (VARCHAR TUS UUID) is used here (not the INT `id`) because it's what's available from the form row
- **Bind `$moderationStatus` twice** — once for `SET j.moderation_status = ?` and once for `CASE WHEN ? = 'approved'`; PDO/mysqli requires the value bound to each `?` position separately
- Flash success/error message; redirect back to same page (POST–Redirect–GET)

---

### Step 9 — PHP: `admin/event_qr.php` — moderation queue UI

**File:** `ansible/roles/docker/files/apache/webroot/admin/event_qr.php`

Extend the existing Guest Uploads table HTML:

1. Add two new badge CSS classes (inline `<style>` block at top of file alongside existing badges):
   - `.badge-mod-pending` — amber background
   - `.badge-mod-approved` — green background
   - `.badge-mod-rejected` — reuse existing `.badge-revoked` (red); no new class needed
2. For each row, add a **Moderation** column showing the badge; if `guest_flagged = 1` append **⚑ Guest report** in amber text
3. Add a **Preview** column: check `$gu['file_relpath'] !== null` first — if null output `—`; otherwise render `<a href="/<?= htmlspecialchars($gu['file_relpath'], ENT_QUOTES) ?>" target="_blank">▶ Preview</a>`. Do **not** pass null directly to `htmlspecialchars` — PHP 8.1+ raises a deprecation and silently outputs `href="/"` (a link to the webroot)
4. Add **[Approve]** / **[Reject]** buttons only on `pending` rows (POST forms with `action=approve_upload` / `action=reject_upload`); already-decided rows show `—`

---

### Step 10 — PHP: new file `/api/guest-status.php`

**File:** `ansible/roles/docker/files/apache/webroot/api/guest-status.php`

- Method: GET; parameter: `?nonce=`
- No admin session required; nonce is the only credential
- **First two lines:** `header('Cache-Control: no-store');` and `header('Content-Type: application/json');` — no-store prevents Cloudflare caching; explicit content-type prevents browsers treating JSON as HTML (SonarQube Security Hotspot)
- **Validate nonce before DB use:** `preg_match('/^[A-Za-z0-9_\-]{30,40}$/', $_GET['nonce'] ?? '')` — SonarQube tracks `$_GET` as tainted input (RSPEC-2076); explicit format check resolves the hotspot even when prepared statements are in use; return `400` on mismatch
- **Wrap all DB operations in `try { … } catch (PDOException $e) { http_response_code(500); exit; }`** — uncaught PDOException exposes stack traces and is flagged as RSPEC-2225
- Query:
  ```sql
  SELECT j.moderation_status,
         e.event_date, e.org_name, e.gallery_expires_at,
         (SELECT COUNT(*) FROM upload_jobs j2
          JOIN anon_upload_attributions a2 ON a2.upload_job_id = j2.job_id
          JOIN event_upload_tokens t2 ON t2.token_id = a2.token_id
          WHERE t2.event_id = t.event_id AND j2.moderation_status = 'approved'
         ) AS video_count
  FROM anon_upload_attributions a
  JOIN upload_jobs j ON j.job_id = a.upload_job_id
  JOIN event_upload_tokens t ON t.token_id = a.token_id
  JOIN events e ON e.event_id = t.event_id
  WHERE a.status_nonce = ?
  ```
- Logic:
  - Nonce not found → `404`
  - `gallery_expires_at` not NULL and is in the past → override status to `"expired"`
  - Otherwise return `j.moderation_status` as status
- Response:
  ```json
  { "status": "pending|approved|rejected|expired",
    "event_name": "StormPigs — 2026-07-17",
    "video_count": 12,
    "days_remaining": 87 }
  ```
  `days_remaining`: integer days until `gallery_expires_at`; `null` if indefinite; `0` if expired

---

### Step 11 — PHP: new file `/api/guest-gallery.php`

**File:** `ansible/roles/docker/files/apache/webroot/api/guest-gallery.php`

- Method: GET; parameter: `?nonce=`
- No admin session required
- **First two lines:** `header('Cache-Control: no-store');` and `header('Content-Type: application/json');` — same rationale as `guest-status.php`
- **Validate nonce before DB use:** same `preg_match` pattern as `guest-status.php`; return `400` on mismatch
- **Wrap all DB operations in try-catch** — same pattern as `guest-status.php`
- Step 1 — verify nonce's own upload is approved and gallery is not expired:
  ```sql
  SELECT t.event_id, e.gallery_expires_at
  FROM anon_upload_attributions a
  JOIN upload_jobs j ON j.job_id = a.upload_job_id
  JOIN event_upload_tokens t ON t.token_id = a.token_id
  JOIN events e ON e.event_id = t.event_id
  WHERE a.status_nonce = ? AND j.moderation_status = 'approved'
  ```
  Nonce not found or not approved → `403`. Gallery expired → return `{ status: "expired", videos: [] }`.
- Step 2 — fetch all approved videos for the event:
  ```sql
  SELECT j.id AS upload_job_id, j.label, j.file_relpath, j.approved_at,
         a.display_name
  FROM upload_jobs j
  JOIN anon_upload_attributions a ON a.upload_job_id = j.job_id
  JOIN event_upload_tokens t ON t.token_id = a.token_id
  WHERE t.event_id = ? AND j.moderation_status = 'approved'
  ORDER BY j.started_at ASC  -- chronological capture order; see spec § GuestGalleryView
  ```
- Construct `stream_url = '/' . $row['file_relpath']` for each video
- Response:
  ```json
  { "status": "approved",
    "days_remaining": 87,
    "videos": [{ "upload_job_id": 1, "label": "my clip", "stream_url": "/video/a3f9bc2d4e1f8c7b5d0e2f9a6c3b4d5e6f7a8b9c0d1e2f3a4b5c6d7e8f9a0b1.mp4",
                 "display_name": "Scott's iPhone", "approved_at": "2026-07-18T10:00:00Z" }] }
  ```
  `status` is always `"approved"` when this endpoint returns `200`. `approved_empty` is not a reachable state here — if Step 1 passes (nonce's own upload is approved), the nonce holder's own video is always included in Step 2's result, so `videos` always contains ≥ 1 entry. The client (`GuestGalleryView`) should still handle `videos: []` defensively as a no-op empty state, but no special status string is needed.

---

### Step 12 — PHP: new file `/api/guest-report.php`

**File:** `ansible/roles/docker/files/apache/webroot/api/guest-report.php`

- Method: POST; JSON body: `{ "nonce": "...", "upload_job_id": 42 }` (INT `upload_jobs.id`)
- No admin session required
- **First two lines:** `header('Cache-Control: no-store');` and `header('Content-Type: application/json');` — POST responses are not typically cached, but `no-store` is added for consistency with the other guest endpoints and to ensure no intermediary caches a nonce-bound response
- **Validate inputs from JSON body before DB use:** `preg_match('/^[A-Za-z0-9_\-]{30,40}$/', $body->nonce ?? '')` for the nonce; `filter_var($body->upload_job_id ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])` for the integer ID — return `400` on either failure (RSPEC-2076)
- **Wrap all DB operations in try-catch** — same pattern as other endpoints (RSPEC-2225)
- Step 1 — verify nonce is an approved contributor and get `event_id`:
  ```sql
  SELECT t.event_id
  FROM anon_upload_attributions a
  JOIN upload_jobs j_mine ON j_mine.job_id = a.upload_job_id
  JOIN event_upload_tokens t ON t.token_id = a.token_id
  WHERE a.status_nonce = ? AND j_mine.moderation_status = 'approved'
  ```
  No row → `403 { "error": "Forbidden" }`
- Step 2 — flag the target video (approved, same event):
  ```sql
  UPDATE upload_jobs j_target
  JOIN anon_upload_attributions a2 ON a2.upload_job_id = j_target.job_id
  JOIN event_upload_tokens t2 ON t2.token_id = a2.token_id
  SET j_target.guest_flagged = 1, j_target.guest_flagged_at = NOW()
  WHERE j_target.id = ? AND j_target.moderation_status = 'approved'
    AND t2.event_id = ?
  ```
  0 rows affected → `403 { "error": "Forbidden" }`
- Success: `200 { "success": true }` — idempotent; `guest_flagged_at` updates on repeat calls

---

### Step 13 — PHP: new file `guest_event_view.php` (web fallback)

**File:** `ansible/roles/docker/files/apache/webroot/guest_event_view.php`

- Parameter: `?nonce=` from `$_GET`
- No admin session required
- **First line:** `header('Cache-Control: no-store');` — page content is nonce-specific and must never be cached by Cloudflare
- Share gallery data retrieval logic with `/api/guest-gallery.php` via a common `include` (e.g. `src/Helpers/GuestGalleryHelper.php`) to avoid duplication
- Render approved videos as `<video controls>` elements; construct each `src` as `'/' . htmlspecialchars($row['file_relpath'], ENT_QUOTES) . '?nonce=' . urlencode($nonce)` to pass through Apache's gallery-nonce gate (see Phase 1 Step 2)
- If nonce invalid/not approved → render a plain HTML 403 error page
- If `videos` result is empty (defensive check — this state is not reachable via normal flow since the nonce holder's own approved video is always in the result; see Step 11) → render "No videos have been approved yet — check back soon" message
- If expired → render "This gallery is no longer available" message
- Report button: HTML `<form method="POST" action="/guest_event_view.php">` with hidden fields `action=report`, `nonce`, and `upload_job_id`. Handle `action=report` at the top of `guest_event_view.php` itself: call the shared flagging logic (same helper as `/api/guest-report.php`), then `header('Location: /guest_event_view.php?nonce=...')` and exit (POST–Redirect–GET pattern). Do **not** POST directly to `/api/guest-report.php` — that endpoint returns raw JSON, which the browser would display as-is instead of showing a flash message
- Styles: minimal inline CSS; no admin portal chrome

---

### Step 14 — PHP: `db/upload_form_single.php` — honor system warning + post-upload message

**File:** `ansible/roles/docker/files/apache/webroot/db/upload_form_single.php`

- Add honor system warning text before the file picker (safety framework item 1)
- After successful upload, display the post-upload message (see spec §"UX message shown immediately after upload submission")
- `display_name` field: make required (safety framework item 5); auto-populate from `User-Agent` device hint if possible on web; HTML-strip server-side (already done)

---

### Step 15 — Ansible: smoke tests for all new endpoints

**File:** `ansible/roles/shared_gallery/tasks/main.yml` (new dedicated role — do **not** add shared gallery smoke tests to the existing `qr_code` role)

Add smoke tests (tags: `shared_gallery,smoke,api`):

```yaml
- name: guest-status — missing nonce returns 400
  ansible.builtin.uri:
    url: "{{ gighive_base_url }}/api/guest-status.php"
    method: GET
    validate_certs: "{{ gighive_validate_certs }}"
    headers: "{{ _qr_host_header }}"
    status_code: 400
  changed_when: false
  tags: [shared_gallery, smoke, api]

- name: guest-status — unknown nonce returns 404
  ansible.builtin.uri:
    url: "{{ gighive_base_url }}/api/guest-status.php?nonce=AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA"
    method: GET
    validate_certs: "{{ gighive_validate_certs }}"
    headers: "{{ _qr_host_header }}"
    status_code: 404
  changed_when: false
  tags: [shared_gallery, smoke, api]

- name: guest-gallery — missing nonce returns 400
  ansible.builtin.uri:
    url: "{{ gighive_base_url }}/api/guest-gallery.php"
    method: GET
    validate_certs: "{{ gighive_validate_certs }}"
    headers: "{{ _qr_host_header }}"
    status_code: 400
  changed_when: false
  tags: [shared_gallery, smoke, api]

- name: guest-gallery — unknown nonce returns 403
  ansible.builtin.uri:
    url: "{{ gighive_base_url }}/api/guest-gallery.php?nonce=AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA"
    method: GET
    validate_certs: "{{ gighive_validate_certs }}"
    headers: "{{ _qr_host_header }}"
    status_code: 403
  changed_when: false
  tags: [shared_gallery, smoke, api]

- name: guest-report — missing body returns 400
  ansible.builtin.uri:
    url: "{{ gighive_base_url }}/api/guest-report.php"
    method: POST
    body: '{}'
    body_format: json
    validate_certs: "{{ gighive_validate_certs }}"
    headers: "{{ _qr_host_header }}"
    status_code: 400
  changed_when: false
  tags: [shared_gallery, smoke, api]

- name: guest-report — unknown nonce returns 403
  ansible.builtin.uri:
    url: "{{ gighive_base_url }}/api/guest-report.php"
    method: POST
    body: '{"nonce":"AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA","upload_job_id":1}'
    body_format: json
    validate_certs: "{{ gighive_validate_certs }}"
    headers: "{{ _qr_host_header }}"
    status_code: 403
  changed_when: false
  tags: [shared_gallery, smoke, api]

- name: guest_event_view — unknown nonce returns 403
  ansible.builtin.uri:
    url: "{{ gighive_base_url }}/guest_event_view.php?nonce=AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA"
    method: GET
    validate_certs: "{{ gighive_validate_certs }}"
    headers: "{{ _qr_host_header }}"
    status_code: 403
  changed_when: false
  tags: [shared_gallery, smoke, api]
```

---

## Phase 2 — iPhone / Mac

> **Prerequisite:** Phase 1 must be deployed to dev/staging and passing smoke tests before beginning Phase 2.

### Step 1 — Swift: `GuestUploadRecord` struct

**File:** `GigHive/Models/GuestUploadRecord.swift` (new file)

```swift
struct GuestUploadRecord: Codable {
    let statusNonce: String
    let uploadJobId: Int        // upload_jobs.id (INT auto-increment)
    let eventName: String       // e.g. "StormPigs — 2026-07-17"
    let submittedAt: Date
    var approvalStatus: String  // "pending" | "approved" | "rejected" | "expired"
    var lastSeenVideoCount: Int
    var daysRemaining: Int?     // nil = indefinite
}
```

- Persisted as a JSON-encoded array under `UserDefaults` key `"guestUploadHistory"` — SonarQube iOS rule RSPEC-5334 flags `UserDefaults` for security tokens and prefers Keychain; for this feature the `UserDefaults` choice is intentional: the nonce is device-bound by design, the model is accountless with no recovery path, and the threat model (device theft) is already out-of-scope for this feature. Mark this hotspot as **reviewed and accepted** in SonarQube with this rationale.
- Provide static helpers: `load() -> [GuestUploadRecord]`, `save(_ records: [GuestUploadRecord])`, `upsert(_ record: GuestUploadRecord)`
- Terminal statuses (`rejected`, `expired`) are never polled again; skip in polling loop
- A guest who uploads multiple clips (e.g. scans a still-valid token twice) gets a separate `GuestUploadRecord` per upload, each with its own nonce. Multiple records for the same event appear as separate entries in "Your Event Galleries" — grouping is deferred to a future iteration.

---

### Step 2 — Swift: update finalize handling in `GuestUploadView` / `GuestUploadSession`

**Files:** `GigHive/Views/GuestUploadView.swift`, `GigHive/Models/GuestUploadSession.swift`

- Parse `status_nonce` (String) and `upload_job_id` (Int) from finalize JSON response
- Construct `GuestUploadRecord` with `approvalStatus = "pending"`, `lastSeenVideoCount = 0`
- Call `GuestUploadRecord.upsert(record)` to persist
- Show success screen: display the post-upload UX message from the spec
- `display_name` field: make required before enabling the Upload button (alongside existing ToS + video-selected gate); auto-populate from `UIDevice.current.name`

---

### Step 3 — Swift: status polling on app open

**File:** `GigHive/App/GigHiveApp.swift` or `GigHive/Views/SplashView.swift`

On `onAppear` (or scene-phase `.active`):

- Load `GuestUploadRecord.load()`
- Filter to records where `approvalStatus` is `"pending"` or `"approved"` (skip terminal)
- For each, fire `GET /api/guest-status.php?nonce=<statusNonce>` concurrently (use `async let` or `TaskGroup`)
- On response:
  - Update `approvalStatus`, `daysRemaining`, `lastSeenVideoCount` in the local record array
  - Call `GuestUploadRecord.save(records)` once after all responses are processed
  - If status changed to `"approved"` for the first time (was previously `"pending"`): set a flag to show the approval banner (one-time; stored in `UserDefaults` or a `@State` bool); update `lastSeenVideoCount`
  - If `video_count` > `lastSeenVideoCount` on an already-approved record: mark for "New videos added" badge
  - If status is `"rejected"`: show brief non-alarming message; no retry
  - If status is `"expired"`: update local record; stop polling

---

### Step 4 — Swift: `SplashView` — approval banner + "Your Event Galleries" section

**File:** `GigHive/Views/SplashView.swift`

Two additions:

**A. One-time approval banner** (shown on transition from pending → approved):
- Full-width card with approval message from spec
- "View Event Gallery" `Button` → navigate to `GuestGalleryView(statusNonce: record.statusNonce)`
- Device-bound warning in smaller text below
- Dismissed by tapping Done; dismissal state persisted in **`UserDefaults`** (e.g. a `Set<String>` of nonces whose banners have been dismissed) — `@State` alone is ephemeral and the banner would reappear on every app relaunch

**B. Persistent "Your Event Galleries" section** (shown whenever any record has `approvalStatus == "approved"`):
- Section header: "Your Event Galleries"
- `ForEach` over approved records; each row shows `eventName`, `daysRemaining` subtitle
- "New videos added" badge if `video_count` increased since last visit
- "View Gallery" button → `NavigationLink` to `GuestGalleryView(statusNonce: record.statusNonce)`

---

### Step 5 — Swift: `GuestGalleryView.swift` (new view)

**File:** `GigHive/Views/GuestGalleryView.swift` (new file)

- Input: `statusNonce: String`
- On `.onAppear` (iOS 14 compat — do not use `.task` which requires iOS 15+): `Task { GET /api/guest-gallery.php?nonce=<statusNonce> }`
  - `403` → show "Access unavailable" error state
  - `status == "expired"` → show "This gallery is no longer available" single row
  - `status == "approved"` and `videos` array is empty → show "No videos approved yet — check back soon" (the API never returns a literal `"approved_empty"` status string; defend against empty `videos` array at the client level)
  - Success → populate video list; update `lastSeenVideoCount` in `GuestUploadRecord`
- Video list: `List` of rows with `display_name`, `label`, tap → full-screen `AVPlayer` (reuse existing `MediaPlayerView`)
- **Video playback:** load each `stream_url` via `AVURLAsset(url:options:)` with `[AVURLAssetHTTPHeaderFieldsKey: ["X-Gallery-Nonce": statusNonce]]`; do **not** use `AVPlayer(url:)` directly — it does not support custom request headers and will receive `401` from Apache's `/video/` gate (see Phase 1 Step 2)
- `days_remaining` shown as subtitle on the view (e.g. "Available for 87 more days"); omit if `null`
- **Report button** per row: `confirmationDialog` → "Report this video" → `POST /api/guest-report.php { nonce, upload_job_id }` → show toast "Thank you — this video has been flagged for review" on `200`; silent on `403` (already flagged or cross-event mismatch)
- Navigation title: event name from the first video's context or stored in `GuestUploadRecord.eventName`

---

### Step 6 — iOS testing checklist

Manual and automated checks before shipping:

- [ ] Guest upload with valid token returns `status_nonce` and `upload_job_id` in finalize response; `GuestUploadRecord` persisted in `UserDefaults`
- [ ] `anon_upload_attributions` row in dev DB has `status_nonce` populated; `upload_jobs` row has `moderation_status = 'pending'`, `label`, `file_relpath` populated
- [ ] `guest-status.php?nonce=<valid>` returns `pending` for a just-uploaded record
- [ ] Admin approves via `event_qr.php` → `guest-status.php` returns `approved`; `approved_at` set in DB
- [ ] App open after approval shows one-time banner; "Your Event Galleries" section appears on `SplashView`
- [ ] `GuestGalleryView` loads approved video list; `AVPlayer` plays clip
- [ ] Report button fires `guest-report.php`; `guest_flagged = 1` confirmed in dev DB; ⚑ badge visible in admin queue
- [ ] Admin rejects → `guest-status.php` returns `rejected` → brief message shown; polling stops for that record
- [ ] Expired gallery (set `gallery_expires_at` to a past date in dev): status endpoint returns `expired`; `GuestGalleryView` shows "no longer available"
- [ ] Non-iOS web path: `guest_event_view.php?nonce=<valid approved nonce>` renders video list in browser
- [ ] Existing `UploadView` (Basic Auth flow) unaffected — no regressions

---

## Notes for Best-Practice Review

The following areas should be reviewed in a subsequent pass once this plan is agreed upon:

- **Event and org isolation** — the nonce → token → `event_id` chain is the isolation boundary; every endpoint that returns or modifies guest data must derive `event_id` from this chain and apply it as a hard `WHERE` constraint:
  - `guest-gallery.php` Step 1 resolves `t.event_id` from `anon_upload_attributions → event_upload_tokens`; Step 2 filters `WHERE t.event_id = ?` — only videos from the same event are returned
  - `guest-report.php` Step 2 filters `WHERE t2.event_id = ?` — a guest cannot flag videos from another event
  - `guest-status.php` returns only the nonce's own upload status — no cross-event data path exists
  - Apache's `/video/` gate checks nonce presence only, not event scope; SHA-256 filenames are the second layer — never leaked by the API outside the `event_id` boundary, and unguessable without the original file
  - Multiple tokens for the same event intentionally share one gallery (query is event-scoped, not token-scoped — correct behaviour; see spec § Event and Org Isolation)
- **SQL injection surface / tainted input (RSPEC-2076)** — all three new PHP API files are unauthenticated; every parameter must use prepared statements with bound values **and** be explicitly validated before DB use: `preg_match('/^[A-Za-z0-9_\-]{30,40}$/', ...)` for nonces, `filter_var(..., FILTER_VALIDATE_INT)` for integer IDs; return `400` on validation failure
- **Unhandled DB exceptions (RSPEC-2225)** — wrap all PDO operations in `try { … } catch (PDOException $e) { http_response_code(500); exit; }`; never let stack traces reach the response
- **`getenv()` return value unchecked (RSPEC-3516)** — `getenv()` returns `false` when the env var is absent; always use `(int)(getenv('KEY') ?: fallback)` pattern
- **Missing `Content-Type: application/json` (Security Hotspot)** — all three JSON API endpoints must emit this header; without it some browser contexts treat the response as HTML, enabling reflected XSS
- **`UserDefaults` for nonce (RSPEC-5334 — iOS)** — intentional design choice for accountless model; mark as reviewed and accepted in SonarQube (rationale documented in iOS Step 1)
- **Nonce entropy** — `random_bytes(24)` = 192 bits; stored in VARCHAR(40) as URL-safe base64 (32 chars); verify no truncation
- **Rate limiting** — unauthenticated endpoints (`guest-status`, `guest-gallery`, `guest-report`) are fronted by Cloudflare, which provides edge-level rate limiting and DDoS protection; no Apache-level rate limiting is required at this time. Adding `mod_evasive` at the vhost level is a future defense-in-depth option if direct-to-origin traffic becomes a concern.
- **Cloudflare caching** — all three API endpoints and `guest_event_view.php` return nonce-specific responses; each must emit `Cache-Control: no-store` (or at minimum `Cache-Control: private, no-cache`) so Cloudflare never caches a response keyed to one nonce and serves it to a different caller. Add `header('Cache-Control: no-store');` as the first line of each file.
- **CORS** — new `/api/*.php` endpoints will be called from the iOS app (not a browser); verify no `Access-Control-Allow-Origin: *` is accidentally added
- **`file_relpath` path traversal** — the preview URL `'/' . $gu['file_relpath']` must use `htmlspecialchars`; verify `file_relpath` is constrained to the `video/` prefix at write time (in `finalizeTusUpload`); guest uploads are video-only
- **`lastInsertId()` ordering** — must be called immediately after `upload_jobs` INSERT; code review gate
- **Migration idempotency** — `ALTER TABLE … ADD COLUMN` fails if column already exists; wrap in `IF NOT EXISTS` guard or use an idempotent migration runner

---

## Database Migration (Existing Installations)

The new columns must be added to `create_music_db.sql` so they are present on any fresh install. For an existing database already running in production, follow this process:

1. **Pre-migration backup** — back up the live database before touching it
2. **Apply DDL** — run the ALTER TABLE statements against the live database
3. **Post-migration backup** — back up the patched database (data + new columns, before the wipe)
4. **Ansible rebuild** — set `rebuild_mysql_data: true` in group_vars (do not commit), run the site playbook (wipes MySQL volume and reinitialises from the updated `create_music_db.sql`), revert the flag
5. **Restore** — restore the Step 3 post-migration backup into the fresh container via the Admin UI

**Step 1 — Pre-migration backup (run from the docker host):**

```bash
docker exec mysqlServer sh -lc 'mysqldump -h 127.0.0.1 -u root -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE"' > backup_pre_shared_gallery_$(date +%Y%m%d_%H%M%S).sql
```

**Step 2 — Apply DDL (run from the docker host):**

`ADD COLUMN IF NOT EXISTS` and `ADD INDEX IF NOT EXISTS` require MySQL 8.0+.

```bash
docker exec -i mysqlServer sh -lc 'mysql -h 127.0.0.1 -u root -p"$MYSQL_ROOT_PASSWORD" -D "$MYSQL_DATABASE"' << 'MIGRATION'
ALTER TABLE anon_upload_attributions
  ADD COLUMN IF NOT EXISTS status_nonce VARCHAR(40)  NULL AFTER tos_accepted_at,
  ADD COLUMN IF NOT EXISTS apns_token   VARCHAR(200) NULL AFTER status_nonce;
-- NOTE: status_nonce is NULL (not NOT NULL) in this migration because MySQL cannot add a NOT NULL
-- column without a DEFAULT when rows already exist. Legacy rows predate the feature and have no nonce;
-- NULL is the correct sentinel for them. The create_music_db.sql DDL (fresh installs, empty table)
-- keeps NOT NULL. MySQL UNIQUE indexes allow multiple NULLs, so the unique constraint still applies
-- correctly to all new guest-upload rows.
ALTER TABLE anon_upload_attributions
  ADD UNIQUE KEY IF NOT EXISTS uq_status_nonce (status_nonce);

ALTER TABLE events
  ADD COLUMN IF NOT EXISTS gallery_expires_at DATETIME   NULL                  AFTER event_type,
  ADD COLUMN IF NOT EXISTS is_multi_day       TINYINT(1) NOT NULL DEFAULT 0   AFTER gallery_expires_at;

ALTER TABLE upload_jobs
  ADD COLUMN IF NOT EXISTS label        VARCHAR(255) NULL AFTER completed_at,
  ADD COLUMN IF NOT EXISTS file_relpath VARCHAR(512) NULL AFTER label;

ALTER TABLE upload_jobs
  ADD COLUMN IF NOT EXISTS moderation_status ENUM('pending','approved','rejected') NULL DEFAULT NULL AFTER file_relpath,
  ADD COLUMN IF NOT EXISTS approved_at       DATETIME   NULL                            AFTER moderation_status,
  ADD COLUMN IF NOT EXISTS guest_flagged     TINYINT(1) NOT NULL DEFAULT 0              AFTER approved_at,
  ADD COLUMN IF NOT EXISTS guest_flagged_at  DATETIME   NULL                            AFTER guest_flagged;

ALTER TABLE upload_jobs
  ADD INDEX IF NOT EXISTS idx_upload_jobs_moderation (moderation_status);
MIGRATION
```

**Step 3 — Post-migration backup (run from the docker host):**

```bash
docker exec mysqlServer sh -lc 'mysqldump -h 127.0.0.1 -u root -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE"' > backup_post_shared_gallery_$(date +%Y%m%d_%H%M%S).sql
```

**Step 4 — Ansible rebuild (run from the control machine):**

First, set `rebuild_mysql_data: true` in the appropriate group_vars file (**do not commit this change**):
```yaml
rebuild_mysql_data: true # Rebuild MySQL container + wipe database (nuclear)
```

Then run the site playbook for the target environment. MySQL is briefly unavailable during the rebuild.

Dev:
```bash
script -q -c "ansible-playbook -i ansible/inventories/inventory_gighive2.yml ansible/playbooks/site.yml --skip-tags vbox_provision,db_migrations,installation_tracking,one_shot_bundle,one_shot_bundle_archive,upload_tests,playwright_admin_tests" ansible-playbook-gighive2-$(date +%Y%m%d).log
```

Lab:
```bash
script -q -c "ansible-playbook -i ansible/inventories/inventory_lab.yml ansible/playbooks/site.yml --skip-tags vbox_provision,db_migrations,installation_tracking,one_shot_bundle,one_shot_bundle_archive,upload_tests,playwright_admin_tests" ansible-playbook-lab-$(date +%Y%m%d).log
```

Staging:
```bash
script -q -c "ansible-playbook -i ansible/inventories/inventory_gighive.yml ansible/playbooks/site.yml --skip-tags vbox_provision,db_migrations,installation_tracking,one_shot_bundle,one_shot_bundle_archive,upload_tests,playwright_admin_tests" ansible-playbook-gighive-$(date +%Y%m%d).log
```

Prod:
```bash
script -q -c "ansible-playbook -i ansible/inventories/inventory_prod.yml ansible/playbooks/site.yml --skip-tags vbox_provision,db_migrations,installation_tracking,one_shot_bundle,one_shot_bundle_archive,upload_tests,playwright_admin_tests" ansible-playbook-prod-$(date +%Y%m%d).log
```

After Ansible completes, revert the flag:
```yaml
rebuild_mysql_data: false
```

---

**Step 5 — Restore** using the Step 3 post-migration backup into the fresh container via the Admin UI. The rebuilt container's fresh schema (from the updated `create_music_db.sql`) and the restored backup will both contain the nine new columns.

---

**Rollback** (reverts all nine new columns — run from the docker host if needed):

Drop columns in reverse addition order; indexes and keys must be dropped before their columns.

```bash
docker exec -i mysqlServer sh -lc 'mysql -h 127.0.0.1 -u root -p"$MYSQL_ROOT_PASSWORD" -D "$MYSQL_DATABASE"' << 'ROLLBACK'
ALTER TABLE anon_upload_attributions
  DROP KEY IF EXISTS uq_status_nonce,
  DROP COLUMN IF EXISTS apns_token,
  DROP COLUMN IF EXISTS status_nonce;

ALTER TABLE events
  DROP COLUMN IF EXISTS is_multi_day,
  DROP COLUMN IF EXISTS gallery_expires_at;

ALTER TABLE upload_jobs
  DROP INDEX IF EXISTS idx_upload_jobs_moderation,
  DROP COLUMN IF EXISTS guest_flagged_at,
  DROP COLUMN IF EXISTS guest_flagged,
  DROP COLUMN IF EXISTS approved_at,
  DROP COLUMN IF EXISTS moderation_status,
  DROP COLUMN IF EXISTS file_relpath,
  DROP COLUMN IF EXISTS label;
ROLLBACK
```
