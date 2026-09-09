# Next Major Changes — Implementation Plan (2026-09-06)

**Scope:** All non-completed `refactor_*.md` and `feature_*.md` docs in `gighiveinfra/docs`, ordered against a single goal: **solid SaaS implementation**.  
**Supersedes:** `refactor_status_20260819.md` (refactor-only ranking) and `update_choices_as_of_20260609.md` (June 2026 snapshot).

---

## Index

**Critical Path**

1. *(operator task)* Google + Microsoft IdP app registrations — vault OAuth credentials before item 3 deploys
2. `feature_security_authentication_migration_jwt_implementation.md` — JWT core, PHP role guards, iOS Bearer cutover, Apache Basic Auth removal
3. `feature_security_authentication_migration_jwt_oidc_phase5.md` — OIDC federation (Google + Microsoft) with iOS PKCE flow

**Force Multipliers and Near-Zero-Effort Wins**

4. `refactor_ansible_host_versions_across_environments.md` — Standardize Ansible controller versions to prevent silent deploy failures
5. `refactor_security_docker_hardened_images.md` — Swap to Docker Official Images for ~95% CVE reduction on mysql, alpine, httpd
6. `refactor_video_player_page_delete_eligibility.md` — Deploy completed `can_delete` flag work to fix spurious 403s (code already done)
7. `feature_add_backup_now.md` — Add "Create Backup Now" to admin; unblocks automated Playwright regression suite
8. `refactor_security_upgrade_ssh_key.md` — Move hardcoded console password out of version-controlled template into ansible-vault

**High-Value, Medium Effort**

9. `refactor_ai_video_tagger_scan_methodology_improvements.md` — Switch frame extractor to I-frames for better scene-boundary sampling at zero extra LLM cost
10. `refactor_acls_on_restore_logs.md` — Replace permissive directory mode on restore logs with proper POSIX ACL inheritance
11. `refactor_schema_upload_jobs_token_attribution.md` — Add `upload_jobs.token_id` FK for per-QR-token upload attribution and SaaS billing audit trail
12. `refactor_db_database_admin_soft_deletes.md` — Soft-delete for media files to prevent irreversible loss on admin misclick
13. `feature_iphone_qr_code_gallery_live_refresh.md` — iOS timer polling for real-time approval updates in QR gallery (Phase 1 only; no server changes)
14. `refactor_iphone_report_video_flag_retract.md` — Per-guest report retraction so accidental report taps can be reversed
15. `feature_tests_admin_functions_playwright_role.md` — Wrap Playwright admin tests in an Ansible role with vault-managed credentials

**Worth Doing, Lower SaaS Urgency**

16. `feature_azure_blob_import.md` — Azure Blob import restore path for operators with large libraries
17. `refactor_storage_media_rest_endpoint_followons.md` — Maintenance role skeleton + guest hard-delete to clear files lingering on disk after soft-delete
18. `refactor_quickstart_specific_template.md` — Quickstart-specific compose template to keep OSB distribution in sync with the app
19. `refactor_mcp_server_telemetry_connection.md` — Expose telemetry DB via MCP without disturbing app DB tools

**Implementation-Blocked**

20. `feature_saas_pricing_model.md` — Stripe billing lifecycle; blocked until item 3, RBAC middleware, and storage quota tracking are complete

---

## Critical Path (SaaS cannot launch without these)

### 1. Google + Microsoft IdP App Registrations — Operator Setup

**Status:** Not started — no code changes; can begin immediately, no blockers.  
**Effort:** < 1 hour total — Google Cloud Console + Azure Portal clicks only; credentials dropped into ansible-vault.  
**Impact:** Prerequisite gate for item 3. OIDC cannot be deployed until these registrations exist and their credentials are vaulted. Starting now means item 3 is not blocked by IdP setup the moment item 2 is verified complete.  
**Full steps:** Documented in `feature_security_authentication_migration_jwt_oidc_phase5.md` § "IdP Registration — What Operators Must Do First".

