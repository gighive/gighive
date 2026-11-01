---
description: "TUS upload finalize path never creates events/event_items rows — QR-uploaded videos are invisible in the event view, have no thumbnail, trigger no approval alerts, and show no delete icon on the iPhone"
---

# Problem: TUS upload finalize misses event linkage, probe cron env, approval alerts, and delete icon

## Summary

After the Phase 5 media-storage refactor replaced the old `UploadService`-based upload
path with a PHP TUS server (`tus-upload.php` + `TusBlockUploadService`), four distinct
gaps were discovered during live iPhone testing on gighive2 (dev). All four are
consequential to the guest QR-upload workflow and are documented together because they
share a common root: the TUS finalize path was implemented to handle file storage and
probing but the event-linkage, notification, and UI-context data writes were never
ported from the old upload service.

Discovery date: **2026-08-18** (post Phase 5 repush to dev).

---

## Impact

| # | Symptom | User-visible effect |
|---|---|---|
| 1 | `events`/`event_items` rows never created | QR-uploaded videos invisible in `/db/database.php?view=event`; librarian view unaffected |
| 2 | `probe_jobs` rows remain `queued` indefinitely | Thumbnail column blank in librarian view; `media_info` / `duration_seconds` never populated |
| 3 | Approval alerts not sent to iPhone | Guest uploader never notified when admin approves/rejects their clip |
| 4 | Delete icon absent for own uploads in guest gallery | Guest cannot delete their own clip from the gallery view |

Items 1 and 2 are confirmed with hard DB and log evidence (see below).
Items 3 and 4 require further investigation before root cause can be stated
with certainty — they are tracked here so evidence gathering and fixes are
not lost.

---

## Symptoms

- **Event view empty for new uploads** — `/db/database.php?view=event` shows zero rows for
  `asset_id` 17 and 18 (the two QR-uploaded test videos). `?view=librarian` shows them.
- **Thumbnail column blank** — `thumbnail_done` is `NULL`; `media_info` is `NULL`.
  `probe_jobs` rows for both assets have `status=queued`, `attempts=0`, `started_at=NULL`
  despite being created over two hours before observation.
- **`/var/log/probe_job.log`** — 1,630 identical fatal errors (every cron slot since deploy):
  ```
  PHP Fatal error: Uncaught PDOException: SQLSTATE[HY000] [2002] No such file or directory
    in /var/www/html/src/Infrastructure/Database.php:30
  ```
- **iPhone alerts** — not received after admin approved the clips via the web interface.
- **Delete icon** — absent in guest gallery for clips uploaded by the authenticated guest.

---

## Root Cause Analysis

### Issue 1 — Event linkage never created

**Impact:**

Every QR-uploaded video is invisible in the event view (`/db/database.php?view=event`)
because `EventRepository` joins `events → event_items → assets` — and no `event_items`
row is ever created. The asset exists in the database and appears correctly in the
librarian view, but it is orphaned from any event. This means:

- The admin event view shows zero QR-uploaded videos
- The video cannot be associated with an event date or organisation
- Any downstream feature that queries by event (reporting, exports, shared galleries
  keyed to an event) will also miss the asset

The librarian view is unaffected because it queries `assets` directly without the event
JOIN.

**Evidence:**

```sql
-- DB query result (2026-08-18)
SELECT event_item_id FROM event_items WHERE asset_id IN (17, 18);
-- Result: 0 rows
```

`TusBlockUploadService::finalizeUpload()` (lines 295–343 of `TusBlockUploadService.php`):

```php
// Only these three writes occur:
INSERT INTO assets ...
INSERT INTO probe_jobs ...
UPDATE tus_uploads SET status = 'complete', asset_id = ?
```

No INSERT into `events` or `event_items` anywhere in the file.

`EventRepository::eventFromClause()`:
```sql
FROM events e
JOIN event_items ei ON e.event_id = ei.event_id
JOIN assets a ON ei.asset_id = a.asset_id
```
The event view JOIN requires `event_items` — without it, the asset is invisible.

`TusBlockUploadService::handlePost()` (lines 76–97): parses `Upload-Metadata` into
`$meta`, reads `filetype` and `filename`, then inserts into `tus_uploads`.
`org_name`, `event_date`, `event_type`, and `label` are present in `$meta` (confirmed
from Swift — see below) but are never extracted or stored.

iOS Swift evidence (`TUSUploadClient::uploadFile()`, lines 96–103):
```swift
var context: [String: String] = [
    "event_date": df.string(from: payload.eventDate),
    "org_name": payload.orgName,
    "event_type": payload.eventType
]
if let label = payload.label {
    context["label"] = label   // always set for QR uploads ("Untitled clip" minimum)
}
```

`UploadPayload+GuestUpload.swift` line 21:
```swift
let resolvedLabel = trimmedLabel.isEmpty ? "Untitled clip" : trimmedLabel
```

The old path (`UploadService.php` lines 94, 214) called:
```php
$eventId     = $this->eventRepo->ensureEvent($eventDate, $orgName, $eventType, ...);
$eventItemId = $this->eventItemRepo->ensureEventItem($eventId, $assetId, $itemType, $label, $position);
```
These calls were never ported to the TUS path.

**Root cause (100% certain):** `TusBlockUploadService` has no reference to
`EventRepository` or `EventItemRepository`. The event metadata arrives in
`Upload-Metadata` but is discarded at POST time and is unavailable at finalize time
because `tus_uploads` has no columns to store it.

---

### Issue 2 — Probe cron cannot connect to MySQL

**Impact:**

Every asset processed through the TUS path is affected across three surfaces. Stream
playback continues to work — the file is stored correctly — but all metadata derived from
the probe job is absent:

**Admin web (librarian and event views):**
- `Duration` column: blank — `duration_seconds` never written to `assets`
- `Media File Info` column: blank — `media_info` / `media_info_tool` never written
- `Thumbnail` column: broken — the thumbnail image URL is derived from `checksum_sha256`
  and points to `/video/thumbnails/<sha>.png`, which does not exist because ffmpeg was
  never run to generate it

**iPhone guest gallery:**
- `guest-gallery.php` calls `MediaStorageService::exists()` before returning
  `thumbnail_url`; since no thumbnail file was generated, it returns `null`
