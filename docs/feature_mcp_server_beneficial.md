# Feature: GigHive MCP Server — Analysis, Prioritisation, and Implementation Plan

Date: 2026-05-26  
Status: Draft / Design  
Origin: Conversation-driven discovery (2026-05-26 design session)

---

## Background

This document captures the analysis from a design session exploring whether GigHive would
benefit from an MCP (Model Context Protocol) server. MCP is an open protocol (originated by
Anthropic) that standardises how AI assistants (Claude Desktop, Windsurf/Cascade, etc.)
connect to external data sources and tools. An MCP server exposes **tools** (callable
actions), **resources** (readable data), and optionally **prompts** (pre-built templates).

The discussion was prompted by a weekend of debugging that produced four refactor docs —
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
| `refactored_ai_worker_parallelism_enable.md` | Iterated `AI_WORKER_CONCURRENCY` by running `docker stats`, `sar -u 1 3`, `vmstat`, live `docker logs -f` to find the 3-thread CPU ceiling on a 4-core host; 818-job queue | `ai_queue_stats` + `get_container_stats` → single tool response with queue depth and resource snapshot |
| `refactored_upload_folder_messaging_server_monotonic_fix.md` | Debugged inflated pending count (77 → 175 files after Restart Upload) by cross-referencing `upload_status.json` on disk with the `assets` table; required manual `docker exec` into both containers | `get_upload_job_state(job_id)` → `{pending, done, already_present, failed}` counts reconciled against DB |
| `refactored_uploads_tus_parallel.md` | Measured baseline vs. parallel upload performance manually; tuned `tus_client_parallel_uploads`; verified via browser Network tab per-file timing | `get_upload_throughput_stats(job_id)` → files/min, MB/s derived from DB timestamps |
| `refactored_ai_jobs_messaging.md` | Discovered the 500-row cap bug (UI polling fetched rows, silently capped at 500, so 818-job queue showed wrong counts); "complete" counter stuck at 0 on resume | `ai_queue_stats` (aggregate `GROUP BY status`) is correct _by construction_ — cannot hit a row cap |

The fifth case (`refactored_ai_jobs_messaging.md`) is the strongest: the existing admin UI
was architecturally wrong for large queues because it fetched rows and counted client-side.
The fix was a new server-side `?action=status_counts` aggregate endpoint in `api/ai_jobs.php`.
An MCP `ai_queue_stats` tool would have been correct from the start, and would have exposed
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

## MCP Tools — Priority Summary

| # | Tool | Effort | Primary driver | Query |
|---|------|--------|----------------|-------|
| 1 | `ai_queue_stats` | ~15 lines | `refactored_ai_jobs_messaging.md` — 500-cap bug | exact |
| 2 | `get_failed_jobs` | ~20 lines | `refactored_tus_retry_delays_and_frame_extractor.md` — 5,800-file run | exact |
| 3 | `get_stale_jobs` | ~10 lines | `refactored_ai_worker_parallelism_enable.md` — orphaned lock detection | needs validation |
| 4 | `reset_retryable_jobs` | ~20 lines | `refactored_tus_retry_delays_and_frame_extractor.md` — post-fix re-queue | exact |
| 5 | `get_upload_job_state` | ~25 lines | `refactored_upload_folder_messaging_server_monotonic_fix.md` — inflated pending count | needs validation |
| 6 | `search_assets_by_tag` | ~40 lines | Media librarian corpus search | needs validation |
| 7 | `list_events` with coverage stats | ~25 lines | Media librarian planning | needs validation |
| 8 | `get_untagged_assets` | ~15 lines | `api/ai_jobs.php` — pre-enqueue audit | exact |
| 9 | `get_tag_namespace_summary` | ~10 lines | `api/tags.php` — corpus quality review | exact |
| 10 | `get_container_env_subset` | ~20 lines | `refactored_ai_worker_parallelism_enable.md` — env var inspection | needs validation |

