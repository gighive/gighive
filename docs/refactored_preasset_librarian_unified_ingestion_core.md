# Refactor: Unified Ingestion Core

## Goal
Create a shared server-side ingestion core that is used by both the upload API paths and the manifest add/reload import paths.

This is intended as a precursor to the broader Event/Asset hard cutover described in `docs/pr_librarianAsset_musicianEvent.md`.

The objective is to reduce drift between ingestion paths, centralize metadata extraction and persistence rules, and simplify the eventual migration from the current session/files-oriented model to the future Event/Asset canonical model.

## Why this should happen before the Event/Asset divergence plan
Today, GigHive has multiple ingestion entrypoints that create or link media records through different code paths:

- Upload API / direct upload flow
- TUS finalize flow
- Manifest add flow from `admin.php`
- Manifest reload flow from `admin.php`

These paths currently overlap in responsibility but do not share a single ingestion service. As a result, media metadata rules, persistence behavior, and future schema migration work are at risk of diverging.

If ingestion is unified first, the later session/event divergence work can port one canonical write-path abstraction instead of porting several partially duplicated implementations.

## Problem statement
The current architecture separates ingestion by transport and operator workflow rather than by domain responsibility.

Examples of duplicated or drift-prone responsibilities include:

- Media type determination
- Duration and ffprobe-based metadata extraction
- Canonical population of derived fields such as `media_created_at`
- Dedupe behavior
- Session/Event linkage behavior
- Song/label linking behavior
- File row insertion and future asset row insertion

The upload API path already contains richer media probing behavior, while the manifest import path currently performs direct inserts into `files` without reusing the same logic.

That split creates inconsistent outcomes depending on how the media entered the system.

The goal is to unify the write-path logic, not the HTTP endpoints. The two outer paths (upload API / TUS finalize vs. manifest add/reload) stay separate because they have legitimately different transport and batching concerns. What gets unified is the server-side ingestion service they both call — dedupe, metadata probing, field derivation, and record persistence.

This refactor is explicitly a precursor, not the end goal. Once both paths share one canonical ingestion abstraction, the later Event/Asset hard cutover only needs to port one write path instead of two partially-duplicated ones. It reduces drift risk and makes the cutover cleaner.

## Decision
Pursue a separate refactor that unifies the ingestion core while allowing different outer entrypoints to remain in place.

This means:

- We do **not** need to force all ingestion through one literal HTTP endpoint.
- We **do** want one canonical server-side ingestion service that both upload and manifest import flows call.

The preferred architecture is:

- Thin entrypoints for each workflow
- Shared ingestion service for domain logic
- Shared media metadata extraction/probing helper(s)
- Shared persistence behavior for file/media rows and linkage logic

## Non-goal
This refactor does **not** itself perform the Event/Asset cutover.

It is a precursor that reduces duplication and risk before the true remodel.

## Proposed architecture
### 1) Keep distinct entrypoints
The outer workflows still have different operational needs and should remain distinct:

- Upload API path
- TUS finalize path
- Manifest add path
- Manifest reload path

These differ in transport, batching, async job behavior, and operator intent.

### 2) Unify the ingestion core
Introduce a canonical ingestion service that accepts a normalized server-side request object and performs the shared write-path logic.

Responsibilities of the ingestion core should include:

- Validate normalized input
- Accept a server-readable media file path where probing is required
- Infer or validate media type
- Probe media metadata
- Extract canonical derived fields
- Persist media/file record
- Enforce dedupe rules
- Link to Event/session and label/song structures
- Return a normalized ingestion result

#### UIC write behavior: upsert-aware

The UIC enforces the following logic on every write, keyed on `checksum_sha256`:

- **No existing row** → canonical INSERT (new asset/file, all paths)
- **Existing stub row** (created by manifest worker, probe metadata not yet populated) → fill in probe metadata via UPDATE
- **Existing complete row** (fully ingested) → dedupe: return existing record without re-inserting

This resolves the two-phase manifest workflow: W1 pre-creates the stub row; when the admin later TUS-uploads the actual file, S3 hands off to UIC which detects the existing stub and completes it via UPDATE. The upload API path (S1) always hits the INSERT branch because global dedupe is enforced before UIC is called.

This upsert behavior maps directly to the planned `assets` table in the Event/Asset model, which uses `checksum_sha256` as the canonical identity key (FR1 in `docs/pr_librarianAsset_musicianEvent.md`). When the hard cutover happens, the UIC's upsert key becomes the `assets` row identity — one canonical write path to port instead of several.

