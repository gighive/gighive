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
| `POST` | `/import_manifest_upload_finalize.php` | `/admin` | ❌ `.php` extension, flat snake_case blob |

---

## Identified Inconsistencies

### 1. `.php` extension leakage

Two endpoints expose their backing file name as the public URL:
- `GET /db/database.php`
- `POST /admin/import_manifest_upload_finalize.php`

All `/api/*` endpoints have no extension. These two break that convention.

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

### 6. Schema vocabulary uses stale terms

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
| `POST /admin/import_manifest_upload_finalize.php` | `POST /admin/manifest/finalize` | **yes** (needs upload tool coord.) | mirrors `/uploads/finalize` pattern |
| `session_id` in schemas | `event_id` | **yes** — tracked in PR7 | canonical vocabulary |
| `seq` in schemas | `position` | **yes** — tracked in PR7 | canonical vocabulary |

---

## What PR7 Covers (Committed)

From `docs/pr_librarianAsset_musicianEvent_implementation.md` (PR7):

- Update `openapi.yaml` schemas to use `event_id`, `asset_id`, `event_item_id`, `position` in place of legacy field names.
- Keep `GET /api/media-files` as 501 (URL stable, no client change required).
- Keep `POST /api/media-files`, `POST /api/uploads`, `POST /api/uploads/finalize`, `GET /db/database.php` URLs stable (iPhone app must not break at the URL level from PR7 alone).
- Publish the updated `openapi.yaml` at PR7 so iPhone app and other clients have an accurate contract to code against.

**Field-level changes in PR7 that require client updates:**

| Legacy field | Canonical replacement | Affected endpoint |
|---|---|---|
| `session_id` | `event_id` | `POST /api/uploads`, `POST /api/uploads/finalize` |
| `seq` | `position` | same |
| session/song/file JSON shape | `asset_id`, `event_id`, `item_type`, `label`, `position` | `GET /db/database.php` listing |

Fields that survive unchanged: `checksum_sha256`, `file_type`, `duration_seconds`, `mime_type`, `size_bytes`.

---

## What Is Deferred (Post-PR7)

URL-level renames require coordinated server + client deployments and are explicitly out of scope for PR7:

- `GET /db/database.php` → `GET /api/media`
- `POST /admin/import_manifest_upload_finalize.php` → `POST /admin/manifest/finalize`
- `POST /api/media-files` alias retirement

These should be revisited once the canonical schema refactor (PR1–PR7) is complete and the iPhone app has been updated to consume the new field names.

---

**Date:** November 7, 2025 (original); updated April 2026  
**Priority:** URL cleanup deferred; schema field cleanup in PR7
