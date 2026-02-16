# Product Requirements: Librarian Asset vs Musician Event (Hard Cutover)

## 1) Objective
Redesign the Gighive data model and user experience to support two primary workflows without cross-contamination:

- **Media librarian**: asset-centric cataloging and retrieval.
- **Capture (musician / wedding videographer)**: event-centric ingest, organization, and playback.

This change will be delivered as a **hard cutover**: the new Event/Assets model is canonical and legacy runtime paths are removed.

Decision (implementation path):

- We will pursue **Option A: true remodel**. The canonical schema will introduce `assets`, `events`, `event_assets`, and `event_items`, and both read paths (listing) and write paths (upload/import) will be migrated to that model.
 - No compatibility layer will be maintained. Existing admin import/upload paths and tests must be ported to the new schema.

## 2) Background / Problem Statement
The current model and listing queries can present duplicate entries and incorrect associations because joins operate at the grain of `session + song + file`, and import/upload logic can reuse global song labels in ways that unintentionally link assets across sessions.

Current implementation status (as of Feb 2026):

- Uploads compute `checksum_sha256` and enforce global dedupe via a unique constraint on `files.checksum_sha256`.
- The database remains session-based (`sessions`, `songs`, `files`, join tables), with added “event-ish” fields such as `sessions.event_type` and `sessions.org_name`.
- The listing endpoint is still driven by session/song/file joins and does not yet provide a separate librarian vs event view.

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
- **Asset**: globally unique media entity identified by content checksum (SHA-256). (Today this is effectively represented by `files` rows deduped by `checksum_sha256`; future work may introduce a dedicated `assets` table.)
- **Event Item**: event-scoped label/annotation for an asset, lightly typed (e.g., `song`, `moment`).

## 5) Non-Goals (for this change set)
- Building a full librarian Collections/Projects model (explicitly backlog).
- Implementing `GET /api/media-files` (explicitly backlog).
- Supporting duplicate binary uploads as separate assets (explicitly reject duplicates globally).

## 6) Scope: Functional Requirements

### FR1: Canonical identity and dedupe
- Canonical binary identity MUST be `checksum_sha256`.
- Upload/import MUST enforce global dedupe when the checksum already exists.
- Current enforcement is via `files.checksum_sha256` unique constraint and upload logic that reuses an existing record.
- Future enforcement may move to a dedicated `assets` table if/when introduced.

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

Current implementation note:

- `/db/database.php` currently supports only `?format=json` vs HTML, and listings are session/song/file join-based.

### FR5: Upload UX (capture)
- Upload MUST support selecting Event Item type via a dropdown.
- Defaults:
  - `APP_FLAVOR=gighive` musician/band capture default item type: `song`.
  - Wedding/videographer capture default item type: `reception`.

### FR6: Upload API behavior
- `POST /api/uploads` MUST ingest media into the new canonical model.
- `POST /api/media-files` (alias) MUST behave the same as `POST /api/uploads`.
- `POST /api/uploads/finalize` (tusd finalize) MUST ingest with the same invariants as `POST /api/uploads`.
- `GET /api/media-files` MUST remain unimplemented (501) for now.

### FR7: Manifest import behavior
- Manifest import MUST ingest into the new canonical model.
- Import must not rely on global basename-derived labels that can cause cross-event linking.

### FR8: App flavor defaults
- `APP_FLAVOR=gighive`: capture-first UX.
- `stormpigs`: librarian-first UX.

## 7) Scope: Data / Schema Requirements
- Current canonical schema is session-based and includes `sessions`, `songs`, `files`, and join tables.
- Current bootstrap already enforces global checksum dedupe via `files.checksum_sha256` unique constraint.
- The new canonical Event/Assets schema MUST include:
  - `assets` (unique checksum)
  - `events`
  - `event_assets` (join)
  - `event_items` (event-scoped typed items)
- Relationship tables MUST have unique constraints to prevent duplication.
- DB bootstrap changes introducing assets/events tables must update:
  - `ansible/roles/docker/files/mysql/externalConfigs/create_music_db.sql`
  - `ansible/roles/docker/files/mysql/externalConfigs/load_and_transform.sql`

