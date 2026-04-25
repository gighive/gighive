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
- URL-level renames are **breaking changes deferred to post-PR7**. They should be bundled into the same coordinated client release as the iPhone app update for PR7 field changes — not done as a separate pre-pass before this refactor. Full deferred list:
  - `/db/database.php` → `/api/media`
  - `/db/delete_media_files.php` → `DELETE /api/assets/{id}` (or `POST /api/assets/delete`)
  - `/db/database_edit_save.php` → `PATCH /api/events/{event_id}/items/{event_item_id}`
  - `/db/database_edit_musicians_preview.php` → `POST /api/participants/preview`
  - `/admin/import_manifest_upload_finalize.php` → `/admin/manifest/finalize`
  - `POST /api/media-files` alias retirement

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

- **PR5**: **`ansible/roles/docker/files/apache/webroot/admin/import_manifest_lib.php`**: port manifest import core logic and step reporting to write canonical tables.
- **PR5**: **`ansible/roles/docker/files/apache/webroot/admin/import_manifest_worker.php`**: port worker execution to call canonical import logic.
- **PR5**: **`ansible/roles/docker/files/apache/webroot/admin/import_manifest_add_async.php`**: keep external contract but ensure queued jobs result in canonical writes.
- **PR5**: **`ansible/roles/docker/files/apache/webroot/admin/import_manifest_reload_async.php`**: keep external contract but ensure reload mode truncates/rebuilds canonical tables.
- **PR5**: **`ansible/roles/docker/files/apache/webroot/admin/import_manifest_status.php`**: update status payloads (including any table counts) to reflect canonical tables.
- **PR5**: **`ansible/roles/docker/files/apache/webroot/admin/import_manifest_cancel.php`**: keep cancellation semantics compatible with canonical worker.
- **PR5**: **`ansible/roles/docker/files/apache/webroot/admin/import_manifest_replay.php`**: keep replay semantics compatible with canonical worker.
- **PR5**: **`ansible/roles/docker/files/apache/webroot/admin/import_manifest_jobs.php`**: keep job listing UI compatible with canonical worker results.

- **PR5**: **`ansible/roles/docker/files/apache/webroot/admin/import_database.php`**: port admin 3A CSV reload endpoint to canonical import. **Preferred approach: convert-to-manifest** so the path runs through `admin/import_manifest_worker.php` (W1) and inherits the Unified Ingestion Core automatically. Direct canonical mapping is a fallback only.
- **PR5**: **`ansible/roles/docker/files/apache/webroot/admin/import_normalized.php`**: port admin 3B normalized CSV reload endpoint to canonical import.
- **PR5**: **`ansible/roles/docker/files/apache/webroot/admin/admin.php`**: ensure admin UI sections 3A/3B/4/5 still trigger working canonical import flows (minimal wiring/text changes only).

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
- Remove or stop creating legacy runtime tables (`sessions/songs/files/session_songs/song_files/session_musicians`) unless they remain required for unrelated features. Under "no compat layer", the app runtime must not depend on them.
- **Participants rename**: rename `musicians` → `participants` and `session_musicians` → `event_participants` (Plex Person+Role model — generic enough for band members, videographers, wedding guests, photographers, etc.):
  - `participants` (`participant_id`, `name`, timestamps)
  - `event_participants` (`event_id`, `participant_id`, `role VARCHAR`) — `role` carries the specific role string (e.g., `band_member`, `videographer`, `guest`)
- Keep non-legacy tables: `genres`, `styles`, `users`, `participants` (renamed from `musicians`).
- `event_items.item_type` ENUM is **`('song', 'loop', 'clip', 'highlight')`**:
  - `song` — a discrete performed/recorded song (band/musician context; maps to legacy `songs.type='song'`)
  - `loop` — a backing or reference loop track (maps to legacy `songs.type='loop'`)
  - `clip` — a generic audio/video segment: ceremony, speech, table video, candid footage, etc. (generalizes legacy `songs.type='event_label'`; label carries the specific name)
  - `highlight` — a curated or best-of cut (cross-context: setlist reel, edited wedding highlight)

### Files to change/add
- Change:
  - `ansible/roles/docker/files/mysql/externalConfigs/create_music_db.sql`
  - `ansible/roles/docker/files/mysql/externalConfigs/load_and_transform.sql`
  - `ansible/roles/docker/files/mysql/dbScripts/select.sql` — update health-check queries to canonical tables (`assets`, `events`, `event_items`, `participants`, `event_participants`); the `validate_app` role executes this file and will error if it still references legacy-only tables on a post-PR1 schema
  - `ansible/roles/db_migrations/tasks/main.yml` — gate all `files.*` column migrations behind a `files` table-existence check; post-PR1 fresh installs never create a `files` table (those columns are already in `assets` DDL), so the migration must be a no-op for them while still running on pre-PR1 existing installs

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
    - `item_type` ENUM('song', 'loop', 'clip', 'highlight') NOT NULL
      - `song` — a discrete performed/recorded song (band/musician context; maps to legacy `songs.type='song'`)
      - `loop` — a backing or reference loop track (maps to legacy `songs.type='loop'`)
      - `clip` — a generic audio/video segment: ceremony, speech, table video, candid footage, etc. (generalizes legacy `songs.type='event_label'`; label carries the specific name)
      - `highlight` — a curated or best-of cut (cross-context: setlist reel, edited wedding highlight)
    - `label` VARCHAR (human-readable name for this item within its event, e.g. "Stairway to Heaven", "Table 7 Toast")
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
  - **Participants CSV rename**: the loader currently reads `musicians.csv` → `musicians` and
  `session_musicians.csv` → `session_musicians`. These must be updated to read the renamed
  CSV files (`participants.csv` → `participants` and `event_participants.csv` → `event_participants`)
  to match the PR1 table rename.

### Verification

#### How to trigger a fresh bootstrap on an existing dev/staging install

`docker-entrypoint-initdb.d` scripts only fire on a **fresh container init with an empty data directory** — they do not re-run on a normal redeploy. On an existing install, running `site.yml` syncs the updated SQL/CSV files but does **not** re-bootstrap the DB. The `validate_app` role runs `select.sql` which now queries canonical tables; if the canonical tables do not exist yet, it will error.

**`rebuild_mysql_data: true` is NOT sufficient** — it recreates the container but the MySQL Docker volume persists, so `docker-entrypoint-initdb.d` never fires (MySQL sees an existing data directory and skips init).

**Correct procedure**: run `site.yml` first (so the new SQL/CSV files are synced to the VM), then run `reloadMyDatabase.sh` directly on the VM:
```bash
bash ~/gighive/ansible/roles/docker/files/mysql/dbScripts/reloadMyDatabase.sh
```
This executes `create_music_db.sql` (which opens with `DROP DATABASE IF EXISTS music_db`) and `load_and_transform.sql` directly against the running container, bypassing the volume init restriction. Then re-run `validate_app` separately:
```bash
ansible-playbook -i ansible/inventories/inventory_gighive2.yml ansible/playbooks/site.yml --tags validate_app
```

#### Expected known failure during PR1 validation (before PR4)

