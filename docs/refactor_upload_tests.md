# Refactor Proposal: `upload_tests` role (documentation-only)

This document proposes *optional* refactors for the `ansible/roles/upload_tests` role to reduce duplicated code, improve maintainability, and align with Ansible best practices and idempotency principles.

**Important:** This is documentation only. No refactor has been implemented yet.

## Goals

- Reduce duplication across `test_3a.yml`, `test_3b.yml`, `test_4.yml`, `test_5.yml`.
- Keep the harness idempotent (repeatable runs converge; “test” tasks remain `changed_when: false`).
- Centralize repeated logic into reusable `include_tasks` helpers.
- Minimize risk: prefer staged refactors with small diffs.

## Non-goals

- Changing application behavior or endpoints.
- Replacing multipart upload `curl` usage (kept for simplicity).
- Changing fixture semantics or assertion semantics.

## Current task files in scope

Location: `ansible/roles/upload_tests/tasks/`

- `main.yml`
- `test_3a.yml` (legacy CSV import)
- `test_3b.yml` (normalized CSV import)
- `test_4.yml` (manifest reload async)
- `test_5.yml` (manifest add async)
- `poll_manifest_job.yml`
- `assert_db_invariants.yml`

## Duplicated code / patterns found

### 1) Manifest generation (duplicated between Section 4 and 5)

- `test_4.yml` and `test_5.yml` both generate a manifest JSON on the control host via inline Python.
- Differences are limited to:
  - input roots variable
    - reload: `upload_test_manifest_source_roots_reload`
    - add: `upload_test_manifest_source_roots_add`
  - output path
    - reload: `/tmp/upload_test_manifest_reload.json`
    - add: `/tmp/upload_test_manifest_add.json`

**Refactor candidate:** `generate_manifest.yml` helper task.

### 2) Step-2 uploader invocation (duplicated between Section 4 and 5)

- `test_4.yml` and `test_5.yml` both invoke `tools/upload_media_by_hash.py` via a large `argv:` list.
- Differences are limited to the “source roots” variable used.

**Refactor candidate:** `run_upload_media_by_hash.yml` helper task.

### 3) DB counts query logic is duplicated

- `assert_db_invariants.yml` contains the canonical “query sessions/files counts” and parsing.
- `test_4.yml` duplicates a “counts before” query.
- `test_5.yml` duplicates “counts before” and “counts after” and then parses/asserts separately.

**Refactor candidates:**

- Option A: create a `query_db_counts.yml` helper that can store results under a caller-provided prefix.
- Option B: extend `assert_db_invariants.yml` to optionally return the parsed counts as caller-provided fact names.

### 4) Import job id extraction (duplicated between 3A and 3B)

- `test_3a.yml` and `test_3b.yml` both:
  - parse response JSON
  - assert `success`
  - extract job id from `steps[0].message` using the same regex
  - debug when missing
  - assert non-empty job id

**Refactor candidate:** `extract_import_job_id.yml` helper.

### 5) Expected “files loaded” calculation from prepped CSV is duplicated-ish

- Both `test_3a.yml` and `test_3b.yml` derive expected file counts from the job’s generated `prepped_csvs/files.csv`.
- Both account for `UNIQUE(checksum_sha256)` by computing:

  `expected_loaded_rows = empty_checksum_rows + unique_nonempty_checksums`

- `test_3b.yml` contains a more robust implementation:
  - detects checksum column name
  - emits diagnostic JSON

**Refactor candidate:** standardize both sections to a shared helper that returns the same diagnostic structure.

## Idempotency notes

- The upload tests are *intentionally not idempotent in the strict Ansible “no changes” sense* because they exercise destructive imports.
- They are idempotent in the **test harness** sense:
  - rerunning the same test variant(s) yields predictable DB contents (converges)
  - tasks are generally marked `changed_when: false` so the play output doesn’t imply config drift

## Proposed refactor stages (recommended order)

### Stage 1 (lowest risk): factor out pure duplication in 4/5

- Add `tasks/generate_manifest.yml`
  - inputs: `upload_tests_manifest_roots`, `upload_tests_manifest_path`
  - output: register/stdout provides item count
- Add `tasks/run_upload_media_by_hash.yml`
  - input: `upload_tests_source_roots`
  - guarded by `upload_test_run_upload_media_by_hash | bool`

**Why first:**
- This is almost pure mechanical extraction.
- Minimal behavioral surface area.

### Stage 2: centralize DB count “before/after” queries

- Add `tasks/query_db_counts.yml` that:
  - queries the DB via `community.docker.docker_container_exec`
  - parses sessions/files counts
  - stores as facts under a prefix (e.g., `upload_tests_db_before_sessions_count` / `upload_tests_db_before_files_count`).

**Why second:**
- Removes brittle parsing duplication.
- Clarifies invariants and reduces chance of future inconsistencies.

### Stage 3: centralize import job id extraction and prepped files expected-count logic

- Add `tasks/extract_import_job_id.yml`
- Add `tasks/derive_expected_files_from_prepped_csv.yml`
  - return structured diagnostics (like 3B)

**Why third:**
- This touches core “expected counts” logic.
- It’s safe, but higher risk because it’s close to the previously-buggy logic.

## Suggested helper-task interfaces (sketch)

These are *proposed* variable names and behaviors.

### `generate_manifest.yml`

Inputs:
- `upload_tests_manifest_roots` (list)
- `upload_tests_manifest_path` (string)

Outputs:
- `upload_tests_manifest_item_count` (register stdout integer)

### `run_upload_media_by_hash.yml`

Inputs:
- `upload_tests_source_roots` (list)

Uses existing vars:
- `repo_root`
- `upload_test_ssh_target`
- `mysql_db_host`, `mysql_user`, `mysql_appuser_password`, `mysql_database`

### `query_db_counts.yml`

Inputs:
- `upload_tests_db_counts_prefix` (string)

Outputs facts:
- `{{ upload_tests_db_counts_prefix }}_sessions_count`
- `{{ upload_tests_db_counts_prefix }}_files_count`

### `extract_import_job_id.yml`

Inputs:
- `upload_tests_resp`

Outputs:
- `upload_tests_job_id`

### `derive_expected_files_from_prepped_csv.yml`

Inputs:
- `upload_tests_job_id`

Outputs:
- `upload_tests_expected_files_count`
- (optional) `upload_tests_prepped_files_diag_json`

## Validation / regression strategy

When refactoring, run in this order to limit variables:

- Run 3A only
- Run 3B only
- Run 4 only
- Run 5 only
- Run full suite

Then confirm:
- same pass/fail behavior as pre-refactor
- same final DB invariants
- restore logic behavior unchanged

## Decision points to defer

- Whether manifest paths should remain fixed under `/tmp/` or use unique temp files.
- Whether to migrate multipart uploads to `uri` (probably not worth it for now).
- Whether to consolidate 3A/3B response parsing into a single standardized structure.
