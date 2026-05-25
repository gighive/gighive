# Refactor: AI Worker Parallelism (`AI_WORKER_CONCURRENCY` + `AI_CHUNK_CONCURRENCY`)

## Problem

The AI worker processes `categorize_video` jobs one at a time. Each job involves:

1. **Frame extraction** (ffmpeg — fast, local I/O, ~1–5 s)
2. **LLM API calls** (OpenAI — slow, network-bound, ~2–5 s per chunk)
3. **DB writes** (fast)

For a typical gig video (duration ≥ 5 min), the extractor hits the `AI_MAX_FRAMES_PER_JOB`
cap of 96 frames (current group_vars value). At 6 frames per chunk (`AI_MAX_FRAMES_PER_CHUNK`),
that produces **16 sequential OpenAI API calls per video**. With a queue of 100 videos,
the worker makes ~1,600 sequential API calls, each blocking the entire loop.

---

## Two Parallelism Levers

### Lever 1 — Parallel LLM chunk calls within a single job (`AI_CHUNK_CONCURRENCY`)

**File:** `adapters/openai_adapter.py` → `analyze_frames()`

Currently iterates chunks sequentially:

```python
for start in range(0, len(frames), self.max_per_chunk):
    chunk = frames[start: start + self.max_per_chunk]
    tags = self._analyze_chunk(chunk)
    all_tags.extend(tags)
```

With `ThreadPoolExecutor`, all chunks for a video are submitted concurrently:

```python
from concurrent.futures import ThreadPoolExecutor, as_completed

with ThreadPoolExecutor(max_workers=self.chunk_concurrency) as ex:
    futures = {
        ex.submit(self._analyze_chunk, frames[s: s + self.max_per_chunk]): s
        for s in range(0, len(frames), self.max_per_chunk)
    }
    for fut in as_completed(futures):
        all_tags.extend(fut.result())
```

**Why threading (not asyncio):** `_call_with_retry()` uses the synchronous `openai.OpenAI`
client. Switching to asyncio would require replacing it with `openai.AsyncOpenAI` and
rewriting the retry loop. `ThreadPoolExecutor` gives the same concurrency benefit with
minimal changes and no asyncio refactor.

**Expected gain for a 90-min video (16 chunks):**
- Before: 16 × ~3 s = ~48 s of LLM time per job
- After (`AI_CHUNK_CONCURRENCY=16`): ~3 s of LLM time per job (~16× speedup on LLM phase)

**New env var:** `AI_CHUNK_CONCURRENCY` (default: `1` — sequential, no behaviour change)

---

### Lever 2 — Parallel job processing (`AI_WORKER_CONCURRENCY`)

**File:** `worker.py` → `main()`

Currently a single-threaded polling loop. `db.claim_next_job()` already uses
`FOR UPDATE SKIP LOCKED`, making it **safe for concurrent callers** — each thread will
claim a different job with no race conditions.

The refactored `main()` spins up N worker threads, each running its own poll loop with
its own DB connection and `OpenAIAdapter` instance:

```python
from concurrent.futures import ThreadPoolExecutor

# NOTE: adapter is created once per thread in main() and passed in — not created inside
def _worker_thread(thread_id: int, adapter) -> None:
    while not _shutdown:
        conn = None
        job = None
        run_id = None
        try:
            conn = db.get_connection()
            job = db.claim_next_job(conn, JOB_TYPE, f"{WORKER_ID}:{thread_id}")
            if not job:
                conn.close()
                time.sleep(POLL_INTERVAL)
                continue
            # ... rest of existing job processing logic ...
        except ...:
            ...

def main():
    ...
    n = int(os.getenv('AI_WORKER_CONCURRENCY', '1'))
    if n <= 1:
        _worker_thread(0)   # original single-thread path, no overhead
    else:
        with ThreadPoolExecutor(max_workers=n) as ex:
            futures = [ex.submit(_worker_thread, i) for i in range(n)]
            for f in futures:
                f.result()  # propagate any fatal exception
```

**Expected gain for a queue of 100 videos:**
- Before (`AI_WORKER_CONCURRENCY=1`): 100 × ~30 s = ~50 min
- After (`AI_WORKER_CONCURRENCY=4`): ~12–13 min (~4× throughput)

**New env var:** `AI_WORKER_CONCURRENCY` (default: `1` — single-threaded, no behaviour change)

