# Product Requirements: Librarian Asset vs Musician Event (Hard Cutover)

## 1) Objective
Redesign the Gighive data model and user experience to support two primary workflows without cross-contamination:

- **Media librarian**: asset-centric cataloging and retrieval.
- **Capture (musician / wedding videographer)**: event-centric ingest, organization, and playback.

This change will be delivered as a **hard cutover**: the new Event/Assets model is canonical and legacy runtime paths are removed.

## 2) Background / Problem Statement
The current model and listing queries can present duplicate entries and incorrect associations because joins operate at the grain of `session + song + file`, and import/upload logic can reuse global song labels in ways that unintentionally link assets across sessions.

The redesign must:

- Prevent data collisions (e.g., filename/basename-driven cross-session links).
- Preserve strong UX for both librarian and capture audiences.
- Keep docs aligned with actual behavior.

## 3) Users / Personas
- **Librarian** (asset-centric): wants one row per unique asset, metadata aggregation, and predictable dedupe.
- **Musician / Band capture** (event-centric): wants to upload media to a specific Event and label it with setlist-like items.
- **Wedding videographer / event capture** (event-centric): wants to upload clips to an Event and label by moment/type.

## 4) Definitions / Vocabulary
- **Event**: generic capture container covering both gigs and weddings (legacy DB may have `sessions` naming, but product vocabulary is “Event”).
- **Asset**: globally unique media entity identified by content checksum (SHA-256).
- **Event Item**: event-scoped label/annotation for an asset, lightly typed (e.g., `song`, `moment`).

## 5) Non-Goals (for this change set)
- Building a full librarian Collections/Projects model (explicitly backlog).
- Implementing `GET /api/media-files` (explicitly backlog).
- Supporting duplicate binary uploads as separate assets (explicitly reject duplicates globally).

## 6) Scope: Functional Requirements

### FR1: Canonical identity and dedupe
- Assets MUST be identified and deduped by `checksum_sha256`.
- Upload/import MUST reject a new asset when the checksum already exists (global dedupe).

### FR2: Event ↔ Asset relationship
- The model MUST support many-to-many between Events and Assets.
- The UI MUST make it clear when an asset appears in multiple events (to avoid user confusion).

### FR3: Event Items (lightly typed)
- The system MUST support event-scoped items with a light `item_type`.
- Beta `item_type` set:
  - `song`
  - `moment`
  - `speech`
  - `ceremony`
  - `reception`
  - `artifact`
  - `other`

### FR4: Media listing must support two views
The existing listing entrypoint (`/db/database.php`) MUST support two UI views via a parameter:

- **Event view**: event/capture-centric listing.
- **Librarian view**: asset-centric listing.

Both views MUST read from the new canonical schema after cutover.

### FR5: Upload UX (capture)
- Upload MUST support selecting Event Item type via a dropdown.
- Defaults:
  - `APP_FLAVOR=gighive` musician/band capture default item type: `song`.
  - Wedding/videographer capture default item type: `reception`.

### FR6: Upload API behavior
- `POST /api/uploads` MUST ingest media into the new canonical model.
- `POST /api/media-files` (alias) MUST behave the same as `POST /api/uploads`.
- `GET /api/media-files` MUST remain unimplemented (501) for now.

### FR7: Manifest import behavior
- Manifest import MUST ingest into the new canonical model.
- Import must not rely on global basename-derived labels that can cause cross-event linking.

### FR8: App flavor defaults
- `APP_FLAVOR=gighive`: capture-first UX.
- `stormpigs`: librarian-first UX.

## 7) Scope: Data / Schema Requirements
- New canonical schema MUST include:
  - `assets` (unique checksum)
  - `events`
  - `event_assets` (join)
  - `event_items` (event-scoped typed items)
- Relationship tables MUST have unique constraints to prevent duplication.
- DB bootstrap must be updated:
  - `ansible/roles/docker/files/mysql/externalConfigs/create_music_db.sql`
  - `ansible/roles/docker/files/mysql/externalConfigs/load_and_transform.sql`

## 8) Documentation Requirements
- `docs/API_CURRENT_STATE.md` MUST reflect actual implemented behavior.
- `docs/openapi.yaml` MUST not claim `GET /api/media-files` exists.

## 9) Operational Requirements

### OR1: Backups tagged with schema version
- DB backups MUST be tagged with a schema version indicator (e.g., write a `schema_version` file alongside each dump) to reduce restore ambiguity.

### OR2: Rollback readiness
- Prior to cutover, a known-good DB dump MUST be available to restore the system to the prior state.

## 10) Acceptance Criteria (high-level)
- **AC1**: Librarian view shows one row per unique asset (checksum) with no join-multiplicity duplicates.
- **AC2**: Event view shows assets attached to an event, with clear event context.
- **AC3**: Upload rejects global duplicate checksums.
- **AC4**: Upload creates event linkage and an event item of the chosen type.
- **AC5**: Manifest import produces the same invariants as upload (dedupe by checksum; correct event linkage).
- **AC6**: Docs match behavior; `GET /api/media-files` remains 501.
- **AC7**: Backup artifacts include schema version tagging.

