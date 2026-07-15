# Shared Gallery — Implementation Plan

**Status:** Completed ✅  
**High-level spec:** `docs/feature_completed_iphone_qr_code_shared_gallery.md`
**Related docs:** `docs/feature_completed_iphone_qr_code_support.md` · `docs/feature_completed_iphone_qr_code_implementation.md`

This plan is split into two sequential **coding phases**. Complete Phase 1 and deploy to dev/staging before beginning Phase 2.

### Files Added / Modified

**Added**
1. [P1] `ansible/roles/docker/files/apache/webroot/api/guest-status.php`
2. [P1] `ansible/roles/docker/files/apache/webroot/api/guest-gallery.php`
3. [P1] `ansible/roles/docker/files/apache/webroot/api/guest-report.php`
4. [P1] `ansible/roles/docker/files/apache/webroot/api/guest-stream.php` *(see Step 11a — iOS streaming proxy; supersedes the `/video/` Apache SetEnvIf path for iOS consumers)*
5. [P1] `ansible/roles/docker/files/apache/webroot/guest_event_view.php`
6. [P1] `ansible/roles/shared_gallery/tasks/main.yml`
7. [P2] `GigHive/Models/GuestUploadRecord.swift`
8. [P2] `GigHive/Views/GuestGalleryView.swift`

**Modified**
1. [P1] `ansible/inventories/group_vars/gighive/gighive.yml`
2. [P1] `ansible/inventories/group_vars/gighive2/gighive2.yml`
3. [P1] `ansible/inventories/group_vars/prod/prod.yml`
4. [P1] `ansible/roles/docker/templates/.env.j2`
5. [P1] `ansible/roles/docker/templates/default-ssl.conf.j2`
6. [P1] `ansible/roles/docker/files/mysql/externalConfigs/create_media_db.sql`
7. [P1] `ansible/roles/docker/files/apache/webroot/src/Services/UploadService.php`
8. [P1] `ansible/roles/docker/files/apache/webroot/admin/event_qr.php`
9. [P1] `ansible/roles/docker/files/apache/webroot/db/upload_form_single.php`
10. [P1] `ansible/playbooks/site.yml`
11. [P2] `GigHive/Views/GuestUploadView.swift`
12. [P2] `GigHive/Models/GuestUploadSession.swift`
13. [P2] `GigHive/Extensions/UploadPayload+GuestUpload.swift`
14. [P2] `GigHive/App/GigHiveApp.swift` or `GigHive/Views/SplashView.swift`
15. [P2] `GigHive/Views/SplashView.swift`
16. [P2] `GigHive/Views/GuestGalleryView.swift`

> ⚠️ **Phase naming note:** the spec (`feature_completed_iphone_qr_code_shared_gallery.md` § "Release Gating") also uses Phase 1/2/3 to describe *feature release gates* (MVP → beta promotion → scale). Those are distinct from the coding phases here. Coding Phase 1 + 2 together correspond to the spec's Release Gate Phase 1 (MVP launch).

> **Completion note:** The implementation described in this document is complete. This file is retained as the authoritative record of the work that shipped.

### Phase 1 — Infrastructure / Ansible / PHP
- **Step 1** — Ansible: add `QR_GALLERY_DEFAULT_LIFESPAN_DAYS` env var to all group_vars and `.env.j2`
- **Step 2** — Apache: update `default-ssl.conf.j2` (three guest API endpoints exempted from Basic Auth; `/video/` opened to gallery-nonce authenticated requests)
- **Step 3** — Schema: update `create_media_db.sql` with all 9 new columns across `anon_upload_attributions`, `events`, and `upload_jobs`; DDL applied to existing installs manually (see Database Migration section)
- **Step 4** — PHP: expand `UploadService::finalizeTusUpload` token-mode path (status_nonce, label, file_relpath, moderation_status, lastInsertId ordering, updated response)
- **Step 5** — PHP: add `save_event_settings` POST handler to `admin/event_qr.php` (gallery lifespan + multi-day flag)
- **Step 6** — PHP: add TTL vs. gallery lifespan mismatch warning to QR Generator card in `admin/event_qr.php`
- **Step 6a** — PHP: add duplicate active-token guard to QR Generator card in `admin/event_qr.php` — after fetching `event_upload_tokens`, compute `$activeTokenCount` (tokens where `is_active = 1` and `expires_at > NOW()`), pre-render a `$activeTokenWarning` string via `htmlspecialchars()`, and attach it as a JS `confirm()` to the Generate QR button's `onclick` when non-empty. Admin can cancel or proceed — this is a guard, not a hard block.
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
- **Step 2a** — Swift: add optional **Clip label** field to the upload form
  - `GuestUploadSession`: add `@Published var clipLabel: String = ""`; clear in `clear()`
  - `GuestUploadView`: insert `NoAccessoryTextField` bound to `$guestSession.clipLabel` between the display name field and the ToS toggle; max 255 chars enforced via `.onChange`; helper text: *"Files are stored under a unique name for privacy. Your label helps you identify the clip in the gallery."*
  - `UploadPayload+GuestUpload.swift`: `forGuestUpload(fileURL:eventDetails:displayName:clipLabel:)` — trim `clipLabel`; if blank fall back to `"Video \(labelFallbackFormatter.string(from: Date()))"` (medium date + short time, locale-aware); pass resolved string as `label` in `UploadPayload`. **Do not** use `fileURL.lastPathComponent` as the label — iOS exports produce UUID-named temp files which are meaningless to users.
- **Step 3** — Swift: implement status polling on app open (concurrent per-record polls, update local records, show approval banner, "New videos added" detection)
- **Step 4** — Swift: add approval banner + "Your Event Galleries" persistent section to `SplashView`
  - **Gallery deduplication:** the `approvedRecords` list is filtered through a deduplication pass keyed on `record.baseURLString + "|" + record.eventName` before rendering. Only the first approved record per key is shown. All nonces for a given event continue to be polled in the background — deduplication is display-only. The "New videos" badge fires if `newVideoNonces` contains *any* nonce whose `baseURLString + eventName` matches the displayed record.
  - **Guest-aware login prompt:** the *"Please login first"* block in `SplashView` has three branches: (1) authenticated → show login info; (2) unauthenticated but `uploadRecords.contains { $0.approvalStatus == "approved" }` → show softer footnote *"Login for full database and upload access"*; (3) no credentials and no approved records → show original *"Please login first"* bold prompt. This ensures gallery-only guests are not nagged to log in.
- **Step 5** — Swift: create `GuestGalleryView.swift` (fetch gallery, AVPlayer playback, report flow, expired/empty states, days_remaining subtitle); add device-bound access warning footer below the video list
- **Step 6** — iOS testing checklist (DB verification, admin approve/reject flow, report flag, expiry, web fallback, regression)

---

## Phase 1 — Infrastructure / Ansible / PHP

### Step 1 — Ansible: add shared gallery env vars (`QR_GALLERY_DEFAULT_LIFESPAN_DAYS`, `QR_GUEST_UPLOAD_TENANT_ID`)

> ✅ **Already complete:** `QR_GALLERY_DEFAULT_LIFESPAN_DAYS` and `qr_gallery_default_lifespan_days` are already present in all group_vars and `.env.j2`. The `QR_GUEST_UPLOAD_TENANT_ID` addition is the remaining pending change.

**Files:** `ansible/inventories/group_vars/gighive/gighive.yml`, `ansible/inventories/group_vars/gighive2/gighive2.yml`, `ansible/inventories/group_vars/prod/prod.yml`, `ansible/roles/docker/templates/.env.j2`

- `qr_gallery_default_lifespan_days: 90` — already in all three group_vars ✅
- `QR_GALLERY_DEFAULT_LIFESPAN_DAYS={{ qr_gallery_default_lifespan_days | default(90) | int }}` — already in `.env.j2` ✅
- Add `qr_guest_upload_tenant_id: 1` to all three group_vars files — single-tenant transitional bridge; change per-environment when SAAS multi-tenancy is activated ✅ Done
- Add `QR_GUEST_UPLOAD_TENANT_ID={{ qr_guest_upload_tenant_id | default(1) | int }}` to `.env.j2` after `QR_GALLERY_DEFAULT_LIFESPAN_DAYS` ✅ Done
- `QR_GUEST_UPLOAD_TENANT_ID` is consumed by `UploadService::finalizeTusUpload` token-mode path (Step 4) in place of the currently hardcoded `1`; full SAAS support will later require adding `tenantId` to `TokenValidationResult` (see Step 4 note)

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

