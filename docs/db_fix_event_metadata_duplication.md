---
description: Stop-gap plan to prevent event/session duplication from mutable metadata before the full Event/Asset remodel
---

# DB Fix Plan: Event Metadata Duplication Stop-Gap

## Objective

Introduce a stable event identity for current session-based import/upload flows so that:

- repeated uploads append to the same logical Event even if display metadata changes later
- mutable metadata such as `org_name` no longer determines Event identity
- checksum-based asset dedupe remains unchanged
- the stop-gap aligns with the future direction in `docs/pr_librarianAsset_musicianEvent.md`

This is an intermediate step only. It is intended to reduce user-facing duplication and cross-linking risk while the codebase still uses the legacy `sessions/songs/files` model.

## Problem Summary

Current Section 5 behavior keys a session/event by:

- `event_date`
- `org_name`

That creates two classes of problems:

1. **Mutable metadata changes identity**
   - If an admin uploads with `org_name=default`, later edits the row to `StormPigs`, then uploads again with `org_name=default`, the importer creates a second session because `(date, org_name)` no longer matches the original row.

2. **Cross-session song reuse becomes visible as duplicate rows**
   - Manifest import and upload paths currently reuse `songs` globally by `title`.
   - When two sessions share titles, `session_songs` can link both sessions to the same song rows, and `song_files` can make the same files appear under both sessions.

The debugging on lab showed:

- `files.checksum_sha256` dedupe is working correctly
- duplicate-looking Media Library rows are caused by two sessions on the same date plus shared global song rows
- the second session was created because `org_name` had changed from `default` to `StormPigs` after the first upload

## Recommended Stop-Gap Design

## Core recommendation

Add a stable `event_key` to the current `sessions` table and use it as the canonical identity for Section 5 imports and upload flows.

### Identity rules

- **Event identity**: `sessions.event_key`
- **Editable display metadata**: `sessions.org_name`, `sessions.title`, `sessions.location`, etc.
- **Asset identity**: `files.checksum_sha256`

### Why this is the best stop-gap

- It moves the legacy schema toward the future Event/Asset split without doing the full remodel yet.
- It stops mutable metadata from changing identity.
- It avoids the bad design of using `event_date + sha` as the Event key.
- It is conceptually compatible with the future canonical `events` table.

## Why NOT use `event_date + sha`

Using `event_date + sha` as the Event identifier is not recommended because SHA identifies an Asset, not an Event.

Problems with `event_date + sha`:

- one Event could fragment depending on which file is seen first
- partial re-uploads would not map reliably
- adding another file later would implicitly change the Event fingerprint set
- two legitimate Events on the same date could share one checksum and become ambiguous

The correct split is:

- use SHA for Asset identity
- use a stable explicit key for Event identity

## Proposed Stop-Gap Contract

## New schema field

Add to `sessions`:

- `event_key VARCHAR(...) NOT NULL`
- `UNIQUE (event_key)`

Optional but recommended:

- retain `UNIQUE(date, org_name)` temporarily only if other legacy flows still depend on it
- plan to de-emphasize it immediately in importer logic

## Section 5 manifest contract

Add a top-level manifest field:

- `event_key`

Manifest payload remains otherwise compatible:

- `org_name`
- `event_type`
- `items[]`
- each item continues to carry `event_date`, `file_name`, `source_relpath`, `checksum_sha256`, etc.

## Event upsert rule

For Section 5 add/reload and upload flows:

- find/create session by `event_key`
- if found, reuse that `session_id`
- if not found, create a new session row with the supplied `event_date`, `org_name`, `event_type`, and derived title

## Editing rule

Admin edits to:

- `org_name`
- `rating`
- `keywords`
- `location`
- `summary`

must not change `event_key`.

`org_name` becomes pure metadata, not identity.

## Song-linking rule for the stop-gap

Even after `event_key` is introduced, the existing global `ensureSong(title, type)` behavior remains a separate risk.

For the stop-gap, the implementation should also stop global song reuse across sessions. The minimum safe behavior is:

- resolve/create songs in a session-scoped way
- do not attach an existing global song row from another session solely because the title matches

This doc is primarily about the event duplication problem, but the implementation plan below explicitly includes this because the current duplicate-row symptom is the combination of both bugs.

## Concrete Implementation Plan

## Phase 1: Schema changes

### 1. Add `event_key` to `sessions`

Update DB bootstrap so fresh databases create `sessions.event_key`.

Recommended column behavior:

- non-null
- unique
- indexed

Recommended initial derivation strategy for the stop-gap:

- compute from import context before first insert
- example format: stable slug derived from folder/event context, such as `20260318-stormpigs`
- better than `org_name` alone because it is explicit and can remain stable after metadata edits