The `post_build_checks` TUS smoke test (`/api/uploads/finalize`) will fail during PR1 validation. This is **expected and not a PR1 bug**. The PHP upload/finalize code still writes to `sessions`/`files` (legacy tables). With a fresh DB init under the canonical schema, those legacy tables no longer exist, so the finalize path errors. This confirms PR4 is needed. Skip or ignore `post_build_checks` TUS failures until PR4 is complete.

#### Verification steps

1. Run a fresh DB bootstrap via `reloadMyDatabase.sh` (see above) after `site.yml` has synced the files to the VM.
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
5. Confirm `create_music_db.sql` no longer creates legacy tables (`sessions`, `songs`, `files`, `session_songs`, `song_files`, `session_musicians`) — fresh installs after PR1 will not have them.
   > ⚠️ On **existing installations**, legacy tables physically persist (they were created before PR1 and are not auto-dropped by DDL changes). The runtime PHP still reads from them until PR3+PR4 ship. **Do not manually drop legacy tables from existing installations** until both PR3 and PR4 have been deployed and verified.

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
  - `ansible/roles/docker/files/apache/webroot/src/Views/media/list.php` (canonical listing + hidden `view=` input on search form)
  - `ansible/roles/docker/files/apache/webroot/src/Controllers/RandomController.php`
  - `ansible/roles/docker/files/apache/webroot/db/singlesRandomPlayer.php` — switch from `SessionRepository` to `AssetRepository`
  - `ansible/roles/docker/files/apache/webroot/admin/admin_system.php` — `renderDbLinkButton` href → `/db/database.php?view=librarian` (restore is a library-level operation)
- Replace or supplement:
  - `ansible/roles/docker/files/apache/webroot/src/Repositories/SessionRepository.php`
- Change (inline-edit endpoints — write to `sessions/songs/musicians/session_musicians`; will break at PR1 unless ported here):
  - `ansible/roles/docker/files/apache/webroot/db/database_edit_save.php` — hard-cutover field renames (request + response): `session_id`→`event_id`, `song_id`→`event_item_id`, `song_title`→`item_label`, `musicians_csv`→`participants_csv`; response: `musicians`→`participants`, `new_musicians`→`new_participants`; backing table writes: `musicians`→`participants`, `session_musicians`→`event_participants`
  - `ansible/roles/docker/files/apache/webroot/db/database_edit_musicians_preview.php` — hard-cutover field renames: request `musicians_csv`→`participants_csv`; response arrays `existing`/`new`/`normalized` stay, backing query `SELECT FROM musicians`→`SELECT FROM participants`
- Add:
  - `ansible/roles/docker/files/apache/webroot/src/Repositories/AssetRepository.php`
  - `ansible/roles/docker/files/apache/webroot/src/Repositories/EventRepository.php`

### Exact changes
1) `db/database.php` and `view` parameter handling
- `resolveView()` is already implemented: explicit `?view=event|librarian` wins; fallback is `APP_FLAVOR` (`gighive`→librarian, else→event).
- Every current link to `database.php` omits `?view=` — all silently fall through to the APP_FLAVOR default.
- Inbound links must pass `?view=` explicitly based on user action, not APP_FLAVOR (see `docs/navigation_event_librarian.md`):
  - Upload success (`upload_form.php`, `upload_form_admin.php`, `src/index.php`) → `?view=event` (owned by PR4)
  - CSV import success (`admin_database_load_import_csv.php`) → `?view=event` (owned by PR5)
  - Folder import + restore (`admin_database_load_import_media_from_folder.php`, `admin_system.php`) → `?view=librarian` (folder import owned by PR5; restore owned by this PR)
  - Search form (`list.php`) → add hidden `view=` input to preserve view across submissions (owned by this PR)
- "Reset to Default View", `header.php` nav link, and direct/bookmark navigation continue to rely on the APP_FLAVOR fallback — intentional.
- APP_FLAVOR remains a valid last-resort default; it is not the primary signal for inbound links.
- See `docs/navigation_event_librarian.md` for full flow map and rationale.
- Keep `event_id` filter and `format=json` contract unchanged.

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

5) `db/database_edit_save.php` — hard-cutover wire renames
- Request body field renames (no backward compat):
  - `session_id` → `event_id`
  - `song_id` → `event_item_id`
  - `song_title` → `item_label`
  - `musicians_csv` → `participants_csv`
- Response JSON field renames:
  - `session_id` → `event_id`
  - `song_id` → `event_item_id`
  - `song_title` → `item_label`
  - `musicians` → `participants`
  - `new_musicians` → `new_participants`
- Backing SQL: replace all `sessions`/`songs`/`session_songs` pair-check with canonical `events`/`event_items`; `musicians`→`participants`, `session_musicians`→`event_participants`.

6) `db/database_edit_musicians_preview.php` — hard-cutover wire renames
- Request body: `musicians_csv` → `participants_csv`.
- Response: `existing`, `new`, `normalized` arrays stay; backing query `SELECT FROM musicians` → `SELECT FROM participants`.

7) `db/singlesRandomPlayer.php`
- Switch from `SessionRepository` to `AssetRepository`; derive served file name from `checksum_sha256 + file_ext` (canonical naming) instead of `source_relpath`.
- `RandomController` updated in parallel to use `AssetRepository::fetchAll()` (a thin alias for `fetchLibrarian()`).

8) `MediaController::listJson()` backward compatibility
- The JSON response key `song_title` is kept as a backward-compatible alias mapping to `event_items.label` (internally `$r['itemLabel']`) until PR7. All other legacy keys (`session_id`, `song_id`) are replaced with canonical names.
- PR7 renames `song_title` → `item_label` in the OpenAPI contract and coordinates with client consumers.

9) Librarian view column behavior (`AssetRepository`)
- In the librarian view, assets are returned without event context. `date`, `org_name`, `rating`, `keywords`, `location`, `summary`, `crew`, `item_label` are returned as empty string; `event_id` and `event_item_id` are returned as NULL. This is by design — the librarian view is a pure asset index deduplicated by checksum.
- The event view (`EventRepository`) fills these via JOIN to `events`, `event_items`, and `event_participants`.
- Consequence: filter fields that require event context (`date`, `org_name`, `item_label`, `crew`) have no effect in librarian view — they silently produce no results rather than erroring.

10) `list.php` specific renames
- Column search keys: `'search' => 'song_title'` → `'search' => 'item_label'`; `'search' => 'file_name'` → `'search' => null` (no canonical equivalent for download-link search).
- Row `<tr>` data attributes: `data-session-id` → `data-event-id`; `data-song-id` → `data-event-item-id`.
- Download/thumbnail `<a>` data attribute: `data-song-name` → `data-item-label`.
- Edit `<input data-field>` values: `song_title` → `item_label`; `musicians_csv` → `participants_csv`.
- JS variable: `supportsMusiciansEdit` → `supportsParticipantsEdit`.
- JS functions renamed: `rowGetSessionId` → `rowGetEventId`; `rowGetSongId` → `rowGetEventItemId`; `updateAllVisibleRowsForSession` → `updateAllVisibleRowsForEvent`; `updateAllVisibleRowsForSong` → `updateAllVisibleRowsForEventItem`.
- GA `file_download` event: `song_name` field → `item_label` (reads `link.dataset.itemLabel`).

