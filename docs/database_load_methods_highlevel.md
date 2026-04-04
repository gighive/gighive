# Database Load Methods — Simplification Notes

This document captures current state observations and a concrete roadmap to streamline GigHive’s database/media ingest workflows.

## Why it feels convoluted right now

There are currently multiple overlapping pipelines, each optimized for a different goal:

- **MySQL init (`/docker-entrypoint-initdb.d/`)**
  - Great for first install / provisioning.
  - Intentionally “dumb”: it can load CSVs but it does not compute hashes, discover media, or upload binaries.

- **Admin CSV imports (`admin_database_load_import.php` Sections A / B)**
  - Useful for controlled reloads.
  - Section A: legacy single `database.csv` reload. Section B: normalized `sessions.csv` + `session_files.csv`.

- **Admin manifest imports (`import_manifest_add.php` / `import_manifest_reload.php`) + controller uploader**
  - Closest to the long-term ideal (hash-first, idempotent add/reload).
  - Currently split across browser hashing, PHP endpoints, and the controller-side `upload_media_by_hash.py`.

This is normal during a migration from “CSV-driven” ingest to “hash-first” ingest.

## Simplification direction (north star)

Aim for a single consistent ingest contract and a single mental model:

- **Canonical ingest contract**: a manifest where each media item includes:
  - `checksum_sha256`
  - `source_relpath`
  - `file_type`
  - optional metadata such as `event_date`, `size_bytes`

- **Two operations only**:
  - **Add** (idempotent) — like `admin_database_load_import_media_from_folder.php` Section B
  - **Reload** (destructive refresh) — like `admin_database_load_import_media_from_folder.php` Section A

- **MySQL init becomes provisioning-only**:
  - Schema + seeds + maybe sample/demo dataset.
  - Not part of the “real ingest” story.

## Practical streamlining guidance (what to converge on)

- Prefer a workflow where hashing is always performed before inserting/updating file rows.
- Prefer controller-side workflows for large libraries (faster/more reliable than browser hashing).
- Keep browser scanning as a convenience feature, not the primary ingest path.

## Concrete roadmap (incremental, low-risk)

1. **Define “manifest is canonical”**
   - Treat the manifest schema as the authoritative ingest interface.
   - Update docs to position `import_manifest_add.php` (add) and `import_manifest_reload.php` (reload) as the primary ingest methods.

2. **Make `admin_database_load_import.php` Section B a compatibility wrapper around the manifest**
   - Keep the UI and upload inputs (`sessions.csv` + `session_files.csv`).
   - Internally convert them server-side into a manifest, then reuse the same insert/link logic as the manifest endpoints.
   - Outcome: fewer different code paths that mutate the DB.

3. **Unify server-side DB mutation logic**
   - Factor shared logic used by:
     - `import_manifest_reload.php`
     - `import_manifest_add.php`
     - (and the internal Section B wrapper in `admin_database_load_import.php`)
   - Outcome: one place to maintain idempotency rules, dedupe rules, and linking rules.

4. **Offer one “recommended ingest command” for operators**
   - A controller-side script that:
     - scans one or more roots,
     - computes hashes,
     - uploads binaries,
     - posts a manifest to either “add” or “reload”.
   - This reduces the number of moving parts users must learn.

5. **Deprecate legacy Section A / legacy CSV paths (after a transition period)**
   - Keep temporarily for migration/backward compatibility.
   - Once the manifest path is proven, mark Section A of `admin_database_load_import.php` as legacy and eventually remove.

## Key point (to keep expectations sane)

A database being “populated” is not the same as the system being “usable” for downloads.

- DB usability for downloads requires `files.checksum_sha256` + media binaries uploaded by checksum.
- MySQL init loading alone cannot produce hashes; it must be paired with a hash-producing path (`import_manifest_add.php`/`import_manifest_reload.php` or converter + `admin_database_load_import.php` Section B) and typically the controller uploader.
