# Refactor: iPhone Guest Video Report Flag Retraction

## Status — 2026-07-18

**Planning only — not implemented.**

Update this status line and date when implementation begins (`In progress — Phase N`) and when Phase 3 verification passes (`Complete — <date>`).

This document captures the current recommended design for allowing a guest to:
- flag a video as inappropriate
- tap the filled flag again to retract that report
- do so without removing reports submitted by other guests for the same video

No implementation work should begin from this document without explicit user approval.

---

## Rationale

The current guest gallery report flow is one-way:
- the iPhone app can submit a report
- the UI locally switches the row to a filled flag state
- there is no supported server-side path to retract the report

That creates two problems:

1. **Bad UX for accidental taps.**
   A guest who taps the flag by mistake has no way to correct the action.

2. **Incorrect data model for retraction.**
   The current backend stores only a coarse aggregate bit on `upload_jobs`:
   - `guest_flagged`
   - `guest_flagged_at`

   That model can represent that *someone* flagged the clip, but it cannot answer:
   - which guest flagged it
   - whether the current guest has flagged it
   - whether one guest retracting should leave the clip flagged because other guests also reported it

A retractable report flow therefore requires a refactor of the moderation signal from a single boolean into a per-guest report record model, while preserving the existing aggregate admin-facing badge behavior.

---

## Goal

Allow a guest in `GuestGalleryView` to toggle the report flag on and off for a video, with the following policy:

**A guest may retract only their own report.**

That means:
- tapping an unfilled flag submits a report for that guest
- tapping a filled flag retracts that guest's report
- if other guests have also reported the same video, the organizer should still see that video as guest-flagged
- the app should render the flag state from server truth, not only from local in-memory state

---

## Industry Precedent

Most consumer media products treat abuse/inappropriate-content reports as **actor-specific actions** rather than a single global toggle on the content row.

Examples in principle:
- a user can report a post and later undo or withdraw that report
- the moderation system retains independent reports from other users
- admin dashboards usually show aggregate signal such as “reported” or “reported N times”

The current `upload_jobs.guest_flagged` boolean is useful as an aggregate badge but is not sufficient as the primary source of truth for user-specific report state.

---

## Decision

Refactor the guest-report system to use **per-guest report records** in the backend, while keeping `upload_jobs.guest_flagged` as a derived aggregate indicator for existing admin UI compatibility.

Server behavior should become:
- `reported: true` → insert a per-guest report row (insert-or-ignore if one already exists)
- `reported: false` → delete that guest's report row
- after either operation, recompute the aggregate `upload_jobs.guest_flagged` / `guest_flagged_at` from remaining per-guest report rows for that video

Client behavior should become:
- gallery payload includes `reported_by_me` per video
- `GuestGalleryView` initializes flag state from `reported_by_me`
- tapping flag toggles server state on/off
- success feedback text changes depending on whether the action was report or retract

---

## Real World Use Cases

### Scenario 1 — Accidental flag tap

**Morgan** is browsing the gallery and accidentally taps the orange flag while trying to scroll.

| | Before | After |
|---|---|---|
| What happens | Report is submitted and cannot be undone | Flag fills, then Morgan taps again and retracts it |
| Organizer signal | False-positive report remains | False-positive report is removed |
| User confidence | Low — app feels unforgiving | Higher — mistakes are reversible |

### Scenario 2 — Two guests report the same bad clip

**Avery** and **Taylor** both flag the same clip as inappropriate.

| | Before | After |
|---|---|---|
| Stored state | One shared boolean, no per-guest identity | Two independent report rows |
| Avery retracts | Not possible | Avery's row removed; Taylor's row remains |
| Organizer signal | Clip shows guest-flagged (but Avery cannot retract) | Clip remains guest-flagged because Taylor still reported it |

### Scenario 3 — App reload / second device session

**Jordan** flags a video, leaves the gallery, and later returns.

| | Before | After |
|---|---|---|
| Source of flag UI | Local in-memory `reportedIds` only | Server-provided `reported_by_me` |
| Reload behavior | At risk of drift | Stable and authoritative |

---

## Design Principles