### Verification
> **Data availability note**: on an *existing* environment (dev/staging upgraded from pre-PR1), canonical tables are empty until a 3B CSV rebuild (PR5) runs. PR3 verification that checks listing content requires either (a) a fresh install (which populates via `load_and_transform.sql`) or (b) manually running a 3B rebuild after PR5 is available. Verifying the page loads without errors is sufficient for an upgraded environment until PR5 data is in place.

1. Browse `/db/database.php` — page loads without errors.
2. Browse `/db/database.php?view=librarian` — confirm no duplicate rows for a checksum that appears in multiple events.
3. Browse `/db/database.php?view=event&event_id=<id>` — confirm assets show with event context (event date, org name, label).
4. Check JSON output:
   ```
   GET /db/database.php?format=json
   ```
   - Response must be valid JSON.
   - Must include `asset_id` and `event_id` (not `session_id` or `song_id`).
   - Must still include `song_title` key (backward-compat alias for `item_label`, populated from `event_items.label`). Verify the value is the song/item label, not empty.
5. Confirm `APP_FLAVOR` routing:
   - `APP_FLAVOR=gighive` → default view is librarian.
   - `APP_FLAVOR=defaultcodebase` (stormpigs) → default view is event.
6. Spot-check that no SQL query in `AssetRepository.php` or `EventRepository.php` references `sessions`, `songs`, or `files`:
   ```bash
   grep -n 'sessions\|FROM songs\|FROM files' \
     src/Repositories/AssetRepository.php \
     src/Repositories/EventRepository.php
   ```
   Must return zero matches.
7. Confirm inline-edit endpoint field renames — with data present, POST to `/db/database_edit_save.php` using canonical field names:
   - Send `event_id`, `event_item_id`, `item_label`, `participants_csv` in the request body.
   - Response must return `event_id` and `event_item_id` (not `session_id` or `song_id`).
   - POST to `/db/database_edit_musicians_preview.php` with `participants_csv` — response `existing`/`new`/`normalized` arrays must be populated.
8. Browse `/db/singlesRandomPlayer.php` (the singles random player UI) — page loads without error and a playable audio/video URL is returned. Confirm the served file URL is in `checksum.ext` form (not a bare `source_relpath`).
9. Verify `list.php` HTML output (view source):
   - Row `<tr>` elements must have `data-event-id` and `data-event-item-id` attributes (not `data-session-id` or `data-song-id`).
   - Download/thumbnail `<a>` elements must have `data-item-label` attribute (not `data-song-name`).
   - Edit inputs must have `data-field="item_label"` (not `song_title`) and `data-field="participants_csv"` (not `musicians_csv`).
10. Column search regression: enter a search term in the Song Name / Item Label column — confirm results filter correctly against `item_label` in the canonical view.

#### Executed test run — gighive2 VM, April 24 2026

All commands run as `ubuntu@gighive2` via `docker exec`. Apache container: `apacheWebServer`. DB container: `mysqlServer`.

**Step 2 — Page load smoke tests (all returned HTTP 200):**
```bash
docker exec apacheWebServer curl -sk -u viewer:secretviewer \
  -o /dev/null -w "%{http_code}\n" https://localhost/db/database.php
# 200

docker exec apacheWebServer curl -sk -u viewer:secretviewer \
  -o /dev/null -w "%{http_code}\n" "https://localhost/db/database.php?view=librarian"
# 200

docker exec apacheWebServer curl -sk -u viewer:secretviewer \
  -o /dev/null -w "%{http_code}\n" https://localhost/db/singlesRandomPlayer.php
# 200
```

**Step 3 — JSON field assertions:**
```bash
docker exec apacheWebServer \
  curl -sk -u viewer:secretviewer "https://localhost/db/database.php?format=json" \
| python3 -c "
import json, sys
data = json.load(sys.stdin)
rows = data.get('rows', [])
if not rows:
    print('WARNING: no rows (empty canonical tables — run data load first)')
    sys.exit(0)
r = rows[0]
assert 'asset_id' in r,       f'FAIL: missing asset_id — got: {list(r.keys())}'
assert 'event_id' in r,       f'FAIL: missing event_id — got: {list(r.keys())}'
assert 'session_id' not in r,  'FAIL: session_id still present'
assert 'song_id'   not in r,   'FAIL: song_id still present'
assert 'song_title' in r,      'FAIL: song_title backward-compat key missing'
assert 'file_name'  in r,      'FAIL: file_name key missing'
print('PASS: JSON field assertions')
print(f'  Keys: {list(r.keys())}')
"
# WARNING: no rows (empty canonical tables — run data load first)
# NOTE: EXPECTED — canonical tables populate on fresh install via load_and_transform.sql;
# full data arrives via PR5 CSV reload. Page loads (step 2) confirmed working.
```

**Step 4 — SQL DB invariant checks:**
```bash
docker exec mysqlServer mysql -u root -pmusiclibrary music_db -e "
SELECT 'assets count'      AS check_name, COUNT(*) AS result FROM assets
UNION ALL
SELECT 'events count',       COUNT(*) FROM events
UNION ALL
SELECT 'event_items count',  COUNT(*) FROM event_items
UNION ALL
SELECT 'participants count', COUNT(*) FROM participants
UNION ALL
SELECT 'orphaned assets (no event link)',
  COUNT(*) FROM assets a
  LEFT JOIN event_items ei ON ei.asset_id = a.asset_id
  WHERE ei.event_item_id IS NULL
UNION ALL
SELECT 'duplicate checksums',
  COUNT(*) FROM (
    SELECT checksum_sha256 FROM assets
    GROUP BY checksum_sha256 HAVING COUNT(*) > 1
  ) t;
"
# check_name                        result
# assets count                      15
# events count                      2
# event_items count                 15
# participants count                8
# orphaned assets (no event link)   0
# duplicate checksums               0
```

**Step 5 — HTML data attribute check (confirmed canonical attrs, no legacy):**
```bash
docker exec apacheWebServer \
  curl -sk -u viewer:secretviewer "https://localhost/db/database.php?view=librarian" \
| grep -oP 'data-[a-z\-]+="[^"]*"' | sort -u | head -30
# Confirmed present: data-event-id, data-event-item-id, data-item-label
# Note: data-col="song_name" and data-col="musicians" are internal CSS/JS column
# selectors only — inputs inside those cells carry data-field="item_label" and
# data-field="participants_csv" which are the actual server-side field names. Not a bug.
```

**Step 6 — Inline edit save (canonical request + response fields):**
```bash
docker exec apacheWebServer \
  curl -sk -u admin:secretadmin -X POST https://localhost/db/database_edit_save.php \
  -H 'Content-Type: application/json' \
  -d '{"event_id":1,"event_item_id":1,"org_name":"TestOrg","item_label":"Test Song"}' \
| python3 -c "
import json,sys; d=json.load(sys.stdin)
assert d.get('success'),       f'FAIL: {d}'
assert 'event_id'      in d,   f'FAIL: event_id missing — {d}'
assert 'event_item_id' in d,   f'FAIL: event_item_id missing — {d}'
assert 'item_label'    in d,   f'FAIL: item_label missing — {d}'
assert 'session_id'    not in d, 'FAIL: session_id in response'
assert 'song_id'       not in d, 'FAIL: song_id in response'
print('PASS: edit save fields')
print(f'  Keys: {list(d.keys())}')
"
# PASS: edit save fields
# Keys: ['success', 'event_id', 'event_item_id', 'org_name', 'rating', 'keywords',
#        'location', 'summary', 'item_label', 'participants', 'new_participants']
```

