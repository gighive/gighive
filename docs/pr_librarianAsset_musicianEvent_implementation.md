# Implementation Plan: Librarian Asset vs Musician Event (Hard Cutover)

This document is the implementation companion to:

- `docs/pr_librarianAsset_musicianSession_changeSet.md`

It specifies, for each PR milestone, the rationale, the concrete code/data changes, and the exact files that must be added or modified, with enough detail to implement without re-discovering requirements.

Guiding decisions

- Hard cutover (Option A true remodel): the canonical schema is `assets/events/event_items`.
- No compatibility layer: do not keep legacy runtime tables/views solely to preserve old behavior.
- Existing operational entrypoints (admin Sections 3A/3B/4/5, upload API, upload tests) must be ported to the canonical schema.

---

## PR Overview

- **PR0** — Take a known-good DB dump and store a rollback snapshot before any schema changes begin.
- **PR1** — Add canonical `assets`, `events`, and `event_items` tables to the DDL and update the bootstrap loader to populate them on fresh installs.
- **PR2** *(optional for known sites)* — Migrate existing live-upload data from legacy tables into canonical tables; known production sites skip this in favor of a CSV rebuild via PR5.
- **PR3** — Cut over the media listing page (`db/database.php`) to read from canonical tables with separate librarian and event views driven by `APP_FLAVOR`.
- **PR4** — Port the upload API (`POST /api/uploads`, TUS finalize) to write `assets`/`events`/`event_items` instead of `sessions`/`songs`/`files`.
- **PR5** — Port the manifest importer (Sections 4/5) and CSV importers (Sections 3A/3B) to write canonical tables; update CSVs with `org_name`/`event_type` columns before this ships.
- **PR5b** — Update `upload_media_by_hash.py` and all upload test assertions to use canonical table names and canonical API response fields.
- **PR6** — Tag each DB dump with a schema-version sidecar so restores are unambiguous about compatibility.
- **PR7** — Update `API_CURRENT_STATE.md` and `openapi.yaml` to reflect canonical vocabulary and confirm endpoint contracts.

---

## Recommended Sequencing

```
PR0 → PR1 → PR3 → PR4 → PR5 → PR5b → PR6 → PR7
                                              ↑
                                  openapi.yaml field renames (session_id→event_id, seq→position)
                                  + coordinate iPhone app update
                                  + schedule URL-level renames as post-PR7 cleanup
```

**API naming cleanup relationship** (see `docs/refactor_api_cleanup_if_desired.md`):
- Field-level renames (`session_id`→`event_id`, `seq`→`position`) are part of PR7 — already in this plan.
- URL-level renames (`/db/database.php` → `/api/media`, `/admin/import_manifest_upload_finalize.php` → `/admin/manifest/finalize`, `/api/media-files` alias retirement) are **breaking changes deferred to post-PR7**. They should be bundled into the same coordinated client release as the iPhone app update for PR7 field changes — not done as a separate pre-pass before this refactor.

---

## PR Quick Reference: Purpose & Verification

### PR0 — Rollback snapshot
**Purpose:** Take a known-good DB dump before any schema changes. Safety net for the entire refactor.
**Verify:** Restore dump to a clean MySQL instance → web UI loads without errors. Record dump path.

### PR1 — Canonical schema DDL + bootstrap loader
**Purpose:** Introduce `assets`, `events`, `event_items` tables in `create_music_db.sql` and update the CSV loader to populate them on fresh installs.
**Verify:**
- `SHOW TABLES` confirms all three canonical tables exist.
- `SHOW CREATE TABLE` confirms UNIQUE constraints on `checksum_sha256`, `(event_date, org_name)`, `(event_id, asset_id)`.
- Run loader → all three tables have row counts > 0.

### PR2 — Live data migration *(all known sites use CSV rebuild; no SQL migration script needed)*
**Purpose:** Define the per-site migration path. Dev/staging/lab use `sessionsSmall.csv` Section 3B rebuild. Prod uses `sessionsLarge.csv` Section 3B rebuild; the small number of jam sessions not in the CSV are loaded post-PR5 via Section 5 manifest add (media files are on disk). `admin_database_load_import_media_from_folder.php` must NOT be used post-cutover — it is not ported and writes legacy tables.
**Verify:**
- No duplicate checksums: `SELECT checksum_sha256 FROM assets GROUP BY checksum_sha256 HAVING COUNT(*) > 1` returns 0 rows.
- `COUNT(*) FROM events` matches expected session count for the site's CSV dataset.
- No orphaned assets (every asset has at least one `event_items` link).
- Prod only: after Section 5 manifest add for missing jam sessions, confirm those events appear in the listing.

### PR3 — Media listing cutover (`/db/database.php`)
**Purpose:** Rewrite the listing page and JSON API to read from canonical repositories, with separate librarian and event views driven by `APP_FLAVOR`.
**Verify:**
- `/db/database.php?view=librarian` — no duplicate rows for a checksum shared across events.
- `/db/database.php?view=event&event_id=<id>` — assets show with event context.
- `/db/database.php?format=json` — response includes `asset_id`, `event_id`; no `session_id` or `song_id`.
- `APP_FLAVOR=gighive` defaults to librarian; `defaultcodebase` defaults to event.