- Server truth must drive whether a video is flagged by the current guest.
- Retraction must be scoped to the current guest only.
- Existing organizer/admin workflows should continue to work without requiring an immediate admin UI redesign.
- Hash raw guest credentials before DB storage or lookup when using them as identity material.
- Keep iPhone changes iOS 14-compatible.
- Prefer a rollback-safe backend transition: add the per-guest table first, then switch APIs/UI to consume it.

---

## Current State

### iPhone app (`gighiveapp`)

`GigHive/Sources/App/GuestGalleryView.swift`
- keeps `reportedIds: Set<Int>` only in view state
- tapping the flag always routes through `.reportConfirm(video)`
- successful report inserts the `uploadJobId` into the local `reportedIds` set
- there is no unreport path

`GigHive/Sources/App/GuestGalleryAPIClient.swift`
- exposes `reportVideo(nonce:uploadJobId:)`
- sends only `nonce` and `upload_job_id` to `/api/guest-report.php`
- has no parameter for “reported true/false”

`GigHive/Sources/App/GuestGalleryAPIClient.swift` model layer
- `GuestGalleryVideo` does not include any `reported_by_me` field from the server

### Backend (`gighiveinfra`)

`ansible/roles/docker/files/apache/webroot/api/guest-report.php`
- validates nonce
- resolves `event_id`
- sets `upload_jobs.guest_flagged = 1` and `guest_flagged_at = NOW()` on the target row
- does not track which guest made the report
- does not support retracting a report

`ansible/roles/docker/files/apache/webroot/api/guest-gallery.php`
- returns the gallery video list
- does not return current-guest report state

`ansible/roles/docker/files/apache/webroot/guest_event_view.php`
- mirrors the same one-way report behavior for the web fallback page

### Schema limitation

`upload_jobs` currently contains only aggregate moderation-report fields:
- `guest_flagged`
- `guest_flagged_at`

Those fields are insufficient to support reversible, guest-scoped report state safely.

---

## Proposed Implementation

### Prerequisites and Deployment Order

Phases must be executed in sequence:

1. Run the DDL (Phase 1 Step 1) on the target environment first.
2. Deploy Phase 1 PHP changes (Steps 2–6) and verify `reported_by_me` appears in the gallery response; commit Step 7 (`create_media_db.sql`) in the same cycle.
3. Release the Phase 2 iPhone build. **Submit to App Store only after the backend is verified in staging** — review typically takes 1–7 days and the backward-compat `reported` default is load-bearing for the entire review window.

Do not deploy PHP changes before the DDL is applied. Do not release the iPhone build before the backend supports `reported_by_me`.

### Phase 1 — Infrastructure / Backend (`gighiveinfra`)

#### Checklist

- [x] **Step 1 — Run DDL: create `guest_video_reports` table**
  - Must run before deploying PHP code that requires the new table.
- [x] **Step 2 — Edit `ansible/roles/docker/files/apache/webroot/api/guest-gallery.php`**
  - Return `reported_by_me` for each video.
- [x] **Step 3 — Edit `ansible/roles/docker/files/apache/webroot/api/guest-report.php`**
  - Accept report/retract toggle, persist per-guest rows, and recompute aggregate compatibility fields.
- [x] **Step 4 — Edit `ansible/roles/docker/files/apache/webroot/src/OpenApi.php`**
  - Update API annotations for `reported_by_me` and the toggle request body.
- [x] **Step 5 — Edit `ansible/roles/docker/files/apache/webroot/docs/openapi.yaml`**
  - Mirror the OpenAPI contract changes.
- [x] **Step 6 — Edit `ansible/roles/shared_gallery/tasks/main.yml`**
  - Extend smoke coverage for the updated endpoint contract.
- [x] **Step 7 — Edit `ansible/roles/docker/files/mysql/externalConfigs/create_media_db.sql`**
  - Add the `guest_video_reports` CREATE TABLE so fresh environment deploys stay in sync with the live schema.

#### Step 1 — Run DDL: create `guest_video_reports` table

##### Database design

Add a new table such as `guest_video_reports` with one row per:
- reported video
- reporting guest credential hash

Recommended columns:
- `report_id` bigint unsigned PK
- `event_id` int not null
- `upload_job_id` int unsigned not null referencing `upload_jobs.id`
- `reporter_credential_hash` char(64) not null
- `created_at` datetime not null default current timestamp
- `updated_at` datetime not null default current timestamp on update current timestamp