- `GuestGalleryView.swift` line 298 guards on `thumbnailUrl` being non-nil; the guest
  sees a blank where their video thumbnail should appear

**Retry exhaustion — silent permanent stall:**
- `failExhaustedJobs()` marks a probe job `failed` after 3 attempts; however, the cron
  crash occurs before a job is ever claimed, so `attempts` is never incremented — all
  jobs stay at `attempts=0` in `queued` indefinitely, never reaching the failure cap
- After Fix 2 deploys and the container restarts, all queued jobs will be picked up and
  processed in `created_at` order without any manual intervention

**Evidence:**

```
# probe_job.log (every cron invocation since deploy — 1,630 occurrences):
PHP Fatal error: Uncaught PDOException: SQLSTATE[HY000] [2002] No such file or directory
  in /var/www/html/src/Infrastructure/Database.php:30
```

`Database::createFromEnv()` (line 21): `$host = getenv('DB_HOST') ?: 'localhost';`

When `DB_HOST` is absent, PDO DSN becomes `mysql:host=localhost;...` — PHP's PDO MySQL
driver treats `localhost` as a Unix socket path (`/var/run/mysqld/mysqld.sock`), which
does not exist inside the Apache container. The error `No such file or directory` is the
Unix socket connect failure.

**Cron daemon environment (confirmed by reading `/proc/27/environ` inside the container):**
```
# All DB vars absent from cron daemon environment:
LANGUAGE=  LC_TIME=  LC_CTYPE=  LC_MONETARY=
PATH=/bin:/usr/bin:/sbin:/usr/sbin
# DB_HOST, MYSQL_DATABASE, MYSQL_USER, MYSQL_PASSWORD — all absent
```

**Docker container environment (PID 1):** `DB_HOST=mysqlServer` present (confirmed via
`cat /proc/1/environ`).

**Manual run as `www-data` succeeds:** `su -s /bin/sh www-data -c 'php run_probe_job.php'`
connects fine — because that shell inherits the Docker process environment from PID 1.

**`entrypoint.sh.j2` lines 69–79** (the `gighive-probe` cron file):
```bash
printf 'SHELL=/bin/sh\n'
printf 'PATH=/usr/local/sbin:/usr/local/bin:/sbin:/bin:/usr/sbin:/usr/bin\n'
printf '* * * * * www-data php /var/www/html/src/Jobs/run_probe_job.php ...\n'
```
No DB env vars written. Compare with `db-backup` cron (lines 54–61) which correctly
writes `DB_HOST`, `MYSQL_DATABASE`, `MYSQL_USER`, `MYSQL_PASSWORD`, `MYSQL_ROOT_PASSWORD`
as cron file variables before the cron lines — the probe cron was written without
following this established pattern.

**Root cause (100% certain):** The `gighive-probe` cron file does not export DB
environment variables. Cron does not inherit Docker container env vars. PHP falls back to
`localhost`, PDO attempts a Unix socket that does not exist, and every probe job invocation
crashes before picking up any queued job.

---

### Issue 3 — Approval alerts not sent to iPhone

**Status: evidence not yet gathered.** Root cause must be proven before proposing a fix.

Suspected area: the approval/rejection notification path (`api/guest-status.php` or
equivalent) may depend on `event_items` or `upload_job_id` data that the TUS path does
not create. Alternatively, the notification trigger may be in the admin approval flow
rather than the upload path. Investigation required.

---

### Issue 4 — Delete icon absent for own uploads in guest gallery

**Status: evidence not yet gathered.** Root cause must be proven before proposing a fix.

Suspected area: the guest gallery delete-icon display (`GuestGalleryView.swift`) checks
`ownUploadIds.contains(video.uploadJobId)`. The `upload_job_id` value may be sourced from
a table or field that the TUS path does not populate, causing the iOS app to never include
the new upload in `ownUploadIds`. Investigation required.

---

## Resolution

### Fix 1 — Event linkage in TUS finalize

**Scope:** 6 files across PHP, SQL, and Ansible.

**Implementation steps:**

#### Step 1a — Schema: add event-metadata columns to `tus_uploads`

Add four nullable columns to `tus_uploads`. All are NULL-able so existing rows and any
future non-iOS client that does not send event metadata are unaffected.

```sql
ALTER TABLE tus_uploads
    ADD COLUMN upload_org_name   VARCHAR(255) NULL AFTER mime_type,
    ADD COLUMN upload_event_date DATE         NULL AFTER upload_org_name,
    ADD COLUMN upload_event_type VARCHAR(64)  NULL DEFAULT 'band' AFTER upload_event_date,
    ADD COLUMN upload_label      VARCHAR(255) NULL AFTER upload_event_type;
```

`upload_event_type` defaults to `'band'` to match `UploadPayload+GuestUpload.swift`
(`eventDetails.eventType` is `"band"` for non-wedding events).

#### Step 1b — `create_media_db.sql`

Add the same four columns to the `tus_uploads` `CREATE TABLE IF NOT EXISTS` block so
fresh environment bootstraps get the schema from first install without any manual step.

#### Step 1c — Apply DDL on existing environments (BABRR process)

Schema changes on existing environments are applied manually by the user following the
`docs/process_backup_alter_backup_rebuild_restore.md` (BABRR) process. Run the ALTER at
BABRR Step 2 from the docker host, **before** deploying the updated PHP code:

```bash
docker exec -i mysqlServer sh -lc 'mysql -h 127.0.0.1 -u root -p"$MYSQL_ROOT_PASSWORD" -D "$MYSQL_DATABASE"' << 'MIGRATION'
ALTER TABLE tus_uploads
    ADD COLUMN upload_org_name   VARCHAR(255) NULL AFTER mime_type,
    ADD COLUMN upload_event_date DATE         NULL AFTER upload_org_name,
    ADD COLUMN upload_event_type VARCHAR(64)  NULL DEFAULT 'band' AFTER upload_event_date,
    ADD COLUMN upload_label      VARCHAR(255) NULL AFTER upload_event_type;
MIGRATION
```

Verify the change took effect before proceeding:

```bash
docker exec mysqlServer sh -lc 'mysql -h 127.0.0.1 -u root -p"$MYSQL_ROOT_PASSWORD" -D "$MYSQL_DATABASE" \
  -e "SHOW CREATE TABLE tus_uploads\G"'
```

**⚠ DDL must run before the Ansible code deploy.** `site.yml` deploys PHP code via the
`docker` role before any schema work can occur. If the new `handlePost()` reaches
production before these columns exist, any upload attempt will produce a SQL column
error. Apply the DDL via BABRR Step 2 first, then deploy.

#### Step 1d — `TusUploadState.php`

Add four nullable fields to the constructor and populate them in `fromRow()`:

```php
// Constructor additions (readonly, nullable):
public readonly ?string $uploadOrgName,
public readonly ?string $uploadEventDate,
public readonly ?string $uploadEventType,
public readonly ?string $uploadLabel,

// fromRow() additions:
uploadOrgName:    $row['upload_org_name']   ?? null,
uploadEventDate:  $row['upload_event_date'] ?? null,
uploadEventType:  $row['upload_event_type'] ?? null,
uploadLabel:      $row['upload_label']      ?? null,
```

#### Step 1e — `TusBlockUploadService::fetchUploadForUpdate()` and `handleHead()` SELECT

Both SELECTs in `TusBlockUploadService` that read `tus_uploads` rows must include the
four new columns, otherwise `TusUploadState::fromRow()` receives `null` for them even
after the ALTER:

```php
// fetchUploadForUpdate() — used by handlePatch() to get the row FOR UPDATE:
'SELECT id, upload_id, user_id, status, upload_length,
        block_count, block_size, sha256_ctx, file_type, mime_type,
        upload_org_name, upload_event_date, upload_event_type, upload_label,
        asset_id, expires_at
 FROM tus_uploads WHERE upload_id = ? FOR UPDATE'

// handleHead() — same column list (no FOR UPDATE):
'SELECT id, upload_id, user_id, status, upload_length,
        block_count, block_size, sha256_ctx, file_type, mime_type,
        upload_org_name, upload_event_date, upload_event_type, upload_label,
        asset_id, expires_at
 FROM tus_uploads WHERE upload_id = ?'
```

#### Step 1f — `TusBlockUploadService::handlePost()`

Extract and normalize the four fields from `$meta`, then include them in the
`tus_uploads` INSERT. `orgName` must be passed through `TextNormalizer::normalizeForStorage()`
before storage — matching `UploadService.php` line 79 — so the `events` table unique
constraint `uq_events_tenant_date_org` (on `tenant_id, event_date, org_name`) matches
consistently with rows created by the old path.

Two PHP constants are added to `TusBlockUploadService` to avoid magic string drift with
the iOS defaults defined in `UploadPayload+GuestUpload.swift` line 21 and
`TUSUploadClient.swift` line 99:

```php
// At class level:
private const DEFAULT_EVENT_TYPE = 'band';       // matches TUSUploadClient.swift context["event_type"]
private const DEFAULT_LABEL      = 'Untitled clip'; // matches UploadPayload+GuestUpload.swift resolvedLabel
```

```php
// In handlePost(), after existing $meta parsing:
$normalizer = new TextNormalizer();
$orgName    = $normalizer->normalizeForStorage(trim($meta['org_name']   ?? ''));
$eventDate  = trim($meta['event_date'] ?? '');
$eventType  = trim($meta['event_type'] ?? self::DEFAULT_EVENT_TYPE);
$label      = trim($meta['label']      ?? '');

// Reject malformed dates — store NULL rather than an invalid DATE value.
if ($eventDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $eventDate)) {
    $eventDate = '';
}

// INSERT with new columns:
$stmt = $this->config->pdo->prepare(
    'INSERT INTO tus_uploads
     (upload_id, user_id, status, upload_length, file_type, mime_type,
      upload_org_name, upload_event_date, upload_event_type, upload_label,
      created_at, expires_at)
     VALUES (?, ?, \'pending\', ?, ?, ?, ?, ?, ?, ?, NOW(), NOW() + INTERVAL 24 HOUR)'
);
$stmt->execute([
    $uploadId, $userId, $uploadLength, $fileType, $mimeType,
    $orgName   !== '' ? $orgName            : null,
    $eventDate !== '' ? $eventDate          : null,
    $eventType !== '' ? $eventType          : self::DEFAULT_EVENT_TYPE,
    $label     !== '' ? $label              : null,
]);
```

#### Step 1g — `TusBlockUploadService` constructor

Inject `EventRepository` and `EventItemRepository`. The constructor now has 4 parameters
— within the RSPEC-107 limit.

```php
use Production\Api\Repositories\EventRepository;
use Production\Api\Repositories\EventItemRepository;

public function __construct(
    private readonly TusUploadConfig          $config,
    private readonly TusChunkBackendInterface $backend,
    private readonly EventRepository          $eventRepo,
    private readonly EventItemRepository      $eventItemRepo,
) {}
```

#### Step 1h — `TusBlockUploadService::finalizeUpload()`

After the `probe_jobs` INSERT, add event linkage using the same pattern as
`UploadService.php` lines 94 and 214. The guard on `$orgName` and `$eventDate` ensures
admin/Basic Auth uploads (which store NULL for these columns) are silently skipped:

```php
// Create event linkage if event metadata was stored at POST time.
// Guard: skip if org_name or event_date absent (Basic Auth / non-QR uploads).
// Matches UploadService.php pattern exactly.
$orgName   = $upload->uploadOrgName;
$eventDate = $upload->uploadEventDate;
$eventType = $upload->uploadEventType ?? self::DEFAULT_EVENT_TYPE;
$label     = $upload->uploadLabel     ?? self::DEFAULT_LABEL;

if ($orgName !== null && $orgName !== '' && $eventDate !== null && $eventDate !== '') {
    $eventId  = $this->eventRepo->ensureEvent($eventDate, $orgName, $eventType);
    $itemType = ($eventType === 'wedding') ? 'clip' : 'song';
    $position = $this->eventItemRepo->nextPosition($eventId);
    $this->eventItemRepo->ensureEventItem($eventId, $assetId, $itemType, $label, $position);
}
```

#### Step 1i — `tus-upload.php`

