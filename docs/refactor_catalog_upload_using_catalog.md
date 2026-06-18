# Refactor: Streamline Upload/Ingest Using Catalog Tables

## Status

Ready — Phase 1 (`docs/feature_completed_catalog_insert.md`) is complete and stable as of June 2026.

---

## Overview

The catalog feature is a multi-source aggregation pre-step upstream of the existing import pipeline. The operator builds an inventory across one or more source directories — without hashing, uploading, or touching any media bytes — then culls the combined list and promotes the selection into the existing browser-driven TUS flow with metadata pre-populated.

Workflow:

1. **Section A — Full-reset scan** of the first source directory — wipes the **entire** catalog (all `catalog_scans` + `catalog_entries`), then populates fresh from the selected folder
2. **Section B — Additive scan** of additional source directories — entries appended without touching existing ones
3. **Review and cull** in `db/database_catalog.php` — use search and filters to find the desired entries; check rows individually or use select-all + bulk Set Status to mark them `selected`; uncheck and bulk-skip unwanted entries; or build up the selection row by row
4. **Promote** — server generates a manifest from `WHERE status = 'selected'`
5. **Browser TUS upload** — existing flow, driven by the catalog manifest; only selected files are hashed and uploaded

---

## Assumptions

This is a browser-driven process. At promote time, the operator's local machine must have all scanned source folders accessible and mounted. Files on drives that are unplugged or network shares that are offline cannot be uploaded. Since a catalog can be assembled from multiple folder scans, the user will have to select each folder when it is time for the catalog to be uploaded. This is a limitation with web browser security models.

For the catalog's **ephemerality rationale**, Section A vs Section B design intent, auto-populated defaults, and post-promote wipe plan, see `docs/feature_completed_catalog_insert.md` → *Catalog Lifecycle and Ephemerality*.

---

## Plain-Language Walkthrough

What happens from catalog scan to files appearing in the media library, step by step:

- **Step 1 — Window-shop, don't buy:** You point the catalog at a folder on your local machine (or a network drive mounted on it). It reads only filenames and sizes — like reading a menu — without opening, hashing, or uploading anything. Use **Section A** to wipe any prior staging work and start fresh with this folder; use **Section B** to accumulate entries from an additional folder without clearing what is already there. → `admin/admin_database_catalog_media_from_folder.php` (UI) + `admin/catalog_scan_start.php` (endpoint)

- **Step 2 — Pick what you want:** You review the catalog list, fill in event details (name, date, type), and check off only the files you actually want to upload. → `db/database_catalog.php` (UI) + `db/catalog_entry_save.php` (save endpoint)

- **Step 3 — Go to the upload page:** You click "Promote Selected (N files) to be Uploaded." The browser navigates to the dedicated promote UI page, which immediately asks the server to build a manifest — a structured list of your selected files with all their event metadata already attached, inherited from the catalog. → button on `db/database_catalog.php` navigates to `admin/admin_database_catalog_promote.php` *(new)*, which calls `admin/catalog_promote_start.php` *(new)*

- **Step 4 — Grant access — pick each source folder to authorize upload:** The browser cannot retain access to local files between page navigations, so the promote UI prompts you to pick each source folder in sequence — one picker per distinct source root. It tells you exactly which folder name to pick for each one. → `admin/admin_database_catalog_promote.php` (same page, now presenting the folder picker)

- **Step 5 — Fingerprint each file:** The browser computes a SHA-256 checksum for each selected file using a background Web Worker. Checksums are cached in IndexedDB so re-runs skip already-hashed files. → browser-side JS only (SHA-256 Web Worker; same as existing import page)

- **Step 6 — Register the job:** The fingerprinted file list is posted to the server, which creates an upload job record and confirms it is ready to receive files. → `admin/import_manifest_prepare.php` + `admin/import_manifest_finalize.php` *(existing, reused unchanged)*

- **Step 7 — Wait for the server to get ready:** The browser polls until the server signals the job is ready for upload. → polls `admin/import_manifest_status.php` *(existing, reused unchanged)*

