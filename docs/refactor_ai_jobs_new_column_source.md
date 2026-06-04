# Refactor: Add `source` Column to `ai_jobs` for Job Origin Tracking

**Date:** 2026-06-03  
**Status:** Not started  
**Related deferred items:**
- `docs/refactored_ai_jobs_messaging.md` — "Known limitation" section (UI progress bar routing)
- `docs/feature_mcp_server.md` — Deferred 1 (MCP observability)

---

## Problem Statement

There are currently two distinct problems caused by the absence of a `source` field on
`ai_jobs`:

### Problem 1 — Admin UI progress bar routes to wrong section on resume (cosmetic)

`admin/ai_worker.php` queries all active `categorize_video` jobs at page-load time and
unconditionally attaches the progress bar to the **Force Re-tag All** section
(`retagCtrl`), even when the active jobs were originally enqueued by **Tag Untagged
Assets** (`bulkCtrl`). There is no way to distinguish job origin at query time.

The symptom: after any page reload mid-run (including the `ALL DONE` auto-reload),
the resuming progress bar always appears in the wrong section. Wording was corrected
to neutral language ("Active tagging job(s) detected from a previous session") as a
stop-gap, but the structural mismatch remains.

### Problem 2 — MCP / operator tooling cannot answer "what triggered this batch?"

`get_ai_queue_stats` and related MCP tools return aggregate counts by status. Without
a `source` field there is no way to answer "which jobs were triggered by auto-ingest
vs. a manual bulk enqueue vs. a single re-tag request?" — a natural operational
question during large tagging runs or post-deploy validation.

---

## Root Cause

All four `INSERT INTO ai_jobs` call sites write the same three columns:
`(job_type, target_type, target_id)`. No caller records the origin of the enqueue
request. The `source` of a job must be inferred from context (which PHP action or
which service method was executing), but that context is discarded at insert time.

---

## Proposed Change

### Schema migration

```sql
ALTER TABLE ai_jobs
    ADD COLUMN source VARCHAR(64) NULL AFTER job_type;
```

`NULL` allows the column to be added without breaking existing rows or requiring a
backfill. Future rows will always be non-null. The column is added `AFTER job_type`
for readability.

### Canonical source values

| Value | Set by | Trigger |
|-------|--------|---------|
| `auto_ingest` | `UnifiedIngestionCore::enqueueAiJob()` | File ingested; `AI_WORKER_ENABLED=true` |
| `bulk_untagged` | `api/ai_jobs.php?action=enqueue_all_untagged` | Admin clicks "Tag Untagged Assets" |
| `retag_all` | `api/ai_jobs.php?action=retag_all` | Admin clicks "Force Re-tag All" |
| `manual` | `api/ai_jobs.php` single POST | Per-asset re-tag button in `db/media_tags.php`, or direct API call |
| `mcp` | Reserved for future MCP tool `reset_retryable_jobs` | MCP-triggered re-queue (deferred until MCP is implemented) |

---

## Implementation

### 1 — Schema (`create_music_db.sql`)

Add `source VARCHAR(64) NULL` to the `ai_jobs` `CREATE TABLE` definition, after
`job_type`:

```sql
CREATE TABLE IF NOT EXISTS ai_jobs (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    job_type     VARCHAR(64)  NOT NULL,
    source       VARCHAR(64)  NULL,          -- ← new
    target_type  VARCHAR(64)  NOT NULL,
    ...
```

Also add the `ALTER TABLE` statement to the DB migrations role so existing deployments
are updated non-destructively.

### 2 — `UnifiedIngestionCore::enqueueAiJob()` (source: `auto_ingest`)

Current INSERT (`src/Services/UnifiedIngestionCore.php` line 180–182):
```php
$this->pdo->prepare(
    "INSERT INTO ai_jobs (job_type, target_type, target_id) VALUES (:jt, :tt, :tid)"
)->execute([':jt' => $jobType, ':tt' => $targetType, ':tid' => $targetId]);
```

Updated:
```php
$this->pdo->prepare(
    "INSERT INTO ai_jobs (job_type, source, target_type, target_id) VALUES (:jt, :src, :tt, :tid)"
)->execute([':jt' => $jobType, ':src' => 'auto_ingest', ':tt' => $targetType, ':tid' => $targetId]);
```

The public `enqueueAiJob()` signature does not need a `$source` parameter — `auto_ingest`
is the only caller context for this method.

### 3 — `api/ai_jobs.php` bulk enqueue actions (sources: `bulk_untagged`, `retag_all`)

Both bulk INSERT statements follow the same pattern. Update each:

**`enqueue_all_untagged` (line 98–100):**
```php
$insert = $pdo->prepare(
    "INSERT INTO ai_jobs (job_type, source, target_type, target_id)
     VALUES ('categorize_video', 'bulk_untagged', 'asset', :aid)"
);
```

