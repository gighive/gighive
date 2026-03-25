# DB Update: Add `media_created_at`

## Goal
Add a dedicated nullable `media_created_at` column to the `files` table and populate it consistently for both:

- upload API / direct upload paths
- manifest add/reload import paths launched from `admin.php`

This field is intended to store the media's original creation timestamp derived from media metadata, not the database row creation time.

## Why a dedicated column
`media_created_at` should be a first-class database field rather than a value derived on every read from `media_info`.

Benefits:

- simpler queries and sorting
- cleaner display in `db/database.php`
- consistent semantics across ingestion paths
- easier future migration to the Event/Asset model
- preserves raw probe metadata separately from normalized canonical fields

## Important distinction
The existing `files.created_at` column records when the database row was created.

The new `files.media_created_at` column should record when the media was originally created according to metadata extracted from the media file.

## Scope
### In scope
- add `files.media_created_at DATETIME NULL`
- populate it during standard upload API ingestion
- populate it during TUS finalize ingestion
- populate it during manifest add imports
- populate it during manifest reload imports
- display it in `db/database.php`

### Out of scope
- full ingestion-core unification
- Event/Asset schema remodel
- backfill of all historical rows unless separately approved
- new dedicated columns for GPS, camera make/model, or other metadata

## Source of truth for extraction
Preferred metadata source order:

1. `format.tags.creation_time`
2. `streams[0].tags.creation_time`

If neither value exists or cannot be parsed, store `NULL` in `media_created_at`.

## Storage rules
- Parse media creation time from probe metadata
- Normalize to a DB-safe datetime value
- Store canonical value in `files.media_created_at`
- Preserve raw `media_info` unchanged

`media_info` is the raw structured metadata captured from an `ffprobe` run against the media file. This plan keeps that `ffprobe`-derived JSON intact while also saving the normalized `media_created_at` value in its own column.

## Recommended implementation approach
Implement the targeted field first, without waiting for the larger unified ingestion core refactor.

A small shared helper for parsing media metadata may be extracted now if it reduces duplication between upload and manifest import code.

## Required code areas
### 1) Baseline schema for new installs
Update:

- `ansible/roles/docker/files/mysql/externalConfigs/create_music_db.sql`

Add the new column to the `files` table definition so fresh environments include it automatically.

### 2) Idempotent migration for existing installs
Update:

- `ansible/roles/db_migrations/tasks/main.yml`

Add a migration following the existing role pattern:

- check whether `files.media_created_at` exists
- add it if missing
- verify it exists afterward
- fail clearly if the migration did not take effect

## Upload API path changes
### Files involved
- `ansible/roles/docker/files/apache/webroot/src/Services/UploadService.php`
- `ansible/roles/docker/files/apache/webroot/src/Repositories/FileRepository.php`

### Planned behavior
During upload handling:

- run existing media probing logic
- extract raw `media_info`
- derive `media_created_at` from probe metadata
- pass `media_created_at` into repository persistence
- insert it into the `files` row

### Notes
This will cover both:

- standard upload requests
- TUS uploads, because finalize flows already funnel through upload handling

## Manifest import path changes
### Files involved
- `ansible/roles/docker/files/apache/webroot/import_manifest_lib.php`
- possibly related wrappers:
  - `import_manifest_add.php`
  - `import_manifest_reload.php`
  - `import_manifest_worker.php`

### Why explicit work is needed
Manifest import paths do not currently use `UploadService`.

They insert directly into `files`, so they will not automatically receive `media_created_at` behavior from upload-path changes alone.

### Planned behavior
For manifest imports:

- ensure the import pipeline can obtain the media creation timestamp from server-side media probing or equivalent import-available metadata
- derive `media_created_at`
- include it in the direct `INSERT INTO files (...)`
- keep behavior consistent for both add and reload modes

### Design note
If practical, manifest imports should reuse the same narrow metadata parsing helper as the upload path so extraction rules stay aligned.

## Display changes
### Files likely involved
- `ansible/roles/docker/files/apache/webroot/src/Repositories/SessionRepository.php`
- `ansible/roles/docker/files/apache/webroot/src/Controllers/MediaController.php`
- `ansible/roles/docker/files/apache/webroot/src/Views/media/list.php`
- `ansible/roles/docker/files/apache/webroot/db/database.php`

### Planned behavior
- select `media_created_at` in the listing query path
- pass it through the controller/view model
- render it in the media table shown by `db/database.php`

Recommended initial label:

- `Media Created`

## Acceptance criteria
- `files.media_created_at` exists in baseline schema and is added via migration on existing installs
- upload API path stores `media_created_at` when probe metadata provides a creation timestamp
- TUS finalize path stores `media_created_at`
- manifest add path stores `media_created_at`
- manifest reload path stores `media_created_at`
- rows without valid metadata store `NULL`
- `db/database.php` displays the field
- raw `media_info` remains preserved and unmodified

## Recommended sequencing
1. Add schema column and migration
2. Implement upload-path extraction and persistence
3. Implement manifest import-path extraction and persistence
4. Add listing/display support
5. Optionally consider historical backfill as a separate follow-up

## Risks and checks
### Manifest import metadata availability
The implementation must confirm that manifest import processing has access to enough information to determine `media_created_at` reliably.

### Duplication risk
If metadata parsing is implemented twice, the two code paths may drift. A small shared helper is recommended if it can be introduced without broad refactoring.

### Nullability
Some files will not provide a usable creation timestamp. The column should remain nullable and the application should tolerate `NULL` cleanly.

## Summary
This change should add a dedicated `media_created_at` column and populate it consistently across both major ingestion families:

- upload API / TUS upload flow
- manifest add/reload import flow

It should be implemented as a targeted schema-and-write-path enhancement now, while leaving the larger unified ingestion core refactor as a separate follow-up effort.

## Future follow-up
A later architectural step should unify the upload API and manifest import write paths behind a shared ingestion core.

That follow-up plan is documented in:

- `docs/refactor_unified_ingestion_core.md`
