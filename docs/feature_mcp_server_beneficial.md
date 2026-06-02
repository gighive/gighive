# Feature: GigHive MCP Server — Analysis, Prioritisation, and Implementation Plan

Date: 2026-05-26  
Status: Draft / Design  
Origin: Conversation-driven discovery (2026-05-26 design session)

---

## Scope Boundary

**Current scope: developer operational tool only.**

The MCP server implemented here serves one purpose — the GigHive developer (and optionally
the media librarian) can ask an AI assistant (Windsurf/Cascade, Claude Desktop) operational
questions about a running GigHive deployment: queue depth, failed jobs, untagged assets,
event coverage, etc. The AI assistant spawns the MCP server on-demand via `stdio` over SSH;
the process exits when the session ends. No persistent daemon is required.

This is **not** a permanent always-on service endpoint. There is no autonomous AI agent or
external service consuming this MCP server in the current scope. If that use case emerges
in the future (e.g. a hosted AI assistant that end-users query, or an autonomous scheduling
agent), the transport would change to SSE/HTTP and the deployment would change to a Docker
service — but that is explicitly deferred and should not influence the current implementation.

---

## Background

This document captures the analysis from a design session exploring whether GigHive would
benefit from an MCP (Model Context Protocol) server. MCP is an open protocol (originated by
Anthropic) that standardises how AI assistants (Claude Desktop, Windsurf/Cascade, etc.)
connect to external data sources and tools. An MCP server exposes **tools** (callable
actions), **resources** (readable data), and optionally **prompts** (pre-built templates).

The discussion was prompted by a weekend of debugging that produced five refactor docs —
each one requiring repeated manual `docker exec mysql` queries, container log inspection,
and DB-vs-filesystem cross-referencing that would have been natural MCP tool calls.

---

## GigHive's Three Personas and MCP Fit

### Persona 1: Musicians / Wedding Photographers
Upload footage from events. They interact with GigHive through the web UI and iPhone app.
**MCP fit: Low.** Their workflows are event-scoped and short-lived. AI features built
directly into the GigHive UI (video tagger chips in `db/database.php`, tag review screens)
serve them far better than an MCP protocol they'd never interact with.

### Persona 2: Media Librarian (`APP_FLAVOR=defaultcodebase` / stormpigs)
Manages a large pre-existing media archive — potentially thousands of files imported via
the admin import tools. Needs to monitor ingestion progress, track AI tagging coverage
across a large corpus, search by tag, and triage failed jobs.
**MCP fit: Medium-High.** The librarian is in a _sustained management relationship_ with a
large corpus and benefits from natural-language querying over structured data: "Find all
assets tagged 'guitar solo' in events before 2010 with no participant assigned." This maps
directly to multi-table joins across `assets`, `events`, `event_items`, `taggings`,
`participants`. The librarian typically operates at a scale (thousands of assets) where
the existing admin UI pages become cumbersome.

### Persona 3: Developer / Operator
Builds and maintains the GigHive installation, tunes performance, triages bugs. Uses AI
coding assistants (Windsurf/Cascade, Claude Desktop) extensively.
**MCP fit: High — immediate.** The weekend debugging sessions (detailed below) prove the
need. The developer repeatedly ran manual DB queries and docker exec commands that would
have been single MCP tool calls.

---

## Evidence from the Weekend Debugging Sessions

Five refactor/debug docs produced over a single weekend collectively illustrate the
developer-side MCP case. In each case the right column shows what the manual work
would have looked like as an MCP tool call.

| Doc | Manual work done | MCP equivalent |
|-----|-----------------|----------------|
| `refactored_tus_retry_delays_and_frame_extractor.md` | Inspected `ai_jobs` after 5,800-file run with `docker exec mysql` JOIN query to find failed jobs and ffmpeg error messages; discovered bugs in `frame_extractor.py` from error text | `get_failed_jobs` → returns `{id, error_msg, source_relpath}` rows with error clustering |
| `refactored_ai_worker_parallelism_enable.md` | Iterated `AI_WORKER_CONCURRENCY` by running `docker stats`, `sar -u 1 3`, `vmstat`, live `docker logs -f` to find the 3-thread CPU ceiling on a 4-core host; 818-job queue | `get_ai_queue_stats` + `get_container_env_subset` → queue depth plus current concurrency env var values in one exchange |
| `refactored_upload_folder_messaging_server_monotonic_fix.md` | Debugged inflated pending count (77 → 175 files after Restart Upload) by cross-referencing `upload_status.json` on disk with the `assets` table; required manual `docker exec` into both containers | `get_upload_job_state(job_id)` → `{pending, done, already_present, failed}` counts reconciled against DB |
| `refactored_uploads_tus_parallel.md` | Measured baseline vs. parallel upload performance manually; tuned `tus_client_parallel_uploads`; verified via browser Network tab per-file timing | *(no MCP tool in current scope — a `get_upload_throughput_stats` tool would require per-file upload timing columns not yet in the schema; deferred)* |
| `refactored_ai_jobs_messaging.md` | Discovered the 500-row cap bug (UI polling fetched rows, silently capped at 500, so 818-job queue showed wrong counts); "complete" counter stuck at 0 on resume | `get_ai_queue_stats` (aggregate `GROUP BY status`) is correct _by construction_ — cannot hit a row cap |

The fifth case (`refactored_ai_jobs_messaging.md`) is the strongest: the existing admin UI
was architecturally wrong for large queues because it fetched rows and counted client-side.
The fix was a new server-side `?action=status_counts` aggregate endpoint in `api/ai_jobs.php`.
An MCP `get_ai_queue_stats` tool would have been correct from the start, and would have exposed
the discrepancy (UI says ≤500, MCP returns 818) immediately.