**Google (free):**
- Google Cloud Console → APIs & Services → Credentials → Create OAuth 2.0 Client ID (Web application)
- Register `https://<host>/oidc/callback` for each environment (dev, lab, staging, prod) — multiple redirect URIs supported in one registration
- Register `gighive://oidc/callback` under the iOS application type
- Vault: `oidc_google_client_id`, `oidc_google_client_secret` per environment

**Microsoft Entra ID (free tier):**
- Azure Portal → Entra ID → App registrations → New registration
- Register `https://<host>/oidc/callback` (Web platform) and `gighive://oidc/callback` (Mobile and desktop platform)
- Token configuration: add `groups` optional claim (ID token)
- API permissions: `openid`, `email`, `profile`, `GroupMember.Read.All` → grant admin consent
- Vault: `oidc_ms_client_id`, `oidc_ms_client_secret`, `oidc_ms_tenant_id` per environment

**Note:** Groups-based role mapping for Google requires Google Workspace (paid). Without it, all Google-authenticated users receive the `OIDC_DEFAULT_ROLE` — assign roles manually in the `users` table instead.  
**Score: N/A — prerequisite operator task**

---

### 2. `feature_security_authentication_migration_jwt_implementation.md` — JWT Phases 1–4

**Status:** Pre-implementation — no code written; pending approval.  
**Effort:** Large but fully planned — 31 specific file changes, server + iOS, detailed smoke tests.  
**Impact:** JWT core → PHP guards → iOS cutover → Apache Basic Auth removal. Required before OIDC (item 3), required before RBAC enforcement (SaaS Step 8), and the point at which the `tenant_id DEFAULT 1` transitional default is dropped. The multi-tenant schema (`feature_completed_saas_model_changes.md`) is already in place — individual `users` rows and JWT sessions are the remaining identity plumbing everything else sits on.  
**Score: 10/10**

---

### 3. `feature_security_authentication_migration_jwt_oidc_phase5.md` — OIDC Federation

**Status:** Pre-implementation — requires item 2 complete.  
**Effort:** Large — `mod_auth_openidc` in Docker image, PKCE on iOS, JWKS validation server-side.  
**Impact:** OIDC federation (Google + Microsoft/AAD) + iOS PKCE flow. Maps directly to SaaS model Step 7: JIT user provisioning, ToS gate on first login, per-tenant identity. Without OIDC there is no scalable identity for external tenants.  
**Score: 9.5/10**

---

## Force Multipliers and Near-Zero-Effort Wins

*All of these are cheap enough to be done in parallel with the critical path items.*

### 4. `refactor_ansible_host_versions_across_environments.md`

**Status:** Open — surfaced 2026-08-16 during Phase 3 rollout.  
**Effort:** < 1 hour.  
**Impact:** Version skew across controllers caused a silent failure on lab that did not appear on dev. Force multiplier: every critical-path deploy goes through Ansible. Fix before the next playbook run on any environment.

Confirmed version spread (2026-08-16):

| Controller | Environment(s) | Ansible core |
|------------|----------------|-------------|
| `sodo@pop-os` /home/sodo (pip) | dev + prod | 2.17.12 |
| `sodo@lab.gighive.internal` (pipx) | lab | 2.20.4 |
| `sodo@staging.gighive.internal` (pipx) | staging | 2.20.4 |

Target: standardise all controllers to **≥ 2.20.4**. See `refactor_ansible_host_versions_across_environments.md` for implementation options.  
**Score: 10/10 (force multiplier)**

---

### 5. `refactor_security_docker_hardened_images.md` (Steps 1–3)

**Status:** Not started.  
**Effort:** Trivial — three one-line image tag substitutions.  
**Impact:** ~95% CVE reduction on `mysql`, `alpine`, and `httpd` images. SBOM, SLSA Build Level 3 provenance, cryptographic signing. Free (Apache 2.0).

Changes:
- `docker-compose.yml.j2`: `mysql:8.4` → `docker/mysql:8.4`
- `install.sh.j2`: `alpine` → `docker/alpine`
- `install.sh.j2`, `install.ps1.j2`, `rotate_basic_auth.sh`: `httpd:2.4` → `docker/httpd:2.4`

