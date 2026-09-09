# Feature: JWT Endpoint Guard Checklist

**Status:** Planning — decisions pending review  
**Date:** 2026-09-07  
**Parent doc:** `docs/feature_security_authentication_migration_jwt_implementation.md`  
**Related docs:** `docs/feature_completed_saas_model_changes.md` (SaaS Step 8 — RBAC middleware)  
**Live reference:** [`docs/ui_role_matrix.html`](ui_role_matrix.html)

---

## Elevator Pitch

When JWT auth lands, every endpoint needs a role guard — a single line that says "this route requires at least X level of access." Right now that decision is easy for most pages: viewer pages get `viewer`, upload pages get `contributor`, admin pages get `owner`. But 27 pages are currently shared between the Local Admin and the platform Tenant Admin, and they need an explicit ruling on which role they ultimately belong to before the SaaS RBAC layer ships. This document captures those decisions and becomes the source of truth that drives the RBAC middleware implementation.

---

## Overview

The `ui_role_matrix.html` maps every GigHive page and screen to its current access pattern across four roles (Viewer, Uploader, Local Admin, Tenant Admin). That matrix is the planning input. This document is the output: a concrete guard assignment for every endpoint, with special focus on the **27 shared LA+TA endpoints** that must be decomposed before SaaS RBAC can be enforced.

### Role Hierarchy (Phase 2 → SaaS)

The JWT implementation (`feature_security_authentication_migration_jwt_implementation.md`) establishes three roles:

| Value | Role | Maps to |
|---|---|---|
| `1` | `viewer` | Authenticated browser |
| `2` | `contributor` | Uploader |
| `3` | `owner` | Local Admin — event organizer managing their own tenant |

The SaaS RBAC layer (Step 8 of `feature_completed_saas_model_changes.md`) adds a fourth:

| Value | Role | Maps to |
|---|---|---|
| `4` | `platform_admin` | Tenant Admin — platform owner managing infrastructure and all tenants |

`requireRole('platform_admin')` is an extension of the existing hierarchy — it uses the same helper function pattern already established in `auth/helpers.php`.

### Guard Assignment Rules

Every endpoint falls into one of five statuses. These are reflected in the **Status column** of `ui_role_matrix.html`:

| Status | Meaning | Phase 2 Guard | SaaS RBAC Guard |
|---|---|---|---|
| `current` | Single-role, clear assignment, no change needed | As planned in JWT impl doc | Same |
| `la-only` | Shared today → reassign to Local Admin only | `requireRole('owner')` | `requireRole('owner')` + tenant scope enforced |
| `ta-only` | Shared today → reassign to Tenant Admin only | `requireRole('owner')` (interim) | `requireRole('platform_admin')` |
| `needs-split` | Same URL today, different scope per role → two surfaces required | `requireRole('owner')` (interim) | Route branches on role; separate API responses or pages |
| `new` | Planned — does not exist yet; design in the correct guard from the start | — | Per SaaS feature doc |

---

## Migration Blast Radius

Of the ~118 pages and endpoints in the role matrix, only **8 are not impacted** by the JWT auth migration. Everything else requires code changes — either a server-side guard, a client-side bearer token change, or both.

### Pages NOT Impacted by the Migration

These pages require no auth-related changes before or after JWT deployment:

| Page | Reason untouched |
|---|---|
| `/index.php` | Public — no auth now, no auth after JWT |
| `/loops.php` | Public — no auth now, no auth after JWT |
| `/guest_event_view.php` | QR nonce URL — JWT implementation explicitly states "QR-code event access is untouched" |
| `/api/guest-gallery.php` | QR nonce auth path, not Basic Auth |
| `/api/guest-status.php` | QR nonce |
| `/api/guest-delete.php` | QR nonce |
| `/api/guest-report.php` | QR nonce |
| `/api/guest-stream.php` | QR nonce |
| `/timeline/timeline-api.php` | Public — intentionally unauthenticated; feeds the homepage timeline widget; `Access-Control-Allow-Origin: *`; no Apache auth block |