**Query legend:**
- **exact** — query sourced directly from a source `.md` refactor doc or existing PHP API file read during this analysis
- **needs validation** — query constructed from schema knowledge; should be tested against a live instance before shipping

---

## Priority-Ordered List of MCP Wins

### Priority 1 — `ai_queue_stats` (aggregate queue state)

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
ORDER BY j.updated_at DESC
```

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

**Implementation note:** This tool needs filesystem access inside the Apache container,
not just DB access. Either: (a) MCP server runs inside the container, or (b) the tool
calls the existing `import_manifest_upload_start.php` reconciliation endpoint and reads
the response. Option (b) is cleaner — it reuses the existing server-side reconciliation
logic. Coding effort: ~25 lines.

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
WHERE t.namespace = :namespace AND t.name LIKE :name
  AND (:event_date_from IS NULL OR e.event_date >= :event_date_from)
GROUP BY a.asset_id
ORDER BY e.event_date DESC, a.source_relpath
LIMIT :limit
```

---

### Priority 7 — `list_events` with tag coverage stats

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
GROUP BY e.event_id, e.name, e.event_date, e.org_name
ORDER BY e.event_date DESC
```

---

### Priority 8 — `get_untagged_assets` (assets with zero taggings)

**Rationale:** Pre-existing logic lives in `api/ai_jobs.php` `enqueue_all_untagged` action.
A read-only version is useful before deciding to enqueue. Coding effort: ~15 lines.

---

### Priority 9 — `get_tag_namespace_summary` (tag distribution across corpus)

**Rationale:** Browses the tag namespace/name catalog with usage counts — useful for
understanding what the AI worker has been producing and spotting low-quality tags.
The existing `api/tags.php` already returns this; MCP is a thin wrapper. Coding effort:
~10 lines.

---

### Priority 10 — `get_container_env_subset(keys[])` (targeted env var inspection)

**Rationale:** From `refactored_ai_worker_parallelism_enable.md` — repeatedly checking
`AI_WORKER_CONCURRENCY`, `AI_CHUNK_CONCURRENCY`, `TUS_CLIENT_PARALLEL_UPLOADS`, etc.
after Ansible deploys. The tool takes an allowlist of safe-to-expose key prefixes
(`AI_`, `TUS_`, `DB_HOST`) and returns their current values — never exposing
`OPENAI_API_KEY`, `MYSQL_PASSWORD`, or other secrets. Coding effort: ~20 lines.

---

## Deferred Item — `source` Column on `ai_jobs`

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
Track in a future DB migration. Not a blocker for Phase 1 MCP work.

---

## Coding Work — Initial Study

### Technology Choice: Python + `fastmcp` or `mcp` SDK

**Rationale:** The existing AI worker (`ansible/roles/ai_worker/files/ai-worker/`) is
already Python with `mysql-connector-python`. The `db.py` connection pattern is directly
reusable. The `mcp` Python SDK (Anthropic) or `fastmcp` (simpler wrapper) handles the
protocol wire format. `stdio` transport is the simplest — the MCP server process is
launched by the AI assistant and communicates on stdin/stdout.

### Phase 1: Standalone Developer MCP (~1–2 days)

Scope: Read-only tools for developer diagnostics. No Ansible role, no Docker.
Runs locally on the developer machine (or staging VM) with credentials from `.env`.

Tools: `ai_queue_stats`, `get_failed_jobs`, `get_stale_jobs`, `list_events`,
`search_assets_by_tag`, `get_untagged_assets`, `get_tag_namespace_summary`

Implementation size estimate: ~300–400 lines of Python total.

### Phase 2: Write Tool + Upload State (~1 day)

Scope: Add `reset_retryable_jobs` (with confirmation), `get_upload_job_state`.

Additional: ~60 lines of Python.

### Phase 3: Ansible / Docker Integration (~1 day)

Scope: Package as an Ansible role + Docker service for librarian use on deployed instances.
Add `mcp_server_enabled` group var. Expose via a local port (not publicly accessible).

---

## Files That Would Need to Change or Be Added

### New files — MCP server (Phase 1 standalone)

| File | Purpose |
|------|---------|
| `mcp-server/server.py` | Entry point; registers all tools; `stdio` transport loop |
| `mcp-server/db.py` | Read-only DB connection helper (reused from `ai_worker/files/ai-worker/db.py` pattern) |
| `mcp-server/tools/ai_pipeline.py` | `ai_queue_stats`, `get_failed_jobs`, `get_stale_jobs`, `reset_retryable_jobs` |
| `mcp-server/tools/media_library.py` | `search_assets_by_tag`, `list_events`, `get_untagged_assets`, `get_tag_namespace_summary` |
| `mcp-server/tools/upload_jobs.py` | `get_upload_job_state` |
| `mcp-server/requirements.txt` | `mcp` or `fastmcp`, `mysql-connector-python` |
| `mcp-server/README.md` | Setup instructions — how to point at `.env`, how to register with Claude Desktop / Windsurf |

Suggested root location: `mcp-server/` at repo root (parallel to `gighive-one-shot-bundle/`),
since it is developer tooling, not deployed application code.

### New files — Ansible role (Phase 3 only)

| File | Purpose |
|------|---------|
| `ansible/roles/mcp_server/tasks/main.yml` | Install Python deps, copy files, start service |
| `ansible/roles/mcp_server/templates/docker-compose-mcp.yml.j2` | Optional Docker service definition |
| `ansible/roles/mcp_server/handlers/main.yml` | Restart handler |

### Existing files that would need changes

| File | Change | Phase |
|------|--------|-------|
| `ansible/inventories/group_vars/gighive2/gighive2.yml` | Add `mcp_server_enabled: false` (default off) | Phase 3 |
| `ansible/playbooks/site.yml` | Add `mcp_server` role (conditional on `mcp_server_enabled`) | Phase 3 |
| `ansible/roles/docker/templates/.env.j2` | Add `MCP_SERVER_ENABLED` env var if needed | Phase 3 |
| `ansible/roles/docker/files/mysql/externalConfigs/create_music_db.sql` | Add `source VARCHAR(64)` to `ai_jobs` table (deferred — see above) | Future |
| `docs/feature_mcp_server_beneficial.md` | This document | Now |

### Existing files whose APIs the MCP server will call (no changes needed)

These are read — the MCP server connects to MySQL directly, not via the PHP API layer.
The PHP endpoints listed here are noted as reference for query parity:

| File | MCP tool that mirrors it |
|------|------------------------|
| `api/ai_jobs.php?action=status_counts` | `ai_queue_stats` |
| `api/ai_jobs.php?status=failed` | `get_failed_jobs` |
| `api/ai_jobs.php?action=enqueue_all_untagged` (read side) | `get_untagged_assets` |
| `api/tags.php` | `get_tag_namespace_summary`, `search_assets_by_tag` |

The MCP server connects directly to MySQL for performance and to avoid HTTP auth
overhead in a local developer context. It uses the same `DB_HOST`, `MYSQL_DATABASE`,
`MYSQL_USER`, `MYSQL_PASSWORD` env vars as the existing containers.

---

## Prerequisites Status (2026-05-29)

A codebase audit confirmed the following. **All three prerequisites are cleared — MCP Phase 1 can be built now.**

| Item | Status | Evidence |
|------|--------|----------|
| PR3 listing cutover (canonical schema live) | ✅ Done | `db/database.php` wires `MediaController` + `AssetRepository` + `EventRepository`; `resolveView()` live |
| Tag filter in `AssetRepository` | ✅ Done | `buildLibrarianFilters()` has full `EXISTS (SELECT 1 FROM taggings ...)` with AND/OR/NOT boolean parsing |
| Event metadata duplication stop-gap | ✅ Superseded | Full Event/Asset hard cutover (`pr_librarianAsset_musicianEvent_completed_implementation.md`) made the legacy `sessions`-targeted stop-gap moot |

### One remaining data quality note for MCP P7 (`list_events`)

The canonical `events` table still uses `UNIQUE(event_date, org_name)` as its identity constraint — no `event_key` UUID column. This is the same mutable-metadata identity risk that the stop-gap addressed for `sessions`, now living on `events`. Concretely: if an admin edits `org_name` on an event row and later re-uploads, the importer will look up `(event_date, new_org_name)`, find no match, and create a second event row.

For MCP Phase 1 this is informational only — the developer tools (P1–P5) don't query `events`. For the librarian tools (P6–P9) this is worth a follow-up schema migration:
```sql
ALTER TABLE events ADD COLUMN event_key CHAR(36) NOT NULL DEFAULT (UUID()) AFTER event_id,
                   ADD CONSTRAINT uq_events_key UNIQUE (event_key);
