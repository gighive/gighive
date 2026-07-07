# Concurrent TUS Upload Load Testing Plan

**Script:** `load_tests/load_test_guest_uploads.py`
**Target:** QR guest upload pipeline — TUS (`/files/`) + finalize (`/api/uploads/finalize`)
**Audience:** Dev / ops; run against local install before Cloudflare-fronted environments

---

## Quick Start — Running the Test on gighive2

> **Where to run each tool:**
> - `monitor_load_test.sh` uses `docker exec` / `docker stats` — **must run on devvm (gighive2)** — SSH in from pop-os
> - `load_test_guest_uploads.py` — **run from pop-os** at `~/gighive/load_tests/`; it's already there via the NFS mount
> - Both machines are on the same LAN — no VPN or hosts entry needed

1. **SSH into gighive2** and confirm containers are up: `docker ps`
2. **Get a token** — admin panel → Events → QR Generator → copy the token from the URL
3. **On pop-os**, install the dependency if not already present: `pip install aiohttp`
4. **Start monitoring** in a gighive2 SSH terminal:
   ```bash
   ./monitor_load_test.sh
   ```
5. **Run the load test from pop-os** (`~/gighive/load_tests/`) — start conservatively:
   ```bash
   python3 load_test_guest_uploads.py \
     --url https://devvm.gighive.internal \
     --token <TOKEN> --no-ssl-verify \
     --concurrency 5 --count 20
   ```
6. **Watch the monitor** — `FPM_SPAWNED` and `FPM_QUEUE` are the key columns; queue > 0 = saturated
7. **Repeat with higher concurrency** — `--concurrency 10`, `15`, `20` until you find the ceiling
8. **Add slow log** in terminal 3 to catch requests taking > 10 s:
   ```bash
   docker exec apacheWebServer tail -f /var/log/php-fpm/www.slow.log
   ```
9. **Review results** — two log files are written per run:
   - `load_test_runs/monitor_YYYYMMDD_HHMMSS.tsv` — on **devvm (gighive2)**, at `~/scripts/gighive/load_tests/load_test_runs/`
   - `load_test_runs/loadtest_YYYYMMDD_HHMMSS_cN.txt` — on **pop-os**, at `~/gighive/load_tests/load_test_runs/` (also visible on Mac via NFS)
10. **Clean up** between passes — remove test records via admin panel or direct DB/disk

> ⚠️ The server must have been rebuilt with the updated `www.conf` (`pm.max_children = 20`) before testing. Run the Ansible deploy playbook if in doubt.

---

## 1. Architecture Summary

```
iPhone / Browser
       │  HTTPS
       ▼
  Cloudflare (prod only)
       │
       ▼
  Apache (mpm_event)  ←── front door
       │
       ├─── /files/*  ──────────────► tusd container (Go)
       │    [pure TCP proxy,              writes to tusd_data volume
       │     no PHP involved]             fires post-finish hook → hook-out volume
       │
       └─── /api/uploads/finalize ──► PHP-FPM (Unix socket)
            [X-Upload-Token gate]         reads hook JSON, moves file,
                                          runs ffprobe × 2-3, ffmpeg × 1,
                                          writes MySQL
```

### Auth gating (Apache)

`/files/` and `/api/uploads/finalize` both use `<RequireAny>`:
- `Require user admin uploader` (Basic Auth), **or**
- `Require env upload_token_auth` — set by `SetEnvIf X-Upload-Token .+ upload_token_auth`

Apache checks only for the **presence** of `X-Upload-Token`. PHP (`UploadTokenValidator`) does
the actual cryptographic validation on finalize. Any non-empty header passes the Apache gate.

---

## 2. Bottleneck Stack (tightest → loosest)

| Layer | Hard limit | Notes |
|---|---|---|
| **PHP-FPM workers** | `pm.max_children` (was **5**, now **20** default) | Only finalize hits PHP; TUS PATCH does not |
| Apache `mpm_event` threads | 150 (default, not configured) | Handles TUS PATCH async — not a bottleneck |
| tusd Go process | Effectively unlimited by connection | Bounded by `tusd_data` volume disk I/O |
| MySQL | Bounded by PHP worker count | Max ~20 concurrent INSERTs |
| Disk I/O | tusd write + file copy on finalize | File move is cross-volume (Docker named vol → host bind mount = full copy, not rename) |

### What finalize actually does (per upload)

This is **not** a lightweight DB insert. Every finalize call does:

1. **Hook file polling** — up to 10 × 200 ms = 2 s wait for tusd `post-finish` hook JSON
2. **`finfo->file()`** — MIME detection from file magic bytes (fast)
3. **`hash_file('sha256', $file)`** — reads + hashes entire file; CPU-bound; scales with file size
4. **File copy** — tusd temp dir (named volume) → `video/` (host bind mount) = full byte copy
5. **Subprocess chain** (all blocking, all synchronous within the worker):
   - `command -v ffprobe` × 3 (no cross-request cache; new object per request)
   - `ffprobe -version` × 1
   - `ffprobe ... -show_entries format=duration` × 1 (duration probe)
   - `ffprobe ... -print_format json -show_format -show_streams -show_chapters` × 1 (full media info)
   - `ffmpeg ... -frames:v 1 ...` × 1 (thumbnail PNG; conditional on file being valid video)
   - **Total: ~7 subprocess forks per finalize call**
6. **MySQL** — 5–7 queries: duplicate check SELECT, asset INSERT, delete token UPDATE,
   next-position SELECT, event_item INSERT, plus guest-mode transaction
   (`upload_jobs` INSERT + `anon_upload_attributions` INSERT)

**Practical throughput with real videos:**
With 20 PHP-FPM workers and each finalize taking ~10 s on a small server:
`20 workers ÷ 10 s = ~2 uploads/s sustained throughput` — and CPU will be the actual wall
(7 subprocess forks × 20 concurrent = 140 processes fighting for CPU cores).

---

## 3. Config Changes Made

### `www.conf.j2` — PHP-FPM pool

Two changes from the original hardcoded values:

**pm.max_children** is now Jinja2-templated (was hardcoded `5`):
```ini
pm.max_children      = {{ php_fpm_max_children      | default(20) }}
pm.start_servers     = {{ php_fpm_start_servers     | default(4)  }}
pm.min_spare_servers = {{ php_fpm_min_spare_servers | default(2)  }}
pm.max_spare_servers = {{ php_fpm_max_spare_servers | default(6)  }}
```

To set per-environment, add to group_vars (e.g. `prod/prod.yml`):
```yaml
php_fpm_max_children: 5        # conservative for prod
php_fpm_start_servers: 2
php_fpm_min_spare_servers: 1
php_fpm_max_spare_servers: 3
```

**FPM status page** enabled for monitoring during tests:
```ini
pm.status_path = /status
```

---

## 4. Prerequisites Before Testing

### 4a. Rebuild the local container

After the `www.conf.j2` changes, the container must be rebuilt and restarted so the new FPM
config is baked in:

```bash
# From gighiveinfra root
ansible-playbook ansible/playbooks/site.yml -l gighive --tags docker
# or however you normally rebuild/restart the stack locally
```

### 4b. Get a valid QR upload token

The token is required because PHP validates it on finalize (not just Apache presence-check).

1. Log in to the admin panel → Events → the event you want to test against
2. Open the QR Generator card → copy the token value from the generated URL
   (format: `https://your.server/upload/<TOKEN>`)
3. Confirm the token is active and not expired (`is_active = 1`, `expires_at > NOW()`)

The token must be for a real event that already exists in the DB — finalize reads
`event_date`, `org_name`, `event_type` from the token record.

### 4c. Generate a valid test video (for realistic ffprobe/ffmpeg testing)

Synthetic random bytes will make ffprobe fail quickly, masking the real CPU cost.
A small valid MP4 exercises the full finalize chain authentically:

```bash
# Requires ffmpeg installed locally
# Generates a 5-second silent 640×480 H.264 clip (~300 KB)
ffmpeg -f lavfi -i color=c=black:s=640x480:r=24 \
       -f lavfi -i anullsrc \
       -t 5 -c:v libx264 -c:a aac -shortest \
       /tmp/test_clip.mp4
```

### 4d. Install the Python dependency

```bash
pip install aiohttp
```

---

## 5. Load Test Script

**Location:** `load_tests/load_test_guest_uploads.py` (run from `load_tests/`)

```
usage: load_test_guest_uploads.py
  --url           https://your.server.com    Base URL (no trailing slash)
  --token         <QR_TOKEN>                 Active QR upload token value
  --concurrency   N                          Max simultaneous uploads (default: 10)
  --count         N                          Total uploads to run (default: 20)
  --size-kb       N                          Synthetic file size KB (default: 512)
  --real-file     /path/to/test_clip.mp4     Use a real file instead of synthetic bytes
  --tus-only                                 Skip finalize; test tusd + disk only
  --no-ssl-verify                            Disable SSL cert verification (needed for local self-signed)
  --timeout       130                        Per-request timeout in seconds (covers CF's 100s limit)
  --log-dir       load_test_runs             Directory for per-run result logs
```