- **Step 8 — Upload:** Files are transferred in chunks via TUS. If your connection drops, the upload resumes from where it stopped. After each file lands, the server processes it (ffprobe, DB insert). → `admin/import_manifest_upload_start.php` + TUS + `admin/import_manifest_upload_finalize.php` per file → `ingestComplete()` *(all existing, reused unchanged)*

- **Step 9 — Mark as imported:** For each uploaded file, the promote UI calls the write-back endpoint with the file's catalog fingerprint. The server resolves the new asset ID and stamps the catalog entry as `status = 'imported'`. → `admin/catalog_promote_writeback.php` *(new)*

- **Step 10 — Done:** The browser polls for overall upload completion, then shows a link to the media library. Your selected files appear with the event details you pre-filled — no re-entry needed. → polls `admin/import_manifest_upload_status.php` *(existing, reused unchanged)*

---

## Operator UX Flow

The entry points and navigation between pages must guide the operator through the correct sequence without bypassing the review step:

```
admin_database_catalog_media_from_folder.php
  Scan completes → result card rendered
  → "Review Catalog Entries →" link to db/database_catalog.php
    (entries are status='cataloged' at this point — nothing is selected yet)

db/database_catalog.php
  Operator reviews entries, edits metadata, marks status='selected' or 'skipped'
  Footer shows: selected count + total size_bytes of selected entries
  → "Promote Selected (N files) to be Uploaded →" button — links to admin_database_catalog_promote.php
    (button displays live selected count so operator knows the commitment before clicking;
     button is disabled / hidden when selected count = 0)

admin_database_catalog_promote.php
  Focused promote flow: folder picker(s) → TUS upload → write-back
  → "Back to Catalog Review" link to db/database_catalog.php
```

**The promote prompt belongs on `db/database_catalog.php`**, not on the scan page. At scan-completion time all entries are `status='cataloged'`; a "ready to upload?" prompt there would send the operator to a promote UI with zero selected files. The value of the catalog is the review/cull step — the upload prompt fires only after the operator has finished selecting.

---

## User Journeys: Flow 1 vs Flow 2 — What Each Phase Does

### User Flow 1 — Catalog and Review (complete)

User Flow 1 is purely a **metadata operation**. No files are hashed, transferred, or written to the `assets` table. Everything happens in the browser's FileList reader and the `catalog_scans` / `catalog_entries` tables.

| What the operator can do in User Flow 1 | What User Flow 1 does NOT do |
|---|---|
| Scan one or more folders — records filenames, sizes, extensions | Hash any file bytes |
| Browse all catalog entries across all scans | Upload any files |
| Edit per-entry metadata (event name, date, type, label, etc.) | Touch `assets`, `events`, or `event_items` |
| Mark entries `selected` or `skipped` | Trigger `ingestComplete()` |
| Delete individual catalog entries | |
| View total catalog stats (file count, size, estimated AI cost) | |

User Flow 1 ends when the operator has reviewed the catalog and marked entries as `status = 'selected'`. Nothing irreversible has happened.

### User Flow 2 — Promote and Upload (this document)

User Flow 2 picks up where User Flow 1 left off. It takes the `status = 'selected'` entries and drives them through the **existing** browser-based TUS upload pipeline — the same one used by `admin_database_load_import_media_from_folder.php` — with catalog metadata pre-populated so the operator does not re-enter event details.

| What User Flow 2 adds | Existing pipeline touched |
|---|---|
| `catalog_promote_start.php` — build manifest from selected entries | `import_manifest_prepare.php` — unchanged |
| `admin_database_catalog_promote.php` — folder picker(s) + TUS UI | `import_manifest_finalize.php` — unchanged |
| `catalog_promote_writeback.php` — stamp `status = 'imported'` + `asset_id` | `import_manifest_status.php` — unchanged |
| "Promote Selected" button on `db/database_catalog.php` | `import_manifest_upload_start.php` — unchanged |
| | `import_manifest_upload_finalize.php` / `ingestComplete()` — unchanged |
| | `import_manifest_upload_status.php` — unchanged |

