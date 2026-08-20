# Refactor Status — Bang for Buck Analysis (2026-08-12)

**Scope:** The `gighiveinfra/docs` refactor-candidate set was reviewed and ranked as of 2026-08-12.  
**Last updated:** 2026-08-19 — Tranche 1 Phases 1–4 complete; #1 status updated accordingly.  
**Method:** Impact divided by effort — highest ratio wins. Strategic value is a second driver for foundational work that unlocks larger architecture changes, and status of already-completed work is factored in (an already-built feature waiting for deployment scores very high).

---

## Scoring Dimensions

| Dimension | What was weighed |
|-----------|-----------------|
| **Impact** | Product / operational / security value delivered |
| **Effort** | Lines of code, risk level, coordination overhead, deployment complexity |
| **Strategic Value** | Long-term platform leverage, prerequisite architecture, future scale-out impact |
| **Status** | Already-built work pending only verification or deployment scores near the top |

---

## Top 8 — Bang for Buck

### 0. `refactor_ansible_host_versions_across_environments.md` — Score: 10/10 (operational blocker)

**Status:** Open — surfaced 2026-08-16 during Phase 3 rollout.  
**Effort:** Option C (version assertion in `site.yml`) is a single task — under 30 minutes.
Option A (pinned `controller-requirements.txt` + `installprerequisites` update) is 1–2 hours across all four controller machines.  
**Impact:** Version skew across the four controller machines (2.17.12 on prod → 2.20.4 on
staging) caused a silent failure on lab that did not appear on dev. Any playbook task that
relies on a behavioural default that changed between Ansible versions will pass on one
environment and fail on another — with no warning. This has already cost multiple deploy
cycles during Phase 3.

**Why it is ranked #0 (above everything else):**
Every other refactor in this list is deployed via Ansible. If the Ansible version is not
consistent, the test signal from dev is unreliable and failures surface unpredictably in
later environments. This is a **force multiplier on all other work** — fixing it makes
every subsequent rollout safer. The Option C assertion takes minutes and should be done
before the next Ansible run on any environment.

Confirmed version spread (2026-08-16):

| Controller | Environment(s) | Ansible core |
|------------|----------------|-------------|
| `sodo@pop-os` /home/sodo (pip) | dev + prod | 2.17.12 |
| `sodo@lab.gighive.internal` (pipx) | lab | 2.20.4 |
| `sodo@staging.gighive.internal` (pipx) | staging | 2.20.4 |

Target: standardise all controllers to **≥ 2.20.4**. See
`refactor_ansible_host_versions_across_environments.md` for implementation options.

---

### 1. `refactor_storage_media_rest_endpoint.md` — Tranche 1 — Score: 10/10

**Status:** Nearly complete — Phases 1–4 complete all envs (2026-08-16); Phase 5 (Local/VirtualBox final step) awaiting approval.  
- Phase 1 (runtime config, IMDS access): **Complete — all envs**  
- Phase 2 (PHP storage abstraction layer): **Complete — all envs**  
- Phase 3 (PHP tus upload server, tusd retired): **Complete — all envs**  
- Phase 4 (media streaming endpoint, `media-stream.php`): **Complete — all envs**  
- Phase 5 (Local/VirtualBox final step): **Not started — awaiting approval**  
- Phases 6–11 (Tranche 2): Deferred — Azure activation, not in scope until SaaS rollout  

**Effort:** Phases 1–5 / 2 PRs. This tranche is the near-term build: it creates the abstraction layer, removes the `tusd` container, and keeps local storage as the backend for now.  
**Impact:** Moves the media stack toward a clean separation of compute and storage by putting the media path behind PHP service classes instead of direct filesystem coupling. Tranche 1 does **not** switch Azure Blob on yet; it keeps the local storage model while preparing the codebase for the later SaaS rollout.

**Why it wins:** This is the strongest strategic lever in the ranking because it creates the compute/storage boundary needed for the multi-client SaaS model without forcing the full Azure migration yet. It delivers the architectural separation now, while the Azure Blob activation stays deferred until the SaaS rollout is ready in roughly 3–6 months.

