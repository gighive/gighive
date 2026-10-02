# PR: Automated Upload / Import Testing (Admin Sections 3A / 3B / 4 / 5)

## Summary

Add an Ansible-driven, repeatable test harness for validating GigHive’s upload/import capabilities in `admin.php`:

- Section 3A (Legacy): upload a single CSV and reload database (destructive)
- Section 3B (Normalized): upload `sessions.csv` + `session_files.csv` and reload database (destructive)
- Section 4: scan a folder, build a SHA-256 manifest, and reload database (destructive, async job)
- Section 5: scan a folder, build a SHA-256 manifest, and add to database (non-destructive, async job)

The tests are intended to support the foundational data model cutover described in:

- `docs/pr_librarianAsset_musicianSession_changeSet.md`

This PR is documentation-only and defines the agreed requirements and implementation plan; no code changes are made until the plan is approved.

## Agreed Requirements

### Functional

- The harness MUST support a test matrix covering:
  - Section 3A legacy import:
    - `APP_FLAVOR=gighive`
    - `APP_FLAVOR=defaultcodebase`
  - Section 3B normalized import:
    - `APP_FLAVOR=gighive`
    - `APP_FLAVOR=defaultcodebase`
  - Section 4 manifest reload import (async, destructive)
  - Section 5 manifest add import (async, non-destructive)

- The harness MUST be runnable from the existing Ansible control host / playbooks workflow.
- The harness MUST not require GitHub Actions / CI integration.

### Safety / Guardrails

- The harness MUST be explicitly opt-in.
- Destructive variants (3A, 3B, 4) MUST require a dedicated confirmation variable (e.g. `allow_destructive=true`).
- The playbook SHOULD refuse to run against production inventory/groups (exact detection mechanism TBD and must match how environments are labeled today).

### Observability / Artifacts

- The harness MUST capture:
  - HTTP responses from import endpoints
  - Async job IDs and final job status payloads
  - A compact summary of pass/fail per variant
- The harness SHOULD capture diagnostic artifacts on failure:
  - import worker log snippet (if present on target)
  - DB counts / queries used for assertions

### Variables

The upload test harness is implemented as an Ansible role (`ansible/roles/upload_tests`). The role will use the following Ansible variables.

These variables SHOULD be defined in per-environment `group_vars` (consistent with the rest of this repo), starting with:

- `ansible/inventories/group_vars/gighive2/gighive2.yml`

New role variables (planned):

1. `run_upload_tests`
   - Master opt-in flag; the role should refuse to run unless enabled.
2. `allow_destructive`
   - Secondary opt-in required for destructive variants (Sections 3A, 3B, 4).
3. `upload_test_destructive_confirm`
   - If true, prompt for a final interactive confirmation before running destructive variants.
4. `upload_tests_csv_dir`
   - Control-host directory containing the CSV fixtures.
5. `upload_test_manifest_source_roots_reload`
   - Control-host directory roots (list) to scan for the Section 4 manifest reload test.
6. `upload_test_manifest_source_roots_add`
   - Control-host directory roots (list) to scan for the Section 5 manifest add test.
7. `upload_test_event_date`
   - `YYYY-MM-DD` date written into each manifest item (default SHOULD be the playbook run date, e.g. `{{ ansible_date_time.date }}`).
8. `upload_test_org_name`
   - Manifest `org_name` field value.
9. `upload_test_event_type`
   - Manifest `event_type` field value.
10. `upload_test_variants`
   - Variant matrix controlling which tests are executed. Proposed format is a list of objects with `section` and (when applicable) `app_flavor`.
11. `upload_test_run_upload_media_by_hash`
   - If true, run `tools/upload_media_by_hash.py` after Section 4/5 completes.
12. `upload_test_ssh_target`
   - SSH target for `upload_media_by_hash.py` (rsync destination).
13. `upload_test_restore_db_after`
   - If true, the role will run a final normalized (Section 3B) import based on `app_flavor` so the instance ends in the correct default DB state.

Example `group_vars` configuration (from `ansible/inventories/group_vars/gighive2/gighive2.yml`):