**The dividing line:** User Flow 1 never writes to `assets`. User Flow 2 does — via the unchanged `ingestComplete()` path — and then writes back to `catalog_entries` to link the catalog record to the new asset.

---

## Wireframe of Promotion UI

### `db/database_catalog.php` — Catalog Review Page

```
┌─────────────────────────────────────────────────────────────────┐
│  GigHive — Catalog                                              │
├─────────────────────────────────────────────────────────────────┤
│  [Scan new folder]                                              │
│                                                                 │
│  Filters: Status ▾  File Type ▾  Event Date ▾  [Search...]     │
│                                                                 │
│  ┌──┬──────────────────┬──────┬────────────┬────────┬────────┐ │
│  │☑ │ File             │ Type │ Event      │ Status │ Action │ │
│  ├──┼──────────────────┼──────┼────────────┼────────┼────────┤ │
│  │☑ │ gig-opener.mp4   │ video│ Blues Co…  │selected│ Edit   │ │
│  │☑ │ set2-closer.mp4  │ video│ Blues Co…  │selected│ Edit   │ │
│  │☐ │ soundcheck.mp4   │ video│ —          │skipped │ Edit   │ │
│  │☑ │ ceremony.mp4     │ video│ Smith Wed… │selected│ Edit   │ │
│  └──┴──────────────────┴──────┴────────────┴────────┴────────┘ │
│                                                                 │
│  [Select all]  [Bulk: Set Status ▾]                             │
│                                                                 │
├─────────────────────────────────────────────────────────────────┤
│  3 files selected  •  2.4 GB                                    │
│                                                                 │
│              [ Promote Selected (3 files) to Upload → ]         │
└─────────────────────────────────────────────────────────────────┘
```

Button is disabled/hidden when selected count = 0. Clicking navigates to `admin/admin_database_catalog_promote.php`.

---

### `admin/admin_database_catalog_promote.php` — Promote UI (New)

Single page that progresses through states in sequence. "Back to Catalog" escape hatch is present at every state except the active upload (interrupting mid-upload leaves a partial job recoverable via `import_manifest_replay.php`).

**State 1 — Validation error** (422 from `catalog_promote_start.php` — missing `org_name` or `event_date`)

```
┌─────────────────────────────────────────────────────────────────┐
│  ⚠  Cannot promote — missing required fields                    │
│                                                                 │
│  The following entries are missing event metadata:              │
│  • ceremony.mp4  (catalog_entry_id 42) — missing: org_name     │
│                                                                 │
│  [ ← Back to Catalog (pre-filtered to affected entries) ]       │
└─────────────────────────────────────────────────────────────────┘
```

**State 2 — Collision warning** (if same `source_root` name spans multiple `scan_id` values)

```
┌─────────────────────────────────────────────────────────────────┐
│  ⚠  Possible folder name conflict                               │
│                                                                 │
│  During directory scan, two physical folders sharing the        │
│  top-level name 'recordings' were identified. Files from one    │
│  drive may have been silently dropped or overwritten during     │
│  scanning. To recover: rename one of the conflicting folders    │
│  so each has a distinct top-level name, then re-scan.           │
│                                                                 │
│  [ ← Back to Catalog ]          [ Continue anyway (risky) ]    │
└─────────────────────────────────────────────────────────────────┘
```

**State 3 — Folder picker** (one step per distinct `source_root`; repeats for each)

```
┌─────────────────────────────────────────────────────────────────┐
│  Step 1 of 2 — Grant access to source folder                    │
│                                                                 │
│  Please select the folder named:  recordings-2024               │
│  (browsers cannot retain file access between sessions)          │
│                                                                 │
│              [ 📁 Select folder "recordings-2024" ]             │
│                                                                 │
│  [ ← Back to Catalog ]                                          │
└─────────────────────────────────────────────────────────────────┘
```

**State 4 — Hashing + upload progress**