**What each upload does:**
1. `POST /files/` (TUS CREATE) — creates the upload slot in tusd
2. `PATCH /files/<id>` (TUS DATA) — single-chunk data transfer
3. `POST /api/uploads/finalize` — triggers the full PHP finalize chain; up to 6 attempts
   with exponential backoff (0 → 0.4 → 0.8 → 1.2 → 1.6 → 2.0 s) to absorb the
   hook-file race between tusd and PHP

**Output metrics per run:**
- Success / failure counts
- Wall-clock time and throughput (uploads/s)
- End-to-end latency p50 / p90 / p99 / max
- TUS phase latency breakdown
- Finalize phase latency breakdown (PHP+MySQL)
- Failure breakdown grouped by error type and HTTP status

---

## 6. Recommended Test Sequence

All dev runs are executed from pop-os (`~/gighive/load_tests/`) targeting `devvm.gighive.internal`.
All dev runs use `--no-ssl-verify` (self-signed cert on devvm). Collect at least 2 runs per
concurrency level and average results.

### Pass 1 — Baseline (TUS only, synthetic file)

Isolates tusd + disk I/O from PHP. Any concurrency level should sustain well.
If this degrades, the problem is network / tusd / disk, not PHP.

```bash
python3 load_test_guest_uploads.py \
  --url https://devvm.gighive.internal --token <TOKEN> \
  --no-ssl-verify --tus-only \
  --concurrency 10 --count 40
```

Repeat with `--concurrency 20`, `30`, `50`.

### Pass 2 — Full pipeline, synthetic file

Shows the PHP-FPM queue and MySQL behavior without the ffprobe/ffmpeg CPU load.
Results will be *optimistic* vs. real uploads but confirm the queuing model is correct.

```bash
python3 load_test_guest_uploads.py \
  --url https://devvm.gighive.internal --token <TOKEN> \
  --no-ssl-verify \
  --concurrency 5  --count 20
# then: 10, 15, 20, 25 concurrent
```

### Pass 3 — Full pipeline, real video file

The most realistic test. Uses the 5-second MP4. This exercises:
- sha256 hashing
- File copy across volumes
- All ffprobe + ffmpeg subprocess forks
- Full MySQL write chain

```bash
python3 load_test_guest_uploads.py \
  --url https://devvm.gighive.internal --token <TOKEN> \
  --no-ssl-verify \
  --real-file /tmp/test_clip.mp4 \
  --concurrency 5  --count 20
# then: 10, 15, 20 concurrent
```

> ⚠️ Each successful run creates real DB records and files on disk.
> Clean up between test passes via the admin panel or direct DB/disk access.

---

## 7. Monitoring During Tests

Use the companion script `monitor_load_test.sh` (in `load_tests/`, alongside the load test script).
It polls all three containers every 2 seconds and writes a timestamped TSV to `load_test_runs/`.

### What the script captures

| Signal | Source | Why it matters |
|---|---|---|
| CPU% per container | `docker stats` | Identifies which container eats CPU under load |
| Memory per container | `docker stats` | Confirms no PHP-FPM OOM (512 MB limit × workers) |
| Network I/O (Apache) | `docker stats` | Bytes in/out — confirms traffic is reaching the stack |
| Block I/O (Apache + tusd) | `docker stats` | Disk pressure on `tusd_data` volume during file copy |
| **FPM worker count** | `ps aux` inside Apache container | `N / pm.max_children` — primary saturation signal |
| **FPM listen queue depth** | `ss -xlnp` inside Apache container | > 0 = requests queuing; this is the saturation threshold |
| MySQL `Threads_running` | `SHOW GLOBAL STATUS` via `docker exec` | DB contention under concurrent finalizes |

### Terminal layout for a test run

Container names come from group_vars (`apache_container_name`, `mysql_db_host`) and are
consistent across all environments — no overrides needed unless you rename them.