### PR4 — Upload API cutover (`POST /api/uploads`, TUS finalize)
**Purpose:** Port the upload write path from `sessions/songs/files` to `events/assets/event_items`. Keep all endpoint URLs stable.
**Verify:**
- Run `test_6.yml` and `test_7.yml` — both pass.
- POST a new file → response has `asset_id` + `event_id`, no `session_id` or `seq`.
- POST same file again → HTTP 409/dedup; `COUNT(*) FROM assets` does not increase.
- TUS finalize path produces same canonical field assertions.

### PR5 — Manifest importer + CSV importers (Sections 3A/3B/4/5)
**Purpose:** Port all admin import paths to write canonical tables. Update `sessionsXxx.csv` files with `org_name` and `event_type` columns first (pre-condition).
**Verify:**
- Pre-condition: confirm CSVs have `org_name` and `event_type` columns before running any import.
- After each section (3A, 3B, 4, 5): `COUNT(*)` from `events`, `assets`, `event_items` matches expected totals.
- Re-run Section 5 (add mode) with the same files — counts must not change (idempotent by checksum).
- `org_name` in `events` must not be `'default'` for rows with a real band name.

### PR5b — Binary copy tooling + automated test suite
**Purpose:** Port `upload_media_by_hash.py` to query `assets` and update the full upload_tests suite to assert against canonical tables.
**Verify:**
- Full suite passes: `ansible-playbook ansible/playbooks/site.yml --tags upload_tests` — all of `test_3a`, `test_3b`, `test_4`, `test_5`, `test_6`, `test_7`, `assert_db_invariants` pass.
- `assert_db_invariants.yml` references `events`/`assets`, not `sessions`/`files`.
- `COUNT(*) FROM assets WHERE duration_seconds IS NOT NULL` is > 0 (populated by `upload_media_by_hash`).

### PR6 — Backup schema-version tagging
**Purpose:** Tag each DB dump with a sidecar (git SHA, schema version, timestamp) so restores are unambiguous about pre- vs post-cutover compatibility.
**Verify:**
- Trigger a dump → sidecar file exists alongside the `.sql` file.
- Sidecar contains `schema_version`, `timestamp`, and `git_sha` fields.
- You can determine pre/post-cutover from the sidecar without opening the SQL.

### PR7 — Docs + OpenAPI alignment
**Purpose:** Update `openapi.yaml` and `API_CURRENT_STATE.md` to reflect canonical vocabulary. Publish the updated contract for the iPhone app and other clients.
**Verify:**
- Swagger UI at `/docs/api-docs.html` — no parse errors.
- `UploadResult` schema has `asset_id`, `event_id`, `position`; `session_id` and `seq` are gone.
- Live `POST /api/uploads` response fields match `openapi.yaml`.
- `GET /api/media-files` returns 501 in both spec and server.
- iPhone app developer(s) have received and acknowledged the field-level breaking changes before coordinated client release.

---

## Summary: Files that will change (quick reference)

- **PR1**: **`ansible/roles/docker/files/mysql/externalConfigs/create_music_db.sql`**: introduce canonical `assets/events/event_items` tables and constraints for fresh installs.
- **PR1**: **`ansible/roles/docker/files/mysql/externalConfigs/load_and_transform.sql`**: change bootstrap loader to populate canonical tables (not legacy `sessions/songs/files`).
- **PR2**: **(new backfill script, location TBD with existing DB scripts)**: migrate existing deployed data from legacy tables into canonical tables.

- **PR3**: **`ansible/roles/docker/files/apache/webroot/db/database.php`**: route listing requests to canonical repositories and support `view=librarian|event`.
- **PR3**: **`ansible/roles/docker/files/apache/webroot/src/Controllers/MediaController.php`**: implement librarian vs event listing behaviors against canonical schema.
- **PR3**: **`ansible/roles/docker/files/apache/webroot/src/Views/media/list.php`**: adjust UI rendering for librarian/event views and canonical fields.
- **PR3**: **`ansible/roles/docker/files/apache/webroot/src/Controllers/RandomController.php`**: update any random-media selection to read from canonical schema.
- **PR3**: **`ansible/roles/docker/files/apache/webroot/src/Repositories/SessionRepository.php`**: retire from listing path (legacy) or repurpose; canonical listing must not depend on it.
- **PR3**: **`ansible/roles/docker/files/apache/webroot/src/Repositories/AssetRepository.php`**: new canonical queries for librarian view (one row per checksum).
- **PR3**: **`ansible/roles/docker/files/apache/webroot/src/Repositories/EventRepository.php`**: new canonical queries for event listing and event resolution.

- **PR4**: **`ansible/roles/docker/files/apache/webroot/src/Controllers/UploadController.php`**: update API responses and wiring for canonical IDs (`asset_id`, `event_id`, `event_item_id`).
- **PR4**: **`ansible/roles/docker/files/apache/webroot/src/Services/UploadService.php`**: port upload persistence from legacy tables to canonical tables while preserving endpoint paths.
- **PR4**: **`ansible/roles/docker/files/apache/webroot/src/Repositories/FileRepository.php`**: stop being the primary write target; keep only if needed for delete/legacy cleanup.
- **PR4**: **`ansible/roles/docker/files/apache/webroot/src/Repositories/EventItemRepository.php`**: new canonical writes for event-scoped typed labels.
- **PR4**: **`ansible/roles/docker/files/apache/webroot/db/upload_form.php`**: update manual upload UI to use canonical endpoints/fields and ensure finalize has enough metadata.