```
┌─────────────────────────────────────────────────────────────────┐
│  Uploading 3 files…                                             │
│                                                                 │
│  Hashing:  ████████████░░░░  8/12 files  (1.1 GB hashed)       │
│                                                                 │
│  ✓  gig-opener.mp4          uploaded                           │
│  ⟳  set2-closer.mp4         uploading…  47%                    │
│  ·  ceremony.mp4            waiting                            │
└─────────────────────────────────────────────────────────────────┘
```

**State 5 — Done**

```
┌─────────────────────────────────────────────────────────────────┐
│  ✓  3 files imported successfully                               │
│                                                                 │
│  [ View in Media Library → ]     [ ← Back to Catalog ]         │
└─────────────────────────────────────────────────────────────────┘
```

---

## Pipeline

### Current (without catalog)

```
Browser picks folder
  → Browser SHA-256 hashes every byte (slow, CPU-bound)
  → Browser uploads all picked files via TUS
  → import_manifest_upload_finalize.php → ingestComplete()
```

### Catalog-promoted

```
Catalog scans (Section A — full-reset scan of first folder; Section B — additive scans of additional folders)
  → Operator selects / deselects entries in db/database_catalog.php
  → promote UI calls catalog_promote_start.php → manifest JSON returned (metadata, no checksums yet)
  → promote UI presents folder picker(s); browser hashes selected files (SHA-256 Web Worker + IndexedDB cache)
  → POST import_manifest_prepare.php { mode: 'add', items, duplicates } → job_id
  → POST import_manifest_finalize.php { job_id } → triggers server-side manifest processing
  → poll import_manifest_status.php until state = 'ok'
  → POST import_manifest_upload_start.php { job_id }
  → TUS upload each selected file
  → POST import_manifest_upload_finalize.php { job_id, upload_id, checksum_sha256 } per file → ingestComplete() (unchanged)
  → POST catalog_promote_writeback.php { path_hash, checksum_sha256, upload_job_id } per file → asset_id resolved server-side
  → UPDATE catalog_entries SET status = 'imported', asset_id = ?, upload_job_id = ? WHERE path_hash = ?
  → poll import_manifest_upload_status.php for overall completion
```

Pre-filtering is the primary gain: on a 500-file folder where 300 are unwanted, that is a 60% reduction in browser hashing and upload work. Metadata (including `org_name`, `event_date`, `event_type`, `location`, `keywords`, `summary`, `label`, and `participants`) is pre-populated from catalog entries — no re-entry required. Per-entry fields that are NULL fall back to the parent `catalog_scans` row at promote time (see Promote Implementation Notes below).

---

## Multi-Source Upload

When selected entries span more than one distinct `source_root`, the browser cannot resolve all files in a single `webkitdirectory` pick — one picker invocation covers one folder root. The promote UI must:

- Identify the distinct `source_root` values among `status = 'selected'` entries
- Present one folder-picker step per `source_root`, in sequence — **display the expected `source_root` name in the picker prompt** (e.g. "Please pick the folder named '2024-recordings'") so the operator knows exactly which folder to select
- Accumulate matched files across all picks before beginning TUS upload
- Match files by `path_hash` (= SHA-256 of `webkitRelativePath`) — O(1) lookup against `catalog_entries.path_hash`; more reliable than relpath string matching

If the user picks the wrong folder, unmatched entries are reported (listing the unresolved `source_relpath` values) before upload begins and the pick for that `source_root` can be retried. The expected folder name must remain visible during the retry.

---

## Promote Workflow Design

The `catalog_entries.status` column and `catalog_entries.asset_id` FK were designed for this:

```
catalog scan(s)
  → operator reviews entries (status: 'cataloged' → 'selected' or 'skipped')
  → generate import manifest from WHERE status = 'selected' AND is_supported = 1
  → browser hashes + TUS upload (one picker round per distinct source_root)
  → ingestComplete() → assets table
  → catalog_promote_writeback.php resolves asset_id server-side
  → UPDATE catalog_entries
      SET status = 'imported',
          asset_id = <resolved server-side>,
          upload_job_id = <job_id>
```

