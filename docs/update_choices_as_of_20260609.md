# Refactor Status as of 2026-06-09

## Rationale

This document supersedes the 2026-04-22 version of itself. It tracks only `refactor_*`
planning documents — files named `refactored_*` represent already-completed work and are not
tracked here. All changes since the 2026-04-22 snapshot are reconciled below.

---

## Changes Since 2026-04-22

### Completed (4 docs)

- `refactor_db_fix_event_metadata_duplication.md` — Renamed to
  `refactored_db_fix_event_metadata_duplication.md`. Was the "bang-for-buck" pick in the
  previous snapshot.
- `refactor_ai_jobs_upload_jobs_event_key_db_schema.md` — Renamed to
  `refactored_ai_jobs_upload_jobs_event_key_db_schema.md`. All four schema changes confirmed
  present in `create_music_db.sql` and live environments; downstream code changes were already done.
- `refactor_upload_folder_nav_away_cancels_fix.md` — Renamed to
  `refactored_upload_folder_nav_away_cancels_fix.md`. Was the bang-for-buck pick in this snapshot.
- `refactor_email_address.md` — Renamed to `refactored_email_address.md`. Both flavor-specific
  `index.php` files updated to read `APP_FLAVOR_EMAIL` from env; group_vars and `.env.j2` updated
  across all three env tiers.

### New Deferred Docs Added Since 20260422 (1 doc)

- `refactor_upload_using_catalog.md` — Streamline upload/ingest once the catalog feature is
  live. Explicitly blocked on `feature_db_catalog_insert.md` Phase 1; deferred until that ships.

### New Reference / Planning Docs Added Since 20260422 (2 docs, no standalone action)

- `refactor_db_fix_event_metadata_example_clarity.md` — Companion concrete-example doc for
  the now-completed duplication fix. No independent action item.
- `refactor_security_recommendations_20260530.md` — Security roadmap summary (Bundles B–F).
  Individual work items are tracked in their own docs; this is a cross-reference only.

### New Not-Started Docs Added Since 20260422 (4 docs)

- `refactor_ai_video_tagger_scan_methodology_improvements.md` — Candidate sampling strategies
  (scene-change, I-frame, two-pass) for the AI video tagger. None implemented; current uniform
  sampling unchanged.
- `refactor_move_stagingvm_to_emmc.md` — Operational: move staging VDI from md0 RAID-5 array
  to eMMC to eliminate I/O contention during upload + AI worker loads. Operational status
  cannot be confirmed from code.
- `refactor_navigation_user_flow.md` — Role-driven navigation flow; stub/future-work doc with
  open pending decisions.
- `refactor_security_upgrade_ssh_key.md` — RSA → ED25519 SSH key migration; `id_rsa.pub`
  still hardcoded in `cloud_init/tasks/main.yml`.

---

## Explicitly Deferred (by design)

| Doc | Reason |
|---|---|
| `refactor_acls_on_restore_logs.md` | "Current approach: not relying on ACLs" — intentional temporary posture |
| `refactor_admin_45_last_steps.md` | Steps 4 and 5 explicitly marked "(Deferred)" in doc |
| `refactor_edge_aware_authentication_model.md` | Bypass CF cache for `/audio/*` and `/video/*` accepted as current posture |
| `refactor_version_number_to_semantic.md` | "Deferred follow-up item, not required for initial telemetry" |
| `refactor_ansible_www_group_vars.md` | Cleanup done; full parameterization deferred — container-internal `www-data` refs are fixed by design |
| `refactor_security_password_unification.md` | Option C done; Options A+B parked — hash divergence cosmetic, cost outweighs benefit |
| `refactor_api_cleanup_if_desired.md` | Current `.php` URL state accepted; clean-URL migration explicitly optional |
| `refactor_database_utf8_enforcement_if_legacy_cleanup_needed.md` | UTF-8 Phases 3-4; deferred pending evidence of real encoding issues |
| `refactor_upload_using_catalog.md` | Blocked on `feature_db_catalog_insert.md` Phase 1 |

---

## Not Done

| Doc | Key finding |
|---|---|
| `refactor_convert_legacy_database_csv_python.md` | `convert_legacy_database_csv_to_normalized.py` reads exts from group_vars YAML only — no `UPLOAD_AUDIO_EXTS_JSON`/`UPLOAD_VIDEO_EXTS_JSON` env var support |
| `refactor_db_database_admin_soft_deletes.md` | No `soft_delete_media_files.php` or `restore_soft_deleted_media_files.php` in webroot |
| `refactor_preasset_librarian_db_ui_based_on_personas.md` | No capability-flag extraction or persona-split view layer implemented |
| `skip_refactor_preasset_librarian_fetch_media_lists.md` | No shared `buildMediaListQuery` builder; the three fetch methods still have separate SQL |
| `refactor_quickstart_specific_template.md` | No quickstart-specific `docker-compose.yml.j2` template exists |
| `refactor_security.md` | Long-horizon future plan; not started |
| `refactor_security_docker_hardened_images.md` | DHI assessment; per-image replaceability and runtime hardening gaps; not started |
| `refactor_ai_video_tagger_scan_methodology_improvements.md` | Scene-change / I-frame / two-pass sampling not implemented; uniform sampling unchanged |
| `refactor_move_stagingvm_to_emmc.md` | Operational VM migration; status unverifiable from code |
| `refactor_navigation_user_flow.md` | Stub; pending decisions on home page fork and role persistence |
| `refactor_security_upgrade_ssh_key.md` | `id_rsa.pub` still hardcoded in `cloud_init/tasks/main.yml:340` and `cloud_init/templates/user-data.j2`; change only takes effect on new VM provisioning — existing VMs must be rebuilt |