Construct the two repositories from `$config->pdo` (not from the token-path `$pdo`,
which only exists for QR uploads). `$config` is available to all code paths:

```php
use Production\Api\Repositories\EventRepository;
use Production\Api\Repositories\EventItemRepository;

// After $config = TusUploadConfig::fromEnv():
$eventRepo     = new EventRepository($config->pdo);
$eventItemRepo = new EventItemRepository($config->pdo);

// Pass to service:
$service = new TusBlockUploadService($config, $backend, $eventRepo, $eventItemRepo);
```

**SonarQube / best-practice notes:**

- `orgName` is NFC-normalized and whitespace-collapsed before DB storage — consistent
  with `UploadService.php` and the `uq_events_tenant_date_org` unique constraint.
- `eventDate` is validated by regex before storage; malformed values become NULL and
  skip event linkage silently.
- All values reach SQL only through PDO prepared statements.
- `ensureEvent()` and `ensureEventItem()` are idempotent (`ON DUPLICATE KEY`) — safe
  on re-upload of an identical file.
- `DEFAULT_EVENT_TYPE` and `DEFAULT_LABEL` are class constants — single authoritative
  source matching the iOS Swift defaults.

**Remediation for existing orphaned assets (17, 18 on dev):**

After deployment, assets 17 and 18 already have `upload_org_name = NULL` (the columns
didn't exist at upload time). T-95 correctly excludes them (it filters on
`upload_org_name IS NOT NULL`). To remediate, re-upload a new video — the new path will
create the event linkage correctly. Alternatively, insert the `event_items` rows manually
using the event metadata known from the QR token record.

---

### Fix 2 — Probe cron DB environment

**Scope:** `ansible/roles/docker/templates/entrypoint.sh.j2`

Add the four DB environment variables to the `gighive-probe` cron file block, following
the established pattern from the `db-backup` cron (lines 54–61 of the same file).
`MYSQL_ROOT_PASSWORD` is intentionally excluded — `run_probe_job.php` connects as
`appuser` using `MYSQL_USER`/`MYSQL_PASSWORD`, matching the minimal set read by
`Database::createFromEnv()`:

```bash
{
  printf 'SHELL=/bin/sh\n'
  printf 'PATH=/usr/local/sbin:/usr/local/bin:/sbin:/bin:/usr/sbin:/usr/bin\n'
  printf 'DB_HOST=%s\n'        "${DB_HOST:-mysqlServer}"
  printf 'MYSQL_DATABASE=%s\n' "${MYSQL_DATABASE:-}"
  printf 'MYSQL_USER=%s\n'     "${MYSQL_USER:-}"
  printf 'MYSQL_PASSWORD=%s\n' "${MYSQL_PASSWORD:-}"
  printf '* * * * * www-data php /var/www/html/src/Jobs/run_probe_job.php >> /var/log/probe_job.log 2>&1\n'
  printf '* * * * * www-data sleep 10 && php /var/www/html/src/Jobs/run_probe_job.php >> /var/log/probe_job.log 2>&1\n'
  printf '* * * * * www-data sleep 20 && php /var/www/html/src/Jobs/run_probe_job.php >> /var/log/probe_job.log 2>&1\n'
  printf '* * * * * www-data sleep 30 && php /var/www/html/src/Jobs/run_probe_job.php >> /var/log/probe_job.log 2>&1\n'
  printf '* * * * * www-data sleep 40 && php /var/www/html/src/Jobs/run_probe_job.php >> /var/log/probe_job.log 2>&1\n'
  printf '* * * * * www-data sleep 50 && php /var/www/html/src/Jobs/run_probe_job.php >> /var/log/probe_job.log 2>&1\n'
  printf '0 3 * * * www-data php /var/www/html/src/Jobs/cleanup_expired_uploads.php >> /var/log/probe_job.log 2>&1\n'
} > /etc/cron.d/gighive-probe
```

This fix requires a container restart to take effect (`entrypoint.sh.j2` runs at
container startup and rewrites the cron file each time). A full Ansible deploy handles
this. No DDL required.

---

### Fix 3 — Approval alerts (pending investigation)

To be determined. Root cause evidence must be gathered before this section can be
completed. Suspected area: `api/guest-status.php` or the admin approval handler may
depend on `event_items` data that Fix 1 will now provide — in which case Fix 3 may be
resolved by Fix 1. Confirm after Fix 1 is deployed and retested.

---

### Fix 4 — Delete icon absent (pending investigation)

To be determined. Root cause evidence must be gathered before this section can be
completed. Suspected area: `GuestGalleryAPIClient.swift` `uploadJobId` field and how
`ownUploadIds` is populated. May depend on `anon_upload_attributions` or `upload_jobs`
data that the TUS path does not write. Confirm after investigating `api/guest-gallery.php`
and the iOS gallery response model.

---

## Files Under Change

### Fix 1 (event linkage)

**Modified:**
1. `ansible/roles/docker/files/mysql/externalConfigs/create_media_db.sql` *(gighiveinfra)* — add `upload_org_name`, `upload_event_date`, `upload_event_type`, `upload_label` columns to `tus_uploads` CREATE TABLE
2. `ansible/roles/docker/files/apache/webroot/src/Dto/TusUploadState.php` *(gighiveinfra)* — add four nullable fields to constructor; populate from `fromRow()`
3. `ansible/roles/docker/files/apache/webroot/src/Services/TusBlockUploadService.php` *(gighiveinfra)* — add `DEFAULT_EVENT_TYPE`/`DEFAULT_LABEL` constants; update both `tus_uploads` SELECTs to include new columns; extract+normalize event metadata in `handlePost()`; inject repos in constructor; call `ensureEvent()`/`ensureEventItem()` in `finalizeUpload()`
4. `ansible/roles/docker/files/apache/webroot/api/tus-upload.php` *(gighiveinfra)* — add `use` statements for `EventRepository` and `EventItemRepository`; construct from `$config->pdo` and inject into service
5. `ansible/roles/post_build_checks/tasks/main.yml` *(gighiveinfra)* — add T-94 and T-95 regression tests

**Manual DDL (BABRR Step 2 — not a file change):**
- Four columns added to `tus_uploads` on existing environments via `docker exec` heredoc (see Step 1c)