**Score: 9/10**

---

### 6. `refactor_video_player_page_delete_eligibility.md`

**Status:** Phases 2–3 already implemented; pending deploy + smoke test run.  
**Effort:** Negligible — just run the deploy.  
**Impact:** Server-authoritative `can_delete` flag per media entry eliminates the Keychain token staleness bug that produces spurious 403s for authenticated users. Work is done; cost to ship is near-zero.  
**Score: 8.5/10 (free lunch)**

---

### 7. `feature_add_backup_now.md`

**Status:** Not started.  
**Effort:** Small — 2 new PHP files, 1 admin_system.php edit, 1 Playwright test change.  
**Impact:** Adds "Create Backup Now" button to Section C. Fixes broken Playwright test on fresh installs (only option is a disabled placeholder; test suite times out at Step 5). Unblocks the entire automated regression suite on clean environments.  
**Score: 8/10**

---

### 8. `refactor_security_upgrade_ssh_key.md`

**Status:** Not started.  
**Effort:** < 1 hour — 2–3 files, ~5 lines Ansible + vault entry per environment.  
**Impact:** (a) Moves hardcoded plaintext `ubuntu:yoboiboi` console password from a version-controlled template into ansible-vault. (b) Parameterizes the SSH key path for future ED25519 migration. The vault step alone closes a genuine VCS security finding.

Files to change:
- `ansible/inventories/group_vars/all.yml` (add `ssh_public_key_file`)
- `ansible/roles/cloud_init/tasks/main.yml` (use variable)
- `ansible/roles/cloud_init/templates/user-data.j2` (use `vm_console_password`)
- `secrets.yml` per environment (add `vm_console_password` under vault)
- `ansible/roles/cloud_init/files/user-data` (delete — dead code)

**Score: 7/10**

---

## High-Value, Medium Effort

### 9. `refactor_ai_video_tagger_scan_methodology_improvements.md` (Option C — I-frame)

**Effort:** Trivial — single-line filter change in `frame_extractor.py` plus a fallback guard. No new dependencies.  
**Impact:** I-frames cluster at scene boundaries by codec design — better sampling with zero change to LLM API cost.

Files to change:
- `ansible/roles/ai_worker/files/ai-worker/frame_extractor.py`
- `ansible/roles/docker/templates/.env.j2`
- `ansible/inventories/group_vars/gighive2/gighive2.yml`

**Score: 7.5/10**

---

### 10. `refactor_acls_on_restore_logs.md`

**Effort:** Two `setfacl` commands + two `--acls` flags on bundle `tar` invocations. Precondition: `acl` package on Ubuntu (standard).  
**Impact:** Closes the explicitly-temporary permissive directory mode on restore logs. Applies proper POSIX ACL grants with default inheritance.  
**Score: 7/10**

---

### 11. `refactor_schema_upload_jobs_token_attribution.md`

**Effort:** Trivial — one nullable `ALTER TABLE` + one PHP change in the upload flow.  
**Impact:** Adds `upload_jobs.token_id` FK to `event_upload_tokens`. Directly useful for SaaS billing audit trail: establishes which QR token authorized which upload for quota attribution and billing lifecycle events.  
**Score: 6.5/10**

---

### 12. `refactor_db_database_admin_soft_deletes.md`

**Effort:** Medium — schema migration (`deleted_at`, `deleted_by` on `files`), two new PHP endpoints, UI changes.  
**Impact:** Prevents catastrophic irreversible media loss on admin misclick. Important before SaaS: once real user data exists, a hard-delete-only default is a liability. Hard-delete remains available for intentional purges.  
**Score: 6.5/10**

---

### 13. `feature_iphone_qr_code_gallery_live_refresh.md` — Phase 1 only

**Effort:** Low — iOS-only timer polling; zero server changes.  
**Impact:** The QR gallery is the top-of-funnel product for the SaaS free trial. Phase 1 makes it feel live (guests see approvals in real time) without any backend work.  
**Score: 6.5/10**

