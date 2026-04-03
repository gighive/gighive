# Refactor Status as of 2026-03-31

## Rationale

This document captures a point-in-time review of all `docs/refactor_*` and `docs/refactored_*` planning documents against the actual codebase state. The goal is to distinguish what has been implemented, what is partially done, what is intentionally deferred, and what remains unstarted.

---

## Fully Done

### `refactored_one_shot_bundle_remove_vestigial.md`
Confirmed complete. `ansible/roles/one_shot_bundle/tasks/main.yml` has no `one_shot_bundle_source`, `get_url`, or url branching. All inventories and group_vars are clean of the removed vars (`one_shot_bundle_url`, `one_shot_bundle_filename`, `one_shot_bundle_controller_src`, `one_shot_bundle_inputs_fingerprint_path`, `one_shot_bundle_monitor_only`). `serve_one_shot_installer_downloads` is gone from `site.yml`. Legacy docker task files (`one_shot_bundle_monitor.yml`, `one_shot_bundle_rebuild.yml`, `one_shot_bundle_publish.yml`) are deleted.

### `refactor_upload_tests.md`
All stages (1, 2, 3D, 3E) confirmed done. All 5 helper task files are present in `ansible/roles/upload_tests/tasks/`:
- `generate_manifest.yml`
- `run_upload_media_by_hash.yml`
- `query_db_counts.yml`
- `extract_import_job_id.yml`
- `derive_expected_files_from_prepped_csv.yml`

### `refactor_add_db_migrations_backup_before.md`
The core goal — the `db_migrations` role — is done. `ansible/roles/db_migrations/` exists and is wired into `ansible/playbooks/site.yml`. The "optional backup-before-migrate" feature documented in the same file remains future work.

---

## Partially Done

### `refactored_gighive_home_and_scripts_dir.md`
The following phases are done per the doc's own "What we changed already" section:
- Removed `GIGHIVE_HOME` preflight from `site.yml`
- Introduced `gighive_home` default in `group_vars/all.yml`
- Added base role `VERSION` validation tasks
- Added marker file behavior
- Phase 1 of the migration: `scripts_dir: "{{ gighive_home }}"` alias in `site.yml`

**Remaining:** Phases 2–5 — retiring `scripts_dir` from base role tasks, updating derived vars, updating `.bashrc` export, full verification pass.

### `refactored_admin_page.md`
Some Phase 1 helpers are present in `admin.php` (`renderOkBannerWithDbLink`, `renderDbLinkButton`). The admin UI reorganization across three pages is complete (noted in the doc's own test plan section).

**Not done:** `escapeHtml`, `fetchJson`, `setButtonLoading` functions not defined. Phases 2–7 (fetch wrapper, button state helpers, JS file split, CSS classes, template fragments) not implemented.

### `refactor_bind_mount_guard_pattern.md`
Approach A (stat + remove-if-dir guard) is partially implemented. Guards confirmed in `ansible/roles/docker/tasks/main.yml` for:
- `htpasswd` path
- `ports.conf`
- `logging.conf`
- `apache2-logrotate.conf`

**Not covered yet** per the doc's own list: `apache2.conf`, `default-ssl.conf`, `php-fpm.conf`, `www.conf`, `entrypoint.sh`, `openssl_san.cnf`, `modsecurity.conf`, `security2.conf`, MySQL config files.

---

## Explicitly Deferred (by design)

These docs explicitly state they are deferred or are future-only planning documents. No implementation is expected yet.

| Doc | Reason deferred |
|---|---|
| `refactor_acls_on_restore_logs.md` | Doc itself states "current approach: not relying on ACLs" — intentional temporary posture |
| `refactor_admin_45_last_steps.md` | Steps 4 (UI controller abstraction) and 5 (external JS file) both explicitly marked "(Deferred)" |
| `refactor_edge_aware_authentication_model.md` | Doc states "current recommendation: bypass CF cache for `/audio/*` and `/video/*`" — awaiting future product need |
| `refactor_version_number_to_semantic.md` | Doc states "deferred follow-up item, not required for initial telemetry" |

---

## Not Done

| Doc | Key finding |
|---|---|
| `refactor_preasset_librarian_admin_pages_move_to_protected_folder.md` | No `webroot/admin/` directory exists. Interim `LocationMatch` block still present in `default-ssl.conf.j2` with comment "will move to /admin/ in a future refactor" |
| `refactor_convert_legacy_database_csv_python.md` | `convert_legacy_database_csv_to_normalized.py` still reads `gighive_upload_audio_exts`/`gighive_upload_video_exts` from `group_vars` YAML only — no `UPLOAD_AUDIO_EXTS_JSON`/`UPLOAD_VIDEO_EXTS_JSON` env var support |
| `refactor_db_database_admin_soft_deletes.md` | No `soft_delete_media_files.php` or `restore_soft_deleted_media_files.php` found in webroot |
| `refactor_preasset_librarian_db_ui_based_on_personas.md` | No capability-flag extraction or persona-split view layer implemented |
| `refactor_email_address.md` | `admin@stormpigs.com` still hardcoded in `webroot/index.php` and `vodcast.xml` |
| `refactor_preasset_librarian_fetch_media_lists.md` | No shared `buildMediaListQuery` builder in `SessionRepository` — `fetchMediaListPage`, `fetchMediaListFiltered`, and `fetchMediaList` still have separate SQL; only `buildMediaListFilters` (WHERE clause only) is shared |
| `refactor_hardening_sections45.md` | None of the 7 hardening items (input limits, stale-lock recovery, crash consistency, observability, ETA quality, replay safety, rate limiting) are implemented |
| `refactor_quickstart_specific_template.md` | No quickstart-specific `docker-compose.yml.j2` template exists under `ansible/roles/docker/files/` |
| `refactored_preasset_librarian_unified_ingestion_core.md` | No shared ingestion service exists; upload API and manifest import paths remain separate |

---

## Master Summary Table

| Status | Doc |
|---|---|
| ✅ Done | `refactored_one_shot_bundle_remove_vestigial.md` |
| ✅ Done | `admin_upload_tests_refactored.md` |
| ✅ Done (core) | `refactored_db_migrations_backup_before.md` |
| 🔶 Partial | `refactored_gighive_home_and_scripts_dir.md` |
| 🔶 Partial | `refactored_admin_page.md` |
| 🔶 Partial | `refactor_bind_mount_guard_pattern.md` |
| ⏸️ Deferred | `refactor_acls_on_restore_logs.md` |
| ⏸️ Deferred | `refactor_admin_45_last_steps.md` |
| ⏸️ Deferred | `refactor_edge_aware_authentication_model.md` |
| ⏸️ Deferred | `refactor_version_number_to_semantic.md` |
| ❌ Not done | `refactor_preasset_librarian_admin_pages_move_to_protected_folder.md` |
| ❌ Not done | `refactor_convert_legacy_database_csv_python.md` |
| ❌ Not done | `refactor_db_database_admin_soft_deletes.md` |
| ❌ Not done | `refactor_preasset_librarian_db_ui_based_on_personas.md` |
| ❌ Not done | `refactor_email_address.md` |
| ❌ Not done | `refactor_preasset_librarian_fetch_media_lists.md` |
| ❌ Not done | `refactor_hardening_sections45.md` |
| ❌ Not done | `refactor_quickstart_specific_template.md` |
| ❌ Not done | `refactored_preasset_librarian_unified_ingestion_core.md` |