**Unchanged:**
- `ansible/roles/docker/files/apache/webroot/src/Repositories/EventRepository.php` — consumed but not modified
- `ansible/roles/docker/files/apache/webroot/src/Repositories/EventItemRepository.php` — consumed but not modified
- `ansible/roles/docker/files/apache/webroot/src/Config/TusUploadConfig.php` — consumed but not modified

### Fix 2 (probe cron env)

**Modified:**
6. `ansible/roles/docker/templates/entrypoint.sh.j2` *(gighiveinfra)* — add DB env var lines to `gighive-probe` cron file block
7. `ansible/roles/post_build_checks/tasks/main.yml` *(gighiveinfra)* — add T-96 regression test

---

## Tests

### T-94 — `probe_jobs` rows transition from `queued` to `done` after deploy

**Purpose:** Proves probe cron can connect to MySQL and picks up queued jobs. Any row
stuck in `queued` for more than 5 minutes after deploy indicates the cron still cannot
connect. Placed in `post_build_checks/tasks/main.yml`, tagged `[smoke, probe]`, permanent.

```yaml
- name: "[T-94] probe_jobs has no rows stuck in queued status for more than 5 minutes"
  community.docker.docker_container_exec:
    container: "{{ mysql_container_name | default('mysqlServer') }}"
    command: >-
      sh -lc 'MYSQL_PWD=$MYSQL_ROOT_PASSWORD mysql -uroot media_db -sN -e
      "SELECT COUNT(*) FROM probe_jobs
       WHERE status = '\''queued'\''
       AND created_at < NOW() - INTERVAL 5 MINUTE;"'
  register: _probe_stuck_count
  changed_when: false
  tags: [smoke, probe]

- name: "[T-94] Assert no probe_jobs stuck in queued"
  ansible.builtin.assert:
    that:
      - (_probe_stuck_count.stdout | trim) == '0'
    fail_msg: >-
      {{ _probe_stuck_count.stdout | trim }} probe_jobs row(s) have been queued
      for more than 5 minutes — probe cron may not be connecting to MySQL.
  changed_when: false
  tags: [smoke, probe]
```

### T-95 — Completed TUS uploads have an `event_items` row

**Purpose:** Proves that `finalizeUpload()` creates event linkage. Checks that every
`tus_uploads` row with `status=complete` and non-null event metadata has a corresponding
`event_items` row, scoped to assets that still exist. Rows without event metadata (Basic
Auth / non-QR uploads) are excluded by the `upload_org_name IS NOT NULL` filter. Rows
whose asset was intentionally deleted (admin delete, smoke test cleanup) are excluded by
the `INNER JOIN assets` — the `ON DELETE CASCADE` on `event_items.fk_event_items_asset`
removes the `event_items` row when the asset is deleted, and without a matching `assets`
row the `INNER JOIN` drops the `tus_uploads` row from the result set entirely.

**Why INNER JOIN and not `asset_id IS NOT NULL`:** The original query used
`AND tu.asset_id IS NOT NULL`. During first deployment (2026-08-18) the `post_build_checks`
TUS smoke test sends event metadata in `Upload-Metadata` (lines 279-281), which the new
`handlePost()` code now stores. After the smoke test's asset cleanup delete, the `assets`
row is gone and `event_items` is cascade-deleted, but `tus_uploads` retains `asset_id` and
`upload_org_name`. The old query incorrectly flagged this cleaned-up row as orphaned.
Changing to `INNER JOIN assets` restricts the check to assets that still exist, which is
the only population where a missing `event_items` row represents a genuine bug.

Placed in `post_build_checks/tasks/main.yml`, tagged `[smoke, tus]`, permanent.

```yaml
- name: "[T-95] All complete TUS uploads with event metadata have an event_items row"
  community.docker.docker_container_exec:
    container: "{{ mysql_container_name | default('mysqlServer') }}"
    command: >-
      sh -lc 'MYSQL_PWD=$MYSQL_ROOT_PASSWORD mysql -uroot media_db -sN -e
      "SELECT COUNT(*) FROM tus_uploads tu
       INNER JOIN assets a ON a.asset_id = tu.asset_id
       WHERE tu.status = '\''complete'\''
       AND tu.upload_org_name IS NOT NULL
       AND tu.upload_event_date IS NOT NULL
       AND NOT EXISTS (
           SELECT 1 FROM event_items ei WHERE ei.asset_id = tu.asset_id
       );"'
  register: _tus_no_event_item_count
  changed_when: false
  tags: [smoke, tus]

- name: "[T-95] Assert no orphaned TUS uploads (complete + metadata but no event_items)"
  ansible.builtin.assert:
    that:
      - (_tus_no_event_item_count.stdout | trim) == '0'
    fail_msg: >-
      {{ _tus_no_event_item_count.stdout | trim }} TUS upload(s) have status=complete
      and event metadata but no event_items row — event linkage in finalizeUpload() is broken.
  changed_when: false
  tags: [smoke, tus]
```

### T-96 — Probe cron file contains DB environment variables

**Purpose:** Proves the `gighive-probe` cron file was written with `DB_HOST` and MySQL
credentials so cron-spawned PHP can connect. Closes the gap left by T-82, which only
checks the cron file exists and references `run_probe_job.php`. Placed in
`post_build_checks/tasks/main.yml`, tagged `[smoke, probe]`, permanent.

```yaml
- name: "[T-96] Read probe cron file and verify DB env vars are present"
  community.docker.docker_container_exec:
    container: "{{ apache_container_name }}"
    command: cat /etc/cron.d/gighive-probe
  register: _probe_cron_file
  changed_when: false
  tags: [smoke, probe]

- name: "[T-96] Assert probe cron file contains DB_HOST and MYSQL_USER"
  ansible.builtin.assert:
    that:
      - "'DB_HOST=' in _probe_cron_file.stdout"
      - "'MYSQL_USER=' in _probe_cron_file.stdout"
      - "'MYSQL_PASSWORD=' in _probe_cron_file.stdout"
      - "'MYSQL_DATABASE=' in _probe_cron_file.stdout"
    fail_msg: >-
      /etc/cron.d/gighive-probe is missing one or more DB environment variables —
      probe jobs will fail with SQLSTATE[HY000] [2002] No such file or directory.
  changed_when: false
  tags: [smoke, probe]
```