Recommended constraints/indexes:
- unique `(upload_job_id, reporter_credential_hash)`
- index on `event_id`
- index on `upload_job_id`

##### DDL command (for implementation phase only)

**Prerequisite:** run this DDL before deploying PHP code that requires the new table in environments beyond dev, unless the deployment is explicitly staged so the new code tolerates the table being temporarily absent.

**Before running — verify column types and collation (MySQL 8.4):**
- Confirm `events.event_id` is `INT` (not `INT UNSIGNED`) and `upload_jobs.id` is `INT UNSIGNED`; a signed/unsigned mismatch will cause FK creation to fail at DDL time.
- Verify existing table collation: MySQL 8.x defaults to `utf8mb4_0900_ai_ci`, not `utf8mb4_unicode_ci`. If the database was initialized under MySQL 8.x, update the `COLLATE` clause in the DDL below to match; a mismatch can produce collation warnings on future JOINs.

```bash
docker exec -i mysqlServer bash -c 'mysql -u root -p"$MYSQL_ROOT_PASSWORD" media_db -e "
CREATE TABLE IF NOT EXISTS guest_video_reports (
  report_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  event_id INT NOT NULL,
  upload_job_id INT UNSIGNED NOT NULL,
  reporter_credential_hash CHAR(64) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (report_id),
  UNIQUE KEY uq_guest_video_reports_reporter (upload_job_id, reporter_credential_hash),
  KEY idx_guest_video_reports_event (event_id),
  KEY idx_guest_video_reports_upload_job (upload_job_id),
  CONSTRAINT fk_gvr_event FOREIGN KEY (event_id) REFERENCES events (event_id) ON DELETE CASCADE,
  CONSTRAINT fk_gvr_upload_job FOREIGN KEY (upload_job_id) REFERENCES upload_jobs (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Make guest_flagged_at nullable so the aggregate recompute can set it
-- to NULL when all reports for a video are retracted. Required in
-- MySQL 8.4 strict mode. No-op if already DATETIME NULL.
-- If the column has a DEFAULT or COMMENT on the live table, include
-- those attributes in the MODIFY to avoid stripping them.
ALTER TABLE upload_jobs MODIFY COLUMN guest_flagged_at DATETIME NULL;
"'
```

#### Step 2 — Edit `ansible/roles/docker/files/apache/webroot/api/guest-gallery.php`

##### `GET /api/guest-gallery.php`

Extend each video object with:

```json
{
  "upload_job_id": 42,
  "label": "First dance",
  "stream_url": "/api/guest-stream.php?...",
  "display_name": "Sarah",
  "approved_at": "2026-07-18 15:00:00",
  "reported_by_me": true
}
```

Implementation note:
- derive `reporter_credential_hash = hash('sha256', $nonce)` from the incoming credential
- the incoming credential may be either a `status_nonce` or a raw QR upload token, so the column name and logic must not assume nonce-only identity
- left join or `EXISTS` against `guest_video_reports`
- return `reported_by_me` as a boolean-like JSON field
- avoid re-implementing credential parsing and validation ad hoc in each endpoint; prefer one shared helper that validates the credential, resolves `event_id`, and returns a normalized auth context used by both `guest-gallery.php` and `guest-report.php`
- **Correctness requirement, not just style:** if the two endpoints hash the credential differently (e.g., different trim, encoding, or input source), the LEFT JOIN will never match and `reported_by_me` will silently always be `false` — a bug that produces no error and is very hard to diagnose. The shared helper is the only reliable guarantee of bit-identical hashing.
- `reported_by_me` must be `false` (not null, not an error) when no matching rows exist for this guest — use `LEFT JOIN` or `EXISTS` with `COALESCE(..., false)` so the gallery still loads cleanly regardless of report history

SQL sketch (extend the existing gallery SELECT):

```sql
-- Add to SELECT list:
(gvr.report_id IS NOT NULL) AS reported_by_me

-- Add to FROM/JOIN chain:
LEFT JOIN guest_video_reports gvr
  ON  gvr.upload_job_id        = uj.id
  AND gvr.reporter_credential_hash = :hash   -- scoped to current guest
  AND gvr.event_id             = :event_id   -- defense-in-depth event scope

-- In PHP: cast before JSON-encoding: (bool)$row['reported_by_me']
```

#### Step 3 — Edit `ansible/roles/docker/files/apache/webroot/api/guest-report.php`