---

## Important Clarification on Data Model

The AI intelligence platform results are **not** queried from the AI worker directly.
The AI worker is the producer; the MCP server is a consumer of the resulting DB state.
All MCP queries operate on:
- `assets`, `events`, `event_items` — canonical media schema
- `taggings`, `tags` — AI-generated and human-confirmed tag data (surfaced in the
  Tags column of `db/database.php` via lazy JS batch fetch)
- `ai_jobs`, `helper_runs` — job queue and execution audit trail

---

## Architectural Flow

```
Developer (you)  — local machine (Ansible controller / baremetal host)
    │  Windsurf IDE — local process, no network
    ▼
Windsurf / Cascade
    │  stdio over SSH — port 22 → gighive-server (~/.ssh/config alias)
    ▼
MCP Python process  ← spawned on-demand on Docker host; exits when session ends
    │
    ├── TCP port 3306 (localhost) ──────────────► mysqlServer
    │   mysql-connector-python, credentials from host .env
    │   used by: tools 1–4, 6–9
    │                                              (AI worker is also a client of
    │                                               mysqlServer but is independent —
    │                                               MCP reads the state it writes)
    │
    ├── HTTPS port 443 (localhost) ─────────────► apacheWebServer
    │   calls PHP reconciliation endpoint
    │   used by: tool 5 (get_upload_job_state) only
    │
    └── filesystem read (no network) ──────────► host .env file
        used by: tool 10 (get_container_env_subset) only
```

Container names are consistent across the one-shot bundle and the full Ansible build.
`apacheWebServer_tusd` and `gighive-...-ai-worker-1` are running peers on the same
Docker network but the MCP server does not connect to them directly.

---

## MCP Tools — Priority Summary

| # | Tool | Effort | Primary driver | Query |
|---|------|--------|----------------|-------|
| 1 | `get_ai_queue_stats` | ~15 lines | `refactored_ai_jobs_messaging.md` — 500-cap bug | exact |
| 2 | `get_failed_jobs` | ~20 lines | `refactored_tus_retry_delays_and_frame_extractor.md` — 5,800-file run | exact |
| 3 | `get_stale_jobs` | ~10 lines | `refactored_ai_worker_parallelism_enable.md` — orphaned lock detection | needs validation |
| 4 | `reset_retryable_jobs` | ~20 lines | `refactored_tus_retry_delays_and_frame_extractor.md` — post-fix re-queue | exact |
| 5 | `get_upload_job_state` | ~25 lines | `refactored_upload_folder_messaging_server_monotonic_fix.md` — inflated pending count | needs validation |
| 6 | `search_assets_by_tag` | ~40 lines | Media librarian corpus search | needs validation |
| 7 | `get_events` with coverage stats | ~25 lines | Media librarian planning | needs validation |
| 8 | `get_untagged_assets` | ~15 lines | `api/ai_jobs.php` — pre-enqueue audit | exact |
| 9 | `get_tag_namespace_summary` | ~10 lines | `api/tags.php` — corpus quality review | exact |
| 10 | `get_container_env_subset` | ~20 lines | `refactored_ai_worker_parallelism_enable.md` — env var inspection | needs validation |

**Query legend:**
- **exact** — query sourced directly from a source `.md` refactor doc or existing PHP API file read during this analysis
- **needs validation** — query constructed from schema knowledge; should be tested against a live instance before shipping

---

## Priority-Ordered List of MCP Wins

### Priority 1 — `get_ai_queue_stats` (aggregate queue state)

**Rationale:** Maps directly to the existing `?action=status_counts` endpoint just
added to `api/ai_jobs.php`. Returns `{queued, running, done, failed, total}` from a
single `GROUP BY status` query. This is the single most-reached-for diagnostic during
large tagging runs. Coding effort: ~15 lines wrapping the existing SQL.

**Query:**
```sql
SELECT status, COUNT(*) AS n FROM ai_jobs
WHERE job_type='categorize_video' GROUP BY status
```

---

### Priority 2 — `get_failed_jobs` (with asset paths and error clustering)

**Rationale:** The exact query written manually during the 5,800-file debugging session
(from `refactored_tus_retry_delays_and_frame_extractor.md`). Identifies both retryable
and permanently-failed jobs by error pattern. Coding effort: ~20 lines.

**Query:**
```sql
SELECT j.id, j.updated_at, j.error_msg, j.attempts, a.source_relpath, a.file_type
FROM ai_jobs j
JOIN assets a ON a.asset_id = j.target_id
WHERE j.status = 'failed'
  AND j.job_type = :job_type
ORDER BY j.updated_at DESC
```

`job_type` defaults to `'categorize_video'`; without this filter, non-asset job types would
silently fail the `JOIN assets` and be excluded from results.

The tool should also group errors by pattern (e.g. `VOB`, `m2v`, `utf-8 codec`, `ffmpeg
failed`) so the caller sees "N permanently failed (VOB/encrypted), M retryable" without
reading every row.

---

### Priority 3 — `get_stale_jobs` (running > N minutes — orphaned lock detection)

**Rationale:** Stale `running` jobs after a container crash or SIGKILL are a recurring
operational issue (the `reset_stale_running_jobs` function in `db.py` handles this at
startup, but not during a running session). During the concurrency tuning session the
question "are any jobs stuck locked?" required manual SQL. Coding effort: ~10 lines.

