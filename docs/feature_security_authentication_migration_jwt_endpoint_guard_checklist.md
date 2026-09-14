# Feature: JWT Endpoint Route, Credential, and Guard Checklist

## Status — 2026-09-09
Planning — rebuilt around the canonical route-class policy and current source inventory. Exact implementation remains pending open role/scope decisions, cross-document PPRR, and explicit approval.

**Strategic plan:** `docs/feature_security_authentication_migration_jwt.md`  
**Implementation:** `docs/feature_security_authentication_migration_jwt_implementation.md`  
**Canonical policy:** `docs/policy_authentication_credential_route.md`  
**Role/page reference:** `docs/ui_role_matrix.html`  
**SaaS RBAC reference:** `docs/feature_completed_saas_model_changes.md`

---

## Elevator Pitch

Every GigHive URL needs an explicit answer to four questions: what kind of route it is, which credential it accepts, which role it requires, and which tenant or event it may access. This checklist replaces broad “protect every PHP file” assumptions with an exact manifest that preserves browser pages, iOS APIs, downloads, media, and QR guest access while denying direct access to internal code.

---

## Purpose

For every web-accessible or intentionally non-web PHP surface, record:

1. Route class.
2. Accepted credential and precedence.
3. Minimum role.
4. Tenant/event scope.
5. CSRF requirement.
6. Failure response type.
7. Local Admin versus Platform Admin disposition.
8. Implementation status.

The canonical route policy defines behavior. This checklist assigns that behavior to concrete files and paths.

---

## Policy Legend

### Route classes

| Code | Route class |
|---|---|
| `PUB` | `PUBLIC` |
| `LOGIN` | `LOGIN` |
| `HTML` | `HTML_PAGE` |
| `API` | `AUTHENTICATED_API` |
| `DL` | `AUTHENTICATED_DOWNLOAD` |
| `GU` | `GUEST_UPLOAD` |
| `GG` | `GUEST_GALLERY` |
| `MEDIA` | `MIXED_MEDIA` |
| `DUAL` | `DUAL_RESPONSE` |
| `WORKER` | `INTERNAL_WORKER` |
| `LIB` | `INTERNAL_LIBRARY` |

### Credentials

| Code | Credential behavior |
|---|---|
| `C` | Browser HttpOnly JWT cookie |
| `B` | Explicit API/iOS Bearer JWT |
| `U` | Explicit QR upload token |
| `N` | Explicit gallery/status nonce |
| `—` | No credential; public or direct HTTP denied |

Rules:

- `B→C`: if Bearer is present it must validate; invalid Bearer does not fall back to cookie.
- `U→B→C`: explicit upload token is authoritative; invalid token does not fall back.
- `N only`: nonce controls guest route; incidental cookie/Bearer is ignored for guest authorization.
- `N/U→B→C`: explicit guest credential is authoritative on media; otherwise Bearer then cookie.
- Cookie-authenticated unsafe methods require CSRF.
- Role/scope is applied after credential resolution.

### Failure responses

| Code | Behavior |
|---|---|
| `HTML` | Safe GET redirects to login; wrong role HTML `403`; unsafe expired request is not replayed |
| `JSON` | Stable JSON `401`/`403`; never login redirect |
| `BINARY` | Status only; never substitute login HTML or partial file |
| `MEDIA` | Status/media semantics; no login redirect; preserve Range behavior |
| `GUEST` | Guest-contract failure; no account-login redirect |
| `DENY` | Direct HTTP denied before implementation code executes |

---

## Exact Inventory Summary

| Surface | Count | Classification |
|---|---:|---|
| `admin/*.php` real files | 59 | 9 HTML + 38 API/action + 2 downloads + 7 workers + 3 libraries |
| `db/*.php` | 14 | 1 public + 8 HTML + 1 guest/auth HTML + 4 API; one HTML file also has explicit JSON mode |
| `api/*.php` | 12 | 4 authenticated API + 5 guest gallery + 1 guest-token validation + 1 mixed upload + 1 mixed media |
| `src/index.php` | 1 | Mixed upload front controller |
| `src/Jobs/*.php` | 2 | Internal workers |
| Public root/timeline/mail pages | Enumerated below | Public/login policy |

