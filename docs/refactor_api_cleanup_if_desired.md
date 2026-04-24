# API Naming Consistency — Issues & Cleanup Plan

## Status

**Status:** 📋 Analysis complete — cleanup planned under PR7 of the canonical schema refactor  
**Related plan:** `docs/pr_librarianAsset_musicianEvent_implementation.md` (PR7: Docs cleanup / alignment)  
**Last reviewed:** April 2026

---

## Current API Surface (as-of April 2026)

| Method | Path | Server prefix | Notes |
|---|---|---|---|
| `POST` | `/uploads` | `/api` | ✅ clean |
| `GET` | `/uploads/{id}` | `/api` | ✅ clean |
| `POST` | `/uploads/finalize` | `/api` | ✅ clean |
| `GET` | `/media-files` | `/api` | ⚠️ stub (501), wrong resource name |
| `POST` | `/media-files` | `/api` | ⚠️ iOS alias for `POST /uploads` — duplicate path |
| `GET` | `/database.php` | `/db` | ❌ `.php` extension, misnamed, wrong prefix |
| `POST` | `/delete_media_files.php` | `/db` | ❌ `.php` extension, wrong prefix; field names use `file_id`/`file_ids` (legacy) |
| `POST` | `/database_edit_save.php` | `/db` | ❌ `.php` extension, wrong prefix; field names use `session_id`, `song_id`, `song_title`, `musicians_csv` (all legacy) |
| `POST` | `/database_edit_musicians_preview.php` | `/db` | ❌ `.php` extension, wrong prefix; field name uses `musicians_csv` (legacy) |
| `POST` | `/import_manifest_upload_finalize.php` | `/admin` | ❌ `.php` extension, flat snake_case blob |

---

## Identified Inconsistencies

### 1. `.php` extension leakage

Five endpoints expose their backing file name as the public URL:
- `GET /db/database.php`
- `POST /db/delete_media_files.php`
- `POST /db/database_edit_save.php`
- `POST /db/database_edit_musicians_preview.php`
- `POST /admin/import_manifest_upload_finalize.php`

All `/api/*` endpoints have no extension. These break that convention. URL-level fixes are deferred post-PR7.

### 2. Duplicate upload resource under two different names

- `POST /api/uploads` — canonical upload endpoint
- `POST /api/media-files` — "iOS alias" doing the exact same thing

Two public paths for the same resource/action is a REST anti-pattern. The alias exists for iOS client compatibility and should eventually be retired or collapsed to an Apache alias rather than a distinct documented path.

### 3. `GET /api/media-files` stub is in the wrong group and wrong name

The 501 stub is tagged under **uploads** in the OpenAPI spec but describes a listing/query operation, not an upload. It also conflicts with the upload-centric `/uploads` naming. Per PR7, this endpoint remains 501 and the name is kept stable for now (no client breakage), but it should not be the canonical listing path long-term.

### 4. `/db/database.php` is misnamed at every level

- The `database` tag and `database.php` path imply DB administration, but the endpoint **lists media files with metadata** — it is a media listing endpoint.
- It lives under the `/db` server prefix rather than `/api`, adding inconsistency.
- Per PR7 (canonical schema refactor), the listing endpoint is being updated to serve from canonical `assets`/`events`/`event_items` tables; that is the right moment to also clean the name.

### 5. `/admin/import_manifest_upload_finalize.php` is verbose and inconsistent

Compare naming patterns:
- `POST /api/uploads/finalize` — clean, hierarchical ✅
- `POST /admin/import_manifest_upload_finalize.php` — flat, snake_case, with extension ❌

A consistent name would be `/admin/manifest/finalize` (mirrors `/uploads/finalize`).

### 6. `/db/delete_media_files.php` — wrong prefix, stale field names, `.php` extension

- Lives under `/db` instead of `/api`; should be a resource action on `assets`.
- Request body uses `file_id`/`file_ids` (legacy `files` table concept) → should be `asset_id`/`asset_ids` after PR4 cutover.
- Response result objects return `file_id` → should return `asset_id`.
- A clean REST shape would be `DELETE /api/assets/{id}` or at minimum `POST /api/assets/delete`.
- The `delete_token` uploader flow is a keeper; only the field name and backing table change.

### 7. `/db/database_edit_save.php` and `/db/database_edit_musicians_preview.php` — stale field names, wrong prefix, `.php` extension

These admin inline-edit endpoints have request and response field names tied entirely to legacy vocabulary:

- `database_edit_save.php` request: `session_id`, `song_id`, `song_title`, `musicians_csv` → hard-cutover to `event_id`, `event_item_id`, `item_label`, `participants_csv`.
- `database_edit_save.php` response: `session_id`, `song_id`, `song_title`, `musicians`, `new_musicians` → `event_id`, `event_item_id`, `item_label`, `participants`, `new_participants`.
- `database_edit_musicians_preview.php` request: `musicians_csv` → `participants_csv`; response arrays (`existing`, `new`, `normalized`) shape stays.
- Both live under `/db` (should be `/api`) and expose `.php` extensions (URL rename deferred post-PR7).

### 8. Schema vocabulary uses stale terms

`openapi.yaml` response schemas still use:
- `session_id` → should be `event_id` (canonical vocabulary)
- `seq` → should be `position`

This is tracked as a required change in PR7 of `docs/pr_librarianAsset_musicianEvent_implementation.md`.