**Query:**
```sql
SELECT j.id, j.locked_by, j.locked_at, j.attempts, a.source_relpath
FROM ai_jobs j
LEFT JOIN assets a ON a.asset_id = j.target_id
WHERE j.status = 'running'
  AND j.locked_at < NOW() - INTERVAL :minutes MINUTE
```

---

### Priority 4 — `reset_retryable_jobs` (the one write tool)

**Rationale:** The exact `UPDATE` command from `refactored_tus_retry_delays_and_frame_extractor.md`
run manually after deploying the `frame_extractor.py` fix. Resets failed jobs to `queued`
while excluding permanently-failing file types. This is the one meaningful write operation;
it should require explicit confirmation from the caller. Coding effort: ~20 lines.

**Query:**
```sql
UPDATE ai_jobs
SET status='queued', attempts=0, error_msg=NULL
WHERE status='failed'
  AND error_msg NOT LIKE '%VOB%'
  AND error_msg NOT LIKE '%.m2v%'
```
The tool should accept an optional `exclude_patterns` list and report `rows_reset` in
the response.

---

### Priority 5 — `get_upload_job_state(job_id)` (upload progress reconciliation)

**Rationale:** The core diagnostic from `refactored_upload_folder_messaging_server_monotonic_fix.md`.
Reads `upload_status.json` from the container filesystem and cross-references `pending`
checksums against `assets` to show how many are _actually_ already in the DB vs. genuinely
pending. Surfaces the inflated-count issue conversationally.

**Implementation note:** This tool needs access to `upload_status.json`, which lives
inside the Apache container filesystem. The MCP server runs on the server host (not inside
a container), so it calls the existing `import_manifest_upload_start.php` reconciliation
endpoint via the Docker bridge network and reads the response — reusing the existing
server-side reconciliation logic. Coding effort: ~25 lines.

**SSL:** `apacheWebServer` serves on HTTPS port 443 with a self-signed certificate.
Use `requests.get(..., verify=False)` — this is a localhost developer tool and strict
cert validation is unnecessary overhead here.

---

### Priority 6 — `search_assets_by_tag` (librarian persona — primary value)

**Rationale:** The central use case for the media librarian persona. Queries `taggings`
+ `tags` + `assets` + `events` with filter params: namespace, tag name, event date range,
participant, untagged-only flag. This is the query that becomes unwieldy in the existing
admin UI at large corpus scale. Coding effort: ~40 lines (parametric WHERE builder).

**Query shape:**
```sql
SELECT a.asset_id, a.source_relpath, a.duration_seconds, a.file_type,
       e.name AS event_name, e.event_date,
       GROUP_CONCAT(DISTINCT CONCAT(t.namespace,':',t.name)) AS tags
FROM assets a
LEFT JOIN event_items ei ON ei.asset_id = a.asset_id
LEFT JOIN events e ON e.event_id = ei.event_id
LEFT JOIN taggings tg ON tg.target_id = a.asset_id AND tg.target_type='asset'
LEFT JOIN tags t ON t.id = tg.tag_id
WHERE (:namespace IS NULL OR t.namespace = :namespace)
  AND (:name IS NULL OR t.name LIKE :name)
  AND (:event_date_from IS NULL OR e.event_date >= :event_date_from)
  AND (:event_date_to IS NULL OR e.event_date <= :event_date_to)
GROUP BY a.asset_id
ORDER BY e.event_date DESC, a.source_relpath
LIMIT :limit
```

---

### Priority 7 — `get_events` with tag coverage stats

**Rationale:** Librarian planning view. Shows each event with asset count, tagged count,
and untagged count — so the librarian can identify which events still need tagging passes.
Coding effort: ~25 lines.

**Query:**
```sql
SELECT e.event_id, e.name, e.event_date, e.org_name,
       COUNT(DISTINCT ei.asset_id) AS asset_count,
       COUNT(DISTINCT tg.target_id) AS tagged_count
FROM events e
LEFT JOIN event_items ei ON ei.event_id = e.event_id
LEFT JOIN taggings tg ON tg.target_id = ei.asset_id AND tg.target_type='asset'
WHERE (:org_name IS NULL OR e.org_name = :org_name)
  AND (:date_from IS NULL OR e.event_date >= :date_from)
  AND (:date_to   IS NULL OR e.event_date <= :date_to)
GROUP BY e.event_id, e.name, e.event_date, e.org_name
ORDER BY e.event_date DESC
```

`untagged_count` is not in the SQL — it is derived in Python as `asset_count - tagged_count`.

---

### Priority 8 — `get_untagged_assets` (assets with zero taggings)

**Rationale:** Pre-existing logic lives in `api/ai_jobs.php` `enqueue_all_untagged` action.
A read-only version is useful before deciding to enqueue. Coding effort: ~15 lines.

**Query** (exact — sourced from `api/ai_jobs.php` `enqueue_all_untagged` action, extended
with `event_name` JOIN to match the Reference table return shape):
```sql
SELECT a.asset_id, a.source_relpath, a.file_type,
       e.name AS event_name
FROM assets a
LEFT JOIN event_items ei ON ei.asset_id = a.asset_id
LEFT JOIN events e ON e.event_id = ei.event_id
WHERE a.file_type = 'video'
  AND a.duration_seconds IS NOT NULL
  AND NOT EXISTS (
    SELECT 1 FROM ai_jobs j
    WHERE j.target_type = 'asset' AND j.target_id = a.asset_id
      AND j.job_type = 'categorize_video'
      AND j.status IN ('queued', 'running', 'done')
  )
LIMIT :limit
```