## 8) Documentation Requirements
- `docs/API_CURRENT_STATE.md` MUST reflect actual implemented behavior.
- `ansible/roles/docker/files/apache/webroot/docs/openapi.yaml` MUST not claim `GET /api/media-files` exists.

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
- **Purpose**: Introduce/establish canonical schema updates for DB bootstrap/rebuild.
- **Likely files**:
  - `ansible/roles/docker/files/mysql/externalConfigs/create_music_db.sql`
  - `ansible/roles/docker/files/mysql/externalConfigs/load_and_transform.sql`
- **Verification**:
  - Fresh DB init creates the intended canonical tables and constraints.
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
- **Purpose**: Ingest uploads into the canonical model used post-cutover.
- Current implementation already supports:
  - `POST /api/uploads`
  - `POST /api/media-files` alias
  - `POST /api/uploads/finalize` (tusd finalize)
- Remaining cutover work is to ensure these routes write the new canonical Event/Assets schema and create event items.
- **Likely files**:
  - `ansible/roles/docker/files/apache/webroot/src/Controllers/UploadController.php`
  - `ansible/roles/docker/files/apache/webroot/src/Services/UploadService.php`
  - Repository changes (replace or supplement `FileRepository.php` with canonical repos)
- **What will break without porting**:
  - The current upload implementation persists and returns identifiers tied to legacy tables (notably `files` and legacy session/song joins). Under hard cutover, any write path that still expects `sessions/songs/files` will fail (missing tables) or write data that the post-cutover listing cannot read.
  - Any consumer expecting the legacy response fields (e.g., `session_id` or `seq`) must be updated to use canonical event/asset concepts.
- **Minimum DB-facing contracts to port (to keep `/api/uploads`, alias, and tusd finalize working)**:
  - Upload must be able to create or resolve an `asset` by `checksum_sha256` (global dedupe).
  - Upload must be able to create or resolve an `event` (capture flow) and create a link row (`event_assets`) between the event and the asset.
  - Upload must be able to create at least one `event_item` associated with the event+asset with fields sufficient to support the capture UI (`item_type` + label/title).
  - Upload must persist enough asset fields for downstream tooling and listing:
    - checksum identity (`checksum_sha256`)
    - file type / extension (used by copy + thumbnail generation)
    - storage-relative path (or a deterministic path derivation contract)
    - media metadata fields currently populated post-copy (`duration_seconds`, `media_info`, `media_info_tool` or equivalents).
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
- **What will break without porting (admin Sections 3A/3B/4/5 + upload tests)**:
  - **Admin Section 3A** (`POST /import_database.php` with `database_csv`) truncates and loads legacy tables (`sessions/songs/files/...`). Under hard cutover, it will fail or populate tables that no longer drive the app.
  - **Admin Section 3B** (`POST /import_normalized.php` with `sessions_csv` + `session_files_csv`) also truncates and loads legacy tables. Same breakage.
  - **Admin Sections 4/5** (`POST /import_manifest_reload_async.php` / `POST /import_manifest_add_async.php`) currently enqueue a worker whose load pipeline ultimately targets legacy tables. Under hard cutover, these jobs will not produce canonical `assets/events/event_assets/event_items` unless rewritten.
  - **Upload tests 3A/3B/4/5** will fail until updated because they currently assert DB invariants by querying `SELECT COUNT(*) FROM sessions` and `SELECT COUNT(*) FROM files`.
  - **Step 2 of tests 4/5** (`tools/upload_media_by_hash.py`) currently queries the legacy `files` table by `checksum_sha256` and updates legacy `files.*` metadata fields. Under hard cutover, it must be ported to query/update the canonical asset storage rows.
