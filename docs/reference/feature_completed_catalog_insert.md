# Feature: Catalog Table

## Overview

The catalog feature is a lightweight, non-destructive folder-scanning mode that records file metadata into the database **without hashing, uploading, or processing any media**. It is a distinct ingestion entry point that sits upstream of the existing pipeline (single file upload, folder upload via TUS, CSV import, manifest import).

A catalog scan reads only what the filesystem exposes: filenames, extensions, sizes, and modification times. No bytes are transferred. No checksums are computed. No media is probed.

---

## Rationale and Target Audiences

### Why Catalog?

The existing ingestion paths all require commitment: files must be hashed, uploaded via TUS, and probed before they appear in the database. Catalog fills the gap for operators who need to understand what they have **before** deciding to commit to a full import.

### Use Cases by Audience

| # | Use Case | Audience | Most Important Reason to Use Catalog |
|---|---|---|---|
| 1 | Inventory — see what files exist | All | No hashing or upload cost; results are instant |
| 2 | Total file size + estimated import time | All | Know the time/bandwidth commitment before starting a multi-hour import |
| 3 | Pre-filter / selective gating before ingest | All | Exclude unwanted files at the source before they touch the pipeline |
| 4 | Gap detection across events | Musician / Band | Reveals missing show recordings without importing everything you do have |
| 5 | Multi-drive / multi-folder reconciliation | Musician / Band | Merges a view across multiple drives or NAS locations; surfaces duplicates before bytes move |
| 6 | Pre-tour prep on limited bandwidth | Musician / Band | Pure metadata scan — works on venue WiFi where a full upload would fail |
| 7 | Contributor aggregation audit | Wedding / Event | Multiple photographers submitted footage; catalog all sources first, review overlap |
| 8 | Client approval gate | Wedding / Event | Get client sign-off on what gets ingested before a single byte is uploaded |
| 9 | Orphan detection / reconciliation | Media Librarian | Identifies files on disk with no DB record, and DB records pointing to files that no longer exist |
| 10 | Format / codec audit | Media Librarian | Reveals unsupported formats (`.vob`, `.m2v`) before a 500-file job produces failures midway |
| 11 | Storage / infrastructure planning | Media Librarian | Total raw bytes scanned before needing to expand Docker volumes or provision disk |
| 12 | Incremental delta detection (re-scan) | Media Librarian | Surface only new files added since last scan, without triggering a full re-import |
| 13 | Import manifest generation from catalog | All (admin) | Catalog becomes step 0 of the pipeline; filtered output feeds directly into manifest import |
| 14 | Estimated AI tagging cost preview | Admin / Operator | Project OpenAI cost (video count × avg cost) before committing to import + auto-tag |

---

## Catalog Lifecycle and Ephemerality

The catalog (`catalog_scans` + `catalog_entries`) is **ephemeral staging, not a long-term database**. Its purpose is to hold a curated selection of files between the scan step and the upload step. Once entries are promoted to `assets/events/event_items`, the catalog record has served its purpose.

### Section A vs Section B — design intent

| Mode | Behavior | When to use |
|---|---|---|
| **Section A — Catalog Media (Reload)** | Runs `DELETE FROM catalog_scans` (cascades to all `catalog_entries`) — wipes the **entire** catalog — then scans the selected folder fresh | Starting a new batch; discarding all prior staging work |
| **Section B — Add to Catalog** | Appends entries from the selected folder without touching existing entries | Building a multi-folder selection before promoting |

Section A wipes **all** scans and entries — not just the one for the folder being scanned. This is intentional: the catalog is a scratchpad, and a reload means "start over completely."

### Auto-populated defaults at scan time

`catalog_scan_start.php` populates two fields automatically so that a fresh scan rarely triggers the promote-time validation error (missing `org_name` or `event_date`):

- **`catalog_scans.org_name`** defaults to `'Default'` when the scan form is submitted with a blank org field. The COALESCE in `catalog_promote_start.php` picks this up for any entry that has no per-entry `org_name`. The operator should review and correct `'Default'` entries in `db/database_catalog.php` before promoting (amber-highlighted Org column indicates values that need review).
- **`catalog_entries.event_date`** is derived per-entry from the filename at scan time — the scanner looks for an 8-digit `YYYYMMDD` pattern (e.g. `StormPigs20050526_…` → `2005-05-26`). If no date is found in the filename, the file's `last_modified` timestamp date is used as a fallback. Either result is better than NULL: it prevents the promote validation error while still being correctable by the operator (amber-highlighted Event Date column).

### Post-promote wipe (deferred)

After a successful promote, all entries transition to `status = 'imported'` and the catalog's value is exhausted. A post-promote catalog wipe is a planned cleanup step that is not yet implemented. For now, operators can observe `status = 'imported'` entries in the catalog as a record of what has been processed.

### Where permanent data lives

The catalog is upstream of the canonical data model. Permanent data lives in `assets`, `events`, and `event_items` — the catalog contributes no rows to these tables directly. Rows reach them via `ingestComplete()` during the TUS upload step.

---

## Database Schema

Two new tables: `catalog_scans` (one row per scan job) and `catalog_entries` (one row per file found).

### `catalog_scans`