Five macOS `._*` files are metadata, not PHP application files, and are excluded from counts.

---

## Public and Login Routes

| Path | Class | Cred | Role | Scope | CSRF | Failure | Status |
|---|---|---|---|---|---|---|---|
| `/index.php` | PUB | — | none | public | no | public | current |
| `/loops.php` | PUB | — | none | public | no | public | current |
| `/guest_event_view.php` | PUB/GG landing | N | none | event | no | GUEST | current |
| `/timeline/timeline-api.php` | PUB | — | none | public event feed | no | JSON/public | current |
| `/db/health.php` | PUB | — | none | health only | no | JSON/public | current |
| `/.well-known/apple-app-site-association` | PUB | — | none | association only | no | public | current |
| `/mail/register.php` | PUB | — | none | registration | form protection when implemented | HTML | planned/stub |
| `/mail/handle_register.php` | PUB | — | none | registration | required when implemented | HTML/JSON | planned/stub |
| `/auth/login.php` | LOGIN | pre-auth/C | none | browser session | login CSRF/Origin | HTML | new |
| `/auth/logout.php` | LOGIN | C | authenticated | own session | yes | HTML | new |
| `/api/login.php` | LOGIN | explicit credentials | none | API/iOS token issue | no browser cookie | JSON | new |
| `/api/verify.php` | API | B | viewer | own token | no | JSON | new |

Public responses do not broaden because an incidental cookie is present. Browser login sets the cookie and does not return JWT to JavaScript; API login returns Bearer JSON and does not set the browser cookie.

---

## `admin/` — 59 Real PHP Files

### HTML pages — 9

| File | Class | Cred | Minimum role | Scope/disposition | CSRF | Failure | Status |
|---|---|---|---|---|---|---|---|
| `admin.php` | HTML | C | owner / platform_admin by surface | needs split: tenant account/content admin vs platform credentials/tenants | unsafe forms | HTML | needs-split |
| `admin_database_catalog_media_from_folder.php` | HTML | C | owner | tenant content | unsafe AJAX | HTML | local-admin |
| `admin_database_catalog_promote.php` | HTML | C | owner | tenant content | unsafe AJAX | HTML | local-admin |
| `admin_database_load_import_csv.php` | HTML | C | owner | tenant content | unsafe AJAX | HTML | local-admin |
| `admin_database_load_import_media_from_folder.php` | HTML | C | owner | tenant content | unsafe AJAX | HTML | local-admin |
| `admin_database_load_import_media_from_iphone.php` | HTML | C | owner | tenant content | unsafe AJAX | HTML | local-admin |
| `admin_system.php` | HTML | C | owner / platform_admin by surface | needs split: tenant dashboard vs platform control plane | unsafe forms/AJAX | HTML | needs-split |
| `ai_worker.php` | HTML | C | owner / platform_admin by surface | needs split: tenant jobs vs shared AI service | unsafe AJAX | HTML | needs-split |
| `event_qr.php` | HTML | C | owner | tenant/event QR management | unsafe forms | HTML | local-admin |

### Authenticated API/action endpoints — 38

All rows use class `API`, credential order `B→C`, JSON failures, and CSRF for cookie-authenticated unsafe methods. Safe GET/status requests do not require CSRF.

#### Tenant Local Admin — 28