Tranche 1 docs:
- `refactor_storage_media_rest_endpoint.md` (architecture and decision doc)
- `refactor_storage_media_rest_endpoint_implementation.md` (build guide)
- `refactor_storage_media_rest_endpoint_followons.md` (deferred cleanup items)

**Deferred follow-on:** Tranche 2 (Azure activation, private endpoint, IMDS auth, Blob cutover, backfill) is intentionally not counted in this ranking and will be revisited when the SaaS rollout is ready.

---

### 2. `refactor_security_docker_hardened_images.md` (Steps 1–3) — Score: 9/10

**Effort:** Three one-line image tag changes. Throwaway containers — no stack reconfiguration, no downtime, no entrypoint rework needed.  
**Impact:** Up to 95% CVE reduction on `mysql`, `alpine`, and `httpd` images. Adds SBOM, SLSA Build Level 3 provenance, and cryptographic signing. Free (Apache 2.0, no subscription).  
**Why it wins:** A change measured in characters delivers measurable supply chain security improvement. Step 4 (runtime hardening: `cap_drop`, `no-new-privileges`, MySQL port binding `0.0.0.0:3306` → `127.0.0.1:3306`) is the natural follow-on at medium effort.

Steps 1–3 changes:
- `docker-compose.yml.j2`: `mysql:8.4` → `docker/mysql:8.4`
- `install.sh.j2`: `alpine` → `docker/alpine`
- `install.sh.j2`, `install.ps1.j2`, `rotate_basic_auth.sh`: `httpd:2.4` → `docker/httpd:2.4`

---

### 3. `refactor_ai_video_tagger_scan_methodology_improvements.md` — Score: 7.5/10

**Effort (Option C — I-frame):** Single-line filter change in `frame_extractor.py` plus a fallback guard. No new dependencies (ffmpeg already used). One env var added.  
**Impact:** I-frames cluster at scene boundaries by codec design — better sampling with zero change to LLM API cost. Option A (scene-change detection) delivers higher quality but requires more work; Option B (two-pass) roughly doubles API cost.  
**Why it wins (Option C specifically):** Drop-in replacement, lowest risk, no new external tool, validated by industry precedent. Recommended first step in the doc's own implementation order.

Files to change:
- `ansible/roles/ai_worker/files/ai-worker/frame_extractor.py`
- `ansible/roles/docker/templates/.env.j2`
- `ansible/inventories/group_vars/gighive2/gighive2.yml`

---

### 4. `refactor_acls_on_restore_logs.md` — Score: 7/10

**Effort:** Two `setfacl` commands on the host directory, two `--acls` flags on the bundle `tar` invocations. Precondition: `acl` package on Ubuntu (standard).  
**Impact:** Fixes a real operational gap — restore logs directory is currently only writable by `www-data` via permissive modes. This applies proper POSIX ACL grants with default inheritance so new files inside the directory also get the right permissions.  
**Why it wins:** The current workaround (permissive directory mode) is explicitly called out as temporary in the doc. Two commands close it properly.

---

### 5. `refactor_security_upgrade_ssh_key.md` — Score: 7/10

**Effort:** 2–3 files, ~5 lines of Ansible + a vault entry per environment.  
**Impact:** Two wins: (a) parameterizes the SSH key path so RSA → ED25519 fleet migration becomes a single `group_vars` change; (b) moves the hardcoded plaintext console password `ubuntu:yoboiboi` from a version-controlled template into `ansible-vault`. The vault step alone closes a genuine version-control security finding. Clear implementation checklist provided.  
**Why it wins:** Cheap, correct, and the console password hardcode is a real risk that pays off immediately regardless of whether the ED25519 migration happens now.

Files to change:
- `ansible/inventories/group_vars/all.yml` (add `ssh_public_key_file`)
- `ansible/roles/cloud_init/tasks/main.yml` (use variable)
- `ansible/roles/cloud_init/templates/user-data.j2` (use `vm_console_password`)
- `secrets.yml` per environment (add `vm_console_password` under vault)
- `ansible/roles/cloud_init/files/user-data` (delete — dead code)

---

### 6. `refactor_db_database_admin_soft_deletes.md` — Score: 6.5/10