```

Track as a future DB migration alongside the existing `ai_jobs.source` column proposal.

---

## Recommended Starting Point

**Build Phase 1 first.** A single `mcp-server/server.py` (~300 lines, no Ansible needed)
with the top-5 priority tools (`ai_queue_stats`, `get_failed_jobs`, `get_stale_jobs`,
`list_events`, `search_assets_by_tag`) would have eliminated the majority of manual
terminal work from the weekend debugging sessions. The `db.py` connection pattern is
already written in the AI worker and can be copied verbatim. The queries for all five
tools are already documented above.

Registration in Windsurf/Cascade or Claude Desktop is a single config line pointing
at the script with the appropriate env vars.

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

Two names from the Priority Summary above are adjusted for consistency: `ai_queue_stats` → `get_ai_queue_stats`, `list_events` → `get_events`.

| # | Tool (function name) | Description | Inputs | Returns |
|---|---------------------|-------------|--------|---------|
| 1 | `get_ai_queue_stats` | Aggregate queue state by status | `job_type?: str = "categorize_video"` | `{queued, running, done, failed, total}` |
| 2 | `get_failed_jobs` | Failed jobs with asset paths and grouped error patterns | `limit?: int = 100` | `[{id, updated_at, error_msg, attempts, source_relpath, file_type}]` + error group summary |
| 3 | `get_stale_jobs` | Jobs stuck in `running` longer than N minutes (orphan detection) | `minutes?: int = 30` | `[{id, locked_by, locked_at, attempts, source_relpath}]` |
| 4 | `reset_retryable_jobs` | Re-queue failed jobs, excluding permanent-failure patterns | `exclude_patterns?: [str]` (default: `["VOB", ".m2v"]`), `dry_run?: bool = true` | `{rows_reset, excluded, dry_run}` |
| 5 | `get_upload_job_state` | Reconcile `upload_status.json` against DB for a given job | `job_id: str` | `{pending, done, already_present, failed, discrepancy_count}` |
| 6 | `search_assets_by_tag` | Tag-filtered asset search across the corpus | `namespace?: str`, `tag_name?: str`, `event_date_from?: str`, `event_date_to?: str`, `limit?: int = 50` | `[{asset_id, source_relpath, duration_seconds, event_name, event_date, tags}]` |
| 7 | `get_events` | Events list with per-event asset count and tag coverage | `org_name?: str`, `date_from?: str`, `date_to?: str` | `[{event_id, name, event_date, org_name, asset_count, tagged_count, untagged_count}]` |
| 8 | `get_untagged_assets` | Assets with zero confirmed taggings | `limit?: int = 100` | `[{asset_id, source_relpath, file_type, event_name}]` + `{total_untagged}` |
| 9 | `get_tag_namespace_summary` | Tag distribution across corpus grouped by namespace | `namespace?: str` | `[{namespace, name, usage_count}]` |
| 10 | `get_container_env_subset` | Read safe env vars from the running container (secrets never exposed) | `keys: [str]` (must match allowed prefixes: `AI_`, `TUS_`, `DB_HOST`) | `{key: value, ...}` |

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

`get_container_env_subset` lives in a new `tools/system.py` rather than `ai_pipeline.py` because it queries the container runtime rather than the database.