| File | Minimum role | Scope | Status |
|---|---|---|---|
| `catalog_entries_list.php` | owner | tenant catalog | local-admin |
| `catalog_promote_start.php` | owner | tenant catalog | local-admin |
| `catalog_promote_writeback.php` | owner | tenant catalog | local-admin |
| `catalog_scan_start.php` | owner | tenant catalog | local-admin |
| `catalog_stats.php` | owner | tenant catalog | local-admin |
| `export_media.php` | owner | tenant export | la-only |
| `export_media_status.php` | owner | tenant export job | la-only |
| `import_manifest_add.php` | owner | tenant import | local-admin |
| `import_manifest_add_async.php` | owner | tenant import | local-admin |
| `import_manifest_cancel.php` | owner | tenant import | local-admin |
| `import_manifest_duplicates.php` | owner | tenant import | local-admin |
| `import_manifest_finalize.php` | owner | tenant import | local-admin |
| `import_manifest_jobs.php` | owner | tenant import jobs | local-admin |
| `import_manifest_prepare.php` | owner | tenant import | local-admin |
| `import_manifest_reload.php` | owner | tenant import | local-admin |
| `import_manifest_reload_async.php` | owner | tenant import | local-admin |
| `import_manifest_replay.php` | owner | tenant import | local-admin |
| `import_manifest_status.php` | owner | tenant import job | local-admin |
| `import_manifest_upload_finalize.php` | owner | tenant import upload | local-admin |
| `import_manifest_upload_start.php` | owner | tenant import upload | local-admin |
| `import_manifest_upload_status.php` | owner | tenant import upload | local-admin |
| `import_media_zip.php` | owner | tenant content import | la-only |
| `import_media_zip_scan_status.php` | owner | tenant import scan | la-only |
| `import_media_zip_status.php` | owner | tenant import job | la-only |
| `import_normalized.php` | owner | tenant content import | la-only |
| `iphone_import_clear_staging.php` | owner | tenant import staging | local-admin |
| `iphone_import_server_scan.php` | owner | tenant import staging | local-admin |
| `iphone_import_status.php` | owner | tenant import job | local-admin |

#### Needs role/scope split — 3

| File | Minimum role | Scope | Status |
|---|---|---|---|
| `admin_system_stats.php` | owner or platform_admin | tenant stats or platform aggregate | needs-split |
| `clear_media.php` | owner or platform_admin | one tenant or explicitly selected tenant | needs-split |
| `clear_media_files.php` | owner or platform_admin | one tenant or explicitly selected tenant storage | needs-split |

#### Platform-only — 7

| File | Minimum role | Scope | Status |
|---|---|---|---|
| `import_database.php` | platform_admin | platform database | ta-only |
| `restore_database.php` | platform_admin | platform database | ta-only |
| `restore_database_status.php` | platform_admin | platform restore job | ta-only |
| `run_backup.php` | platform_admin | platform database | ta-only |
| `run_backup_status.php` | platform_admin | platform backup job | ta-only |
| `upload_restore_backup.php` | platform_admin | platform restore artifact | ta-only |
| `write_resize_request.php` | platform_admin | platform infrastructure | ta-only |

### Authenticated downloads — 2

| File | Class | Cred | Minimum role | Scope | CSRF | Failure | Status |
|---|---|---|---|---|---|---|---|
| `export_media_download.php` | DL | B→C | owner | tenant export artifact | no mutation; one-time authorization required | BINARY | la-only |
| `download_backup.php` | DL | B→C | platform_admin | platform backup artifact | no mutation; one-time authorization required | BINARY | ta-only |

### Internal workers — 7

| File | Class | HTTP | Invocation scope | Required change | Status |
|---|---|---|---|---|---|
| `export_media_worker.php` | WORKER | DENY | tenant export job | retain CLI guard | la-only |
| `export_media_worker_azure.php` | WORKER | DENY | platform/approved Azure export context | retain CLI guard | platform/internal |
| `import_manifest_worker.php` | WORKER | DENY | tenant import job | **add missing explicit CLI guard** | local-admin/internal |
| `import_media_zip_scan_worker.php` | WORKER | DENY | tenant import scan | retain CLI guard | la-only |
| `import_media_zip_worker.php` | WORKER | DENY | tenant import | retain CLI guard | la-only |
| `import_media_zip_worker_azure.php` | WORKER | DENY | platform/approved Azure import context | retain CLI guard | platform/internal |
| `iphone_import_worker.php` | WORKER | DENY | tenant import job | retain CLI guard | local-admin/internal |