The `duration_seconds IS NOT NULL` guard excludes stub rows created by `ingestStub()` during
manifest Step 1 — those have no file on disk and would cause immediate worker failures.
Return the row list plus `total_untagged` (Python: `len(rows)`).

---

### Priority 9 — `get_tag_namespace_summary` (tag distribution across corpus)

**Rationale:** Browses the tag namespace/name catalog with usage counts — useful for
understanding what the AI worker has been producing and spotting low-quality tags.
The existing `api/tags.php` already returns this; MCP is a thin wrapper. Coding effort:
~10 lines.

**Query** (exact — sourced from `api/tags.php` tag list / browse endpoint):
```sql
SELECT t.namespace, t.name,
       COUNT(tg.id) AS usage_count
FROM tags t
LEFT JOIN taggings tg ON tg.tag_id = t.id
WHERE (:namespace IS NULL OR t.namespace = :namespace)
GROUP BY t.namespace, t.name
ORDER BY t.namespace, t.name
```

`namespace` is optional; omit to return the full corpus tag distribution.

---

### Priority 10 — `get_container_env_subset(keys[])` (targeted env var inspection)

**Rationale:** From `refactored_ai_worker_parallelism_enable.md` — repeatedly checking
`AI_WORKER_CONCURRENCY`, `AI_CHUNK_CONCURRENCY`, `TUS_CLIENT_PARALLEL_UPLOADS`, etc.
after Ansible deploys. The tool takes an allowlist of safe-to-expose key prefixes
(`AI_`, `TUS_`, `DB_HOST`) and returns their current values — never exposing
`OPENAI_API_KEY`, `MYSQL_PASSWORD`, or other secrets. Coding effort: ~20 lines.

---

## Deferred Items

### Deferred 1 — `source` Column on `ai_jobs`

The `refactored_ai_jobs_messaging.md` identifies a known limitation: no `source` field
on `ai_jobs` to distinguish whether a job was created by `enqueue_all_untagged`,
`retag_all`, auto-enqueue on ingest, or manual enqueue. An MCP tool that answers
_"which jobs were triggered by which action?"_ cannot be built without this column.

Proposed addition (low-migration-cost schema change):
```sql
ALTER TABLE ai_jobs ADD COLUMN source VARCHAR(64) NULL AFTER job_type;
```
Values: `auto_ingest`, `bulk_untagged`, `retag_all`, `manual`, `mcp`.
This is a small but high-value addition for both the admin UI and MCP observability.
Track in a future DB migration. Not a blocker for the initial MCP implementation.

### Deferred 2 — `get_upload_throughput_stats` Tool

**Rationale:** From `refactored_uploads_tus_parallel.md` — measuring upload throughput
(files/min, MB/s) during and after a TUS parallel upload batch currently requires manual
work: noting start/end wall-clock times, watching the browser Network tab per-file timing,
and calculating metrics by hand. This tool would replace that entirely:

```
get_upload_throughput_stats(job_id) → {files_total, files_done, elapsed_seconds,
                                        files_per_min, mb_per_sec, avg_file_size_mb}
```

**Why it can't be built yet — schema gap:** The current schema has no upload session
timing record. `assets` has `file_size_bytes` and a creation timestamp, but no reliable
upload job identifier per asset row and no transfer-time vs. ingestion-time distinction.
What is needed is one of:
- An `upload_sessions` table: `(job_id, start_time, end_time, file_count, total_bytes)`
- Or per-asset upload timing: `upload_started_at`, `upload_completed_at` on `assets`

**Design note:** The simpler option is `upload_sessions` — one row per upload job, written
by the PHP TUS finalization hook when the last file completes. `get_upload_job_state`
already reconciles the job via the PHP endpoint; adding timing to that same record is
a natural extension.

Track as a future schema addition. Pointer: `docs/refactored_uploads_tus_parallel.md`
(Testing section — the manual measurement steps this tool would replace).

---

## Deployment Topology

The MCP server is deployed by Ansible to the **same host that runs Docker** — not the
developer's local machine. In GigHive's inventory structure:

| Inventory | `ansible_host` | Docker runs on |
|-----------|---------------|----------------|
| `gighive2` (dev) | VirtualBox VM at `192.168.1.50` | same VM |
| `gighive` (staging/lab) | VirtualBox VM at `192.168.1.248` | same VM |
| `prod` | Physical machine at `192.168.1.227` | same machine |

The developer's physical machine (`baremetal` / `localhost` in the inventory) is the
Ansible controller only — it is not a deploy target and the MCP server does not run there.

Because the MCP server process runs on the Docker host, it has direct access to:
- MySQL via `localhost` (Docker-exposed port) or the Docker bridge network
- The Apache/PHP container's HTTP port via `localhost`
- The `.env` file on the local filesystem

### Connection flow — protocol and port at each hop

```
Developer (you)
    │  Windsurf IDE — local process, no network
    ▼
Windsurf / Cascade
    │  stdio over SSH — port 22 → gighive-server (~/.ssh/config alias)
    ▼
MCP Python process  ← spawned on-demand on Docker host; exits when session ends
    ├── TCP port 3306 (localhost) ──────────────► mysqlServer
    │   mysql-connector-python, credentials from host .env
    │   used by: tools 1–4, 6–9
    │
    ├── HTTPS port 443 (localhost) ─────────────► apacheWebServer
    │   calls import_manifest_upload_start.php reconciliation endpoint
    │   used by: tool 5 (get_upload_job_state) only
    │
    └── filesystem read (no network) ──────────► host .env file
        used by: tool 10 (get_container_env_subset) only
```