> **Two-path streaming architecture (implemented):** The `/video/` `SetEnvIf` gate above serves **web browser consumers only** (`guest_event_view.php` renders `<video src="/video/...?nonce=...">` tags that pass through this Apache gate). **iOS AVPlayer** is served by a separate PHP streaming proxy — `/api/guest-stream.php` (Step 11a) — which DB-validates the nonce, verifies event scope, checks approval status and gallery expiry, and streams the file with full `Accept-Ranges` / `Content-Range` support. This is more secure than the Apache regex gate (which only checks nonce *presence*, not validity) and avoids the need to pass custom `X-Gallery-Nonce` headers via `AVURLAsset`. `guest-gallery.php` returns different `stream_url` formats per consumer type: `/api/guest-stream.php?nonce=…&job_id=…` for the iOS path (Step 11a) and `/video/…?nonce=…` for the web path (Step 13). The Apache `/video/` `RequireAny` gate is still required and deployed — it serves the web fallback and provides defense-in-depth.

Replace the existing `<LocationMatch "^/video(?:/|$)">` staging-only conditional block with:

```apache
# --- VIDEO DIRECTORY: Basic Auth for all roles; gallery nonce for approved guests ---
# Apache is a forward gate only; nonce validity was established in guest-gallery.php
# or guest_event_view.php before stream_url was issued. SHA-256 filenames provide a
# second layer — unguessable without possessing the original file.
# The staging conditional is preserved so staging.gighive.app retains its existing
# public /video/ access; only non-staging environments use the RequireAny gate.
<LocationMatch "^/video(?:/|$)">
    AuthMerging Off
    <If "%{HTTP_HOST} == 'staging.gighive.app'">
        Require all granted
    <Else>
        AuthType Basic
        AuthName "GigHive Protected"
        AuthBasicProvider file
        AuthUserFile {{ gighive_htpasswd_path | default('/etc/apache2/gighive.htpasswd') }}
        <RequireAny>
            Require valid-user
            Require env gallery_nonce_auth
        </RequireAny>
    </Else>
</LocationMatch>
```

**`/video/podcasts/` note:** the outer catch-all `LocationMatch` (line 173 of the conf) already excludes `/video/podcasts/` from its Basic Auth block. However, this inner `^/video(?:/|$)` block still covers `/video/podcasts/` — meaning podcast files will now be accessible to gallery nonce holders. This is acceptable in practice (podcast filenames are not publicly enumerable), but if stricter isolation is required, change the pattern to `^/video/(?!podcasts(?:/|$))` and add a separate public `<Location "/video/podcasts/">` block.

**`guest_event_view.php` requires no exemption:** this file lives at the webroot root, not under `/api/` or `/db/`. The main Basic Auth `LocationMatch` at line 173 only covers specific subdirectory paths (`api`, `db/...`, `video`, etc.) and does not match root-level `.php` files. No `AuthMerging Off` block is needed for `guest_event_view.php`.

---

### Step 3 — Schema: update DDL source file

**File:** `ansible/roles/docker/files/mysql/externalConfigs/create_media_db.sql` — add the nine new columns so fresh installs include them automatically. For existing installs, the DDL is applied manually via the docker command in the Database Migration section below.

Run in this order — `moderation_status AFTER label` requires `label` to exist first:

```sql
-- 1. anon_upload_attributions
ALTER TABLE anon_upload_attributions
  ADD COLUMN status_nonce VARCHAR(40)  NOT NULL AFTER tos_accepted_at,
  ADD COLUMN apns_token   VARCHAR(200) NULL     AFTER status_nonce,  -- future-use: APNs push device token; MVP uses polling via guest-status.php
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

**Database Migration (existing installs):**

Run from the **docker host** for the target environment. Follow the full BABRR procedure in `docs/process_backup_alter_backup.md` — take a pre-migration backup first, apply the DDL below, then take a post-migration backup before rebuilding.

```bash
docker exec -i mysqlServer sh -lc 'mysql -h 127.0.0.1 -u root -p"$MYSQL_ROOT_PASSWORD" -D "$MYSQL_DATABASE"' << 'MIGRATION'
-- 1. anon_upload_attributions
ALTER TABLE anon_upload_attributions
  ADD COLUMN IF NOT EXISTS status_nonce VARCHAR(40)  NOT NULL AFTER tos_accepted_at,
  ADD COLUMN IF NOT EXISTS apns_token   VARCHAR(200) NULL     AFTER status_nonce;
ALTER TABLE anon_upload_attributions
  DROP KEY IF EXISTS uq_status_nonce;
ALTER TABLE anon_upload_attributions
  ADD UNIQUE KEY uq_status_nonce (status_nonce);

-- 2. events
ALTER TABLE events
  ADD COLUMN IF NOT EXISTS gallery_expires_at DATETIME   NULL               AFTER event_type,
  ADD COLUMN IF NOT EXISTS is_multi_day       TINYINT(1) NOT NULL DEFAULT 0 AFTER gallery_expires_at;

-- 3. upload_jobs — label and file_relpath first (moderation columns reference AFTER label)
ALTER TABLE upload_jobs
  ADD COLUMN IF NOT EXISTS label        VARCHAR(255) NULL AFTER completed_at,
  ADD COLUMN IF NOT EXISTS file_relpath VARCHAR(512) NULL AFTER label;

-- 4. upload_jobs — moderation columns
ALTER TABLE upload_jobs
  ADD COLUMN IF NOT EXISTS moderation_status ENUM('pending','approved','rejected') NULL DEFAULT NULL AFTER file_relpath,
  ADD COLUMN IF NOT EXISTS approved_at       DATETIME   NULL                            AFTER moderation_status,
  ADD COLUMN IF NOT EXISTS guest_flagged     TINYINT(1) NOT NULL DEFAULT 0              AFTER approved_at,
  ADD COLUMN IF NOT EXISTS guest_flagged_at  DATETIME   NULL                            AFTER guest_flagged;

-- 5. Moderation index
ALTER TABLE upload_jobs DROP INDEX IF EXISTS idx_upload_jobs_moderation;
ALTER TABLE upload_jobs ADD INDEX idx_upload_jobs_moderation (moderation_status);
MIGRATION
```

Verify all three tables:

```bash
docker exec mysqlServer sh -lc 'mysql -h 127.0.0.1 -u root -p"$MYSQL_ROOT_PASSWORD" -D "$MYSQL_DATABASE" \
  -e "SHOW CREATE TABLE anon_upload_attributions\G SHOW CREATE TABLE events\G SHOW CREATE TABLE upload_jobs\G"'