The `/mail/register.php` and `/mail/handle_register.php` stubs are planned SaaS pages that do not yet enforce auth; their final auth model will be designed when those pages are built.

See **Non-Shared Endpoint Guard Reference** below for the complete guard assignment list for all other pages.

---

### Impact Type 1 — Server-side Guard Changes (PHP Files)

Every PHP file not in the list above needs a `requireRole()` call added in Phase 2. This is because most admin pages currently have **no PHP-level auth at all** — they rely entirely on Apache's `AuthType Basic` location block. When Phase 4 removes that block, any file that did not receive a PHP guard in Phase 2 becomes completely unprotected.

**Worker files are the specific risk.** Files like `*_worker.php` are background processes typically invoked by AJAX from the admin UI, but they may also be directly accessible via HTTP. They live under `/admin/` and receive Apache Basic Auth protection today, but any worker invoked via `exec()` or `shell_exec()` internally bypasses Apache entirely and currently has **zero auth**. Phase 2 must explicitly include all worker files — not just UI pages — in the `requireRole('owner')` sweep.

The JWT implementation doc specifies `admin/*.php` (42 files) receive `requireRole('owner')` in Phase 2. Verify that the file count includes all worker files, not only the navigable UI pages.

---

### Impact Type 2 — Client-side Bearer Token Changes (iOS + JavaScript)

Any component that currently constructs an HTTP request with Basic Auth credentials must switch to `Authorization: Bearer <token>`. This covers two surfaces:

**iOS app** — `AuthSession.swift`, `LoginView.swift`, `SplashView.swift`, and every `URLRequest` that currently adds Basic Auth headers, including the Share Extension. These are already enumerated in the JWT implementation doc (Phase 3).

**JavaScript AJAX in admin and DB pages** — Every `fetch()` or `XMLHttpRequest()` call inside admin pages that hits an authenticated endpoint (catalog status polling, manifest jobs, export status, AI worker status, etc.) currently relies on the browser forwarding the Apache Basic Auth session. After Phase 4 removes Basic Auth, those JS calls break unless they send a Bearer token.

The JavaScript impact is the easiest to miss because it is not a PHP file — it is inline `<script>` blocks or loaded `.js` files within the admin UI pages. Before Phase 4 ships, every AJAX endpoint consumed by the admin UI must:

1. Accept and validate a Bearer token on the PHP side (`requireRole()`)
2. Have the calling JavaScript retrieve the stored JWT (from `localStorage` or a short-lived session cookie) and attach it as `Authorization: Bearer <token>`

The audit is enumerated in section 2c of `feature_security_authentication_migration_jwt_implementation.md` (Phase 2 Companion). The full 20-step implementation plan is in `docs/refactor_security_authentication_shared_auth_function.md` — **a prerequisite for Phase 4**.

---

## The 27 Shared Endpoints — Decisions

The actual count from `ui_role_matrix.html` is **27** (25 existing + 2 planned). The earlier estimate of 24 was approximate.

### Rationale Framework

Three questions determine each endpoint's final assignment:

1. **Is this about content?** Importing, exporting, tagging, managing media → Local Admin. Content belongs to the tenant.
2. **Is this about the platform?** DB backups, restores, server resize, platform diagnostics → Tenant Admin. The platform is the operator's responsibility.
3. **Are both valid but at different scopes?** Stats, account management, AI queue → Needs split. Same concept, different scope (tenant vs. platform-wide).

---

### Group A — la-only (11 endpoints)

These are content operations. Local Admin owns them; Tenant Admin does not need direct access (platform-level equivalents exist separately, e.g. platform backup is not the same as content export).