##### `POST /api/guest-report.php`

Evolve request body from:

```json
{ "nonce": "...", "upload_job_id": 42 }
```

to:

```json
{ "nonce": "...", "upload_job_id": 42, "reported": true }
```

Rules:
- `reported: true` → ensure the current guest has a report row for this video
- `reported: false` → remove only the current guest's row for this video
- if `reported: false` and no matching row exists for this guest, treat as idempotent: continue through the aggregate recompute and return `{ "success": true, "reported_by_me": false }` without error
- still validate:
  - nonce format
  - nonce/token belongs to same event
  - target upload is approved
  - target upload belongs to same event
- if `reported` is absent from the request body, return HTTP 400; do not silently default, as this hides client bugs. Exception: if backward compatibility with pre-upgrade clients is required, default `reported` to `true`.
- **`guest_event_view.php` impact:** the web fallback page currently POSTs without a `reported` field. If HTTP 400 is enforced, the web fallback report button breaks immediately on deploy, before web fallback parity is implemented. Recommended: default `reported` to `true` for the first ship and tighten to required once web fallback parity is complete.
- **Pre-existing regex bug — one-line side fix:** `guest-delete.php` line 15 uses nonce regex `{30,40}` while this file and `guest-gallery.php` use `{30,43}`. Fix `guest-delete.php` line 15 (`{30,40}` → `{30,43}`) as a one-line change alongside this step. A permanent fix via shared helper is tracked in `docs/refactor_iphone_qr_code_guest_nonce_shared_helper.md`.

Recommended response body:

```json
{ "success": true, "reported_by_me": true }
```

or

```json
{ "success": true, "reported_by_me": false }
```

##### Aggregate compatibility behavior

Keep `upload_jobs.guest_flagged` and `guest_flagged_at` as derived compatibility fields for now.

After each report/unreport action:
- if at least one `guest_video_reports` row remains for that `upload_job_id`, set:
  - `guest_flagged = 1`
  - `guest_flagged_at = MAX(created_at)` across remaining report rows for that `upload_job_id` (use `created_at`, not `updated_at` — this table is insert/delete only; `updated_at` never changes after insert)
- if zero rows remain, set:
  - `guest_flagged = 0`
  - `guest_flagged_at = NULL`

This preserves current admin UI semantics in `admin/event_qr.php` without requiring that page to understand the new table immediately.

**Legacy flag gap on deploy day:** existing videos with `guest_flagged = 1` have no rows in `guest_video_reports`. Legacy flags persist untouched until the first retract on such a video, at which point the recompute runs, finds zero rows, and sets `guest_flagged = 0` — silently removing a real organizer signal. Decision required before ship: accept this gap (legacy flags decay naturally on first interaction) or run a one-time backfill. See Follow-on Work.

##### Transaction / consistency recommendation

`guest-report.php` should validate credential and event scope first, then wrap only the write and recompute in a transaction:

1. Validate credential, resolve `event_id`, verify target video belongs to same event (outside transaction — do not hold locks during auth).
2. Begin transaction.
3. Write the guest report row:
   - `reported: true` — insert if not already present:
     ```sql
     INSERT INTO guest_video_reports
       (event_id, upload_job_id, reporter_credential_hash)
     VALUES (:event_id, :upload_job_id, :hash)
     ON DUPLICATE KEY UPDATE updated_at = updated_at;
     -- Do NOT use INSERT IGNORE: it silently swallows genuine FK
     -- violations. ON DUPLICATE KEY UPDATE handles only duplicate
     -- key conflicts and lets other errors propagate correctly.
     ```
   - `reported: false` — remove only this guest's row:
     ```sql
     DELETE FROM guest_video_reports
     WHERE upload_job_id        = :upload_job_id
       AND reporter_credential_hash = :hash
       AND event_id             = :event_id;
     ```
4. Count remaining rows for this video:
   ```sql
   SELECT COUNT(*) AS remaining, MAX(created_at) AS latest_at
   FROM guest_video_reports
   WHERE upload_job_id = :upload_job_id;
   ```
5. Update aggregate compatibility fields:
   ```sql
   UPDATE upload_jobs
   SET guest_flagged    = IF(:remaining > 0, 1, 0),
       guest_flagged_at = IF(:remaining > 0, :latest_at, NULL)
   WHERE id = :upload_job_id;
   ```