---

## Verification

### Fix 1 (event linkage)

1. Apply DDL manually on target environment before deploy (see ordering note in Step 1c).
2. Run Ansible deploy.
3. Confirm new columns exist:
   ```bash
   docker exec mysqlServer sh -c 'MYSQL_PWD=$MYSQL_ROOT_PASSWORD mysql -uroot media_db -e "DESCRIBE tus_uploads"'
   # Expect: upload_org_name, upload_event_date, upload_event_type, upload_label present
   ```
4. QR-upload a new video from iPhone.
5. Confirm `tus_uploads` row has metadata populated:
   ```bash
   docker exec mysqlServer sh -c 'MYSQL_PWD=$MYSQL_ROOT_PASSWORD mysql -uroot media_db -e
   "SELECT upload_id, upload_org_name, upload_event_date, upload_event_type, upload_label, status
    FROM tus_uploads ORDER BY id DESC LIMIT 3"'
   ```
6. Confirm `event_items` row created:
   ```bash
   docker exec mysqlServer sh -c 'MYSQL_PWD=$MYSQL_ROOT_PASSWORD mysql -uroot media_db -e
   "SELECT ei.event_item_id, ei.event_id, ei.asset_id, ei.label FROM event_items ei
    ORDER BY ei.event_item_id DESC LIMIT 3"'
   ```
7. Confirm video appears in `/db/database.php?view=event`.
8. T-95 passes in `post_build_checks`.

### Fix 2 (probe cron env)

1. Run Ansible deploy (container restart rewrites cron file).
2. Confirm cron file has DB vars:
   ```bash
   docker exec apacheWebServer cat /etc/cron.d/gighive-probe
   # Expect: DB_HOST=mysqlServer line present
   ```
3. Wait up to 2 minutes; check probe log:
   ```bash
   docker exec apacheWebServer tail -20 /var/log/probe_job.log
   # Expect: [probe] job=N asset=N lines — no SQLSTATE errors
   ```
4. Confirm `probe_jobs` rows transition to `status=done`:
   ```bash
   docker exec mysqlServer sh -c 'MYSQL_PWD=$MYSQL_ROOT_PASSWORD mysql -uroot media_db -e
   "SELECT asset_id, status, attempts FROM probe_jobs ORDER BY id DESC LIMIT 5"'
   ```
5. Confirm thumbnails appear in librarian view.
6. T-94 and T-96 pass in `post_build_checks`.

### Fix 3 and Fix 4

Verification criteria TBD pending root cause investigation.

---

## Deployment Notes (2026-08-18, gighive2/dev)

### T-95 false positives during first deployment — root cause and resolution

T-95 failed three consecutive times during the first deployment run on gighive2. Each
failure reported exactly 1 orphaned row. Investigation via SSH into the docker host
revealed the following sequence of causes:

**Attempt 1 and 2 — stale rows from nuclear wipe restore:**
A `rebuild_mysql_data: true` deploy was used to reinitialize the DB from the updated
`create_media_db.sql`. The BABRR restore step brought back a backup that contained
`tus_uploads` rows from the old broken code path whose `asset_id` values no longer
existed in `assets` (the asset write had been wiped). These were confirmed stale by
checking `SELECT asset_id FROM assets WHERE asset_id IN (17, 18)` — both empty. Each
stale row was deleted manually via `DELETE FROM tus_uploads WHERE id = N`.

**Attempt 3 — smoke test asset cleanup:**
After the stale rows were cleared, T-95 still fired on a new row. Investigation showed:
- The row had `upload_label='TUS_VALIDATE'` — not an iPhone upload but the
  `post_build_checks` TUS smoke test itself
- The smoke test sends `org_name`, `event_date`, `event_type`, `label` in
  `Upload-Metadata` (lines 279-281 of `post_build_checks/tasks/main.yml`)
- The new `handlePost()` code now stores those fields; `finalizeUpload()` creates the
  `event_items` row correctly
- The smoke test then deletes the asset via `delete_media_files.php` at cleanup
- `ON DELETE CASCADE` on `event_items.fk_event_items_asset` deletes the `event_items`
  row, but `tus_uploads` retains its `asset_id` and `upload_org_name`
- T-95's original `AND tu.asset_id IS NOT NULL` filter did not exclude this row
- The fix was to change the query to `INNER JOIN assets a ON a.asset_id = tu.asset_id`,
  restricting the assertion to assets that still exist

**Transaction safety confirmed:** The concern that T-95 might fire due to in-flight
uploads was investigated and ruled out. `finalizeUpload()` runs entirely inside the
transaction opened by `handlePatch()`. `UPDATE tus_uploads SET status='complete'` and
`ensureEventItem()` both commit atomically. If `ensureEventItem()` throws, the entire
transaction rolls back including the `status='complete'` update, so T-95 never sees a
genuinely in-flight row.

**Operational note for future nuclear wipe deployments:** If `rebuild_mysql_data: true`
is used and the restore brings back `tus_uploads` rows whose assets were wiped, those
stale rows must be manually deleted before re-running `post_build_checks`. Use:
```bash
docker exec -i mysqlServer sh -lc 'mysql -h 127.0.0.1 -u root -p"$MYSQL_ROOT_PASSWORD" -D "$MYSQL_DATABASE"' << 'SQL'
DELETE tu FROM tus_uploads tu
LEFT JOIN assets a ON a.asset_id = tu.asset_id
WHERE tu.status = 'complete'
  AND tu.upload_org_name IS NOT NULL
  AND a.asset_id IS NULL;
SQL
```

### Follow-on: Standing DB cruft cleanup before T-94/T-95 (M-01, M-02) — implemented 2026-08-18

**Future maintenance role note:** These cleanup tasks are temporarily placed in
`post_build_checks` because no dedicated maintenance Ansible role exists yet. A future
`maintenance` role should be created to own all standing DB hygiene tasks (orphan cleanup,
retry exhaustion purges, expired token purges, etc.) and run early in `site.yml` before
any validation or smoke-test roles. When that role exists, M-01 and M-02 should be moved
there and removed from `post_build_checks`.

