# Hardware Options for GigHive Server Upgrades

Date: 2026-05-30  
Status: Reference

---

## Overview

This document covers hardware upgrade options for the GigHive server role — the machine that
runs the Docker stack (Apache/PHP, MySQL, AI worker) and, optionally, the MCP server for
developer diagnostics. The analysis was prompted by performance ceilings encountered during a
5,800-file AI tagging run and a 6,745-file upload run on the current hardware.

---

## Current Server

| Component | Spec |
|-----------|------|
| **Model** | GMKtec NucBoxG9 ([Amazon listing](https://www.amazon.com/GMKtec-G9-Desktop-Computer-Attached/dp/B0DRYL8J7N)) |
| **CPU** | Intel N150 · 4 cores @ 3.6 GHz (Twin Lake efficiency chip) |
| **RAM** | ~11 GB usable (LPDDR5, soldered — not upgradeable) |
| **OS** | Ubuntu 24.10 x86_64 |
| **Storage** | 4 × 2 TB NVMe RAID-5 |

---

## Why Hardware Matters for GigHive

The GigHive server runs three concurrent workloads, each with distinct resource demands:

| Workload | CPU demand | RAM demand | Notes |
|----------|-----------|------------|-------|
| **Docker stack** (MySQL + Apache/PHP) | Low–medium | ~3–4 GB | Steady baseline |
| **AI worker** (ffmpeg frame extraction + OpenAI tagging) | **High — CPU-bound** | ~500 MB + ~500 MB per concurrent thread | Each ffmpeg frame-extraction pass pegs a full core |
| **MCP server** (developer diagnostics via SQL queries) | Negligible | ~100 MB | `stdio` Python process; not a bottleneck |
| **OS + headroom** | — | ~2 GB | — |

The two concrete ceilings hit on the current hardware:

- **AI worker concurrency capped at 3 threads** — a 4-core N150 cannot safely run more without
  starving MySQL and the upload pipeline. A 818-job queue that would run in ~4 hours on 8 real
  cores took ~12 hours.
- **RAM contention** — with ~11 GB usable, running 3 ffmpeg processes simultaneously alongside
  MySQL and Apache left ~2 GB free, making the system vulnerable to OOM pressure under peak load.

---

## Options to Skip

These machines on the anniversary sale page offer no meaningful improvement over the current
N150-class hardware:

| Machine | CPU | Reason to skip |
|---------|-----|----------------|
| **NucBox G3 Plus** | Intel N150 | Identical chip to current server |
| **NucBox G3S** | Intel N95 | Same efficiency tier; negligible gain |
| **NucBox G3 Pro** | Intel i3-10110U | 2019 dual-core; strictly worse for this workload |
| **G10** | AMD Ryzen 5 3500U | 2019 Zen+, 4 cores; lateral move at best |
| **NucBox G11** | AMD Ryzen Embedded R2514 | Embedded efficiency chip; not a workload machine |

---

## Meaningful Upgrades

| Machine | Cores | Price (32 GB / 1 TB config) | RAM max | RAM type | Notes |
|---------|-------|-----------------------------|---------|----------|-------|
| **NucBox M3 Pro** (i5-13500H) | 12 (4P+8E) | ~$680 | 32 GB | DDR4 SO-DIMM (upgradeable) | Most cores on the list; newer launch, some variants currently sold out |
| **NucBox K8 Plus** (Ryzen 7 8845HS) | 8 Zen 4 | ~$399 | 64 GB | DDR5 SO-DIMM (upgradeable) | Best price/performance ratio; easily expandable to 64 GB later |
| **NucBox K11** (Ryzen 9 8945HS) | 8 Zen 4 | ~$470–600 | 96 GB | DDR5 SO-DIMM (upgradeable) | Higher clocks and more L3 cache than K8 Plus; extreme headroom to 96 GB |
| **EVO-X1** (Ryzen AI 9 HX 370) | 12 Zen 4 + NPU | ~$700–892 | 64 GB | **LPDDR5X soldered — cannot upgrade** | Fastest; NPU useful for future local AI; RAM permanently fixed at purchase config |

> **Important on EVO-X1 RAM:** The 32 GB that ships is soldered to the board. If you buy a 32 GB
> unit and later need more, you cannot add more. The K8 Plus and K11 use standard DDR5 SO-DIMMs
> — you can buy 32 GB now and drop in 64 GB sticks later.

---

## Recommended RAM: 32 GB

This is the minimum comfortable tier for a GigHive server running the full stack with AI
worker parallelism enabled.

| Component | Typical footprint |
|-----------|------------------|
| OS + kernel overhead | ~2 GB |
| MySQL container | ~1–2 GB |
| Apache/PHP container | ~1 GB |
| AI worker base process | ~500 MB |
| 6× concurrent ffmpeg workers | ~3 GB |
| MCP server process | ~100 MB |
| Headroom / OS page cache | ~4 GB |
| **Total** | **~12–13 GB active · ~16–17 GB comfortable** |

| RAM tier | Assessment |
|----------|-----------|
| **16 GB** | Still tight — gains ~5 GB over today but 6+ concurrent AI workers still compete with MySQL under peak load |
| **32 GB** ✅ **Recommended** | ~15 GB free headroom; all containers stable with 6 concurrent workers, MCP server, and IDE open simultaneously |
| **64 GB** | Future-proof; worthwhile if you add a second GigHive stack or a local LLM on the same machine |

---

## Recommendation Summary

**Best value: NucBox K8 Plus (Ryzen 7 8845HS) at ~$399 with 32 GB DDR5**

- 8 Zen 4 cores vs. the current 4 efficiency N150 cores — AI worker concurrency grows from 3
  threads to 6+, roughly halving queue processing time
- Ships with 32 GB DDR5 SO-DIMMs; expandable to 64 GB at any time by swapping sticks
- ~$400 price point makes it the clearest cost-effective upgrade on the list

**Step up: NucBox K11 (Ryzen 9 8945HS) at ~$470–600**

- Marginal CPU gain over K8 Plus (higher clocks, more L3 cache, same core count)
- RAM ceiling of 96 GB is the main differentiator — worthwhile if the workload grows significantly

**Skip if on a budget: EVO-X1** — the soldered RAM is a long-term flexibility penalty that
outweighs the NPU benefit for this workload.

---

## Related Docs

- `docs/guide_upload_estimated_times.md` — empirical upload throughput figures from a 6,745-file run on the current hardware
- `docs/feature_mcp_server_beneficial.md` — MCP server analysis; details the AI worker CPU ceiling that motivated this hardware review
- `docs/refactored_ai_worker_parallelism_enable.md` — concurrency tuning session that hit the 3-thread ceiling
