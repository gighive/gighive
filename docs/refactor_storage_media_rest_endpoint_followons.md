---
description: "Follow-on tasks deferred from the MediaStorageService / storage REST endpoint refactor and related QR-upload work — not blocking for Tranche 1"
---

# Follow-on Tasks — Storage REST Endpoint Refactor

These items were identified during the `MediaStorageService` refactor (Tranche 1) and
the QR-upload shared gallery work but deferred as non-blocking. They are collected here
so they are not lost across refactor and problem docs.

Source docs:

- `docs/refactor_storage_media_rest_endpoint_implementation.md` → Progress → Remaining — Follow-on Tasks
- `docs/problem_storage_media_endpoint_upload_field_miss.md` → Follow-on: Standing DB cruft cleanup
- `docs/problem_iphone_qr_code_shared_gallery_notifications.md` → Preventative Actions item 5

---

## Maintenance Role (Ansible)

No dedicated `maintenance` Ansible role exists. Hygiene tasks are currently placed in
`post_build_checks` as a temporary home. A future `maintenance` role should:

- Own all standing DB hygiene tasks.
- Run early in `site.yml` — before `validate_app`, `post_build_checks`, or any smoke-test role.
- Be idempotent and safe to run on every deploy.

### Tasks to move into the maintenance role when it is created

#### M-01 — `probe_jobs` orphan row cleanup
**Current location:** `post_build_checks/tasks/main.yml` (task `[M-01]`)
**Action:** Delete `probe_jobs` rows whose `asset_id` no longer exists in `assets`.
**Why deferred:** No maintenance role exists yet. Moving now would require creating the
role skeleton, which is out of scope for the storage refactor.

#### M-02 — `tus_uploads` orphan row cleanup
**Current location:** `post_build_checks/tasks/main.yml` (task `[M-02]`)
**Action:** Delete `tus_uploads` rows whose `asset_id` no longer exists in `assets`.
**Why deferred:** Same as M-01.

#### Guest soft-delete physical file removal
**Current location:** Not yet implemented anywhere.
**Action:** `guest-delete.php` sets `upload_jobs.guest_deleted = 1` (soft delete) but
does not remove the media file from disk or purge the database rows. A maintenance task
should periodically hard-delete assets where `upload_jobs.guest_deleted = 1` by:
1. Calling `MediaStorageService::delete()` (or the appropriate backend method) to remove
   the physical file.
2. Deleting the `assets` row (cascades `probe_jobs` and `tus_uploads` if FK cascade is
   present; otherwise delete explicitly first).
3. Deleting the `upload_jobs` row (cascades `anon_upload_attributions` via `fk_aua_job`).

Until this task exists, guest-deleted clips remain on disk and in the DB — they are only
hidden from the gallery by the `guest_deleted` flag. This is acceptable short-term.

**Context:** `docs/problem_iphone_qr_code_shared_gallery_notifications.md` — Preventative
Actions item 5 explains the guest delete flow and why hard deletion was not included in
the original fix.

---

## MediaStorageService / PHP

#### Session-cookie auth for `media-stream.php`
Guest gallery thumbnails are handled by the gallery nonce path (Phase 4). The remaining
gap is the browser admin panel: if admin thumbnail display via `<img>` tags is ever added,
session-cookie auth must be added to `media-stream.php` as a fourth credential path.
Not blocking for Tranche 1.

#### Retire `AZURE_BLOB_SAS_TOKEN`
Four admin PHP files still read this token directly rather than through `MediaStorageService`:
- `export_media_worker_azure.php`
- `import_media_zip_worker_azure.php`
- `import_media_zip.php`
- `export_media.php`

Retirement requires migrating all four to use `MediaStorageService` inline SAS calls. This
is Phase 10 (Tranche 2) work. Until then, keep the token in `.env.j2`.

#### Evaluate Apache `X-Sendfile` for local-mode read path
Relevant if PHP file serving becomes a CPU or throughput bottleneck under real load. Not a
known issue at current scale; revisit if profiling shows it.

#### Delete `src/Services/FallbackMediaBackend.php`
This is a migration-window-only class and must not persist in the codebase beyond Tranche 2.
Delete after Phase 11 step 9 backfill is verified complete.

---

## `tus_local_staging_dir` group_vars gap (pre-existing)
`TusUploadConfig.php` hardcodes `'/tmp/tus-staging'` as a fallback when `TUS_LOCAL_STAGING_DIR`
env var is absent. The env var is set in `.env.j2` via
`{{ tus_local_staging_dir | default('/tmp/tus-staging') }}` but `tus_local_staging_dir` is
not declared in any group_vars file. Not introduced by this fix; tracked here to avoid loss.