```

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
   `handleUpload()` always returns `'file_name'` as `{sha256}.{ext}` (see `UploadService.php` line 109 comment: *"Stored filename is always {sha256}.{ext}"*). `source_relpath` is a DB-only provenance column written to the `assets` table; it is **not** returned in the `$result` array. **`file_type` is always `'video'` for token-mode uploads** — enforced by the server-side `UPLOAD_ALLOWED_MIMES_JSON` constraint and `PHPickerViewController`'s `.videos` filter; no runtime assertion is needed here. Revisit `file_relpath` construction if audio upload support is added in a future phase.

> **`apns_token` write path:** the `apns_token` column is intentionally not populated by the finalize INSERT below. The MVP notification mechanism is polling via `guest-status.php` (Phase 2 Step 3). APNs push support is deferred to a future phase and will require a separate device-token registration endpoint.

3. Read `tenant_id` from the env var — `TokenValidationResult` does not carry `tenant_id` (it is not in the token validation query), so it cannot be sourced from `$tokenResult`. Never hardcode `1`:
   ```php
   $tenantId = (int)(getenv('QR_GUEST_UPLOAD_TENANT_ID') ?: 1);
   ```
   Expand the `upload_jobs` INSERT to include `label`, `file_relpath`, `moderation_status`:
   ```sql
   INSERT INTO upload_jobs
     (tenant_id, job_id, job_type, status, total_files, started_at,
      label, file_relpath, moderation_status)
   VALUES (?, ?, 'qr_guest_upload', 'completed', 1, NOW(), ?, ?, 'pending')
   -- bind: $tenantId, $jobId (TUS UUID VARCHAR), $label, $fileRelpath
   ```
   `QR_GUEST_UPLOAD_TENANT_ID` is set via `qr_guest_upload_tenant_id` in group_vars (Step 1). **Future SAAS path:** add `public int $tenantId` to `TokenValidationResult` and `t.tenant_id` to the `UploadTokenValidator` SELECT — the env var is the single-tenant transitional bridge until full multi-tenancy is activated.
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
- Validate `gallery_lifespan_days` with `filter_var` — a raw `(int)` cast silently coerces `"abc"` to `0` and `"-5"` to `-5` without error (SonarQube RSPEC-2076 flags `$_POST` as tainted); 0 is a valid input meaning indefinite (`gallery_expires_at = NULL`):
  ```php
  $n = filter_var($_POST['gallery_lifespan_days'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
  if ($n === false) { /* flash error and redirect — reject non-integer and negative inputs */ }
  ```
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

Add four POST action handlers:

**`approve_upload` / `reject_upload`** — per-row moderation. Cross-validation UPDATE (upload_jobs has no event_id — must JOIN through attributions → tokens):

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

**`approve_all_pending` / `reject_all_pending`** — bulk moderation. Same JOIN pattern but no `job_id` filter; restricts to `pending OR NULL` rows:

```sql
UPDATE upload_jobs j
JOIN anon_upload_attributions a ON a.upload_job_id = j.job_id
JOIN event_upload_tokens t ON t.token_id = a.token_id
SET j.moderation_status = 'approved',  -- or 'rejected'
    j.approved_at = NOW()               -- or NULL for reject
WHERE t.event_id = ?
  AND (j.moderation_status = 'pending' OR j.moderation_status IS NULL)
```

- Reject All requires a browser `confirm()` dialog (`onclick="return confirm('...')"`) — non-reversible
- Returns affected row count in the flash message (e.g. "Approved 5 uploads.")
- Flash success/error message; redirect back to same page (POST–Redirect–GET) for all four actions

---

### Step 9 — PHP: `admin/event_qr.php` — moderation queue UI

**File:** `ansible/roles/docker/files/apache/webroot/admin/event_qr.php`

Extend the existing Guest Uploads table HTML:

1. Add two new badge CSS classes (inline `<style>` block at top of file alongside existing badges):
   - `.badge-mod-pending` — amber background
   - `.badge-mod-approved` — green background
   - `.badge-mod-rejected` — reuse existing `.badge-revoked` (red); no new class needed
2. Above the table, when `$pendingCount > 0`, render a flex row with **Approve All Pending** (green border) and **Reject All Pending** (red, `btn-danger`) buttons as inline POST forms (actions `approve_all_pending` / `reject_all_pending`). Reject All requires `onclick="return confirm('Reject all N pending uploads?')"`. Show upload count + pending count as muted text on the left of this row.
3. For each row, add a **Moderation** column showing the badge; if `guest_flagged = 1` append **⚑ Guest report** in amber text
4. Add a **Preview** column: check `$gu['file_relpath'] !== null` first — if null output `—`; otherwise render `<a href="/<?= htmlspecialchars($gu['file_relpath'], ENT_QUOTES) ?>" target="_blank">▶ Preview</a>`. Do **not** pass null directly to `htmlspecialchars` — PHP 8.1+ raises a deprecation and silently outputs `href="/"` (a link to the webroot)
5. Add **[Approve]** / **[Reject]** buttons only on `pending` rows (POST forms with `action=approve_upload` / `action=reject_upload`); already-decided rows show `—`

---

### Step 10 — PHP: new file `/api/guest-status.php`

**File:** `ansible/roles/docker/files/apache/webroot/api/guest-status.php`

- Method: GET; parameter: `?nonce=`
- No admin session required; nonce is the only credential
- **First two lines:** `header('Cache-Control: no-store');` and `header('Content-Type: application/json');` — no-store prevents Cloudflare caching; explicit content-type prevents browsers treating JSON as HTML (SonarQube Security Hotspot). These must appear before any `echo` or HTML output, including whitespace before `<?php` (SonarQube RSPEC-5724)
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
- **First two lines:** `header('Cache-Control: no-store');` and `header('Content-Type: application/json');` — same rationale as `guest-status.php`; must appear before any output (SonarQube RSPEC-5724)
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
- Construct `stream_url` for **two consumer paths** — iOS app and web browser use different endpoints (see Step 2 two-path note and Step 11a):
  - **iOS AVPlayer** → PHP streaming proxy (DB-validates nonce + event scope on every request; full `Accept-Ranges` support):
    ```php
    $streamUrl = '/api/guest-stream.php?nonce=' . urlencode($nonce) . '&job_id=' . (int)$row['upload_job_id'];
    ```
  - **Web browser** (`guest_event_view.php`) → direct file path through Apache `SetEnvIf` nonce gate (Step 2B):
    ```php
    $streamUrl = '/' . $row['file_relpath'] . '?nonce=' . urlencode($nonce);
    ```
  The **implemented** path uses the iOS proxy URL above. `guest_event_view.php` (Step 13) builds its own `src` attribute independently using the direct `/video/` path — it does not consume `guest-gallery.php`'s `stream_url`.
- Response (iOS path):
  ```json
  { "status": "approved",
    "days_remaining": 87,
    "videos": [{ "upload_job_id": 5, "label": "my clip",
                 "stream_url": "/api/guest-stream.php?nonce=abc123XYZ789abc123XYZ789abc12345&job_id=5",
                 "display_name": "Scott's iPhone", "approved_at": "2026-07-18T10:00:00Z" }] }
  ```
  `status` is always `"approved"` when this endpoint returns `200`. `approved_empty` is not a reachable state here — if Step 1 passes (nonce's own upload is approved), the nonce holder's own video is always included in Step 2's result, so `videos` always contains ≥ 1 entry. The client (`GuestGalleryView`) should still handle `videos: []` defensively as a no-op empty state, but no special status string is needed.

---

### Step 11a — PHP: new file `/api/guest-stream.php` *(iOS streaming proxy)*

**File:** `ansible/roles/docker/files/apache/webroot/api/guest-stream.php`

> **Rationale:** iOS `AVPlayer` receives a `401` when fetching `/video/…` directly because the dev/prod servers protect that directory with HTTP Basic Auth (Apache `LocationMatch`). The Apache `SetEnvIf` nonce gate (Step 2B) is the spec's original mechanism and remains deployed for the **web browser** path. For iOS, a PHP proxy is cleaner: it DB-validates the nonce on every request (not just regex-matches its presence), enforces event scope, checks gallery expiry, and handles `Accept-Ranges` / `Content-Range` for AVPlayer seeking — without requiring custom `X-Gallery-Nonce` headers via `AVURLAsset`.

- Method: GET; parameters: `?nonce=` and `?job_id=` (INT `upload_jobs.id`)
- No admin session required; Apache exemption block added to `default-ssl.conf.j2` (same `AuthMerging Off / Require all granted` pattern as the other three guest API endpoints)
- **Input validation:** `preg_match` nonce pattern (same as all guest endpoints); `filter_var($jobId, FILTER_VALIDATE_INT)` for `job_id` — return `400` on failure
- **Auth — Step 1:** validate nonce using the same query as `guest-gallery.php` Step 1 (nonce's own upload approved + gallery not expired) → `403` on failure or expiry
- **Auth — Step 2:** verify the requested `job_id` belongs to the same `event_id` and is approved:
  ```sql
  SELECT j.file_relpath
  FROM upload_jobs j
  JOIN anon_upload_attributions a ON a.upload_job_id = j.job_id
  JOIN event_upload_tokens t ON t.token_id = a.token_id
  WHERE j.id = ? AND t.event_id = ? AND j.moderation_status = 'approved'
  ```
  → `404` if not found (wrong event, unapproved, or nonexistent job)
- **Path traversal guard:** reject `file_relpath` containing `..`, leading `/`, or characters outside `[a-zA-Z0-9_/\-.]+`
- **File streaming with Range support** (required by AVPlayer for seeking):
  - Parse `HTTP_RANGE` header (`bytes=start-end`)
  - `206 Partial Content` with `Content-Range` header for range requests
  - `200 OK` with `readfile()` for full requests
  - Always emit `Accept-Ranges: bytes`, `Content-Type: video/mp4`, `Content-Length`, `Cache-Control: no-store`

---

### Step 12 — PHP: new file `/api/guest-report.php`

**File:** `ansible/roles/docker/files/apache/webroot/api/guest-report.php`

- Method: POST; JSON body: `{ "nonce": "...", "upload_job_id": 42 }` (INT `upload_jobs.id`)
- No admin session required
- **First two lines:** `header('Cache-Control: no-store');` and `header('Content-Type: application/json');` — POST responses are not typically cached, but `no-store` is added for consistency with the other guest endpoints and to ensure no intermediary caches a nonce-bound response; must appear before any output (SonarQube RSPEC-5724)
- **Parse and validate JSON body before DB use:** `json_decode()` returns `null` on malformed or empty input; accessing `$body->nonce` when `$body` is null is a fatal error in PHP 8 — the `??` null-coalescing operator does not suppress property access on a null object (SonarQube RSPEC-2259). Decode and check first:
  ```php
  $body = json_decode(file_get_contents('php://input'));
  if ($body === null) { http_response_code(400); echo json_encode(['error' => 'invalid request']); exit; }
  if (preg_match('/^[A-Za-z0-9_\-]{30,40}$/', $body->nonce ?? '') !== 1) { http_response_code(400); exit; }
  $uploadJobId = filter_var($body->upload_job_id ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
  if ($uploadJobId === false) { http_response_code(400); exit; }
  ```
  (RSPEC-2076)
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
- Share gallery data retrieval logic with `/api/guest-gallery.php` via a common `include` (e.g. `src/Helpers/GuestGalleryHelper.php`) to avoid duplication. Verify that `src/Helpers/` is declared under the `Production\Api\` namespace in the project's `composer.json` `autoload.psr-4` map before using the class path
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

**`site.yml` wiring:** add the `shared_gallery` role immediately after `qr_code` in `ansible/playbooks/site.yml`, following the same `include_role` / tag pattern.

**Host header:** `_qr_host_header` is set by `set_fact` inside the `qr_code` role. When running `--tags shared_gallery` standalone, that fact is undefined. This role sets its own `_shared_gallery_host_header` fact as its first task so it can run independently.

Add the following tasks (full file — tags: `shared_gallery,smoke,env|db|api`):

```yaml
- name: Build common Host header dict for shared gallery smoke tests
  ansible.builtin.set_fact:
    _shared_gallery_host_header: "{% raw %}{{ {'Host': gighive_hostname_for_host_header} if (gighive_hostname_for_host_header | length) > 0 else {} }}{% endraw %}"
  changed_when: false
  tags: [shared_gallery, smoke]

# --- Env vars ---

- name: Assert QR_GALLERY_DEFAULT_LIFESPAN_DAYS is set in Apache container environment
  community.docker.docker_container_exec:
    container: "{{ apache_container_name }}"
    command: printenv QR_GALLERY_DEFAULT_LIFESPAN_DAYS
  register: _sg_lifespan_env
  changed_when: false
  failed_when: (_sg_lifespan_env.rc | default(1)) != 0 or (_sg_lifespan_env.stdout | trim) == ''
  tags: [shared_gallery, smoke, env]

- name: Assert QR_GALLERY_DEFAULT_LIFESPAN_DAYS is a positive integer
  ansible.builtin.assert:
    that:
      - (_sg_lifespan_env.stdout | trim) is match('^[0-9]+$')
      - (_sg_lifespan_env.stdout | trim | int) > 0
    fail_msg: >-
      QR_GALLERY_DEFAULT_LIFESPAN_DAYS='{{ _sg_lifespan_env.stdout | trim }}' is not a positive integer.
      Check qr_gallery_default_lifespan_days in group_vars and QR_GALLERY_DEFAULT_LIFESPAN_DAYS in .env.j2.
  tags: [shared_gallery, smoke, env]

- name: Assert QR_GUEST_UPLOAD_TENANT_ID is set in Apache container environment
  community.docker.docker_container_exec:
    container: "{{ apache_container_name }}"
    command: printenv QR_GUEST_UPLOAD_TENANT_ID
  register: _sg_tenant_env
  changed_when: false
  failed_when: (_sg_tenant_env.rc | default(1)) != 0 or (_sg_tenant_env.stdout | trim) == ''
  tags: [shared_gallery, smoke, env]

- name: Assert QR_GUEST_UPLOAD_TENANT_ID is a positive integer
  ansible.builtin.assert:
    that:
      - (_sg_tenant_env.stdout | trim) is match('^[0-9]+$')
      - (_sg_tenant_env.stdout | trim | int) > 0
    fail_msg: >-
      QR_GUEST_UPLOAD_TENANT_ID='{{ _sg_tenant_env.stdout | trim }}' is not a positive integer.
      Check qr_guest_upload_tenant_id in group_vars and QR_GUEST_UPLOAD_TENANT_ID in .env.j2.
  tags: [shared_gallery, smoke, env]

# --- DB columns (SELECT LIMIT 0 validates column names without reading data) ---

- name: Assert shared gallery columns exist in anon_upload_attributions
  community.docker.docker_container_exec:
    container: "{{ mysql_container_name }}"
    command: >-
      sh -lc 'mysql -h 127.0.0.1 -u root -p"$MYSQL_ROOT_PASSWORD" -D "$MYSQL_DATABASE"
      -e "SELECT status_nonce, apns_token FROM anon_upload_attributions LIMIT 0" > /dev/null 2>&1
      && echo 1 || echo 0'
  register: _sg_aua_cols
  changed_when: false
  tags: [shared_gallery, smoke, db]

- name: Fail if shared gallery columns missing from anon_upload_attributions
  ansible.builtin.assert:
    that:
      - (_sg_aua_cols.stdout | trim) == '1'
    fail_msg: >-
      status_nonce or apns_token column missing from anon_upload_attributions.
      Run the shared gallery DDL migration (Phase 1 Step 3).
  tags: [shared_gallery, smoke, db]

- name: Assert shared gallery columns exist in events
  community.docker.docker_container_exec:
    container: "{{ mysql_container_name }}"
    command: >-
      sh -lc 'mysql -h 127.0.0.1 -u root -p"$MYSQL_ROOT_PASSWORD" -D "$MYSQL_DATABASE"
      -e "SELECT gallery_expires_at, is_multi_day FROM events LIMIT 0" > /dev/null 2>&1
      && echo 1 || echo 0'
  register: _sg_events_cols
  changed_when: false
  tags: [shared_gallery, smoke, db]

- name: Fail if shared gallery columns missing from events
  ansible.builtin.assert:
    that:
      - (_sg_events_cols.stdout | trim) == '1'
    fail_msg: >-
      gallery_expires_at or is_multi_day column missing from events.
      Run the shared gallery DDL migration (Phase 1 Step 3).
  tags: [shared_gallery, smoke, db]

- name: Assert shared gallery columns exist in upload_jobs
  community.docker.docker_container_exec:
    container: "{{ mysql_container_name }}"
    command: >-
      sh -lc 'mysql -h 127.0.0.1 -u root -p"$MYSQL_ROOT_PASSWORD" -D "$MYSQL_DATABASE"
      -e "SELECT label, file_relpath, moderation_status, guest_flagged, approved_at FROM upload_jobs LIMIT 0"
      > /dev/null 2>&1 && echo 1 || echo 0'
  register: _sg_uj_cols
  changed_when: false
  tags: [shared_gallery, smoke, db]

- name: Fail if shared gallery columns missing from upload_jobs
  ansible.builtin.assert:
    that:
      - (_sg_uj_cols.stdout | trim) == '1'
    fail_msg: >-
      One or more shared gallery columns missing from upload_jobs
      (label, file_relpath, moderation_status, guest_flagged, approved_at).
      Run the shared gallery DDL migration (Phase 1 Step 3).
  tags: [shared_gallery, smoke, db]

# --- API smoke tests ---

- name: guest-status — missing nonce returns 400
  ansible.builtin.uri:
    url: "{{ gighive_base_url }}/api/guest-status.php"
    method: GET
    validate_certs: "{{ gighive_validate_certs }}"
    headers: "{{ _shared_gallery_host_header }}"
    status_code: 400
  changed_when: false
  tags: [shared_gallery, smoke, api]

- name: guest-status — unknown nonce returns 404
  ansible.builtin.uri:
    url: "{{ gighive_base_url }}/api/guest-status.php?nonce=AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA"
    method: GET
    validate_certs: "{{ gighive_validate_certs }}"
    headers: "{{ _shared_gallery_host_header }}"
    status_code: 404
  changed_when: false
  tags: [shared_gallery, smoke, api]

- name: guest-gallery — missing nonce returns 400
  ansible.builtin.uri:
    url: "{{ gighive_base_url }}/api/guest-gallery.php"
    method: GET
    validate_certs: "{{ gighive_validate_certs }}"
    headers: "{{ _shared_gallery_host_header }}"
    status_code: 400
  changed_when: false
  tags: [shared_gallery, smoke, api]

- name: guest-gallery — unknown nonce returns 403
  ansible.builtin.uri:
    url: "{{ gighive_base_url }}/api/guest-gallery.php?nonce=AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA"
    method: GET
    validate_certs: "{{ gighive_validate_certs }}"
    headers: "{{ _shared_gallery_host_header }}"
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
    headers: "{{ _shared_gallery_host_header }}"
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
    headers: "{{ _shared_gallery_host_header }}"
    status_code: 403
  changed_when: false
  tags: [shared_gallery, smoke, api]

- name: guest_event_view — unknown nonce returns 403
  ansible.builtin.uri:
    url: "{{ gighive_base_url }}/guest_event_view.php?nonce=AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA"
    method: GET
    validate_certs: "{{ gighive_validate_certs }}"
    headers: "{{ _shared_gallery_host_header }}"
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
    let baseURLString: String   // server origin used for polling and gallery navigation
    var approvalStatus: String  // "pending" | "approved" | "rejected" | "expired"
    var lastSeenVideoCount: Int
    var viewedUploadJobIds: [Int]  // IDs tapped in GuestGalleryView; drives per-video "New" badge
    var daysRemaining: Int?     // nil = indefinite

    // Custom init(from:) so records persisted before viewedUploadJobIds was added decode successfully.
    init(from decoder: Decoder) throws { … }
    init(statusNonce:uploadJobId:eventName:submittedAt:baseURLString:approvalStatus:lastSeenVideoCount:viewedUploadJobIds:daysRemaining:) { … }
}
```

- Persisted as a JSON-encoded array under `UserDefaults` key `"guestUploadHistory"` — SonarQube iOS rule RSPEC-5334 flags `UserDefaults` for security tokens and prefers Keychain; for this feature the `UserDefaults` choice is intentional: the nonce is device-bound by design, the model is accountless with no recovery path, and the threat model (device theft) is already out-of-scope for this feature. Mark this hotspot as **reviewed and accepted** in SonarQube with this rationale.
- Provide static helpers: `load() -> [GuestUploadRecord]`, `save(_ records: [GuestUploadRecord])`, `upsert(_ record: GuestUploadRecord)`, `loadDismissedBanners() -> Set<String>`, `dismissBanner(nonce:)`
- Terminal statuses (`rejected`, `expired`) are never polled again; skip in polling loop
- A guest who uploads multiple clips (e.g. scans a still-valid token twice) gets a separate `GuestUploadRecord` per upload, each with its own nonce. **`SplashView` deduplicates the "Your Event Galleries" list** by `baseURLString + "|" + eventName`, showing one entry per event (preferring records already tracked, sorted by submission order) — the raw array may contain multiple records for the same event, but the UI collapses them.

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

**Stale record removal (implemented — do not use `try?` on `fetchStatus`):**

> **Problem:** `GuestUploadRecord` entries are persisted in `UserDefaults` on the device. If the server database is rebuilt (e.g. `rebuild_mysql_data: true` during a dev/staging Ansible run), the nonce stored on-device no longer exists server-side. The poll calls `guest-status.php?nonce=<stale>`, which returns `404`. With a bare `try?`, the error is silently swallowed and the response is `nil` — indistinguishable from a transient network failure. The stale record stays in `UserDefaults` indefinitely and appears in "Your Event Galleries" as a phantom entry pointing to a dead nonce.

> **Fix:** use `do/catch` in the `TaskGroup` task body to capture the thrown error alongside the response. After all tasks complete, differentiate by error type:
> - `GuestGalleryError.badServer(404)` — nonce definitively not found; collect the nonce in `noncesToRemove`; after the loop call `records.removeAll { noncesToRemove.contains($0.statusNonce) }` before saving
> - Any other error (`URLError`, `badServer` non-404, decode failure) — transient; leave the record untouched so it is retried on the next poll
>
> This means the `withTaskGroup(of:)` element type must be `(Int, GuestStatusResponse?, Error?)` rather than `(Int, GuestStatusResponse?)`, and the results array must store the error alongside the optional response. `403` (`accessDenied`) is treated as a permanent failure and should also be added to `noncesToRemove` (the token was revoked or the nonce was never valid).

---

### Step 4 — Swift: `SplashView` — approval banner + "Your Event Galleries" section

**File:** `GigHive/Views/SplashView.swift`

Two additions:

**A. One-time approval banner** (shown for any approved record whose nonce hasn't been dismissed):
- Full-width card with approval message from spec
- "View Event Gallery" `Button` → navigate to `GuestGalleryView(record: record)` (passes the full `GuestUploadRecord`)
- Device-bound warning in smaller text below
- Dismissed by tapping Done or "View Event Gallery"; dismissal nonces stored in **`UserDefaults`** key `"dismissedApprovalBanners"` (`Set<String>` JSON-encoded) — `@State` alone is ephemeral and the banner would reappear on every app relaunch
- Banner re-appears on every app open until dismissed — it is not a one-shot transition detector; any approved-and-undismissed record triggers it

**B. Persistent "Your Event Galleries" section** (shown whenever any record has `approvalStatus == "approved"`):
- Section header: "Your Event Galleries"
- **Deduplicate by event:** `SplashView` collapses the raw `uploadRecords` array to one entry per `baseURLString + "|" + eventName` (first-seen wins among approved records). This means multiple uploads to the same event appear as a single gallery row.
- Each row shows `eventName`, optional `daysRemaining` subtitle ("Available for N more days"), a "New videos" badge if `newVideoNonces.contains(record.statusNonce)`, and a chevron
- `NavigationLink(destination: GuestGalleryView(record: record))` — full record is passed, not just the nonce
- Footer line below the gallery list: *"To add more videos to an event, scan the event QR code again."*
- **`newVideoNonces` re-filter on `.onAppear`:** after loading fresh records on each Splash appear, `newVideoNonces` is pruned by removing any nonce where `viewedUploadJobIds.count >= lastSeenVideoCount` (badge already cleared by the user having opened the gallery). The set is then repopulated from the async poll result.

---

### Step 5 — Swift: `GuestGalleryView.swift` (new view)

**File:** `GigHive/Views/GuestGalleryView.swift` (new file)

- Input: `record: GuestUploadRecord` — provides `statusNonce`, `uploadJobId` (own video ID), `eventName`, `baseURLString`, and `viewedUploadJobIds`
- On `.onAppear` (iOS 14 compat — do not use `.task` which requires iOS 15+): `Task { GET /api/guest-gallery.php?nonce=<record.statusNonce> }`
  - `403` → show "Access unavailable" error state
  - `status == "expired"` → show "This gallery is no longer available" single row
  - `status == "approved"` and `videos` array is empty → show "No videos approved yet — check back soon" (the API never returns a literal `"approved_empty"` status string; defend against empty `videos` array at the client level)
  - Success → populate video list; update `lastSeenVideoCount` in `GuestUploadRecord`
- **`@State private var viewedIds: Set<Int>`** — populated from `record.viewedUploadJobIds` on appear; updated via `markViewed(uploadJobId:)` when the user taps a video. `markViewed` persists to `UserDefaults` via `GuestUploadRecord.upsert` so `viewedUploadJobIds` stays in sync.
- Video list: `List` of rows with `display_name`, `label`, per-video "New" badge (show if `!viewedIds.contains(video.uploadJobId)`), tap → full-screen `AVPlayer`
- **Video playback:** `buildStreamURL(video:)` constructs the absolute URL from `URL(string: video.streamUrl, relativeTo: URL(string: record.baseURLString))?.absoluteURL`. The `stream_url` from the API is a relative path (`/api/guest-stream.php?nonce=…&job_id=…`); the base URL comes from `record.baseURLString`. `AVPlayer(url:)` is used directly — no `AVURLAsset` with custom headers required. See Phase 1 Step 11a for why the proxy is used.
- `days_remaining` shown as subtitle on the view (e.g. "Available for 87 more days"); omit if `null`
- **Report button** per row: **`.alert` confirmation** (not `.confirmationDialog` — requires iOS 15+) → "Report this video" → `POST /api/guest-report.php { nonce, upload_job_id }` → show feedback `.alert` "Thank you — this video has been flagged for review" on `200`; silent on `403` (already flagged or cross-event mismatch). Implement via a single `Optional<GalleryAlert>` `@State` with a `GalleryAlert` enum (`case reportConfirm(GuestGalleryVideo)`, `case reportFeedback(String)`, `case error(String)`) and an `activeAlert: Binding<Bool>` derived from the optional.
- Navigation title: `record.eventName`
- **Device-bound warning footer:** below the video list, render an `HStack` (outside the `ScrollView`/video rows `VStack`) containing a `⚠️` triangle icon and the text: *"Your gallery access is stored on this device. Deleting the app will remove access."* styled `.caption2` / muted color. Shown on every gallery visit — not just on the one-time approval banner — so the limitation is always visible.

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
- **Admin-side tenant isolation (Steps 7/8)** — the `$guestUploads` SELECT (Step 7) and approve/reject UPDATE (Step 8) filter by `event_id` only. Add `AND t.tenant_id = ?` (bound to the admin session's tenant) to both queries as a defense-in-depth layer against CSRF or logic bugs that could inject a valid `event_id` from another tenant
- **SQL injection surface / tainted input (RSPEC-2076)** — all three new PHP API files are unauthenticated; every parameter must use prepared statements with bound values **and** be explicitly validated before DB use: `preg_match('/^[A-Za-z0-9_\-]{30,40}$/', ...)` for nonces, `filter_var(..., FILTER_VALIDATE_INT)` for integer IDs; return `400` on validation failure
- **Unhandled DB exceptions (RSPEC-2225)** — wrap all PDO operations in `try { … } catch (PDOException $e) { http_response_code(500); exit; }`; never let stack traces reach the response
- **`getenv()` return value unchecked (RSPEC-3516)** — `getenv()` returns `false` when the env var is absent; always use `(int)(getenv('KEY') ?: fallback)` pattern
- **`json_decode()` null return (RSPEC-2259)** — accessing `$body->nonce` when `json_decode()` returns `null` is a fatal error in PHP 8; the `??` null-coalescing operator does not suppress property access on a null object; always check the return value before property access (see Step 12)
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

The new columns must be added to `create_media_db.sql` so they are present on any fresh install. For an existing database already running in production, follow this process:

1. **Pre-migration backup** — back up the live database before touching it
2. **Apply DDL** — run the ALTER TABLE statements against the live database
3. **Post-migration backup** — back up the patched database (data + new columns, before the wipe)
4. **Ansible rebuild** — set `rebuild_mysql_data: true` in group_vars (do not commit), run the site playbook (wipes MySQL volume and reinitialises from the updated `create_media_db.sql`), revert the flag
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
-- NULL is the correct sentinel for them. The create_media_db.sql DDL (fresh installs, empty table)
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

**Step 5 — Restore** using the Step 3 post-migration backup into the fresh container via the Admin UI. The rebuilt container's fresh schema (from the updated `create_media_db.sql`) and the restored backup will both contain the nine new columns.

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

---

## Phase 3 — Production Universal Links Launch

This phase activates Universal Links for real end-users on the App Store build. The `?mode=developer` entitlements used during development **do not apply** to App Store or TestFlight builds. A separate production path is required.

> **Context:** During development, Universal Links were debugged and confirmed working on `dev.gighive.app` using `?mode=developer` entitlements + the device's **Settings → Developer → Associated Domains Development** toggle. See `docs/problem_iphone_qr_code_redirect.md` for the full root-cause log. Production users have none of these developer settings — they rely entirely on `applinks:*.gighive.app` (wildcard, no query parameter) and Apple's CDN cache seeded per org subdomain.

---

### URL pattern

Production QR codes use org-specific subdomains:
```
https://<org-slug>.gighive.app/upload/<token>
```
e.g. `https://stormpigs.gighive.app/upload/js-abc123...`

`applinks:gighive.app` does **not** cover subdomains. A wildcard entitlement is required.

---

### Step 1 — Deploy AASA to every org subdomain on `*.gighive.app`

The AASA file content is identical for all org subdomains (same `appIDs`). The Ansible template and group_vars are already correct. Confirm the file is live and reachable on at least one org subdomain:

```bash
# Substitute an actual org slug
curl -sI https://stormpigs.gighive.app/.well-known/apple-app-site-association
# Must: HTTP/2 200, Content-Type: application/json, no redirect

curl -s https://stormpigs.gighive.app/.well-known/apple-app-site-association
# Expected: {"applinks":{"details":[{"appIDs":["WB7D4FC7XU.app.gighive.GigHive"],"components":[{"/":"/upload/*"}]}]}}
```

If missing, run the production Ansible playbook — the Apache vhost config serves the AASA for all subdomains from the same template.

---

### Step 2 — Understand the CDN warm-up model for wildcard subdomains

Apple's CDN does **not** pre-crawl all possible `*.gighive.app` subdomains. It crawls a specific subdomain on-demand the first time a device with the installed app encounters a link to that subdomain.

**Practical consequence:**
- The first guest to tap a `stormpigs.gighive.app` QR link may land on the web fallback (CDN not yet seeded for that subdomain)
- Subsequent guests will get the native app once the CDN has crawled `stormpigs.gighive.app`
- CDN seeding typically completes within minutes to hours of first contact

**To force CDN seeding before an event:**
```bash
# Trigger Apple's crawler by accessing the subdomain — or just check it directly:
curl -s https://app-site-association.cdn-apple.com/a/v1/stormpigs.gighive.app
# "Not Found" = not yet seeded. Check again after ~15–60 min.
# Correct JSON = ready.
```

Consider adding a pre-event step to your runbook: generate the QR code a day before the event so the CDN has time to crawl the org subdomain.

---

### Step 3 — Add `applinks:*.gighive.app` to the entitlements

Replace `applinks:gighive.app` with the wildcard in `GigHive/Configs/GigHive.entitlements`:

```xml
<key>com.apple.developer.associated-domains</key>
<array>
    <string>applinks:*.gighive.app</string>
    <string>applinks:dev.gighive.app?mode=developer</string>
    <string>applinks:gighive.internal?mode=developer</string>
    <string>applinks:gighive2.gighive.internal?mode=developer</string>
</array>
```

Notes:
- `applinks:*.gighive.app` covers one level of subdomains — `stormpigs.gighive.app` ✅, `a.stormpigs.gighive.app` ✗
- `dev.gighive.app` also matches the wildcard, but the explicit `?mode=developer` entry is kept to preserve direct-server fetch for development
- The `?mode=developer` entries are silently ignored by swcd on production devices without Associated Domains Development enabled

---

### Step 4 — Build and distribute via App Store / TestFlight

Submit a new build. After installation:

- QR codes generated from `https://<org>.gighive.app/admin/event_qr.php` produce URLs like `https://<org>.gighive.app/upload/<token>`
- When a guest scans the QR code with the iPhone Camera app (with Safari as default browser), the camera banner will read **GigHive** and tapping it will open `GuestUploadView` directly in the app with the correct event pre-loaded
- Guests with Chrome as their default browser will land on the web fallback form — expected and acceptable per the spec

---

### Step 5 — Smoke test on a production (non-developer) device

Use a device with no developer settings enabled to replicate the real guest experience:

1. Install the App Store build
2. Generate a QR code from `https://<org>.gighive.app/admin/event_qr.php`
3. Scan with iPhone Camera — banner must read **GigHive** (not the URL)
4. Tap the banner — `GuestUploadView` must open with the correct event pre-loaded
5. Long-press a `<org>.gighive.app/upload/...` link in Notes or iMessage — **Open in GigHive** must appear as the first menu option

---

### Production readiness checklist

| # | Check | Command / Location |
|---|-------|--------------------|
| 1 | AASA live on org subdomain with `Content-Type: application/json`, HTTP 200, no redirect | `curl -sI https://<org>.gighive.app/.well-known/apple-app-site-association` |
| 2 | Apple CDN seeded for the org subdomain being tested | `curl -s https://app-site-association.cdn-apple.com/a/v1/<org>.gighive.app` |
| 3 | `applinks:*.gighive.app` (no `?mode=developer`) in `GigHive.entitlements` | Read `Configs/GigHive.entitlements` |
| 4 | App Store build signed with correct Team ID `WB7D4FC7XU` and bundle ID `app.gighive.GigHive` | `codesign -d --entitlements -` on the `.ipa` |
| 5 | QR codes generated from production admin page use the org subdomain | Check generated URL shown under the QR image |
| 6 | CDN pre-seeded for org subdomains before live events (generate QR day before) | `curl -s https://app-site-association.cdn-apple.com/a/v1/<org>.gighive.app` |
| 7 | Smoke test on non-developer device passes (Camera banner reads GigHive, tap opens app) | Manual test |

---

---

## Phase 4 — Guest Self-Delete

> **Infrastructure (Steps 1–5) complete and deployed to dev — 2026-07-08. iOS (Step 6) pending.**

**Prerequisite:** Phase 1 and Phase 2 complete and deployed.

**Files added/modified:**

| File | Change |
|---|---|
| `db/create_media_db.sql` | Add `guest_deleted`, `guest_deleted_at` to `upload_jobs` | ✅ |
| `ansible/roles/docker/files/apache/webroot/api/guest-delete.php` | New endpoint | ✅ |
| `ansible/roles/docker/files/apache/webroot/api/guest-gallery.php` | Add `AND j.guest_deleted = 0` to Step 2 WHERE | ✅ |
| `ansible/roles/docker/files/apache/webroot/api/guest-stream.php` | Add `AND j.guest_deleted = 0` to Step 2 WHERE | ✅ |
| `ansible/roles/docker/files/apache/webroot/api/guest-status.php` | Add `AND j2.guest_deleted = 0` to `video_count` subquery | ✅ |
| `ansible/roles/docker/files/apache/webroot/admin/event_qr.php` | Add `guest_deleted`, `guest_deleted_at` to SELECT; add badge in UI | ✅ |
| `ansible/roles/docker/templates/default-ssl.conf.j2` | Add Apache exemption for `/api/guest-delete.php` | ✅ |
| `GigHive/Sources/App/GuestGalleryAPIClient.swift` | Add `deleteVideo()` method | ⏳ pending |
| `GigHive/Sources/App/GuestGalleryView.swift` | Add `deletedIds` state, `xmark` button, `deleteConfirm`/`deleteFeedback` alert cases, `performDelete()` | ⏳ pending |
| `ansible/roles/docker/files/apache/webroot/docs/openapi.yaml` | Add `POST /guest-delete.php` entry to `guest` tag group | ✅ |

---

### Step 1 — Schema migration ✅

**File:** `ansible/roles/docker/files/mysql/create_media_db.sql`

Add immediately after the existing `guest_flagged_at` column:

```sql
guest_deleted     TINYINT(1)  NOT NULL DEFAULT 0
    COMMENT 'Guest self-delete flag; moderation_status unchanged; physical file retained on disk',
guest_deleted_at  DATETIME    NULL,
```

**Migration script** (same Ansible task pattern as existing migrations):

```sql
ALTER TABLE upload_jobs
  ADD COLUMN IF NOT EXISTS guest_deleted    TINYINT(1) NOT NULL DEFAULT 0
      COMMENT 'Guest self-delete flag; moderation_status unchanged; physical file retained on disk',
  ADD COLUMN IF NOT EXISTS guest_deleted_at DATETIME NULL;
```

**Rollback:**

```sql
ALTER TABLE upload_jobs
  DROP COLUMN IF EXISTS guest_deleted,
  DROP COLUMN IF EXISTS guest_deleted_at;
```

No new index needed. `guest_deleted` is a low-cardinality flag (`0`/`1`) and queries filtering it are already constrained by `idx_upload_jobs_moderation` on `moderation_status`.

---

### Step 2 — Apache configuration ✅

**File:** `ansible/roles/docker/templates/default-ssl.conf.j2`

Add immediately after the existing `guest-report.php` exemption block:

```apache
<Location "/api/guest-delete.php">
    AuthMerging Off
    Require all granted
</Location>
```

---

### Step 3 — PHP: update three existing query WHERE clauses ✅

**`api/guest-gallery.php` — Step 2 result query:**

```sql
-- Before:
WHERE t.event_id = ? AND j.moderation_status = 'approved'
-- After:
WHERE t.event_id = ? AND j.moderation_status = 'approved' AND j.guest_deleted = 0
```

> **Do NOT add `guest_deleted = 0` to Step 1** (the access gate). Step 1 verifies the nonce holder's own upload is approved to grant gallery entry. Since `moderation_status` stays `'approved'` after deletion, a guest who deleted their video must still pass Step 1 and retain gallery access. See spec for rationale.

**`api/guest-stream.php` — Step 2 validation query:**

```sql
-- Before:
WHERE j.id = ? AND t.event_id = ? AND j.moderation_status = 'approved'
-- After:
WHERE j.id = ? AND t.event_id = ? AND j.moderation_status = 'approved' AND j.guest_deleted = 0
```

Streaming a guest-deleted video returns `403`. In normal flow the iOS client removes the row from the local list immediately on delete, so this is a defence-in-depth guard.

**`api/guest-status.php` — `video_count` subquery:**

```sql
-- Before:
WHERE t2.event_id = t.event_id AND j2.moderation_status = 'approved'
-- After:
WHERE t2.event_id = t.event_id AND j2.moderation_status = 'approved' AND j2.guest_deleted = 0
```

The "New videos" badge count on `SplashView` excludes guest-deleted videos.

---

### Step 4 — PHP: new file `/api/guest-delete.php` ✅

**File:** `ansible/roles/docker/files/apache/webroot/api/guest-delete.php`

- Method: `POST`; JSON body: `{ "nonce": "…", "upload_job_id": <int> }`
- Apache exempt (Step 2 above); no admin session required

```php
<?php
header('Cache-Control: no-store');
header('Content-Type: application/json');

$body        = json_decode(file_get_contents('php://input'), true) ?? [];
$nonce       = (string)($body['nonce']         ?? '');
$uploadJobId = (int)   ($body['upload_job_id'] ?? 0);

if (!preg_match('/^[A-Za-z0-9_\-]{30,40}$/', $nonce) || $uploadJobId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Bad Request']);
    exit;
}

// Step 1: validate nonce (own upload approved) — same access gate as guest-gallery.php Step 1
// Gallery expiry intentionally NOT checked: guests may delete their own video at any time.
$stmt = $pdo->prepare(
    'SELECT t.event_id
     FROM anon_upload_attributions a
     JOIN upload_jobs j ON j.job_id = a.upload_job_id
     JOIN event_upload_tokens t ON t.token_id = a.token_id
     WHERE a.status_nonce = ? AND j.moderation_status = \'approved\''
);
$stmt->execute([$nonce]);
if (!$stmt->fetch()) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

// Step 2: soft-delete — nonce must own the target upload_job_id (upload_jobs.id INT)
// guest_deleted_at = NOW() unconditionally (updates on repeat calls, like guest_flagged_at).
// This ensures rowCount() = 1 whenever the nonce owns the row, making the endpoint idempotent.
$stmt = $pdo->prepare(
    'UPDATE upload_jobs j
     JOIN anon_upload_attributions a ON a.upload_job_id = j.job_id
     SET j.guest_deleted = 1, j.guest_deleted_at = NOW()
     WHERE j.id = ? AND a.status_nonce = ?'
);
$stmt->execute([$uploadJobId, $nonce]);
if ($stmt->rowCount() === 0) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

echo json_encode(['status' => 'deleted']);
```

**Response contract:**

| Status | Body |
|---|---|
| `200` | `{ "status": "deleted" }` |
| `400` | `{ "error": "Bad Request" }` — missing/malformed nonce or non-positive job ID |
| `403` | `{ "error": "Forbidden" }` — unknown nonce, nonce not approved, or nonce trying to delete another guest's video |

---

### Step 4b — OpenAPI: add `POST /guest-delete.php` to Swagger doc ✅

**File:** `ansible/roles/docker/files/apache/webroot/docs/openapi.yaml`

Add a new path entry in the `guest` tag group, immediately after `/guest-report.php`:

```yaml
/guest-delete.php:
  post:
    tags:
      - guest
    summary: 'Soft-delete the current guest''s own uploaded video'
    description: 'Sets guest_deleted=1; moderation_status unchanged so gallery access is preserved. Idempotent.'
    operationId: deleteGuestVideo
    requestBody:
      required: true
      content:
        application/json:
          schema:
            required: [nonce, upload_job_id]
            properties:
              nonce:
                type: string
                minLength: 30
                maxLength: 40
                pattern: '^[A-Za-z0-9_\-]+$'
              upload_job_id:
                description: 'upload_jobs.id (integer PK) of the guest''s own upload'
                type: integer
                minimum: 1
            type: object
    responses:
      '200':
        description: 'Soft-deleted successfully'
        content:
          application/json:
            schema:
              properties:
                status: { type: string, enum: [deleted] }
              type: object
      '400':
        description: 'Invalid request body'
      '403':
        description: 'Forbidden — nonce not approved or attempting to delete another guest''s video'
    servers:
      - url: /api
```

---

### Step 5 — PHP: `admin/event_qr.php` — show deleted badge ✅

**File:** `ansible/roles/docker/files/apache/webroot/admin/event_qr.php`

**In the `$guestUploads` SELECT**, add two columns to the existing column list:

```sql
j.guest_deleted, j.guest_deleted_at,
```

**In the moderation queue HTML**, after the existing `guest_flagged` badge logic, add:

```php
<?php if (!empty($gu['guest_deleted'])): ?>
  <br><span style="color:#9ca3af;font-size:.78rem">🗑 Deleted by guest
    (<?= htmlspecialchars(
        $gu['guest_deleted_at']
          ? date('M j g:ia', strtotime($gu['guest_deleted_at']))
          : '—',
        ENT_QUOTES
    ) ?>)
  </span>
<?php endif; ?>
```

No admin un-delete button in MVP. The physical file remains on disk; restoring requires a direct DB update.

---

### Step 6 — iOS: `GuestGalleryView` and `GuestGalleryAPIClient` ⏳ pending

#### 6a — `GuestGalleryAPIClient.swift`: add `deleteVideo`

**File:** `GigHive/Sources/App/GuestGalleryAPIClient.swift`

Add after `reportVideo`:

```swift
func deleteVideo(nonce: String, uploadJobId: Int) async throws {
    let url = baseURL
        .appendingPathComponent("api")
        .appendingPathComponent("guest-delete.php")
    var request = URLRequest(url: url)
    request.httpMethod = "POST"
    request.timeoutInterval = 10
    request.setValue("application/json", forHTTPHeaderField: "Content-Type")
    request.httpBody = try JSONSerialization.data(withJSONObject: [
        "nonce": nonce,
        "upload_job_id": uploadJobId
    ])
    let (_, response) = try await URLSession.shared.data(for: request)
    let code = (response as? HTTPURLResponse)?.statusCode ?? -1
    if code == 403 { throw GuestGalleryError.accessDenied }
    guard code == 200 else { throw GuestGalleryError.badServer(code) }
}
```

#### 6b — `GuestGalleryView.swift`: `GalleryAlert` enum

Add two new cases:

```swift
private enum GalleryAlert {
    case reportConfirm(GuestGalleryVideo)
    case reportFeedback(String)
    case deleteConfirm(GuestGalleryVideo)   // NEW
    case deleteFeedback(String)             // NEW
    case error(String)
}
```

#### 6c — `GuestGalleryView.swift`: `deletedIds` state

Add after `reportedIds`:

```swift
@State private var deletedIds: Set<Int> = []
```

#### 6d — `GuestGalleryView.swift`: `xmark` delete button in `ForEach` row

Insert immediately after the closing `}` of the existing `flag` `Button` (line 144), still inside the row `HStack`:

```swift
if video.uploadJobId == record.uploadJobId {
    Button {
        activeAlert = .deleteConfirm(video)
    } label: {
        Image(systemName: "xmark")
            .font(.title3)
            .foregroundColor(.red)
    }
}
```

**Update the `ForEach` to filter deleted rows:**

```swift
// Before:
ForEach(resp.videos) { video in
// After:
ForEach(resp.videos.filter { !deletedIds.contains($0.uploadJobId) }) { video in
```

`GuestGalleryVideo` is `Identifiable` via `var id: Int { uploadJobId }` — the filtered array works with `ForEach` without a key path.

#### 6e — `GuestGalleryView.swift`: `makeAlert()` new cases

Add to the `switch activeAlert` in `makeAlert()`:

```swift
case .deleteConfirm(let video):
    return Alert(
        title: Text("Delete your video?"),
        message: Text("This removes your clip from the gallery. You'll still have access to view other videos."),
        primaryButton: .destructive(Text("Delete")) {
            Task { await performDelete(video: video) }
        },
        secondaryButton: .cancel()
    )
case .deleteFeedback(let msg):
    return Alert(
        title: Text("Video removed"),
        message: Text(msg),
        dismissButton: .default(Text("OK"))
    )
```

#### 6f — `GuestGalleryView.swift`: `performDelete` function

Add after `submitReport`:

```swift
@MainActor
private func performDelete(video: GuestGalleryVideo) async {
    guard let baseURL = URL(string: record.baseURLString) else { return }
    do {
        try await GuestGalleryAPIClient(baseURL: baseURL).deleteVideo(
            nonce: record.statusNonce,
            uploadJobId: video.uploadJobId
        )
        deletedIds.insert(video.uploadJobId)
        activeAlert = .deleteFeedback("Your video has been removed from the gallery.")
    } catch {
        activeAlert = .error(error.localizedDescription)
    }
}
```

---

### Step 7 — Smoke tests ⏳ pending

```bash
# 1. Missing body / malformed nonce → 400
curl -s -o /dev/null -w "%{http_code}" -X POST \
  https://devvm.gighive.internal/api/guest-delete.php \
  -H 'Content-Type: application/json' -d '{}'
# expect: 400

# 2. Unknown nonce → 403
curl -s -o /dev/null -w "%{http_code}" -X POST \
  https://devvm.gighive.internal/api/guest-delete.php \
  -H 'Content-Type: application/json' \
  -d '{"nonce":"AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA","upload_job_id":1}'
# expect: 403

# 3. Nonce trying to delete someone else's upload_job_id → 403
# (use real nonce from guest A; pass upload_job_id of guest B's row)
# expect: 403

# 4. Valid nonce + own upload_job_id → 200; guest_deleted = 1 in DB
curl -s -X POST https://devvm.gighive.internal/api/guest-delete.php \
  -H 'Content-Type: application/json' \
  -d '{"nonce":"<validNonce>","upload_job_id":<ownId>}'
# expect: {"status":"deleted"}
# verify: SELECT guest_deleted, guest_deleted_at FROM upload_jobs WHERE id = <ownId>
# expect: guest_deleted=1, guest_deleted_at IS NOT NULL

# 5. Repeat call (idempotency) → 200
# same request as above
# expect: {"status":"deleted"}  (guest_deleted_at timestamp updated)

# 6. guest-gallery — deleted video excluded from results
curl -s "https://devvm.gighive.internal/api/guest-gallery.php?nonce=<validNonce>"
# expect: videos array does NOT contain the deleted upload_job_id
# expect: status = "approved" (gallery access retained)

# 7. guest-status — video_count excludes deleted video
curl -s "https://devvm.gighive.internal/api/guest-status.php?nonce=<validNonce>"
# expect: video_count decremented by 1

# 8. guest-stream — streaming deleted video → 403
curl -s -o /dev/null -w "%{http_code}" \
  "https://devvm.gighive.internal/api/guest-stream.php?nonce=<validNonce>&job_id=<deletedId>"
# expect: 403
```
