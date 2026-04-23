# Refactor Status as of 2026-04-22

## Rationale

This document supersedes `docs/refactor_status_as_of_20260331.md`. It tracks only `refactor_*`
planning documents — files named `refactored_*` represent already-completed work and are not
tracked here. All changes since the 2026-03-31 snapshot are reconciled below.

---

## Changes Since 2026-03-31

### Deleted (2 docs)

- `refactor_hardening_sections45.md` — Removed. None of the 7 hardening items were implemented
  before removal; no successor doc exists. Work appears abandoned or absorbed elsewhere.
- `refactor_preasset_librarian_admin_pages_move_to_protected_folder.md` — Removed. The
  corresponding completed work is now documented in `refactored_admin_all_pages_move_under_admin.md`.

### New Deferred Docs Added Since 20260331 (4 docs)

- `refactor_ansible_www_group_vars.md` — Duplicate `apache_group: www-data` key cleaned up
  in all three group_vars files. Full parameterization of container-internal hardcoded
  references explicitly deferred per doc (low benefit, high complexity).
- `refactor_security_password_unification.md` — Option C (length-gap closure) is done.
  Options A and B (hash format unification, rotate_basic_auth.sh replacement) explicitly
  parked per doc recommendation.
- `refactor_api_cleanup_if_desired.md` — Current `/api/uploads.php` state declared acceptable;
  clean-URL migration explicitly optional and not recommended.
- `refactor_database_utf8_enforcement_if_legacy_cleanup_needed.md` — Holds UTF-8 Phases 3-4.
  Both phases deferred pending evidence of real encoding issues in existing data.

### New Unstarted Docs Added Since 20260331 (3 docs)

- `refactor_db_fix_event_metadata_duplication.md` — Stop-gap: add `sessions.event_key`,
  replace `(event_date, org_name)` identity with stable key, fix cross-session song reuse.
- `refactor_security.md` — Long-horizon future plan (local user auth, OIDC/OAuth2 modes).
- `refactor_security_docker_hardened_images.md` — DHI assessment; per-image replaceability
  and runtime hardening gaps. Added 2026-04-22.

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

---

## Not Done

| Doc | Key finding |
|---|---|
| `refactor_convert_legacy_database_csv_python.md` | `convert_legacy_database_csv_to_normalized.py` reads exts from group_vars YAML only — no `UPLOAD_AUDIO_EXTS_JSON`/`UPLOAD_VIDEO_EXTS_JSON` env var support |
| `refactor_db_database_admin_soft_deletes.md` | No `soft_delete_media_files.php` or `restore_soft_deleted_media_files.php` in webroot |
| `refactor_preasset_librarian_db_ui_based_on_personas.md` | No capability-flag extraction or persona-split view layer implemented |
| `refactor_email_address.md` | `admin@stormpigs.com` still hardcoded in `webroot/index.php` and `vodcast.xml` |
| `skip_refactor_preasset_librarian_fetch_media_lists.md` | No shared `buildMediaListQuery` builder; the three fetch methods still have separate SQL |
| `refactor_quickstart_specific_template.md` | No quickstart-specific `docker-compose.yml.j2` template exists |
| `refactor_db_fix_event_metadata_duplication.md` | New since 20260331; not started |
| `refactor_security.md` | Long-horizon future plan; not started |
| `refactor_security_docker_hardened_images.md` | Assessment added 2026-04-22; not started |

---

## Bang-for-Buck Recommendation

**Best single pick: `refactor_db_fix_event_metadata_duplication.md`**

This fixes a real, reproducible user-visible bug: if an admin edits `org_name` after an initial
upload, re-uploading the same event creates a second session row because `(event_date, org_name)`
no longer matches. The result is split event rows and cross-session song linking visible in the
Media Library.

The doc already has clear delivery milestones (A–D), a concrete schema change (`sessions.event_key`),
and explicit acceptance criteria. It aligns directly with the approved Event/Asset hard-cutover
direction (making `event_key` the precursor to future canonical `events.event_key`). No new
infrastructure needed — it's pure PHP/SQL within the existing role.

**Trivial quick win alongside: `refactor_email_address.md`**

Two-line change: replace `admin@stormpigs.com` in `webroot/index.php` and `vodcast.xml`.
Zero risk, zero coordination. Worth doing in any nearby work session.

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
| ❌ Not done | `refactor_convert_legacy_database_csv_python.md` |
| ❌ Not done | `refactor_db_database_admin_soft_deletes.md` |
| ❌ Not done | `refactor_preasset_librarian_db_ui_based_on_personas.md` |
| ❌ Not done | `refactor_email_address.md` |
| ❌ Not done | `skip_refactor_preasset_librarian_fetch_media_lists.md` |
| ❌ Not done | `refactor_quickstart_specific_template.md` |
| ❌ Not done ⭐ | `refactor_db_fix_event_metadata_duplication.md` (bang-for-buck pick) |
| ❌ Not done | `refactor_security.md` |
| ❌ Not done | `refactor_security_docker_hardened_images.md` |
| 🗑️ Deleted | `refactor_hardening_sections45.md` |
| 🗑️ Deleted | `refactor_preasset_librarian_admin_pages_move_to_protected_folder.md` |
