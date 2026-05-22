# Refactor: AI Jobs Progress Messaging (Option B — Proper Fix)

## Problem Statement

The `QUEUED` progress section on `/admin/ai_worker.php` shows unreliable counts in two
distinct ways when a Force Re-tag (or bulk enqueue) batch is auto-recovered after a page
load or container restart:

1. **"complete" counter is always 0** — Jobs that finished before the page was loaded
   are invisible to the progress bar. It starts at "0 of N complete" even when many jobs
   are already done.

2. **"queued" count is hard-capped at 500** — The `poll()` function fetches individual
   job records via `GET /api/ai_jobs.php?limit=500`. The server enforces
   `min((int)($limit ?? 100), 500)` (see `api/ai_jobs.php` line 62), so any batch
   larger than 500 jobs will always under-report the queued count regardless of what the
   client requests.

The top stat cards (Done / Running / Queued jobs) are unaffected — they are generated
server-side at PHP render time and are always accurate. The QUEUED progress row is the
only broken surface.

---

## Root Cause Analysis

### Bug 1 — "complete" = 0 on resume

`admin/ai_worker.php` PHP (lines 60–63) collects only the IDs of jobs currently in
`queued` or `running` state at page render time:

```php
$activeStmt = $pdo->query(
    "SELECT id FROM ai_jobs WHERE job_type='categorize_video'
     AND status IN ('queued','running') ORDER BY id"
);
$activeJobIds = array_map('intval', array_column(..., 'id'));
```

The auto-recover JS block (lines 454–467) seeds the progress controller with this set:

```js
const activeIds = <?= json_encode(array_values($activeJobIds)) ?>;
retagCtrl.setJobIds(activeIds);        // jobIdSet = {536 active IDs}
retagCtrl.start(activeIds.length);     // total = 536
```

Inside `poll()`, the fetched job records are filtered to `jobIdSet`:

```js
const jobs = jobIdSet
    ? allJobs.filter(j => jobIdSet.has(Number(j.id)))
    : allJobs.filter(j => j.job_type === 'categorize_video');
```

Jobs that completed (status changed to `done`/`failed`) **before** page load are not in
`jobIdSet`. So `done` starts at 0 and only increments as jobs transition during this
browser session. If 87 of 623 jobs already finished, the bar still starts at "0 of 536".

### Bug 2 — 500 hard cap

`api/ai_jobs.php` GET list handler (line 62):

```php
$limit = min((int)($_GET['limit'] ?? 100), 500);
```

The server silently clamps any `?limit=N` to 500. The JS polls with `?limit=500`, so
any batch exceeding 500 active jobs returns an incomplete record set. The `queued`
counter derived from those records is wrong — it shows at most 500.

---

## Option B: Proper Fix

Instead of fetching individual job records and counting them client-side, add a
lightweight **server-side aggregate endpoint** that returns status counts. This avoids
the limit cap entirely (it runs a single `GROUP BY` query) and gives accurate totals
inclusive of all statuses (done/failed jobs from before page load included).

### Change 1 — New PHP action in `api/ai_jobs.php`

Add a `GET /api/ai_jobs.php?action=status_counts` handler. Supports two modes:

- **Global**: no `job_ids` param → counts ALL `categorize_video` jobs by status.
- **Filtered**: `job_ids=1,2,3,...` → counts only those specific job IDs by status.

The auto-recover case uses **global** mode. The "just-clicked" button cases (where the
JS has fresh `job_ids` from the enqueue response) use filtered mode so progress is
scoped to the current batch.

Insert before the final `json_err('Method not allowed', 405)` line:

```php
// ── GET status_counts ─────────────────────────────────────────────────────────
if ($method === 'GET' && $action === 'status_counts') {
    if (!$isAdmin) {
        json_err('Admin required', 403);
    }
    $rawIds = $_GET['job_ids'] ?? '';
    if ($rawIds !== '') {
        $ids = array_filter(array_map('intval', explode(',', $rawIds)));
        if (empty($ids)) {
            json_ok(['queued' => 0, 'running' => 0, 'done' => 0, 'failed' => 0, 'total' => 0]);
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare(
            "SELECT status, COUNT(*) AS n FROM ai_jobs
             WHERE id IN ($placeholders)
             GROUP BY status"
        );
        $stmt->execute(array_values($ids));
    } else {
        $stmt = $pdo->query(
            "SELECT status, COUNT(*) AS n FROM ai_jobs
             WHERE job_type='categorize_video'
             GROUP BY status"
        );
    }
    $counts = ['queued' => 0, 'running' => 0, 'done' => 0, 'failed' => 0];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $st = $row['status'];
        if (array_key_exists($st, $counts)) {
            $counts[$st] = (int)$row['n'];
        }
    }
    $counts['total'] = array_sum($counts);
    json_ok($counts);
}
```

**Note**: The `job_ids` list for a full re-tag batch can be hundreds of IDs long. For
batches ≤ ~1000 IDs this is fine as a query parameter. If future batch sizes approach
the URL length limit, switch to a `POST` with a JSON body — but this is not a concern
at current scale.

---

### Change 2 — `poll()` in `admin/ai_worker.php`

Replace the body of the `async function poll()` inside `makeProgressController`:

**Before:**
```js
async function poll() {
    try {
        const r = await fetch('/api/ai_jobs.php?limit=500');
        if (!r.ok) return;
        const d = await r.json();
        const allJobs = d.jobs || [];
        const jobs = jobIdSet
            ? allJobs.filter(j => jobIdSet.has(Number(j.id)))
            : allJobs.filter(j => j.job_type === 'categorize_video');
        let queued = 0, running = 0, done = 0, failed = 0;
        jobs.forEach(j => {
            if (j.status === 'queued')       queued++;
            else if (j.status === 'running') running++;
            else if (j.status === 'done')    done++;
            else if (j.status === 'failed')  failed++;
        });
        const failedJobs = jobs.filter(j => j.status === 'failed');
        const active   = queued + running;
        const finished = done + failed;
        const t        = Math.max(total, active + finished);
        const pct      = t > 0 ? (finished / t * 100) : 0;
        const failNote = failed > 0 ? ` · <span style="color:#f87171">${failed} failed</span>` : '';
        showFailed(failedJobs);
        if (active === 0) {
            clearInterval(timer);
            showStopBtn(false);
            show(100, 'pf-done', `<strong>ALL DONE</strong> — ${done} tagged, ${failed} failed. Reloading…`);
            setTimeout(() => location.reload(), 2000);
        } else {
            show(pct, 'pf-active',
                `<strong>${running > 0 ? 'RUNNING' : 'QUEUED'}</strong> — ${done} of ${t} complete · ${running} running · ${queued} queued${failNote}`);
        }
    } catch(e) { /* network hiccup — keep polling */ }
}
```

**After:**
```js
async function poll() {
    try {
        const url = jobIdSet && jobIdSet.size > 0
            ? '/api/ai_jobs.php?action=status_counts&job_ids=' + Array.from(jobIdSet).join(',')
            : '/api/ai_jobs.php?action=status_counts';
        const r = await fetch(url);
        if (!r.ok) return;
        const d = await r.json();
        const queued  = d.queued  || 0;
        const running = d.running || 0;
        const done    = d.done    || 0;
        const failed  = d.failed  || 0;
        const active   = queued + running;
        const finished = done + failed;
        const t        = d.total || Math.max(total, active + finished);
        const pct      = t > 0 ? (finished / t * 100) : 0;
        const failNote = failed > 0 ? ` · <span style="color:#f87171">${failed} failed</span>` : '';
        if (active === 0) {
            clearInterval(timer);
            showStopBtn(false);
            show(100, 'pf-done', `<strong>ALL DONE</strong> — ${done} tagged, ${failed} failed. Reloading…`);
            setTimeout(() => location.reload(), 2000);
        } else {
            show(pct, 'pf-active',
                `<strong>${running > 0 ? 'RUNNING' : 'QUEUED'}</strong> — ${done} of ${t} complete · ${running} running · ${queued} queued${failNote}`);
        }
    } catch(e) { /* network hiccup — keep polling */ }
}
```

Key differences:
- Calls `?action=status_counts` — one aggregate query, no row fetch, no 500 cap.
- `jobIdSet` is still respected when present (scoped batch tracking after button click).
- On auto-recover (where `jobIdSet` contains the active IDs at page load), uses filtered
  mode so "done" correctly includes any pre-page-load completions for those IDs.
- `t = d.total` is authoritative from the server; the `Math.max` fallback covers the
  brief window before the first poll returns.
- Removes `showFailed()` dependency (failed count is now aggregate only — individual
  failed job links are still visible in the Recent Jobs table below).

---

### Change 3 — Auto-recover block: seed `total` correctly

The auto-recover block currently passes `activeIds.length` as `total`, which is only
the currently-active count, not the full batch. With the new `status_counts` endpoint
returning `total` directly from the DB, the first `poll()` call will self-correct
`t` on its own (via `d.total`). No change needed to the auto-recover block itself.

However, the `RESUMING` label shown before the first poll returns still says
`${activeIds.length} active job(s)` — this is accurate (those are genuinely active).
It will be replaced by the correct totals on the first poll cycle (within 5 s).

---

## Summary of File Changes

| File | Change |
|------|--------|
| `api/ai_jobs.php` | Add `GET ?action=status_counts` handler (~20 lines) |
| `admin/ai_worker.php` | Replace `poll()` body in `makeProgressController` (~15 lines) |

No DB schema changes. No new dependencies. Backward-compatible — existing
`enqueue_all_untagged` and `retag_all` actions are untouched.

---

## Testing

After implementing:

1. Navigate to `/admin/ai_worker.php` with active queued jobs.
2. Reload the page — QUEUED row should immediately show correct `done` count (not 0).
3. With > 500 jobs queued, confirm `queued` count matches the "Queued jobs" stat card.
4. Click "Stop remaining jobs" — cancel still works (uses `jobIdSet` IDs, unchanged).
5. Let batch complete — bar reaches 100% and page reloads automatically.

---

## Related Docs

- `docs/guide_ai_worker_tagging.md` — operator guide including crash-loop recovery scenarios
- `docs/feature_ai_video_tagger.md` — full AI worker technical spec
- `docs/problem_ai_worker_force_retag_debugging.md` — force re-tag debugging
