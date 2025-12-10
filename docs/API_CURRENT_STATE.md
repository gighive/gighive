# API Current State (Webroot)

This document describes the **current, implemented API behavior** under
`ansible/roles/docker/files/apache/webroot` and outlines planned enhancements.

---

## 1. Directory Overview

The Apache webroot for the API and UI is:

- `ansible/roles/docker/files/apache/webroot/`

Key subdirectories relevant to the API:

- `/api/`
  - Legacy entrypoints, currently only `uploads.php` is active.
- `/src/`
  - Modern MVC implementation (controllers, services, repositories, router).
- `/db/`
  - Upload forms and database listing pages.

---

## 2. Upload API Routing

### 2.1 MVC Router (`/src/index.php`)

The main Router lives in:

- `src/index.php`

Responsibilities:

- Boots the MVC stack (Composer autoload, `Database`, `UploadController`).
- Parses the incoming request method and URI.
- Normalizes the path by stripping `/src` or `/api` prefixes.
- Routes only **upload-related** paths:
  - `POST /src/uploads` → `UploadController::post($_FILES, $_POST)`
  - `GET  /src/uploads/{id}` → `UploadController::get($id)`
  - `POST /api/uploads` and `GET /api/uploads/{id}` are also supported,
    because the router strips both `/src` and `/api` prefixes.
- Handles the response formatting:
  - Default: JSON (`Content-Type: application/json`).
  - For HTML UI usage (when `ui=html` or `Accept` prefers `text/html` and
    the request is `POST`), renders a minimal HTML confirmation page with
    a link back to `/db/database.php`.
- Logs basic debug information (`[SRC_ROUTER_DEBUG]`) to help trace routing.

Net effect: **All modern upload behavior is implemented in the MVC layer and
routed through `src/index.php`.**

### 2.2 Legacy Shim (`/api/uploads.php`)

File:

- `api/uploads.php`

Current behavior:

- This file is now a **thin shim** that keeps the historical URL
  `/api/uploads.php` working while delegating to the new MVC router.
- It rewrites `$_SERVER['REQUEST_URI']` to a normalized `/api/uploads...`
  form and then includes `../src/index.php`.

Result:

- Requests to `/api/uploads.php` are ultimately handled by the same
  `UploadController` logic used by `/src/uploads`.
- This preserves backwards compatibility for existing clients that still
  post to `/api/uploads.php`.

---

## 3. Upload Forms (`/db/`)

There are two main upload forms under `/db/`:

1. **User-facing form**
   - File: `db/upload_form.php`
   - Purpose: simple upload UI for regular users.
   - Form action (current):
     - `action="/api/uploads.php"`

2. **Admin form**
   - File: `db/upload_form_admin.php`
   - Purpose: admin-focused upload UI with advanced fields.
   - Form action (current):
     - `action="/api/uploads.php"`

Important notes:

- Both forms still post to the **legacy URL** `/api/uploads.php`.
- Because `/api/uploads.php` is now a shim into `src/index.php`, the
  submissions are handled by the MVC upload logic.
- The plan documented in `docs/API_CLEANUP.md` suggested changing these
  actions to `/src/uploads`, but that specific change has **not yet been
  applied**. Compatibility is provided instead via the shim.

---

## 4. Database Listing (`/db/database.php`)

File:

- `db/database.php`

Behavior (high level):

- Provides the media listing page and JSON endpoint used by the UI and
  external clients.
- Supports an optional `?format=json` query parameter for JSON responses.
- This endpoint is referenced in the OpenAPI spec as the `GET /database.php`
  path (for JSON list behavior).

Note:

- This document is focused on upload routing; `database.php` remains the
  current, canonical implementation for listing media in both HTML and JSON
  formats.

---

## 5. Relationship to OpenAPI Spec and Swagger UI

The Swagger/OpenAPI documentation is served as static files under:

- `docs/api-docs.html` — Swagger UI HTML shell.
- `docs/openapi.yaml` — OpenAPI 3.0.3 specification.

Key points:

- `api-docs.html` loads Swagger UI from a CDN and points it at
  `./openapi.yaml`.
- `openapi.yaml` is a **hand-maintained** spec and describes:
  - `POST /uploads` (upload endpoint).
  - `GET /uploads/{id}`.
  - `GET /database.php` with `?format=json` behavior.
- Apache does **not** use the OpenAPI file for routing; it is documentation
  only. Actual routing is implemented in PHP (`/src/index.php` and related
  files).

---

## 6. Current Status vs API_CLEANUP Plan

The `docs/API_CLEANUP.md` plan outlined a migration from `/api/uploads.php`
into the MVC router and eventually into `/src/uploads` URLs.

What has been implemented:

- ✅ A minimal router exists at `src/index.php` and handles upload routes.
- ✅ `api/uploads.php` now delegates into `src/index.php` (shim), so both
  legacy and new-style URLs share the same `UploadController` logic.
- ✅ The MVC stack (controllers/services/repositories) is the single
  implementation of upload behavior.

What remains from the original plan:

- ⏳ Upload forms in `/db/` still point to `/api/uploads.php` instead of
  `/src/uploads`.
- ⏳ The `/api/` directory and `uploads.php` shim are still present and
  active; final cleanup/deletion has not yet occurred.

This document reflects the **actual, deployed state**, not just the plan.

---

## 7. Planned Enhancements

### 7.1 Deprecate `/api/uploads.php` and `/api/`

Goal:

- Move fully to MVC-style routing under `/src/` (and future `/media-files`)
  while preserving compatibility during a transition period.

Planned steps:

1. **Update upload forms to use `/src/uploads`**
   - Change `action` in `db/upload_form.php` and `db/upload_form_admin.php`:
     - From: `/api/uploads.php`
     - To:   `/src/uploads`
   - Verify that form submissions still succeed (since `src/index.php` is
     already the canonical handler for uploads).

2. **Maintain `/api/uploads.php` as a legacy alias temporarily**
   - Keep the shim file in place so any hard-coded clients using
     `/api/uploads.php` continue to work.
   - Optionally add logging to measure remaining usage of the legacy path.

3. **Document deprecation in OpenAPI spec**
   - In `docs/openapi.yaml`, mark the `/uploads` (or `/api/uploads.php`)
     path as `deprecated: true` once the new naming is introduced (see 7.2).

4. **Final cleanup (future)**
   - After you are confident no clients depend on `/api/uploads.php`:
     - Remove `api/uploads.php`.
     - Remove the `/api/` directory if it has no other responsibilities.
   - Update documentation to remove references to the legacy path.

### 7.2 Introduce `/media-files` GET/POST Endpoints

Goal:

- Provide clearer, more resource-oriented endpoints for media uploads and
  listings:
  - `POST /media-files` — upload a media file.
  - `GET  /media-files` — list media files (JSON).

Design principles:

- Keep existing behavior and data structures.
- Avoid breaking existing clients immediately.
- Use REST-style, plural resource naming instead of verb-heavy paths.

#### 7.2.1 PHP / Routing Plan

1. **Extend the router** (`src/index.php`)
   - Add new routes that map to the existing upload and listing logic:
     - `POST /media-files` → same behavior as `POST /uploads` today.
     - `GET  /media-files` → same JSON list behavior as
       `GET /database.php?format=json`.
   - Internally, ensure there is a **single implementation** of each
     behavior, with the new `/media-files` routes and the existing routes
     both calling into the same functions/services.

2. **Keep legacy URLs functioning**
   - Continue to support:
     - Uploads via `/src/uploads` (and `/api/uploads.php` during the
       deprecation period).
     - Listings via `/db/database.php` and `/db/database.php?format=json`.
   - This allows a gradual migration of clients and documentation without
     forcing a breaking change.

#### 7.2.2 OpenAPI Spec Plan

1. **Add new path definitions to `openapi.yaml`**
   - `POST /media-files`
     - Summary: "Upload media file".
     - Request body: reuse the schema from the current upload endpoint.
     - Responses: mirror existing upload responses (including file metadata
       plus session information, errors, etc.).
     - `operationId: uploadMediaFile`.

   - `GET /media-files`
     - Summary: "Get media file list".
     - Response: JSON array/object structure equivalent to what
       `database.php?format=json` returns today.
     - `operationId: getMediaFileList`.

2. **Mark legacy paths as deprecated**
   - For the existing upload path and `/database.php` list path in
     `openapi.yaml`, add `deprecated: true` once `/media-files` is present
     and documented as the preferred interface.
   - Update descriptions to state that `/media-files` is the preferred
     endpoint for new integrations.

#### 7.2.3 Documentation and Client Updates

1. **Docs**
   - Update relevant Markdown in `/docs` (e.g., upload options, request
     flow diagrams) to reference `/media-files` as the primary API.
   - Clearly indicate that older paths remain available for backward
     compatibility during a transition period.

2. **Client code**
   - When convenient, update any client integrations (curl examples, iOS
     app, admin tools) to call:
     - `POST /media-files` for uploads.
     - `GET  /media-files` for listings.

3. **Migration window**
   - Maintain both old and new paths for a defined period.
   - Once all important clients are updated and tested, consider:
     - Removing `deprecated` endpoints in a future major version, or
     - Keeping them as permanent aliases if the maintenance cost is low.

---

This document is intended to be the **authoritative reference** for what is
currently implemented in the webroot API and how we plan to evolve it toward
cleaner, REST-style endpoints while maintaining backward compatibility.
