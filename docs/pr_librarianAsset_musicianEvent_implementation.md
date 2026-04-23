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

Verification
- Restore dump to a clean MySQL instance and confirm the web UI comes up.

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

Verification
- Fresh DB init creates canonical tables.
- Loader runs successfully and populates canonical tables.

---

## PR2: Backfill/migration

### Rationale
You need a bridge from existing deployed data to the canonical tables.

### Production migration strategy (known sites)

Both current production sites (`prod` / `defaultcodebase` / stormpigs and `gighive` / staging)
have their DB state 100% reproducible from their CSV fixtures via Section 3B
(`sessionsXxx.csv` + `session_filesXxx.csv`). Neither site has live uploads that exist
outside the CSV source data.

For these sites the migration path is:
1. Take a DB dump (safety snapshot — see PR0).
2. Update Section 3B (`import_normalized.php`) to write canonical tables (part of PR5).
3. Rebuild the DB by running Section 3B with the existing CSVs.

**A SQL migration script (live data migration) is only required for sites that have
live uploads not covered by a CSV rebuild.** For the known production sites it is
optional/skippable.

**Pre-condition**: before rebuilding from CSVs, both `sessionsSmall.csv` and
`sessionsLarge.csv` must have `org_name` and `event_type` columns added (see PR5
pre-condition note). The rebuild must not run until those columns are present.

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

Verification
- Sample migrated dataset produces:
  - assets unique by checksum
  - correct event/asset link counts

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

Verification
- Librarian view: no duplicates for shared assets.
- Event view: same asset can show in multiple events with clear context.

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

Verification
- Duplicate checksum upload results in:
  - no new asset row
  - link to event is created (or idempotently ensured)
  - user-friendly “deduped” outcome in response

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

Verification
- Reload mode produces a canonical DB matching expected counts.
- Add mode is idempotent by checksum.

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

Verification
- Tests 3A/3B/4/5 pass using canonical tables.

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

---

## Cross-cutting checklist (apply across PRs)

- DB uniqueness invariants
  - assets unique by checksum
  - event_items unique by (event_id, asset_id)
- “Same binary, different event” behavior
  - must create a new link and new event item, not reject.
- Operational invariants
  - admin 3A/3B and 4/5 endpoints remain callable with the same URLs and basic payload shapes, but now write canonical tables.