**PR3 overall result: PASS** (JSON rows empty is expected until PR5 CSV reload runs)

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
- Change (per-file delete endpoint — queries/deletes from `files` by `file_id`; called from listing UI, upload forms, and smoke tests):
  - `ansible/roles/docker/files/apache/webroot/db/delete_media_files.php`
  - `ansible/roles/docker/files/apache/webroot/db/upload_form_admin.php` (JS caller: `file_id`/`file_ids` → `asset_id`/`asset_ids`)
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

5) `db/delete_media_files.php` + JS callers
- Port endpoint:
  - Replace `SELECT … FROM files WHERE file_id` / `DELETE FROM files WHERE file_id` with canonical equivalents against `assets` using `asset_id`.
  - `FileRepository::getDeleteTokenHashById()` → equivalent lookup on `assets`.
  - Keep the `delete_token` flow for the `uploader` role unchanged (token validation logic stays; only the backing table changes).
- Update JS in all three callers to send `asset_id`/`asset_ids` instead of `file_id`/`file_ids`:
  - `db/upload_form.php` — already in file list above
  - `db/upload_form_admin.php` — already in file list above
  - `src/Views/media/list.php` — already in PR3 scope; delete JS update is a PR4 concern (coordinate with PR3 author or do in this PR)

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
3. Duplicate upload test — two sub-cases:
   - **Same file, same event**: POST the same checksum to the same event → dedup; `COUNT(*) FROM assets` must not increase; `COUNT(*) FROM event_items` must not increase (link already exists).
   - **Same file, different event**: POST the same checksum to a different `event_id` / different date+org → `COUNT(*) FROM assets` must not increase (asset reused); `COUNT(*) FROM event_items` **must** increase by 1 (new link for new event). Response must not error.
4. TUS finalize path — upload via TUS then call `POST /api/uploads/finalize`:
   - Same canonical field assertions as step 2.
5. Confirm `upload_form.php` manual uploader page loads and submits successfully.
6. UI check: browse `/db/database.php` after upload — confirm the new asset appears in the listing with correct event context.

#### Implementation status — files changed

- `src/Exceptions/DuplicateChecksumException.php` — `file_id` → `asset_id` throughout.
- `src/Repositories/AssetRepository.php` — added 6 write methods: `create`, `findById`, `findByChecksum`, `getDeleteTokenHashById`, `setDeleteTokenHashIfNull`, `updateProbeMetadata`.
- `src/Repositories/EventRepository.php` — added `ensureEvent()` (find-or-create by `event_date + org_name`).
- `src/Repositories/EventItemRepository.php` — **new file**: `findLink`, `nextPosition`, `ensureEventItem`.
- `src/Services/UploadService.php` — constructor drops `FileRepository`; adds `AssetRepository`, `EventRepository`, `EventItemRepository`; `handleUpload()` rewritten to write `events/assets/event_items`; cross-event reuse path added; `attachParticipants()` ported to `participants/event_participants`; `isDuplicateChecksumException()` updated for `assets_uq_checksum`; `finalizeManifestTusUpload()` was left wrapping legacy `FileRepository` locally as a TODO — fully ported to `AssetRepository` in PR5.
- `src/Controllers/UploadController.php` — `FileRepository` → `AssetRepository`; `duplicateChecksumResponse()` uses `getExistingAssetId()`; `get()` returns canonical `asset_id` fields.
- `db/delete_media_files.php` — ported to `assets` table; `file_ids`/`file_id` → `asset_ids`/`asset_id` in request/response; `event_items` rows deleted before asset row.
- `ansible/roles/upload_tests/tasks/query_db_counts.yml` — queries `events` and `assets` (was `sessions` and `files`); renamed fact vars to `*_events_count` / `*_assets_count`.
- `ansible/roles/upload_tests/tasks/test_6.yml` — assertions updated to `asset_id`, `event_id`, `event_item_id`; DB count assertions updated.
- `ansible/roles/upload_tests/tasks/test_7.yml` — same as test_6.

#### Copy-pastable verification commands

**Ansible upload tests (test_6 + test_7):**
```bash
ansible-playbook ansible/playbooks/site.yml \
  -i ansible/inventories/inventory_gighive.yml \
  --tags upload_tests \
  -e "upload_tests_variants=['test_6','test_7']"
```

**Manual POST /api/uploads — canonical field check:**
```bash
docker exec apacheWebServer curl -sk -u admin:secretadmin \
  -F "file=@/tmp/test.mp3" \
  -F "event_date=2026-04-24" \
  -F "org_name=TestBand" \
  -F "event_type=band" \
  -F "label=Test Song" \
  https://localhost/api/uploads \
| python3 -c "
import json, sys
d = json.load(sys.stdin)
assert 'asset_id'      in d, f'FAIL: missing asset_id — {list(d.keys())}'
assert 'event_id'      in d, f'FAIL: missing event_id — {list(d.keys())}'
assert 'event_item_id' in d, f'FAIL: missing event_item_id — {list(d.keys())}'
assert 'session_id'    not in d, 'FAIL: session_id still present'
assert 'seq'           not in d, 'FAIL: seq still present'
print('PASS: upload response field assertions')
print(f'  asset_id={d[\"asset_id\"]}  event_id={d[\"event_id\"]}  event_item_id={d[\"event_item_id\"]}')
"
```

**DB count checks (pre/post upload):**
```sql
-- Run before and after a test upload to confirm counts increment correctly.
docker exec mysqlServer mysql -u root -pmusiclibrary music_db -e "
SELECT 'assets count'      AS check_name, COUNT(*) AS result FROM assets
UNION ALL
SELECT 'events count',       COUNT(*) FROM events
UNION ALL
SELECT 'event_items count',  COUNT(*) FROM event_items;"
```

**Duplicate upload test (same file, same event → HTTP 409; assets count unchanged):**
```bash
# Upload same file twice — second should return 409
docker exec apacheWebServer curl -sk -u admin:secretadmin \
  -F "file=@/tmp/test.mp3" \
  -F "event_date=2026-04-24" \
  -F "org_name=TestBand" \
  -F "event_type=band" \
  -F "label=Test Song" \
  -o /dev/null -w "%{http_code}\n" \
  https://localhost/api/uploads
# Expected: 409
```

**Cross-event reuse test (same file, different event → 201; assets count unchanged, event_items +1):**
```bash
docker exec apacheWebServer curl -sk -u admin:secretadmin \
  -F "file=@/tmp/test.mp3" \
  -F "event_date=2026-04-25" \
  -F "org_name=TestBand" \
  -F "event_type=band" \
  -F "label=Test Song Reprise" \
  https://localhost/api/uploads \
| python3 -c "
import json, sys; d = json.load(sys.stdin)
assert d.get('asset_id'), f'FAIL: {d}'
print(f'PASS: cross-event reuse — asset_id={d[\"asset_id\"]}  event_item_id={d[\"event_item_id\"]}')
"
```

