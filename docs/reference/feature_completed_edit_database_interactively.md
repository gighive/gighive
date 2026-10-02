# Feature: Edit Media Library Rows Interactively (Admin-only)

## Problem / Motivation
The non-destructive “scan folder and add” workflow (Admin Section 5) is convenient for quickly ingesting new media with checksums, but it does not always populate *legacy-quality* metadata.

In particular, for StormPigs-style data entry the Media Library view can show:

- **Band or Event** (session org) as `default` instead of `StormPigs`
- **Song Name** in an undesirable format (e.g., derived labels vs. legacy naming conventions)
- **Musicians** empty (no `session_musicians` links created)

The legacy reload pipelines (Admin 3A/3B) can populate these fields as desired, but are **destructive** and are not a good fit for the common task of adding a single new jam.

This feature adds an **admin-only, human-friendly “edit-in-place” mode** to the Media Library page so that small metadata gaps from incremental ingest can be corrected immediately without a full reload.

## Goals

- Provide a **stopgap** workflow to correct metadata for a newly added session/jam.
- Keep the UX close to the existing `/db/database.php` table.
- Make edits **admin-only** and validated.
- Make it obvious when musician names are **new** and will be inserted into the database.
- Limit risk by allowing only **one active edit row at a time**.

## Non-goals

- No schema redesign.
- No bulk editing across many sessions.
- Not a replacement for a future “proper” metadata authoring workflow.

## Current Data Model (what the columns really mean)
The Media Library table is rendered from a join across `sessions`, `songs`, `files`, and relationship tables.

The target editable columns map to these database fields:

- **Band or Event**
  - Source: `sessions.org_name`
  - Scope: **session-level** (applies to all files/songs in that session)

- **Rating**
  - Source: `sessions.rating`
  - Scope: **session-level** (applies to all files/songs in that session)
  - App flavor: **`defaultcodebase` only**

- **Keywords**
  - Source: `sessions.keywords`
  - Scope: **session-level** (applies to all files/songs in that session)
  - App flavor: **`defaultcodebase` only**

- **Location**
  - Source: `sessions.location`
  - Scope: **session-level** (applies to all files/songs in that session)
  - App flavor: **`defaultcodebase` only**

- **Summary**
  - Source: `sessions.summary`
  - Scope: **session-level** (applies to all files/songs in that session)
  - App flavor: **`defaultcodebase` only**

- **Song Name**
  - Source: `songs.title`
  - Scope: **song-level** (applies anywhere that `song_id` is referenced)

- **Musicians**
  - Source in UI: `GROUP_CONCAT(musicians.name)` for the session
  - Storage: `session_musicians(session_id, musician_id)` with `musicians(musician_id, name)`
  - Scope: **session-level** (applies to the whole session)
  - App flavor: **`defaultcodebase` only**

## Rationale for adding `session_id` and `song_id` to the query
Each displayed row is not a single entity—it is a *join result* representing one (session, song, file) combination.

When the user edits:

- **Band or Event** they are editing a row in `sessions`.
- **Rating / Keywords / Location / Summary** they are editing fields in `sessions`.
- **Song Name** they are editing a row in `songs`.
- In **`defaultcodebase`**, **Musicians** edits update *relationships* in `session_musicians` and may insert into `musicians`.

The existing table rows carry `file_id` (as `id`) for deletion, download, etc., but `file_id` alone is not sufficient to update the correct `sessions` and `songs` rows without additional lookup queries and potential ambiguity.

Including these identifiers directly in the query output allows a clean, deterministic update API:

- `session_id` for session-scoped updates (`sessions.org_name`, `sessions.rating`, `sessions.keywords`, `sessions.location`, `sessions.summary`, `session_musicians`)
- `song_id` for song-scoped updates (`songs.title`)

Both IDs will be carried as **hidden inputs** / `data-` attributes and are not user-facing.

## Proposed UX (admin-only)

### Overview
- Add an **Edit** selection column (checkbox) for admins.
- Only **one** row can be active for editing at a time.
  - If multiple rows are checked, the UI will keep only one “active” and disable/clear the others.
  - This matches the underlying semantics: session-level changes would affect the whole session anyway.

### Live editing behavior
When a row is active:

- **Band or Event** cell becomes an editable textbox, prefilled with current `org_name`.
- In **`defaultcodebase` only**, **Rating**, **Keywords**, **Location**, and **Summary** become editable textboxes, prefilled from the current session metadata.
- **Song Name** cell becomes an editable textbox, prefilled with current `song_title`.
- In **`defaultcodebase` only**, **Musicians** becomes an editable textbox, prefilled with the current session musician list.
  - User enters a comma-separated list.

A **Save** button applies changes.

### “New musician” callouts (`defaultcodebase` only)
As the user types the musicians list, the UI will:

- Validate tokens (trim, dedupe, reject empties)
- Compare each token against the existing musician names known to the DB
- Display a callout showing which names already exist and which will be added, for example:
  - “Existing musician(s): Jules, Maximus”
  - “New musician(s) will be created: Snuffler, TBonk”

This provides the desired feedback before the save actually inserts anything.

