# Refactor: Move Upload Job State from JSON Files to MySQL

**Date:** 2026-06-03  
**Status:** Phase 1 complete, Phase 2 implemented (pending test)  
**Schema prerequisite:** `docs/refactor_ai_jobs_upload_jobs_event_key_db_schema.md` — apply all DDL changes to each environment before implementing the code changes below.  
**Related items:**
- `docs/feature_mcp_server.md` — Tool 5 `get_upload_job_state`, Deferred 2 `get_upload_throughput_stats`
- `docs/refactored_upload_folder_messaging_server_monotonic_fix.md` — inflated pending count debugging

---

## Problem Statement

Upload job state — which files are pending, uploading, done, failed — is stored
exclusively in JSON files inside the Apache container's filesystem:

```
/var/www/private/import_jobs/{job_id}/
    upload_status.json    ← per-file state, written by upload_start + updated by finalize
    upload_result.json    ← batch summary, written when all files reach terminal state
```

This creates three compounding problems:

### Problem 1 — State is inaccessible to host-side processes without `docker exec`

Any tool running outside the Apache container (the MCP server, scripts, monitoring)
must use `docker exec apacheWebServer cat <path>` to read upload state. This is
fragile, requires the container to be running, and produces unstructured output that
must be parsed. It is the only reason MCP Tool 5 (`get_upload_job_state`) cannot be
a simple SQL query like every other tool.

### Problem 2 — Upload throughput stats cannot be computed

There is no upload session timing record in the database. `upload_status.json` has a
`started_at` field but no `completed_at`; `upload_result.json` has a `completed_at`
but is only written after all files finish. Because the timing information is
in filesystem JSON rather than in MySQL, the planned MCP tool `get_upload_throughput_stats`
(Deferred 2 in `feature_mcp_server.md`) cannot be built — there is no schema to
query.

### Problem 3 — No cross-job upload history is queryable

Each job's state is siloed to its own directory. Answering "how many upload jobs
have run in the past 30 days?" or "which jobs had > 5 failed files?" requires
reading every directory on the container filesystem. With DB rows, these are
single queries.

---

## What Stays on the Filesystem

This migration is specifically about **operational state** (which files are done,
which are pending). The following filesystem artefacts are **not** affected — they
are not operational state and do not belong in a relational table:

| File | Role | Decision |
|------|------|----------|
| `manifest.json` | The import file list payload (large; needed for retry/reload) | Keep on filesystem |
| `meta.json` | Job type + creation metadata | Keep on filesystem |
| `cancel.json` | Cancellation flag (presence = cancelled) | Keep on filesystem |
| `upload_trace.jsonl` | Append-only debug/audit trace | Keep on filesystem |

---

## Proposed Schema

### New table: `upload_jobs`

One row per upload batch session. Replaces the job-level fields in
`upload_status.json` (`job_id`, `started_at`) and `upload_result.json`
(`completed_at`, batch summary counts).