### 3) Centralize metadata extraction
Create a shared metadata/probing helper used by all ingestion paths.

Responsibilities should include:

- Running `ffprobe` when available
- Returning raw `media_info`
- Returning `media_info_tool`
- Extracting canonical derived fields such as:
  - `media_created_at`
  - later, potentially latitude/longitude, camera make/model, etc.

### 4) Keep raw metadata and derived fields separate
The refactor should preserve a clear distinction between:

- Raw source metadata
  - e.g. `media_info`
- Derived canonical columns
  - e.g. `media_created_at`

This is important for performance, queryability, and future schema migration.

## Why this provides value
### Consistency
All ingestion paths would produce the same metadata and persistence behavior.

### Simpler maintenance
Future changes to metadata handling or dedupe policy would be implemented once.

### Lower risk for Event/Asset cutover
The later remodel can migrate one canonical ingestion abstraction rather than several duplicated flows.

### Better testing
A shared ingestion core can be tested directly with multiple entrypoint wrappers.

### Easier future metadata rollout
New derived fields such as GPS or camera metadata can be added once and reused everywhere.

## Benefits of doing this work

### 1) Closes the metadata gap between upload and manifest paths today
`UploadService::handleUpload` (S1) already runs `ffprobe`, derives `media_created_at`, enforces dedupe, and links song/label structures. `import_manifest_worker.php` (W1) does a direct INSERT with none of that — no probe, always song type, no location/rating/notes. Every file that enters via a manifest import is missing metadata that an upload-path file would have. UIC closes that gap: both paths call the same probe and derivation logic.

### 2) Canonical dedupe across all paths
Today, dedupe behavior differs by entrypoint. UIC enforces a single rule everywhere: if a `checksum_sha256` already exists, return the existing record rather than creating a duplicate. This is consistent with the planned `assets` table identity in the Event/Asset model.

### 3) The two-phase manifest workflow is explicitly handled — no surprises
The stub-INSERT (W1) → probe-UPDATE (S3 via UIC) sequence is now a first-class design decision, not an implicit behavior scattered across two code paths. Both phases go through UIC's upsert logic, keyed on `checksum_sha256`. Any future change to how stubs are completed changes in one place.

### 4) One write path to port for the Event/Asset cutover
PR4 and PR5 of `docs/pr_librarianAsset_musicianEvent_implementation.md` must migrate upload and manifest ingestion to the canonical `assets/events/event_assets/event_items` tables. If both paths share UIC before that work starts, PR4 and PR5 together become: port UIC to write canonical tables. Without UIC, they must port S1, W1, and S3 independently, each with its own diverged logic.

### 5) New metadata fields roll out once
Adding GPS coordinates, camera make/model, or any future derived field means updating the UIC metadata helper in one place. Without UIC, the same addition must be made to `UploadService::handleUpload` and `import_manifest_worker.php` (and kept in sync indefinitely).

### 6) upload_tests already cover all paths — refactor is immediately verifiable
The existing `upload_tests` Ansible role exercises E1/S1 (test_6), W1 (test_4/5 step 1), and S3 (test_4/5 step 2) independently. The refactor can be verified end-to-end against the existing test suite without writing new infrastructure.

## Risks and caveats
### 1) Manifest worker (W1) runs before files arrive — stub INSERT, no probe at W1 time
**Confirmed:** the manifest workflow is always two-phase. W1 runs when the admin imports a manifest; the actual media files do not exist on the server at that point. Files arrive later via TUS upload, at which point S3 runs.

This means UIC will always receive a stub INSERT request from W1 (no server-readable file path available, no probe possible). Probe metadata is populated in UIC's UPDATE branch when S3 hands off after the TUS upload completes.

This is handled correctly by the upsert-aware UIC design (see `### 2) Unify the ingestion core` above): the stub INSERT and the subsequent metadata UPDATE are both canonical UIC operations, keyed on `checksum_sha256`.

### 2) Upload and manifest flows still have legitimate outer differences
Manifest reload truncates tables and runs asynchronously.
Upload API paths deal with temporary uploaded files and client upload semantics.

The refactor should avoid flattening these differences too aggressively.

### 3) Scope creep
This should remain a write-path refactor, not become an accidental full schema remodel.

## Recommended implementation phases
### Phase 1: Extract shared media metadata helper
Create a reusable metadata helper for:

- probing media
- storing raw probe output
- extracting canonical derived fields