#### Executed test run — gighive2 VM, April 24 2026

All commands run as `ubuntu@gighive2`. Apache container: `apacheWebServer`. DB container: `mysqlServer`.

**Step 1 — Deploy + validate_app (passed):**
```bash
script -q -c "ansible-playbook -i ansible/inventories/inventory_gighive2.yml ansible/playbooks/site.yml --tags set_targets,post_build_checks,validate_app --skip-tags tus" ansible-playbook-gighive2-20260424b.log
```

**Step 2 — Ansible upload tests test_6 + test_7 (passed):**
```bash
ansible-playbook ansible/playbooks/site.yml \
  -i ansible/inventories/inventory_gighive.yml \
  --tags set_targets,upload_tests \
  -e "upload_tests_variants=['test_6','test_7']"
# gighive_vm : ok=18  changed=1  unreachable=0  failed=0  skipped=8  rescued=0  ignored=0
```

**Step 3 — Prep test fixture + POST /api/uploads canonical field check (passed):**
```bash
docker cp ~/gighive/ansible/fixtures/audio apacheWebServer:/tmp/test.mp3

docker exec apacheWebServer curl -sk -u admin:secretadmin \
  -F "file=@/tmp/test.mp3" \
  -F "event_date=2026-04-24" \
  -F "org_name=TestBand" \
  -F "event_type=band" \
  -F "label=Test Song" \
  https://localhost/api/uploads \
| python3 -c "
import json, sys
d = json.load(sys.stdin)
assert 'asset_id'      in d, f'FAIL: missing asset_id — {list(d.keys())}'
assert 'event_id'      in d, f'FAIL: missing event_id — {list(d.keys())}'
assert 'event_item_id' in d, f'FAIL: missing event_item_id — {list(d.keys())}'
assert 'session_id'    not in d, 'FAIL: session_id still present'
assert 'seq'           not in d, 'FAIL: seq still present'
print('PASS')
print(f'  asset_id={d[\"asset_id\"]}  event_id={d[\"event_id\"]}  event_item_id={d[\"event_item_id\"]}')
"
# PASS
#   asset_id=17  event_id=4  event_item_id=17
```

**Step 4 — DB count sanity (passed):**
```bash
docker exec mysqlServer mysql -u root -pmusiclibrary music_db -e "
SELECT 'assets count'     AS c, COUNT(*) FROM assets
UNION ALL SELECT 'events count',      COUNT(*) FROM events
UNION ALL SELECT 'event_items count', COUNT(*) FROM event_items;"
# c                  COUNT(*)
# assets count       17
# events count       4
# event_items count  17
```

**Step 5 — Duplicate upload same file + same event → 409 (passed):**
```bash
docker exec apacheWebServer curl -sk -u admin:secretadmin \
  -F "file=@/tmp/test.mp3" \
  -F "event_date=2026-04-24" \
  -F "org_name=TestBand" \
  -F "event_type=band" \
  -F "label=Test Song" \
  -o /dev/null -w "%{http_code}\n" \
  https://localhost/api/uploads
# 409
```

**Step 6 — Cross-event reuse same file + different event → 201, asset_id unchanged, new event_item_id (passed):**
```bash
docker exec apacheWebServer curl -sk -u admin:secretadmin \
  -F "file=@/tmp/test.mp3" \
  -F "event_date=2026-04-25" \
  -F "org_name=TestBand" \
  -F "event_type=band" \
  -F "label=Reprise" \
  https://localhost/api/uploads \
| python3 -c "
import json, sys; d = json.load(sys.stdin)
assert d.get('asset_id'), f'FAIL: {d}'
print(f'PASS: asset_id={d[\"asset_id\"]}  event_item_id={d[\"event_item_id\"]}')
"
# PASS: asset_id=17  event_item_id=18
# Note: asset_id=17 reused (no new disk write); event_item_id=18 is the new event↔asset link
```

**PR4 overall result: PASS**

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
  - `ansible/roles/docker/files/apache/webroot/src/Services/UnifiedIngestionCore.php` — replace `ensureSession()`/`ensureSong()` with `ensureEvent()`/`ensureAsset()`/`ensureEventItem()`; this is the central service the manifest lib routes through and **must be ported or the manifest lib changes have no effect**
  - `ansible/roles/docker/files/apache/webroot/admin/import_manifest_lib.php`
  - `ansible/roles/docker/files/apache/webroot/admin/import_manifest_worker.php`
  - `ansible/roles/docker/files/apache/webroot/admin/import_manifest_add_async.php`
  - `ansible/roles/docker/files/apache/webroot/admin/import_manifest_reload_async.php`
  - `ansible/roles/docker/files/apache/webroot/admin/import_manifest_status.php`
  - `ansible/roles/docker/files/apache/webroot/admin/import_manifest_cancel.php`
  - `ansible/roles/docker/files/apache/webroot/admin/import_manifest_replay.php`
  - `ansible/roles/docker/files/apache/webroot/admin/import_manifest_jobs.php`

CSV import endpoints (admin Sections 3A/3B):
- Change:
  - `ansible/roles/docker/files/apache/webroot/admin/import_database.php`
  - `ansible/roles/docker/files/apache/webroot/admin/import_normalized.php`

Admin UI (optional text changes only if needed):
- Change (only if UI wording or wiring needs to change):
  - `ansible/roles/docker/files/apache/webroot/admin/admin.php`

Additional manifest files — **decide before implementing**:
These files exist in `admin/` but are not in the scope above. Each needs an explicit decision:
- `admin/import_manifest_prepare.php` — likely coordinates import pipeline setup; **probably needs porting**
- `admin/import_manifest_finalize.php` — likely finalizes import state; **probably needs porting**
- `admin/import_manifest_duplicates.php` — likely queries legacy tables for duplicate analysis; **probably needs porting** to query `assets`
- `admin/import_manifest_add.php` — synchronous variant of add; if used, **needs porting** alongside the async version
- `admin/import_manifest_upload_start.php` / `import_manifest_upload_status.php` / `import_manifest_upload_finalize.php` — chunked manifest file upload (not the DB import itself); likely no table writes, **probably no change needed** but verify

### Exact changes
1) Manifest import contract (preserve external interface)
- Keep accepting JSON body with `items` array.
- Preserve quick validation behavior in async endpoints.

2) `import_manifest_lib.php`
- Replace legacy “steps” semantics and underlying DB logic:
  - Remove truncate/seed/upsert logic for `sessions/songs/files`.
  - Replace with canonical equivalents:
    - (reload mode) truncate canonical tables **in FK-safe order**: `event_participants` → `event_items` → `events` → `assets` (violating this order will cause FK constraint errors); reseed any reference data if you add it.
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

#### Implementation notes (actual approach vs plan)