*(Design summary — see Pipeline → Catalog-promoted for the full endpoint sequence.)*

The `asset_id` FK on `catalog_entries` then provides a traceable link between the catalog record and the ingested asset (note: defined `ON DELETE SET NULL` — the link is nulled if the asset is deleted). Directly supports:
- Use case #9 — orphan detection ("was this file ever imported?")
- Use case #3 — pre-filter gate ("only promote approved entries")
- Use case #13 — catalog as step 0 of the pipeline

---

## Files & Sequence (New/Changed) for Catalog Promotion 

| Walkthrough Step | Status | File | Purpose |
|---|---|---|---|
| Step 2 — Review & Trigger | **Changed** | `db/database_catalog.php` | Operator reviews entries, edits metadata, marks selections; footer button shows live selected count and total `size_bytes`; disabled when count = 0; clicking navigates browser to the promote UI |
| Step 3 — Navigate & Orchestrate | **New** | `admin/admin_database_catalog_promote.php` | Promote UI hub — loaded when operator clicks the promote button; calls `catalog_promote_start.php` on page load to fetch the manifest; presents folder picker(s) per distinct `source_root`; drives browser hashing; calls the full existing upload pipeline (Steps Pre-upload → Step 6 in the Existing table); issues per-file write-back calls after each upload completes |
| Step 3 — Manifest Build | **New** | `admin/catalog_promote_start.php` | POST endpoint called by promote UI on load; queries `catalog_entries WHERE status = 'selected' AND is_supported = 1`; applies `COALESCE(entry_col, scan_col)` fallback for all six event fields; derives `item_type` if NULL; returns manifest JSON — no checksums, those are computed browser-side in Step 5 |
| Steps 4–8 | *(existing)* | *(See "Files & Sequence (Existing…)" table)* | Folder picker(s) → browser SHA-256 hashing → `import_manifest_prepare.php` → `import_manifest_finalize.php` → poll → `import_manifest_upload_start.php` → TUS per file → `import_manifest_upload_finalize.php` → `ingestComplete()` |
| Step 9 — Write-back (per file) | **New** | `admin/catalog_promote_writeback.php` | POST endpoint called per file immediately after `import_manifest_upload_finalize.php` returns `200`; resolves `asset_id` server-side via `checksum_sha256` lookup (`SELECT asset_id FROM assets WHERE checksum_sha256 = ?`); executes `UPDATE catalog_entries SET status = 'imported', asset_id = ?, upload_job_id = ? WHERE path_hash = ?` directly via PDO — must bypass `db/catalog_entry_save.php` which blocks `status = 'imported'` as a pipeline-only value |

### Optional: server-side hashing flag

Add `"hash": true` parameter to `catalog_scan_start.php`. When set, the scan computes `checksum_sha256` via `hash_file('sha256', $fullPath)` for each file. Scan becomes slow for large collections but the pre-computed hash can be passed into the TUS flow, eliminating browser-side hashing for files the server can read. Should be gated behind a warning in the UI.

**Requires Phase 2 schema change:** `catalog_entries` has no `checksum_sha256` column. Adding this flag requires:
```sql
ALTER TABLE catalog_entries ADD COLUMN checksum_sha256 CHAR(64) NULL AFTER path_hash;
```

---

## Files & Sequence (Existing, Reused for Upload Manifest)

These files are **not modified** by User Flow 2. The promote UI feeds into them exactly as `admin_database_load_import_media_from_folder.php` does.

The internal Steps 1–6 below map to the Plain-Language Walkthrough as follows: Pre-upload = Step 5, Steps 1–2 = Step 6, Step 3 = Step 7, Steps 4–5 = Step 8, Step 6 = Step 10.