The developer's AI assistant (Windsurf/Cascade, Claude Desktop) connects to the MCP
server via `stdio` over SSH from their local machine to the Docker host:
```json
{"command": "ssh", "args": ["gighive-server", "{{ mcp_server_dir }}/venv/bin/python", "{{ mcp_server_dir }}/server.py"]}
```

---

## Coding Work — Initial Study

### Technology Choice: Python + `mcp` SDK (`FastMCP` API)

**Rationale:** The existing AI worker (`ansible/roles/ai_worker/files/ai-worker/`) is
already Python with `mysql-connector-python`. The `db.py` connection pattern is directly
reusable. The official `mcp` Python SDK (Anthropic) handles the protocol wire format —
and as of `mcp` v1.0+, `FastMCP` (the clean decorator-based API) ships **inside** the
official package as `mcp.server.fastmcp`. No third-party wrapper needed:
```python
from mcp.server.fastmcp import FastMCP
```
`stdio` transport is used — the AI assistant spawns the MCP server process directly via
SSH to the Docker host and communicates over stdin/stdout. No port, no daemon, no Docker
container needed for this use case.

### Implementation: Ansible Role from Day One

Scope: All 10 tools, structured as an Ansible role. The MCP server runs as an on-demand
process on the **server host** (not inside a Docker container) — spawned by the AI
assistant via `stdio` over SSH. Ansible deploys the Python source files and a virtualenv
to the server host. Because it runs on the host, it can reach MySQL and the PHP container
via the Docker bridge network, and can read `.env` directly from the filesystem without
crossing any container boundary. Deployed via `ansible/playbooks/site.yml` after `docker`
and `ai_worker` (if enabled), gated by `mcp_server_enabled: false` in group_vars.

**Ansible implementation pattern:** `ansible.builtin.file` creates the target directory;
`ansible.posix.synchronize` syncs Python source (same `--exclude=__pycache__` pattern as
`ai_worker`); `ansible.builtin.pip` with the `virtualenv` parameter creates the venv if
absent and installs deps — fully idempotent, no reinstall if packages are already
satisfied. No handler is needed: there is no daemon to restart, and `pip` is idempotent.
The Docker-specific parts of the `ai_worker` role (`docker_compose_v2`, Compose template,
restart handler) do not apply here.

All 10 tools register unconditionally when `mcp_server_enabled: true`. The AI pipeline
tools (`get_ai_queue_stats`, `get_failed_jobs`, `get_stale_jobs`, `reset_retryable_jobs`)
query tables that always exist in the schema regardless of `ai_worker_enabled` — they
return empty results if no jobs have been enqueued, which is itself informative. No
`ai_worker_enabled` gating is applied to MCP tool registration.

Implementation size estimate: ~360–460 lines of Python total.

---

## Files That Would Need to Change or Be Added

### New files — Ansible role + Python source

Python source lives under `ansible/roles/mcp_server/files/mcp-server/`, mirroring the
`ai_worker` layout (`ansible/roles/ai_worker/files/ai-worker/`).

| File | Purpose |
|------|---------|
| `ansible/roles/mcp_server/tasks/main.yml` | Create `mcp_server_dir`, sync source via `synchronize`, install deps via `ansible.builtin.pip` + `virtualenv` |
| `ansible/roles/mcp_server/tasks/validate.yml` | Assert venv exists, `server.py` present, `python -c "import mcp"` passes |
| `ansible/roles/mcp_server/files/mcp-server/server.py` | Entry point; registers all 10 tools; `stdio` transport loop |
| `ansible/roles/mcp_server/files/mcp-server/db.py` | DB connection helper (pattern from `ai_worker/files/ai-worker/db.py`) |
| `ansible/roles/mcp_server/files/mcp-server/tools/ai_pipeline.py` | `get_ai_queue_stats`, `get_failed_jobs`, `get_stale_jobs`, `reset_retryable_jobs` |
| `ansible/roles/mcp_server/files/mcp-server/tools/media_library.py` | `search_assets_by_tag`, `get_events`, `get_untagged_assets`, `get_tag_namespace_summary` |
| `ansible/roles/mcp_server/files/mcp-server/tools/upload_jobs.py` | `get_upload_job_state` |
| `ansible/roles/mcp_server/files/mcp-server/tools/system.py` | `get_container_env_subset` |
| `ansible/roles/mcp_server/files/mcp-server/requirements.txt` | `mcp`, `mysql-connector-python`, `requests` |
| `ansible/roles/mcp_server/templates/README.md.j2` | Templated setup instructions — resolves `{{ mcp_server_dir }}` and `{{ ansible_host }}` at deploy time; how to register with Claude Desktop / Windsurf |

### Existing files that would need changes

| File | Change |
|------|--------|
| `ansible/inventories/group_vars/gighive2/gighive2.yml`, `gighive/gighive.yml`, `prod/prod.yml` | Add `mcp_server_enabled: false`, `mcp_server_dir: "{{ gighive_home }}/mcp-server"`, and `mcp_env_file: "{{ gighive_home }}/ansible/roles/docker/files/apache/externalConfigs/.env"` to all three |
| `ansible/playbooks/site.yml` | Add `mcp_server` role after `ai_worker`, conditional on `mcp_server_enabled` |
| `ansible/roles/docker/files/mysql/externalConfigs/create_music_db.sql` | Add `source VARCHAR(64)` to `ai_jobs` table (deferred — see above) |
| `docs/feature_mcp_server_beneficial.md` | This document |

