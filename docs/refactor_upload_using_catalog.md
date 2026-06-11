# Refactor: Streamline Upload/Ingest Using Catalog Tables

## Status

Deferred — requires `feature_db_catalog_insert.md` Phase 1 to be fully implemented and stable first.

---

## Overview

The catalog feature is a multi-source aggregation pre-step upstream of the existing import pipeline. The operator builds an inventory across one or more source directories — without hashing, uploading, or touching any media bytes — then culls the combined list and promotes the selection into the existing browser-driven TUS flow with metadata pre-populated.

Workflow:

1. **Destructive scan** of the first source directory — populates `catalog_entries` fresh
2. **Non-destructive adds** of additional source directories — entries appended without overwriting existing ones
3. **Review and cull** in `db/database_catalog.php` — select all, then deselect unwanted; or build up selection individually
4. **Promote** — server generates a manifest from `WHERE status = 'selected'`
5. **Browser TUS upload** — existing flow, driven by the catalog manifest; only selected files are hashed and uploaded

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
Catalog scans (one destructive + zero or more non-destructive adds)
  → Operator selects / deselects entries in db/database_catalog.php
  → Server generates manifest from WHERE status = 'selected'
  → Browser hashes + TUS uploads manifest-listed files only
  → import_manifest_upload_finalize.php → ingestComplete() (unchanged)
  → UPDATE catalog_entries SET status = 'imported', asset_id = ?
```

Pre-filtering is the primary gain: on a 500-file folder where 300 are unwanted, that is a 60% reduction in browser hashing and upload work. Metadata (`org_name`, `event_date`, `event_type`, `location`, `label`, `participants`) is pre-populated from catalog entries — no re-entry required.

---

## Multi-Source Upload

When selected entries span more than one distinct `source_root`, the browser cannot resolve all files in a single `webkitdirectory` pick — one picker invocation covers one folder root. The promote UI must:

- Identify the distinct `source_root` values among `status = 'selected'` entries
- Present one folder-picker step per `source_root`, in sequence
- Accumulate matched files across all picks before beginning TUS upload
- Match files by `source_relpath` relative to the picked folder root

If the user picks the wrong folder, unmatched entries are reported before upload begins and the pick can be retried.

---

## Promote Workflow Design

The `catalog_entries.status` column and `catalog_entries.asset_id` FK were designed for this:

```
catalog scan(s)
  → operator reviews entries (status: 'cataloged' → 'selected' or 'skipped')
  → generate import manifest from WHERE status = 'selected' AND is_supported = 1
  → browser hashes + TUS upload (one picker round per distinct source_root)
  → ingestComplete() → assets table
  → UPDATE catalog_entries
      SET status = 'imported',
          asset_id = <new asset_id>,
          upload_job_id = <job_id>
```

The `asset_id` FK on `catalog_entries` then provides a permanent link between the catalog record and the ingested asset, directly supporting:
- Use case #9 — orphan detection ("was this file ever imported?")
- Use case #3 — pre-filter gate ("only promote approved entries")
- Use case #13 — catalog as step 0 of the pipeline

---

## New Files Required (Phase 2)

| File | Purpose |
|---|---|
| `admin/catalog_promote_start.php` | POST — generates manifest JSON from `status = 'selected'` entries for the browser TUS flow |
| `admin/catalog_entry_update.php` | POST — updates `status`, per-entry metadata (`org_name`, `event_date`, etc.) on one or many entries |
| `admin/admin_database_catalog_promote.php` | UI — entry review table with select-all/deselect controls, manifest export, multi-source picker sequence |

### Optional: server-side hashing flag

Add `"hash": true` parameter to `catalog_scan_start.php`. When set, the scan computes `checksum_sha256` via `hash_file('sha256', $fullPath)` for each file. Scan becomes slow for large collections but the pre-computed hash can be passed into the TUS flow, eliminating browser-side hashing for files the server can read. Should be gated behind a warning in the UI.

**Requires Phase 2 schema change:** `catalog_entries` has no `checksum_sha256` column. Adding this flag requires:
```sql
ALTER TABLE catalog_entries ADD COLUMN checksum_sha256 CHAR(64) NULL AFTER path_hash;
```

---

## Schema Changes Required (Phase 2)

The base promote workflow requires no schema changes — `catalog_entries` already has `asset_id`, `upload_job_id`, `status`, and `path_hash` for all of the above.

If the optional `hash=true` flag is implemented, one additional column is required:

```sql
ALTER TABLE catalog_entries ADD COLUMN checksum_sha256 CHAR(64) NULL AFTER path_hash;
```

This allows the pre-computed checksum to be passed directly into `ingestStub()`, eliminating browser hashing for server-readable sources.

---

## Dependencies

- Phase 1 of `docs/feature_db_catalog_insert.md` must be complete and stable.
- `catalog_entries.status` state machine must be enforced consistently across all endpoints.
- The existing `import_manifest_lib.php` / `UnifiedIngestionCore` pipeline is unchanged; the promote workflow feeds into it, not around it.