- **PR5**: **`ansible/roles/docker/files/apache/webroot/import_manifest_lib.php`**: port manifest import core logic and step reporting to write canonical tables.
- **PR5**: **`ansible/roles/docker/files/apache/webroot/import_manifest_worker.php`**: port worker execution to call canonical import logic.
- **PR5**: **`ansible/roles/docker/files/apache/webroot/import_manifest_add_async.php`**: keep external contract but ensure queued jobs result in canonical writes.
- **PR5**: **`ansible/roles/docker/files/apache/webroot/import_manifest_reload_async.php`**: keep external contract but ensure reload mode truncates/rebuilds canonical tables.
- **PR5**: **`ansible/roles/docker/files/apache/webroot/import_manifest_status.php`**: update status payloads (including any table counts) to reflect canonical tables.
- **PR5**: **`ansible/roles/docker/files/apache/webroot/import_manifest_cancel.php`**: keep cancellation semantics compatible with canonical worker.
- **PR5**: **`ansible/roles/docker/files/apache/webroot/import_manifest_replay.php`**: keep replay semantics compatible with canonical worker.
- **PR5**: **`ansible/roles/docker/files/apache/webroot/import_manifest_jobs.php`**: keep job listing UI compatible with canonical worker results.

- **PR5**: **`ansible/roles/docker/files/apache/webroot/import_database.php`**: port admin 3A CSV reload endpoint to canonical import. **Preferred approach: convert-to-manifest** so the path runs through `import_manifest_worker.php` (W1) and inherits the Unified Ingestion Core automatically. Direct canonical mapping is a fallback only.
- **PR5**: **`ansible/roles/docker/files/apache/webroot/import_normalized.php`**: port admin 3B normalized CSV reload endpoint to canonical import.
- **PR5**: **`ansible/roles/docker/files/apache/webroot/admin.php`**: ensure admin UI sections 3A/3B/4/5 still trigger working canonical import flows (minimal wiring/text changes only).

- **PR5b**: **`ansible/roles/docker/files/apache/webroot/tools/upload_media_by_hash.py`**: port binary copy tool to query/update canonical assets instead of legacy `files`.

- **PR5b**: **`ansible/roles/upload_tests/tasks/assert_db_invariants.yml`**: update DB assertions from legacy `sessions/files` counts to canonical `events/assets` counts.
- **PR5b**: **`ansible/roles/upload_tests/tasks/test_3a.yml`**: update expected invariants to canonical tables while preserving endpoint call to `/import_database.php`.
- **PR5b**: **`ansible/roles/upload_tests/tasks/test_3b.yml`**: update expected invariants to canonical tables while preserving endpoint call to `/import_normalized.php`.
- **PR5b**: **`ansible/roles/upload_tests/tasks/test_4.yml`**: update expected invariants to canonical tables while preserving endpoint call to `/import_manifest_reload_async.php`.
- **PR5b**: **`ansible/roles/upload_tests/tasks/test_5.yml`**: update expected invariants to canonical tables while preserving endpoint call to `/import_manifest_add_async.php`.

- **PR6**: **`ansible/roles/mysql_backup/templates/dbDump.sh.j2`**: add schema-version tagging sidecar metadata for each dump.
- **PR6 (optional)**: **`ansible/roles/mysql_backup/templates/dbRestore.sh.j2`**: read schema-version metadata to reduce restore ambiguity.

- **PR7**: **`docs/API_CURRENT_STATE.md`**: update docs to canonical event/asset vocabulary and actual upload/import behavior.
- **PR7**: **`ansible/roles/docker/files/apache/webroot/docs/openapi.yaml`**: update OpenAPI schemas/fields to canonical IDs and confirm `GET /api/media-files` remains 501.

---

## PR0 (operational): Freeze rollback artifacts

### Rationale
Schema+data remodel is high-risk. A restorable rollback snapshot is the safety net.

### Changes
- Produce a known-good DB dump and store alongside schema/version metadata.

### Files to change/add
- No application code.
- If you want automation:
  - `ansible/roles/mysql_backup/templates/dbDump.sh.j2` (already exists per PR6; PR0 may be a manual run of current tooling).

### Exact changes
- Ensure the dump is produced from the pre-cutover schema.
- Store a schema identifier next to it (details in PR6).

### Verification
1. Confirm dump file exists and is non-zero size.
2. Restore to a clean MySQL instance:
   ```sql
   mysql -u root -p music_db < /path/to/dump.sql
   ```
3. Browse to the web UI root (`/db/database.php`) — page must load without DB errors.
4. Note the dump file path and timestamp; store alongside the rollback plan.
5. **Rollback trigger**: if any subsequent PR produces data corruption or broken UI, restore this dump and roll back application code.

---

## PR1: Canonical schema + bootstrap/loader scripts

### Rationale
The canonical model must exist in bootstrap SQL so fresh installs and rebuilds produce the new runtime schema.

### Changes
- Introduce the canonical tables and constraints:
  - `assets` (unique `checksum_sha256`)
  - `events`
  - `event_items` (typed, event-scoped labels; also serves as the event↔asset join)