### Existing files whose APIs the MCP server will call (no changes needed)

These are read — the MCP server connects to MySQL directly, not via the PHP API layer.
The PHP endpoints listed here are noted as reference for query parity:

| File | MCP tool that mirrors it |
|------|------------------------|
| `api/ai_jobs.php?action=status_counts` | `get_ai_queue_stats` |
| `api/ai_jobs.php?status=failed` | `get_failed_jobs` |
| `api/ai_jobs.php?action=enqueue_all_untagged` (read side) | `get_untagged_assets` |
| `api/tags.php` | `get_tag_namespace_summary`, `search_assets_by_tag` |

The MCP server connects directly to MySQL for performance and to avoid HTTP auth
overhead in a local developer context. It uses the same `DB_HOST`, `MYSQL_DATABASE`,
`MYSQL_USER`, `MYSQL_PASSWORD` env vars as the existing containers.

---

## Prerequisites Status (2026-05-29)

A codebase audit confirmed the following. **All three prerequisites are cleared — MCP implementation can begin now.**

| Item | Status | Evidence |
|------|--------|----------|
| PR3 listing cutover (canonical schema live) | ✅ Done | `db/database.php` wires `MediaController` + `AssetRepository` + `EventRepository`; `resolveView()` live |
| Tag filter in `AssetRepository` | ✅ Done | `buildLibrarianFilters()` has full `EXISTS (SELECT 1 FROM taggings ...)` with AND/OR/NOT boolean parsing |
| Event metadata duplication stop-gap | ✅ Superseded | Full Event/Asset hard cutover (`pr_librarianAsset_musicianEvent_completed_implementation.md`) made the legacy `sessions`-targeted stop-gap moot |

### One remaining data quality note for MCP P7 (`get_events`)

The canonical `events` table still uses `UNIQUE(event_date, org_name)` as its identity constraint — no `event_key` UUID column. This is the same mutable-metadata identity risk that the stop-gap addressed for `sessions`, now living on `events`. Concretely: if an admin edits `org_name` on an event row and later re-uploads, the importer will look up `(event_date, new_org_name)`, find no match, and create a second event row.

For the developer tools (P1–P5) this is informational only — they don't query `events`. For the librarian tools (P6–P9) this is worth a follow-up schema migration:
```sql
ALTER TABLE events ADD COLUMN event_key CHAR(36) NOT NULL DEFAULT (UUID()) AFTER event_id,
                   ADD CONSTRAINT uq_events_key UNIQUE (event_key);
```

Track as a future DB migration alongside the existing `ai_jobs.source` column proposal.

---

## Recommended Starting Point

**Build the `mcp_server` Ansible role directly** — no standalone prototype phase. The
`db.py` connection pattern is already written in `ai_worker` and can be copied verbatim.
All 10 tool queries are documented above. The role is gated by `mcp_server_enabled: false`
in group_vars so it has no impact on existing deployments until explicitly enabled.

`site.yml` ordering: `docker` → `ai_worker` (if enabled) → `mcp_server` (if enabled) →
`post_build_checks`. This ensures the full DB schema is in place before Ansible deploys
the MCP server files to the host.

Registration in Windsurf/Cascade or Claude Desktop is a single config entry:
```json
{"command": "ssh", "args": ["gighive-server", "{{ mcp_server_dir }}/venv/bin/python", "{{ mcp_server_dir }}/server.py"]}
```
The process is spawned on-demand by the AI assistant and exits when the session ends.

**Future phase (deferred):** If an always-on AI service consumer emerges, change transport
to SSE/HTTP, add a Docker service via `docker-compose-mcp.yml.j2`, and expose on a
local-only port. Do not implement speculatively.

**One-shot bundle:** `mcp_server` is excluded from the one-shot bundle — the same decision
as `ai_worker`. The one-shot bundle targets end users running a quick GigHive install; the
MCP server is a developer/operator tool with no relevance to that audience. Full Ansible
build only, gated by `mcp_server_enabled: false`.

---

## Implementation Checklist

- [ ] Create `ansible/roles/mcp_server/tasks/main.yml` — `file` for dir, `synchronize` for source, `pip` + `virtualenv` for deps (no handler needed)
- [ ] Create `ansible/roles/mcp_server/tasks/validate.yml` — stat venv + `server.py`, verify `import mcp` passes; assert Python ≥ 3.10 (confirmed 3.12.3 on gighive2)
- [ ] Write `files/mcp-server/db.py` — DB connection helper (copy pattern from `ai_worker/files/ai-worker/db.py`)
- [ ] Write `files/mcp-server/tools/ai_pipeline.py` — `get_ai_queue_stats`, `get_failed_jobs`, `get_stale_jobs`, `reset_retryable_jobs`
- [ ] Write `files/mcp-server/tools/media_library.py` — `search_assets_by_tag`, `get_events`, `get_untagged_assets`, `get_tag_namespace_summary`
- [ ] Write `files/mcp-server/tools/upload_jobs.py` — `get_upload_job_state` (calls PHP reconciliation endpoint via Docker bridge)
- [ ] Write `files/mcp-server/tools/system.py` — `get_container_env_subset` (reads host `.env` directly)
- [ ] Write `files/mcp-server/server.py` — entry point, registers all 10 tools, `stdio` transport
- [ ] Add `mcp_server_enabled: false`, `mcp_server_dir`, and `mcp_env_file` to `group_vars/gighive2/gighive2.yml`, `gighive/gighive.yml`, `prod/prod.yml`; wire role into `site.yml` after `ai_worker`
- [ ] Write `templates/README.md.j2` — templated SSH config entry; resolves `{{ mcp_server_dir }}` and `{{ ansible_host }}` at deploy time

