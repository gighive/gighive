# Media file deletion (database + disk)

This document describes the admin-only deletion feature that removes media file records from the database and deletes the corresponding media files from disk.

## Where it lives

- UI entry point:
  - `/db/database.php` ("Media Library")
  - Implemented in the media list view:
    - `ansible/roles/docker/files/apache/webroot/src/Views/media/list.php`
- Backend endpoint:
  - `/db/delete_media_files.php`
  - File:
    - `ansible/roles/docker/files/apache/webroot/db/delete_media_files.php`
- Apache access control (defense in depth):
  - Template:
    - `ansible/roles/docker/templates/default-ssl.conf.j2`
  - Endpoint is restricted to the `admin` user via Basic Auth.

## Authorization and visibility

- The delete UI (checkbox column + delete button) is only rendered when the authenticated user is `admin`.
- The endpoint also enforces admin-only access:
  - Apache blocks non-admins from reaching `/db/delete_media_files.php`.
  - PHP additionally verifies the authenticated user is exactly `admin`.

## UI flow

1. As `admin`, open `/db/database.php`.
2. A new first column appears with checkboxes.
   - Header checkbox selects/deselects all visible rows.
3. Select one or more rows.
4. Click `Delete Media File(s)`.
5. Confirm the dialog.
6. The UI issues a JSON `POST` request to `/db/delete_media_files.php` containing the selected `file_id` values.
7. On success, the page reloads.

## API contract

- Method: `POST`
- Content type: `application/json`
- Request body:

```json
{ "file_ids": [123, 456] }
```

- Response:
  - Always JSON.
  - HTTP 200 on a normal run (even if some IDs fail), HTTP 4xx/5xx for request/auth/server errors.
  - Response includes:
    - `deleted_count`
    - `error_count`
    - `results.deleted` and `results.errors` arrays

## Deletion semantics

Deletion is performed per `file_id` with these goals:

- Remove the `files` row from the database.
- Rely on FK cascade to remove join rows in `song_files`.
- Delete the media file from disk using **checksum-based filenames only**.
- For video files, also delete the associated thumbnail.

### Checksum-only disk deletion

The endpoint will only attempt disk deletion when:

- `checksum_sha256` exists and matches a strict SHA-256 hex pattern (`64` hex chars).

This prevents accidental deletion of arbitrary paths.

### Computing the served filename

The served on-disk filename is derived as:

- `<checksum_sha256>.<ext>`

where `ext` is determined from:

- `source_relpath` file extension (preferred), falling back to
- `file_name` extension

### Disk locations (inside container)

- Audio files:
  - `/var/www/html/audio/<sha>.<ext>`
- Video files:
  - `/var/www/html/video/<sha>.<ext>`
- Video thumbnails:
  - `/var/www/html/video/thumbnails/<sha>.png`

### Order of operations

For each file:

1. Load the row from `files`.
2. Validate checksum and file type.
3. Delete media file from disk (if it exists).
4. If video, delete thumbnail from disk (if it exists).
5. Delete the database row:

```sql
DELETE FROM files WHERE file_id = :id
```

This triggers FK cascade deletion from `song_files`.

If disk deletion fails for an existing file, the endpoint records an error for that `file_id` and does not delete the DB row for that ID.

## Why a deleted item can still appear in the Media Library

The Media Library query joins session/song data to file data. If the query uses `LEFT JOIN` for `song_files`/`files`, you can see rows where:

- session/song columns are populated
- file columns are `NULL` (because the file was deleted)

This is not a "null record" in the database. It is simply the result of a left-join with no matching row.

### Current behavior

The Media Library list is intended to show only media items (rows with a file). To enforce this, the repository now includes a filter:

- `f.file_id IS NOT NULL`

so songs/sessions without an attached file do not render as empty media rows.

## Files changed / added

- UI:
  - `ansible/roles/docker/files/apache/webroot/src/Views/media/list.php`
- Endpoint:
  - `ansible/roles/docker/files/apache/webroot/db/delete_media_files.php`
- Apache restriction:
  - `ansible/roles/docker/templates/default-ssl.conf.j2`
- Media list filtering to hide missing-file rows:
  - `ansible/roles/docker/files/apache/webroot/src/Repositories/SessionRepository.php`