```sql
CREATE TABLE IF NOT EXISTS upload_jobs (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    job_id       VARCHAR(64)  NOT NULL,
    job_type     VARCHAR(32)  NOT NULL DEFAULT 'manifest_import',
    status       VARCHAR(32)  NOT NULL DEFAULT 'in_progress',
                 -- in_progress | complete | complete_with_failures | cancelled
    total_files  INT UNSIGNED NOT NULL DEFAULT 0,
    started_at   DATETIME     NOT NULL,
    completed_at DATETIME     NULL,
    UNIQUE KEY uq_upload_jobs_job_id (job_id),
    INDEX        idx_upload_jobs_started  (started_at),
    INDEX        idx_upload_jobs_status   (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### New table: `upload_job_files`

One row per file per upload batch. Replaces the `files[]` array in both
`upload_status.json` and `upload_result.json`. Column set mirrors the existing
per-file JSON object fields exactly to simplify the migration.

```sql
CREATE TABLE IF NOT EXISTS upload_job_files (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    job_id          VARCHAR(64)  NOT NULL,
    checksum_sha256 CHAR(64)     NOT NULL,
    source_relpath  VARCHAR(512) NOT NULL,
    file_type       VARCHAR(16)  NULL,
    size_bytes      BIGINT UNSIGNED NULL,
    state           VARCHAR(32)  NOT NULL DEFAULT 'pending',
                    -- pending | uploading | already_present | uploaded
                    -- | thumbnail_done | db_done | failed
    media_state     VARCHAR(16)  NULL,  -- pending | done
    thumbnail_state VARCHAR(16)  NULL,  -- pending | done | n_a
    db_state        VARCHAR(16)  NULL,  -- pending | done
    file_name       VARCHAR(512) NOT NULL DEFAULT '',
    error           TEXT         NULL,
    last_error      TEXT         NULL,
    retryable       TINYINT(1)   NULL,
    failure_code    VARCHAR(64)  NULL,
    last_failed_at  DATETIME     NULL,
    retry_count     TINYINT UNSIGNED NOT NULL DEFAULT 0,
    diagnostics     JSON         NULL,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_upload_job_file (job_id, checksum_sha256),
    INDEX idx_upload_job_files_job_id (job_id),
    INDEX idx_upload_job_files_state  (job_id, state),
    CONSTRAINT fk_upload_job_files_job FOREIGN KEY (job_id) REFERENCES upload_jobs(job_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

---

## File Changes

| File | Change |
|------|--------|
| `create_music_db.sql` | Add `upload_jobs` and `upload_job_files` CREATE TABLE statements |
| DB migrations role | Add `ALTER TABLE` / `CREATE TABLE` migration for existing deployments |
| `admin/import_manifest_upload_start.php` | INSERT into `upload_jobs` + `upload_job_files`; on resume, UPDATE rows instead of rewriting JSON |
| `admin/import_manifest_upload_finalize.php` | UPDATE `upload_job_files` row; when `$allDone`, UPDATE `upload_jobs.status` + `completed_at` instead of writing `upload_result.json` |
| `admin/import_manifest_upload_status.php` | Add PDO connection; SELECT from `upload_jobs` + `upload_job_files` instead of reading JSON files |
| `docs/feature_mcp_server.md` | Phase 4: remove `docker exec` architecture branch (two locations); update Priority 5 section to mark `docker exec` approach superseded; update Tool 5 checklist item description; update Reference table Tool 5 description to reflect pure-SQL implementation |

---

## Implementation

### Phase 1 — Schema (non-destructive)

Add both tables to `create_music_db.sql` and to a DB migration. No existing code
changes. Verify tables exist on gighive2 after Ansible run.

### Phase 2 — Dual-write (safe transition)

Update `upload_start.php` and `upload_finalize.php` to write to **both** MySQL and
the JSON files. Read path (`upload_status.php`) continues to read from JSON.

This allows rollback by commenting out the DB writes. Verify DB rows are being
populated correctly on a test upload.

### Phase 3 — Switch read path

Update `import_manifest_upload_status.php` to read from MySQL:

```php
require_once __DIR__ . '/../vendor/autoload.php';
use Production\Api\Infrastructure\Database;
$pdo = Database::createFromEnv();

// Job-level header
$jobRow = $pdo->prepare("SELECT * FROM upload_jobs WHERE job_id = :jid");
$jobRow->execute([':jid' => $jobId]);
$job = $jobRow->fetch(PDO::FETCH_ASSOC);
if (!$job) { /* 404 */ }

// Per-file state
$filesStmt = $pdo->prepare("SELECT * FROM upload_job_files WHERE job_id = :jid ORDER BY id");
$filesStmt->execute([':jid' => $jobId]);
$files = $filesStmt->fetchAll(PDO::FETCH_ASSOC);

$complete = in_array($job['status'], ['complete', 'complete_with_failures'], true);
$response = [
    'job_id'     => $jobId,
    'started_at' => $job['started_at'],
    'complete'   => $complete,
    'files'      => $files,
    'trace'      => gighive_manifest_read_upload_trace($jobDir),
];
if ($complete) {
    // Mirror the fields upload_result.json used to provide so clients see no change.
    $failedCount = count(array_filter($files, fn($f) => $f['state'] === 'failed'));
    $response['success']      = $job['status'] === 'complete';
    $response['total']        = (int)$job['total_files'];
    $response['failed']       = $failedCount;
    $response['completed_at'] = $job['completed_at'];
}
echo json_encode($response);
```

The `trace` field still comes from `upload_trace.jsonl` (it stays on the filesystem).

**Phase 3 also requires:** Remove the `is_dir($jobDir)` directory existence check that
currently acts as the primary 404 gate in `upload_status.php`. After Phase 3 the DB
row is the source of truth; `$jobDir` is still derived from `$jobId` for the trace
call but must not 404 if the directory is absent (e.g. post-container-rebuild).

### Phase 4 — Remove JSON writes

Once Phase 3 is verified on a live upload cycle:
- Remove `gighive_manifest_write_json($uploadStatusPath, ...)` from `upload_start.php`
- Remove `gighive_manifest_write_json($uploadStatusPath, ...)` from `upload_finalize.php`
- Remove `gighive_manifest_write_json($uploadResultPath, ...)` from `upload_finalize.php`
- **Update resume detection in `upload_start.php`** — the current gate is
  `is_file($uploadStatusPath)`; without `upload_status.json`, every call looks like a
  fresh start. Replace with a DB row check:
  ```php
  $existingJob = $pdo->prepare("SELECT id FROM upload_jobs WHERE job_id = :jid");
  $existingJob->execute([':jid' => $jobId]);
  if ($existingJob->fetch()) { /* resume path */ }
  ```
- **Update `$allDone` in `upload_finalize.php`** — currently computed by looping over
  `$statusData['files']`; after JSON removal that array is gone. Replace with:
  ```php
  $counts = $pdo->prepare(
      "SELECT
           SUM(state IN ('db_done','thumbnail_done','uploaded','already_present')) AS terminal_ok,
           SUM(state = 'failed' AND (retryable IS NULL OR retryable = 0))          AS terminal_failed,
           SUM(state = 'failed' AND retryable = 1)                                 AS retryable_failed,
           COUNT(*)                                                                AS total
       FROM upload_job_files WHERE job_id = :jid"
  );
  $counts->execute([':jid' => $jobId]);
  $row = $counts->fetch(PDO::FETCH_ASSOC);
  $allDone   = ((int)$row['terminal_ok'] + (int)$row['terminal_failed']) === (int)$row['total'];
  $failCount = (int)$row['terminal_failed'] + (int)$row['retryable_failed'];
  ```

`upload_status.json` and `upload_result.json` no longer need to be created. Existing
job directories with old JSON files are **not** migrated. The status endpoint returns
a 404 for any pre-migration job whose `job_id` has no row in `upload_jobs`. The job
directory itself remains on disk (for `meta.json`, `upload_trace.jsonl`, etc.).

---

## Migration Detail: upload_start.php Resume Logic

The existing resume path in `upload_start.php` normalizes stale `uploading` state
and reconciles `pending` → `already_present` via a checksum lookup. With DB rows,
this becomes two UPDATE statements instead of JSON mutation:

```php
// 1. Reset any stale 'uploading' rows (page reload during active upload)
$pdo->prepare(
    "UPDATE upload_job_files SET state='pending', error=NULL, last_error=NULL
     WHERE job_id=:jid AND state='uploading'"
)->execute([':jid' => $jobId]);

// 2. Promote 'pending' rows already in assets to 'already_present'
$pdo->prepare(
    "UPDATE upload_job_files ujf
     INNER JOIN assets a ON a.checksum_sha256 = ujf.checksum_sha256
     SET ujf.state = 'already_present'
     WHERE ujf.job_id = :jid AND ujf.state = 'pending'"
)->execute([':jid' => $jobId]);
```

This is simpler than the current PHP loop that iterates all files to build a checksum
set and then iterates again to update matches.

---

## Outcome: MCP Tool 5 Becomes a SQL Query

After this migration, `get_upload_job_state` no longer needs `docker exec`:

```python
@mcp.tool()
def get_upload_job_state(job_id: str) -> dict:
    """Reconcile upload job state from DB for a given job."""
    rows = db.query(
        """SELECT state, COUNT(*) AS n
           FROM upload_job_files WHERE job_id = %s GROUP BY state""",
        [job_id]
    )
    counts = {r['state']: r['n'] for r in rows}
    pending       = counts.get('pending', 0) + counts.get('uploading', 0)
    done          = counts.get('db_done', 0) + counts.get('thumbnail_done', 0) + counts.get('uploaded', 0)
    already_present = counts.get('already_present', 0)
    failed        = counts.get('failed', 0)
    return {
        'pending': pending,
        'done': done,
        'already_present': already_present,
        'failed': failed,
    }
```

The `docker exec` branch in `feature_mcp_server.md` Tool 5 is replaced entirely.

---

## Outcome: Deferred 2 (`get_upload_throughput_stats`) Becomes Buildable

With `upload_jobs.started_at` and `upload_jobs.completed_at` in the schema:

```python
@mcp.tool()
def get_upload_throughput_stats(job_id: str) -> dict:
    job  = db.query_one("SELECT * FROM upload_jobs WHERE job_id = %s", [job_id])
    files = db.query(
        "SELECT state, COUNT(*) AS n, SUM(size_bytes) AS total_bytes
         FROM upload_job_files WHERE job_id = %s GROUP BY state", [job_id]
    )
    terminal_success = {'db_done', 'thumbnail_done', 'uploaded', 'already_present'}
    elapsed = (job['completed_at'] - job['started_at']).total_seconds()
    # Exclude already_present files — they were not uploaded, so they should not
    # inflate total_bytes or skew mb_per_sec / avg_file_size_mb.
    total_bytes = sum(r['total_bytes'] or 0 for r in files if r['state'] != 'already_present')
    total_files = sum(r['n'] for r in files if r['state'] != 'already_present')
    return {
        'files_total':     job['total_files'],
        'files_done':      sum(r['n'] for r in files if r['state'] in terminal_success),
        'elapsed_seconds': elapsed,
        'files_per_min':   round(total_files / (elapsed / 60), 1) if elapsed > 0 else None,
        'mb_per_sec':      round(total_bytes / 1_048_576 / elapsed, 2) if elapsed > 0 else None,
        'avg_file_size_mb':round(total_bytes / 1_048_576 / total_files, 2) if total_files > 0 else None,
    }
```

---

## Benefits Summary

| Benefit | Detail |
|---------|--------|
| MCP Tool 5 → pure SQL | Removes the only `docker exec` read in the MCP server |
| Unblocks `get_upload_throughput_stats` | `started_at` + `completed_at` in `upload_jobs` |
| Resume logic simplification | Two UPDATE statements replace a PHP array-crawl + loop |
| Cross-job history queryable | "Failed jobs in last 30 days" is a single SELECT |
| State survives container rebuilds | No longer depends on container filesystem continuity |
| Status polling | DB query vs. file read + JSON parse — simpler, more consistent |

---

## Notes

- **No backfill needed** — historical upload job directories with existing JSON files
  are not migrated. Old directories remain on disk; the status endpoint can return a
  "legacy job, no DB record" 404 for any job predating this change.
- **`upload_trace.jsonl` is unchanged** — it is debug/audit log, not operational
  state, and is returned as-is in all status responses.
- **`assets.checksum_sha256`** — the reconciliation JOIN in the resume UPDATE assumes
  this column is indexed. Confirm index exists in `create_music_db.sql`.
- **`started_at` datetime format** — current code uses `date('c')` (ISO 8601 with
  timezone offset) which MySQL `DATETIME` cannot store correctly. The INSERT must use
  `date('Y-m-d H:i:s')` instead.
- **`cancelled` status is unimplemented** — the schema reserves `status = 'cancelled'`
  but no code path writes it. `cancel.json` remains the sole cancellation signal and
  stays on the filesystem. Implement or remove the `cancelled` value before Phase 1
  goes to production.
- **`upload_jobs.total_files` must be set explicitly on INSERT** — the column default
  is `0`; `upload_start.php` must pass `count($uploadFiles)` when inserting the row.
- **UNIQUE KEY on `(job_id, checksum_sha256)` is stricter than JSON** — a manifest
  with duplicate checksums would silently create two JSON entries but will throw a DB
  error here. This is intentional (duplicate checksums in a manifest are a client
  bug), but `upload_start.php` should validate for duplicates and surface a clear
  error rather than letting the INSERT fail.
- **Error classifier string goes stale in Phase 4** — `gighive_classify_finalize_error()`
  checks `str_contains($msg, 'not found in upload_status')`. After migration, a
  missing checksum comes from a DB SELECT returning null; the error message will not
  match. Update this string in `import_manifest_upload_finalize.php` during Phase 4.
- **`started_at` output format changes** — MySQL `DATETIME` returns `YYYY-MM-DD HH:MM:SS`
  (no timezone); clients currently receive ISO 8601 from the JSON files. The Phase 3
  status endpoint should reformat for consistency: `date('c', strtotime($job['started_at']))`.
- **`get_upload_throughput_stats` needs null guard** — `completed_at` is `NULL` for
  in-progress jobs; `(job['completed_at'] - job['started_at'])` will throw. Add an
  early return or guard before computing `elapsed`.

---

## Related Docs

- `docs/feature_mcp_server.md` — Tool 5 (`get_upload_job_state`) and Deferred 2 (`get_upload_throughput_stats`)
- `docs/refactored_upload_folder_messaging_server_monotonic_fix.md` — original inflated pending count bug
- `docs/refactored_uploads_tus_parallel.md` — TUS parallel upload tuning (manual throughput measurement this replaces)