---

## Related Docs

- `docs/refactored_tus_retry_delays_and_frame_extractor.md` — ffmpeg error capture bugs discovered via manual `ai_jobs` inspection
- `docs/refactored_ai_worker_parallelism_enable.md` — CPU ceiling discovery via manual `docker stats` + `sar`
- `docs/refactored_upload_folder_messaging_server_monotonic_fix.md` — inflated pending count debugging
- `docs/refactored_uploads_tus_parallel.md` — TUS parallel upload tuning
- `docs/refactored_ai_jobs_messaging.md` — 500-row cap bug and `status_counts` aggregate fix
- `docs/feature_ai_intelligence_platform.md` — canonical AI platform schema and helper registry
- `docs/feature_ai_video_tagger.md` — `video_tagger_v1` full spec
- `docs/API_CURRENT_STATE.md` — existing REST API surface
- `docs/pr_librarianAsset_musicianEvent_completed.md` — Event/Asset hard cutover PRD (completed)
- `docs/pr_librarianAsset_musicianEvent_completed_implementation.md` — Event/Asset cutover implementation plan (completed)
- `docs/refactor_db_fix_event_metadata_duplication_completed.md` — event_key stop-gap (superseded by full cutover)

---

## GigHive MCP Tools Reference

**"Function" is the correct term.** In MCP, each tool is registered with a `name` and called by the AI assistant exactly like a function — the assistant passes typed arguments and receives a structured response. The table below formalizes the ten priority tools with consistent naming, their inputs, and what they return.

All tool names in the Priority Summary and section headings above now use the canonical names defined here.

| # | Tool (function name) | Description | Inputs | Returns |
|---|---------------------|-------------|--------|---------|
| 1 | `get_ai_queue_stats` | Aggregate queue state by status | `job_type?: str = "categorize_video"` | `{queued, running, done, failed, total}` |
| 2 | `get_failed_jobs` | Failed jobs with asset paths and grouped error patterns | `job_type?: str = "categorize_video"`, `limit?: int = 100` | `[{id, updated_at, error_msg, attempts, source_relpath, file_type}]` + error group summary |
| 3 | `get_stale_jobs` | Jobs stuck in `running` longer than N minutes (orphan detection) | `minutes?: int = 30` | `[{id, locked_by, locked_at, attempts, source_relpath}]` |
| 4 | `reset_retryable_jobs` | Re-queue failed jobs, excluding permanent-failure patterns | `exclude_patterns?: [str]` (default: `["VOB", ".m2v"]`), `dry_run?: bool = true` | `{rows_reset, excluded, dry_run}` |
| 5 | `get_upload_job_state` | Reconcile `upload_status.json` against DB for a given job | `job_id: str` | `{pending, done, already_present, failed, discrepancy_count}` |
| 6 | `search_assets_by_tag` | Tag-filtered asset search across the corpus | `namespace?: str`, `tag_name?: str`, `event_date_from?: str`, `event_date_to?: str`, `limit?: int = 50` | `[{asset_id, source_relpath, duration_seconds, event_name, event_date, tags}]` |
| 7 | `get_events` | Events list with per-event asset count and tag coverage | `org_name?: str`, `date_from?: str`, `date_to?: str` | `[{event_id, name, event_date, org_name, asset_count, tagged_count, untagged_count}]` |
| 8 | `get_untagged_assets` | Assets with zero confirmed taggings | `limit?: int = 100` | `[{asset_id, source_relpath, file_type, event_name}]` + `{total_untagged}` |
| 9 | `get_tag_namespace_summary` | Tag distribution across corpus grouped by namespace | `namespace?: str` | `[{namespace, name, usage_count}]` |
| 10 | `get_container_env_subset` | Read safe env vars from the host `.env` file (secrets never exposed) | `keys: [str]` (must match allowed prefixes: `AI_`, `TUS_`, `DB_HOST`) | `{key: value, ...}` |

### Naming convention

- **`get_`** — read-only query; no side effects (tools 1–3, 5–10)
- **`search_`** — read-only filtered query with multiple optional parameters (tool 6)
- **`reset_`** — the one write tool; defaults to `dry_run=true` to require explicit confirmation (tool 4)

### Module grouping (mirrors the `tools/` file structure)

| Module | Tools |
|--------|-------|
| `tools/ai_pipeline.py` | `get_ai_queue_stats`, `get_failed_jobs`, `get_stale_jobs`, `reset_retryable_jobs` |
| `tools/media_library.py` | `search_assets_by_tag`, `get_events`, `get_untagged_assets`, `get_tag_namespace_summary` |
| `tools/upload_jobs.py` | `get_upload_job_state` |
| `tools/system.py` | `get_container_env_subset` |

`get_container_env_subset` lives in a new `tools/system.py` rather than `ai_pipeline.py` because it reads the host `.env` file rather than the database.

---

## Implementation Details — Ansible Source / Destinations

The Python source files live in the repo under the `mcp_server` Ansible role and are
synced to the Docker host (not into any container) by `ansible.builtin.synchronize`.

### Repo source (version-controlled)