6. Commit.

This avoids holding locks open during validation and prevents race-condition windows where the aggregate flag could drift from the per-guest table.

#### Step 4 — Edit `ansible/roles/docker/files/apache/webroot/src/OpenApi.php`

Follow `docs/process_api_swagger_generation.md`. All guest API annotations live in `src/OpenApi.php` as phantom route annotations. Update:
- `GuestVideo` schema — add `reported_by_me: boolean` property
- `guest-report.php` operation — update description; add `reported: boolean` to request body; add response schema with `success: boolean` and `reported_by_me: boolean`

#### Step 5 — Regenerate `ansible/roles/docker/files/apache/webroot/docs/openapi.yaml`

`openapi.yaml` is a **pre-generated artifact** — do not edit it manually. After Step 4, regenerate it per `docs/process_api_swagger_generation.md`:

```bash
cd ~/gighive/ansible/roles/docker/files/apache/webroot && composer openapi
```

Commit the updated `docs/openapi.yaml` alongside the `src/OpenApi.php` changes.

#### Step 6 — Edit `ansible/roles/shared_gallery/tasks/main.yml`

- Extend smoke coverage for:
  - report request with `reported: true`
  - retract request with `reported: false`
  - gallery response containing `reported_by_me`
- **Fixture prerequisites:** tests require a valid event, a nonce scoped to that event, and an approved video. Verify existing fixtures cover this or add setup tasks before the new assertions.
- **Idempotency:** the test sequence must be self-cleaning — report → assert row exists → retract → assert row removed. A smoke test that leaves a `guest_video_reports` row behind will cause false failures on re-run.

#### Step 7 — Edit `ansible/roles/docker/files/mysql/externalConfigs/create_media_db.sql`

Add the `guest_video_reports` table definition at the end of the file (after `anon_upload_attributions`) so fresh environment deploys — dev resets, lab, and staging — automatically include the new table.

**Schema file notes (verified from file):**
- `events.event_id` is `INT` (not UNSIGNED) — Step 1 FK is correct as-is.
- `upload_jobs.id` is `INT UNSIGNED` — Step 1 FK is correct as-is.
- `upload_jobs.guest_flagged_at` is already `DATETIME NULL` in this file — no ALTER TABLE needed here; the `ALTER TABLE` in Step 1’s DDL command is only a safety net for live environments created before this column was nullable.

Block to append:

```sql
/****************************
 * Guest Video Reports      *
 ****************************/
CREATE TABLE IF NOT EXISTS guest_video_reports (
  report_id                bigint unsigned NOT NULL AUTO_INCREMENT,
  event_id                 INT             NOT NULL,
  upload_job_id            INT UNSIGNED    NOT NULL,
  reporter_credential_hash CHAR(64)        NOT NULL,
  created_at               datetime        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at               datetime        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (report_id),
  UNIQUE KEY uq_guest_video_reports_reporter (upload_job_id, reporter_credential_hash),
  KEY idx_guest_video_reports_event      (event_id),
  KEY idx_guest_video_reports_upload_job (upload_job_id),
  CONSTRAINT fk_gvr_event      FOREIGN KEY (event_id)      REFERENCES events      (event_id) ON DELETE CASCADE,
  CONSTRAINT fk_gvr_upload_job FOREIGN KEY (upload_job_id) REFERENCES upload_jobs (id)       ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

#### Rollback

If the new PHP must be reverted after deploy:
- redeploy the previous PHP versions of `guest-gallery.php` and `guest-report.php`
- `guest_video_reports` rows are orphaned but harmless; `upload_jobs.guest_flagged` continues to reflect the last computed aggregate state
- the `guest_video_reports` table can remain in place — it does not affect the old code path
- no iPhone app rollback is required; gallery `reportedByMe` uses `decodeIfPresent ?? false` so a missing field defaults to `false` without throwing; report response `reportedByMe` is `Bool?` with a fallback to the locally-requested value so report taps degrade gracefully — note: previously-reported flag states will silently revert to unset in the gallery UI until the new backend is redeployed

### Test Methodology — Phase 1, Steps 1–3

Run these after deploying Phase 1 PHP changes via Ansible playbook. Requires a valid nonce from an event with at least one approved video.

**Prerequisites:**
- DDL (Step 1) applied to the target environment **and** Step 7 (`create_media_db.sql`) committed — otherwise `rebuild_mysql_data: true` in `group_vars` will drop the table on the next Ansible run.
- Use `-k` with all curl commands on dev/lab (self-signed cert).
- Find a nonce that has at least one approved video:
  ```bash
  docker exec -it mysqlServer bash -lc 'mysql -u root -p"$MYSQL_ROOT_PASSWORD" media_db -e "
  SELECT j.id, j.label, j.moderation_status, t.event_id
  FROM upload_jobs j
  JOIN anon_upload_attributions a ON a.upload_job_id = j.job_id
  JOIN event_upload_tokens t ON t.token_id = a.token_id
  WHERE t.token_hash = SHA2('\''YOUR_NONCE'\'', 256)
  ORDER BY j.started_at DESC;
  "'
  ```

**Step 1 — Baseline gallery (all `reported_by_me` should be `false`)**
```bash
curl -sk "https://devvm.gighive.internal/api/guest-gallery.php?nonce=YOUR_NONCE" \
  | python3 -m json.tool