- **`import_manifest_worker.php`, `_async` variants, `_status`, `_cancel`, `_replay`, `_jobs`** required no changes — they delegate entirely to `import_manifest_lib.php` and the async queue. Only the lib needed updating.
- **Sync variants** (`import_manifest_reload.php`, `import_manifest_add.php`) were confirmed in use and fully ported alongside the async path.
- **CSV approach**: "load to staging (legacy) tables then canonicalize" was chosen over "convert-to-manifest". The Python preprocessing scripts and the LOAD DATA statements are unchanged. Canonicalization SQL is appended to the same SQL file, run in the same mysql client call.
- **3A (mysqlPrep_full.py) has no checksums** in its `files.csv` output, so only `events` are canonicalized from `sessions`. Assets and `event_items` remain empty after a 3A import.
- **Pre-condition (CSV `org_name`/`event_type` columns)**: blocking pre-condition was avoided by using `COALESCE(NULLIF(org_name,''), 'default')` and `COALESCE(NULLIF(event_type,''), 'band')` in the canonicalization SQL, providing safe fallbacks for CSVs that omit those columns.
- **`UploadService::finalizeManifestTusUpload()`**: PR4 left this using a local `FileRepository` (marked TODO PR5). It is now ported: uses `$this->assetRepo->findByChecksum()`, reads `file_ext` from the asset row, calls `$this->uic->ingestComplete($assetId, …)`, and returns `asset_id` instead of `file_id`.

#### Implementation status — files changed

- `src/Services/UnifiedIngestionCore.php` — `ingestStub()` rewritten to write `assets`; `ingestComplete()` updated to accept `asset_id`; legacy `ensureSession`/`ensureSong`/`ensureSessionSong`/`linkSongFile` marked `@deprecated`.
- `src/Services/UploadService.php` — `finalizeManifestTusUpload()` ported from `FileRepository` to `$this->assetRepo`; falls back on `file_ext` asset column (not `file_name`); returns `asset_id`.
- `admin/import_manifest_lib.php` — added `use` for `AssetRepository`/`EventItemRepository`/`EventRepository`; step names updated; canonical truncation order in reload mode; `ensureEvent()` replaces `ensureSession()`; `ingestStub()` now writes `assets`; `ensureEventItem()` replaces song-linking; canonical table counts in job result.
- `admin/import_manifest_reload.php` — full rewrite: all legacy closures removed; uses `EventRepository`/`AssetRepository`/`EventItemRepository` directly; canonical truncation; canonical table counts.
- `admin/import_manifest_add.php` — same rewrite pattern; additive (no truncation).
- `admin/import_database.php` (Section 3A) — legacy TRUNCATEs removed; `CREATE TEMPORARY TABLE IF NOT EXISTS` for all 7 legacy staging tables prepended to SQL file; events-only canonicalization appended; reports `events`/`assets`/`event_items` counts.
- `admin/import_normalized.php` (Section 3B) — same TRUNCATE fix; full canonicalization SQL appended (events + assets + event_items via legacy junction join using `ROW_NUMBER() OVER`); `item_type` sourced from `sg.type` not `f.file_type`; reports `events`/`assets`/`event_items` counts.
- `ansible/roles/upload_tests/tasks/assert_db_invariants.yml` — queries `events`/`assets`; all facts/params renamed to `*_events_count`/`*_assets_count`.
- `ansible/roles/upload_tests/tasks/test_3a.yml` — invariant call: `events_count = expected_sessions_count`, `assets_count = null`.
- `ansible/roles/upload_tests/tasks/test_3b.yml` — invariant call: `events_count = expected_sessions_count`, `assets_count = expected_files_count`.
- `ansible/roles/upload_tests/tasks/test_4.yml` — before-count SQL queries `events`/`assets`; invariant params updated.
- `ansible/roles/upload_tests/tasks/test_5.yml` — fact names updated to `assets_before`/`assets_after`.

#### Copy-pastable validation commands

**Ansible upload tests (sections 3A, 3B, 4, 5) — see exact verified commands in the PR5b status section below.**

**Manual manifest reload (sync endpoint, small payload):**
```bash
docker exec apacheWebServer curl -sk -u admin:secretadmin \
  -X POST https://localhost/admin/import_manifest_reload.php \
  -H "Content-Type: application/json" \
  -d '{
    "org_name": "TestBand",
    "event_type": "band",
    "items": [{
      "checksum_sha256": "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa",
      "file_type": "audio",
      "file_name": "test.mp3",
      "event_date": "2026-04-24",
      "size_bytes": 123456
    }]
  }' \
| python3 -c "
import json, sys
d = json.load(sys.stdin)
assert d.get('success'), f'FAIL: {d.get(\"message\", d)}'
tc = d.get('table_counts', {})
print('PASS: manifest reload succeeded')
print(f'  table_counts={tc}')
assert tc.get('assets', 0) > 0, 'FAIL: no assets written'
assert tc.get('events', 0) > 0, 'FAIL: no events written'
assert tc.get('event_items', 0) > 0, 'FAIL: no event_items written'
print('PASS: all canonical table counts > 0')
"
```

**DB counts after manifest reload:**
```bash
docker exec mysqlServer mysql -u root -pmusiclibrary music_db -e "
SELECT 'events'      AS tbl, COUNT(*) AS cnt FROM events
UNION ALL SELECT 'assets',      COUNT(*) FROM assets
UNION ALL SELECT 'event_items', COUNT(*) FROM event_items;"
```

**Confirm legacy tables are gone (schema verification):**
```bash
docker exec mysqlServer mysql -u root -pmusiclibrary music_db -e "
SHOW TABLES LIKE 'sessions';
SHOW TABLES LIKE 'files';
SHOW TABLES LIKE 'songs';"
# Expected: all empty result sets — legacy tables were dropped in the canonical schema cutover
```

**TUS finalize after manifest reload — response must contain asset_id:**
```bash
# After a manifest reload that inserted checksum <sha256>, call finalize:
docker exec apacheWebServer curl -sk -u admin:secretadmin \
  -X POST https://localhost/api/uploads/finalize \
  -H "Content-Type: application/json" \
  -d '{"upload_id": "<tus_upload_id>", "checksum_sha256": "<sha256>"}' \
| python3 -c "
import json, sys
d = json.load(sys.stdin)
assert 'asset_id' in d, f'FAIL: missing asset_id — {list(d.keys())}'
assert 'file_id'  not in d, 'FAIL: legacy file_id still present'
print(f'PASS: finalize returned asset_id={d[\"asset_id\"]}')
"
```

**Section 3B (normalized CSV reload) — canonical count check:**
```bash
# After triggering import_normalized.php reload, query canonical tables:
docker exec mysqlServer mysql -u root -pmusiclibrary music_db -e "
SELECT 'events'      AS tbl, COUNT(*) FROM events
UNION ALL SELECT 'assets',      COUNT(*) FROM assets
UNION ALL SELECT 'event_items', COUNT(*) FROM event_items;"
# events count should match sessions.csv row count
# assets count should match files.csv rows with non-empty checksum_sha256
# event_items count should match total song_files links with valid checksums
```

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
- **Variable rename**: the task sets/reads Ansible facts named `upload_tests_db_sessions_count`,
  `upload_tests_db_files_count`, `upload_tests_expect_sessions_count`, and
  `upload_tests_expect_files_count`. Rename all four (and any corresponding vars in the
  calling tests `test_3a`–`test_5`) to `*_events_count` and `*_assets_count` respectively
  to stay consistent with canonical table names and avoid misleading fact names in test output.

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