**`retag_all` (line 135–136):**
```php
$insert = $pdo->prepare(
    "INSERT INTO ai_jobs (job_type, source, target_type, target_id)
     VALUES ('categorize_video', 'retag_all', 'asset', :aid)"
);
```

### 4 — `api/ai_jobs.php` single POST enqueue (source: `manual`)

Current INSERT (line 204–207):
```php
$ins = $pdo->prepare(
    "INSERT INTO ai_jobs (job_type, target_type, target_id) VALUES (:jt, :tt, :tid)"
);
$ins->execute([':jt' => $jobType, ':tt' => $targetType, ':tid' => $targetId]);
```

Updated:
```php
$ins = $pdo->prepare(
    "INSERT INTO ai_jobs (job_type, source, target_type, target_id) VALUES (:jt, 'manual', :tt, :tid)"
);
$ins->execute([':jt' => $jobType, ':tt' => $targetType, ':tid' => $targetId]);
```

Note: the caller (`db/media_tags.php` re-tag button) does not pass a source — `manual`
is the correct default for any single-asset enqueue via the API.

### 5 — `admin/ai_worker.php` auto-recover routing fix (the UI Problem 1 fix)

Once `source` is populated, the auto-recover block can route correctly:

```php
// Current: fetches all active job IDs unconditionally and attaches to retagCtrl
$activeStmt = $pdo->query(
    "SELECT id FROM ai_jobs WHERE job_type='categorize_video'
     AND status IN ('queued','running') ORDER BY id"
);
```

Updated: fetch `id` and `source` together, then split into two sets for JS:

```php
$activeStmt = $pdo->query(
    "SELECT id, source FROM ai_jobs WHERE job_type='categorize_video'
     AND status IN ('queued','running') ORDER BY id"
);
$activeRows = $activeStmt->fetchAll(PDO::FETCH_ASSOC);

$retagIds = array_map('intval', array_column(
    array_filter($activeRows, fn($r) => $r['source'] === 'retag_all'),
    'id'
));
$bulkIds = array_map('intval', array_column(
    array_filter($activeRows, fn($r) => $r['source'] !== 'retag_all'),
    'id'
));
```

The JS auto-recover block then seeds `retagCtrl` with `$retagIds` and `bulkCtrl` with
`$bulkIds`, routing the progress bar to the correct section.

---

## File Changes

| File | Change |
|------|--------|
| `ansible/roles/docker/files/mysql/externalConfigs/create_music_db.sql` | Add `source VARCHAR(64) NULL` to `ai_jobs` CREATE TABLE |
| DB migrations role | Add `ALTER TABLE ai_jobs ADD COLUMN source VARCHAR(64) NULL AFTER job_type` migration |
| `src/Services/UnifiedIngestionCore.php` | Add `'auto_ingest'` to `enqueueAiJob()` INSERT |
| `api/ai_jobs.php` | Add source to 3 INSERT statements: `bulk_untagged`, `retag_all`, `manual` |
| `admin/ai_worker.php` | Auto-recover block: fetch `source`, split IDs by source, route to correct controller |

---

## Testing

1. Run a fresh ingest — confirm new `ai_jobs` rows have `source = 'auto_ingest'`.
2. Click "Tag Untagged Assets" — confirm new rows have `source = 'bulk_untagged'`.
3. Click "Force Re-tag All" — confirm new rows have `source = 'retag_all'`.
4. Click per-asset re-tag button in `db/media_tags.php` — confirm `source = 'manual'`.
5. Mid-run page reload after a bulk_untagged batch — confirm progress bar appears in
   the **Tag Untagged Assets** section, not Force Re-tag All.
6. Mid-run page reload after a retag_all batch — confirm progress bar appears in the
   **Force Re-tag All** section.
7. Existing deployments: confirm `ALTER TABLE` migration runs cleanly; existing rows
   have `source = NULL` (no backfill required).

---

## Notes

- `NULL` existing rows are not a problem — the auto-recover split treats any
  `source != 'retag_all'` as bulk, which is the correct conservative fallback for
  pre-migration rows.
- The `mcp` source value is reserved for when `reset_retryable_jobs` in the MCP server
  re-queues failed jobs. Add it to the PHP enqueue path when the MCP server is
  implemented (see `docs/feature_mcp_server.md` Deferred 1).
- This migration is low-risk: the column is nullable, all existing queries that do not
  reference `source` continue to work unchanged.

---

## Related Docs

- `docs/refactored_ai_jobs_messaging.md` — original "Known limitation" that first
  identified this gap (UI progress bar routing)
- `docs/feature_mcp_server.md` — Deferred 1 (MCP observability use case)
- `docs/feature_ai_video_tagger.md` — full `ai_jobs` schema spec