| Endpoint | Rationale |
|---|---|
| `/db/ai_tags.php` | Per-asset AI tag retrieval — tenant-scoped content operation |
| `/admin/import_media_zip.php` | Content import — the organizer imports their own media library |
| `/admin/import_media_zip_scan_status.php` | Part of LA import flow |
| `/admin/import_media_zip_scan_worker.php` | Part of LA import flow |
| `/admin/import_media_zip_status.php` | Part of LA import flow |
| `/admin/import_media_zip_worker.php` | Part of LA import flow |
| `/admin/import_normalized.php` | Staging/import — content management, LA job |
| `/admin/export_media.php` | Content export — organizer exports their own data (TAR download) |
| `/admin/export_media_status.php` | Part of LA export flow |
| `/admin/export_media_download.php` | Part of LA export flow |
| `/admin/export_media_worker.php` | Part of LA export flow |

**Phase 2 guard:** `requireRole('owner')` (no change from blanket admin guard)  
**SaaS RBAC guard:** `requireRole('owner')` + filter all queries by `tenant_id`

---

### Group B — ta-only (7 endpoints)

These are platform-level database operations. In a multi-tenant SaaS environment, a Local Admin cannot back up or restore the shared database — those are platform-level responsibilities. LA data portability is served by the export flow (Group A above), not raw DB dumps.

| Endpoint | Rationale |
|---|---|
| `/admin/run_backup.php` | Platform DB backup — LA has no concept of "dump the shared DB" |
| `/admin/run_backup_status.php` | Part of TA backup flow |
| `/admin/download_backup.php` | Platform backup download — operator artifact, not tenant artifact |
| `/admin/restore_database.php` | Full DB restore — platform-level destructive op, never tenant-scoped |
| `/admin/restore_database_status.php` | Part of TA restore flow |
| `/admin/upload_restore_backup.php` | Platform backup upload for restore |
| `/admin/import_database.php` | In-place platform DB import |

**Phase 2 guard:** `requireRole('owner')` (interim — Tenant Admin is also an `owner` today)  
**SaaS RBAC guard:** `requireRole('platform_admin')`  
**Note:** These endpoints should move to a dedicated `/platform/` route prefix at SaaS Step 8 to make the access boundary visually explicit and enforce it at the Apache level before PHP is even reached.

---

### Group C — needs-split (9 endpoints)

These endpoints serve both roles legitimately but at different scopes. The same URL cannot serve both without branching on the caller's role.

| Endpoint | LA view | TA view |
|---|---|---|
| `/admin/admin_system.php` | Tenant dashboard: their media stats, clear their data, their export/import controls | Platform dashboard: all-tenant stats, server health, resize controls — completely different UI |
| `/admin/admin_system_stats.php` | Returns stats scoped to `tenant_id` | Returns platform-wide aggregate stats |
| `/admin/admin.php` | Manage viewer/uploader accounts for their own events (future: invite links) | Manage platform credentials; future: create/suspend tenant accounts |
| `/admin/clear_media.php` | Clear this tenant's media records from the DB | Clear any tenant's records (account termination / GDPR cascade) |
| `/admin/clear_media_files.php` | Delete this tenant's files from storage | Delete any tenant's files from storage |
| `/admin/ai_worker.php` | View AI tagging progress for their own content; trigger tagging for their assets | Manage the shared AI service: global queue, LLM model config, provider settings |
| `/api/ai_jobs.php` | CRUD scoped to `tenant_id` AI jobs | Platform-wide AI job management; adjust priority, pause queue |
| Storage quota dashboard *(planned)* | Shows their own storage used vs. plan limit | Shows all-tenant storage, aggregate costs, quota enforcement toggle |
| Per-tenant data export / GDPR delete *(planned)* | Requests export of their own data or deletes their own account | Executes export for any tenant; cascades hard-delete for GDPR compliance |

**Implementation approach for split endpoints:**
- **Short term (SaaS Step 8 interim):** Add a `requireRole` check at the top; if `platform_admin`, serve the broader-scope response. If `owner`, serve the tenant-scoped response. One endpoint, branched logic.
- **Long term:** Separate the TA surface to `/platform/` routes for clarity and Apache-level enforcement.

---

## Phase 2 Blanket Guard (Current Priority)