## 11) Rollout Notes
- This change is a hard cutover: no legacy runtime code paths remain.
- Recommended rollout sequence (implementation-oriented, not exhaustive):
  1. Update canonical schema and load scripts.
  2. Backfill/migrate data.
  3. Cut over listing.
  4. Cut over uploads and import.
  5. Update docs and backup tagging.

## 12) Delivery Plan (PR-sized milestones + rollback checkpoints)

### PR0 (operational): Freeze rollback artifacts
- **Purpose**: Ensure a known-good rollback state exists before schema/data changes.
- **Likely files**: none.
- **Verification**:
  - Pre-cutover DB dump exists and is restorable.
  - The dump is labeled with a schema/version identifier.
- **Rollback checkpoint**: This dump is the rollback source of truth.

### PR1: Canonical schema + loader scripts
- **Purpose**: Make the new Event/Assets schema canonical for DB bootstrap/rebuild.
- **Likely files**:
  - `ansible/roles/docker/files/mysql/externalConfigs/create_music_db.sql`
  - `ansible/roles/docker/files/mysql/externalConfigs/load_and_transform.sql`
- **Verification**:
  - Fresh DB init creates the new canonical tables and constraints.
  - Loader completes without errors.
- **Rollback checkpoint**: Restore pre-cutover dump and revert PR1.

### PR2: Backfill/migration (if not fully handled by loader)
- **Purpose**: Populate new canonical tables from existing data sources or legacy tables.
- **Likely files**:
  - New backfill script (SQL or CLI) located with other DB scripts.
  - Possibly adjustments to `load_and_transform.sql` to invoke/sequence backfill.
- **Verification**:
  - Assets are unique by checksum.
  - Event ↔ asset links exist and are unique.
- **Rollback checkpoint**: Restore pre-cutover dump and revert PR2.

### PR3: Media listing cutover (UI + JSON)
- **Purpose**: Switch `/db/database.php` listing queries to the new model; support librarian/event views.
- **Likely files**:
  - `ansible/roles/docker/files/apache/webroot/db/database.php`
  - `ansible/roles/docker/files/apache/webroot/src/Controllers/MediaController.php`
  - `ansible/roles/docker/files/apache/webroot/src/Repositories/SessionRepository.php` (or replacement repos)
  - `ansible/roles/docker/files/apache/webroot/src/Views/media/list.php`
  - `ansible/roles/docker/files/apache/webroot/src/Controllers/RandomController.php`
- **Verification**:
  - Librarian view: one row per checksum (no join-multiplicity duplicates).
  - Event view: assets appear with event context.
- **Rollback checkpoint**: Restore pre-cutover dump and revert PR3.

### PR4: Upload API cutover (`/api/uploads`)
- **Purpose**: Ingest uploads into `assets` + event linkage + event items (no legacy joins).
- **Likely files**:
  - `ansible/roles/docker/files/apache/webroot/src/Controllers/UploadController.php`
  - `ansible/roles/docker/files/apache/webroot/src/Services/UploadService.php`
  - Repository changes (replace or supplement `FileRepository.php` with canonical repos)
- **Verification**:
  - Upload rejects duplicate checksums globally.
  - Upload creates event linkage + event item of selected type.
- **Rollback checkpoint**: Restore pre-cutover dump and revert PR4.

### PR5: Manifest import cutover
- **Purpose**: Make manifest import write the new canonical model; prevent basename/global-label collisions.
- **Likely files**:
  - `ansible/roles/docker/files/apache/webroot/import_manifest_add.php`
  - `ansible/roles/docker/files/apache/webroot/import_manifest_reload.php`
  - `ansible/roles/docker/files/apache/webroot/admin.php`
- **Verification**:
  - Import produces the same invariants as upload (dedupe by checksum; correct event linkage).
- **Rollback checkpoint**: Restore pre-cutover dump and revert PR5.

### PR6: Backup schema-version tagging
- **Purpose**: Add schema/version metadata alongside each DB dump to reduce restore ambiguity.
- **Likely files**:
  - `ansible/roles/mysql_backup/templates/dbDump.sh.j2`
  - (optional) `ansible/roles/mysql_backup/templates/dbRestore.sh.j2`
- **Verification**:
  - Each dump produces an adjacent schema/version marker file.
- **Rollback checkpoint**: Revert PR6 (backup metadata is non-critical to runtime).

### PR7: Docs cleanup / alignment
- **Purpose**: Ensure docs reflect the hard-cutover reality and current API behavior.
- **Likely files**:
  - `docs/API_CURRENT_STATE.md`
  - `docs/openapi.yaml`
- **Verification**:
  - Docs match implemented endpoints and payload semantics.
- **Rollback checkpoint**: Revert PR7 (docs-only).