**Note on `reset_stale_running_jobs`:** The startup reset must only reset jobs not owned by
*any* thread of *this* container. The `WORKER_ID` prefix (hostname + uuid) already
identifies the container. Thread IDs are appended as `{WORKER_ID}:{thread_id}`, so the
existing `WHERE locked_by != %s` reset query no longer works correctly when
`AI_WORKER_CONCURRENCY > 1` — it would only skip the thread-0 ID. Fix: change the reset
query to use `LIKE '{WORKER_ID}%'` to exclude all threads of this container.

---

## Files to Change

| File | Change |
|------|--------|
| `ansible/roles/ai_worker/files/ai-worker/adapters/openai_adapter.py` | Read `AI_CHUNK_CONCURRENCY`; replace sequential loop in `analyze_frames()` with `ThreadPoolExecutor` |
| `ansible/roles/ai_worker/files/ai-worker/worker.py` | Read `AI_WORKER_CONCURRENCY`; extract job loop into `_worker_thread()`; run N threads in `main()`; fix stale-job reset to use `LIKE` prefix match |
| `ansible/roles/docker/templates/.env.j2` | Add `AI_WORKER_CONCURRENCY` and `AI_CHUNK_CONCURRENCY` env vars |
| `ansible/inventories/group_vars/gighive/gighive.yml` | Add `ai_worker_concurrency` and `ai_chunk_concurrency` vars |
| `ansible/inventories/group_vars/gighive2/gighive2.yml` | Same |
| `ansible/inventories/group_vars/prod/prod.yml` | Same |

---

## Detailed Code Changes

### 1. `group_vars/gighive/gighive.yml` (and gighive2, prod — identical)

Insert after `ai_max_tags_per_asset: 81` (end of the existing AI worker block):

```yaml
# Number of concurrent job-processing threads in the AI worker container.
# Each thread claims and processes a separate ai_jobs row concurrently.
# claim_next_job uses FOR UPDATE SKIP LOCKED so this is race-condition-safe.
# 1 = single-threaded (default). 4 = recommended starting point.
ai_worker_concurrency: 4

# Number of concurrent OpenAI chunk calls per video within a single job.
# A 90-min video produces 8 chunks (48 frames / 6 per chunk); setting this to
# 8 reduces per-job LLM time from ~24 s to ~3 s.
# 1 = sequential (default). 16 = max (matches 96 frames / 6 per chunk).
ai_chunk_concurrency: 8
```

### 2. `ansible/roles/docker/templates/.env.j2`

Insert after `AI_MAX_TAGS_PER_ASSET=...` (end of the AI block, before the blank line):

```jinja
AI_WORKER_CONCURRENCY={{ ai_worker_concurrency | default(1) | int }}
AI_CHUNK_CONCURRENCY={{ ai_chunk_concurrency | default(1) | int }}
```

### 3. `adapters/openai_adapter.py`

**`__init__` — read new env var (insert after the `self.max_per_chunk` line):**

```python
self.chunk_concurrency = max(1, int(os.getenv('AI_CHUNK_CONCURRENCY', '1')))
```

**`analyze_frames()` — replace sequential loop:**

```python
def analyze_frames(self, frames: list[FrameData], prompt: str = '') -> list[TagResult]:
    """Chunk frames and call the LLM; aggregate and return all TagResults."""
    chunks = [
        frames[s: s + self.max_per_chunk]
        for s in range(0, len(frames), self.max_per_chunk)
    ]
    if self.chunk_concurrency <= 1 or len(chunks) <= 1:
        all_tags: list[TagResult] = []
        for i, chunk in enumerate(chunks):
            tags = self._analyze_chunk(chunk)
            logger.debug("Chunk [%d] produced %d tags", i, len(tags))
            all_tags.extend(tags)
        return all_tags

    from concurrent.futures import ThreadPoolExecutor, as_completed
    all_tags = []
    with ThreadPoolExecutor(max_workers=min(self.chunk_concurrency, len(chunks))) as ex:
        futures = {ex.submit(self._analyze_chunk, chunk): i for i, chunk in enumerate(chunks)}
        for fut in as_completed(futures):
            i = futures[fut]
            tags = fut.result()
            logger.debug("Chunk [%d] produced %d tags", i, len(tags))
            all_tags.extend(tags)
    return all_tags
```

### 4. `worker.py`

**Extract the job body into `_worker_thread(thread_id)`:**

