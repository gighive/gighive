# Guide: Upload Estimated Times

## Overview

This guide provides empirically-derived upload time estimates based on a real production data load
of 6,745 supported media files totalling 2,071 GB. Use these figures to plan batch upload sessions.

---

## Test System

| Component | Spec |
|-----------|------|
| **Network** | Cat 5 Gigabit LAN |
| **Source machine** | Intel i7-11700K (16T) @ 4.9 GHz · 64 GB RAM |
| **Source OS** | Pop!_OS 22.04 LTS x86_64 (kernel 6.8.0-85-generic) |
| **Server (upload target)** | GMKtec G9 Mini PC ([Amazon listing](https://www.amazon.com/GMKtec-G9-Desktop-Computer-Attached/dp/B0DRYL8J7N)) |
| **Target storage** | 4 × 2 TB NVMe RAID-5 |
| **Server OS** | Ubuntu 24.10 |
| **Protocol** | TUS resumable upload, 8 MB chunks |

---

## Empirical Baseline

| Metric | Value |
|--------|-------|
| Total supported files | 6,745 |
| Total supported data | 2,071 GB (2.02 TB) |
| Average file size | ~307 MB |
| Files successfully loaded to DB | 6,119 |
| Wall-clock time | ~24 hours |
| Effective pipeline throughput | ~258 files/hr · ~78 GB/hr · ~21.7 MB/s |
| Overall success rate | ~98.7% (excluding known-bad source files) |

> **Note on throughput:** The 21.7 MB/s figure is the end-to-end pipeline rate — not raw network
> speed. It includes client-side SHA-256 hashing, 8 MB TUS chunk round-trips, server-side thumbnail
> generation, and database ingestion. LAN raw transfer is significantly faster; this is the
> bottleneck-adjusted rate per file.

---

## Estimates by File Count

Based on 258 files/hr and 307 MB average file size.

| Files | Est. Data | Est. Time | Expected Successes (~98.7%) |
|-------|-----------|-----------|----------------------------|
| 100 | ~30 GB | ~25 min | ~99 |
| 500 | ~150 GB | ~2 hr | ~494 |
| 1,000 | ~300 GB | ~4 hr | ~987 |
| 2,500 | ~768 GB | ~10 hr | ~2,468 |
| 5,000 | ~1.5 TB | ~19 hr | ~4,935 |
| **6,745** | **~2.07 TB** | **~26 hr** | **~6,119** ✅ empirical |
| 10,000 | ~3.0 TB | ~39 hr | ~9,870 |

---

## Estimates by Total Data Size

Based on 78 GB/hr effective throughput.

| Total Data | Est. Time |
|------------|-----------|
| 50 GB | ~40 min |
| 200 GB | ~2.5 hr |
| 500 GB | ~6.5 hr |
| 1 TB | ~13 hr |
| **2.07 TB** | **~26 hr** ✅ empirical |
| 4 TB | ~51 hr |

---

## File Breakdown from Test Run

| Category | Types | Count | Notes |
|----------|-------|-------|-------|
| Primary video | `.mov`, `.mp4`, `.m2t` | 5,938 | Core of the upload |
| Secondary video | `.avi`, `.mpg`, `.mkv`, `.m2ts`, `.ts`, `.wmv`, `.mpeg`, `.m4v`, `.webm`, `.flv` | 223 | Mostly compatible |
| Audio | `.mp3`, `.wav`, `.aac`, `.m4a`, `.au`, `.aiff`, `.ogg`, `.m2a` | 452 | Fast — small files |
| **Expected failures** | `.vob`, `.rm`, `.ifo`, `.bup`, `.m2v` | **128** | DVD/encrypted/metadata — fail fast, no wasted upload time |

Full file-type spec from the Choose Files scan:

```
Files: 16780 total, 6745 supported (2071.15 GB)
.mp4:1916  .mov:2835  .m2t:1187  .mp3:115  .au:155  .wav:131
.mpg:99    .mkv:44    .m2v:39    .ts:28    .avi:31  .vob:32
.m4a:21    .m2a:15    .m4v:3     .m2ts:2   .wmv:6   .webm:8
.aac:11    .flv:5     .rm:19     .ifo:19   .bup:19  .ogg:1
.mpeg:1    .aiff:3
```

---

## Factors That Shift Estimates

| Condition | Impact |
|-----------|--------|
| Larger average file size (e.g. 4K vs 1080p) | Proportional increase in transfer time |
| Upload bandwidth below Gigabit LAN | Network becomes bottleneck — each file takes longer |
| Corrupt/unplayable source files | Fail fast (~2–5 sec each) — negligible overall impact |
| Network interruption | Resume Upload recovers without re-hashing; ~1–2 min overhead |
| Browser closed mid-session | Resume Upload feature re-initialises and continues from last completed file |

---

## Planning Recommendations

- **Under 1,000 files / 300 GB** — complete in a single working session (4 hrs or less)
- **1,000–5,000 files** — plan as an overnight job
- **5,000+ files / 1 TB+** — plan for multiple overnight runs; use Resume Upload across sessions
- The browser tab must remain open; or use **Resume Upload** to continue after a restart
- Expected ~1–2% failure rate from unplayable source files (VOB/RM/encrypted DVD) — these fail fast and do not affect overall throughput

---

## Technical Discussion

### Would Larger Chunks Make It Faster?

The current TUS chunk size is **8 MB**. Increasing to 64 MB or 128 MB would reduce the number of
HTTP round-trips per file:

| Chunk Size | Requests per 307 MB file | RTT overhead @ 0.1 ms LAN | RTT overhead @ 20 ms WAN |
|------------|--------------------------|---------------------------|--------------------------|
| 8 MB (current) | ~38 | ~3.8 ms | ~760 ms |
| 32 MB | ~10 | ~1.0 ms | ~200 ms |
| 64 MB | ~5 | ~0.5 ms | ~100 ms |
| 128 MB | ~3 | ~0.3 ms | ~60 ms |

**On LAN:** The round-trip saving is negligible (milliseconds). Server-side processing
(thumbnail generation + DB write + TUS hook) runs **after** the last chunk lands and takes 3–6
seconds per file regardless of chunk size. Larger chunks would save roughly **5–10 minutes** on a
24-hour run — not worth the trade-off in memory pressure.

**Over the internet:** Larger chunks matter more. At 20 ms WAN RTT, 8 MB chunks add ~12 minutes
of pure round-trip overhead across 6,119 files. 64 MB chunks reduce that to ~1.7 minutes.

**The real lever is parallel uploads.** Running 2–4 files concurrently would roughly halve or
quarter the wall-clock time by overlapping server processing across files. This requires a
UI + concurrency change, but the group_vars infrastructure is already in place to configure it.

---

### group_vars Optimization Table

These are the variables in `ansible/inventories/group_vars/gighive/gighive.yml` that directly
affect upload performance. Change and re-run Ansible to apply.

| Variable | Current Value | Tuned for LAN | Tuned for WAN | Impact |
|----------|---------------|---------------|---------------|--------|
| `tus_client_chunk_size_bytes` | `8388608` (8 MB) | `33554432` (32 MB) | `67108864` (64 MB) | Low on LAN · Medium on WAN |
| `tus_client_retry_delays` | `[0,1000,3000,10000,30000]` | `[0,500,2000]` (faster retry on stable LAN) | Keep current | Low — only affects failure recovery time |
| `tus_client_remove_fingerprint_on_success` | `true` | `true` | `true` | Keep — prevents localStorage quota exhaustion on large batches |
| `tus_client_parallel_uploads` *(proposed)* | N/A | `3–4` | `2` | **High — 3× throughput; requires UI + server concurrency work** |

> **Note on `tus_client_parallel_uploads`:** This variable does not exist yet. Adding it would
> require changes to the JavaScript upload loop in
> `admin/admin_database_load_import_media_from_folder.php` to dispatch multiple `tus.Upload`
> instances concurrently, and server-side review of TUSD and thumbnail worker concurrency limits.
> The potential gain (24 hrs → ~7–8 hrs for the 6,200-file run) makes it the highest-value
> future optimization.

---

## Related Docs

- `docs/refactored_import_saved_jobs_browser_restart.md` — Resume Upload implementation
- `docs/refactor_upload_folder_messaging_server_monotonic_fix.md` — pending count fix on resume
