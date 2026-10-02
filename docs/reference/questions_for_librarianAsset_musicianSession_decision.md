# Questions to Answer (Librarian Asset vs Musician Session)

## Decisions so far (answered)

- **Deployment defaults**
  - `APP_FLAVOR=gighive`: default workflow is **capture** (band + wedding).
  - Stormpigs / `defaultcodebase`: default workflow is **media librarian**.

- **Vocabulary**
  - Use **Event** as the generic term covering both band and wedding capture workflows.

- **Asset identity (beta)**
  - **Checksum-first**: assets must have a SHA-256 checksum; identity/deduping is by checksum.

- **Asset ↔ event relationship (beta)**
  - **Many-to-many**: an asset (checksum) can be attached to multiple events.
  - **Policy/UX note**: UI should clearly indicate when an upload is a duplicate of an existing asset and when an asset appears in multiple events to avoid user confusion.

- **Event structure (beta)**
  - Use **Event Items** (generic, event-scoped) rather than global “songs”.
  - Items must support both:
    - band use cases (e.g., song titles)
    - wedding/guest capture moments (free-form descriptions)
  - Items should be **lightly typed** (e.g., `item_type`) to support sorting/filtering (especially for videographers).
  - Beta `item_type` set:
    - `song`
    - `moment`
    - `speech`
    - `ceremony`
    - `reception`
    - `artifact`
    - `other`

- **Capture upload UX (beta)**
  - Use a **dropdown** to select the Event Item type (and/or an existing Event Item) to keep the flow minimally invasive.
  - Default selection for musician/band capture should be `song`.
  - Default selection for wedding/videographer capture should be `reception`.

- **Librarian workflow (beta)**
  - No explicit Collections/Projects model in beta.
  - Backlog: add `collections` and `collection_assets` later if needed.

- **Duplicate checksum uploads (beta)**
  - Reject duplicate uploads **globally** when `checksum_sha256` already exists.
  - Backlog: consider allowing reuse/linking of an existing asset into a different event if clientele asks for it.

- **Migration strategy (beta)**
  - Do a **hard cutover** to the new Event/Assets model (new schema is canonical; no legacy runtime code paths).
  - Legacy tables are not kept for ongoing compatibility; if retained briefly, it is only for rollback/verification before being dropped.

 - **Backups (operational)**
  - Tag database backups with a schema version (e.g., write a `schema_version` file alongside each dump) to reduce restore ambiguity.

This document captures the open design questions we should answer before implementing the model shift to support:

- A **librarian / asset-centric** workflow
- A **musician / session-centric** workflow

Once these are answered, we can write a concrete, phased implementation plan.

---

## A) Core data model / identity

- What is the canonical identity of an asset?
  - `checksum_sha256` only?
  - `asset_id` + unique `checksum_sha256`?
  - How do we handle assets with missing SHA (legacy rows)?

- Can the same binary (same SHA) legitimately appear in multiple contexts?
  - If yes, do we store one asset row and multiple “occurrence” rows?

- Do we keep `files` as a “source/ingestion” record, or replace it with `assets` + `asset_sources`?

- Should session membership be direct (e.g., `session_assets`) and independent of songs/items?

---

## B) Band/session semantics

- Do we want a “song” entity at all in the band/session workflow?
  - Or do we want session-scoped “items” (setlist entries) instead of global songs?

- If songs exist, are they:
  - global canonical works (e.g., “All Along the Watchtower”), or
  - session-scoped labels (“the thing we played in session 199 at position 3”)?

- What is the session-view row grain?
  - One row per `session + asset`?
  - Or one row per `session + item + asset`?

- How should ordering work?
  - Today we effectively have `position` in `session_songs`; what replaces that?

---

## C) Librarian semantics

- What does the librarian view show as “one row”?
  - One row per unique asset (checksum)?

- What context should be displayed/aggregated in librarian view?
  - `session_count`, orgs, dates, tags, etc.

- Should librarian uploads create sessions at all?
  - Or be pure asset ingestion with optional later attachment to sessions/projects?

---

## D) Import/upload behavior (where the current collision originates)

- How should `label` behave going forward?
  - Is it still accepted on upload?
  - If so, does it map to session-scoped items only?

- Should manifest import have an explicit `mode=session|librarian`?
  - If yes, what is the default mode per `APP_FLAVOR`?

- What should happen with generic basenames (`output`, `final`, `export`, etc.)?
  - Ignore for session labeling?
  - Treat as “artifact type”?
  - Require explicit user-provided label?

---

## E) Migration strategy / compatibility

- Do we want an in-place migration or an additive migration with backfill + cutover?

- How long must legacy tables remain readable/writable?
  - Do we need dual-write during beta (write both old and new)?

- How do we backfill relationships from existing data safely?
  - Use `files.session_id` as ground truth for session membership?
  - How do we handle cases where current joins imply broader membership than `files.session_id`?

---

## F) API surface + routing

- Do we want `GET /api/media-files` implemented now?
  - It’s currently `501 Not Implemented`, while docs imply it exists/planned.

- Should the API expose the same toggle as the UI?
  - `view=session|librarian` on:
    - `/db/database.php?format=json`
    - `/api/media-files`

- Do we want separate response schemas per view, or one response with `view` + different entry shapes?

---

## G) Documentation source-of-truth

- Confirmation: `docs/API_CURRENT_STATE.md` is published by GitHub Pages as `https://gighive.app/API_CURRENT_STATE.html`.

- Do we want `docs/openapi.yaml` to be the single source of truth for endpoints?
  - If yes, what is the process for keeping `API_CURRENT_STATE.md` aligned with it?

- Who “owns” keeping swagger + `API_CURRENT_STATE.md` in sync during beta (process question)?

---

## H) Operational / performance considerations

- Expected dataset size and performance requirements for librarian view
  - Grouping by checksum with aggregates can be heavy; do we need indexes/materialized summaries?

- Index/constraint decisions
  - Unique index on `assets.checksum_sha256`?
  - Unique constraints on relationship tables to prevent duplication?
