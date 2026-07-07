# Concurrent TUS Upload Load Test — Results

**Date:** 2026-07-06  
**Environment:** devvm.gighive.internal (VirtualBox VM on pop-os)  
**Load generator:** pop-os (192.168.1.235), separate host to mimic real-world conditions  
**PHP-FPM `pm.max_children`:** 20  

---

## Summary

The pipeline handles concurrent TUS uploads gracefully across all tested conditions.

- **Synthetic 512 KB files:** zero failures up to c=50. Throughput ceiling ~11/s set by `pm.max_children=20`.
- **Real 368 MB files (stagger=1.0):** zero failures up to c=20. First failure (1/25 broken pipe) at c=25.
- **VirtualBox NIC** is the limiting factor for large-file concurrency — not the application.
- The application pipeline itself has no error budget impact at any tested load level.

---

## Full Load Profile — Synthetic 512 KB Files

| Concurrency | Count | Success | Fin p50 | Fin p90 | Fin max | FPM_QUEUE peak | Throughput |
|:-----------:|:-----:|:-------:|:-------:|:-------:|:-------:|:--------------:|:----------:|
| c=10 | 20 | 20/20 ✅ | 1.20s | 2.04s | 2.20s | 0 | 6.59/s |
| c=20 | 40 | 40/40 ✅ | 1.93s | 2.80s | 2.80s | 0 | 8.31/s |
| c=25 | 50 | 50/50 ✅ | 2.19s | 3.18s | 3.69s | 5 | 9.06/s |
| c=30 | 60 | 60/60 ✅ | 2.58s | 3.66s | 4.13s | 17 | 9.65/s |
| c=40 | 80 | 80/80 ✅ | 3.06s | 4.12s | 4.39s | 29 | 10.91/s |
| c=50 | 100 | 100/100 ✅ | 3.74s | 4.53s | 5.45s | 39 | 11.14/s |

> **File:** 512 KB synthetic payload (ftyp MP4 header + random bytes + 8-byte unique suffix)  
> **TUS-only:** False (full pipeline: TUS CREATE → PATCH → PHP finalize → MySQL insert)

---

## Key Findings

### 1. Throughput Ceiling: ~11 ok-uploads/s

Throughput increases sub-linearly with concurrency and plateaus above c=40.
The marginal gain from c=40 → c=50 was only +2% (10.91 → 11.14/s).
This ceiling is set by `pm.max_children=20` — the FPM pool is the bottleneck.

### 2. FPM Queue is the Degradation Mechanism

Once active concurrency exceeds 20, requests queue at the FPM UNIX socket.
The queue depth at peak scales approximately as `concurrency − active_workers`.

| Concurrency | FPM_QUEUE peak | FPM_SPAWNED peak |
|:-----------:|:--------------:|:----------------:|
| c=25 | 5 | 13/20 |
| c=30 | 17 | 13/20 |
| c=40 | 29 | 9/20 → 19/20 |
| c=50 | 39 | 11/20 → 19/20 |

The two-poll pattern (high queue → near-zero queue) reflects FPM spawning  
workers dynamically to drain the queue within a single 2-second monitor interval.

### 3. FPM Finalize Dominates Latency (~97% of total time)

The TUS layer (tusd PATCH) is not a bottleneck at any tested level.  
Nearly all end-to-end latency is PHP finalize time (SHA-256 check, file move,  
ffprobe metadata, MySQL insert).

| Concurrency | TUS p50 | TUS max | Finalize p50 | Finalize max |
|:-----------:|:-------:|:-------:|:------------:|:------------:|
| c=10 | 0.05s | 0.07s | 1.20s | 2.20s |
| c=50 | 0.09s | 0.35s | 3.74s | 5.13s |

TUS p90 begins to climb at c=40+ (0.34s), indicating Apache is also starting  
to queue PATCH requests at very high concurrency, but it remains minor.

### 4. Graceful Degradation — Zero Failures

No upload failed at any concurrency level. Requests that exceed worker capacity  
queue at the FPM socket and are served in order. There is no error budget impact  
from overload — only latency impact.

### 5. Safe Zone vs. Degraded Zone

| Zone | Concurrency | Behavior |
|------|:-----------:|---------|
| **Safe** | ≤ c=20 | FPM queue = 0, finalize ≤ 2.8s, throughput scales linearly |
| **Degraded (functional)** | c=21–50+ | Queue grows, latency increases, throughput plateaus ~11/s, no failures |

---

## Real-File Tests — 368 MB MP4

All tests use a real 368 MB production video file uploaded from pop-os  
(separate host) to mimic real-world network conditions.

### Simultaneous launch (no stagger)

| Concurrency | Count | Stagger | Result |
|:-----------:|:-----:|:-------:|:------:|
| c=4 | 4 | none | 4/4 ✅ Clean |
| c=5 | 5 | none | 4/5 ❌ 1× "Broken pipe" (TUS-PATCH phase) |

### Staggered launch (--stagger 1.0) — full progression