| Sequence | File | Role in promote flow |
|---|---|---|
| Pre-upload | Browser JS (SHA-256 Web Worker + IndexedDB cache) | Client-side hashing of selected files before any server call; already present in `admin_database_load_import_media_from_folder.php` — copy or extract to a shared script |
| Step 1 | `admin/import_manifest_prepare.php` | POST `{ mode: 'add', items, duplicates }` — creates the `upload_jobs` record; returns `job_id` used by all subsequent steps |
| Step 2 | `admin/import_manifest_finalize.php` | POST `{ job_id }` — triggers server-side manifest processing; called immediately after prepare |
| Step 3 (poll) | `admin/import_manifest_status.php` | GET `?job_id=` — polled until `state = 'ok'`; signals the manifest job is ready for upload to begin |
| Step 4 | `admin/import_manifest_upload_start.php` | POST `{ job_id }` — opens the upload session before the first TUS transfer |
| Step 5 (per file) | `admin/import_manifest_upload_finalize.php` | POST `{ job_id, upload_id, checksum_sha256 }` — called after each individual TUS upload completes; runs `ingestComplete()` synchronously before responding |
| Step 5 (internal) | `src/Services/UnifiedIngestionCore.php` | `ingestComplete()` — core ingestion called inside Step 5: ffprobe, `assets` table write, `events` / `event_items` creation; DB row committed before HTTP response returns |
| Step 6 (poll) | `admin/import_manifest_upload_status.php` | GET `?job_id=` — polled for overall upload completion across all files; drives the done/summary state |
| Recovery only | `admin/import_manifest_jobs.php` | GET — lists past upload jobs by mode; exposes job history in the promote UI so the operator can identify a failed job |
| Recovery only | `admin/import_manifest_replay.php` | POST `{ job_id }` — replays a previously failed job; allows per-job retry without returning to the catalog review page |

---

## Schema Changes Required (Phase 2)

The base promote workflow requires **no schema changes** — `catalog_entries` already has `asset_id`, `upload_job_id`, `status`, and `path_hash` for all of the above.

If the optional `hash=true` flag is implemented, the `ALTER TABLE` shown in the Optional server-side hashing section above applies. No other schema changes are required.

---

## Dependencies

- Phase 1 of `docs/feature_completed_catalog_insert.md` is complete and stable.
- `catalog_entries.status` state machine must be enforced consistently across all endpoints.
- The existing `import_manifest_lib.php` / `UnifiedIngestionCore` pipeline is unchanged; the promote workflow feeds into it, not around it.

---

## Schema Note: source_relpath Collation

`catalog_entries.source_relpath` uses the table default `utf8mb4_unicode_ci` — **not** `utf8mb4_bin`. An earlier design used `utf8mb4_bin` (byte-exact) but it was reverted; see the decision log in `docs/feature_completed_catalog_insert.md` for the full rationale. Key reasons: `_bin` broke cross-OS deduplication (macOS stores paths as NFD; Linux uses NFC — `_bin` treats them as different even when visually identical) and created JOIN inconsistencies with `assets.source_relpath`.

**Impact on promote workflow file-matching:** The `path_hash` column (SHA-256 of `webkitRelativePath`) is the correct lookup key at promote time — O(1), byte-exact by construction since it is a hash of the literal browser string. Do not match on `source_relpath` directly. When grouping selected entries by `source_root` for multi-picker sequencing, `source_root` comparisons are case/accent-insensitive under `_ci` — diacritic-variant folder names collapse to the same picker group, which is the correct behaviour for most users.

**Impact on UI search/filter:** `source_relpath` search in `db/database_catalog.php` or `admin_database_catalog_promote.php` is naturally case/accent-insensitive under `_ci`. No `COLLATE` override is needed for user-facing search.

---

## Promote Implementation Notes

Required logic inside `catalog_promote_start.php` and `catalog_promote_writeback.php`:

### Scan-level NULL fallback

When generating the manifest, six event fields on `catalog_entries` may be NULL — the operator left them to inherit from the parent scan. The promote endpoint must JOIN `catalog_scans` and apply `COALESCE`:

```sql
SELECT
  e.catalog_entry_id,
  e.path_hash,
  e.source_relpath,
  e.file_name,
  e.file_type,
  e.file_ext,
  e.mime_type,
  e.size_bytes,
  s.source_root                          AS source_root,
  COALESCE(e.org_name,    s.org_name)    AS org_name,
  COALESCE(e.event_date,  s.event_date)  AS event_date,
  COALESCE(e.event_type,  s.event_type)  AS event_type,
  COALESCE(e.location,    s.location)    AS location,
  COALESCE(e.keywords,    s.keywords)    AS keywords,
  COALESCE(e.summary,     s.summary)     AS summary,
  e.label,
  e.item_type,
  e.participants
FROM catalog_entries e
JOIN catalog_scans s ON s.scan_id = e.scan_id
WHERE e.status = 'selected' AND e.is_supported = 1
```

### `item_type` NULL auto-derive

When `item_type IS NULL` after COALESCE, apply the same default rule as `UploadService`:
```php
$itemType = $row['item_type'] ?? null;
if ($itemType === null) {
    $itemType = ($row['event_type'] === 'wedding') ? 'clip' : 'song';
}
```

### Write-back key and guard

- `catalog_promote_start.php` must include `path_hash` in each manifest row. The promote UI uses this to build a client-side **`webkitRelativePath → path_hash` map** (keyed by `source_root + '/' + source_relpath` — the full browser path, not the stripped `source_relpath` stored in `catalog_entries`). When `import_manifest_upload_finalize.php` returns `200` for a file, the UI looks up that file's `path_hash` from the map and POSTs it to `catalog_promote_writeback.php`.
- After `ingestComplete()` completes for a file, the browser POSTs `{ path_hash, checksum_sha256, upload_job_id }` to `catalog_promote_writeback.php` — **do not include `asset_id` in the browser payload**; the browser has no direct access to the server-assigned `asset_id`. `checksum_sha256` is required for the server-side `asset_id` lookup (see below).
- **Timing assumption:** `catalog_promote_writeback.php` must only be called after `import_manifest_upload_finalize.php` returns a `200` success response. This is safe because `ingestComplete()` runs **synchronously** inside `import_manifest_upload_finalize.php` — the HTTP response is not sent until the DB row in `assets` is committed. If `ingestComplete()` is ever made async, this assumption breaks and the write-back query will find no matching row.
- The write-back resolves `asset_id` server-side via the unique checksum index — **do not JOIN on `source_relpath`**: `catalog_entries.source_relpath` is the stripped path (no `source_root` prefix), while `assets.source_relpath` stores the full `webkitRelativePath` (with prefix), so that JOIN always returns zero rows:
```sql
SELECT asset_id FROM assets WHERE checksum_sha256 = ?
```
- Then executes `UPDATE catalog_entries SET status = 'imported', asset_id = ?, upload_job_id = ? WHERE path_hash = ?` directly via PDO — **do not route through `db/catalog_entry_save.php`**, which explicitly rejects `status = 'imported'` as a pipeline-only value.
- This design keeps `import_manifest_upload_finalize.php` and `ingestComplete()` fully unchanged — no modification to the core pipeline is required.

### `source_relpath` reconstruction for `import_manifest_prepare.php`

The manifest from `catalog_promote_start.php` carries a stripped `source_relpath` (the path below `source_root`, as stored in `catalog_entries`). Items POSTed to `import_manifest_prepare.php` must use the full `webkitRelativePath` as `source_relpath` — matching what the standard import page passes (`file.webkitRelativePath`). This ensures `assets.source_relpath` is written in the same format as a normal folder import, and that the TUS upload file map lookup resolves correctly.

The promote UI must reconstruct the full path per item before calling `import_manifest_prepare.php`:

```js
const fullRelpath = entry.source_root + '/' + entry.source_relpath;
items.push({ file_name: file.name, source_relpath: fullRelpath, ... });
```

The same `fullRelpath` is used as the key in the `webkitRelativePath → path_hash` map for write-back lookup consistency.

---

### Required-field validation in `catalog_promote_start.php`

Before returning the manifest, `catalog_promote_start.php` must validate that every selected entry has a non-NULL `org_name` and `event_date` after COALESCE. Both are required by `ensureEvent()` inside `ingestComplete()` — a NULL value causes a DB error mid-upload, after hashing and TUS work have already begun.