CLI workers do not receive browser/API JWTs. The authenticated HTTP endpoint that creates a job must persist its authorized tenant/user scope; the worker consumes only that bounded job context.

### Include-only libraries — 3

| File | Class | HTTP | Required change |
|---|---|---|---|
| `admin_media_lib.php` | LIB | DENY | deny direct HTTP or move outside webroot |
| `admin_sys_stats_lib.php` | LIB | DENY | deny direct HTTP or move outside webroot |
| `import_manifest_lib.php` | LIB | DENY | deny direct HTTP or move outside webroot |

---

## `db/` — 14 PHP Files

| File | Class | Cred | Minimum role | Scope | CSRF | Failure | Status |
|---|---|---|---|---|---|---|---|
| `health.php` | PUB | — | none | health only | no | JSON/public | current |
| `database.php` | HTML | C | viewer | tenant media | unsafe edit/delete actions delegated to API | HTML | current |
| `database_catalog.php` | HTML | C | **owner proposed** | tenant catalog | unsafe AJAX | HTML | role decision pending |
| `media_tags.php` | HTML | C | owner | tenant asset/tags | unsafe AJAX | HTML | local-admin |
| `ai_tags.php` | HTML | C | owner | tenant AI tags | safe browse unless source proves mutation | HTML | la-only |
| `tag_browser.php` | HTML | C | viewer | tenant tags | safe GET | HTML | current |
| `upload_form.php` | HTML | C | contributor | tenant upload | unsafe upload | HTML | current |
| `upload_form_admin.php` | HTML | C | owner | tenant upload/admin fields | unsafe upload/delete | HTML | local-admin |
| `upload_form_single.php` | GU/HTML | U→C | contributor when no U | QR event when U; tenant when C | token path explicit; cookie mutations CSRF | GUEST/HTML | mixed |
| `singlesRandomPlayer.php` | DUAL | C in HTML; B→C in JSON | viewer | tenant media | safe GET | HTML/JSON explicit mode | current; split preferred |
| `catalog_entry_save.php` | API | B→C | owner | tenant catalog entry | cookie unsafe: yes | JSON | local-admin |
| `database_edit_musicians_preview.php` | API | B→C | owner | tenant asset preview | cookie unsafe: yes | JSON | local-admin |
| `database_edit_save.php` | API | B→C | owner | tenant asset | cookie unsafe: yes | JSON | local-admin |
| `delete_media_files.php` | API | B→C | **owner proposed** | tenant asset/storage | cookie unsafe: yes | JSON | role decision pending; guest path moves to guest-delete |

Open role decisions are retained rather than silently resolved: catalog administration and destructive file deletion appear owner-level based on current UI and risk, but require explicit approval.

---

## `api/` — 12 PHP Files

| File | Class | Cred | Minimum role | Scope | CSRF | Failure | Status |
|---|---|---|---|---|---|---|---|
| `ai_jobs.php` | API | B→C | owner / platform_admin by operation | tenant jobs or platform queue | cookie unsafe: yes | JSON | needs-split |
| `tags.php` | API | B→C | owner | tenant tags/assets | cookie unsafe: yes | JSON | local-admin |
| `taggings.php` | API | B→C | owner | tenant tag associations | cookie unsafe: yes | JSON | local-admin |
| `guest-delete.php` | GG | N only | none | nonce/uploader-bound event asset | explicit capability | GUEST | current |
| `guest-gallery.php` | GG | N only | none | nonce-bound event | no | GUEST | current |
| `guest-report.php` | GG | N only | none | nonce-bound event asset | explicit capability | GUEST | current |
| `guest-status.php` | GG | N only | none | nonce/uploader-bound job | no | GUEST | current |
| `guest-stream.php` | GG | N only | none | nonce-bound event asset | no | MEDIA/GUEST | current |
| `upload-token.php` | GU | U only | none | upload-token-bound event | no | GUEST/JSON | current |
| `uploads.php` | API | B→C | contributor | tenant upload/finalization | cookie unsafe: yes | JSON | authenticated; no upload-token code in this legacy endpoint |
| `tus-upload.php` | GU/API | U→B→C | contributor when no U | QR event or tenant | TUS protocol; cookie Origin/CSRF policy required | GUEST/JSON | mixed |
| `media-stream.php` | MEDIA | N/U→B→C | viewer when no guest credential | QR event or tenant media | no | MEDIA | mixed |