```python
WORKER_ID = f"{socket.gethostname()}:{uuid.uuid4().hex[:8]}"


def _worker_thread(thread_id: int, adapter) -> None:
    worker_label = f"{WORKER_ID}:{thread_id}"
    logger.info('Worker thread %s started', worker_label)
    while not _shutdown:
        conn = None
        job = None
        run_id = None
        try:
            conn = db.get_connection()
            job = db.claim_next_job(conn, JOB_TYPE, worker_label)

            if not job:
                conn.close()
                time.sleep(POLL_INTERVAL)
                continue

            logger.info('Thread %s claimed job id=%s target=%s/%s',
                        thread_id, job['id'], job['target_type'], job['target_id'])

            if int(job.get('attempts', 1)) > MAX_ATTEMPTS:
                db.mark_job_failed(conn, job['id'], f'Exceeded max attempts ({MAX_ATTEMPTS})', no_retry=True)
                conn.close()
                continue

            run = db.create_helper_run(conn, job, video_tagger.HELPER_ID, video_tagger.HELPER_VERSION)
            run_id = run['id']

            if job['job_type'] == JOB_TYPE:
                video_tagger.run(conn, job, run_id, adapter)
            else:
                raise NotImplementedError(f"Unknown job_type: {job['job_type']}")

            db.mark_job_done(conn, job['id'])
            conn.close()

        except (MediaNotFoundError, MediaDecodeError, ValueError) as exc:
            logger.error('Permanent failure for job %s: %s', job['id'] if job else '?', exc)
            if conn and job:
                if run_id:
                    db.mark_run_failed(conn, run_id, str(exc))
                db.mark_job_failed(conn, job['id'], str(exc), no_retry=True)
            if conn:
                conn.close()

        except Exception as exc:
            logger.exception('Transient error for job %s: %s', job['id'] if job else '?', exc)
            if conn and job:
                if run_id:
                    db.mark_run_failed(conn, run_id, str(exc))
                db.mark_job_failed(conn, job['id'], str(exc), no_retry=False)
            if conn:
                try:
                    conn.close()
                except Exception:
                    pass
            time.sleep(POLL_INTERVAL)

    logger.info('Worker thread %s shut down', worker_label)
```

**`main()` — add concurrency + fix stale-job reset:**

```python
def main():
    if os.getenv('AI_WORKER_ENABLED', 'false').lower() not in ('1', 'true', 'yes'):
        logger.info('AI_WORKER_ENABLED is not true — exiting cleanly')
        return

    n = max(1, int(os.getenv('AI_WORKER_CONCURRENCY', '1')))
    logger.info('AI worker starting (id=%s, threads=%d, poll_interval=%ds)', WORKER_ID, n, POLL_INTERVAL)

    startup_conn = db.get_connection()
    db.reset_stale_running_jobs(startup_conn, WORKER_ID)   # see note below
    startup_conn.close()

    if n <= 1:
        adapter = OpenAIAdapter()
        _worker_thread(0, adapter)
    else:
        from concurrent.futures import ThreadPoolExecutor
        adapters = [OpenAIAdapter() for _ in range(n)]
        with ThreadPoolExecutor(max_workers=n) as ex:
            futures = [ex.submit(_worker_thread, i, adapters[i]) for i in range(n)]
            for f in futures:
                try:
                    f.result()
                except Exception as exc:
                    logger.error('Worker thread raised fatal exception: %s', exc)

    logger.info('AI worker shut down cleanly')
```

**`db.reset_stale_running_jobs()` — fix for multi-thread worker IDs:**

The current reset query is `WHERE locked_by != %s` with a single exact `WORKER_ID`.
With multi-threading, thread labels are `{WORKER_ID}:0`, `{WORKER_ID}:1`, etc. The fix
is to use a prefix match so all threads of this container are excluded from the reset:

```python
def reset_stale_running_jobs(conn, worker_id: str) -> int:
    cur = conn.cursor()
    try:
        cur.execute(
            "UPDATE ai_jobs SET status='queued', locked_by=NULL, locked_at=NULL, "
            "updated_at=NOW() WHERE status='running' AND locked_by NOT LIKE %s",
            (worker_id + '%',),
        )
        conn.commit()
        count = cur.rowcount
        if count:
            logger.info('Reset %d stale running job(s) to queued on startup', count)
        return count
    finally:
        cur.close()
```

---

## Expected Gains

| Config | Queue of 100 videos (90 min each, 16 chunks/video) | Per-job time |
|--------|-----------------------------------------------------|--------------|
| Baseline (`CONCURRENCY=1`, `CHUNK=1`) | ~90 min | ~54 s |
| Chunk only (`CONCURRENCY=1`, `CHUNK=16`) | ~12 min | ~8 s |
| Jobs only (`CONCURRENCY=4`, `CHUNK=1`) | ~23 min | ~54 s |
| Both (`CONCURRENCY=4`, `CHUNK=8`) | ~6 min | ~8 s |