---

## Proposed Clean Naming

| Current | Proposed | Breaking? | Notes |
|---|---|---|---|
| `POST /api/uploads` | `POST /api/uploads` | no | ✅ keep |
| `GET /api/uploads/{id}` | `GET /api/uploads/{id}` | no | ✅ keep |
| `POST /api/uploads/finalize` | `POST /api/uploads/finalize` | no | ✅ keep |
| `GET /api/media-files` (501 stub) | keep as 501, rename deferred | no | per PR7 decision |
| `POST /api/media-files` (iOS alias) | deprecate path; Apache alias only | **yes** (needs iPhone app coord.) | remove from public docs once iOS updated |
| `GET /db/database.php` | `GET /api/media` | **yes** (needs client coord.) | listing endpoint; deferred to post-PR7 |
| `POST /db/delete_media_files.php` | `DELETE /api/assets/{id}` or `POST /api/assets/delete` | **yes** (needs client coord.) | URL deferred; field rename tracked in PR7 |
| `POST /admin/import_manifest_upload_finalize.php` | `POST /admin/manifest/finalize` | **yes** (needs upload tool coord.) | mirrors `/uploads/finalize` pattern |
| `session_id` in schemas | `event_id` | **yes** — tracked in PR7 | canonical vocabulary |
| `seq` in schemas | `position` | **yes** — tracked in PR7 | canonical vocabulary |
| `file_id`/`file_ids` in delete body | `asset_id`/`asset_ids` | **yes** — tracked in PR4/PR7 | canonical vocabulary |
| `session_id` in edit-save body/response | `event_id` | **yes** — tracked in PR3 | canonical vocabulary |
| `song_id` in edit-save body/response | `event_item_id` | **yes** — tracked in PR3 | canonical vocabulary |
| `song_title` in edit-save body/response | `item_label` | **yes** — tracked in PR3 | canonical vocabulary |
| `musicians_csv` in edit-save + preview request | `participants_csv` | **yes** — tracked in PR3 | canonical vocabulary |
| `musicians`/`new_musicians` in edit-save response | `participants`/`new_participants` | **yes** — tracked in PR3 | canonical vocabulary |

---

## What PR7 Covers (Committed)

From `docs/pr_librarianAsset_musicianEvent_implementation.md` (PR7):

- Update `openapi.yaml` schemas to use `event_id`, `asset_id`, `event_item_id`, `position` in place of legacy field names.
- Keep `GET /api/media-files` as 501 (URL stable, no client change required).
- Keep `POST /api/media-files`, `POST /api/uploads`, `POST /api/uploads/finalize`, `GET /db/database.php` URLs stable (iPhone app must not break at the URL level from PR7 alone).
- Publish the updated `openapi.yaml` at PR7 so iPhone app and other clients have an accurate contract to code against.

**Field-level changes in PR3 (listing cutover + inline-edit port):**

| Legacy field | Canonical replacement | Affected endpoint |
|---|---|---|
| session/song/file JSON shape | `asset_id`, `event_id`, `item_type`, `label`, `position` | `GET /db/database.php` listing |
| `session_id`, `song_id`, `song_title`, `musicians_csv` (request) | `event_id`, `event_item_id`, `item_label`, `participants_csv` | `POST /db/database_edit_save.php` |
| `session_id`, `song_id`, `song_title`, `musicians`, `new_musicians` (response) | `event_id`, `event_item_id`, `item_label`, `participants`, `new_participants` | `POST /db/database_edit_save.php` |
| `musicians_csv` (request) | `participants_csv` | `POST /db/database_edit_musicians_preview.php` |

**Field-level changes in PR4 (upload + delete port):**

| Legacy field | Canonical replacement | Affected endpoint |
|---|---|---|
| `file_id`/`file_ids` (request body) | `asset_id`/`asset_ids` | `POST /db/delete_media_files.php` |
| `file_id` (response result objects) | `asset_id` | `POST /db/delete_media_files.php` |

**Field-level changes in PR7 (openapi.yaml + docs) that require client updates:**

| Legacy field | Canonical replacement | Affected endpoint |
|---|---|---|
| `session_id` | `event_id` | `POST /api/uploads`, `POST /api/uploads/finalize` |
| `seq` | `position` | same |

Fields that survive unchanged: `checksum_sha256`, `file_type`, `duration_seconds`, `mime_type`, `size_bytes`.

---

## What Is Deferred (Post-PR7)

URL-level renames require coordinated server + client deployments and are explicitly out of scope for PR7:

- `GET /db/database.php` → `GET /api/media`
- `POST /db/delete_media_files.php` → `DELETE /api/assets/{id}` (or `POST /api/assets/delete`)
- `POST /db/database_edit_save.php` → `PATCH /api/events/{event_id}/items/{event_item_id}` (or similar REST shape)
- `POST /db/database_edit_musicians_preview.php` → `POST /api/participants/preview` (or similar)
- `POST /admin/import_manifest_upload_finalize.php` → `POST /admin/manifest/finalize`
- `POST /api/media-files` alias retirement

These should be revisited once the canonical schema refactor (PR1–PR7) is complete and the iPhone app has been updated to consume the new field names.

---

**Date:** November 7, 2025 (original); updated April 2026  
**Priority:** URL cleanup deferred; schema field cleanup in PR7