```
ansible/roles/mcp_server/
    tasks/main.yml              ← creates mcp_server_dir, syncs source, pip installs deps
    tasks/validate.yml          ← verifies venv + server.py present
    templates/README.md.j2      ← rendered with resolved paths at deploy time
    files/mcp-server/           ← synced verbatim to {{ mcp_server_dir }} on the host
        server.py
        db.py
        requirements.txt
        tools/
            ai_pipeline.py
            media_library.py
            upload_jobs.py
            system.py
```

### Deployed layout on the Docker host (runtime)

`{{ mcp_server_dir }}` resolves to `{{ gighive_home }}/mcp-server`
(e.g. `~/gighive/mcp-server`):

```
~/gighive/mcp-server/           ← {{ mcp_server_dir }}
    server.py
    db.py
    requirements.txt
    README.md                   ← rendered from templates/README.md.j2
    venv/                       ← created by ansible.builtin.pip + virtualenv parameter
        bin/python              ← the binary Windsurf SSH-spawns on-demand
    tools/
        ai_pipeline.py
        media_library.py
        upload_jobs.py
        system.py
```

The `venv/` directory is created idempotently by `ansible.builtin.pip` — if the packages
in `requirements.txt` are already satisfied, nothing is reinstalled. No daemon runs;
no handler is needed. The process is spawned on-demand by the AI assistant via SSH and
exits when the session ends.

---

## Registration Guide — Connecting an AI Assistant to the MCP Server

After the `mcp_server` Ansible role has been deployed, four steps are required to connect
Windsurf/Cascade or Claude Desktop on your local machine to the MCP server on the target host.

### Step 1 — Deploy via Ansible

Set `mcp_server_enabled: true` in the target environment's group_vars
(e.g. `ansible/inventories/group_vars/gighive2/gighive2.yml`), then run:

```bash
ansible-playbook -i ansible/inventories/inventory_gighive2.yml ansible/playbooks/site.yml
```

This creates the virtualenv, installs deps from `requirements.txt`, and renders
`README.md` on the target host with the resolved `mcp_server_dir` path.

### Step 2 — Set up SSH alias on your local machine

Add to `~/.ssh/config` on your local machine (the Ansible controller / baremetal host):

```
Host gighive-server
    HostName 192.168.1.50
    User ubuntu
    IdentityFile ~/.ssh/your_key
```

Use the correct IP for your target environment (see Deployment Topology table above).
Verify with: `ssh gighive-server echo ok`

### Step 3 — Read the deployed README for the resolved config entry

The `README.md.j2` template is rendered at deploy time with the correct `mcp_server_dir`
value. Read it from the target to get the exact paths to copy:

```bash
ssh gighive-server cat ~/gighive/mcp-server/README.md
```

### Step 4 — Register in Windsurf/Cascade or Claude Desktop

**Windsurf/Cascade** — add via Settings → MCP Servers, or directly in the Windsurf MCP
config JSON:

```json
{
  "mcpServers": {
    "gighive": {
      "command": "ssh",
      "args": ["gighive-server", "{{ mcp_server_dir }}/venv/bin/python", "{{ mcp_server_dir }}/server.py"]
    }
  }
}
```

**Claude Desktop (Linux)** — add the same `"mcpServers"` block to
`~/.config/Claude/claude_desktop_config.json`.

Replace `{{ mcp_server_dir }}` with the resolved path from the README in Step 3.

The MCP server process is spawned on-demand when the AI assistant session starts and exits
when the session ends. No persistent daemon runs between sessions.

---

## Appendix — FastMCP Registration Process

There are three distinct registration events that happen at different times. Understanding
them clarifies what each piece of code does and why.

### Registration A — Tools register with the `FastMCP` instance (at Python import time)

Inside `server.py`, each tool function is decorated with `@mcp.tool()`. This wires the
callable, its name, its docstring (used as the tool description), and its input schema
(derived from Python type hints → JSON Schema) into the `FastMCP` instance:

```python
from mcp.server.fastmcp import FastMCP

mcp = FastMCP("gighive")

@mcp.tool()
def get_ai_queue_stats(job_type: str = "categorize_video") -> dict:
    """Aggregate queue state by status."""
    ...
```

This happens at process startup when `server.py` is imported. All 10 tools register
unconditionally — no runtime gating.

### Registration B — Server advertises its tools to the AI client (at session start)

When Windsurf SSH-spawns `server.py`, the MCP protocol performs an initialization
handshake over `stdin`/`stdout`. The server responds to `tools/list` with the full
catalog of all registered tools — their names, descriptions, and JSON input schemas.
From that point the AI assistant's LLM knows what tools are available and can call
any of them by name, passing typed arguments as JSON.

This handshake is automatic — `FastMCP` handles it. No code needed beyond the `@mcp.tool()`
decorators.

### Registration C — You tell Windsurf where to find the server (one-time config)

This is the step in the Registration Guide (Step 4). It does not register tools — it
tells Windsurf: *"when you need GigHive tools, spawn this SSH command."* Windsurf stores
this entry and uses it to start the process on-demand.

```json
{
  "mcpServers": {
    "gighive": {
      "command": "ssh",
      "args": ["gighive-server", "/resolved/mcp_server_dir/venv/bin/python",
               "/resolved/mcp_server_dir/server.py"]
    }
  }
}
```

### Summary — when each registration happens

| Registration | When | Who does it |
|---|---|---|
| A — tools → `FastMCP` instance | Python import time (`server.py` loads) | `@mcp.tool()` decorators |
| B — server → AI client tool catalog | Each session start (SSH spawn + handshake) | `FastMCP` protocol handler (automatic) |
| C — server location → Windsurf config | One-time manual step | You (Registration Guide Step 4) |
