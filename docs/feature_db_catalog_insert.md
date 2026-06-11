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

- **`summary`** — event description; promoted to `events.summary` at ingest time. Follows `UploadService` convention where the `notes` POST parameter maps to `events.summary`.
- **`notes`** — operator-only annotation on the catalog entry itself (e.g., "client hasn't approved", "skip — duplicate recording"). **Not promoted to any downstream table.** Catalog lifecycle only.

### `path_hash`

SHA-256 of `(source_root + '/' + source_relpath)`, computed in PHP at scan time with no file I/O. Globally unique per physical file path. Powers:
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
| `admin/catalog_scan_start.php` | POST endpoint — runs the scan, returns JSON summary |
| `admin/catalog_entries_list.php` | GET endpoint — paginated entries for the scan trigger UI |
| `db/database_catalog.php` | Browse / edit / delete UI for catalog entries |
| `db/catalog_entry_save.php` | POST endpoint — saves field edits and row deletes |

### Admin Page

**`admin_database_catalog_media_from_folder.php`**

Mirrors the structure of `admin_database_load_import_media_from_folder.php` with two sections.

### Section A — Catalog Folder (Destructive)

- Clears all `catalog_entries` for the given `source_root` (via cascade from deleted `catalog_scans` rows)
- Creates a fresh `catalog_scans` row (`status = 'running'`)
- Server-side `scandir` + `stat()` — no file upload, no TUS, no hashing
- Populates `catalog_entries` from scratch; computes `path_hash` per entry
- Updates scan aggregate columns (`total_files`, `total_size_bytes`, etc.) on completion
- Sets `scan.status = 'complete'`

### Section B — Add to Catalog (Non-Destructive)

- Scans the same folder
- For each file: computes `path_hash`
  - If `path_hash` not in `catalog_entries`: INSERT new entry with `first_seen_scan_id = last_seen_scan_id = current scan_id`
  - If `path_hash` exists: UPDATE `last_seen_scan_id = current scan_id`
