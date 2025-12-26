# Database Load Methods — Simplification Notes

This document captures current state observations and a concrete roadmap to streamline GigHive’s database/media ingest workflows.

## Why it feels convoluted right now

There are currently multiple overlapping pipelines, each optimized for a different goal:

- **MySQL init (`/docker-entrypoint-initdb.d/`)**
  - Great for first install / provisioning.
  - Intentionally “dumb”: it can load CSVs but it does not compute hashes, discover media, or upload binaries.

- **Admin CSV imports (Section 3A / 3B)**
  - Useful for controlled reloads.
  - Involves multiple CSV formats (legacy `database.csv` vs normalized `sessions.csv` + `session_files.csv`).

- **Admin manifest imports (Section 4 / 5) + controller uploader**
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
  - **Add** (idempotent) — like Section 5
  - **Reload** (destructive refresh) — like Section 4

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
   - Update docs to position Sections 4/5 as the primary ingest methods.

2. **Make Section 3B a compatibility wrapper around the manifest**
   - Keep the UI and upload inputs (`sessions.csv` + `session_files.csv`).
   - Internally convert them server-side into a manifest, then reuse the same insert/link logic as the manifest endpoints.
   - Outcome: fewer different code paths that mutate the DB.

3. **Unify server-side DB mutation logic**
   - Factor shared logic used by:
     - `import_manifest_reload.php`
     - `import_manifest_add.php`
     - (and the new internal Section 3B wrapper)
   - Outcome: one place to maintain idempotency rules, dedupe rules, and linking rules.

4. **Offer one “recommended ingest command” for operators**
   - A controller-side script that:
     - scans one or more roots,
     - computes hashes,
     - uploads binaries,
     - posts a manifest to either “add” or “reload”.
   - This reduces the number of moving parts users must learn.

5. **Deprecate legacy 3A / legacy CSV paths (after a transition period)**
   - Keep temporarily for migration/backward compatibility.
   - Once the manifest path is proven, mark Section 3A as legacy and eventually remove.

## Key point (to keep expectations sane)

A database being “populated” is not the same as the system being “usable” for downloads.

- DB usability for downloads requires `files.checksum_sha256` + media binaries uploaded by checksum.
- MySQL init loading alone cannot produce hashes; it must be paired with a hash-producing path (Sections 4/5 or converter+3B) and typically the controller uploader.