The JWT implementation doc already specifies this: all `admin/*.php` files receive `requireRole('owner')` in Phase 2, as a blanket guard replacing the `$user !== 'admin'` htpasswd check. That is correct and should not wait for the SaaS split decisions to be finalised.

The SaaS split (Groups A/B/C above) happens at SaaS Step 8, layered on top of the Phase 2 guard. The Phase 2 guard is NOT wrong for shared endpoints — it just does not distinguish LA from TA yet, which is acceptable until Step 8.

---

## Non-Shared Endpoint Guard Reference

For completeness. All of these are unambiguous and already planned in the JWT implementation doc.

| Category | Guard |
|---|---|
| Public pages (`/index.php`, `/loops.php`, `/guest_event_view.php`) | None (unauthenticated) |
| Guest API (`/api/guest-*.php`) | QR nonce — no JWT |
| Viewer pages (`/db/database.php`, `/db/singlesRandomPlayer.php`, `/db/tag_browser.php`) | `requireRole('viewer')` |
| Upload forms (`/db/upload_form.php`, `/db/upload_form_single.php`) | `requireRole('contributor')` |
| Uploader APIs (`/api/uploads.php`, `/api/tus-upload.php`, `/api/uploads/*` via `src/index.php` MVC router) | `requireRole('contributor')` — `src/index.php` uses QR token validation in `UploadController`; same auth model as `api/uploads.php` |
| Media stream (`/api/media-stream.php`) | `requireRole('viewer')` |
| Admin-only DB pages (`/db/upload_form_admin.php`, `/db/media_tags.php`, `/db/database_catalog.php`, edit/delete endpoints) | `requireRole('owner')` |
| QR code management (`/admin/event_qr.php`) | `requireRole('owner')` |
| All import flows (iPhone, folder, CSV, manifest) | `requireRole('owner')` |
| Catalog operations | `requireRole('owner')` |
| Debug pages (`/debug/*`) | `requireRole('platform_admin')` |
| Registration (`/mail/register.php`) | None (public, rate-limited) |
| iOS screens | Guard applied at API layer; screen-level gating mirrors role from JWT claim |

---

## Open Questions

- [ ] **JavaScript AJAX audit** — Audit complete; full 20-step implementation plan in `docs/refactor_security_authentication_shared_auth_function.md` **(prerequisite for Phase 4)**. Decision (2026-09-07): localStorage + Bearer token (Option B). 15 files identified: 1 new (`auth/gh-auth.js`), 14 modified across `admin/`, `db/`, and `src/Views/`. Smoke tests T-151–T-156 defined. Implementation pending approval.
- [ ] **`db/delete_media_files.php`** — The JWT impl doc lists `requireRole('contributor')`. Given it deletes files, `requireRole('owner')` seems more appropriate. Confirm intended guard before Phase 2 lands.
- [ ] **`db/database_catalog.php`** — JWT impl doc lists `requireRole('viewer')`. This is an admin-only catalog browser. Confirm `requireRole('owner')` is the correct assignment.
- [ ] **`/platform/` route prefix** — Confirm whether TA-only endpoints move to a new `/platform/` prefix at SaaS Step 8, or stay under `/admin/` with guard-only enforcement. This affects the Apache `Location` block structure.
- [ ] **`platform_admin` role value** — Confirm `4` extends the existing `auth/helpers.php` hierarchy cleanly. No other value conflicts expected, but verify before implementation.

---

## Tests

All endpoint guard assignments must have corresponding smoke tests. The pattern is already established in `post_build_checks/tasks/main.yml`:

- **Unauthenticated GET** → `status_code: 401` (proves the file landed and the guard fires)
- **Wrong-role request** → `status_code: 403` (proves role enforcement is correct)
- **Correct-role request** → `status_code: 200` (proves the happy path works)

New tests are required at SaaS Step 8 for the `platform_admin` guard on the Group B endpoints. Those tests should use a dedicated `platform_admin` test credential stored in ansible-vault.