- Entries in DB whose `last_seen_scan_id` was not updated = file no longer on disk (orphan detection, use case #9)

### Key Differences from Folder Import Page

| Aspect | `admin_database_load_import_media_from_folder.php` | `admin_database_catalog_media_from_folder.php` |
|---|---|---|
| File selection | Browser file picker (`webkitdirectory`) | Operator types server-side path |
| File transfer | TUS chunked upload | None — server-side `scandir`/`stat()` only |
| Hashing | SHA-256 per file (browser-side) | None |
| Media probing | Via `ingestComplete()` | None |
| Steps | Step 1 (manifest) → Step 2 (upload) → Step 3 (finalize) | Single-step scan |
| Output | Assets in `assets` + `event_items` | Entries in `catalog_scans` + `catalog_entries` only |
| Downstream tables touched | `assets`, `events`, `event_items`, `upload_jobs`, `upload_job_files` | `catalog_scans`, `catalog_entries` only |

### Path Access

The server-side path must be mounted into the Apache container. For Phase 1, valid scan roots are paths already accessible to the PHP process (e.g., bind-mounted `/srv/audio`, `/srv/video`, or additional NAS mounts). Application-level path validation guards against directory traversal.

### Phase 1 Boundary

Phase 1 ends at **catalog + review**. The operator can scan folders, browse entries in `db/database_catalog.php`, edit metadata fields, and mark entries as `status = 'selected'` or `status = 'skipped'`. No upload, no hashing, no `assets` table writes occur in Phase 1.

The subsequent **promote step** — taking `status = 'selected'` entries through hash → TUS upload → `ingestComplete()` → `assets` INSERT — is Phase 2, documented separately in `docs/refactor_upload_using_catalog.md`. Phase 1 must be fully implemented and stable before Phase 2 begins.

---

## Design Decisions Log

| Decision | Choice Made | Rationale |
|---|---|---|
| Catalog vs. extending `assets` | Separate `catalog_scans` + `catalog_entries` tables | `assets.checksum_sha256` is NOT NULL UNIQUE; no checksum = can't fit cleanly in `assets` without corrupting the dedup invariant |
| Primary dedup key | `UNIQUE(path_hash)` — SHA-256 of `(source_root + '/' + source_relpath)` | Prevents duplicate catalog entries across re-scans; ensures clean 1:1 mapping to upload job files at promote time. `UNIQUE(scan_id, source_relpath(512))` added as secondary within-scan guard |
| `duration_seconds` | Omitted; proxy via `size_bytes` ÷ avg bitrate | Duration requires ffprobe; violates no-media-processing constraint. Proxy is sufficient for import time estimation (#2) and AI cost preview (#14) |
| `item_type` NULL behavior | NULL = auto-derive (wedding→clip, else→song) | Consistent with existing `UploadService` logic; explicit value overrides for highlight/loop use cases |
| `summary` vs `notes` | Both present with distinct semantics | `summary` → `events.summary` at promote time; `notes` = operator annotation, catalog lifecycle only, never promoted |
| `path_hash` nullability | `NOT NULL` | Enforces uniqueness constraint; computed in PHP from path strings with zero file I/O |
| Per-entry vs scan-level event columns | Both, with entry overriding scan | Scan provides defaults for bulk operation; per-entry override supports multi-event scans (e.g., a NAS with folders for multiple events) |

---

## Database Migration (Existing Installations)

The tables were added to `create_music_db.sql` and will be present on any fresh install. For an existing `music_db` that is already running, apply the statements below manually. No existing tables are modified.

```sql
-- Run against music_db on the target host.
-- Drop order matters for the rollback; entries must be dropped before scans.
-- Rollback: DROP TABLE IF EXISTS catalog_entries; DROP TABLE IF EXISTS catalog_scans;

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

## Phase 1 Implementation Plan

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
| File selection | `webkitdirectory` browser picker | Operator types server-side path (text input) |
| `tus-js-client` | Required | Not needed |
| `import_progress.css/js` | Required | Not needed |
| SHA-256 Web Worker | Required | Not needed |
| IndexedDB hash cache | Required | Not needed |
| Duplicate resolution modal | Required | Not needed |
| Step 2 / Step 3 upload panel | Required | Not needed |
| JS scan flow | hash → prepare → finalize → poll → upload | POST `catalog_scan_start.php` → render summary |

Reused from the import page: same CSS card/badge/alert styles, `formatBytes()`, `escapeHtml()`, `el()`, `html()`, `UPLOAD_AUDIO_EXTS_JSON` / `UPLOAD_VIDEO_EXTS_JSON` env var read, `_S` state object pattern.

**Section A** — Catalog Folder (Destructive): clears existing `catalog_entries` for the `source_root` then scans fresh.

**Section B** — Add to Catalog (Non-Destructive): scans and upserts via `path_hash`; existing entries get `last_seen_scan_id` updated.

Both sections accept optional metadata fields: `scan_label`, `org_name`, `event_date`, `event_type`, `location`, `keywords`, `summary`, `notes`.

After a successful scan the page renders a summary card showing: total / supported / unsupported file counts, total size, audio/video breakdown, estimated import time, and estimated AI tagging cost (proxy formula).

Nav buttons on this page: existing admin nav buttons (Password Reset, etc.) + **Catalog Review** link to `db/database_catalog.php`.

---

#### 2. `admin/catalog_scan_start.php` — POST endpoint

**Request** (JSON body):
```json
{
  "source_root": "/srv/audio",
  "mode": "reload",
  "scan_label": null,
  "org_name": null,
  "event_date": null,
  "event_type": null,
  "location": null,
  "keywords": null,
  "summary": null,
  "notes": null
}
```

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
    "total_size_bytes": 5368709120,
    "audio_count": 80,
    "video_count": 62,
    "audio_size_bytes": 1073741824,
    "video_size_bytes": 4294967296,
    "estimated_audio_minutes": 746,
    "estimated_video_minutes": 143,
    "estimated_ai_cost_usd": 2.86
  },
  "duration_ms": 450
}
```

**Server-side logic:**
1. Auth check (admin only)
2. Normalize `source_root`: `$source_root = rtrim($source_root, '/')` — must be done before validation, hashing, and storage so that `/srv/audio` and `/srv/audio/` produce identical path_hashes across scans. Then validate: non-empty, absolute path, exists on disk, readable by PHP process; reject directory traversal attempts
3. If `mode === 'reload'`: `DELETE FROM catalog_scans WHERE source_root = ?` (cascades to `catalog_entries`)
4. `INSERT INTO catalog_scans` (`status = 'running'`)
5. Recursive `scandir()` walk; for each file:
   - `pathinfo()` → `file_name`, `file_ext`
   - Infer `file_type` + `is_supported` from ext against `UPLOAD_AUDIO_EXTS_JSON` / `UPLOAD_VIDEO_EXTS_JSON`
   - Static ext→MIME lookup (no probing)
   - `stat()` → `size_bytes`, `file_mtime`
   - `source_relpath` = path relative to `source_root`
   - `path_hash = hash('sha256', $source_root . '/' . $source_relpath)` (uses already-normalized `$source_root` from step 2)
   - If `mode === 'reload'`: `INSERT` with `scan_id`, `first_seen_scan_id`, and `last_seen_scan_id` all set to the current `scan_id`
   - If `mode === 'add'`: `INSERT` (same columns) `ON DUPLICATE KEY UPDATE last_seen_scan_id = current_scan_id` — `scan_id` and `first_seen_scan_id` are left unchanged on conflict, preserving the original scan reference
6. `UPDATE catalog_scans SET total_files=?, supported_files=?, unsupported_files=?, total_size_bytes=?, audio_count=?, video_count=?, audio_size_bytes=?, video_size_bytes=?` (aggregate counts from in-memory tallies accumulated during the walk)
7. `UPDATE catalog_scans SET status='complete', completed_at=NOW()`
8. Compute three derived values on the fly (not stored in `catalog_scans`):
   - `estimated_audio_minutes` = `round(audio_size_bytes / (192 * 125 * 60))` — assumes 192 kbps average bitrate (matches design notes; 192 × 125 = 24,000 bytes/sec)
   - `estimated_video_minutes` = `round(video_size_bytes / (4000 * 125 * 60))` — assumes 4,000 kbps average bitrate (matches design notes; 4000 × 125 = 500,000 bytes/sec)
   - `estimated_ai_cost_usd` = `round(video_count * 0.046, 2)` — proxy cost per video clip at current GPT-4o pricing; update constant as pricing changes
9. Return JSON summary (stored aggregates + three computed estimates) + `duration_ms`

**Implementation notes:**
- Call `set_time_limit(0)` at the top of the scan — a large NAS mount will exceed PHP's default 30-second limit.
- Skip symlinks during the `scandir()` walk (`is_link($path)`) to prevent infinite recursion on circular symlink graphs.
- Wrap steps 3–7 in a `try/catch`; on exception set `status = 'failed'` and rethrow/log — prevents scans from being stuck in `'running'` permanently.
- Use batched INSERTs (e.g., 200 rows per query) rather than one INSERT per file for large directories.
- Path traversal guard: resolve `source_root` with `realpath()` and reject if it returns false (path does not exist or is not accessible). For Phase 1, the Apache container's bind-mount topology limits accessible paths; a sufficient guard is asserting `realpath()` succeeds and the resolved path does not begin with the webroot (e.g., `/var/www/html`). A configurable `CATALOG_ALLOWED_ROOTS_JSON` env var can be added if stricter whitelisting is needed.
- Static ext→MIME lookup can delegate to `src/Config/MediaTypes.php` (`MediaTypes::audioExts()` / `MediaTypes::videoExts()`) rather than maintaining a separate lookup table.
- Skip non-file filesystem entries explicitly: `is_file($path)` before processing; skip `.` and `..`.

---

#### 3. `admin/catalog_entries_list.php` — GET endpoint

**Request:** `GET catalog_entries_list.php?scan_id=X&page=1&limit=100&file_type=&status=&is_supported=`

**Response:** Paginated `catalog_entries` rows for the given `scan_id` plus the parent `catalog_scans` summary row. Used by `admin_database_catalog_media_from_folder.php` to render the post-scan file table. `db/database_catalog.php` queries `catalog_entries` directly via PDO rather than through this endpoint.

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
5. Phase 2 promote step (see `docs/refactor_upload_using_catalog.md`) picks up `selected` entries and uses the corrected `event_date` as `events.event_date` at ingest time — no re-entry of metadata needed

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
  <button type="button" style="border-color:#3b82f6;font-size:.8rem;padding:.4rem .8rem">Catalog Folder</button>
</a>
```

#### Files requiring the nav link addition

| File | Current nav buttons present |
|---|---|
| `admin/admin_database_load_import_media_from_folder.php` | Password Reset, System & Recovery, CSV Import |
| `admin/admin_system.php` | Password Reset, Import Media, CSV Import |
| `admin/admin_database_load_import_csv.php` | Password Reset, Import Media, System & Recovery |