```
Terminal 1 — monitoring (start first, run from load_tests/)
  ./monitor_load_test.sh
  # Defaults: apacheWebServer / apacheWebServer_tusd / mysqlServer (from group_vars)
  # Override if needed: APACHE_CONTAINER=x TUSD_CONTAINER=y MYSQL_CONTAINER=z ./monitor_load_test.sh

Terminal 2 — load test (from pop-os, ~/gighive/load_tests/)
  python3 load_test_guest_uploads.py \
    --url https://devvm.gighive.internal --token <TOKEN> \
    --no-ssl-verify --concurrency 10 --count 30

Terminal 3 — slow log (requests > 10 s flagged by PHP-FPM)
  docker exec apacheWebServer tail -f /var/log/php-fpm/www.slow.log

Terminal 4 — Apache error log (502/504 on FPM proxy timeout)
  docker exec apacheWebServer tail -f /var/log/apache2/error.log
```

### Live display columns

```
TIME          APACHE_CPU    TUSD_CPU      MYSQL_CPU   FPM_SPAWNED   FPM_QUEUE    MEM_MiB   MYSQL_THR
14:22:03      38.2%         12.1%         4.5%        9/20 spawned  0            312        4
14:22:05      71.4%         18.3%         6.2%        16/20 spawned 0            318        8
14:22:07      94.8%         19.1%         7.1%        20/20 spawned 3            321        11   ← queue > 0: saturated
```

- **`FPM_SPAWNED`** turns yellow at ≥ 80% of `FPM_MAX_CHILDREN`, red at 100%
- **`FPM_QUEUE`** turns red the moment it exceeds 0 — this is your saturation point

### On Ctrl-C the script prints peak values

```
══════════════════════════════════════════════════════════════
  Peak values over 47 samples (2s interval)
══════════════════════════════════════════════════════════════
  Apache CPU%:                 94.8%
  tusd CPU%:                   19.1%
  MySQL CPU%:                  7.1%
  Apache memory:               321
  FPM workers (peak):          20 / 20
  FPM listen queue (peak):     5
  MySQL Threads_running:       11
══════════════════════════════════════════════════════════════
```

### FPM listen queue — the key signal

The FPM socket (`/run/php/php8.3-fpm.sock`) has a listen backlog of 511.
`ss -xlnp` reports the `Recv-Q` field on the LISTEN entry — this is the number of
requests sitting in the kernel socket queue waiting for a worker to call `accept()`.

- **Queue = 0** → workers available; latency is dominated by finalize work time
- **Queue > 0** → all workers busy; new requests wait in kernel queue; latency climbs linearly
- **Queue > 511** → kernel starts rejecting connections; Apache gets a connection error → 502

The concurrency level at which the queue *first rises above 0* is your practical ceiling
for the current `pm.max_children` setting.

### TSV log for post-test analysis

Each test session produces two complementary log files, each in its own `load_test_runs/` directory:

| File | Machine | Path |
|---|---|---|
| `monitor_YYYYMMDD_HHMMSS.tsv` | **devvm (gighive2)** | `~/scripts/gighive/load_tests/load_test_runs/` |
| `loadtest_YYYYMMDD_HHMMSS_cN.txt` | **pop-os** | `~/gighive/load_tests/load_test_runs/` (NFS → visible on Mac) |

Correlate them by timestamp to answer: *at the moment FPM queue rose above 0, what was the p90 finalize latency?*

Load into Numbers, Excel, or Python pandas to plot CPU vs FPM workers vs queue depth
across a sweep of concurrency levels.

```bash
# SCP the monitor TSV from devvm to pop-os (run from ~/gighive/load_tests/ on pop-os):
scp ubuntu@devvm.gighive.internal:~/scripts/gighive/load_tests/load_test_runs/monitor_*.tsv load_test_runs/
# The file then appears on the Mac automatically via the NFS mount.
```

```python
import glob
import pandas as pd
import matplotlib.pyplot as plt
latest = sorted(glob.glob("load_test_runs/monitor_*.tsv"))[-1]
df = pd.read_csv(latest, sep="\t", parse_dates=["timestamp"])
df.plot(x="timestamp", y=["apache_cpu_pct", "fpm_spawned", "fpm_listen_queue"])
plt.show()
```

---

## 8. Interpreting Results