### Post-save UI updates (Option A)
After a successful Save (server returns OK), light JavaScript will update the page immediately:

- Update the **active row** cells to show the saved values.
- Because **Band or Event** is session-level, also update that column for **all currently visible rows** in the table whose `session_id` matches the edited row.
- In **`defaultcodebase` only**, also update **Rating**, **Keywords**, **Location**, **Summary**, and **Musicians** across all currently visible rows with the same `session_id`.
- Because **Song Name** is song-level, also update that column for **all currently visible rows** whose `song_id` matches the edited row.

Rows not currently visible due to pagination or filtering will reflect changes on refresh/navigation.

## Validation (server-side)
All validation must be enforced server-side (JS is only a convenience).

- **org_name**
  - Required
  - Reasonable max length (e.g. 120)
  - Reject control characters

- **song_title**
  - Required
  - Reasonable max length
  - Reject control characters

- **rating** (`defaultcodebase` only)
  - Required
  - Reasonable max length
  - Reject control characters

- **keywords** (`defaultcodebase` only)
  - Required
  - Reasonable max length
  - Reject control characters

- **location** (`defaultcodebase` only)
  - Required
  - Reasonable max length
  - Reject control characters

- **summary** (`defaultcodebase` only)
  - Required
  - Reasonable max length
  - Reject control characters

- **musicians_csv** (`defaultcodebase` only)
  - Split on commas, trim whitespace
  - Drop empties
  - Dedupe case-insensitively
  - Validate each name token length and characters
  - Upsert missing musicians (Option 2)

## Database update behavior (transactional)
A single Save should run in a transaction:

1. Update session-level metadata
   - For all app flavors: `UPDATE sessions SET org_name = :org WHERE session_id = :sid`
   - In **`defaultcodebase` only**: also update `rating`, `keywords`, `location`, and `summary`

2. Validate the selected row mapping
   - Verify that the submitted `session_id` / `song_id` pair still exists in the joined session-song relationship before applying updates.

3. Update song title
   - `UPDATE songs SET title = :title WHERE song_id = :song_id`

4. Update musicians set for the session (**`defaultcodebase` only**)
   - Parse the requested musician names
   - For each name:
     - Find by name (case-insensitive match strategy to be decided)
     - If missing, insert into `musicians`
   - Replace session links:
     - `DELETE FROM session_musicians WHERE session_id = :sid`
     - Insert the desired `(session_id, musician_id)` rows

If any step fails validation or SQL execution, the transaction rolls back.

## Security
- Endpoint(s) must require Basic Auth user `admin` (same pattern used elsewhere in admin endpoints).
- Use prepared statements for all SQL.
- Avoid allowing edits by unauthenticated users even if they can load the page.

## Required code changes (planned)

### Query layer
- `src/Repositories/SessionRepository.php`
  - Add `sesh.session_id AS session_id`
  - Add `s.song_id AS song_id`
  - Ensure these are returned in both paged and filtered query variants used by the Media Library.

### View layer
- `src/Views/media/list.php`
  - Add an admin-only **Edit** checkbox column.
  - When a row is selected, render inputs for the app-flavor-appropriate editable columns.
  - In **`gighive`**, this is limited to `org_name` and `song_title`.
  - In **`defaultcodebase`**, this includes `rating`, `keywords`, `location`, `summary`, and `musicians`.
  - Include hidden inputs / data attributes for `session_id` and `song_id`.
  - Add a Save button and status area.

### New admin endpoints
- Add a small admin-only PHP endpoint under `db/` (e.g. `db/database_edit_save.php`)
  - Validates input
  - Runs transactional DB updates
  - Returns JSON for JS to show success/errors (or redirects—implementation choice)

- Add a “musician name preview” endpoint (`db/database_edit_musicians_preview.php`) for **`defaultcodebase`**
  - Accepts the typed list
  - Returns which names already exist and which will be new
  - This powers the “this guy is new” callout.

### Light JavaScript
- In `list.php`:
  - Enforce single active edit row
  - Enable/disable Save
  - In **`defaultcodebase`**, call preview endpoint on musicians field debounce to show new-name callout
  - Submit Save via fetch (or standard POST)

## Testing / Verification checklist
- As admin:
  - Select a row and verify only one row becomes editable
  - In **`gighive`**, edit `org_name` and `song_title`, then Save
  - In **`defaultcodebase`**, edit `org_name`, `rating`, `keywords`, `location`, `summary`, `song_title`, and `musicians`, then Save
  - Verify changes appear immediately in the active row after Save
  - Verify session-level changes propagate across all currently visible rows in that session
  - Verify song title changes propagate across all currently visible rows sharing that song
  - In **`defaultcodebase`**, verify musicians are linked for the whole session (all rows in that session show updated musicians)
  - In **`defaultcodebase`**, verify new musician callout appears before save
  - In **`defaultcodebase`**, verify new names get inserted into `musicians` on save

- As non-admin:
  - No edit controls rendered
  - Cannot call save endpoint (403)

## Rollback
- This is additive.
- If needed, disable by removing the edit column/UI and endpoints.