---

## Additional Routes and Internal Code

| Path | Class | Cred/HTTP | Minimum role | Scope | Failure/status |
|---|---|---|---|---|---|
| `/api/uploads/*` via `src/index.php` | GU/API | U→B→C | contributor when no U | QR event or tenant | GUEST/JSON |
| `/api/media-files` via `src/index.php` | GU/API | U→B→C | contributor when no U | QR event or tenant | GUEST/JSON |
| `src/Jobs/cleanup_expired_uploads.php` | WORKER | DENY HTTP | internal | bounded cleanup | CLI only |
| `src/Jobs/run_probe_job.php` | WORKER | DENY HTTP | internal | queued tenant asset context | CLI only |
| `src/Config/*`, `Contracts/*`, `Controllers/*`, `Dto/*`, `Exceptions/*`, `Infrastructure/*`, `Presentation/*`, `Repositories/*`, `Services/*`, `Validation/*`, `Views/*` | LIB | DENY direct HTTP | internal | include/autoload only | DENY |
| `vendor/*.php` and nested PHP | LIB | DENY direct HTTP | internal | Composer only | DENY |
| `/debug/phpinfo.php`, `/debug/iptest.php`, `/src/debug/test.php`, `/test_render.php` if retained | HTML/internal diagnostic | C or DENY | platform_admin | platform diagnostics | HTML/DENY; verify files actually exist |

The final Apache denial must be tested against representative and sensitive paths without blocking internal includes, Composer autoloading, rewrites to `src/index.php`, or CLI jobs.

---

## Local Admin and Platform Admin Decisions — 27 Shared Rows

The matrix has 27 shared LA+TA rows: 11 local-admin-only, 7 platform-admin-only, and 9 requiring split. Two are planned rather than existing files.

### Local Admin only — 11

| Endpoint | Final role/scope |
|---|---|
| `/db/ai_tags.php` | owner + tenant asset |
| `/admin/import_media_zip.php` | owner + tenant content |
| `/admin/import_media_zip_scan_status.php` | owner + tenant job |
| `/admin/import_media_zip_scan_worker.php` | internal worker + tenant job context |
| `/admin/import_media_zip_status.php` | owner + tenant job |
| `/admin/import_media_zip_worker.php` | internal worker + tenant job context |
| `/admin/import_normalized.php` | owner + tenant content |
| `/admin/export_media.php` | owner + tenant content |
| `/admin/export_media_status.php` | owner + tenant job |
| `/admin/export_media_download.php` | owner + tenant artifact |
| `/admin/export_media_worker.php` | internal worker + tenant job context |

### Platform Admin only — 7

| Endpoint | Final role/scope |
|---|---|
| `/admin/run_backup.php` | platform_admin + platform database |
| `/admin/run_backup_status.php` | platform_admin + platform job |
| `/admin/download_backup.php` | platform_admin + platform artifact |
| `/admin/restore_database.php` | platform_admin + platform database |
| `/admin/restore_database_status.php` | platform_admin + platform job |
| `/admin/upload_restore_backup.php` | platform_admin + platform restore artifact |
| `/admin/import_database.php` | platform_admin + platform database |

### Needs split — 9