### Phase 2: Extract shared ingestion/persistence service
Create a canonical ingestion service that handles:

- validated normalized input
- dedupe
- metadata population
- file/media row creation
- session/Event and label/song linking

### Phase 3: Refactor upload API paths to use the shared service
Refactor:

- direct upload flow
- TUS finalize flow

so they rely on the shared ingestion service rather than path-specific persistence logic.

### Phase 4: Refactor manifest add/reload paths to use the shared service
Refactor:

- `import_manifest_lib.php`
- manifest add worker flow
- manifest reload worker flow

so they delegate record creation to the shared ingestion service.

### Phase 5: Reduce duplicated SQL and validation logic
After both sides share the same ingestion core, remove redundant insert/link logic from legacy wrappers.

## Suggested acceptance criteria
- Upload API and manifest import paths both use the same server-side ingestion service for record creation.
- Media metadata extraction logic exists in one canonical place.
- `media_created_at` and similar future derived fields are populated consistently regardless of ingestion path.
- Entry-point-specific behavior remains intact where operationally necessary.
- Existing upload and manifest workflows remain usable after the refactor.
- The resulting ingestion abstraction is suitable for later migration to the Event/Asset canonical model.

## Relationship to the Event/Asset hard cutover
This refactor should be treated as a precursor and enabler for `docs/pr_librarianAsset_musicianEvent.md`.

Expected benefit to the later remodel:

- one canonical write path to port
- fewer ingestion-specific edge cases
- cleaner mapping from current `files`-oriented persistence to future `assets`/`events` relationships
- lower risk of cross-contamination between librarian and capture workflows

## Recommended sequencing relative to other work
1. Implement the targeted `media_created_at` change now.
2. Plan and execute the unified ingestion core refactor as a separate effort.
3. Use the unified ingestion core as the precursor to the Event/Asset hard cutover.

## Out of scope for this plan
- Immediate Event/Asset schema cutover
- Compatibility layers for legacy runtime paths
- Read-path unification
- Broad UI redesign
- Full metadata expansion beyond the ingestion abstraction needed to support future fields
- **CSV import paths E3/E4** (`/import_database.php`, `/import_normalized.php`): these remain as-is for this refactor. They do not have server-readable binaries at import time (musicians upload a spreadsheet of metadata; files may not yet be on disk), so they cannot call UIC directly. They will reach UIC indirectly via PR5 of the Event/Asset cutover, which will convert them to generate a manifest and process through `import_manifest_worker.php` (W1 → UIC). No diagram changes or Phase 4 work targets E3/E4.

## Summary
The recommended direction is to unify the ingestion core, not necessarily collapse every ingestion workflow into one outer endpoint.

This provides architectural value now, reduces duplication and drift, and creates a better foundation for the planned Event/Asset remodel.

## Current Ingestion Architecture

The diagram below shows all active ingestion paths from both the regular user and admin perspectives, mapped to the six upload_tests coverage areas.

Border colors indicate test status:
- Green — currently tested
- Amber — test exists but flag must be enabled (`run_upload_media_by_hash: true`)
- Red — not tested separately (thin wrapper; core logic covered by test_6)
- Purple — `UploadService` layer (upload path, richer)
- Orange — manifest worker layer (direct insert path, leaner — primary unification target)
- Gold/yellow (dashed border) — **planned Unified Ingestion Core** (UIC)

**Solid arrows** = current data flow. **Dashed arrows** = planned future routing once the UIC is built. Edges marked **✗** are the current flows that the UIC will replace.