```sql
CREATE TABLE IF NOT EXISTS catalog_scans (
    scan_id           INT UNSIGNED    NOT NULL AUTO_INCREMENT PRIMARY KEY,
    source_root       VARCHAR(1024)   NOT NULL,
    scan_label        VARCHAR(255)    NULL,
    org_name          VARCHAR(128)    NULL,
    event_date        DATE            NULL,
    event_type        ENUM('band','wedding','other') NULL,
    location          VARCHAR(255)    NULL,
    keywords          TEXT            NULL,
    summary           TEXT            NULL,
    notes             TEXT            NULL,
    status            ENUM('running','complete','failed','canceled') NOT NULL DEFAULT 'running',
    total_files       INT UNSIGNED    NULL,
    supported_files   INT UNSIGNED    NULL,
    unsupported_files INT UNSIGNED    NULL,
    total_size_bytes  BIGINT UNSIGNED NULL,
    audio_count       INT UNSIGNED    NULL,
    video_count       INT UNSIGNED    NULL,
    audio_size_bytes  BIGINT UNSIGNED NULL,
    video_size_bytes  BIGINT UNSIGNED NULL,
    started_at        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at      DATETIME        NULL,
    created_at        TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_catalog_scans_status (status),
    INDEX idx_catalog_scans_source (source_root(255))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### `catalog_entries`

```sql
CREATE TABLE IF NOT EXISTS catalog_entries (
    catalog_entry_id   INT UNSIGNED    NOT NULL AUTO_INCREMENT PRIMARY KEY,
    scan_id            INT UNSIGNED    NOT NULL,
    source_relpath     VARCHAR(4096)   NOT NULL,
    file_name          VARCHAR(512)    NOT NULL,
    file_ext           VARCHAR(32)     NULL,
    file_type          ENUM('audio','video','unknown') NOT NULL DEFAULT 'unknown',
    is_supported       TINYINT(1)      NOT NULL DEFAULT 0,
    mime_type          VARCHAR(255)    NULL,
    size_bytes         BIGINT UNSIGNED NULL,
    file_mtime         DATETIME        NULL,
    org_name           VARCHAR(128)    NULL,
    event_date         DATE            NULL,
    event_type         ENUM('band','wedding','other') NULL,
    location           VARCHAR(255)    NULL,
    keywords           TEXT            NULL,
    summary            TEXT            NULL,
    notes              TEXT            NULL,
    label              VARCHAR(255)    NULL,
    item_type          ENUM('song','loop','clip','highlight') NULL,
    participants       VARCHAR(1024)   NULL,
    status             ENUM('cataloged','selected','skipped','imported','failed') NOT NULL DEFAULT 'cataloged',
    asset_id           INT             NULL,
    upload_job_id      VARCHAR(64)     NULL,
    first_seen_scan_id INT UNSIGNED    NULL,
    last_seen_scan_id  INT UNSIGNED    NULL,
    path_hash          CHAR(64)        NOT NULL,
    created_at         TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at         TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_catalog_entries_scan        FOREIGN KEY (scan_id)            REFERENCES catalog_scans (scan_id)  ON DELETE CASCADE,
    CONSTRAINT fk_catalog_entries_asset       FOREIGN KEY (asset_id)           REFERENCES assets        (asset_id) ON DELETE SET NULL,
    CONSTRAINT fk_catalog_entries_job         FOREIGN KEY (upload_job_id)      REFERENCES upload_jobs   (job_id)   ON DELETE SET NULL,
    CONSTRAINT fk_catalog_entries_first_scan  FOREIGN KEY (first_seen_scan_id) REFERENCES catalog_scans (scan_id)  ON DELETE SET NULL,
    CONSTRAINT fk_catalog_entries_last_scan   FOREIGN KEY (last_seen_scan_id)  REFERENCES catalog_scans (scan_id)  ON DELETE SET NULL,
    UNIQUE KEY uq_catalog_entries_path_hash    (path_hash),
    UNIQUE KEY uq_catalog_entries_scan_relpath (scan_id, source_relpath(512)),
    INDEX idx_catalog_entries_status    (status),
    INDEX idx_catalog_entries_file_type (file_type),
    INDEX idx_catalog_entries_event     (org_name, event_date),
    INDEX idx_catalog_entries_asset     (asset_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

---

## Column Design Notes

### Column Naming Convention

All column names mirror the existing canonical tables (`assets`, `events`, `event_items`) exactly to eliminate ambiguity at promote time:

| Catalog Column | Mirrors |
|---|---|
| `file_type` | `assets.file_type` |
| `file_ext` | `assets.file_ext` |
| `source_relpath` | `assets.source_relpath` |
| `size_bytes` | `assets.size_bytes` |
| `mime_type` | `assets.mime_type` |
| `org_name` | `events.org_name` |
| `event_date` | `events.event_date` |
| `event_type` | `events.event_type` |
| `location` | `events.location` |
| `keywords` | `events.keywords` |
| `summary` | `events.summary` |
| `label` | `event_items.label` |
| `item_type` | `event_items.item_type` |

### `summary` vs `notes`

- **`summary`** — event description; promoted to `events.summary` at ingest time. The column name matches `events.summary` directly. (Note: the existing single-file upload form in `UploadService` POSTs this value under a field named `notes` — that is a pre-existing form-field naming quirk in the upload layer, not a general naming convention.)
- **`notes`** — operator-only annotation on the catalog entry itself (e.g., "client hasn't approved", "skip — duplicate recording"). **Not promoted to any downstream table.** Catalog lifecycle only.

### `path_hash`

SHA-256 of `webkitRelativePath` (= SHA-256(`source_root + '/' + source_relpath`)), computed server-side from the browser-provided `relpath` string with no file I/O. Unique per browser-visible relative path. Powers:
- Cross-scan deduplication (Section B re-scans update existing entries rather than inserting new ones)
- Clean 1:1 mapping to upload job files at promote time — prevents duplicate entries in `upload_job_files` on re-scan

A secondary `UNIQUE(scan_id, source_relpath(512))` guards within-scan duplicates.

### `duration_seconds` — Intentionally Absent

Duration requires ffprobe and violates the no-media-processing constraint. Estimated duration is derived at query/display time from `size_bytes` + `file_type`:

| Type | Formula | Bitrate Assumption |
|---|---|---|
| Audio | `size_bytes / (192 * 125)` | 192 kbps (MP3/AAC/M4A/FLAC average) |
| Video | `size_bytes / (4000 * 125)` | 4,000 kbps (MP4/MOV/MKV average) |

For use case #14 (AI cost preview): `estimated_video_minutes = video_size_bytes / (4000 * 125 * 60)`.

### `item_type` — NULL Means Auto-Derive

When NULL at promote time, the same default rule as `UploadService` applies: `event_type === 'wedding' ? 'clip' : 'song'`. An explicit value overrides the rule (e.g., to mark a file as `'highlight'` or `'loop'`).

### Scan-Level vs Entry-Level Event Columns

`org_name`, `event_date`, `event_type`, `location`, `keywords`, `summary` appear on **both** tables. The scan-level values are defaults applied to all entries in that scan. Per-entry values override the scan default at promote time. NULL on an entry = inherit from `catalog_scans`.

---

## Column Coverage by Use Case

### `catalog_scans` — Column Coverage

| Column | Use Cases Covered |
|---|---|
| `scan_id` | #5, #6, #7, #12 |
| `source_root` | #5, #7 |
| `scan_label` | #5 |
| `org_name` | #4 |
| `event_date` | #4 |
| `event_type` | #1 |
| `location` | #13 |
| `keywords` | #13 |
| `summary` | #13 |
| `notes` | #6, #8 |
| `status` | #6 |
| `total_files` | #1, #2 |
| `supported_files` | #10 |
| `unsupported_files` | #10 |
| `total_size_bytes` | #2, #11 |
| `audio_count` | #2, #14 |
| `video_count` | #2, #14 |
| `audio_size_bytes` | #2, #14 (proxy for duration) |
| `video_size_bytes` | #2, #14 (proxy for duration) |
| `started_at` | #6, #12 |
| `completed_at` | #6, #12 |

### `catalog_entries` — Column Coverage

| Column | Use Cases Covered |
|---|---|
| `scan_id` FK | #5, #6, #7, #12 |
| `source_relpath` | #1, #3, #7 |
| `file_name` | #1, #10, #13 |
| `file_ext` | #10 |
| `file_type` | #1, #2, #10, #14 |
| `is_supported` | #10 |
| `mime_type` | #1, #10 |
| `size_bytes` | #2, #11, #14 (proxy for duration) |
| `file_mtime` | #12 |
| `org_name` | #1, #4 |
| `event_date` | #1, #4 |
| `event_type` | #1 |
| `location` | #13 |
| `keywords` | #13 |
| `summary` | #13 |
| `notes` | #8 |
| `label` | #1, #13 |
| `item_type` | #13 |
| `participants` | #7, #13 |
| `status` | #3, #8, #13 |
| `asset_id` FK | #9 |
| `upload_job_id` FK | #13 |
| `first_seen_scan_id` FK | #12 |
| `last_seen_scan_id` FK | #9, #12 |
| `path_hash` | #12 |

---

## Catalog → Ingested File Column Mapping

Columns consumed by the downstream ingest pipeline when a catalog entry is promoted via `status = 'selected'` → manifest import → TUS upload → `UnifiedIngestionCore`.

| `catalog_entries` Column | Destination Column | Destination Table | Notes |
|---|---|---|---|
| `file_type` | `file_type` | `assets` | Direct — exact match |
| `file_ext` | `file_ext` | `assets` | Direct — exact match |
| `source_relpath` | `source_relpath` | `assets` | Direct — exact match |
| `size_bytes` | `size_bytes` | `assets` | Direct — exact match |
| `mime_type` | `mime_type` | `assets` | Direct — exact match |
| `org_name` | `org_name` | `events` | Direct — exact match |
| `event_date` | `event_date` | `events` | Direct — exact match |
| `event_type` | `event_type` | `events` | Direct — exact match |
| `location` | `location` | `events` | Direct — exact match |
| `keywords` | `keywords` | `events` | Direct — exact match |
| `summary` | `summary` | `events` | Direct — exact match |
| `label` | `label` | `event_items` | Direct — exact match |
| `item_type` | `item_type` | `event_items` | Direct — exact match; NULL → auto-derive at promote time |
| `asset_id` | *(write-back only)* | `catalog_entries` | NULL at promote time; written back to `catalog_entries.asset_id` after `assets` INSERT completes. Not an input to the pipeline. |
| `participants` | `name` (×N rows) | `participants` + `event_participants` | Comma-split → `attachParticipants()` — same as single upload |
| `file_name` | *(derived only)* | — | Used to derive `source_relpath` + `label`; not a column in `assets` |
| `file_mtime` | *(not promoted)* | — | Catalog lifecycle only; `assets.media_created_at` comes from ffprobe |
| `path_hash`, `first/last_seen_scan_id`, `is_supported` | *(not promoted)* | — | Catalog lifecycle columns only |
| `notes` | *(not promoted)* | — | Operator annotation; catalog lifecycle only |

### Promote Restriction

`assets.file_type` is `ENUM('audio','video') NOT NULL` — the 'unknown' value present in `catalog_entries.file_type` has no legal target. Only entries where `is_supported = 1` (i.e., `file_type IN ('audio','video')`) may be promoted. The UI must prevent marking `is_supported = 0` entries as `status = 'selected'`, and the promote endpoint must enforce this as a server-side guard.

### `catalog_scans` → `events` Defaults

Used when the corresponding `catalog_entries` column is NULL; inherited from the parent scan at promote time.

| `catalog_scans` Column | Destination Column | Destination Table |
|---|---|---|
| `org_name` | `org_name` | `events` |
| `event_date` | `event_date` | `events` |
| `event_type` | `event_type` | `events` |
| `location` | `location` | `events` |
| `keywords` | `keywords` | `events` |
| `summary` | `summary` | `events` |

---

## Phase 1 Scope

### Pages and Endpoints

| File | Role |
|---|---|
| `admin/admin_database_catalog_media_from_folder.php` | Scan trigger UI (Section A / B) |
| `admin/catalog_scan_start.php` | POST endpoint — receives browser FileList metadata, writes catalog DB rows, returns JSON summary |
| `admin/catalog_entries_list.php` | GET endpoint — paginated entries for the scan trigger UI |
| `db/database_catalog.php` | Browse / edit / delete UI for catalog entries |
| `db/catalog_entry_save.php` | POST endpoint — saves field edits and row deletes |

### Admin Page

**`admin_database_catalog_media_from_folder.php`**

Mirrors the structure of `admin_database_load_import_media_from_folder.php` with two sections.

### Section A — Catalog Media (Reload)

- User picks a folder via browser `<input type="file" webkitdirectory>` (same picker as import page)
- JS reads `FileList` metadata (name, size, lastModified, webkitRelativePath) — no hashing, no upload, no TUS
- JS POSTs a `files` array to `catalog_scan_start.php`
- Server wipes the **entire** catalog: runs `DELETE FROM catalog_scans` which cascades to all `catalog_entries` (not scoped to the current `source_root` — see Catalog Lifecycle below)
- Creates a fresh `catalog_scans` row (`status = 'running'`)
- Server iterates the `files` array, computes `path_hash` per entry, batch-INSERTs into `catalog_entries`
- Updates scan aggregate columns (`total_files`, `total_size_bytes`, etc.) on completion
- Sets `scan.status = 'complete'`

### Section B — Add to Catalog (Non-Destructive)

- Same folder picker flow; user may pick the same or a different folder
- JS POSTs the `files` array to `catalog_scan_start.php` with `mode = 'add'`
- For each file: server computes `path_hash`
  - If `path_hash` not in `catalog_entries`: INSERT new entry with `first_seen_scan_id = last_seen_scan_id = current scan_id`
  - If `path_hash` exists: UPDATE `last_seen_scan_id = current scan_id`
- Entries in DB whose `last_seen_scan_id` was not updated = file no longer present in the latest pick (orphan detection, use case #9)

### Key Differences from Folder Import Page

| Aspect | `admin_database_load_import_media_from_folder.php` | `admin_database_catalog_media_from_folder.php` |
|---|---|---|
| File selection | Browser file picker (`webkitdirectory`) | Browser file picker (`webkitdirectory`) — same as import page |
| File transfer | TUS chunked upload | None — metadata-only POST, no bytes transferred |
| Hashing | SHA-256 per file (browser-side) | None — `path_hash` derived from `webkitRelativePath` server-side |
| Media probing | Via `ingestComplete()` | None |
| Steps | Step 1 (Choose Folder → hash → manifest) → Step 2 (upload) → Step 3 (finalize) | Step 1 (Choose Folder, scan button enables) → Step 2 (Catalog — metadata POST only) |
| Output | Assets in `assets` + `event_items` | Entries in `catalog_scans` + `catalog_entries` only |
| Downstream tables touched | `assets`, `events`, `event_items`, `upload_jobs`, `upload_job_files` | `catalog_scans`, `catalog_entries` only |

### source_root Derivation

Because file selection is browser-side, `source_root` is derived from `webkitRelativePath`: the first path segment is the top-level folder name chosen by the user (e.g., if the user picks a folder named `2024-recordings`, all `webkitRelativePath` values begin with `2024-recordings/...`). The `path_hash` is SHA-256 of the full `webkitRelativePath`, which equals SHA-256(`source_root` + `'/'` + `source_relpath`) — the same formula as before, just fed browser-provided strings instead of server-provided absolute paths.

### Phase 1 Boundary

Phase 1 ends at **catalog + review**. The operator can scan folders, browse entries in `db/database_catalog.php`, edit metadata fields, and mark entries as `status = 'selected'` or `status = 'skipped'`. No upload, no hashing, no `assets` table writes occur in Phase 1.

The subsequent **promote step** — taking `status = 'selected'` entries through hash → TUS upload → `ingestComplete()` → `assets` INSERT — is Phase 2, documented separately in `docs/refactor_catalog_upload_using_catalog.md`. Phase 1 must be fully implemented and stable before Phase 2 begins.

---

## Design Decisions Log

| Decision | Choice Made | Rationale |
|---|---|---|
| Catalog vs. extending `assets` | Separate `catalog_scans` + `catalog_entries` tables | `assets.checksum_sha256` is NOT NULL UNIQUE; no checksum = can't fit cleanly in `assets` without corrupting the dedup invariant |
| Primary dedup key | `UNIQUE(path_hash)` — SHA-256 of `(source_root + '/' + source_relpath)` = SHA-256(`webkitRelativePath`) | Prevents duplicate catalog entries across re-scans; ensures clean 1:1 mapping to upload job files at promote time. `UNIQUE(scan_id, source_relpath(512))` added as secondary within-scan guard. **Collision risk**: because `source_root` is now just the chosen folder's name (not an absolute path), two physically different folders with the same top-level folder name and overlapping relative paths will produce identical `path_hash` values — operators must use distinct top-level folder names to avoid silent cross-contamination |
| `duration_seconds` | Omitted; proxy via `size_bytes` ÷ avg bitrate | Duration requires ffprobe; violates no-media-processing constraint. Proxy is sufficient for import time estimation (#2) and AI cost preview (#14) |
| `item_type` NULL behavior | NULL = auto-derive (wedding→clip, else→song) | Consistent with existing `UploadService` logic; explicit value overrides for highlight/loop use cases |
| `summary` vs `notes` | Both present with distinct semantics | `summary` → `events.summary` at promote time; `notes` = operator annotation, catalog lifecycle only, never promoted |
| `path_hash` nullability | `NOT NULL` | Enforces uniqueness constraint; computed in PHP from path strings with zero file I/O |
| Per-entry vs scan-level event columns | Both, with entry overriding scan | Scan provides defaults for bulk operation; per-entry override supports multi-event scans (e.g., a NAS with folders for multiple events) |
| Section A reload scope | Wipes **entire** catalog (`DELETE FROM catalog_scans`, no `WHERE`) | Catalog is ephemeral staging — reload means start over completely, not just replace one source_root. Pre-existing entries from other source_roots are staging artifacts with no value once a fresh session begins. |
| `catalog_scans.org_name` scan-level default | `'Default'` when blank/absent | Prevents promote-time 422 validation error for operators who skip the scan form; amber Org column in `db/database_catalog.php` signals which entries need review before promoting. |
| `catalog_entries.event_date` auto-derivation | YYYYMMDD from filename; file mtime fallback | Same goal as org_name default: ensures COALESCE always resolves to a non-NULL value at promote time; filename-derived date is usually correct for structured archives (e.g. `StormPigs20050526_…`); operator can override per-row before promoting. |

---

## Database Migration (Existing Installations)

The tables were added to `create_music_db.sql` and will be present on any fresh install. For an existing `music_db` that is already running, run the following from the **docker host**. Both statements use `IF NOT EXISTS` so the command is safe to re-run.

Rollback if needed: `DROP TABLE IF EXISTS catalog_entries; DROP TABLE IF EXISTS catalog_scans;`

```bash
docker exec -i mysqlServer sh -lc 'mysql -h 127.0.0.1 -u root -p"$MYSQL_ROOT_PASSWORD" -D "$MYSQL_DATABASE"' << 'MIGRATION'
CREATE TABLE IF NOT EXISTS catalog_scans (
    scan_id           INT UNSIGNED    NOT NULL AUTO_INCREMENT PRIMARY KEY,
    source_root       VARCHAR(1024)   NOT NULL,
    scan_label        VARCHAR(255)    NULL,
    org_name          VARCHAR(128)    NULL,
    event_date        DATE            NULL,
    event_type        ENUM('band','wedding','other') NULL,
    location          VARCHAR(255)    NULL,
    keywords          TEXT            NULL,
    summary           TEXT            NULL,
    notes             TEXT            NULL,
    status            ENUM('running','complete','failed','canceled') NOT NULL DEFAULT 'running',
    total_files       INT UNSIGNED    NULL,
    supported_files   INT UNSIGNED    NULL,
    unsupported_files INT UNSIGNED    NULL,
    total_size_bytes  BIGINT UNSIGNED NULL,
    audio_count       INT UNSIGNED    NULL,
    video_count       INT UNSIGNED    NULL,
    audio_size_bytes  BIGINT UNSIGNED NULL,
    video_size_bytes  BIGINT UNSIGNED NULL,
    started_at        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at      DATETIME        NULL,
    created_at        TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    skipped_count     INT UNSIGNED    NOT NULL DEFAULT 0,
    INDEX idx_catalog_scans_status (status),
    INDEX idx_catalog_scans_source (source_root(255))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS catalog_entries (
    catalog_entry_id   INT UNSIGNED    NOT NULL AUTO_INCREMENT PRIMARY KEY,
    scan_id            INT UNSIGNED    NOT NULL,
    source_relpath     VARCHAR(4096)   NOT NULL,
    file_name          VARCHAR(512)    NOT NULL,
    file_ext           VARCHAR(32)     NULL,
    file_type          ENUM('audio','video','unknown') NOT NULL DEFAULT 'unknown',
    is_supported       TINYINT(1)      NOT NULL DEFAULT 0,
    mime_type          VARCHAR(255)    NULL,
    size_bytes         BIGINT UNSIGNED NULL,
    file_mtime         DATETIME        NULL,
    org_name           VARCHAR(128)    NULL,
    event_date         DATE            NULL,
    event_type         ENUM('band','wedding','other') NULL,
    location           VARCHAR(255)    NULL,
    keywords           TEXT            NULL,
    summary            TEXT            NULL,
    notes              TEXT            NULL,
    label              VARCHAR(255)    NULL,
    item_type          ENUM('song','loop','clip','highlight') NULL,
    participants       VARCHAR(1024)   NULL,
    status             ENUM('cataloged','selected','skipped','imported','failed') NOT NULL DEFAULT 'cataloged',
    asset_id           INT             NULL,
    upload_job_id      VARCHAR(64)     NULL,
    first_seen_scan_id INT UNSIGNED    NULL,
    last_seen_scan_id  INT UNSIGNED    NULL,
    path_hash          CHAR(64)        NOT NULL,
    created_at         TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at         TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_catalog_entries_scan        FOREIGN KEY (scan_id)            REFERENCES catalog_scans (scan_id)  ON DELETE CASCADE,
    CONSTRAINT fk_catalog_entries_asset       FOREIGN KEY (asset_id)           REFERENCES assets        (asset_id) ON DELETE SET NULL,
    CONSTRAINT fk_catalog_entries_job         FOREIGN KEY (upload_job_id)      REFERENCES upload_jobs   (job_id)   ON DELETE SET NULL,
    CONSTRAINT fk_catalog_entries_first_scan  FOREIGN KEY (first_seen_scan_id) REFERENCES catalog_scans (scan_id)  ON DELETE SET NULL,
    CONSTRAINT fk_catalog_entries_last_scan   FOREIGN KEY (last_seen_scan_id)  REFERENCES catalog_scans (scan_id)  ON DELETE SET NULL,
    UNIQUE KEY uq_catalog_entries_path_hash    (path_hash),
    UNIQUE KEY uq_catalog_entries_scan_relpath (scan_id, source_relpath(512)),
    INDEX idx_catalog_entries_status    (status),
    INDEX idx_catalog_entries_file_type (file_type),
    INDEX idx_catalog_entries_event     (org_name, event_date),
    INDEX idx_catalog_entries_asset     (asset_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
MIGRATION
```

### source_relpath collation — decision and skip-tracking plan

#### Decision: revert to `utf8mb4_unicode_ci` (table default)

An earlier revision changed `source_relpath` to `utf8mb4_bin` (byte-exact) to prevent a duplicate-key error when two folders differ only by Unicode diacritics (e.g. `Milos Karadaglic/` vs `Miloš Karadaglić/`). After further review, `utf8mb4_bin` was **reverted** for the following reasons:

| Concern | `utf8mb4_bin` risk | `utf8mb4_unicode_ci` behaviour |
|---|---|---|
| Cross-OS NFD/NFC normalization | macOS stores paths as NFD; Linux uses NFC. `_bin` treats them as different even when visually identical — breaks correct dedup in mixed-OS environments | `_ci` normalises these to equal — correct for most users |
| Consistency with `assets.source_relpath` | Would be an exception column; JOINs between tables could silently fail | Matches existing column on `assets` table — Phase 2 promote workflow is safe |
| Case-variant folder names (`ACDC` vs `acdc`) | Treated as distinct — operationally surprising | Correctly collapsed |
| Diacritic-distinct artist folders | Both cataloged independently — benefit for librarian persona < 5% of user base | Second folder's files skipped — acceptable with transparent warning (see below) |

`assets.source_relpath` and all other path columns in the schema use `utf8mb4_unicode_ci`. Consistency and correct cross-OS behaviour outweigh the edge-case benefit.

#### How duplicate paths are handled under `_ci`

`utf8mb4_unicode_ci` uses primary-weight comparison — diacritics are stripped at comparison time, so `s` and `š` are equal. Within a single **reload** scan, if two submitted files resolve to the same `source_relpath` under `_ci` (e.g. both folders contain the same album/track names), the second occurrence is a key collision.

**Reload mode (`INSERT IGNORE` + skip tracking):**

1. Change the batch INSERT to `INSERT IGNORE` (reload mode only).
2. After each batch: `$skipped += ($batchSize - $stmt->rowCount())`.
3. If `$skipped > 0` after all batches: run one `SELECT source_relpath FROM catalog_entries WHERE scan_id = $scanId`, diff against `$submittedPaths` in PHP → `$droppedPaths[]`.
4. Store `$skipped` in the new `catalog_scans.skipped_count` column.
5. Return `ignored: { count: N, paths: [...] }` in the JSON response.
6. Render a **warning stat box** in the scan result card if `skipped_count > 0`.

**Add mode (unchanged):** keeps `ON DUPLICATE KEY UPDATE last_seen_scan_id = VALUES(last_seen_scan_id)`. This intentionally updates the last-seen timestamp when a file is re-encountered in a later add scan. Within-batch diacritic collisions in add mode cause the second file's metadata to silently overwrite the first's via the ON DUPLICATE KEY path — acceptable given the rarity.

**Why not use `INSERT IGNORE` for add mode:** the diff against `scan_id` would conflate "file already cataloged from a prior scan" (normal, expected) with "path collision" (warning). These are indistinguishable from a single `SELECT WHERE scan_id = $scanId`, so skip-tracking is limited to reload mode where the scan_id is always fresh.

#### Schema change: `catalog_scans.skipped_count`

Add the following column to `catalog_scans`:

```sql
skipped_count INT UNSIGNED NOT NULL DEFAULT 0
```

#### Migration for existing installations

Run from the **docker host** to apply both changes (revert `_bin` + add `skipped_count`):

```bash
docker exec -i mysqlServer sh -lc 'mysql -h 127.0.0.1 -u root -p"$MYSQL_ROOT_PASSWORD" -D "$MYSQL_DATABASE" << "SQL"
ALTER TABLE catalog_entries MODIFY source_relpath VARCHAR(4096) NOT NULL;
ALTER TABLE catalog_scans ADD COLUMN skipped_count INT UNSIGNED NOT NULL DEFAULT 0;
SQL'
```

> **Note:** `ADD COLUMN IF NOT EXISTS` is MariaDB syntax and is not supported in MySQL 8.x. If `skipped_count` already exists, MySQL will return `ERROR 1060 (42S21): Duplicate column name` — that error is safe to ignore.

#### Files changed by this plan

| File | Change |
|---|---|
| `create_music_db.sql` | Revert `source_relpath` explicit `COLLATE utf8mb4_bin` → column default; add `skipped_count` to `catalog_scans` |
| `admin/catalog_scan_start.php` | See implementation notes below |
| `admin/admin_database_catalog_media_from_folder.php` | `renderSummary()` renders an **Ignored** stat box (orange badge) when `data.ignored.count > 0`, listing the first 5 dropped paths |

#### `catalog_scan_start.php` implementation details (skip tracking)

These are the exact code changes required — caught during logic review:

1. **`insertBatch` return type**: Change `void` → `int`; capture `$stmt` before `execute()` and return `$stmt->rowCount()`.
2. **`INSERT IGNORE` for reload only**: Change base SQL from `INSERT INTO` to `INSERT IGNORE INTO` when `$mode === 'reload'`. Add mode keeps `ON DUPLICATE KEY UPDATE` unchanged.
3. **Collect `$submittedRelpaths`**: Inside the `foreach ($filesIn as $entry)` loop, add `$submittedRelpaths[] = $sourceRel` alongside the existing counter increments. This array is needed for the post-INSERT diff. Note: `$sourceRel` is the stripped relpath (`explode('/', $relpath, 2)[1]`), matching exactly what is stored in `source_relpath`.
4. **Mode-gated skip accumulation**: `if ($mode === 'reload') { $skipped += (count($batch) - $insertBatch($pdo, $batch, $mode)); }` — must be reload-only. In add mode, `rowCount()` returns `2` per ON DUPLICATE KEY row (not `0`), so `batchSize - rowCount` would be incorrect.
5. **Post-INSERT diff**: `if ($skipped > 0) { $inserted = $pdo->query("SELECT source_relpath FROM catalog_entries WHERE scan_id = $scanId")->fetchAll(PDO::FETCH_COLUMN, 0); $droppedPaths = array_values(array_diff($submittedRelpaths, $inserted)); }`
6. **`skipped_count` in UPDATE**: Add `skipped_count=?` to the `UPDATE catalog_scans SET ...` statement and append `$skipped` to the execute params array.
7. **Response**: Add `'ignored' => ['count' => $skipped, 'paths' => $droppedPaths]` to the `json_encode` output.

---

## Phase 1 Implementation Plan

### Files Changed

**New (5):**

1. `admin/admin_database_catalog_media_from_folder.php` — scan trigger UI with Section A (destructive reload) and Section B (non-destructive add) forms; uses browser `webkitdirectory` file picker (mirroring the import page): Choose Folder (Step 1) → scan button enables (Step 2)
2. `admin/catalog_scan_start.php` — POST endpoint that receives a browser-provided `files` metadata array and writes `catalog_scans` + `catalog_entries` rows; no server-side filesystem walk
3. `admin/catalog_entries_list.php` — GET endpoint returning paginated `catalog_entries` for a given `scan_id`; used by the scan trigger UI post-scan
4. `db/database_catalog.php` — browse / edit / delete UI for reviewing and annotating all catalog entries before promote
5. `db/catalog_entry_save.php` — POST endpoint handling inline field edits and row deletes from the catalog review UI

**Modified (3):**

6. `admin/admin_database_load_import_media_from_folder.php` — add **Catalog Folder** nav button
7. `admin/admin_system.php` — add **Catalog Folder** nav button
8. `admin/admin_database_load_import_csv.php` — add **Catalog Folder** nav button

---

### Environment Variables

No new environment variables are required. Phase 1 uses only two already-present vars:

| Var | Already defined in | Purpose in catalog scan |
|---|---|---|
| `UPLOAD_AUDIO_EXTS_JSON` | `.env.j2` via `gighive_upload_audio_exts` | Infer `file_type = 'audio'` + `is_supported = 1` |
| `UPLOAD_VIDEO_EXTS_JSON` | `.env.j2` via `gighive_upload_video_exts` | Infer `file_type = 'video'` + `is_supported = 1` |

No Ansible group_vars changes, no `.env.j2` additions, no new role vars needed.

---

### New Files (5)

#### 1. `admin/admin_database_catalog_media_from_folder.php` — UI page

Mirrors the Section A / Section B structure of `admin_database_load_import_media_from_folder.php` with the following differences:

| Aspect | Import page | Catalog page |
|---|---|---|
| File selection | `webkitdirectory` browser picker | `webkitdirectory` browser picker — identical |
| `tus-js-client` | Required | Not needed |
| `import_progress.css/js` | Required | Not needed |
| SHA-256 Web Worker | Required | Not needed — no hashing |
| IndexedDB hash cache | Required | Not needed |
| Duplicate resolution modal | Required | Not needed |
| Step 2 / Step 3 upload panel | Required | Not needed |
| JS scan flow | hash → prepare → finalize → poll → upload | Choose Folder → read FileList metadata → POST `catalog_scan_start.php` → render summary |

Reused from the import page: same CSS card/badge/alert styles, `formatBytes()`, `escapeHtml()`, `el()`, `html()`, `UPLOAD_AUDIO_EXTS_JSON` / `UPLOAD_VIDEO_EXTS_JSON` env var read, `_S` state object pattern.

**Section A** — Catalog Media (Reload): wipes the **entire** catalog (`DELETE FROM catalog_scans`, cascades to all `catalog_entries`), then scans the selected folder fresh. See *Catalog Lifecycle and Ephemerality* above.

**Section B** — Add to Catalog (Non-Destructive): scans and upserts via `path_hash`; existing entries get `last_seen_scan_id` updated.

Both sections accept optional metadata fields: `scan_label`, `org_name`, `event_date`, `event_type`, `location`, `keywords`, `summary`, `notes`.

After a successful scan the page renders a summary card showing: total / supported / unsupported file counts, total size, audio/video breakdown, estimated import time, and estimated AI tagging cost (proxy formula).

Nav buttons on this page: existing admin nav buttons (Password Reset, etc.) + **Catalog Review** link to `db/database_catalog.php`.

---

#### 2. `admin/catalog_scan_start.php` — POST endpoint

**Request** (JSON body):
```json
{
  "mode": "reload",
  "scan_label": null,
  "org_name": null,
  "event_date": null,
  "event_type": null,
  "location": null,
  "keywords": null,
  "summary": null,
  "notes": null,
  "files": [
    { "relpath": "2024-recordings/set1/song.mp3", "size_bytes": 8421376, "last_modified_ms": 1718000000000 },
    { "relpath": "2024-recordings/set1/clip.mp4", "size_bytes": 104857600, "last_modified_ms": 1718000100000 }
  ]
}
```

`relpath` = `file.webkitRelativePath` from the browser `FileList`. The server derives `source_root` (first path segment), `source_relpath` (remainder), `file_ext`, `file_type`, `is_supported`, `mime_type`, `file_mtime` (from `last_modified_ms`), `path_hash` = SHA-256(`relpath`), and `event_date` (extracted from filename — see step 6). No server-side directory walk occurs.

**Scan-level `org_name` default:** if the submitted `org_name` is blank or absent, the server defaults it to `'Default'` before writing `catalog_scans`. This ensures the COALESCE fallback in `catalog_promote_start.php` always resolves to a non-NULL value, preventing the promote-time 422 validation error for operators who do not fill in the scan form. The operator corrects `'Default'` entries in `db/database_catalog.php` (amber-highlighted Org column) before promoting.

**Guards the endpoint must enforce before any DB writes:**
- `files` array missing or empty → HTTP 400 `"No files found in selected folder"`
- `files` count exceeds `MAX_FILES` (50 000) → HTTP 400 `"Too many files; split into smaller batches"`
- Any `relpath` entry with no `/` separator (i.e. no subdirectory — webkitRelativePath always includes the folder name as the first segment) → skip entry or HTTP 400
- `mode` not `reload` or `add` → HTTP 400

**Response**:
```json
{
  "success": true,
  "scan_id": 1,
  "mode": "reload",
  "summary": {
    "total_files": 150,
    "supported_files": 142,
    "unsupported_files": 8,
    "total_size_bytes": 5494140928,
    "audio_count": 80,
    "video_count": 62,
    "audio_size_bytes": 1073741824,
    "video_size_bytes": 4294967296,
    "estimated_audio_minutes": 746,
    "estimated_video_minutes": 143,
    "estimated_ai_cost_usd": 2.85
  },
  "duration_ms": 450
}
```

Note: `total_size_bytes` is the sum of `size_bytes` for **all** files in the browser-provided `files` array, including unsupported files. `audio_size_bytes + video_size_bytes` will therefore be less than `total_size_bytes` whenever unsupported files are present (as in the example above, where 8 unsupported files account for the ~118 MB difference).

**Server-side logic:**
1. Auth check (admin only)
2. Guards (see above): `files` present and non-empty; count ≤ `MAX_FILES` (50 000); `mode` is `reload` or `add`; every `relpath` contains at least one `/`
3. Derive `source_root` = first path segment of `$files[0]['relpath']` (e.g. `2024-recordings` from `2024-recordings/set1/song.mp3`)
4. If `mode === 'reload'`: `DELETE FROM catalog_scans` — **no `WHERE` clause** — wipes the entire catalog (cascades to all `catalog_entries`). This is intentional: the catalog is ephemeral staging; reload = start over completely.
5. `INSERT INTO catalog_scans` (`status = 'running'`, `source_root`, optional metadata fields)
6. Iterate the `files` array; for each entry:
   - `$relpath = $entry['relpath']` (= `webkitRelativePath`)
   - Split on first `/`: `source_root` (ignored — already derived), `source_relpath` (remainder)
   - `pathinfo($relpath)` → `file_name`, `file_ext`
   - Infer `file_type` + `is_supported` from ext against `UPLOAD_AUDIO_EXTS_JSON` / `UPLOAD_VIDEO_EXTS_JSON`
   - Static ext→MIME lookup (no probing)
   - `size_bytes = (int)$entry['size_bytes']`
   - `file_mtime = date('Y-m-d H:i:s', intval($entry['last_modified_ms'] / 1000))`
   - `path_hash = hash('sha256', $relpath)` — SHA-256 of `webkitRelativePath`; equals SHA-256(`source_root . '/' . source_relpath`)
   - `event_date`: look for an 8-digit `YYYYMMDD` pattern in `file_name` (e.g. `StormPigs20050526_…` → `2005-05-26`, year must be 1990–2099); if not found, fall back to the date portion of `file_mtime`. Stored per-entry in `catalog_entries.event_date`. Operator can override in `db/database_catalog.php` before promoting.
   - If `mode === 'reload'`: `INSERT` with `scan_id`, `first_seen_scan_id`, `last_seen_scan_id` all set to the current `scan_id`, and `event_date` set to the derived date
   - If `mode === 'add'`: `INSERT` (same columns) `ON DUPLICATE KEY UPDATE last_seen_scan_id = current_scan_id` — `scan_id` and `first_seen_scan_id` are left unchanged on conflict, preserving the original scan reference
7. `UPDATE catalog_scans SET total_files=?, supported_files=?, unsupported_files=?, total_size_bytes=?, audio_count=?, video_count=?, audio_size_bytes=?, video_size_bytes=?` (aggregate counts from in-memory tallies accumulated during the iteration)
8. `UPDATE catalog_scans SET status='complete', completed_at=NOW()`
9. Compute three derived values on the fly (not stored in `catalog_scans`):
   - `estimated_audio_minutes` = `round(audio_size_bytes / (192 * 125 * 60))` — assumes 192 kbps average bitrate (matches design notes; 192 × 125 = 24,000 bytes/sec)
   - `estimated_video_minutes` = `round(video_size_bytes / (4000 * 125 * 60))` — assumes 4,000 kbps average bitrate (matches design notes; 4000 × 125 = 500,000 bytes/sec)
   - `estimated_ai_cost_usd` = `round(video_count * 0.046, 2)` — proxy cost per video clip at current GPT-4o pricing; update constant as pricing changes
10. Return JSON summary (stored aggregates + three computed estimates) + `duration_ms`

**Implementation notes:**
- Wrap steps 4–8 in a `try/catch`; on exception set `status = 'failed'` and return error JSON — prevents scans from being stuck in `'running'` permanently.
- Use batched INSERTs (e.g., 200 rows per query) rather than one INSERT per file for large arrays.
- Static ext→MIME lookup can delegate to `src/Config/MediaTypes.php` (`MediaTypes::audioExts()` / `MediaTypes::videoExts()`) rather than maintaining a separate lookup table.
- No `set_time_limit(0)` needed — no filesystem walk occurs; iteration over a JSON array is fast even at 50,000 entries.
- No `realpath()`, no symlink checks, no webroot guard — server never touches the filesystem for file discovery.

---

#### 3. `admin/catalog_entries_list.php` — GET endpoint

**Request:** `GET catalog_entries_list.php?scan_id=X&page=1&limit=100&file_type=&status=&is_supported=`

**Response:** Paginated `catalog_entries` rows for the given `scan_id` plus the parent `catalog_scans` summary row. Used by `admin_database_catalog_media_from_folder.php` to render the post-scan file table. `db/database_catalog.php` queries `catalog_entries` directly via PDO rather than through this endpoint.

**Section B scope caveat:** For `mode=add` scans, `scan_id` is only assigned to newly inserted entries — pre-existing entries retain their original `scan_id` unchanged (per the `ON DUPLICATE KEY UPDATE` logic). Querying by `scan_id` therefore returns only the delta (files newly added since the last scan), while the parent `catalog_scans` aggregate columns (`total_files`, `audio_count`, etc.) reflect the full current directory. This is intentional: the aggregate shows the current state of the directory; the entry list shows what changed. The post-scan summary card in `admin_database_catalog_media_from_folder.php` should make this distinction explicit when displaying Section B results.

---

#### 4. `db/database_catalog.php` — Browse / Edit / Delete UI page

Mirrors the style of `db/database.php` (scrollable row list, inline edit, row delete). Provides the operator review step between scan and promote — without this page there is no way to drive the `status` state machine or assign per-entry metadata before import.

The default view shows **all `catalog_entries` across all scans** — the operator's mental model is a single unified catalog, not per-scan buckets. The `scan_id` is an internal tracking detail; the user does not need to think about which scan a file came from.

**Filters (top of page):**
- `status` filter — all / cataloged / selected / skipped / imported / failed
- `file_type` filter — all / audio / video / unknown
- `is_supported` filter — all / supported / unsupported

**Per-row display (read-only):**

| Column | Notes |
|---|---|
| `file_name` | Display name |
| `source_relpath` | Full relative path |
| `file_type` + `is_supported` | Badge (audio/video/unsupported) |
| `mime_type` | |
| `size_bytes` | Formatted via `formatBytes()` |
| `file_mtime` | Last modified on disk |
| `first_seen_scan_id` / `last_seen_scan_id` | Orphan detection — highlight if `last_seen_scan_id` is stale relative to the most recent scan of that `source_root` |

**Per-row editable fields:**

| Column | Input type |
|---|---|
| `status` | Dropdown: cataloged / selected / skipped |
| `org_name` | Text |
| `event_date` | Date |
| `event_type` | Dropdown: band / wedding / other |
| `location` | Text |
| `label` | Text |
| `item_type` | Dropdown: song / loop / clip / highlight (NULL = auto) |
| `keywords` | Text |
| `summary` | Text (promotes to `events.summary`) |
| `participants` | Text (comma-separated) |
| `notes` | Textarea |

**Typical operator workflow (example: musician correcting event dates before upload):**

1. Scan the NAS folder via `admin_database_catalog_media_from_folder.php` → `catalog_entries` rows created with `status = 'cataloged'`
2. Open `db/database_catalog.php` — browse the full entry list
3. Edit `event_date` (and any other metadata) per row → saved to `catalog_entries` via `db/catalog_entry_save.php`
4. Mark desired entries as `status = 'selected'`; mark unwanted entries as `status = 'skipped'`
5. Phase 2 promote step (see `docs/refactor_catalog_upload_using_catalog.md`) picks up `selected` entries and uses the corrected `event_date` as `events.event_date` at ingest time — no re-entry of metadata needed

This is the gap the existing `admin_database_load_import_media_from_folder.php` does not fill: it has no pre-upload metadata review or correction step.

**Nav buttons on this page:** **Catalog Folder** link back to `admin/admin_database_catalog_media_from_folder.php` (so the operator can trigger a new scan without using the browser back button).

**Row-level actions:**
- Save inline edits (POST to `db/catalog_entry_save.php`)
- Delete row (`DELETE FROM catalog_entries WHERE catalog_entry_id = ?`)

**Bulk actions (header row):**
- Mark all visible rows as `selected` — only applies to rows where `is_supported = 1`; silently skips `is_supported = 0` rows. Intended as a "select all, then deselect unwanted" starting point.
- Mark all visible rows as `skipped`

**Page footer summary:** count of selected / skipped / total rows across the entire `catalog_entries` table (not scoped to a single scan) and total `size_bytes` of selected entries.

**Backend endpoint:** `db/catalog_entry_save.php` — see file 5 below.

---

#### 5. `db/catalog_entry_save.php` — Save endpoint

Follows the same pattern as `db/database_edit_save.php`. Admin-only POST endpoint called by `db/database_catalog.php` for both inline field edits and row deletes.

**Request** (POST body, `application/x-www-form-urlencoded` or JSON):
- `catalog_entry_id` — required
- `action` — `save` or `delete`
- Any subset of editable fields: `status`, `org_name`, `event_date`, `event_type`, `location`, `label`, `item_type`, `keywords`, `summary`, `participants`, `notes`

**Guards:**
- Reject `status = 'selected'` if `is_supported = 0` for the target entry (server-side enforce of promote restriction)
- Reject `status = 'imported'` or `status = 'failed'` — those are pipeline-set values, not operator-settable

**Response:** `{"success": true}` or `{"success": false, "error": "..."}`.

---

### Changed Files (3)

#### Navigation link to add

The following **Catalog Folder** button must be added to the top-right nav panel in each of the three existing files below. The nav button for `admin_database_catalog_media_from_folder.php` pointing back to the review page is part of that new file's implementation (see file 1 above).

```html
<a href="/admin/admin_database_catalog_media_from_folder.php">
  <button type="button" style="border-color:#a855f7;font-size:.8rem;padding:.4rem .8rem">Catalog Folder</button>
</a>
```

#### Files requiring the nav link addition

| File | Current nav buttons present |
|---|---|
| `admin/admin_database_load_import_media_from_folder.php` | Password Reset, System & Recovery, CSV Import |
| `admin/admin_system.php` | Password Reset, Import Media, CSV Import |
| `admin/admin_database_load_import_csv.php` | Password Reset, Import Media, System & Recovery |

---

## Known Limitations / Future Review Items

### Catalog scan uses synchronous HTTP response, not database polling

`catalog_scan_start.php` processes all file metadata synchronously and returns a single JSON response when complete. The JavaScript `runScan()` function awaits this HTTP response and renders the result card from the returned JSON.

This is intentionally different from `admin_database_load_import_media_from_folder.php`, which uses database-polling because TUS uploads are async and long-running (minutes to hours). The catalog scan writes metadata only — no file uploads — and completes in under 10 seconds for typical collections (~8 000 files in ~7 s observed). A synchronous request/response is appropriate and sufficient.

The `catalog_scans` table already tracks `status = 'running'` → `status = 'complete'` in the DB during the scan. If polling were ever needed (e.g. a future background-queue approach), the status column is already in place.

**To revisit if:** scans regularly exceed 30 seconds (PHP `max_execution_time` limit) or if a poll-based progress bar during large scans becomes a UX requirement.

---

### PHP `max_execution_time` risk for large catalogs

`catalog_scan_start.php` has no `set_time_limit()` call (removed during the `scandir` refactor). PHP's default `max_execution_time` is typically 30 seconds. Batch INSERT of 50 000 files (250 batches × 200 rows) at ~50 ms per query ≈ 12–15 seconds under normal load, which fits within the default. However, under DB contention or on slow storage, this margin can disappear.

**Mitigations in priority order:**
1. Add `set_time_limit(120)` at the top of `catalog_scan_start.php` as a conservative guard.
2. If scans regularly approach the limit, consider a poll-based architecture (emit `scan_id` immediately, process in background, JS polls `catalog_scans.status`).
3. The 50 000 file client-side guard (`MAX_FILES`) is already enforced; do not raise it without also addressing the timeout risk.

---

### Browser folder-picker dialog language

When the user clicks **Choose Folder**, the browser shows a native OS-level dialog ("Upload X files to this site?") before JavaScript receives control. This dialog text is hardcoded by the browser engine and **cannot be customised** by HTML, CSS, or JavaScript — it fires before any JS hook executes. Attempting to prepend a custom `confirm()` call would result in two dialogs in sequence (worse UX).

This affects all `<input type="file" webkitdirectory>` usage across browsers (Chrome, Firefox, Safari each show their own variant). The import media page (`admin_database_load_import_media_from_folder.php`) has the same limitation.

**To revisit if:** a future browser API exposes a pre-picker hook, or if switching to the [File System Access API](https://developer.mozilla.org/en-US/docs/Web/API/File_System_Access_API) (`showDirectoryPicker()`) becomes viable — that API allows a fully custom JS flow without the browser upload dialog, but has different browser support and security model considerations.

---

## Browser-Side FileList Rewrite — Implementation Notes

This section captures all implementation constraints identified during design review, before coding begins. Derived from a cross-check of this plan against `admin_database_load_import_media_from_folder.php` (the model).

### Files Under Change

| File | Change Type | Summary |
|---|---|---|
| `admin/admin_database_catalog_media_from_folder.php` | Major rewrite of Sections A + B | Replace server-path text inputs with `webkitdirectory` file picker; rewrite all related JS |
| `admin/catalog_scan_start.php` | Major rewrite | Remove `scandir()` walk entirely; accept browser-provided `files` JSON array |

`admin/catalog_entries_list.php`, `db/catalog_entry_save.php`, `db/database_catalog.php` — **no changes required**.

### Implementation Checklist

**HTML — both Sections A and B:**

1. File input must carry all three attributes: `webkitdirectory directory multiple style="display:none"` — not just `webkitdirectory`
2. Two separate elements after the Choose Folder button — matching the model exactly:
   - `<span id="a-folder-chosen">` — folder name + total file count only (e.g., `2024-recordings (1 234 files)`)
   - `<div id="a-preview">` — detailed breakdown (total / audio / video / unsupported counts + size) rendered by `buildScanState()` + `renderScanPreview()` equivalent
3. Remove `id="a-path"` / `id="b-path"` text inputs entirely; no `for="a-path"` label references remain

**JavaScript — `onchange` handler:**

4. `canRun` condition must be `list.length > 0` — **not** `supportedCount > 0`. The import page uses `supportedCount > 0` because it only hashes/uploads supported files. Catalog records all files including unsupported ones (`is_supported = 0`). Using `supportedCount > 0` would block a valid catalog operation on a folder containing only `.vob` or `.m2v` files.
5. All existing `el(sec + '-path')` references must be removed; replaced with `el(sec + '-folder')`
6. `_S` state object simplified to `{ mode, folderKey, scanState }` — no upload / trace / abort / jobId fields needed

**JavaScript — scan function:**

7. Section A must gate behind `confirm()` before POSTing — same pattern as import page — since it is destructive (deletes all prior `catalog_entries` for this `source_root`)
8. `files` array built as: `Array.from(inp.files).map(f => ({ relpath: f.webkitRelativePath || f.name, size_bytes: f.size, last_modified_ms: f.lastModified }))`
9. Scan button re-enable in `finally`: `el(sec + '-scan-btn').disabled = !(el(sec + '-folder').files && el(sec + '-folder').files.length > 0)`

**PHP — `catalog_scan_start.php`:**

10. Guards enforced in this order before any DB writes: `files` array present and non-empty → count ≤ 50 000 → `mode` is `reload` or `add` → every `relpath` contains at least one `/`
11. `source_root` = `explode('/', $files[0]['relpath'], 2)[0]` — no `realpath()`, no filesystem check, no webroot guard

---

## Feature: Total Catalog Stats Viewport (Section B — Add to Catalog)

### Rationale

After running an **Add to Catalog** scan, the result card shows stats for the just-scanned folder only. The operator has no immediate visibility into how that scan changed the overall catalog. Adding a second "Total Catalog Stats" card below the scan result gives instant context — total file count, total size, audio/video breakdown, and estimated AI cost across the entire `catalog_entries` table — without leaving the page.

This card is **Add-only** by design. After a Reload scan, showing total catalog stats would be redundant: reload first `DELETE CASCADE`s all prior `catalog_scans` (and their `catalog_entries`) for that `source_root`, then inserts fresh entries — the scan result card already shows the complete, accurate picture for the just-reloaded folder. `INSERT IGNORE` is used during the batch insert only to handle within-batch `path_hash` collisions from the current browser FileList submission (not to preserve old entries). After an Add scan the catalog accumulates across multiple source folders and scans, so the delta between scan card and total card is meaningful and trustworthy.

### Files Touched

| File | Change Type | Summary |
|---|---|---|
| `admin/catalog_stats.php` | **New file** | Admin-only JSON endpoint; single aggregate query across all `catalog_entries`; computes derived fields in PHP |
| `admin/admin_database_catalog_media_from_folder.php` | JS + HTML change | After add scan succeeds, fetch `/admin/catalog_stats.php`, render second card into new `<div id="add-total-result">` |

No schema changes. No group_var changes. No new environment variables.

### New Endpoint: `admin/catalog_stats.php`

**Auth:** Same `$user !== 'admin'` guard as all other admin endpoints. Returns HTTP 403 if not admin.

**Query:**
```sql
SELECT
  COUNT(*)                                                                      AS total_files,
  COALESCE(SUM(is_supported), 0)                                               AS supported_files,
  COALESCE(SUM(1 - is_supported), 0)                                           AS unsupported_files,
  COALESCE(SUM(COALESCE(size_bytes, 0)), 0)                                    AS total_size_bytes,
  COALESCE(SUM(CASE WHEN file_type = 'audio' THEN 1 ELSE 0 END), 0)           AS audio_count,
  COALESCE(SUM(CASE WHEN file_type = 'video' THEN 1 ELSE 0 END), 0)           AS video_count,
  COALESCE(SUM(CASE WHEN file_type = 'audio' THEN COALESCE(size_bytes, 0) ELSE 0 END), 0) AS audio_size_bytes,
  COALESCE(SUM(CASE WHEN file_type = 'video' THEN COALESCE(size_bytes, 0) ELSE 0 END), 0) AS video_size_bytes
FROM catalog_entries
```

`is_supported` is `TINYINT(1) NOT NULL DEFAULT 0`. Outer `COALESCE(..., 0)` wraps every `SUM()` so that an empty table returns zeros rather than `NULL` — without this, PHP arithmetic on `NULL` produces silent `0` only by accident.

**PHP derives these fields** (same formulas as `catalog_scan_start.php`):
```php
$estAudioMin = (int)round($audioSizeBytes / (192  * 125 * 60));
$estVideoMin = (int)round($videoSizeBytes / (4000 * 125 * 60));
$estAiCost   = round($videoCount * 0.046, 2);
```

**Response shape** (matches the `summary` sub-object returned by `catalog_scan_start.php`):
```json
{
  "success": true,
  "summary": {
    "total_files": N,
    "supported_files": N,
    "unsupported_files": N,
    "total_size_bytes": N,
    "audio_count": N,
    "video_count": N,
    "audio_size_bytes": N,
    "video_size_bytes": N,
    "estimated_audio_minutes": N,
    "estimated_video_minutes": N,
    "estimated_ai_cost_usd": N
  }
}
```

### Frontend Changes

**HTML** — add below `<div id="add-result">` in Section B:
```html
<div id="add-total-result"></div>
```

**JavaScript** — in `runScan('add')` success path, after rendering the scan card:
```js
try {
  const statsRes = await fetch('/admin/catalog_stats.php');
  if (!statsRes.ok) throw new Error('HTTP ' + statsRes.status);
  const statsData = await statsRes.json();
  if (statsData.success) {
    html('add-total-result', renderTotalStats(statsData));
  }
} catch (e) {
  html('add-total-result', '<p class="muted" style="margin:.5rem 0">Could not load total catalog stats.</p>');
}
```

**`renderTotalStats(data)`** — a new function using the same `.summary-card` / `.summary-grid` / `.stat-box` markup as `renderSummary()`, but:
- Title: **"Total Catalog Stats"** (no scan # or mode label)
- No ignored/skipped box (that is scan-specific)
- No "Note: for Add to Catalog scans…" footnote
- "Open Catalog Media →" link retained (useful for navigation to the full list)

### Known Limitations

- **Reload mode**: Total Catalog Stats is intentionally not shown after a Reload scan. Reload `DELETE CASCADE`s all prior entries for the `source_root` and inserts fresh ones — the scan result card already shows the complete, accurate picture. `INSERT IGNORE` in reload mode only guards against within-batch `path_hash` collisions in the current browser FileList (e.g. two files that collide under `utf8mb4_unicode_ci`); it does not preserve stale entries.
- **Performance**: The aggregate query is a full-table scan with no `WHERE` clause. For catalogs of ~50 000 rows (the enforced maximum per scan), this is fast (~1–5 ms). No index changes are needed.
- **Stale data**: The total stats card reflects the DB state at the moment the scan response is received. If two browser sessions scan concurrently, one session's total card may undercount by the concurrent session's inserts. Acceptable for an admin-only tool.