---

## Bang-for-Buck Recommendation

### Combined ranking (refactor_* and feature_* together)

**#1 overall: `feature_db_catalog_insert.md` (Phase 1)**

Highest value next item across both lists. 5 new PHP files + 2 new tables, zero changes to
existing files, zero new env vars. Fills a workflow gap nothing else covers (server-side scan →
metadata review → select/skip before committing to upload). Completing Phase 1 also unblocks the
deferred `refactor_upload_using_catalog.md`.

### Best bang-for-buck among remaining refactors only

**`refactor_db_database_admin_soft_deletes.md`**

The two lowest-effort items (`refactor_upload_folder_nav_away_cancels_fix.md` and
`refactor_email_address.md`) are now done. Among the remaining not-done refactors, soft deletes
delivers the best ratio of user-visible value to implementation effort: one new DB column
(`deleted_at`), two new PHP endpoints, and a toggle in the Media Library admin UI. No schema
migration coordination required and no infra changes.

**Why this over the rest:**

- `refactor_security_upgrade_ssh_key.md` — Code change is trivial (2 files) but the effective
  cost is 4 VM rebuilds (dev, lab, staging, prod) since `user-data.j2` is a cloud-init template
  applied only at VM creation time. Do this when a rebuild is already planned, not standalone.
- `refactor_security_docker_hardened_images.md` — Meaningful security improvement but the
  non-trivial parts (entrypoint redesign for non-root) are multi-session work.
- `refactor_convert_legacy_database_csv_python.md` — Small script fix but low user visibility;
  only affects the legacy CSV import path.
- `refactor_quickstart_specific_template.md` — Medium effort, no existing-file changes, but
  purely operational with no end-user impact.
- All others — either long-horizon, stub/future work, or have blocking dependencies.

**Bundle with next planned VM rebuild:**

- `refactor_security_upgrade_ssh_key.md` — Two-file Ansible change; zero standalone value until
  VMs are rebuilt.

---

## Reference / Planning Docs (no standalone action needed)

| Doc | Purpose |
|---|---|
| `refactor_db_fix_event_metadata_example_clarity.md` | Concrete-example companion to the completed duplication fix |
| `refactor_security_recommendations_20260530.md` | Security roadmap summary (Bundles B–F); individual items tracked in their own docs |

---

## Master Summary Table

| Status | Doc |
|---|---|
| ⏸️ Deferred | `refactor_acls_on_restore_logs.md` |
| ⏸️ Deferred | `refactor_admin_45_last_steps.md` |
| ⏸️ Deferred | `refactor_edge_aware_authentication_model.md` |
| ⏸️ Deferred | `refactor_version_number_to_semantic.md` |
| ⏸️ Deferred | `refactor_ansible_www_group_vars.md` |
| ⏸️ Deferred | `refactor_security_password_unification.md` |
| ⏸️ Deferred | `refactor_api_cleanup_if_desired.md` |
| ⏸️ Deferred | `refactor_database_utf8_enforcement_if_legacy_cleanup_needed.md` |
| ⏸️ Deferred | `refactor_upload_using_catalog.md` |
| ❌ Not done | `refactor_convert_legacy_database_csv_python.md` |
| ❌ Not done | `refactor_db_database_admin_soft_deletes.md` |
| ❌ Not done | `refactor_preasset_librarian_db_ui_based_on_personas.md` |
| ❌ Not done | `skip_refactor_preasset_librarian_fetch_media_lists.md` |
| ❌ Not done | `refactor_quickstart_specific_template.md` |
| ❌ Not done | `refactor_security.md` |
| ❌ Not done | `refactor_security_docker_hardened_images.md` |
| ✅ Done | `refactor_ai_jobs_upload_jobs_event_key_db_schema.md` → `refactored_ai_jobs_upload_jobs_event_key_db_schema.md` |
| ❌ Not done | `refactor_ai_video_tagger_scan_methodology_improvements.md` |
| ❌ Not done | `refactor_move_stagingvm_to_emmc.md` |
| ❌ Not done | `refactor_navigation_user_flow.md` |
| ❌ Not done | `refactor_security_upgrade_ssh_key.md` |
| ✅ Done | `refactor_upload_folder_nav_away_cancels_fix.md` → `refactored_upload_folder_nav_away_cancels_fix.md` |
| ✅ Done | `refactor_email_address.md` → `refactored_email_address.md` |
| 📄 Reference | `refactor_db_fix_event_metadata_example_clarity.md` |
| 📄 Reference | `refactor_security_recommendations_20260530.md` |
| ✅ Done | `refactor_db_fix_event_metadata_duplication.md` → `refactored_db_fix_event_metadata_duplication.md` |
| 🗑️ Deleted | `refactor_hardening_sections45.md` |
| 🗑️ Deleted | `refactor_preasset_librarian_admin_pages_move_to_protected_folder.md` |