- Remove or stop creating legacy runtime tables (`sessions/songs/files/...`) unless they remain required for unrelated features. Under “no compat layer”, the app runtime must not depend on them.

### Files to change/add
- Change:
  - `ansible/roles/docker/files/mysql/externalConfigs/create_music_db.sql`
  - `ansible/roles/docker/files/mysql/externalConfigs/load_and_transform.sql`

### Exact changes
1) `create_music_db.sql`
- Add DDL for canonical tables (names are requirements; columns below are the minimum contract implied by existing tools):
  - `assets`
    - `asset_id` PK
    - `checksum_sha256` CHAR(64) UNIQUE NOT NULL
    - `file_type` ENUM('audio','video') NOT NULL
    - `file_ext` VARCHAR(...) NULL (or derive from stored filename)
    - `source_relpath` VARCHAR(...) NULL (needed for manifest + upload_media_by_hash)
    - `size_bytes` BIGINT NULL
    - `mime_type` VARCHAR(...) NULL
    - `duration_seconds` INT NULL
    - `media_info` JSON NULL
    - `media_info_tool` VARCHAR(...) NULL
    - timestamps
  - `events`
    - `event_id` PK
    - `event_date` DATE NOT NULL
    - `org_name` VARCHAR(128) NOT NULL
    - `event_type` ENUM('band','wedding','other') (or VARCHAR)
    - `title` (optional)
    - additional metadata fields you currently keep on `sessions` (location/keywords/summary/etc.) as needed
    - UNIQUE constraint approximating the current session uniqueness behavior: `(event_date, org_name)` (or add event_time if you need multiples per day)
  - `event_items`
    - `event_item_id` PK
    - `event_id` FK
    - `asset_id` FK
    - `item_type` ENUM(...) or VARCHAR
    - `label` VARCHAR
    - `position` INT NULL (event-local ordering, replacing legacy `files.seq`)
    - unique constraint `(event_id, asset_id)` to prevent duplicate links

2) `load_and_transform.sql`
- Replace the legacy CSV load pipeline with a canonical pipeline.
- Decide one of:
  - (A) New canonical CSV formats (recommended long-term)
  - (B) Keep existing CSV files but map their columns into canonical tables
- Under hard cutover, ensure the loader does not populate only `sessions/songs/files`.
- **Cross-reference**: this file reads from `sessionsXxx.csv`. When the PR5 pre-condition
  adds `org_name` and `event_type` columns to those CSVs, this loader must also be
  updated to read those columns — otherwise fresh installs will silently fall back to
  `org_name = 'default'` instead of the real band name.

### Verification
1. Run a fresh DB bootstrap (via Ansible or direct SQL apply) against the updated `create_music_db.sql`.
2. Confirm canonical tables exist:
   ```sql
   SHOW TABLES LIKE 'assets';
   SHOW TABLES LIKE 'events';
   SHOW TABLES LIKE 'event_items';
   ```
3. Confirm uniqueness constraints:
   ```sql
   SHOW CREATE TABLE assets;        -- must include UNIQUE KEY on checksum_sha256
   SHOW CREATE TABLE events;        -- must include UNIQUE KEY on (event_date, org_name)
   SHOW CREATE TABLE event_items;   -- must include UNIQUE KEY on (event_id, asset_id)
   ```
4. Run the loader (`load_and_transform.sql`) and verify rows are populated:
   ```sql
   SELECT COUNT(*) FROM assets;
   SELECT COUNT(*) FROM events;
   SELECT COUNT(*) FROM event_items;
   ```
   All counts must be > 0 for a non-empty seed dataset.
5. Confirm legacy tables (`sessions`, `songs`, `files`) are either absent or not depended on by any runtime path that runs after this PR.

---

## PR2: Backfill/migration

### Rationale
You need a bridge from existing deployed data to the canonical tables.

### Production migration strategy (per site)

| Site | `APP_FLAVOR` | CSV rebuild | Missing data |
|---|---|---|---|
| gighive2 (dev) | gighive | `sessionsSmall.csv` (Section 3B) | none |
| gighive (staging) | gighive | `sessionsSmall.csv` (Section 3B) | none |
| gighive (lab) | gighive | `sessionsSmall.csv` (Section 3B) | none |
| prod | gighive | `sessionsLarge.csv` (Section 3B) | a few jam sessions not in CSV — see below |

**Pre-condition for all sites**: `sessionsSmall.csv` and `sessionsLarge.csv` must have
`org_name` and `event_type` columns added before the Section 3B rebuild runs (see PR5
pre-condition note).

#### Prod: handling jam sessions not in `sessionsLarge.csv`

Prod has a small number of jam sessions whose media files exist on disk but are not
represented in `sessionsLarge.csv`. Since a DB restore from the PR0 dump will not be
compatible with the post-cutover schema, these cannot be recovered via restore.

**Chosen approach: Section 5 manifest add (post-PR5)**
1. After PR5 ships, the Section 5 manifest add path writes canonical tables.
2. For each missing jam session, submit a manifest add job via the admin UI (Section 5)
   pointing at the media files on disk.
3. The canonical ingest pipeline (`import_manifest_lib.php` + `import_manifest_worker.php`)
   will create the `events`, `assets`, and `event_items` rows.