| Observation | Diagnosis |
|---|---|
| **TUS-only fast, full pipeline slow** | PHP-FPM or MySQL is the bottleneck |
| **Finalize p90 climbs steadily with concurrency** | FPM listen queue filling — normal queuing behaviour; requests succeed but latency grows |
| **`Finalize 404`** | Invalid or expired upload token — `UploadTokenValidator` returned null; retrying will not help; check token `is_active=1` and `expires_at > NOW()` |
| **`Finalize 409`** | Duplicate SHA-256 — same file bytes already ingested for this event; ensure unique content per upload and clean up between runs |
| **`Finalize 500`** | Transient: tusd post-finish hook JSON not yet written to disk — script retries automatically (up to 6 attempts). Persistent after all retries: real PHP error — check Apache error log and PHP slow log |
| **`502` / `504`** | Apache's 300 s FPM proxy timeout hit; a worker took too long (likely ffmpeg on a large/corrupt file) |
| **`524`** (Cloudflare only) | Cloudflare's own **100-second upstream timeout** — CF dropped the connection before Apache responded. Distinct from 504. Check CF dashboard → Analytics → Errors |
| **`listen queue` > 0 in FPM status** | You've exceeded `pm.max_children`; requests are queueing — expected above saturation point |
| **`max children reached` incrementing** | Confirms saturation; note the concurrency level at which it first appears |
| **CPU at 100% on server** | The 7-subprocess-per-upload chain is the cause; reducing `pm.max_children` reduces peak CPU |

---

## 9. Cloudflare Considerations (future)

When testing through Cloudflare (prod and staging), several differences apply:

- **Upload size limit** — Cloudflare free/pro plans cap request bodies at 100 MB by default;
  large real videos may be silently terminated. Verify the plan's limits before testing.
- **TUS resumability** — Cloudflare may buffer or interrupt long-lived PATCH requests.
  tusd's retry delays (`[0, 1000, 3000, 5000]` ms in the JS client) handle this, but the
  load test script does not retry PATCH — failures here are expected and acceptable as a data point.
- **Connection coalescing** — Cloudflare may coalesce concurrent connections from the same
  origin IP, making high-concurrency tests from a single machine look lower than real-world
  traffic from many phones. Test from multiple client IPs for more representative results.
- **Tunnel vs. public DNS** — if testing via Cloudflare Tunnel, the tunnel's bandwidth
  and connection limits add another variable. Test the origin directly first.
- **SSL** — Cloudflare terminates TLS; remove `--no-ssl-verify` when testing through CF.
- **CF upstream timeout is 100 seconds** — stricter than Apache's 300 s FPM proxy timeout. If the FPM
  queue fills and finalize waits > 100 s, CF drops the connection and returns **524**, not 504. The
  `--timeout 130` default on the load test script is calibrated for this. Under heavy load you will
  hit CF's ceiling before Apache's.
- **CF WAF / Bot Fight Mode** — high-concurrency bursts from a single IP (20+ rapid POSTs) can
  trigger CF bot protection or rate-limiting rules, returning `403` or connection resets that look
  like server errors. Before a CF test run: check CF dashboard → Security → Firewall Events. If
  triggered, temporarily disable Bot Fight Mode or add the test machine IP to an Allow rule.
- **`X-Upload-Token` header** — verify CF doesn't strip it before running the full test:
  ```bash
  curl -sv -H "X-Upload-Token: test" https://dev.gighive.app/api/uploads/finalize 2>&1 | grep -i upload-token
  ```
  Expect to see the header reflected or a PHP-level 400/422, not a CF-level 403.

---

## 10. Tuning Levers (if saturation point is too low)

If real-video testing shows the server saturates at an unacceptably low concurrency:

### Short-term (no code changes)

| Lever | Change | Effect |
|---|---|---|
| `php_fpm_max_children` in group_vars | Increase (e.g. 30) | More parallel PHP workers; watch RAM (512 MB limit each) |
| Run fewer ffprobe calls | Cache `command -v ffprobe` result across the request | Saves ~3 forks per upload; minor |
| `pm.process_idle_timeout` | Add (e.g. `10s`) if using `ondemand` | Reclaim idle worker RAM |

### Longer-term (code changes)

| Change | Effect |
|---|---|
| Move ffprobe/ffmpeg to an async background job (already partly done via `ai_jobs`) | Finalize returns immediately after DB write; media probing happens out-of-band |
| Skip `ffprobeToolString()` (3 extra forks) — cache result in a file or env var | Eliminates ~3 shell_execs per finalize |
| Replace file copy with a hard link if volumes are on the same filesystem | Reduces file-move I/O from O(size) to O(1) |
| Add `pm.status_listen = 127.0.0.1:9001` to www.conf.j2 | Exposes FPM status on a dedicated socket; avoids routing `/status` through Apache auth |