- **Minimum DB-facing contracts to port (so Sections 3A/3B/4/5 survive hard cutover)**:
  - Canonical tables must support import of the following conceptual fields (exact column names TBD, but the data must exist):
    - **Assets**: `checksum_sha256` (unique), `file_type`/extension, `source_relpath` (or equivalent provenance pointer), and placeholders for `duration_seconds`, `media_info`, `media_info_tool`.
    - **Events**: event identity + basic capture metadata (date/time, org/name, event type).
    - **Event ↔ Asset linkage**: unique link so the same asset can be attached to multiple events.
    - **Event items**: `(event_id, asset_id, item_type, label/title)` to preserve capture semantics.
  - **CSV import contracts** (admin 3A/3B):
    - The import scripts must either (a) map CSV rows into canonical events/assets/event links/items, or (b) produce an intermediate manifest that the canonical importer consumes.
    - Both scripts must preserve their current external interface (same endpoints and form field names) if you want existing automation and operator muscle memory to survive.
  - **Manifest import contracts** (admin 4/5 + tests 4/5):
    - The async endpoints must continue to accept a JSON payload with `items` (current contract) and enqueue a job that results in canonical writes.
    - Each manifest `item` must provide enough info to deterministically:
      - compute/record checksum
      - create/resolve the asset row
      - create/resolve the event row
      - create the event↔asset link
      - optionally create event item(s)
  - **Binary copy tool contract** (`upload_media_by_hash.py`):
    - It must be able to query “assets that need binaries copied” by `checksum_sha256` and a source path (`source_relpath`) from the canonical schema.
    - After copying, it must be able to write back media-derived metadata (duration + ffprobe JSON + tool name) to the canonical asset row.
  - **Updated test invariants contract** (`upload_tests`):
    - Replace legacy count assertions with canonical equivalents (e.g., events/assets counts, and uniqueness constraints), and keep one small, stable query surface for tests to validate a successful import.
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

## Implications to the UX

Agree on the high-level UX split — with a couple important implications

Your proposed UX—an early choice between:

- **Capture (Event-centric)**: “I’m uploading for a band show / wedding Event”
- **Librarian (Asset-centric)**: “I’m curating my media library”

…is the right mental model for **Option A (true remodel)**. It matches the underlying data model split (Event context + event items vs global assets).

Where I’d refine it is *how* the switch is presented and what new UX obligations come from **global asset identity**.

Implications to UX after the upgrade (Option A)

1) You’ll need a *mode* that is persistent, not a one-off question
If every screen asks “capture vs librarian?” it will feel repetitive. Better:

- **Web**: a top-level toggle or landing “mode selector” that sets a session cookie / URL param / user preference.
- **iOS**: a primary app “mode” (or default tab) stored in settings, with a visible switch.

This prevents the user from constantly re-answering the same question.

2) “Capture” flow becomes: select/create Event first, then upload
Post-remodel, uploads must attach to an Event (via `event_assets`) and create at least one `event_item` entry.

So the capture UX should guide:

1. Pick Event (or create new Event)
2. Upload files
3. For each asset, assign an Event Item type + label (song/moment/etc.)

You can still allow a “quick upload” path, but you’ll need a default Event selection (last used / today’s event).

3) Librarian flow becomes: assets first, then optionally attach to Events
In librarian mode, the default list should be **one row per asset** (checksum identity). Attachments to Events are secondary.

That implies:
- Asset detail page should show:
  - metadata
  - where it’s used: “Appears in Events: …”
  - actions: “Attach to Event”, “Create Event Item”, etc.

Key edge cases you’ll need to handle (new UX obligations)

1) Dedupe messaging must be user-friendly
Because checksum dedupe is global:

- User uploads a file that already exists
- The system must say something like:
  - “Already uploaded (matched existing asset). Linked to this Event.”
  instead of “duplicate key” / silent reuse.

Also: decide what happens if the *same user* tries to upload the same binary into a *different Event*:
- This should succeed as a **new link**, not as a “reject upload entirely”.

2) Assets can appear in multiple Events — avoid “why is this here?” confusion
Your PRD already calls this out, and UX must make it explicit:

- In **Event view**, show “also used in X other events” (or an icon/badge).
- In **Librarian view**, show “linked to N events”.

3) The “band vs wedding” split belongs inside Capture, not at the root
I agree with your “maybe further split” idea, but I’d structure it as:

- First pick **mode**: Capture vs Librarian
- If **Capture**, then pick **Event type** (band/wedding/other)
  - This choice drives default `event_item.item_type` and form fields.

Reason: librarian users may still curate mixed media; you don’t want to force them to declare “band or wedding” upfront.

Recommendation: navigation model for the two main functions you mentioned

`db/database.php` listing
Make it support:
- `view=librarian` (default when in librarian mode)
- `view=event` + `event_id=...` (default when in capture mode)

Upload API
Keep endpoints stable, but add explicit semantics:
- Capture uploads must include `event_id` (or enough fields to create one).
- Librarian uploads can omit `event_id` (creates an asset only) *or* attach later.