```mermaid
%%{init: {'theme': 'default'}}%%
flowchart LR
    classDef tested   fill:#ffffff,stroke:#28a745,stroke-width:2px,color:#000000
    classDef disabled fill:#ffffff,stroke:#856404,stroke-width:2px,color:#000000
    classDef notested fill:#ffffff,stroke:#cc0000,stroke-width:2px,color:#000000
    classDef actor    fill:#ffffff,stroke:#6c757d,stroke-width:2px,color:#000000
    classDef svc      fill:#ffffff,stroke:#6f42c1,stroke-width:2px,color:#000000
    classDef worker   fill:#ffffff,stroke:#e67e22,stroke-width:2px,color:#000000
    classDef db       fill:#ffffff,stroke:#495057,stroke-width:2px,color:#000000
    classDef planned  fill:#fff9c4,stroke:#f39c12,stroke-width:3px,stroke-dasharray:6 3,color:#000000
    classDef note     fill:#f8f9fa,stroke:#dee2e6,stroke-width:1px,color:#555555,text-align:left

    subgraph ActorCol ["Actors"]
        direction TB
        RegUser(["Regular User · browser / mobile app"]):::actor
        AdminUser(["Admin · admin.php · upload_media_by_hash.py"]):::actor
    end

    subgraph EndpointCol ["HTTP Endpoints"]
        direction TB
        E1["POST /api/uploads · test_6 ✓"]:::tested
        E2["POST /api/uploads/finalize · not tested · thin wrapper over handleUpload"]:::notested
        E3["/import_database.php · test_3a ✓"]:::tested
        E4["/import_normalized.php · test_3b ✓"]:::tested
        E5["/import_manifest_add_async.php · test_5 ✓ step 1"]:::tested
        E6["/import_manifest_reload_async.php · test_4 ✓ step 1"]:::tested
        E7["/import_manifest_upload_finalize.php · test_4/5 step 2 · needs run_upload_media_by_hash: true"]:::disabled
    end

    subgraph SvcCol ["Service / Worker Layer"]
        direction TB
        S1["UploadService::handleUpload · type infer · ffprobe · dedupe · FileRepository::create · label link"]:::svc
        S2["UploadService::finalizeTusUpload · reads TUS data · calls handleUpload"]:::svc
        S3["UploadService::finalizeManifestTusUpload · checksum verify · probe · UPDATE existing row"]:::svc
        W1["import_manifest_worker.php · direct INSERT sessions+files · no probe · always song type · no location/rating/notes"]:::worker
    end

    UIC["🎯 Unified Ingestion Core (planned)"]:::planned

    DB[("MySQL · files · sessions · songs · song_files")]:::db

    RegUser --"E1"--> E1
    RegUser --"E2"--> E2
    AdminUser --"E3"--> E3
    AdminUser --"E4"--> E4
    AdminUser --"E5"--> E5
    AdminUser --"E6"--> E6
    AdminUser --"E7 · TUS client"--> E7

    E1 --"E1"--> S1
    E2 --"E2"--> S2
    S2 --"S2"--> S1
    E3 --"E3 · direct INSERT"--> DB
    E4 --"E4 · direct INSERT"--> DB
    E5 --"E5"--> W1
    E6 --"E6"--> W1
    W1 --"W1 · ✗ INSERT (precreates stub)"--> DB
    E7 --"E7"--> S3
    S1 --"S1 · INSERT"--> DB
    S3 --"S3 · ✗ UPDATE"--> DB

    S1 -."S1 logic extracted into UIC · S1 becomes thin wrapper".-> UIC
    W1 -."W1".-> UIC
    S3 -."S3 hands off to UIC".-> UIC
    UIC --"UIC · upsert"--> DB

    DB ~~~ LA
    DB ~~~ LB
    DB ~~~ LC
    DB ~~~ LD

    LA["  • solid arrow  = current data flow                                "]:::note
    LB["  • dashed arrow = planned future flow once UIC is built         "]:::note
    LC["  • ✗ on edge    = that direct DB write is replaced by UIC       "]:::note
    LD["  • S1 to UIC   = S1 becomes UIC core · UIC is upsert-aware (INSERT or UPDATE by checksum)"]:::note

```

[![Pre-change ingestion flow](images/unifedIngestionCorePreChange.png)](images/unifedIngestionCorePreChange.png)
[![Post-change ingestion flow](images/unifedIngestionCorePostChange.png)](images/unifedIngestionCorePostChange.png)

## Testing the Changes

All upload ingestion paths covered by this refactor are exercised by the `upload_tests` Ansible role (`ansible/roles/upload_tests`). The role tests each ingestion path in isolation (separate variants) and verifies DB invariants after each run.

### Playbook command

```sh
script -q -c "ansible-playbook -i ansible/inventories/inventory_gighive2.yml ansible/playbooks/site.yml --tags set_targets,upload_tests" ansible-playbook-gighive2-20260402.log
```

The `script` wrapper captures full terminal output (including color/timing) to the named log file while also printing to the terminal.

### Test coverage per ingestion path