**⚠️ Do NOT use `admin/admin_database_load_import_media_from_folder.php` post-cutover.**
That tool is not in the PR5 porting scope and still writes to legacy `sessions/songs/files`.
It will fail or silently write nowhere useful once the hard cutover is complete.

**A SQL migration script is not required for any known site** — all data is either covered
by the CSV rebuild or recoverable via the canonical Section 5 manifest add path.

### Changes (optional — only required for sites with live uploads not covered by a CSV rebuild)
- Create a migration/backfill step that:
  - creates `assets` from unique legacy `files.checksum_sha256`
  - creates `events` from legacy `sessions`
  - creates `event_items` rows (link + label) from legacy `files.session_id` and `songs` + join tables

### Files to change/add
- Add (one of):
  - a SQL migration script colocated with DB scripts, or
  - a CLI PHP script colocated with other admin tools.
- Likely add alongside existing DB scripts:
  - `ansible/roles/docker/files/mysql/dbScripts/...` (choose a location consistent with your current DB script conventions)

### Exact changes
- Define a deterministic mapping for event identity:
  - `sessions.date` + `sessions.org_name` -> `events(event_date, org_name)`
- Define a deterministic mapping for asset identity:
  - `files.checksum_sha256` -> `assets.checksum_sha256`
- Define event item mapping:
  - today: basename-derived label stored in `songs.title` and linked by `session_songs` + `song_files`
  - new: create `event_items` per `(event_id, asset_id)` with `item_type` default based on event_type

### Verification
1. Confirm no duplicate checksums in `assets`:
   ```sql
   SELECT checksum_sha256, COUNT(*) AS n
   FROM assets
   GROUP BY checksum_sha256
   HAVING n > 1;
   ```
   Must return 0 rows.
2. Confirm event count matches expected legacy session count:
   ```sql
   SELECT COUNT(*) FROM events;
   SELECT COUNT(*) FROM sessions;   -- legacy; should match
   ```
3. Confirm asset count matches expected legacy unique-file count:
   ```sql
   SELECT COUNT(*) FROM assets;
   SELECT COUNT(DISTINCT checksum_sha256) FROM files;  -- legacy
   ```
4. Confirm every asset has at least one event link:
   ```sql
   SELECT COUNT(*) FROM assets a
   LEFT JOIN event_items ei ON ei.asset_id = a.asset_id
   WHERE ei.event_item_id IS NULL;
   ```
   Should be 0 for a clean migration (all assets are linked to at least one event).
5. *(Skip this PR entirely for known sites using CSV rebuild via PR5.)*

---

## PR3: Media listing cutover (`/db/database.php`) (UI + JSON)

### Rationale
Today’s listing is join-multiplicity prone and session/song/file based. Post-cutover, it must support:

- Librarian view: one row per asset checksum
- Event view: assets shown with event context

### Changes
- Introduce canonical repositories for listings.
- Add a view switch param (`view=librarian|event`) and event selectors.

### Files to change/add
- Change:
  - `ansible/roles/docker/files/apache/webroot/db/database.php`
  - `ansible/roles/docker/files/apache/webroot/src/Controllers/MediaController.php`
  - `ansible/roles/docker/files/apache/webroot/src/Views/media/list.php`
  - `ansible/roles/docker/files/apache/webroot/src/Controllers/RandomController.php` (if it picks random media from legacy tables)
- Replace or supplement:
  - `ansible/roles/docker/files/apache/webroot/src/Repositories/SessionRepository.php`
- Add:
  - `ansible/roles/docker/files/apache/webroot/src/Repositories/AssetRepository.php`
  - `ansible/roles/docker/files/apache/webroot/src/Repositories/EventRepository.php`

### Exact changes
1) `db/database.php`
- Replace `SessionRepository` usage with the canonical repositories.
- Parse query params:
  - `view` default driven by `APP_FLAVOR`: `defaultcodebase` → `event`, `gighive` → `librarian`.
    Also support explicit `view=librarian|event` override.
  - `event_id` for event view
  - keep `format=json` contract

2) `MediaController.php`
- Split listing into two code paths:
  - `listEventView(...)`
  - `listLibrarianView(...)`
- Ensure JSON output is consistent and documented.

3) `AssetRepository.php`
- Librarian query: one row per `assets.checksum_sha256`.
- Provide filters analogous to current ones where feasible.

4) `EventRepository.php` / event view query
- Event query returns assets for a given `event_id` by joining `event_items`.
- Include `item_type`, `label`, `position` from `event_items` as the UI needs them.

### Verification
1. Browse `/db/database.php` — page loads without errors.
2. Browse `/db/database.php?view=librarian` — confirm no duplicate rows for a checksum that appears in multiple events.
3. Browse `/db/database.php?view=event&event_id=<id>` — confirm assets show with event context (event date, org name, label).
4. Check JSON output:
   ```
   GET /db/database.php?format=json
   ```
   Response must be valid JSON and must include `asset_id`, `event_id` (not `session_id` / `song_id`).
5. Confirm `APP_FLAVOR` routing:
   - `APP_FLAVOR=gighive` → default view is librarian.
   - `APP_FLAVOR=defaultcodebase` (stormpigs) → default view is event.
6. Spot-check that no SQL query in `AssetRepository.php` or `EventRepository.php` references `sessions`, `songs`, or `files`.

---