> Estimates assume ~3 s per LLM call, ~5 s frame extraction, 16 chunks/video (96 frames ÷ 6).
> Actual gains depend on OpenAI rate limits and network latency.

---

## Rate Limit Considerations

With `AI_WORKER_CONCURRENCY=4` and `AI_CHUNK_CONCURRENCY=8`, the worker sends up to
**32 concurrent OpenAI requests** (4 jobs × 8 chunks each). The existing `_call_with_retry()` handles `RateLimitError`
with exponential backoff, so this is safe — it will self-throttle if rate limits are hit.

**Recommended starting values:**
- `ai_worker_concurrency: 4` — process 4 videos in parallel
- `ai_chunk_concurrency: 4` — 4 concurrent LLM calls per video (balanced vs rate limits)

Test with these values first; increase `ai_chunk_concurrency` to 8 if rate limits are
not a problem in practice.

---

## Testing

### Baseline (before implementing)

1. Confirm current settings: `docker exec aiWorker env | grep AI_WORKER\|AI_CHUNK`
2. Queue 10 videos via the admin UI or directly into `ai_jobs`.
3. Note start time; wait for all 10 to reach `status='done'`.
4. Record: total elapsed time, jobs/minute.

### After implementing

1. Set `ai_worker_concurrency: 4` and `ai_chunk_concurrency: 4` in group_vars.
2. Run Ansible; confirm: `docker exec aiWorker env | grep AI_WORKER\|AI_CHUNK`
3. Queue the same 10 videos (clear previous `ai_jobs` rows first).
4. Record same metrics and compare.

### Pass / Fail Criteria

| Check | Pass |
|-------|------|
| All 10 jobs reach `status='done'` | Yes |
| No `status='failed'` with `concat` or lock errors | Clean |
| No duplicate tag rows for the same asset | Verified via `SELECT asset_id, COUNT(*) FROM taggings GROUP BY asset_id` |
| Total elapsed ≤ 50% of baseline | Confirmed improvement |

### Revert

Set `ai_worker_concurrency: 1` and `ai_chunk_concurrency: 1` in group_vars and re-run
Ansible. No data is affected — these are runtime concurrency settings only.

---

## Operational Commands

### Verify env vars loaded
```bash
docker exec gighive-one-shot-bundle-ai-worker-1 sh -c "env | grep -E 'AI_|LLM_|OPENAI_' | sort"
```

### Confirm threads and concurrency on startup
```bash
docker logs gighive-one-shot-bundle-ai-worker-1 --tail 20
```
Expected: `threads=3` in startup line, three `Worker thread ... started` lines, then multiple `Thread N claimed job` lines firing near-simultaneously.

### Watch live log stream
```bash
docker logs -f gighive-one-shot-bundle-ai-worker-1
```

### Check queue depth (on staging VM, adapt password as needed)
```bash
docker exec gighive-one-shot-bundle-mysql-1 mysql \
  -u root -p"$(docker exec gighive-one-shot-bundle-mysql-1 printenv MYSQL_ROOT_PASSWORD)" \
  music_db -e "SELECT status, COUNT(*) FROM ai_jobs GROUP BY status;"
```

### Hot-patch sequence (OSB staging — after rsync of updated files to ~/ai_worker_patch/)
```bash
docker cp ~/ai_worker_patch/worker.py gighive-one-shot-bundle-ai-worker-1:/app/worker.py && \
docker cp ~/ai_worker_patch/openai_adapter.py gighive-one-shot-bundle-ai-worker-1:/app/adapters/openai_adapter.py && \
docker cp ~/ai_worker_patch/db.py gighive-one-shot-bundle-ai-worker-1:/app/db.py
```
```bash
docker restart gighive-one-shot-bundle-ai-worker-1
```

### Inject new env vars without Ansible redeploy (append to .env then recreate only ai-worker)
```bash
echo 'AI_WORKER_CONCURRENCY=3' >> /mnt/gighive/gighive-one-shot-bundle/apache/externalConfigs/.env
echo 'AI_CHUNK_CONCURRENCY=4'  >> /mnt/gighive/gighive-one-shot-bundle/apache/externalConfigs/.env
```
```bash
docker compose -f /mnt/gighive/gighive-one-shot-bundle/docker-compose.yml \
  up --no-deps --force-recreate -d ai-worker
```
Then re-run the hot-patch cp sequence above and `docker restart`.

---

## System Health Commands

### Container CPU / memory snapshot (from host)
```bash
docker stats gighive-one-shot-bundle-ai-worker-1 --no-stream
```

### Live container resource usage
```bash
docker stats gighive-one-shot-bundle-ai-worker-1
```

