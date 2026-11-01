# SonarQube: `phpsecurity:S2083` — Path constructed from user-controlled data

## Summary
SonarQube Cloud flagged a blocker issue (`phpsecurity:S2083`) in:

- `ansible/roles/docker/files/apache/webroot/import_manifest_status.php`

The rule warns when code constructs filesystem paths using user-controlled input (e.g., query params), because this can enable path traversal / path injection.

In GigHive, the `import_manifest_status.php` endpoint accepts a `job_id` query parameter and reads `status.json` / `result.json` from the corresponding job directory under:

- `/var/www/private/import_jobs/<job_id>/`

Even with strict validation and `realpath()` containment checks, Sonar may continue to treat the path as tainted if it is still derived from user input.

## What Sonar flagged
The original flow (simplified):

- `$_GET['job_id']` → `$jobId`
- `$jobDir = $jobRoot . '/' . $jobId`
- `$statusPath = $jobDir . '/status.json'`

Sonar identified this as “constructing the path from user-controlled data”.

## Fix implemented
### Goal
Avoid *directly* constructing a filesystem path from the user-provided `job_id`.

### Approach
In `import_manifest_status.php`, instead of building `$jobDir` from `$jobId`, the code now:

1. Uses a trusted root directory:
   - `$jobRoot = '/var/www/private/import_jobs'`
2. Resolves it via `realpath()` and validates it is readable.
3. Enumerates subdirectories under the trusted root (allowlist from filesystem):
   - `glob($jobRootReal . '/*', GLOB_ONLYDIR)`
4. Looks for an exact match where `basename($dir) === $jobId`.
5. If found, uses that directory name (from the filesystem allowlist) to form the job directory.
6. Applies defense-in-depth containment:
   - `realpath($jobDir)`
   - `str_starts_with($jobDirReal . '/', $jobRootReal . '/')`
7. Reads only:
   - `$jobDirReal . '/status.json'`
   - `$jobDirReal . '/result.json'`

### Why this satisfies S2083 (typically)
This shifts the trust boundary:

- The requested `job_id` is treated as an **identifier**.
- The actual directory used is selected from a **trusted allowlist** (directories that exist under the trusted root).

Many static analyzers accept this pattern where the filesystem path ultimately comes from a controlled source (directory listing) rather than directly from user input.

## Related hardening
- `job_id` is still validated with a strict regex:
  - `^[0-9]{8}-[0-9]{6}-[a-f0-9]{12}$`
- `realpath()` containment remains in place to mitigate symlink-based escapes.

## Verification
### A) Post-build smoke test
A smoke test was added to:

- `ansible/roles/post_build_checks/tasks/main.yml`

It:

1. Generates a valid `job_id`.
2. Creates `/var/www/private/import_jobs/<job_id>/status.json` inside the apache container.
3. Calls:
   - `GET /import_manifest_status.php?job_id=<job_id>`
4. Asserts the response includes:
   - `state == 'running'`
   - `message == 'post_build_checks smoke test'`
5. Cleans up the directory in an `always:` block.

### B) Sonar re-scan
Re-run SonarQube Cloud analysis and verify the `phpsecurity:S2083` issue for `import_manifest_status.php` is resolved.

## Notes / tradeoffs
- Directory enumeration (`glob`) is additional I/O, but job directories are expected to be a small set and this endpoint is admin-only.
- If job directory count grows very large, we can switch to a more efficient lookup strategy (e.g., hashed index file, DB mapping), but the current approach is simplest and Sonar-friendly.