```
✅ Each video object includes `"reported_by_me": false`.

**Step 2 — Report a video**
```bash
curl -sk -X POST "https://devvm.gighive.internal/api/guest-report.php" \
  -H "Content-Type: application/json" \
  -d '{"nonce":"YOUR_NONCE","upload_job_id":YOUR_JOB_ID,"reported":true}' \
  | python3 -m json.tool
```
✅ Response: `{"success": true, "reported_by_me": true}`

**Step 3 — Gallery reflects the report**
```bash
curl -sk "https://devvm.gighive.internal/api/guest-gallery.php?nonce=YOUR_NONCE" \
  | python3 -m json.tool
```
✅ Target video shows `"reported_by_me": true`; all others remain `false`.

**Step 4 — Verify aggregate in DB**
```bash
docker exec -it mysqlServer bash -lc 'mysql -u root -p"$MYSQL_ROOT_PASSWORD" media_db \
  -e "SELECT id, guest_flagged, guest_flagged_at FROM upload_jobs WHERE id = YOUR_JOB_ID;"'
```
✅ `guest_flagged = 1`, `guest_flagged_at` non-NULL.

**Step 5 — Retract the report**
```bash
curl -sk -X POST "https://devvm.gighive.internal/api/guest-report.php" \
  -H "Content-Type: application/json" \
  -d '{"nonce":"YOUR_NONCE","upload_job_id":YOUR_JOB_ID,"reported":false}' \
  | python3 -m json.tool
```
✅ Response: `{"success": true, "reported_by_me": false}`

**Step 6 — Gallery clears the flag**
```bash
curl -sk "https://devvm.gighive.internal/api/guest-gallery.php?nonce=YOUR_NONCE" \
  | python3 -m json.tool
```
✅ All videos show `"reported_by_me": false`.

**Step 7 — Verify aggregate cleared in DB**
```bash
docker exec -it mysqlServer bash -lc 'mysql -u root -p"$MYSQL_ROOT_PASSWORD" media_db \
  -e "SELECT id, guest_flagged, guest_flagged_at FROM upload_jobs WHERE id = YOUR_JOB_ID;"'