---

### 14. `refactor_iphone_report_video_flag_retract.md`

**Effort:** Medium — backend mostly done; iOS coordination + rollout validation remain.  
**Impact:** Fixes per-guest report retraction: accidental report taps are currently irreversible. Correct per-guest report model with aggregate compatibility preserved for admin views.  
**Score: 6/10**

---

### 15. `feature_tests_admin_functions_playwright_role.md`

**Effort:** Medium but fully spec'd.  
**Impact:** Wraps existing Playwright test in an Ansible role (`playwright_admin_tests`). Credentials from group_vars/vault, no manual `.env` drift. Enables consistent automated regression coverage as the codebase grows toward SaaS.  
**Score: 6/10**

---

## Worth Doing, Lower SaaS Urgency

| Item | Notes | Score |
|---|---|---|
| `feature_azure_blob_import.md` | Companion restore path for completed Azure export; useful for operators with large libraries | 5.5 |
| `refactor_storage_media_rest_endpoint_followons.md` | Maintenance role skeleton + guest hard-delete (files linger on disk after soft-delete); hygiene before SaaS storage costs accumulate | 5 |
| `refactor_quickstart_specific_template.md` | Quickstart-specific compose template; keeps OSB distribution in sync with evolving app | 5 |
| `refactor_mcp_server_telemetry_connection.md` | Expose telemetry DB through MCP without disturbing app DB tools; developer productivity | 4.5 |

---

## Implementation-Blocked (High Importance, Not Ready)

### `feature_saas_pricing_model.md`

Design is locked and excellent. Implementation is **blocked** until OIDC (item 3), RBAC middleware (SaaS Step 8), and storage quota tracking (SaaS Step 13) are deployed. Stripe webhook integration and the billing lifecycle cron must not be started before those prerequisites exist.

Importance: 9/10. Readiness: 3/10. Revisit after item 3 is deployed.

---

## Deferred, Low Direct SaaS Impact

| Item | Notes |
|---|---|
| `feature_iphone_video_zoom.md` | Nice UX; not blocking |
| `feature_genres_styles_reconnect.md` | Schema FK debt; niche use case |
| `feature_iphone_upload_catalog.md` | OSB / self-hosted only; not SaaS-relevant |
| `refactor_admin_45_last_steps.md` | UI abstraction deferred; medium regression risk |
| `feature_ai_intelligence_platform.md` | Draft; large strategic investment |
| `feature_trust_and_provenance.md` | Concept stage; future differentiator |
| `feature_future_strategy_licensing_communitypro_monetization.md` | Discussion doc; not actionable |
| `feature_set.md` | Architecture description; not actionable |

---

## Not Actionable / Blocked / Deferred

| File | Reason |
|---|---|
| `refactor_iphone_tuskit_inject_deprecation.md` | Blocked — TUSKit delegate support not available |
| `refactor_security_password_unification.md` | Option C done; A/B cosmetic and explicitly deferred |
| `refactor_security_ssl_cert_lifetime.md` | Dev-only; `dev.gighive.app` workaround is functional |
| `refactor_ansible_www_group_vars.md` | Container-internal `www-data` refs are fixed by design |
| `refactor_iphone_security_insecure_tls_breaks_on_names.md` | Dev-only; IP / `dev.gighive.app` workaround functional |
| `refactor_navigation_user_flow.md` | Stub; open questions, not actionable |
| `refactor_version_number_to_semantic.md` | Explicitly deferred; not required for current telemetry |
| `refactor_edge_aware_authentication_model.md` | Future planning only |
| `refactor_db_fix_event_metadata_example_clarity.md` | Documentation only; no implementation work |
| `refactor_ansible_controller_prereqs.md` | Deferred; Option A (self-contained install) already applied |
| `refactor_convert_legacy_database_csv_python.md` | Legacy CSV import path; low user visibility |
| `refactor_database_utf8_enforcement_if_legacy_cleanup_needed.md` | Conditional on evidence of real encoding issues |
| `refactor_virtualbox_apple_silicon.md` | Infrastructure concern; not on SaaS path |
| `refactor_api_cleanup_if_desired.md` | Explicitly optional |
| `refactor_storage_media_rest_endpoint_azurite.md` | Dev tooling only |
| `refactor_security_recommendations_20260530.md` | Planning doc; all concrete items tracked in their own docs |
| ~~`refactor_security.md`~~ | Deleted — superseded by `feature_security_authentication_migration_jwt.md` |
| `refactor_preasset_librarian_db_ui_based_on_personas.md` | High long-term value; 5-phase architectural refactor, high effort; not SaaS-blocking |