```yaml
run_upload_tests: false
allow_destructive: false
upload_test_destructive_confirm: true

upload_tests_csv_dir: "{{ repo_root }}/ansible/fixtures/upload_tests/csv"

upload_test_manifest_source_roots_reload:
  - "{{ video_reduced }}"
upload_test_manifest_source_roots_add:
  - "{{ audio_reduced }}"

upload_test_event_date: "{{ ansible_date_time.date }}"
upload_test_org_name: "default"
upload_test_event_type: "band"

upload_test_variants:
  - name: "3a_legacy_import_gighive"
    section: "3a"
    app_flavor: "gighive"
  - name: "3a_legacy_import_defaultcodebase"
    section: "3a"
    app_flavor: "defaultcodebase"
  - name: "3b_normalized_import_gighive"
    section: "3b"
    app_flavor: "gighive"
  - name: "3b_normalized_import_defaultcodebase"
    section: "3b"
    app_flavor: "defaultcodebase"
  - name: "4_manifest_reload"
    section: "4"
  - name: "5_manifest_add"
    section: "5"

upload_test_run_upload_media_by_hash: false
upload_test_ssh_target: "{{ ansible_user }}@{{ gighive_host }}"

upload_test_restore_db_after: false
```

DB connection variables are sourced from existing `group_vars` (see DB section below) and are not duplicated as new role variables.

### Test Inputs

- Section 3A/3B tests will use user-supplied fixtures:
  - A single fixtures directory on the Ansible control host:
    - `ansible/fixtures/upload_tests/csv/`
  - The harness will reference this directory via a single Ansible variable:
    - `upload_tests_csv_dir`
  - Expected files within that directory:
    - `databaseSmall.csv` (Section 3A legacy import; `APP_FLAVOR=gighive`)
    - `databaseLarge.csv` (Section 3A legacy import; `APP_FLAVOR=defaultcodebase`)
    - `sessionsSmall.csv` and `session_filesSmall.csv` (Section 3B normalized; `APP_FLAVOR=gighive`)
    - `sessionsLarge.csv` and `session_filesLarge.csv` (Section 3B normalized; `APP_FLAVOR=defaultcodebase`)

- Section 4/5 tests will use a standardized local directory on the Ansible control host:
  - The harness generates a manifest JSON by scanning local directory roots (lists) and computing SHA-256
  - The harness then posts the manifest to the server and polls for completion

Defaults SHOULD be derived from existing `group_vars` media fixture variables:

- Section 4 (reload): `upload_test_manifest_source_roots_reload: ["{{ video_reduced }}"]`
- Section 5 (add): `upload_test_manifest_source_roots_add: ["{{ audio_reduced }}"]`

### Integration with Step-2 “actual file upload” tool

- After Section 4/5 (manifest-based) DB import completes, the harness SHOULD optionally run:
  - `ansible/roles/docker/files/apache/webroot/tools/upload_media_by_hash.py`

This implements the two-step workflow described in `admin.php`:

1) Import metadata + hashes into DB
2) Upload/copy binaries by hash into server bind mounts

This tool will run on the Ansible control host and will:
- query MySQL `files` rows
- rsync files to the VM via `--ssh-target`

DB connection values used by the harness (and passed to `upload_media_by_hash.py`) SHOULD be sourced from existing Ansible `group_vars` variables already used to render the MySQL env file template:

- `ansible/roles/docker/templates/.env.mysql.j2`

Specifically:

- `mysql_database` (maps to `MYSQL_DATABASE`)
- `mysql_user` (maps to `MYSQL_USER`)
- `mysql_appuser_password` (maps to `MYSQL_PASSWORD`)
- `mysql_db_host` (maps to `DB_HOST`)

The harness SHOULD avoid introducing a separate set of DB credential variables unless strictly necessary.

## Current System Fit (Ansible + Docker)

### Placement in the Ansible install flow

The `upload_tests` role SHOULD run after `validate_app` during new instance provisioning so each newly instantiated GigHive instance is verified for upload/import functionality.

This MUST remain opt-in via `run_upload_tests` (and `allow_destructive` for destructive variants) so normal installs do not unexpectedly run destructive import tests.

If `upload_test_restore_db_after=true`, the role SHOULD perform a final normalized import (Section 3B) after running the test matrix so the DB ends in a deterministic “correct” state for the selected `app_flavor`:

- `app_flavor: gighive` -> `sessionsSmall.csv` + `session_filesSmall.csv`
- `app_flavor: defaultcodebase` -> `sessionsLarge.csv` + `session_filesLarge.csv`

### Relevant application endpoints (authoritative)

From `ansible/roles/docker/files/apache/webroot/admin.php` and the import endpoint implementations:

- Section 3A:
  - `POST import_database.php` (multipart form upload)

- Section 3B:
  - `POST import_normalized.php` (multipart form upload)

- Section 4 (async, destructive):
  - `POST import_manifest_reload_async.php` (JSON body)
  - `GET import_manifest_status.php?job_id=...` (poll)
  - `POST import_manifest_cancel.php` (cancel)

- Section 5 (async, non-destructive):
  - `POST import_manifest_add_async.php` (JSON body)
  - `GET import_manifest_status.php?job_id=...` (poll)
  - `POST import_manifest_cancel.php` (cancel)

Note: manifest endpoints require admin access and return HTTP 403 if the authenticated user is not `admin`.

### Manifest JSON schema (current implementation)

From `import_manifest_lib.php` payload validation, the POST body must be JSON:

- top-level:
  - `org_name` (string, default `default`)
  - `event_type` (string, default `band`)
  - `items` (array, required, non-empty)

- each item:
  - `checksum_sha256` (64-char hex, required)
  - `file_type` (`audio` or `video`, required)
  - `file_name` (required)
  - `source_relpath` (string; may be empty)
  - `event_date` (`YYYY-MM-DD`, required)
  - `size_bytes` (integer; optional)

### Notes on schema cutover alignment

The cutover requirements in `docs/pr_librarianAsset_musicianSession_changeSet.md` make the Event/Asset model canonical for both app flavors.

However, the current manifest importer implementation (as of this doc) still writes to the legacy-ish tables (`sessions`, `songs`, `files`, etc.).

The testing harness is still valuable now (repeatability and regressions), but the DB assertions MUST be updated as the PR5 manifest import cutover lands.

## Implementation Plan (Phased)

### Phase 0: Document-only planning and discovery (this PR)

- Define the agreed test variants and safety gates
- Identify the exact endpoints and payload formats
- Define expected assertions per variant (initially table-count-level, later canonical invariants)

### Phase 1: Add playbook-first test harness (Ansible-owned)

Create a dedicated Ansible role to encapsulate the test harness logic, integrated into the main `site.yml` flow.

Proposed structure:

- `ansible/playbooks/site.yml`
  - runs `upload_tests` after `validate_app`
  - the role is tagged so it can be invoked via `--tags upload_tests`

- `ansible/roles/upload_tests/` (new role)
  - `tasks/main.yml`
  - `tasks/test_3a_legacy_import.yml`
  - `tasks/test_3b_normalized_import.yml`
  - `tasks/test_4_manifest_reload.yml`
  - `tasks/test_5_manifest_add.yml`
  - `tasks/poll_manifest_job.yml` (shared helper)
  - `tasks/assert_db_invariants.yml` (shared helper)

No changes to the application code are required to implement the harness.

### Phase 2: Standardized local directory -> manifest generation (control host)

Add a small control-host-side helper script or Ansible task sequence to:
- walk a local directory
- compute SHA-256 per file
- infer `file_type` from extension
- emit the manifest JSON matching the schema above

This replaces the browser-only hashing path used by the `admin.php` UI.

### Phase 3: Optional Step-2 binary upload execution

Optionally run `tools/upload_media_by_hash.py` on the Ansible control host after Section 4/5 completes.

This requires:
- control host has `mysql`, `rsync`, and `ssh`
- correct DB credentials available to the harness

### Phase 4: Update assertions for Event/Asset cutover

As PR4/PR5 land (Upload API cutover + Manifest import cutover), update the harness assertions to validate canonical invariants:

- assets deduped by checksum
- event↔asset links created and unique
- event-scoped item typing behavior (capture defaults)

## Ansible Changes (roles/files)

### New Ansible content (planned)

- Update existing playbook:
  - `ansible/playbooks/site.yml` (add `upload_tests` role after `validate_app`)

- Update existing group vars (role configuration lives in `group_vars`, starting in dev):
  - `ansible/inventories/group_vars/gighive2/gighive2.yml`

