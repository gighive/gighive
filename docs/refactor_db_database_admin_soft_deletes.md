# Refactor: Admin soft deletes in Media Library (DB-only)

## Context
Today, the Media Library “Delete Media File(s)” action calls `POST /db/delete_media_files.php` which:

- Deletes the physical media bytes from disk (`unlink` in `/var/www/html/audio` or `/var/www/html/video`)
- Deletes the `files` row from MySQL

This is appropriate for true purges but is risky for admins doing routine cleanup (easy to remove bytes unintentionally).

Separately, we have tooling to re-register/reload media by checksum (e.g., `apache/webroot/tools/upload_media_by_hash.py`), so accidental hard deletes can sometimes be recovered, but that is still operationally costly.

## Goal
Add an **admin-only soft delete** path that:

- Removes media from normal listings and search results (Media Library / playback surfaces)
- Does **not** delete the physical file bytes
- Is reversible (“restore” / “undelete”)

## Non-goals
- Replacing the existing hard-delete endpoint.
- Changing uploader delete-token semantics.
- Implementing a full audit subsystem (nice-to-have later).

## Proposed design

### A) Database schema
Add a soft-delete marker to the `files` table (preferred):

- `deleted_at` `DATETIME NULL`
- `deleted_by` `VARCHAR(64) NULL` (e.g., `admin`)
- Optional: `delete_reason` `VARCHAR(255) NULL`

Alternative (if schema changes are difficult):
- Add a boolean `is_deleted TINYINT(1) NOT NULL DEFAULT 0`

### B) Query behavior
Update the read paths used by Media Library and other listing endpoints to exclude soft-deleted rows by default:

- Default filter: `WHERE deleted_at IS NULL`
- Admin-only UI can add “Show deleted” toggle (`include_deleted=1`) for discovery / restore.

### C) API / endpoints

1) New endpoint: `POST /db/soft_delete_media_files.php` (admin only)
   - Input: `{ file_ids: [1,2,3], reason?: "..." }`
   - Action:
     - Marks rows as deleted (sets `deleted_at`, `deleted_by`, optional reason)
     - Does **not** call `unlink`

2) New endpoint: `POST /db/restore_soft_deleted_media_files.php` (admin only)
   - Input: `{ file_ids: [...] }`
   - Action:
     - Clears `deleted_at`/`deleted_by`/reason

3) Keep existing endpoint: `POST /db/delete_media_files.php`
   - Continue to perform irreversible deletion of bytes + DB row.
   - Consider adding an additional confirm mechanism later (optional).

### D) UI changes
In `src/Views/media/list.php` for admins:

- Change the existing “Delete Media File(s)” flow into a small chooser:
  - **Soft delete (recommended)**
  - **Hard delete (purge bytes)**

- Add:
  - “Show deleted” checkbox / filter
  - “Restore selected” button when viewing deleted items

### E) Edge cases / considerations
- Soft-deleted records must not be playable/downloadable from normal UI.
- If hard-delete is called on a soft-deleted row, it should still purge bytes and remove the row.
- If physical bytes are already missing, soft delete should still work (it’s DB-only).

## Migration plan

1) Add schema change (migration SQL) and deploy.
2) Update repositories/controllers to default-exclude deleted rows.
3) Add soft-delete and restore endpoints.
4) Update Media Library admin UI.
5) Validate:
   - Soft delete hides items
   - Restore re-exposes items
   - Hard delete still purges bytes

## Testing / verification
- Unit/integration test (or manual checklist) for:
  - Soft delete + restore
  - Filtering behavior (default excludes)
  - Admin include_deleted view
  - Hard delete still removes bytes

## Notes
- This refactor is primarily to prevent accidental byte deletion.
- Recovery tooling (`tools/upload_media_by_hash.py`) remains useful, but should not be the primary safety mechanism.