**Effort:** Medium — schema migration (`deleted_at`, `deleted_by` on `files` table), two new PHP endpoints, and UI changes in the Media Library.  
**Impact:** Prevents catastrophic irreversible media loss when an admin misclicks Delete. Physical bytes are not deleted; the row is hidden and restorable. The recovery tooling (`upload_media_by_hash.py`) exists as a fallback but is operationally expensive and not always viable.  
**Why it wins:** The existing hard-delete is a one-way door. Soft delete makes the default action reversible without removing the hard-delete option for intentional purges. Clear migration plan and testing checklist are already in the doc.

---

### 7. `refactor_iphone_report_video_flag_retract.md` — Score: 6/10

**Effort:** Medium. The backend plan is detailed and most of Phase 1 is already marked complete in the document, but finishing the work still requires coordinated iOS client changes and rollout validation.  
**Impact:** Fixes a real user-facing moderation UX flaw: accidental guest report taps are currently irreversible, and the existing aggregate boolean model cannot represent per-guest retraction safely. The end state is a correct per-guest report model with aggregate compatibility preserved for admin views.  
**Why it makes the cut now:** It was the strongest near-miss in the prior analysis. With the completed gallery-thumbnails and QR splash-page items removed, this is the next most valuable candidate in the remaining set.

---

## What Did Not Make the Cut

### Completed — removed from active ranking

| File | Completed |
|------|-----------|
| `refactored_iphone_qr_code_extend_time.md` | 2026-07-17 |
| `refactored_iphone_qr_code_gallery_access_for_all.md` | 2026-07-15 |
| `refactored_iphone_qr_code_gallery_notifications.md` | Core work complete; optional cleanup deferred to `refactor_storage_media_rest_endpoint_followons.md` |
| `refactored_iphone_qr_code_gallery_thumbnails.md` | Completed; date not recorded |
| `refactored_qr_code_users_splash_page.md` | Completed; date not recorded |
| `refactored_azure_blob_export_import_session_storage.md` | Completed; date not recorded |
| `refactor_os_add_swap.md` | Completed; date not recorded |

### Deferred — intentionally out of scope

| File | Primary Reason |
|------|----------------|
| `refactor_security.md` | Strategic OIDC/JWT roadmap; large and complex |
| `refactor_security_recommendations_20260530.md` | Useful planning doc; concrete items covered by individual refactor docs already ranked |
| `refactor_preasset_librarian_db_ui_based_on_personas.md` | High long-term value; 5-phase architectural refactor, high effort |
| `refactor_version_number_to_semantic.md` | Explicitly deferred; not required for current telemetry |
| `refactor_edge_aware_authentication_model.md` | Future planning only; current bypass-Cloudflare approach is correct for now |
| `refactor_db_fix_event_metadata_example_clarity.md` | Documentation clarification only, no implementation work |

### Not actionable — blocked or workaround in place

| File | Primary Reason |
|------|----------------|
| `refactor_iphone_tuskit_inject_deprecation.md` | Not actionable until TUSKit adds delegate support or CA is deployed |
| `refactor_security_password_unification.md` | Option C already done; A/B explicitly deferred as cosmetic |
| `refactor_security_ssl_cert_lifetime.md` | Dev-only concern; `dev.gighive.app` is the recommended workaround and it works |
| `refactor_ansible_www_group_vars.md` | Remaining hardcoded `www-data` instances are inside containers where the value is always correct |
| `refactor_iphone_security_insecure_tls_breaks_on_names.md` | Dev-only; workaround (use IP or `dev.gighive.app`) is functional |
| `refactor_navigation_user_flow.md` | Stub with open questions — not actionable yet |

---

## Quick Reference — Effort by Category

| Category | Items | Notes |
|----------|-------|-------|
| Operational blocker (< 1 hour) | #0 | Force multiplier — fix before next Ansible run |
| Strategic platform tranche | #1 | Compute/storage separation now; full SaaS rollout later |
| Trivial code change (< 1 day) | #2, #3, #4 | Measured in lines, not files |
| Small focused refactor (1–3 days) | #5 | Clear plan, no coordination overhead |
| Medium refactor (1–2 weeks) | #6, #7 | Schema / endpoints / UI / client coordination |