```
✅ `guest_flagged = 0`, `guest_flagged_at = NULL`.

---

### Phase 2 — iPhone App (`gighiveapp`)

#### Checklist

- [x] **Step 1 — Edit `GigHive/Sources/App/GuestGalleryAPIClient.swift`**
  - Decode `reportedByMe` and send `reported` in the report request body.
- [x] **Step 2 — Edit `GigHive/Sources/App/GuestGalleryView.swift`**
  - Render flag state from server-backed data and support both report and retract flows.

#### Step 1 — Edit `GigHive/Sources/App/GuestGalleryAPIClient.swift`

Change `GuestGalleryVideo` to decode:
- `reportedByMe: Bool` — decode with a default of `false` so responses from a pre-deploy backend (missing the field) do not crash the decoder. Use `decodeIfPresent` in a custom `init(from:)` on `GuestGalleryVideo`:
  ```swift
  reportedByMe = try container.decodeIfPresent(Bool.self, forKey: .reportedByMe) ?? false
  ```
  Do not rely on `let reportedByMe: Bool` alone — a non-optional `Bool` without a custom decoder throws `DecodingError.keyNotFound` when the key is absent.

Add `reported` to the report request body, for example:

```swift
func setVideoReported(nonce: String, uploadJobId: Int, reported: Bool) async throws -> Bool
```

**Code reuse:** `setVideoReported` replaces `reportVideo` entirely — use the `deleteVideo` method body (lines 129–145 of `GuestGalleryAPIClient.swift`) as the template; same URL construction, POST, JSON body, `Content-Type` header, and error handling. Add `"reported": reported` to the body dict and decode `ReportResponse` from the response `data`. Remove `reportVideo` and update all call sites in `GuestGalleryView` to `setVideoReported`.

**Extending `GuestGalleryVideo`:** add `let reportedByMe: Bool` property and `case reportedByMe = "reported_by_me"` to the existing `CodingKeys` enum, then implement `init(from:)` for the full struct — use `decodeIfPresent` for `reportedByMe` and `decode` for all existing keys.

The return value should be the server's `reported_by_me` from the response body, not the locally-requested `reported` value. This ensures the caller always applies server truth rather than assuming the toggle succeeded with the requested state.

Define a private response struct inside `GuestGalleryAPIClient` to decode the report endpoint response:

```swift
private struct ReportResponse: Decodable {
    let reportedByMe: Bool?   // optional: nil if key absent (e.g. during a PHP rollback)
}
```

In `setVideoReported`, return `response.reportedByMe ?? reported` so a missing `reported_by_me` key silently falls back to the locally-requested value rather than throwing.

#### Step 2 — Edit `GigHive/Sources/App/GuestGalleryView.swift`

Replace purely local report state initialization with server-backed state from `galleryResponse.videos`.

Recommended approach:
- on each successful `loadGallery`, rebuild `reportedIds` from videos where `reportedByMe == true`
- after a successful `setVideoReported` call, immediately update `reportedIds` from the response's `reported_by_me` return value — do not wait for the next `loadGallery` poll
- tapping flag when not currently reported → show confirm alert to report
- tapping filled flag when currently reported → show confirm alert to retract
- success message depends on action:
  - report: “Thank you. The event organizer will review your report.”
  - retract: “Your report has been removed.”

Alert state enum changes required:
- add a `.retractConfirm(video)` case alongside the existing `.reportConfirm(video)` case in the alert state enum
- the filled-flag tap path must route to `.retractConfirm`, not `.reportConfirm`
- keep alert bodies, buttons, and actions separate — do not reuse the same alert for both flows

`reportedIds` persistence: must remain purely transient, in-memory state derived from server data on each `loadGallery`. Do not store it in `GuestUploadRecord` or `UserDefaults` — per the event-scoped persistence design principle, any persisted local report state would be scoped to a nonce and become stale after rotation.

**First-launch transition note:** on first `loadGallery` after an app update, any videos the guest previously flagged under the old client will appear unflagged — `reported_by_me` is `false` for all videos until the guest interacts with the new report flow (assuming no backend backfill). This is correct behavior (server truth is now authoritative) but is a visible UX change for returning guests with prior reports.

#### iOS 14 compatibility notes

This refactor can stay fully iOS 14-compatible:
- continue using `.alert`
- no `confirmationDialog`
- no iOS 15+ async view modifiers required

### Phase 3 — Validation and Rollout

#### Checklist

- [ ] **Step 1 — Run dev verification**
- [ ] **Step 2 — Confirm aggregate admin badge compatibility**
- [ ] **Step 3 — Schedule follow-on work**

#### Step 1 — Dev verification

Verify at minimum:
- a guest can report a video and sees the filled flag state
- the same guest can retract their own report
- after reload, the flag state is driven by server truth via `reported_by_me`
- if two guests report the same video, one guest retracting leaves the aggregate guest-flagged state in place

#### Step 2 — Aggregate compatibility check

Confirm that existing organizer/admin behavior in `admin/event_qr.php` remains correct while `upload_jobs.guest_flagged` and `guest_flagged_at` continue to act as derived compatibility fields.

Pass criteria:
- guest-flagged badge appears in `admin/event_qr.php` after at least one `guest_video_reports` row exists for a video
- badge disappears after all `guest_video_reports` rows for that video are retracted
- no PHP errors or unexpected behavior in `admin/event_qr.php` after deploy

#### Step 3 — Schedule follow-on work

Review `## Progress > Remaining — Follow-on Tasks` and schedule any items appropriate for the next cycle.