| Endpoint/surface | Local Admin | Platform Admin |
|---|---|---|
| `/admin/admin_system.php` | Tenant dashboard/content controls | Platform control plane/infrastructure |
| `/admin/admin_system_stats.php` | Tenant-scoped stats | Platform aggregates |
| `/admin/admin.php` | Tenant users/content moderation | Platform credentials/tenants |
| `/admin/clear_media.php` | One tenant's records | Explicit selected tenant/platform operation |
| `/admin/clear_media_files.php` | One tenant's storage | Explicit selected tenant/platform operation |
| `/admin/ai_worker.php` | Tenant AI jobs/content | Shared AI service/global queue |
| `/api/ai_jobs.php` | Tenant job CRUD | Platform queue management |
| Storage quota dashboard *(planned)* | Own usage/plan | All tenants/cost/enforcement |
| Per-tenant export/GDPR delete *(planned)* | Own tenant request | Any-tenant execution/cascade |

Short-term branching still requires tenant scope. Long-term platform-only surfaces should move to an explicit `/platform/` route prefix.

---

## Browser AJAX Caller Prerequisite

The audit identified 14 caller files plus new `auth/gh-auth.js`. The exact Phase 0 implementation is owned by `docs/refactor_security_authentication_shared_auth_function.md`.

- Phase 0 `GHAuth.authedFetch()` is a token-free native-fetch passthrough under Basic Auth.
- JWT browser mode uses the HttpOnly cookie automatically; JavaScript never reads the JWT.
- Later `GHAuth` behavior adds CSRF header and centralized API `401` handling only.
- Public/QR requests remain explicit route-policy flows.

---

## Open Decisions

- [ ] **`db/delete_media_files.php` role** — approve owner rather than contributor; guest delete routes to `/api/guest-delete.php`.
- [ ] **`db/database_catalog.php` role** — approve owner rather than viewer.
- [ ] **Platform role name** — reconcile DB `superadmin` with SaaS `platform_admin` before implementation.
- [ ] **`/platform/` prefix timing** — interim role branch versus immediate route separation.
- [ ] **Revocation mode** — request-time account/token-version check, denylist, or expiry-only.
- [ ] **`singlesRandomPlayer.php`** — retain explicit `DUAL_RESPONSE` mode or split HTML/JSON routes.
- [ ] **Exact browser CSRF implementation** — centralized design from implementation guide.
- [ ] **Diagnostic routes** — verify existence and decide platform-authenticated versus deny/remove.

---

## Test Requirements

The implementation guide reserves T-169–T-184 for core route-policy behavior. Every entry also receives applicable permanent checks:

1. Unauthenticated/invalid credential → expected `401`, redirect, guest error, or direct denial.
2. Wrong role → `403` without data/action.
3. Correct role + same tenant/event → success.
4. Correct role + wrong tenant/event → denied.
5. Cookie unsafe request without valid CSRF → `403`, no mutation.
6. Invalid explicit Bearer/token/nonce + valid broader cookie → fail without fallback.
7. HTML/API/download/media response type remains correct.
8. Internal worker/library direct HTTP denied.
9. Public route remains public and does not broaden with cookie.
10. Raw credentials never appear in logs.

New or modified protected `/admin/` and `/api/` endpoints require the permanent smoke checks mandated by SKILL.md. Tests that create jobs, files, or database rows must define safe setup and cleanup.

---

## Progress

### Completed

- [x] Exact source counts established.
- [x] Route classes and credential precedence assigned by current evidence.
- [x] 27 shared Local Admin/Platform Admin decisions preserved.
- [x] Internal workers and libraries separated from HTTP guards.
- [x] Browser localStorage/Bearer instructions removed.
- [x] Atomic cutover and canonical policy linked.

### Remaining — Documentation

- [ ] Resolve eight Open Decisions above.
- [ ] Reconcile OIDC Phase 5.
- [ ] Align shared-auth refactor plan.
- [ ] Run repository-wide stale-guidance search.
- [ ] Run cross-document PPRR.

### Remaining — Implementation

- [ ] Do not change source/configuration until all prerequisite documents pass PPRR and the user explicitly approves implementation.