**Reduced by auto-populated scan defaults:** For entries created by a recent scan, `org_name` defaults to `'Default'` (scan level) and `event_date` is derived from the filename or file mtime (entry level), so the COALESCE almost always resolves to a non-NULL value. The 422 fires only for entries where both the per-entry value and the scan-level fallback are NULL — most commonly on entries created before the default-population logic was added, or edge cases where the scan form was submitted with a blank org field on an older build. In those cases the operator should edit the affected entries in `db/database_catalog.php` (amber-highlighted Org and Event Date columns indicate values that need review) and re-promote.

The endpoint also returns a count of selected entries excluded due to `is_supported = 0` so the UI can warn the operator before the folder picker appears (e.g. "2 of your 5 selected files were excluded — unsupported file types cannot be uploaded").

If any entry fails validation, the endpoint returns `422` before the folder picker is presented:

```json
{
  "status": "validation_error",
  "message": "2 selected entries are missing required fields.",
  "entries": [
    { "catalog_entry_id": 42, "file_name": "song.mp4", "missing": ["org_name"] }
  ]
}
```

The promote UI renders this as a blocking error with a direct link back to `db/database_catalog.php` (pre-filtered to the affected `catalog_entry_id` values) so the operator can fill in the missing metadata and re-promote.

---

### Source root name collision at promote time

Phase 1 (`docs/feature_completed_catalog_insert.md` — Design Decisions Log) documents that two physically different folders sharing the same top-level folder name produce identical `path_hash` values, silently dropping one set of entries at scan time. At promote time the folder picker prompt names each folder by `source_root` — if two physical drives sharing that name were scanned, the operator may pick the wrong one and some files will be unmatched.

**Option A — Warning + proceed/abort choice (recommended for Phase 2):** At promote load, `catalog_promote_start.php` detects whether the selected entries span multiple distinct `scan_id` values that share the same `source_root` string. If so, a `collision_warnings` array is included in the manifest response. Note: this condition also fires when the same physical folder has been re-scanned non-destructively more than once (both scans share `source_root` but no collision occurred). That produces a false-positive warning the operator can safely dismiss; the tradeoff is acceptable for Phase 2. The promote UI displays a blocking alert before the folder picker:

> "During directory scan, two physical folders sharing the top-level name '`<source_root>`' were identified. Files from one drive may have been silently dropped or overwritten during scanning. To recover: rename one of the conflicting folders so each has a distinct top-level name, then re-scan before promoting."

One primary action: **Back to catalog**. A secondary **Continue anyway** link is available for operators who understand the risk and have verified their catalog entries are complete.

**Option B — Scan-time enforcement (requires Phase 1 patch):** Block a non-destructive add in `catalog_scan_start.php` when the submitted `source_root` already exists under a different `scan_id` and the new batch shares any `source_relpath` with an existing entry. Returns a conflict error listing the clashing paths before any INSERT. Eliminates the problem before it reaches promote — but adds friction at every scan.

**Option C — Document only:** No runtime check. Rely on the Phase 1 design warning and the picker prompt text. Appropriate when the operator is a technically aware admin who controls the folder-naming convention.

---

### Partial upload failure and recovery

If some files fail to upload mid-promote, their `catalog_entries` rows remain at `status = 'selected'` — only successfully written-back entries reach `status = 'imported'`. The operator can:

1. Return to `db/database_catalog.php` — entries still showing `status = 'selected'` are the ones that did not complete.
2. Click "Promote Selected" again — `catalog_promote_start.php` queries `WHERE status = 'selected' AND is_supported = 1`, so only the remaining unimported entries are included in the new manifest.
3. The retry promote goes through the full pipeline again (`import_manifest_prepare.php` → TUS → write-back) for just the outstanding files.

`admin_database_catalog_promote.php` should display the `import_manifest_jobs.php` job history and expose the `import_manifest_replay.php` replay action so the operator can also retry at the upload-job level without returning to the catalog review page.
