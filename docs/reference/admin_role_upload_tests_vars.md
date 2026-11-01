# upload_tests role variables (guardrails)

The `upload_tests` role is intentionally protected by multiple independent “locks”. This is defense-in-depth to reduce the chance of accidentally running destructive import/test flows (especially on `prod`).

## Guardrail layers

1. Tags (`--tags upload_tests`)

- Prevents the role from running during normal full deploys *if you’re disciplined about tags*.

2. `run_upload_tests` (role-level gate in `ansible/playbooks/site.yml`)

- Prevents running tests even if someone accidentally includes the `upload_tests` tag set, runs with a broad tag selection, or runs from shell history without thinking.
- This is the “global enable switch” for the entire role.

3. `allow_destructive` (inside `ansible/roles/upload_tests/tasks/main.yml`)

- Prevents *destructive variants* from running even when the role is enabled.
- In the current role logic, “destructive selected” is true if:
  - any variants in sections `3a` / `3b` / `4` are present, **or**
  - `upload_test_restore_only: true`

4. Optional interactive confirmation

- `upload_test_destructive_confirm: true` triggers an interactive prompt requiring typing `YES` before destructive variants proceed.

## Tradeoffs

- The redundancy is deliberate for production safety.
- The main downside is potential confusion (“I passed `--tags upload_tests`—why didn’t it run?”), which is typically caused by `run_upload_tests: false` or `allow_destructive: false`.

## Key variables

- `run_upload_tests`
  - Master opt-in flag; the role is skipped unless enabled.
- `allow_destructive`
  - Secondary opt-in required for destructive variants (Sections 3A, 3B, 4) and for restore-only mode.
- `upload_test_destructive_confirm`
  - If true, prompt for a final interactive confirmation before running destructive variants.
- `upload_test_restore_only`
  - If true, skip running the configured variants and only perform the post-run normalized restore.
- `upload_test_restore_db_after`
  - If true, run the post-run normalized restore at the end of a normal test run (when destructive variants were selected).
- `upload_test_run_upload_media_by_hash`
  - If true, the Step 2 helper (`run_upload_media_by_hash.yml`) is activated after test_4 and test_5, running `upload_media_by_hash.py` to rsync-copy source files to the VM and backfill `duration_seconds` / `media_info` in the DB. Default `false`; set `true` in `gighive2.yml` to enable manifest Step 2 coverage.
- `upload_test_direct_upload_fixture`
  - Absolute path to a single audio or video file on the control host used by `test_6` (direct upload API test). Must be a file that is **not yet in the DB when test_6 runs**. Currently set to the first MP3 in `audio_reduced`. **Ordering constraint:** `6_direct_upload_api` must appear in `upload_test_variants` before `5_manifest_add`; test_5 bulk-inserts all audio files from `audio_reduced` (including the fixture) into the DB, which would cause test_6 to receive a 409 Duplicate Upload.
- `upload_test_manifest_source_roots_reload`
  - List of source root directories for the manifest reload test (test_4). Typically points to `video_reduced`.
- `upload_test_manifest_source_roots_add`
  - List of source root directories for the manifest add test (test_5). Typically points to `audio_reduced`.