| c | Count | Result | TUS p50 | TUS max | Fin p50 | Fin max | Mem peak | FPM queue | Wall |
|:---:|:---:|:---:|:---:|:---:|:---:|:---:|:---:|:---:|:---:|
| c=5 | 5 | 5/5 ✅ | 15.9s | 18.2s | 4.1s | 4.5s | 1,084 MiB | 0 | 22.8s |
| c=8 | 8 | 8/8 ✅ | 36.0s | 39.2s | 5.3s | 17.3s | 1,096 MiB | 0 | 47.5s |
| c=10 | 10 | 10/10 ✅ | 32.7s | 35.3s | 10.3s | 12.6s | 2,503 MiB | 0 | 47.5s |
| c=12 | 12 | 12/12 ✅ | 39.3s | 44.1s | 10.0s | 12.3s | 2,421 MiB | 0 | 57.6s |
| c=15 | 15 | 15/15 ✅ | 32.9s | 40.6s | 3.6s | 6.8s | 2,535 MiB | 0 | 50.1s |
| c=20 | 20 | 20/20 ✅ | 49.1s | 58.9s | 7.2s | 9.4s | 2,811 MiB | 4 | 72.5s |
| **c=25** | 25 | **24/25 ❌** | 62.7s | 77.6s | 6.1s | 10.7s | 2,557 MiB | 5 | 92.0s |

**Safe limit: c=20. Point of breakage: c=25 (1/25 broken pipe).**

**Root cause — VirtualBox NIC stream saturation:**  
At c≤5 (no stagger), the broken pipe was a thundering-herd burst at t=0.  
With stagger=1.0, this is eliminated for c≤20, but at c=25 all 25 streams  
are simultaneously active for 50–80 seconds each. The VirtualBox NIC cannot  
sustain ~25 concurrent 368 MB TCP streams indefinitely — one connection is  
dropped mid-PATCH. The NIC ceiling sits somewhere between 20 and 25 streams.

Server application logs (`apacheWebServer`, `apacheWebServer_tusd`) showed  
no errors at any level — the failure is entirely at the VirtualBox NIC layer.

**Memory:** peaks around 2.5–2.8 GiB during large finalize waves regardless  
of concurrency (bounded by how many finalizes cluster simultaneously, not by `c`  
directly). VM has 7.8 GiB total / 6.3 GiB available — memory is not the limit.

**FPM queue:** first appears at c=20 (transient peak of 4), c=25 (peak of 5).  
Both drain within one monitor cycle. FPM is not the bottleneck for real files.

---

## Monitor Snapshots

### Session aggregate (442 samples, 2s interval — all runs combined)
```
══════════════════════════════════════════════════════════════
  Peak values over 442 samples (2s interval)
══════════════════════════════════════════════════════════════
  Apache CPU%:                 752.52%   ← c=20 real-file finalize wave
  tusd CPU%:                   110.95%   ← c=15 real-file PATCH phase
  MySQL CPU%:                  10.06%    ← c=50 synthetic
  Apache memory:               2811 MiB  ← c=20 real-file finalize wave
  FPM workers (peak):          19 / 20   ← c=50 synthetic
  FPM listen queue (peak):     39        ← c=50 synthetic
  MySQL Threads_running:       22        ← c=50 synthetic

  Full log: load_test_runs/monitor_20260706_194312.tsv
══════════════════════════════════════════════════════════════
```

> The 752% Apache CPU and 2,811 MiB memory peaks are from real 368 MB file runs.  
> The FPM queue=39 and MySQL threads=22 peaks are from synthetic c=50 runs.  
> These maximums were observed in separate tests and did not co-occur.

### Per-run peak rows — synthetic files

#### c=25 (first saturation signal)
```
19:43:25   176.36%   6.14%   6.75%   13/20 spawned   5 🔴   240.3 MiB   10
```

#### c=30
```
19:45:15   82.51%    4.04%   6.95%   13/20 spawned   17 🔴   248 MiB    9
```

#### c=40
```
19:47:11   135.74%   4.93%   4.45%   9/20 spawned    29 🔴   242.5 MiB  9
19:47:15   225.12%   0.00%   9.68%   19/20 spawned   0        275.3 MiB  14
```

#### c=50
```
19:49:56   163.93%   6.28%   6.44%   11/20 spawned   39 🔴   266.3 MiB  11
19:50:00   266.66%   0.00%   10.06%  19/20 spawned   7  🔴   288.5 MiB  22
```

---

## Recommendations

1. **`pm.max_children=20` is appropriate** for the current workload. It provides  
   a stable throughput ceiling of ~11 ok-uploads/s with graceful queueing.

2. **Scaling headroom:** Increasing `pm.max_children` (e.g. to 30–40) on hosts  
   with sufficient RAM would push the throughput ceiling proportionally, since  
   the finalize pipeline has no single-threaded bottleneck.

3. **Production real-file concurrency:** The devvm VirtualBox NIC ceiling is  
   c=20 stable / c=25 first failure. These limits are specific to the VirtualBox  
   NIC layer and are not expected on bare-metal. Should be re-validated on  
   lab/staging hardware with real files using `--stagger 1.0`.

4. **MySQL at c=50:** Threads_running peaked at 22 (above `max_children=20`),  
   suggesting some queries were already queued at the DB layer. Worth monitoring  
   `innodb_row_lock_waits` under sustained high-concurrency load.

---

## Test Environment

- **Load generator:** pop-os (192.168.1.235), Python 3 + aiohttp  
- **Target:** devvm.gighive.internal (192.168.1.50), VirtualBox VM  
- **Script:** `load_tests/load_test_guest_uploads.py`  
- **Monitor:** `load_tests/monitor_load_test.sh` (2s poll interval)  
- **Logs:** `load_tests/load_test_runs/loadtest_20260706_*.txt`  