## PR4: Upload API cutover (`/api/uploads`, alias, tusd finalize)

### Rationale
Uploads are a primary ingest path. They must write canonical tables and enforce checksum uniqueness globally.

### Changes
- Port upload write path from `sessions/songs/files` to `events/assets/event_items`.
- Keep endpoint paths stable:
  - `POST /api/uploads`
  - `POST /api/media-files` alias
  - `POST /api/uploads/finalize`

### Files to change/add
- Change:
  - `ansible/roles/docker/files/apache/webroot/src/Controllers/UploadController.php`
  - `ansible/roles/docker/files/apache/webroot/src/Services/UploadService.php`
  - `ansible/roles/docker/files/apache/webroot/db/upload_form.php`
- Replace or supplement repositories:
  - `ansible/roles/docker/files/apache/webroot/src/Repositories/FileRepository.php` (should no longer be the primary write target)
- Change (already created in PR3; extend as needed for write operations):
  - `ansible/roles/docker/files/apache/webroot/src/Repositories/AssetRepository.php`
  - `ansible/roles/docker/files/apache/webroot/src/Repositories/EventRepository.php`
- Add:
  - `ansible/roles/docker/files/apache/webroot/src/Repositories/EventItemRepository.php`
- Change (update assertions to canonical response fields):
  - `ansible/roles/upload_tests/tasks/test_6.yml`
  - `ansible/roles/upload_tests/tasks/test_7.yml`

### Exact changes
1) Request/response contract
- Replace legacy concepts in responses (`session_id`, `seq`) with canonical equivalents:
  - `asset_id`, `event_id`, `event_item_id` (as applicable)
- Maintain checksum + file_type + size + duration metadata fields.

2) `UploadService::handleUpload`
- Replace `ensureSession()` + per-session `seq` logic with:
  - `ensureEvent(event_date, org_name, event_type, ...)`
  - `ensureAsset(checksum_sha256, file_type, ext, size_bytes, mime_type, source_relpath?)`
  - `ensureEventItem(event_id, asset_id, item_type, label, position)` — creates the event↔asset link and label in a single write
- Keep storage behavior (write to `/audio` or `/video` under webroot) unless that is also being changed.

3) `db/upload_form.php` (manual uploader UI)
- Update the legacy form target:
  - Change `action="/api/uploads.php"` to `action="/api/uploads"` (or remove reliance on the form action entirely, since JS intercepts submission).
- Update metadata fields to match canonical write requirements:
  - Replace or supplement `label` with:
    - `item_type` (dropdown)
    - `item_label` (text)
  - Keep `event_date`, `org_name`, `event_type` (they map cleanly to canonical `events`).
  - If the canonical upload flow requires selecting an existing Event, add `event_id` support (optional if `ensureEvent()` by date+org remains the contract).
- Ensure finalize has enough information to perform canonical writes:
  - Today, finalize is called with only `{ upload_id }` and relies on TUS metadata.
  - Under cutover, either:
    - include event + item metadata in the finalize request body, or
    - ensure the server-side finalize path reads and trusts the upload’s TUS metadata and maps it to canonical `events/assets/event_items`.

4) `UploadService::finalizeTusUpload`
- Ensure it shares the same canonical writes as `handleUpload`.

### Verification
1. Run upload tests 6 and 7 (single-file upload and TUS finalize variants):
   ```
   ansible-playbook ansible/playbooks/site.yml --tags upload_tests
   ```
   Tests `test_6.yml` and `test_7.yml` must pass.
2. Manual spot-check — POST a new file to `/api/uploads`:
   - Response must include `asset_id` and `event_id`.
   - Response must **not** include `session_id` or `seq`.
   ```sql
   SELECT COUNT(*) FROM assets;       -- increments by 1
   SELECT COUNT(*) FROM event_items;  -- increments by 1
   ```
3. Duplicate upload test — POST the same file again (same checksum):
   - Response must return HTTP 409 or a dedup-success response (not an error).
   - SQL: `SELECT COUNT(*) FROM assets;` must **not** increase.
   - SQL: `SELECT COUNT(*) FROM event_items;` must **not** increase (link already exists for same event).
4. TUS finalize path — upload via TUS then call `POST /api/uploads/finalize`:
   - Same canonical field assertions as step 2.
5. Confirm `upload_form.php` manual uploader page loads and submits successfully.
6. UI check: browse `/db/database.php` after upload — confirm the new asset appears in the listing with correct event context.
7. Automated test: run `test_4.yml` and `test_5.yml` to ensure canonical writes are correct for multi-file uploads.

---

## PR5: Manifest import cutover (admin Sections 4/5) + port CSV imports (admin Sections 3A/3B)

### Rationale
Admin imports are operationally critical and now covered by tests. They currently write legacy tables.

### Changes
- Rewrite the manifest importer to write canonical tables.
- Port CSV imports to canonical tables. **Preferred: convert-to-manifest** so Sections 3A/3B route through the manifest worker and inherit the Unified Ingestion Core automatically (see `docs/refactor_preasset_librarian_unified_ingestion_core.md`). Direct canonical mapping is a fallback only.