### Host CPU usage over 3 samples (requires sysstat)
```bash
sar -u 1 3
```

### Host memory pressure
```bash
vmstat -s | head -10
```

### Host I/O wait (useful if frame extraction is disk-bound)
```bash
iostat -x 1 3
```

### MySQL active connections vs max
```bash
docker exec gighive-one-shot-bundle-mysql-1 mysql \
  -u root -p"$(docker exec gighive-one-shot-bundle-mysql-1 printenv MYSQL_ROOT_PASSWORD)" \
  -e "SHOW STATUS LIKE 'Threads_connected'; SHOW VARIABLES LIKE 'max_connections';"
```

### CPU cores available inside the container
```bash
docker exec gighive-one-shot-bundle-ai-worker-1 nproc
```

---

## Observed Production Results (2026-05-25, staging — 818-job queue)

### Confirmation screenshot
- **3 running jobs simultaneously** visible in admin UI — confirms `AI_WORKER_CONCURRENCY=3` working
- Jobs processing visibly faster than single-threaded baseline

### System health at steady state (3 threads + `AI_CHUNK_CONCURRENCY=4`)

| Metric | Value | Assessment |
|--------|-------|------------|
| CPU (`%user + %system`) | ~96% (84% user, 12% system) | At capacity — **3 is the ceiling for this host** |
| Memory used | 5.5 GB / 11.4 GB (48%) | Healthy |
| Swap | None | No risk |
| Disk `%iowait` | 0.00% | Not a bottleneck |
| NVMe RAID (`md0`) util | <2% | Plenty of headroom |

### Raw `sar` output
```
%user   %nice  %system  %iowait  %steal  %idle
84.40    0.42    11.58     0.00    0.00    3.61   (average over 3 samples)
```

### `docker stats --no-stream` (all containers)

```
CONTAINER    NAME                    CPU %    MEM USAGE / LIMIT      MEM %   PIDs
ai-worker    gighive-...-ai-worker   342.55%  238.5MiB / 10.92GiB   2.13%   54
mysqlServer  mysqlServer               0.46%  682.4MiB / 10.92GiB   6.10%   40
tusd         apacheWebServer_tusd      0.00%   22.2MiB / 10.92GiB   0.20%   23
apache       apacheWebServer           0.01%  140.6MiB / 10.92GiB   1.26%  111
```

In Docker's accounting **100% = 1 core**, so `342%` = **3.4 of 4 cores** consumed by the
ai-worker. MySQL, Apache, and tusd are essentially idle — no starvation.
PIDs: 54 confirms multiple threads are genuinely active.

### Concurrent upload stress test (106 files / 17.21 GB uploaded while AI worker running)

| Phase | ai-worker CPU | Apache CPU | Host `%idle` | Notes |
|-------|--------------|------------|--------------|-------|
| Baseline (AI only) | 342% | ~0% | 4% | 3 threads at steady state |
| Upload starting | 263% | 35% | 13% | OS throttled AI worker to share |
| Mid-upload peak | 187% | 190% | 0% | Simultaneous finalization bursts |
| 80% done | 159% | 140% | 0% | Still fully saturated |
| Final burst | 207% | 195% | 0.25% | `%system` spiked to 24% (php-fpm forks) |
| Upload complete | 385% | 0.01% | 0.25% | Fully recovered, back to baseline |

**Conclusions:** System handled concurrent upload + 3-thread AI tagging without errors or failures.
Apache peaked at 190% during finalization bursts (ffmpeg thumbnail + ffprobe per file).
Host hit 100% CPU during peak but degraded gracefully — OS scheduler divided CPU fairly.
AI worker recovered immediately once upload finalization cleared.

### Tuning conclusions
- **`AI_WORKER_CONCURRENCY=3`** is the correct ceiling for a 4-core host — CPU is ~96% utilised.
  Adding a 4th job thread would cause CPU contention on ffmpeg frame extraction and likely
  slow throughput, not improve it.
- **`AI_CHUNK_CONCURRENCY=4`** is safe — LLM calls are network-bound and consume negligible CPU,
  so concurrent chunk calls during the OpenAI phase do not contribute to CPU saturation.
- Disk and memory are not constraints on this hardware (4× NVMe RAID, 11 GB RAM).
- On a host with more CPU cores, `AI_WORKER_CONCURRENCY` can be raised proportionally
  (e.g. 6 on an 8-core host).

---

## Related Docs

- `docs/guide_upload_estimated_times.md` — upload pipeline performance context
- `docs/refactor_uploads_tus_parallel.md` — TUS parallel chunk upload plan (implemented)
