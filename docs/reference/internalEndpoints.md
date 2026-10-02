# Internal Endpoints (Admin/Ops)

This document tracks **internal/admin/ops** HTTP endpoints that exist outside the public, versioned API surface documented in Swagger (`/docs/api-docs.html`).

These endpoints are primarily used by:
- `admin.php` (GigHive admin panel)
- manual operational tooling under `/db/*`

## Important notes

- These endpoints are **not** part of the public REST API contract.
- Many are **destructive** and intended only for administrators.
- Authentication is typically enforced by **Apache Basic Auth** plus an application-level gate that requires Basic-Auth user `admin`.
- Unless otherwise noted, responses are `application/json`.

## Authentication model

Most admin endpoints implement this pattern:
- Look for the username from one of:
  - `$_SERVER['PHP_AUTH_USER']`
  - `$_SERVER['REMOTE_USER']`
  - `$_SERVER['REDIRECT_REMOTE_USER']`
- Require the user to be exactly `admin`.

Apache configuration additionally protects many endpoints with `<Location>` rules.

## Admin panel

### `GET /admin.php`
- **Purpose**: GigHive admin panel UI.
- **Auth**: Admin-only (Basic Auth; `admin`).

## Database restore (async)

### `POST /db/restore_database.php`
- **Purpose**: Start an **asynchronous** MySQL restore from a selected backup file.
- **Auth**: Admin-only.
- **Request**: JSON
  - `filename` (string): basename only (no paths). Must match allowlist for current DB:
    - `${MYSQL_DATABASE}_latest.sql.gz`
    - `${MYSQL_DATABASE}_YYYY-MM-DD_HHMMSS.sql.gz`
  - `confirm` (string): must be exactly `RESTORE`
- **Response (200)**:
  - `success` (bool)
  - `job_id` (string)
  - `message` (string)
- **Behavior**:
  - Validates gzip integrity (`gzip -t`).
  - Streams `zcat` into `mysql`.
  - Writes logs to `${GIGHIVE_MYSQL_RESTORE_LOG_DIR}/restore-<job_id>.log`.

### `GET /db/restore_database_status.php?job_id=<id>&offset=<n>`
- **Purpose**: Poll restore progress/log output.
- **Auth**: Admin-only.
- **Query params**:
  - `job_id` (string): format `YYYYMMDD-HHMMSS-<12 hex>`
  - `offset` (int, optional): byte offset for incremental log reads
- **Response (200)**:
  - `success` (bool)
  - `job_id` (string)
  - `state` (string): `running` | `ok` | `error`
  - `exit_code` (int|null)
  - `offset` (int): new offset
  - `log_chunk` (string): additional log bytes

## Database import / load tools (admin)

These are used by `admin.php` to load/seed/replace database content.

### `POST /import_database.php`
- **Purpose**: Upload a single `database.csv`, preprocess, truncate tables, and reload DB.
- **Auth**: Admin-only.
- **Request**: `multipart/form-data`
  - `database_csv` (file)
- **Response**: JSON with `success`, `message`, `job_id`, and `steps[]`.
- **Concurrency control**:
  - Uses `/var/www/private/import_database.lock`.

### `POST /import_normalized.php`
- **Purpose**: Upload `sessions.csv` + `session_files.csv`, preprocess to normalized CSVs, truncate, and reload DB.
- **Auth**: Admin-only.
- **Request**: `multipart/form-data`
  - `sessions_csv` (file)
  - `session_files_csv` (file)
- **Response**: JSON with `success`, `message`, `job_id`, `steps[]`, and optional `table_counts`.
- **Concurrency control**:
  - Uses `/var/www/private/import_database.lock`.

### `POST /import_manifest_add.php`
- **Purpose**: Add files to DB (dedupe by checksum) and create label/song links.
- **Auth**: Admin-only.
- **Request**: JSON
  - `org_name` (string)
  - `event_type` (string)
  - `items` (array): objects containing `checksum_sha256`, `file_type`, `file_name`, `event_date`, optional `source_relpath`, `size_bytes`
- **Response**: JSON with inserted/duplicate counts and `steps[]`.

### `POST /import_manifest_reload.php`
- **Purpose**: Truncate tables and reload from a manifest payload (dedupe by checksum) and create label/song links.
- **Auth**: Admin-only.
- **Request**: JSON
  - `org_name` (string)
  - `event_type` (string)
  - `items` (array): same shape as `/import_manifest_add.php`
- **Response**: JSON with inserted/duplicate counts, `steps[]`, and optional `table_counts`.

## Database maintenance (admin)

### `POST /clear_media.php`
- **Purpose**: Truncate all media tables (preserves users table).
- **Auth**: Admin-only.
- **Request**: No body required.
- **Response**: JSON with `tables_cleared`.

## Host/VM operational requests (admin)

### `POST /write_resize_request.php`
- **Purpose**: Write a disk resize request file for later execution outside the web process.
- **Auth**: Admin-only.
- **Request**: JSON
  - `inventory_host` (string)
  - `disk_size_mb` (int, must be >= 16384)
- **Response**: JSON with `request_file` and echoed request payload.

## `/db/*` manual tooling pages

These are generally **UI/debug pages** and not part of the public API.

### `GET /db/upload_form.php`
- **Purpose**: Manual upload form for testing `/api/uploads.php`.
- **Auth**: Protected by Apache Basic Auth (admin + uploader). Page displays current user.

### `GET /db/upload_form_admin.php`
- **Purpose**: Admin version of upload form with advanced fields.
- **Auth**: Intended to be admin-only (Apache Basic Auth).

### `GET /db/database.php`
- **Purpose**: Render a database/media list view; supports `?format=json`.
- **Auth**: Protected by Apache Basic Auth (per Apache config).

### `GET /db/databaseFullView.php`
- **Purpose**: Render a full database/media list view.
- **Auth**: Protected by Apache Basic Auth (per Apache config).

### `GET /db/singlesRandomPlayer.php`
- **Purpose**: Random player/debug view.
- **Auth**: Protected by Apache Basic Auth (per Apache config).

### `GET /db/health.php`
- **Purpose**: DB health check endpoint.
- **Auth**: **No auth required** (explicitly documented in the file). Intended to return only success/failure.
- **Response**:
  - `status`: `ok` or `error`
  - `message`: human-friendly string

## Relationship to public Swagger API

The public API (Swagger) should remain focused on the stable application endpoints (e.g. under `/api/*`).

If you ever want OpenAPI coverage for these endpoints, the recommended approach is a **separate** internal-only OpenAPI document (admin-gated), rather than adding them to the public API docs.