### Files to change/add
Manifest async pipeline (already exists and must be ported):
- Change:
  - `ansible/roles/docker/files/apache/webroot/import_manifest_lib.php`
  - `ansible/roles/docker/files/apache/webroot/import_manifest_worker.php`
  - `ansible/roles/docker/files/apache/webroot/import_manifest_add_async.php`
  - `ansible/roles/docker/files/apache/webroot/import_manifest_reload_async.php`
  - `ansible/roles/docker/files/apache/webroot/import_manifest_status.php`
  - `ansible/roles/docker/files/apache/webroot/import_manifest_cancel.php`
  - `ansible/roles/docker/files/apache/webroot/import_manifest_replay.php`
  - `ansible/roles/docker/files/apache/webroot/import_manifest_jobs.php`

CSV import endpoints (admin Sections 3A/3B):
- Change:
  - `ansible/roles/docker/files/apache/webroot/import_database.php`
  - `ansible/roles/docker/files/apache/webroot/import_normalized.php`

Admin UI (optional text changes only if needed):
- Change (only if UI wording or wiring needs to change):
  - `ansible/roles/docker/files/apache/webroot/admin.php`

### Exact changes
1) Manifest import contract (preserve external interface)
- Keep accepting JSON body with `items` array.
- Preserve quick validation behavior in async endpoints.

2) `import_manifest_lib.php`
- Replace legacy “steps” semantics and underlying DB logic:
  - Remove truncate/seed/upsert logic for `sessions/songs/files`.
  - Replace with canonical equivalents:
    - (reload mode) truncate canonical tables (`event_items`, `events`, `assets` or a safe subset) and reseed any reference data if you add it.
    - ensure events by `(event_date, org_name)` (or your updated uniqueness contract)
    - upsert assets by `checksum_sha256`
    - upsert event_items by `(event_id, asset_id)` (typed label + link in one row)
- Replace basename-derived global label behavior:
  - today: `gighive_manifest_basename_no_ext()` used to create `songs`
  - new: create an `event_item` label, scoped to the event

3) CSV imports (3A/3B)

**Pre-condition — CSV format update required before this PR ships**:
`sessionsSmall.csv` and `sessionsLarge.csv` must gain two new columns:
- `org_name` — the band/org name for the imported events (e.g., `StormPigs` for
  stormpigs/prod and gighive/staging). This becomes the displayed identity in
  `db/database.php` and the uniqueness key for `events`.
- `event_type` — defaults to `band` for all existing rows.

Rationale: `org_name = "default"` is a legacy placeholder. After cutover, the `events`
table must carry a real org identity so users see their band name in the UI. New
installs will populate their own band name in the CSV. Do not update the CSVs yet —
this must be done as a deliberate step immediately before this PR is implemented.

- Keep endpoints + form field names stable.
- Update `import_normalized.php` to read `org_name` and `event_type` from the CSV
  session row; keep a fallback default (`org_name = 'default'`, `event_type = 'band'`)
  for backward compatibility with CSVs that omit the columns.
- Replace the destructive legacy truncation + legacy LOAD DATA pipeline with either:
  - convert CSV(s) to a manifest payload and invoke the canonical importer, or
  - load to staging tables and then canonicalize.

### Verification
**Pre-condition check**: confirm `sessionsSmall.csv` and `sessionsLarge.csv` have `org_name` and `event_type` columns before running any import.

1. **Section 3B (normalized CSV reload)** — trigger from admin UI or directly:
   - SQL after completion:
     ```sql
     SELECT COUNT(*) FROM events;
     SELECT COUNT(*) FROM assets;
     SELECT COUNT(*) FROM event_items;
     ```
   - All counts must match the expected row totals for the CSV dataset.
   - `org_name` must not be `'default'` for rows that have a real band name in the CSV.
2. **Section 3A (CSV reload)** — trigger and run the same SQL checks.
3. **Section 4 (manifest reload)** — trigger an async reload job, poll until complete:
   - SQL: canonical table counts must be populated.
   - Browse `/db/database.php` — data must appear in the listing.
4. **Section 5 (manifest add)** — trigger an add job with a subset of files:
   - SQL: counts increase by the number of added items.
   - Re-run the same add job — counts must **not** change (idempotent by checksum).
5. Spot-check that no write in any of these flows touches `sessions`, `songs`, or `files`.

---

## PR5b (required for PR5 verification): Port binary copy tooling + tests

### Rationale
Tests 4/5 are two-step: (1) import hashes/metadata, (2) copy binaries by checksum and write metadata back.

### Files to change/add
- Change:
  - `ansible/roles/docker/files/apache/webroot/tools/upload_media_by_hash.py`
  - `ansible/roles/upload_tests/tasks/assert_db_invariants.yml`
  - `ansible/roles/upload_tests/tasks/test_3a.yml`
  - `ansible/roles/upload_tests/tasks/test_3b.yml`
  - `ansible/roles/upload_tests/tasks/test_4.yml`
  - `ansible/roles/upload_tests/tasks/test_5.yml`

### Exact changes
1) `upload_media_by_hash.py`
- Replace queries against legacy `files` with canonical `assets` (and optionally join `event_items` if event context is needed).
- Minimum needed query surface:
  - select checksum + file_type + source_relpath for assets that should exist
- Replace updates to legacy `files.duration_seconds/media_info/media_info_tool` with updates to canonical `assets.*`.

2) `assert_db_invariants.yml`
- Replace:
  - `SELECT COUNT(*) FROM sessions` / `files`