**Deferred tasks for the future `maintenance` role:** See
`docs/refactor_storage_media_rest_endpoint_followons.md` → Maintenance Role section.

**Problem:**
`probe_jobs` and `tus_uploads` have no `ON DELETE CASCADE` foreign key from `assets`.
Any time an asset is deleted — via `delete_media_files.php`, Section A/B in
`admin_system.php`, or a BABRR nuclear wipe/restore — the corresponding `probe_jobs` and
`tus_uploads` rows are left orphaned. Over multiple playbook runs and backup/restore cycles
these accumulate, causing:

- Spurious probe log noise (`Local media file not found`) on every cron tick until retry
  exhaustion (max attempts reached, `status=failed`)
- False T-94 failures if orphaned rows happen to be `status=queued`
- False T-95 failures for orphaned `tus_uploads` rows with event metadata

**Scope:** Delete only rows whose `asset_id` has no matching row in `assets`. This is a
safe, conservative predicate regardless of how the orphan was created. In-flight uploads
always have a corresponding `assets` row (written atomically in the same transaction), so
they are never touched.

**Placement:** Immediately before T-94 in `post_build_checks/tasks/main.yml`, so T-94
and T-95 assert against a clean state.

**M-01 — Delete orphaned `probe_jobs` rows:**
```yaml
- name: "[M-01] Delete probe_jobs rows whose asset no longer exists"
  community.docker.docker_container_exec:
    container: "{{ mysql_container_name | default('mysqlServer') }}"
    command: >-
      sh -lc 'MYSQL_PWD=$MYSQL_ROOT_PASSWORD mysql -uroot media_db -sN -e
      "DELETE FROM probe_jobs
       WHERE NOT EXISTS (
           SELECT 1 FROM assets a WHERE a.asset_id = probe_jobs.asset_id
       );"'
  changed_when: false
  tags: [smoke, maintenance]
```

**M-02 — Delete orphaned `tus_uploads` rows:**
Only targets `status=complete` rows — `pending` rows may be for in-flight uploads whose
asset write hasn't committed yet (though atomicity makes this impossible in normal
operation, it is excluded as a safety margin).
```yaml
- name: "[M-02] Delete tus_uploads rows whose asset no longer exists"
  community.docker.docker_container_exec:
    container: "{{ mysql_container_name | default('mysqlServer') }}"
    command: >-
      sh -lc 'MYSQL_PWD=$MYSQL_ROOT_PASSWORD mysql -uroot media_db -sN -e
      "DELETE FROM tus_uploads
       WHERE status = '\''complete'\''
       AND asset_id IS NOT NULL
       AND NOT EXISTS (
           SELECT 1 FROM assets a WHERE a.asset_id = tus_uploads.asset_id
       );"'
  changed_when: false
  tags: [smoke, maintenance]
```

**Post-plan review (SKILL.md):**
1. **Logic correctness:** `NOT EXISTS (SELECT 1 FROM assets ...)` is the established orphan
   predicate in this file — matches the style of T-95's query. Safe on every run.
2. **Pattern reuse:** Switched from multi-table `DELETE ... LEFT JOIN` (not used anywhere
   in this file) to single-table `DELETE ... WHERE NOT EXISTS` — consistent with T-95's
   `NOT EXISTS` subquery style. Avoids introducing aliased multi-table DELETE syntax.
3. **In-flight safety:** `status=complete` guard on M-02 ensures pending TUS uploads are
   never touched. M-01 has no status filter because any `probe_jobs` row for a non-existent
   asset is orphaned regardless of status — the asset was written before `probe_jobs` in
   the same transaction, so a queued job with no asset is always orphaned.
4. **Shell/credential pattern:** Uses `sh -lc '$MYSQL_ROOT_PASSWORD'` — matches
   T-94/T-95/T-96 pattern, correct for tasks outside the cleanup block.
5. **No schema changes, no DDL, no BABRR step.**
6. **Tags:** `[smoke, maintenance]` — `smoke` ensures they run with other smoke tests;
   `maintenance` allows targeting with `--tags maintenance` independently.
7. **MySQL 8.4 compatibility:** Single-table DELETE with NOT EXISTS subquery — fully
   supported and idiomatic.
8. **Idempotent:** Deleting already-absent rows is a no-op.

**File to be modified:**
- `ansible/roles/post_build_checks/tasks/main.yml` — two tasks added immediately before
  the `# --- T-94` comment block.

### Follow-on: TUS smoke test full cleanup (probe_jobs + tus_uploads) — pending approval

**Status: implemented 2026-08-18.**

**Context:**
`delete_media_files.php` (called by the smoke test cleanup block at line 566 of
`post_build_checks/tasks/main.yml`) deletes the physical file, `event_items`, and
`assets`. However it does not delete `probe_jobs` or `tus_uploads` because neither
table has an `ON DELETE CASCADE` foreign key from `assets`. Confirmed via `SHOW CREATE
TABLE probe_jobs` — no FK to `assets` at all.

Over multiple playbook runs, orphaned `probe_jobs` and `tus_uploads` rows accumulate.
When a nuclear wipe backup/restore cycle is performed, those rows are restored but their
assets and files are gone — producing the exact stale-row failures seen in T-94 and
T-95 during the 2026-08-18 deployment.

**What will be added:**

Two new tasks inside the existing cleanup `block:` in `post_build_checks/tasks/main.yml`,
after the `Assert cleanup delete removed 1 row` task (currently line 585), before the
`when: tus_cleanup_after_check` guard (line 595):

**Task 1 — Delete smoke test `probe_jobs` row:**
```yaml
- name: Delete TUS smoke-test probe_jobs row
  community.docker.docker_container_exec:
    container: "{{ mysql_container_name }}"
    command: >-
      sh -c "MYSQL_PWD={{ mysql_root_password | quote }} mysql -uroot media_db -sN
      -e \"DELETE FROM probe_jobs WHERE asset_id={{ tus_finalize_1.json.asset_id | int }};\""
  changed_when: false
  no_log: "{{ tus_checks_no_log }}"
  tags: [tus]
```