| Variant | Section | Ingestion path tested |
|---|---|---|
| `3a_legacy_import_gighive` | 3A | `/import_database.php` → direct INSERT |
| `3a_legacy_import_defaultcodebase` | 3A | `/import_database.php` → direct INSERT (defaultcodebase flavor) |
| `3b_normalized_import_gighive` | 3B | `/import_normalized.php` → direct INSERT |
| `3b_normalized_import_defaultcodebase` | 3B | `/import_normalized.php` → direct INSERT (defaultcodebase flavor) |
| `4_manifest_reload` | 4 | `/import_manifest_reload_async.php` → worker → direct INSERT (step 1); `/import_manifest_upload_finalize.php` → `finalizeManifestTusUpload` (step 2, requires `upload_test_run_upload_media_by_hash: true`) |
| `6_direct_upload_api` | 6 | `POST /api/uploads` → `UploadService::handleUpload` |
| `5_manifest_add` | 5 | `/import_manifest_add_async.php` → worker → direct INSERT (step 1); step 2 same as test_4 |

### Enabling all paths

Two flags must be set in `ansible/inventories/group_vars/gighive2/gighive2.yml` to exercise the full set:

```yaml
upload_test_run_upload_media_by_hash: true   # enables step 2 (TUS finalize) for tests 4 and 5
run_upload_tests: true
allow_destructive: true
```

### Variant ordering constraint

`6_direct_upload_api` must appear **before** `5_manifest_add` in `upload_test_variants`. Test 5 bulk-inserts all audio files from `audio_reduced` into the DB (including the test_6 fixture file). If test_6 runs after test_5, `UploadService::handleUpload` rejects the upload as a duplicate checksum (409) and the test fails.

### What is not yet covered

- `POST /api/uploads/finalize` (`UploadService::finalizeTusUpload`) — thin wrapper over `handleUpload`; core logic covered by test_6, but the TUS finalize entry point itself has no dedicated variant yet.

---

## Implementation record

### Files created

- **`src/Services/MediaProbeService.php`** — `inferType`, `sanitizeFilename`, `probeDuration`, `ffprobeToolString`, `probeMediaInfo`, `generateVideoThumbnail` (and their private helpers `pickThumbnailTimestamp`, `runWithTimeout`) extracted from `UploadService`. All ingestion paths share one probe implementation.
- **`src/Services/UnifiedIngestionCore.php`** — canonical write path:
  - `ingestStub(array $params)` — stub INSERT for W1 (file not yet on disk); keyed on `checksum_sha256`, returns `'skipped'` if already exists
  - `ingestComplete(int $fileId, ...)` — probe + UPDATE for S3 (file now on disk); fills in the stub row created by W1
  - `ensureSession`, `ensureSong`, `ensureSessionSong`, `linkSongFile` — shared session/song helpers previously duplicated between `UploadService` and `import_manifest_lib.php`

### Files modified

- **`src/Repositories/FileRepository.php`** — added `updateProbeMetadata()`, called by `UnifiedIngestionCore::ingestComplete` to fill in `file_name`, `size_bytes`, `mime_type`, `duration_seconds`, `media_info`, `media_info_tool` on the existing stub row
- **`src/Services/UploadService.php`** — constructor now injects `MediaProbeService` and `UnifiedIngestionCore` (both optional with defaults); all 12 extracted private methods removed; `handleUpload` (S1), `finalizeTusUpload` (S2), and `finalizeManifestTusUpload` (S3) are now thin call-through wrappers
- **`admin/import_manifest_lib.php`** — added `use Production\Api\Services\UnifiedIngestionCore`; the 5 inline closures (`$ensureSession`, `$nextSeq`, `$ensureSong`, `$ensureSessionSong`, `$linkSongFile`) removed; raw `INSERT INTO files` loop replaced with `$uic->ingestStub()`; `$ensureSession(...)` call replaced with `$uic->ensureSession(...)`

### Key behavior preservation

- **S1 (handleUpload)**: identical logic — session, seq, file persist, probe, song link — now delegated to UIC and MediaProbeService instead of private methods; duplicate checksum still throws `DuplicateChecksumException` as before
- **S3 (finalizeManifestTusUpload)**: raw `UPDATE files SET ...` SQL replaced with `$uic->ingestComplete()`; all returned fields (`duration_seconds`, etc.) unchanged; file storage and MIME detection remain in S3 before the UIC call
- **W1 (import_manifest_lib)**: old `catch(PDOException SQLSTATE 23000)` duplicate detection replaced by UIC's `findByChecksum`-first upsert returning `status: 'skipped'`; duplicate counter and sample collection behaviour identical; `seq` now computed inside UIC rather than inline
- **Two-phase manifest flow**: W1 stub INSERT → S3 `ingestComplete` UPDATE; both phases go through UIC's upsert logic keyed on `checksum_sha256` (see `### UIC write behavior: upsert-aware` above)