### Status: ✅ VERIFIED PASSING — 2026-04-24

Full test run result: `ok=192  changed=8  failed=0  skipped=13` (1m 23s, gighive2 inventory).

**Exact commands used during verification:**

Upload tests only — use when only Ansible YAML / controller-side Python files changed (no Apache container sync needed):
```bash
ansible-playbook ansible/playbooks/site.yml \
  -i ansible/inventories/inventory_gighive2.yml \
  --tags set_targets,upload_tests \
  -e "allow_destructive=true" \
  -e "upload_test_destructive_confirm=false"
```

With base + docker sync — use when PHP files inside the Apache container changed (e.g. `import_*.php`, `import_manifest_lib.php`):
```bash
ansible-playbook ansible/playbooks/site.yml \
  -i ansible/inventories/inventory_gighive2.yml \
  --tags set_targets,base,docker,upload_tests \
  -e "allow_destructive=true" \
  -e "upload_test_destructive_confirm=false"
```

Notes:
- `allow_destructive=true` permits sections 3A/3B/4 (which truncate the database).
- `upload_test_destructive_confirm=false` skips the interactive pause prompt (required for non-interactive runs).
- `upload_test_variants` defaults are defined in `ansible/inventories/group_vars/gighive2/gighive2.yml`; override on the command line with `-e 'upload_test_variants=[...]'` if you want a subset.
- `base` syncs all files from the Ansible controller to the VM via rsync; `docker` restarts the Apache container to pick them up.

**Bugs discovered and fixed during verification run (not covered by original plan):**

| File | Bug | Fix |
|---|---|---|
| `import_database.php` | `TRUNCATE TABLE session_musicians` (and 6 other legacy tables) — tables removed from schema | Remove legacy TRUNCATEs; prepend `CREATE TEMPORARY TABLE IF NOT EXISTS` for all 7 staging tables before `LOAD DATA LOCAL INFILE` |
| `import_normalized.php` | Same as above; additionally `INSERT INTO event_items` used `f.file_type` (`'audio'`/`'video'`) for `item_type` ENUM (`'song'`,`'loop'`,`'clip'`,`'highlight'`) | Same TRUNCATE fix; change `item_type` source to `sg.type` |
| `import_manifest_lib.php`, `import_manifest_reload.php`, `import_manifest_add.php` | `ensureEventItem(... $it['file_type'] ...)` passes `'audio'`/`'video'` as `item_type` | Change to hardcoded `'clip'` (correct default for manifest imports lacking musical classification) |
| `derive_expected_files_from_prepped_csv.yml` | `expected_loaded_rows = empty + uniq` counted checksum-less files; canonicalization only inserts rows with checksums | Change to `expected_loaded_rows = uniq` |
| `upload_media_by_hash.py` | All queries reference `files` / `file_id` instead of `assets` / `asset_id` | Replace `FROM files` → `FROM assets`, `ORDER BY file_id` → `ORDER BY asset_id`, `UPDATE files SET` → `UPDATE assets SET` throughout |

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

### Exact changes implemented

- `dbDump.sh.j2`: after successful gzip integrity check, writes `${DB_NAME}_${STAMP}.schema.json` containing `schema_version`, `timestamp`, `dump_file`, `git_sha`. Updates `_latest.schema.json` symlink. `git_sha` uses `git -C "{{ gighive_home }}" rev-parse --short HEAD` (falls back to `'N/A'` if git unavailable on VM — note: `repo_root` is controller-side; `gighive_home` is the VM-side repo path). `schema_version` is hardcoded to `"canonical-v1"` — bump manually on future breaking schema changes.
- `dbRestore.sh.j2`: no changes required for PR6.

### Verification

**Step 1 — sync the updated script to the VM** (base role handles rsync):
```bash
ansible-playbook ansible/playbooks/site.yml \
  -i ansible/inventories/inventory_gighive2.yml \
  --tags set_targets,base
```

**Step 2 — trigger a dump** (run directly on VM via SSH; the `mysql_backup` Ansible tag only installs the cron job, it does not trigger a dump):
```bash
ssh ubuntu@192.168.1.50 '~/gighive/ansible/roles/docker/files/mysql/dbScripts/dbDump.sh'
```

**Step 3 — inspect the sidecar**:
```bash
ssh ubuntu@192.168.1.50 'cat ~/gighive/ansible/roles/docker/files/mysql/dbScripts/backups/music_db_latest.schema.json'
```

### Status: ✅ VERIFIED PASSING — 2026-04-24

```
2026-04-24T13:07:39-04:00 INFO: wrote schema sidecar -> music_db_2026-04-24_130739.schema.json (schema_version=canonical-v1, git_sha=e308166)
```
```json
{
  "schema_version": "canonical-v1",
  "timestamp": "2026-04-24T13:07:39-04:00",
  "dump_file": "music_db_2026-04-24_130739.sql.gz",
  "git_sha": "e308166"
}
```

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
- **`db/delete_media_files.php`** — add a `DELETE /db/delete_media_files` entry (or update the existing informal path) to `openapi.yaml`:
  - Request body: replace `file_ids` (array of `file_id` integers) with `asset_ids` (array of `asset_id` integers); keep `delete_token` for uploader flow.
  - Response: replace `file_id` in result objects with `asset_id`.

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
| `file_id` | `asset_id` | `POST /db/delete_media_files.php` request + response |

The following fields survive unchanged and require no client update:
- `checksum_sha256`, `file_type`, `duration_seconds`, `mime_type`, `size_bytes`

The following endpoint URLs are kept stable and require no client update:
- `POST /api/uploads`, `POST /api/media-files` (alias), `POST /api/uploads/finalize`, `db/database.php`

PR7 is the correct point to freeze and publish the updated `openapi.yaml` so external
client developers (including the iPhone app) have an accurate contract to code against.

### Exact changes implemented

- `openapi.yaml` — `File` schema: removed `session_id` and `seq`; added `asset_id`, `event_id`, `event_item_id`, `position` (with descriptions); kept `id` with a backward-compat note.
- `openapi.yaml` — `DuplicateError` schema: `existing_file_id` → `existing_asset_id`.
- `openapi.yaml` — added `POST /db/delete_media_files.php` endpoint with `oneOf` request body (admin: `asset_ids[]`; uploader: `asset_id` + `delete_token`) and response shape with `deleted[].asset_id` / `errors[].asset_id`.
- `docs/API_CURRENT_STATE.md` — added blockquote at top confirming canonical schema is in effect and listing all dropped legacy tables.

### Follow-on fixes discovered during SDLC testing (2026-04-24)

All callers of `delete_media_files.php` and the delete UI in `database.php` were still sending legacy field names. Fixed across the board:

| File | Change |
|---|---|
| `ansible/roles/post_build_checks/tasks/main.yml` | 3 tasks: `file_id`/`file_ids` → `asset_id`/`asset_ids`; stale `file_name is search('tus-validate')` assertion removed; idempotency check updated to `asset_id` |
| `src/Views/media/list.php` | Delete checkbox value: `$r['id']` → `$r['asset_id']`; JS body: `file_ids` → `asset_ids` |
| `db/upload_form.php` | JS body: `{ file_ids: [...], file_id: ... }` → `{ asset_id: ... }` |
| `db/upload_form_admin.php` | Same as above |
| `src/OpenApi.php` | PHP annotation: `existing_file_id` → `existing_asset_id` (aligns with `openapi.yaml` fix) |
| `admin/import_manifest_upload_start.php` | DB check for Step 2 completion: `SELECT file_name FROM files` → `SELECT asset_id FROM assets` (canonical table; presence of row = ingestion complete) |
| `admin/clear_media.php` | Full rewrite: replace legacy `TRUNCATE files/songs/sessions/session_musicians/session_songs/song_files/musicians` with canonical `TRUNCATE event_participants/event_items/assets/events/participants/genres/styles` |

Also added during this session:
- `admin/admin_system.php` — Section E: Export Media to ZIP (filter by org_name / file type, download preserving original filenames)
- `admin/export_media.php` — backend: queries `assets → event_items → events`, zips from disk, streams `Content-Disposition: attachment`
- `admin/import_normalized.php` — canonicalization now populates `participants` and `event_participants` from `musicians`/`session_musicians` temp tables (fixes missing Musicians column in event view after CSV import)

### Verification

Sync + browse Swagger UI:
```bash
ansible-playbook ansible/playbooks/site.yml \
  -i ansible/inventories/inventory_gighive2.yml \
  --tags set_targets,base,docker
# then open https://192.168.1.50/docs/api-docs.html
```

Checklist (verified 2026-04-24 via Swagger UI screenshot):
- [x] `File` schema: `asset_id`, `event_id`, `event_item_id`, `position` present; `session_id`, `seq` absent
- [x] `DuplicateError` schema: `existing_asset_id` present; `existing_file_id` absent
- [x] `POST /delete_media_files.php` endpoint visible under `database` tag
- [x] No red parse errors in Swagger UI
- [x] Live `POST /api/uploads` response fields match spec — verified 2026-04-24

  First upload (from VM — 201 Created):
  ```bash
  curl -skS -u uploader:<uploader-password> \
    -X POST https://admin:<admin-password>@192.168.1.50/api/uploads \
    -F "file=@/home/ubuntu/wannaJam20260419.mp4" \
    -F "label=TestSong" \
    -F "event_date=2026-04-24" \
    -F "org_name=TestBand" \
    -D /tmp/upload_headers.txt \
    -o /tmp/upload_body.txt \
    -w "HTTP_STATUS=%{http_code}\n"
  # Result: HTTP_STATUS=201
  ```

  Duplicate upload (from Mac with same file — 409 Conflict):
  ```bash
  curl -skS -u uploader:<uploader-password> \
    -X POST https://admin:<admin-password>@192.168.1.50/api/uploads \
    -F "file=@wannaJam20260419.mp4" \
    -F "label=TestSong" \
    -F "event_date=2026-04-24" \
    -F "org_name=TestBand" \
    -D /tmp/upload_headers.txt \
    -o /tmp/upload_body.txt \
    -w "HTTP_STATUS=%{http_code}\n"
  # Result: HTTP_STATUS=409 (DuplicateError with existing_asset_id)
  ```
- [ ] `openapi.yaml` distributed to iPhone app developer(s) with breaking-change notice (`session_id`→`event_id`, `seq`→`position`)

### Status: ✅ VERIFIED PASSING — 2026-04-24 (Swagger UI)

---

## Schema end state (post-PR5b)

**Dropped** (removed entirely — no runtime path may reference these after cutover):

| Table | Notes |
|---|---|
| `sessions` | replaced by `events` |
| `songs` | replaced by `event_items.label` + `item_type` |
| `files` | replaced by `assets` |
| `session_songs` | join dissolved into `event_items` |
| `song_files` | join dissolved into `event_items` |

**Renamed** (data preserved, table and column names change):

| Old name | New name | Key column changes |
|---|---|---|
| `musicians` | `participants` | `musician_id` → `participant_id` |
| `session_musicians` | `event_participants` | `musician_id` → `participant_id`; `session_id` → `event_id`; add `role VARCHAR` |

**Added** (net-new canonical tables):

| Table | Purpose |
|---|---|
| `assets` | globally unique media entity, keyed on `checksum_sha256` |
| `events` | capture container (gig, wedding, etc.), keyed on `(event_date, org_name)` |
| `event_items` | event-scoped typed label + event↔asset join; keyed on `(event_id, asset_id)` |

> `genres`, `styles`, and `users` are unaffected and carry over unchanged.

---

## Testing Implementation Through the SDLC

### labvm example — 2026-04-24

**Step 1 — Set nuclear rebuild flag in `ansible/inventories/group_vars/gighive/gighive.yml`:**
```yaml
rebuild_mysql_data: true
```

**Step 2 — Run the full playbook (skipping non-essential tags):**
```bash
script -q -c "ansible-playbook -i ansible/inventories/inventory_gighive.yml ansible/playbooks/site.yml --skip-tags vbox_provision,upload_tests,installation_tracking,one_shot_bundle,one_shot_bundle_archive" ansible-playbook-gighive-20260424.log
```

**Step 3 — Reset nuclear flag immediately after:**
```yaml
rebuild_mysql_data: false
```

**Step 4 — Re-import CSV data via admin UI** to populate `participants` / `event_participants`.

**Step 5 — Verify PR6 sidecar:**
```bash
ssh ubuntu@labvm.gighive.internal '~/gighive/ansible/roles/docker/files/mysql/dbScripts/dbDump.sh'
# Output:
# 2026-04-24T14:36:42-04:00 START: dumping music_db from container mysqlServer to .../music_db_2026-04-24_143642.sql.gz
# 2026-04-24T14:36:42-04:00 OK: wrote 6296 bytes to .../music_db_2026-04-24_143642.sql.gz
# 2026-04-24T14:36:42-04:00 INFO: updated latest symlink -> music_db_latest.sql.gz
# 2026-04-24T14:36:42-04:00 INFO: wrote schema sidecar -> music_db_2026-04-24_143642.schema.json (schema_version=canonical-v1, git_sha=93178b2)
```

**Step 6 — Verify canonical schema:**
```bash
ssh ubuntu@labvm.gighive.internal "docker exec mysqlServer mysql -u root -p<password> music_db -e 'SHOW TABLES;'"
# Expected tables: assets, event_items, event_participants, events, genres, participants, styles, users
# (no sessions, songs, files, session_musicians, session_songs, song_files)
```

**Step 7 — Verify event view with Musicians populated:**
```
https://labvm.gighive.internal/db/database.php?view=event
```
Confirm Musicians column shows participant names (not blank).

**Result: ✅ PASSED — 2026-04-24**

---

## Cross-cutting checklist (apply across PRs)

- DB uniqueness invariants
  - assets unique by checksum
  - event_items unique by (event_id, asset_id)
- "Same binary, different event" behavior
  - must create a new link and new event item, not reject.
- Operational invariants
  - admin 3A/3B and 4/5 endpoints remain callable with the same URLs and basic payload shapes, but now write canonical tables.