- New role:
  - `ansible/roles/upload_tests/tasks/main.yml`
  - `ansible/roles/upload_tests/tasks/test_3a_legacy_import.yml`
  - `ansible/roles/upload_tests/tasks/test_3b_normalized_import.yml`
  - `ansible/roles/upload_tests/tasks/test_4_manifest_reload.yml`
  - `ansible/roles/upload_tests/tasks/test_5_manifest_add.yml`
  - `ansible/roles/upload_tests/tasks/poll_manifest_job.yml`
  - `ansible/roles/upload_tests/tasks/assert_db_invariants.yml`

- Optional control-host helper script (name TBD) for manifest generation.

### Existing files referenced (not changed by the harness)

- Application endpoints:
  - `ansible/roles/docker/files/apache/webroot/import_database.php`
  - `ansible/roles/docker/files/apache/webroot/import_normalized.php`
  - `ansible/roles/docker/files/apache/webroot/import_manifest_*`

- Step-2 uploader:
  - `ansible/roles/docker/files/apache/webroot/tools/upload_media_by_hash.py`

## Open Questions (tracked)

- How should the harness determine and enforce “non-prod” targets (inventory naming convention)?
- For manifest tests (4/5), should `event_date` be:
  - explicitly set by a playbook var, or
  - inferred from filename patterns?
- Should the harness run 4/5 under both flavors, or treat them as flavor-agnostic until the cutover changes require otherwise?

## Notes (idempotency + Ansible best practices)

### Idempotency

- Sections 3A / 3B / 4 are destructive (they overwrite DB state) but repeatable; rerunning with the same inputs should converge to the same final DB contents.
- Section 5 is additive; it should be idempotent when rerun with an identical manifest if the importer dedupes by `checksum_sha256` as intended.
- `upload_media_by_hash.py` is idempotent by default (skips already-present destination files unless force options are used).

### Post-run DB restore behavior

The role performs an optional “restore” at the end of the run by re-running the normalized import (Section 3B) for the inventory’s `app_flavor`.

Purpose:
- Ensure the final database state after running destructive variants (3A/3B/4) is predictable.
- Prevent later playbook steps (or a subsequent test run) from inheriting a partially-modified DB.

When it runs:
- Runs only if destructive variants were selected (any of 3A/3B/4).
- Defaults to running unless explicitly disabled.

Control variable:
- `upload_test_restore_db_after`
  - If `true` or undefined: restore runs.
  - If `false`: restore is skipped.

Restore-only mode:
- `upload_test_restore_only: true`
  - Skips running the configured variants and only performs the post-run normalized restore.
  - Still requires `allow_destructive=true` (since restore truncates/imports).

Variable semantics (two switches):

`upload_test_restore_only`:
- `false` (normal mode)
  - Run the configured `upload_test_variants`
  - Then optionally run the post-run restore (controlled by `upload_test_restore_db_after`)
- `true` (restore-only mode)
  - Skip all variants
  - Run only the normalized restore

`upload_test_restore_db_after`:
- `false`
  - Do not run the restore at the end of a normal test run
- `true`
  - Run the restore at the end of a normal test run (when destructive variants were selected)

Quick recipes:
- Run tests + restore at end
  - `upload_test_restore_only: false`
  - `upload_test_restore_db_after: true`
- Run tests + do NOT restore
  - `upload_test_restore_only: false`
  - `upload_test_restore_db_after: false`
- Restore only (no tests)
  - `upload_test_restore_only: true`
  - (`upload_test_restore_db_after` does not matter)

Notes:
- This “restore” is itself destructive (it truncates/imports), so it is intentionally gated behind the “destructive variants selected” check.

### Best-practice refactors applied

- Polling (async job status) is implemented with `ansible.builtin.uri` instead of raw `curl`.
- Async JSON POSTs for Sections 4/5 are implemented with `ansible.builtin.uri`.
- DB assertions use `community.docker.docker_container_exec` instead of raw `docker exec`.
- Manifest generation extension allowlists are sourced from `group_vars` (`gighive_upload_audio_exts` / `gighive_upload_video_exts`).

Remaining pragmatic implementations:

- Multipart uploads for Sections 3A/3B still use `curl` (kept for simplicity).
- Manifest generation is currently inline Python in tasks (can be extracted into role scripts if/when it grows).
