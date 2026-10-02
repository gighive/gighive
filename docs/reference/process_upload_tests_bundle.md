# Upload Tests: Bundle vs Full Build

## How to run

```bash
ansible-playbook \
  -i ansible/inventories/inventory_gighive.yml \
  ansible/playbooks/upload_tests_bundle.yml \
  --tags upload_tests \
  -e "mysql_appuser_password=<bundle_admin_password>"
```

The bundle must be running (`docker compose up -d` in `/tmp/gighive-one-shot-bundle`).
No special media file cleanup is required before running.

---

## Why bundle differs from full build

- **Full build runs two 3B variants** (`3b_normalized_import_gighive` then
  `3b_normalized_import_defaultcodebase`). The second 3B does `TRUNCATE TABLE files`
  and reloads from `session_filesLarge.csv`, which does not contain the test 6 or
  test 7 fixture checksums. By the time test 6 runs, those checksums are gone.

- **Bundle runs only one 3B** (`3b_normalized_import_gighive`). Its fixture
  `session_filesSmall.csv` contains the exact `checksum_sha256` values used by
  test 6 (`007e8780...mp3`) and test 7 (`1982d302...mp3`). If test 6/7 run
  _after_ 3B, `/api/uploads` rejects them as "Duplicate Upload" (HTTP 409).

- **After 3A, `checksum_sha256` is NULL** for all file records. `import_database.php`
  (3A) uses `mysqlPrep_full.py` to process `databaseSmall.csv`, which contains
  original filenames (e.g. `20050303_8.mp3`). The audio directory stores files by
  SHA256 hash name, so `mysqlPrep_full.py` cannot match them — checksums stay NULL.

- **The fix is variant ordering.** Running test 6 and test 7 _before_ 3B means they
  execute while checksums are NULL → no duplicate detected → uploads succeed. 3B then
  runs last, truncating and restoring the normalized state with proper checksums.

- **`_host_audio/` and `_host_video/` contents do not affect test outcomes.** Whether
  or not the sample media files are present in those directories before the test run,
  3A always produces NULL checksums and tests 6/7 always succeed.

---

## Variant order in `upload_tests_bundle.yml`

| Order | Variant | Why |
|-------|---------|-----|
| 1 | `3a_legacy_import_gighive` | Truncates all tables, reloads from `databaseSmall.csv` with NULL checksums |
| 2 | `6_direct_upload_api` | Uploads `007e8780...mp3` — checksum is NULL in DB, no duplicate |
| 3 | `7_tus_finalize` | Uploads `1982d302...mp3` via TUS — same reason |
| 4 | `3b_normalized_import_gighive` | Truncates + reloads from `session_filesSmall.csv` with proper checksums, leaving DB in clean normalized state |