**Task 2 — Delete smoke test `tus_uploads` row:**
```yaml
- name: Delete TUS smoke-test tus_uploads row
  community.docker.docker_container_exec:
    container: "{{ mysql_container_name }}"
    command: >-
      sh -c "MYSQL_PWD={{ mysql_root_password | quote }} mysql -uroot media_db -sN
      -e \"DELETE FROM tus_uploads WHERE upload_id='{{ tus_upload_id }}';\""
  changed_when: false
  no_log: "{{ tus_checks_no_log }}"
  tags: [tus]
```

**Pattern corrections applied during plan review (2026-08-18):**
- Changed from `sh -lc '$MYSQL_ROOT_PASSWORD'` to `sh -c "{{ mysql_root_password | quote }}"` to
  match the pattern used by existing tasks in the same cleanup block (line 521).
- Added `no_log: "{{ tus_checks_no_log }}"` — omitted in the first draft, present on every other
  task in the cleanup block.

**Why after `delete_media_files.php` and not before:**
`delete_media_files.php` is the authoritative delete for the asset. `probe_jobs` and
`tus_uploads` are dependent rows that must follow. If asset delete fails, the cleanup
block stops and the orphaned rows are the least of the concerns.

**Why `asset_id` is safe to use directly:**
The `Assert TUS smoke-test asset_id looks valid before cleanup` task (line 556) already
validates `asset_id` is a positive integer before the block proceeds. No additional
validation needed.

**Why `tus_upload_id` is safe to use directly:**
`tus_upload_id` is set at the start of the TUS smoke test block from a UUID generated
by Ansible (`community.general.random_string` or `set_fact`). It is scoped to the
current playbook run and cannot be empty at this point in execution.

**No schema changes required.** No DDL. No BABRR step. Pure Ansible task additions.

**Post-plan review checklist (SKILL.md):**

1. **Logic correctness:** Both deletes are scoped to the exact test artifact (`asset_id`
   from finalize response, `upload_id` from smoke test set_fact). No risk of deleting
   production rows.
2. **Internal consistency:** Tasks sit inside the same `block:` as the existing cleanup,
   under the same `when: tus_cleanup_after_check` guard — consistent with existing pattern.
3. **Coding best practices:** Uses `docker_container_exec` + `MYSQL_PWD` pattern already
   established in this file. No shell module. No hardcoded credentials.
4. **Secure coding:** No credentials in plaintext. `MYSQL_PWD` from container env.
5. **Ansible best practices:** Idempotent (DELETE of a specific row is safe to re-run if
   already gone — returns 0 rows affected, no error). `changed_when: false` consistent
   with surrounding tasks. Tagged `[tus]` matching existing cleanup tags.
6. **Hardcoded paths:** None introduced. `mysql_container_name` from group_vars.
7. **No new tests required:** These are cleanup tasks within an existing test block, not
   new functionality. T-94 (no stuck queued probe_jobs) and T-95 (no orphaned tus_uploads)
   are the regression guards — they will now pass cleanly because the smoke test leaves
   no residue.
8. **Completeness:** No DDL, no BABRR, no schema changes, no new endpoints, no new
   group_vars needed.
9. **Timing:** Cleanup runs after asset delete succeeds. Both deletes are unconditional
   within the block — if either fails, the block fails and the smoke test is marked failed,
   which is the correct outcome.
10. **MySQL 8.4 compatibility:** Plain `DELETE FROM ... WHERE` — fully compatible.

**File to be modified:**
- `ansible/roles/post_build_checks/tasks/main.yml` — two tasks added inside the existing
  cleanup `block:`, after line 593 (`Assert cleanup delete removed 1 row`).

---

## Preventative Actions

1. **SKILL.md rule added:** Every solution in a `problem_*.md` doc must include one or
   more permanent tests in `post_build_checks` or the most appropriate Ansible role.
2. **Pattern alignment:** When a new upload path replaces an existing one, a checklist
   of all DB writes performed by the old path must be explicitly audited against the new
   path. Missing writes are silent — no error is thrown when an optional row is absent.
3. **Probe cron pattern:** Any new cron file written in `entrypoint.sh.j2` that runs PHP
   code connecting to MySQL must include `DB_HOST`, `MYSQL_DATABASE`, `MYSQL_USER`, and
   `MYSQL_PASSWORD` as cron file variable lines, matching the `db-backup` cron pattern
   (lines 54–61 of `entrypoint.sh.j2`).
4. **Test T-82 gap closed:** T-82 verifies the probe cron file exists and references
   `run_probe_job.php` but does not check for DB env vars. T-96 closes this gap.
5. **DDL before code deploy:** Any schema change that accompanies a PHP code change must
   be applied via the BABRR process before the Ansible code deploy runs. The `docker`
   role (code) runs before any schema work in `site.yml`; the BABRR manual pre-step is
   the reliable mitigation.
6. **`tus_local_staging_dir` group_vars gap (pre-existing):** `TusUploadConfig.php`
   hardcodes `'/tmp/tus-staging'` as a fallback when `TUS_LOCAL_STAGING_DIR` env var is
   absent. The env var is set in `.env.j2` via `{{ tus_local_staging_dir | default('/tmp/tus-staging') }}`
   but `tus_local_staging_dir` is not declared in any group_vars file. Tracked as a
   follow-on; not introduced by this fix.

---

## Related Files

- `ansible/roles/docker/files/apache/webroot/src/Services/TusBlockUploadService.php`
- `ansible/roles/docker/files/apache/webroot/src/Services/UploadService.php` (reference implementation)
- `ansible/roles/docker/files/apache/webroot/src/Services/TextNormalizer.php`
- `ansible/roles/docker/files/apache/webroot/src/Dto/TusUploadState.php`
- `ansible/roles/docker/files/apache/webroot/api/tus-upload.php`
- `ansible/roles/docker/files/mysql/externalConfigs/create_media_db.sql`
- `ansible/roles/docker/templates/entrypoint.sh.j2`
- `ansible/roles/post_build_checks/tasks/main.yml`
- `GigHive/Sources/App/TUSUploadClient.swift`
- `GigHive/Sources/App/UploadPayload+GuestUpload.swift`
- `docs/process_backup_alter_backup_rebuild_restore.md` — BABRR process used for Step 1c DDL
- `docs/refactor_storage_media_rest_endpoint_implementation.md` — Phase 5 context