### 2. Add migration for existing DBs

For already-deployed DBs, add a migration that:

- adds `event_key`
- backfills existing sessions with deterministic values
- adds a unique constraint on `event_key`

Backfill note:

- existing duplicate logical events may need manual review if they already represent accidental splits
- migration should preserve existing `session_id` values and only add identity metadata

## Phase 2: Section 5 UI and payload changes

### 3. Extend admin Section 5 manifest generation

In `admin.php` Section 5:

- derive or collect an `event_key`
- include it in the manifest payload posted to add/reload endpoints
- show the chosen `event_key` in preview/status UI so operators understand what Event is being targeted

### 4. Make the Section 5 UX explicit

Preferred stop-gap UX:

- prefill `event_key` based on folder/date/org inference
- allow the admin to edit/confirm it before upload
- explain that future uploads to the same Event should reuse the same `event_key`

## Phase 3: Importer changes

### 5. Replace `(event_date, org_name)` session lookup with `event_key`

Update both sync and async manifest import paths so they:

- validate top-level `event_key`
- use `event_key` as the session key in memory and in SQL lookup
- create sessions with `event_key` if absent

### 6. Preserve display metadata updates without changing identity

When an existing session is found by `event_key`:

- do not create a new session just because `org_name` differs
- optionally update metadata fields only when the incoming values are non-empty and policy allows it
- do not let metadata drift silently without an explicit rule; document exact update behavior

### 7. Fix song reuse in the same implementation pass

Current code reuses songs globally by title. That is unsafe.

Stop-gap implementation options:

- **Preferred**: make song identity session-scoped in importer/upload logic
- **Minimum acceptable**: on import, create a new song row for the session when the title exists only in another session

The implementation should ensure:

- the same `song_id` is not silently shared across unrelated sessions just because the title matches
- `session_songs` and `song_files` do not create cross-session fanout through reused song rows

## Phase 4: Upload API alignment

### 8. Update upload flows to use the same event identity rule

The stop-gap should not only fix Section 5. The upload service currently also finds sessions by `(date, org_name)`.

Update it to:

- accept/use `event_key`
- reuse the same session when metadata changes later
- avoid reintroducing the same bug through non-Section-5 upload paths

## Phase 5: Listing and editing considerations

### 9. Keep Media Library behavior stable

Current Media Library rows can still show duplication if cross-session song reuse remains.

After event-key and song-link fixes:

- the same repeat upload should stay on the same session/event
- duplicated display rows caused by cross-session song sharing should stop being created going forward

### 10. Keep row editing metadata-only

Admin edit flows should continue updating:

- `org_name`
- `rating`
- `keywords`
- `location`
- `summary`
- `song_title`

But must not mutate the stable event identity unless an explicit future feature is added for changing/re-keying an Event.

## Rationale

## Why this is preferable to the status quo

- Re-uploading to the same real-world Event remains possible after metadata cleanup.
- Operators can rename `default` to `StormPigs` without breaking future attachment behavior.
- Checksum dedupe remains global and simple.
- The stop-gap matches the future model in `docs/pr_librarianAsset_musicianEvent.md` where Event identity and Asset identity are distinct.

## Why this is preferable to jumping straight to `event_date + sha`

- `event_date + sha` confuses Event identity with Asset identity
- it makes partial imports and later additions unstable
- it does not match the future Event/Asset model

## Why this is preferable to keeping `(date, org_name)`

- `org_name` is mutable metadata
- users already edit it after import
- using it as identity guarantees accidental event splits

## Affected Files Under `ansible/roles/docker/files`

The following files are expected to be affected by this stop-gap.

## Database / schema

- `ansible/roles/docker/files/mysql/externalConfigs/create_music_db.sql`
  - add `sessions.event_key`
  - add unique constraint on `event_key`
  - decide whether to retain or later relax `UNIQUE(date, org_name)`

- `ansible/roles/docker/files/mysql/externalConfigs/load_and_transform.sql`
  - review if bootstrap/load scripts need to populate `event_key` during database rebuild/import flows

- `ansible/roles/docker/files/mysql/dbScripts/...`
  - any operational SQL scripts that inspect sessions may need updates once `event_key` exists
  - exact scripts should be reviewed if they create/repair session rows or assume `(date, org_name)` identity

## Admin / manifest import UI

- `ansible/roles/docker/files/apache/webroot/admin.php`
  - Section 5 add/reload manifest generation must include `event_key`
  - UI should expose or confirm `event_key`
  - preview/help text should explain that Event identity is stable and not tied to editable band metadata

## Async manifest endpoints and worker path

- `ansible/roles/docker/files/apache/webroot/import_manifest_add_async.php`
  - quick validation may need to require `event_key`
  - manifest job metadata may include it for observability