- With canonical invariants, minimally:
  - `SELECT COUNT(*) FROM events`
  - `SELECT COUNT(*) FROM assets`
  - Optionally verify link table counts and uniqueness constraints.

### Verification
1. Run the full upload_tests suite:
   ```
   ansible-playbook ansible/playbooks/site.yml --tags upload_tests
   ```
   All of the following must pass: `test_3a.yml`, `test_3b.yml`, `test_4.yml`, `test_5.yml`, `test_6.yml`, `test_7.yml`, `assert_db_invariants.yml`.
2. Confirm `assert_db_invariants.yml` assertions reference `events` and `assets` (not `sessions` and `files`).
3. Confirm `upload_media_by_hash.py` queries `assets` for checksum lookups and writes `assets.duration_seconds` / `assets.media_info` (not `files.*`).
4. After a full test run, spot-check canonical counts:
   ```sql
   SELECT COUNT(*) FROM assets;
   SELECT COUNT(*) FROM events;
   SELECT COUNT(*) FROM event_items;
   SELECT COUNT(*) FROM assets WHERE duration_seconds IS NOT NULL;  -- populated by upload_media_by_hash
   ```

---

## PR6: Backup schema-version tagging

### Rationale
After cutover, restores must be unambiguous about schema compatibility.

### Files to change/add
- Change:
  - `ansible/roles/mysql_backup/templates/dbDump.sh.j2`
  - (optional) `ansible/roles/mysql_backup/templates/dbRestore.sh.j2`

### Exact changes
- Add a sidecar file written alongside each dump (e.g., `schema_version.txt` or JSON) containing:
  - git SHA (if available)
  - schema version string (manual constant or derived)
  - timestamp

### Verification
1. Trigger a DB dump.
2. Confirm a sidecar file is written alongside the `.sql` dump with a matching timestamp prefix or name.
3. Open the sidecar and confirm it contains:
   - a `schema_version` field
   - a `timestamp` field
   - a `git_sha` field (or a clear `N/A` if git is unavailable in the dump context)
4. Simulate a restore scenario: given only the dump file + sidecar, confirm you can determine whether the dump is pre- or post-canonical-cutover without looking at the SQL.

---

## PR7: Docs cleanup / alignment

### Rationale
Docs must match behavior and prevent accidental re-introduction of legacy assumptions.

### Files to change/add
- Change:
  - `docs/API_CURRENT_STATE.md`
  - `ansible/roles/docker/files/apache/webroot/docs/openapi.yaml`
  - (optional) `docs/pr_librarianAsset_musicianSession_changeSet.md` (to reflect any implementation-driven deltas)

### Exact changes
- Update payload schemas to reference canonical concepts (`event_id`, `asset_id`, `event_item_id`).
- Ensure `GET /api/media-files` remains 501.

### External application impact (iPhone app and any other API consumers)

**Any external application that consumes this API — including the GigHive iPhone app —
must be updated, because the API contract defined in `openapi.yaml` will change.**
The server-side cutover (PR3 + PR4) and the client-side update must be coordinated;
shipping the server changes without a corresponding client update will cause the client
to receive responses it does not understand.

Specifically, the following fields change in API responses:

| Legacy field | Canonical replacement | Affected endpoint |
|---|---|---|
| `session_id` | `event_id` | `POST /api/uploads`, `/api/uploads/finalize` |
| `seq` | `position` | same |
| session/song/file JSON shape | `asset_id`, `event_id`, `item_type`, `label`, `position` | `db/database.php` listing |

The following fields survive unchanged and require no client update:
- `checksum_sha256`, `file_type`, `duration_seconds`, `mime_type`, `size_bytes`

The following endpoint URLs are kept stable and require no client update:
- `POST /api/uploads`, `POST /api/media-files` (alias), `POST /api/uploads/finalize`, `db/database.php`

PR7 is the correct point to freeze and publish the updated `openapi.yaml` so external
client developers (including the iPhone app) have an accurate contract to code against.

### Verification
1. Validate `openapi.yaml` is well-formed (view in Swagger UI at `/docs/api-docs.html` — no red parse errors).
2. Confirm the following fields appear in the `UploadResult` schema in `openapi.yaml` and are absent from legacy names:
   - `asset_id` present, `session_id` absent
   - `event_id` present
   - `position` present, `seq` absent
3. Make a live `POST /api/uploads` request and compare the actual response fields against `openapi.yaml` — they must match.
4. Confirm `GET /api/media-files` still shows `501` in the spec and returns 501 from the server.
5. Review `docs/API_CURRENT_STATE.md` — confirm it describes canonical tables and does not reference `sessions/songs/files` as the authoritative runtime schema.
6. Distribute the updated `openapi.yaml` to iPhone app developer(s) and confirm they have received and acknowledged the field-level breaking changes (`session_id`→`event_id`, `seq`→`position`) before any coordinated client release.

---

## Cross-cutting checklist (apply across PRs)

- DB uniqueness invariants
  - assets unique by checksum
  - event_items unique by (event_id, asset_id)
- “Same binary, different event” behavior
  - must create a new link and new event item, not reject.
- Operational invariants
  - admin 3A/3B and 4/5 endpoints remain callable with the same URLs and basic payload shapes, but now write canonical tables.