---

## Completed — Removed from Active Ranking

| File | Completed |
|---|---|
| `feature_completed_security_authentication_migration_jwt_ios_auth_cred_type.md` | Complete — `AuthCredential` enum + both `apply(to:)` overloads in place; `AuthSession`, `DatabaseAPIClient`, `KeychainStore`, `LoginView`, `SplashView` all updated |
| `feature_completed_saas_model_changes.md` — Phase 1 (Steps 1–4) + Phase 1a (Step 5) | Complete — schema in `create_media_db.sql`; QR/SAAS_MODE per `feature_completed_iphone_qr_code_support.md`; Phase 2 (Steps 6–21) tracked in items 2 and 3 |
| `refactored_storage_media_rest_endpoint.md` (Tranche 1, Phases 1–5) | 2026-08-19 — all envs verified |
| `refactored_video_player_page.md` (Phases 1–4) | 2026-08-27 — full test suite 27/27 passing |
| `feature_completed_azure_blob_export.md` | 2026-07-25 |
| `feature_completed_catalog_insert.md` | Complete |
| `feature_completed_ai_video_tagger.md` | Complete |
| `feature_completed_ai_video_tagger_osb.md` | Complete |
| `feature_completed_edit_database_interactively.md` | Complete |
| `feature_completed_home_page_video_background.md` | Complete |
| `feature_completed_import_media_from_zip.md` | Complete |
| `feature_completed_install_windows_installer_ps1.md` | Complete |
| `feature_completed_iphone_qr_code_implementation.md` | Complete |
| `feature_completed_iphone_qr_code_shared_gallery.md` | Complete |
| `feature_completed_iphone_qr_code_shared_gallery_implementation.md` | Complete |
| `feature_completed_iphone_qr_code_support.md` | Complete |
| `feature_completed_mcp_server.md` | Complete |
| `feature_completed_progress_meter_heartbeat.md` | Complete |
| `feature_completed_tagging_manual_tagging.md` | Complete |
| `refactored_iphone_qr_code_extend_time.md` | 2026-07-17 |
| `refactored_iphone_qr_code_gallery_access_for_all.md` | 2026-07-15 |
| `refactored_iphone_qr_code_gallery_notifications.md` | Core work complete |
| `refactored_iphone_qr_code_gallery_thumbnails.md` | Complete |
| `refactored_qr_code_users_splash_page.md` | Complete |
| `refactored_azure_blob_export_import_session_storage.md` | Complete |
| `refactor_os_add_swap.md` | Complete |
| `refactored_ai_jobs_upload_jobs_event_key_db_schema.md` | Complete |
| `refactored_upload_folder_nav_away_cancels_fix.md` | Complete |
| `refactored_email_address.md` | Complete |
| `refactored_db_fix_event_metadata_duplication.md` | Complete |

---

## Quick Reference — Effort by Category

| Category | Items |
|----------|-------|
| Operational blocker (< 1 hour) | 4 (Ansible versions) |
| SaaS critical path — IdP setup | 1 (Google + Microsoft app registrations) |
| SaaS critical path — auth | 2 (JWT implementation), 3 (OIDC) |
| Trivial code change (< 1 day) | 5, 8, 9, 10, 11 |
| Small focused change (1–3 days) | 6 (deploy only), 7 |
| Medium refactor (1–2 weeks) | 12, 14, 15 |
| Implementation-blocked | `feature_saas_pricing_model.md` (needs item 3 first) |