### Follow-on Work (tracked in Progress > Remaining — Follow-on Tasks)

- `guest_event_view.php` web fallback parity (report/retract toggle, server-backed `reported_by_me`)
- Optional: report count or report detail in `admin/event_qr.php`
- Optional: automated integration tests for report → retract → aggregate recompute
- Optional: backfill legacy `guest_flagged = 1` rows if historical accuracy matters
- Shared guest credential helper — extract duplicated nonce validation and event-resolution auth block from `guest-gallery.php`, `guest-report.php`, and `guest-delete.php` into a shared PHP class; also permanently fixes the pre-existing `{30,40}` regex bug in `guest-delete.php`. See `docs/refactor_iphone_qr_code_guest_nonce_shared_helper.md`.

### SonarQube / Best-Practice Notes

#### PHP / backend

- Hash guest credential before persistence or lookup in the new table; do not store raw nonce or raw token.
- Use prepared statements only.
- Keep event isolation strict: every modification must derive `event_id` from the authenticated nonce/token chain and constrain the target video to that event.
- Avoid breaking existing admin UI by preserving aggregate `guest_flagged` compatibility behavior.
- **Brittle-pattern warning:** do not duplicate the guest credential regex, token-vs-nonce branching, or event-resolution SQL in multiple endpoints. Repeated inline regexes and auth-resolution blocks are easy to let drift and are a likely source of brittle behavior. Prefer a shared helper or service with one authoritative validation pattern and one authoritative credential-resolution path.
- **RSPEC-3776 risk:** `guest-report.php` and `guest-gallery.php` can become cognitively complex if validation, auth resolution, transaction logic, aggregate recompute, and response shaping all remain inline. Keep the controller thin and extract helper functions for auth resolution, report mutation, and aggregate refresh.
- **RSPEC-2635 / sensitive data handling:** keep hashing of the incoming credential in PHP before DB comparison where feasible, and do not log raw guest credentials in success or error paths.

#### Swift / iPhone

- Do not rely on `reportedIds` as the primary source of truth across reloads.
- Rebuild local UI state from server payload after each successful fetch.
- Keep the alert logic explicit and separate for report vs retract to avoid accidental destructive semantics.
- No force unwraps.
- Avoid brittle duplicated state transitions: centralize the “is this video reported by me?” decision in one place so the icon state, tap behavior, and success messaging cannot drift apart.

---

## Wireframe

### Gallery row — unreported

```text
+--------------------------------------------------------------+
|  ▶   Sarah's iPhone - First dance                ⚑       X   |
|      Crowd cheering near stage                               |
+--------------------------------------------------------------+
```

### Gallery row — reported by current guest

```text
+--------------------------------------------------------------+
|  ▶   Sarah's iPhone - First dance                ⚑●      X   |
|      Crowd cheering near stage                               |
+--------------------------------------------------------------+
```

### Retract confirmation

```text
+------------------------------------------+
| Remove your report?                      |
| This will take back your flag for this   |
| video. Other guests' reports, if any,    |
| will remain in place.                    |
|                                          |
|   [Cancel]              [Remove Report]  |
+------------------------------------------+
```

---

## Progress

### Completed

- Investigated current iPhone client report flow.
- Investigated current backend report endpoint and gallery payload.
- Identified root cause: current implementation stores only aggregate report state on `upload_jobs`.
- Chosen refactor direction: per-guest report table plus server-driven `reported_by_me` UI state.
- Added stronger approval-first language to `SKILL.md`.
- Wrote this planning document.

### Remaining — This Feature

- Review and approve this plan.
- Decide whether admin UI should remain aggregate-only or surface report count later.
- Implement backend schema/API changes.
- Implement iPhone toggle UI changes.
- Run dev verification.

### Remaining — Follow-on Tasks

- Optional: update `guest_event_view.php` to support report retraction and server-backed `reported_by_me` parity.
- Optional: show report counts or report detail in `admin/event_qr.php`.
- Optional: add automated integration tests for report → retract → aggregate recompute.
- Optional: backfill or normalize any legacy `guest_flagged = 1` rows if historical accuracy becomes important.