- `ansible/roles/docker/files/apache/webroot/import_manifest_reload_async.php`
  - same as add async path

- `ansible/roles/docker/files/apache/webroot/import_manifest_worker.php`
  - worker orchestration may not need logic changes, but affected indirectly because the payload contract changes

- `ansible/roles/docker/files/apache/webroot/import_manifest_lib.php`
  - top-level payload validation must include `event_key`
  - `ensureSession(...)` must upsert by `event_key`
  - in-memory session map must key by `event_key`, not `event_date|org_name`
  - song resolution logic should stop global cross-session reuse by title

## Sync manifest endpoints (if retained / parity)

- `ansible/roles/docker/files/apache/webroot/import_manifest_add.php`
  - same logic changes as async library path
  - upsert by `event_key`
  - stop global song reuse by title

- `ansible/roles/docker/files/apache/webroot/import_manifest_reload.php`
  - same logic changes as sync add path
  - upsert by `event_key`
  - stop global song reuse by title

## Upload API path

- `ansible/roles/docker/files/apache/webroot/src/Services/UploadService.php`
  - `ensureSession(...)` currently finds by `(date, org_name)` and must move to `event_key`
  - `ensureSong(...)` currently reuses songs globally by title and must be made event/session-safe

- `ansible/roles/docker/files/apache/webroot/src/Controllers/UploadController.php`
  - if request/response contracts need to carry `event_key`, controller validation/serialization may need updates

## Media editing / admin metadata updates

- `ansible/roles/docker/files/apache/webroot/db/database_edit_save.php`
  - currently updates `sessions.org_name`
  - should remain metadata-only; document that editing org name must not affect Event identity once `event_key` exists
  - may need future safeguards if re-keying is not supported

## Listing / read paths to review

These files may not require direct stop-gap logic changes immediately, but they should be reviewed because they surface the consequences of identity/linkage rules:

- `ansible/roles/docker/files/apache/webroot/db/database.php`
- `ansible/roles/docker/files/apache/webroot/src/Controllers/MediaController.php`
- `ansible/roles/docker/files/apache/webroot/src/Repositories/SessionRepository.php`
- `ansible/roles/docker/files/apache/webroot/src/Views/media/list.php`

Reason:

- current Media Library joins are where duplication symptoms become visible
- if any view/filter/sort logic later needs to expose `event_key`, these files will change

## API / docs contracts under `ansible/roles/docker/files`

- `ansible/roles/docker/files/apache/webroot/docs/openapi.yaml`
  - if any import/upload contract is documented there, review for `event_key`

## Tests / automation under `ansible/roles/docker/files`

No direct test files were found under `ansible/roles/docker/files` that fully cover this path, but any file in that subtree that:

- posts manifest payloads
- builds session rows
- assumes `(date, org_name)` identity

should be updated if present.

## Implementation Notes / Guardrails

## Guardrail 1: do not treat editable metadata as identity

Once `event_key` is introduced:

- `org_name` must be metadata only
- editing it must not create or imply a different Event

## Guardrail 2: do not use checksum to identify Events

- keep SHA for Asset dedupe only
- do not derive Event identity from current file membership

## Guardrail 3: stop reusing songs globally by title

This stop-gap will not be sufficient if song reuse remains global. Even with `event_key`, cross-session joins can still become misleading if songs are shared incorrectly across sessions.

## Guardrail 4: preserve future migration path

The stop-gap should make future migration easier by treating:

- `sessions.event_key` as the precursor to future canonical `events.event_key`
- `files.checksum_sha256` as the precursor to future canonical `assets.checksum_sha256`

## Suggested Delivery Milestones

### Milestone A: schema + importer contract

- add `sessions.event_key`
- update manifest payload validation
- switch Section 5 session upsert to `event_key`

### Milestone B: UI + upload alignment

- expose/confirm `event_key` in Section 5 UI
- update upload service to use the same identity rule

### Milestone C: song-link safety

- make song creation/linking session-safe for current schema
- verify no new cross-session `song_id` sharing is created by import/upload

### Milestone D: cleanup + docs

- update operator-facing docs
- add diagnostics/queries for validating correct event reuse and no cross-session song leakage

## Acceptance Criteria for the Stop-Gap

- Re-uploading the same folder to the same intended Event reuses the same session row even if `org_name` was edited after the first upload.
- Editing `org_name` after import does not change Event identity.
- Section 5 add/reload and upload API use the same Event identity rule.
- Checksum dedupe continues to prevent duplicate file rows.
- Import/upload no longer create cross-session row fanout by reusing songs globally by title.
- The plan remains compatible with the future Event/Asset hard cutover described in `docs/pr_librarianAsset_musicianEvent.md`.
