# Feature: AI Video Tagger (`llm_categorize_v1`)

Date: 2026-04-25  
Status: Draft / Design  
Parent framework: [GigHive Intelligence Platform Framework](feature_ai_intelligence_platform.md)

---

## Overview

Once a user uploads a video to GigHive, the AI Video Tagger automatically analyzes the video content using a multimodal LLM and applies structured tags — people, objects, places, activities — without any manual effort. This is the first LLM-driven helper built on top of the GigHive Intelligence Platform framework.

---

## Why This First?

Tags derived from video content are the most broadly useful AI output GigHive can produce:

- Power search and filtering across events/videos immediately
- No human-in-the-loop required for an initial pass (unlike face labeling)
- Demonstrates end-to-end AI pipeline value quickly
- Results feed directly into the existing `tags`/`taggings` schema — no new framework tables needed

---

## How It Works (High Level)

When a video is uploaded and registered in `media_files`, the system enqueues an `llm_categorize_v1` job. An AI worker picks it up and runs `ffmpeg` to extract a set of representative frames — at regular intervals of one frame every 5 seconds (scene-change detection is a v2 option). These frames are encoded as base64 images and bundled into a prompt that instructs the multimodal LLM to return structured JSON describing what it observes: the types of people present (musician on stage, audience member, officiant, wedding party), physical objects (guitar, drum kit, microphone, crowd, stage lighting, flowers, cake), setting/environment (concert venue, outdoor festival, indoor reception hall, recording studio), and notable activities (performing, dancing, speaking). The LLM's JSON response is parsed and normalized into tag namespaces — `scene`, `object`, `activity`, `person_role` — and written into the existing `tags`/`taggings` tables with `source='ai'` and a `confidence` score.

From the user's perspective, videos automatically acquire searchable tags without any manual effort. An event might be tagged `scene:outdoor_stage`, `object:drum_kit`, `activity:live_performance`, `person_role:audience` within minutes of upload. These tags immediately power search and filtering in the admin UI — find all videos where a guitar is visible, or all wedding reception clips, or all concert footage with a large crowd. Because every tagging row carries a `run_id` back to `helper_runs`, results are fully auditable and re-runnable if you switch models or tune prompts.

The architecture cleanly separates the LLM provider from the rest of the system: the worker calls an adapter interface (`LLMVisionAdapter`) that accepts frames + prompt and returns parsed tags. Swapping from GPT-4o to Gemini or a self-hosted model requires only a new adapter implementation — no changes to the job queue, tagging schema, or UI.

---

## Tag Namespaces

| Namespace | Example values |
|-----------|----------------|
| `scene` | `outdoor_stage`, `indoor_reception`, `recording_studio`, `rehearsal_space` |
| `object` | `guitar`, `drum_kit`, `microphone`, `wedding_cake`, `stage_lighting`, `crowd` |
| `activity` | `live_performance`, `dancing`, `speech`, `soundcheck` |
| `person_role` | `musician`, `audience`, `officiant`, `wedding_party` |

These namespaces are open — the LLM prompt and normalization layer can be extended to add new ones without schema changes.

---

## Implementation Plan

| Phase | Scope |
|-------|-------|
| **1 — Frame extractor** | `ffmpeg` task in the worker samples N frames per video (configurable interval or scene-change); stores frame paths in `derived_assets` with type `sampled_frame` |
| **2 — LLM adapter layer** | Abstract `LLMVisionAdapter` interface + first concrete implementation (GPT-4o or Gemini 1.5 Pro); returns `[{namespace, name, confidence, start_seconds, end_seconds}]` |
| **3 — `llm_categorize_v1` helper** | Orchestrates frame extractor → LLM call → tag normalization → writes to `tags`/`taggings`; registered in helper registry with `helper_id=llm_categorize_v1` |
| **4 — Job wiring** | Trigger `llm_categorize_v1` job automatically on `media_file` registration (or on-demand via admin UI / `POST /api/v1/ai/jobs`) |
| **5 — Tag browsing UI** | Admin view: per-video tag list with confidence scores; per-tag view listing all matching videos |
| **6 — Search integration** | Filter events/videos by tag namespace+name in existing search/query surfaces |
| **7 — Human review layer** | Admin can confirm, reject, or edit AI-generated tags; `source` field flips to `'human'` on override; `run_id` preserved for provenance |

---

## MCP Server as Provider Conduit

An MCP server (`gighive-ai-mcp`) is the recommended integration layer between GigHive's AI worker and external LLM providers. It exposes GigHive-specific tools as MCP tool definitions:

- `analyze_video_frames` — accepts a set of frame images + prompt, returns structured tag JSON
- `get_media_tags` — returns current tags for a given `media_file_id`
- `enqueue_categorization_job` — triggers `llm_categorize_v1` for a given media file

**Benefits of the MCP approach:**

- **Provider agnosticism** — the MCP server owns the adapter layer; switching from GPT-4o to Gemini or a self-hosted model requires only a new adapter in the MCP server, not changes to GigHive worker code
- **Agentic extensibility** — an LLM agent can chain tools autonomously (e.g., "find all videos tagged `outdoor_stage`, extract highlights, generate a reel") without GigHive writing that orchestration logic
- **Reuse across surfaces** — the same MCP server serves both the background AI worker and any future chat/assistant interface built on top of GigHive
- **Separation of concerns** — MCP server = integration layer (tool definitions, LLM API auth, adapter impls); GigHive worker = execution layer (job queue, DB writes, file I/O)

The MCP server communicates with the GigHive worker through the existing `ai_jobs`/`taggings` schema, keeping the boundary clean.

---

## Open Questions / Decisions

- Which LLM provider to use for the first concrete `LLMVisionAdapter` impl (GPT-4o, Gemini 1.5 Pro, Claude 3.5 Sonnet)?
- Frame sampling strategy: fixed interval vs. scene-change detection vs. hybrid?
- Prompt design: free-form description vs. structured JSON schema enforced by the model?
- Cost control: cap on frames per video or tokens per job?
- Whether to build the MCP server as a separate container or embed adapter logic directly in the AI worker for v1

---

## Related

- [GigHive Intelligence Platform Framework](feature_ai_intelligence_platform.md) — parent framework, schema definitions, helper registry model, job queue

---

## Logic Issues Identified (Design Review)

### 1. Frame-timestamp → time-ranged tag mismatch
The LLM adapter return type `[{namespace, name, confidence, start_seconds, end_seconds}]` implies time-ranged tagging. However, the described flow sends all frames batched in a single LLM call with no per-frame timestamps in the prompt. The model cannot assign `start_seconds`/`end_seconds` without explicit per-frame timestamp labels. **Fix**: include timestamps in the prompt per frame (e.g. "Frame 1 at t=0:05, Frame 2 at t=0:10…") and document that v1 time ranges are approximate, aligned to sampled frame timestamps only.

### 2. `helper_runs` FK must be satisfied before `derived_assets` writes
`derived_assets.run_id` is a FK to `helper_runs`. Phase 1 (frame extraction) writes `derived_assets` rows before the LLM call and run complete. The `helper_runs` record must be created at the very start of the pipeline with `status='running'` — not after — otherwise the FK constraint fails during frame writes.

### 3. Token-limit / multi-call chunking is a hard requirement, not an open question
A 30-minute video at 1 frame/5s = 360 frames. GPT-4o's practical batch limit is ~8–16 images per call. Chunking into multiple sequential LLM calls is mandatory. Leaving this as an open question means any video longer than ~2 minutes will either fail or produce silently truncated results.

### 4. MCP server vs. direct adapter is unresolved and internally contradictory
The plan simultaneously describes an `LLMVisionAdapter` inside the worker (Phase 2) and the MCP server as the "provider conduit" that owns the adapter layer. These are architecturally mutually exclusive for v1. **Recommendation**: direct adapter in worker for v1; MCP server deferred to v2.

### 5. `enqueue_categorization_job` MCP tool breaks the clean-boundary claim
If the MCP server is a separate service it cannot insert into `ai_jobs` without direct DB access, which contradicts the "clean separation" claim. It must instead call `POST /api/v1/ai/jobs`. The document implies direct schema access from an external integration layer.

### 6. `helper_id` naming inconsistent with parent framework convention
Parent doc uses `faces_v1`, `energy_v1`, `scenes_v1` (capability + version). This doc uses `llm_categorize_v1` (method + verb), which breaks the convention. **Recommended**: rename to `video_tagger_v1`.

### 7. No retry / backoff / dead-letter strategy specified
`ai_jobs.attempts` exists but max retries, backoff intervals, and dead-letter behavior on LLM API failures (rate limits, timeouts, malformed JSON) are unspecified. Required before implementation to prevent infinite retry loops.

### 8. Sampled frame lifecycle / storage policy undefined
Frames written as `derived_assets` rows are intermediary artifacts. No policy exists for retaining vs. deleting them post-run. At scale, retaining N frames per video is significant storage cost; deleting breaks reproducibility. A retention policy must be chosen before implementation.

---

## Detailed Implementation Plan

### Phase 0 — Pre-Implementation Decisions (Blockers)

Resolve all of these before writing any code:

| Decision | Options | Recommendation |
|----------|---------|----------------|
| LLM provider for v1 | GPT-4o, Gemini 2.0 Flash, Claude 3.5 Sonnet | GPT-4o (best JSON-mode reliability) |
| Worker runtime | Python, PHP, Node | Python (broadest LLM SDK + ffmpeg support) |
| v1 adapter boundary | Direct adapter in worker vs. MCP server | Direct adapter — MCP deferred to v2 |
| Frame sampling strategy | ~~Fixed interval vs. scene-change vs. hybrid~~ | **Decided: fixed interval at 1 frame / 5 s; scene-change as v2** |
| Max frames per job (hard cap) | Uncapped vs. capped | 48 frames (8 LLM chunks × 6 frames each) |
| Frames per LLM chunk | 4, 6, 8, 16 | 6 (safe for GPT-4o context + cost) |
| Frame retention policy | Retain on disk vs. delete post-run | Retain 30 days in `ai_assets/frames/`, then purge |
| `helper_id` canonical name | `llm_categorize_v1` vs. `video_tagger_v1` | `video_tagger_v1` |

---

### Phase 1 — Database Schema Migrations

New migration file(s) in `ansible/roles/docker/files/mysql/dbScripts/` (or extend `create_music_db.sql`).

#### 1a. Framework tables — create first if not already present

These are defined in the parent framework doc; all must exist before tagging tables:

- `media_files` — registers video/audio assets with `storage_backend`, `storage_locator`, codec metadata
- `media_file_links` — links `media_files` to domain entities (`event`, `song`, `musician`)
- `ai_jobs` — generic DB-backed job queue (`status`, `priority`, `attempts`, `locked_by`, `locked_at`)
- `helper_runs` — per-execution audit trail (`helper_id`, `job_id`, `version`, `params_json`, `status`, `metrics_json`)
- `derived_assets` — files produced by helpers (`run_id`, `asset_type`, `storage_locator`, `mime_type`)

Required index additions to `ai_jobs` for the worker atomic-claim query:

```sql
ALTER TABLE ai_jobs
  ADD INDEX idx_ai_jobs_claim  (status, job_type, priority, created_at),
  ADD INDEX idx_ai_jobs_target (target_type, target_id);
```

#### 1b. Tagging tables

```sql
CREATE TABLE tags (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  namespace  VARCHAR(64)  NOT NULL,
  name       VARCHAR(128) NOT NULL,
  created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_tags_ns_name (namespace, name),
  KEY idx_tags_namespace (namespace)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE taggings (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  tag_id        BIGINT UNSIGNED NOT NULL,
  target_type   ENUM('media_file','event','song','segment') NOT NULL,
  target_id     BIGINT UNSIGNED NOT NULL,
  start_seconds FLOAT          NULL,
  end_seconds   FLOAT          NULL,
  confidence    FLOAT          NULL,
  source        ENUM('ai','human') NOT NULL DEFAULT 'ai',
  run_id        BIGINT UNSIGNED NULL,
  created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_taggings_tag_target (tag_id, target_type, target_id),
  KEY idx_taggings_target     (target_type, target_id),
  KEY idx_taggings_run        (run_id),
  KEY idx_taggings_source     (source),
  CONSTRAINT fk_taggings_tag FOREIGN KEY (tag_id) REFERENCES tags (id),
  CONSTRAINT fk_taggings_run FOREIGN KEY (run_id) REFERENCES helper_runs (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

---

### Phase 2 — AI Worker Container Scaffold

New Docker service: `ai-worker`

**Source layout:**
```
ansible/roles/docker/files/ai-worker/
  Dockerfile
  requirements.txt             ← openai, mysql-connector-python, Pillow
  worker.py                    ← main polling loop
  db.py                        ← MySQL connection + claim/complete/fail helpers
  frame_extractor.py           ← ffprobe + ffmpeg wrapper
  tag_normalizer.py            ← parse LLM JSON → normalized TagResult list + DB writes
  helpers/
    video_tagger.py            ← video_tagger_v1 orchestrator (frames → LLM → taggings)
  adapters/
    base.py                    ← LLMVisionAdapter ABC (FrameData, TagResult dataclasses)
    openai_adapter.py          ← GPT-4o concrete implementation
```

**`docker-compose.yml.j2` addition:**
```yaml
ai-worker:
  build: ./ai-worker
  restart: unless-stopped
  env_file: ./apache/externalConfigs/.env
  volumes:
    - /home/{{ ansible_user }}/video:/data/video:ro
    - /home/{{ ansible_user }}/audio:/data/audio:ro
    - /home/{{ ansible_user }}/ai_assets:/data/ai_assets:rw
  depends_on:
    - mysql
```

**New env vars for `.env.j2` and `group_vars`:**

| Variable | Default | Purpose |
|----------|---------|---------|
| `AI_WORKER_ENABLED` | `false` | PHP-side gate — no-ops job enqueue when false |
| `LLM_PROVIDER` | `openai` | `openai` \| `gemini` |
| `OPENAI_API_KEY` | — | Store in Ansible Vault |
| `OPENAI_MODEL` | `gpt-4o` | Override to `gpt-4o-mini` for cost testing |
| `AI_FRAME_INTERVAL_SECONDS` | `5` | Seconds between sampled frames (**confirmed**) |
| `AI_MAX_FRAMES_PER_JOB` | `48` | Hard cap per job |
| `AI_MAX_FRAMES_PER_CHUNK` | `6` | Frames per single LLM API call |
| `AI_FRAME_RETENTION_DAYS` | `30` | Days to keep sampled frames before purge |
| `AI_WORKER_POLL_INTERVAL` | `5` | Seconds to sleep when queue is empty |
| `AI_WORKER_MAX_ATTEMPTS` | `3` | Max retries before marking job permanently failed |
| `GIGHIVE_VIDEO_ROOT` | `/data/video` | Container path to video mount |
| `GIGHIVE_AI_ASSETS_ROOT` | `/data/ai_assets` | Container path to AI assets mount |

---

### Phase 3 — Frame Extractor (`frame_extractor.py`)

**Inputs:** `media_file_id`, `storage_locator`, `run_id`, `params` (interval, max_frames)  
**Outputs:** `list[FrameData]` — each: `{path, timestamp_seconds, derived_asset_id}`

**Steps (in order):**

1. **Resolve path** — `abs_path = os.path.join(GIGHIVE_VIDEO_ROOT, storage_locator)`; raise `MediaNotFoundError` if absent.
2. **Preflight ffprobe** — `ffprobe -v quiet -print_format json -show_streams <path>`; parse duration, codec, width/height; write JSON to `ai_assets/diagnostics/<media_file_id>/ffprobe.json`. Non-zero exit → raise `MediaDecodeError`, fail job immediately without retry.
3. **Calculate timestamps** — `[i * interval for i in range(int(duration / interval))][:max_frames]`
4. **Extract frames with ffmpeg** — output to `ai_assets/frames/<media_file_id>/<run_id>/`; command:
   ```
   ffmpeg -i <path> -vf fps=1/<interval>,scale=768:-1 -q:v 3 frame_%04d.jpg
   ```
   Zero output frames → raise `FrameExtractionError`, fail job.
5. **Write `derived_assets` rows** — one row per frame: `asset_type='sampled_frame'`, `storage_locator=<relative_path>`, `mime_type='image/jpeg'`, `run_id=<run_id>`. Note: `run_id` must already exist (created at pipeline start with `status='running'`).
6. **Return** `list[FrameData]` with path, `timestamp_seconds`, and `derived_asset_id`.

---

### Phase 4 — LLM Adapter Layer (`adapters/`)

#### `adapters/base.py` — abstract interface

```python
from dataclasses import dataclass
from typing import Optional
from abc import ABC, abstractmethod

@dataclass
class FrameData:
    path: str
    timestamp_seconds: float
    derived_asset_id: int

@dataclass
class TagResult:
    namespace: str
    name: str
    confidence: float
    start_seconds: Optional[float]
    end_seconds: Optional[float]

class LLMVisionAdapter(ABC):
    @abstractmethod
    def analyze_frames(self, frames: list[FrameData]) -> list[TagResult]:
        """Analyze one chunk of frames; return tags for that chunk only."""
        ...
```

#### Chunking strategy (in `helpers/video_tagger.py`)

Chunking is the caller's responsibility, not the adapter's. The orchestrator splits frames into chunks of `AI_MAX_FRAMES_PER_CHUNK` and calls `adapter.analyze_frames()` once per chunk:

```python
def analyze_in_chunks(adapter, frames, chunk_size):
    raw = []
    for i in range(0, len(frames), chunk_size):
        raw.extend(adapter.analyze_frames(frames[i:i + chunk_size]))
    return deduplicate_tags(raw)
```

**Deduplication rule:** same `(namespace, name)` across multiple chunks → merge into one `TagResult` with highest confidence and time union `[min(start_seconds), max(end_seconds)]`.

#### Prompt template (structured JSON, sent per chunk)

```
You are analyzing a video. Below are {N} sampled frames at these timestamps:
Frame 1 = {t1}s, Frame 2 = {t2}s, ...

Identify what is observable. Return ONLY valid JSON:
{
  "tags": [
    {"namespace": "<scene|object|activity|person_role>",
     "name": "<snake_case>",
     "confidence": <0.0-1.0>,
     "start_seconds": <float or null>,
     "end_seconds": <float or null>}
  ]
}

Allowed namespaces and examples:
- scene:       outdoor_stage, indoor_reception, recording_studio, rehearsal_space
- object:      guitar, drum_kit, microphone, crowd, stage_lighting, wedding_cake, flowers
- activity:    live_performance, dancing, speech, soundcheck, applauding
- person_role: musician, audience, officiant, wedding_party, photographer

Use null for start/end_seconds when a tag applies across the whole chunk.
Use snake_case for all name values. Do not invent namespaces.
```

#### `adapters/openai_adapter.py`

- Model: `OPENAI_MODEL` env var (default `gpt-4o`)
- Call `client.chat.completions.create(response_format={"type":"json_object"}, ...)`
- Each frame: `{"type":"image_url","image_url":{"url":"data:image/jpeg;base64,<b64>","detail":"low"}}`
- `RateLimitError`: exponential backoff 1s/2s/4s, up to 3 retries before re-raising
- Unparseable JSON response: log ERROR, return `[]` for this chunk (do not abort entire job)

---

### Phase 5 — Tag Normalizer (`tag_normalizer.py`)

**Input:** raw `list[TagResult]` from adapter  
**Output:** validated, normalized list + upserts into `tags` and `taggings`

**Normalization rules:**
1. `namespace` must be in `{scene, object, activity, person_role}` — discard unknown; log WARN with raw value.
2. `name`: lowercase → strip whitespace → spaces to underscores → strip non-`[a-z0-9_]` → truncate to 128 chars.
3. `confidence`: clamp to `[0.0, 1.0]`; default `0.5` if null.
4. Time range: null valid (whole-video). If both present, validate `start < end`; swap if inverted; drop if equal.

**DB writes:**
- `tags`: `INSERT IGNORE INTO tags (namespace, name) VALUES (%s, %s)` then `SELECT id`.
- `taggings`: `INSERT ... ON DUPLICATE KEY UPDATE confidence=VALUES(confidence)` with unique key on `(tag_id, target_type, target_id, run_id)` for idempotency on retry.

---

### Phase 6 — Job Wiring

#### 6a. Automatic trigger on `media_files` registration (PHP)

In `UnifiedIngestionCore.php`, after a new `media_files` row is successfully inserted:

```php
if (getenv('AI_WORKER_ENABLED') === 'true') {
    $params = json_encode([
        'fps_interval' => (int)(getenv('AI_FRAME_INTERVAL_SECONDS') ?: 5),
        'max_frames'   => (int)(getenv('AI_MAX_FRAMES_PER_JOB') ?: 48),
    ]);
    $this->db->execute(
        "INSERT INTO ai_jobs (job_type, target_type, target_id, params_json, status, priority)
         VALUES ('categorize_video', 'media_file', ?, ?, 'queued', 100)",
        [$mediaFileId, $params]
    );
}
```

This is a no-op when `AI_WORKER_ENABLED=false`, so PHP can be deployed safely before the worker container exists.

#### 6b. On-demand via REST API

New controller: `src/Controllers/AiJobController.php`

- `POST /api/v1/ai/jobs` — validate `job_type` against allowlist, validate `target_id` exists, insert `ai_jobs` row, return `{id, status}` HTTP 201
- `GET /api/v1/ai/jobs` — list with optional `?status=&job_type=` filters
- `GET /api/v1/ai/jobs/{id}` — single job + associated `helper_runs` rows

#### 6c. On-demand via Admin UI

- "Analyze with AI Tagger" button on the media file detail page
- Fires `POST /api/v1/ai/jobs` via `fetch()`; polls `GET /api/v1/ai/jobs/{id}` every 3s to show inline job status

---

### Phase 7 — Worker Polling Loop (`worker.py`)

**Atomic claim pattern** (prevents double-processing across multiple worker instances):

```python
def claim_next_job(conn, job_type, worker_id):
    with conn.begin():
        job = conn.execute(
            "SELECT * FROM ai_jobs WHERE status='queued' AND job_type=%s "
            "ORDER BY priority ASC, created_at ASC LIMIT 1 FOR UPDATE SKIP LOCKED",
            (job_type,)
        ).fetchone()
        if not job:
            return None
        conn.execute(
            "UPDATE ai_jobs SET status='running', locked_by=%s, locked_at=NOW(), "
            "attempts=attempts+1 WHERE id=%s",
            (worker_id, job['id'])
        )
    return job
```

**Main loop:**

```python
MAX_ATTEMPTS = int(os.getenv('AI_WORKER_MAX_ATTEMPTS', 3))

while True:
    job = claim_next_job(conn, 'categorize_video', WORKER_ID)
    if not job:
        time.sleep(POLL_INTERVAL)
        continue
    if job['attempts'] > MAX_ATTEMPTS:
        mark_job_failed(conn, job['id'], 'exceeded max attempts')
        continue

    run = create_helper_run(conn, job, 'video_tagger_v1')   # status='running'
    try:
        video_tagger.run(conn, job, run)
        mark_job_done(conn, job['id'], run['id'])
    except MediaDecodeError as e:
        mark_job_failed(conn, job['id'], str(e), no_retry=True)
    except Exception as e:
        mark_job_failed(conn, job['id'], str(e))
        backoff = min(60, 2 ** job['attempts'])
        time.sleep(backoff)
```

**Dead-letter policy:** after `MAX_ATTEMPTS` failures, `status` is set to `'failed'` permanently. Worker logs at ERROR level. No automatic alerting in v1 — manual inspection of `ai_jobs WHERE status='failed'`.

---

### Phase 8 — Tag Browsing UI (Admin)

#### 8a. Per-video tag list — `/admin/media/{id}/tags`
- Table: Namespace | Tag name | Confidence bar | Time range | Source (AI / Human) | Actions
- Actions per row: **Confirm** (flip source to `'human'`), **Reject** (delete tagging), **Edit** (reject + add new)
- "Re-run AI Tagger" button → `POST /api/v1/ai/jobs`
- "Add manual tag" inline form

#### 8b. Tag browser — `/admin/tags`
- Filter by namespace; tag list with video count per tag
- Click tag → media file list (thumbnails + confidence score)

#### 8c. Tag stats — admin dashboard sidebar widget
- Total AI tags, total human-confirmed tags, top 5 tags by count

**New PHP files:**
- `admin/ai_tags.php` — tag browser
- `admin/media_tags.php` — per-media tag list
- Shared partial: `admin/partials/tag_list.php`

**New API endpoints:**
- `GET /api/v1/tags?namespace=` — list tags
- `GET /api/v1/taggings?target_type=media_file&target_id=` — list taggings for a media file
- `PATCH /api/v1/taggings/{id}` — confirm / reject / edit
- `POST /api/v1/taggings` — manual tag add

---

### Phase 9 — Search Integration

Extend existing event/video query surfaces with tag filters.

**SQL pattern:**
```sql
SELECT mf.* FROM media_files mf
JOIN taggings tg ON tg.target_id = mf.id AND tg.target_type = 'media_file'
JOIN tags t      ON t.id = tg.tag_id
WHERE t.namespace = ? AND t.name = ?
  AND tg.confidence >= ?
```

**API additions:**
- `GET /api/v1/media-files?tag=scene:outdoor_stage&tag=object:guitar` — AND logic across tags
- `GET /api/v1/events?tag=activity:live_performance` — events with at least one matching media file

**UI additions:**
- Tag filter chips in admin media/event list views
- Namespace dropdown + tag name autocomplete fed by `GET /api/v1/tags`

---

### Phase 10 — Human Review Layer

| User action | DB change |
|-------------|-----------|
| Confirm AI tag | `UPDATE taggings SET source='human' WHERE id=?` — `run_id` preserved for provenance |
| Reject AI tag | `DELETE FROM taggings WHERE id=?` — hard delete in v1 |
| Edit AI tag | DELETE original + INSERT new with `source='human'`, `run_id=NULL` |
| Add manual tag | INSERT with `source='human'`, `run_id=NULL`, `confidence=1.0` |

On confirm, `run_id` is preserved so the originating run is still auditable. On edit or manual add, `run_id=NULL` indicates entirely human-authored.

---

### Implementation Sequence (Recommended Order)

| Step | Work item | Dependency |
|------|-----------|------------|
| 1 | Phase 0 decisions | — |
| 2 | Phase 1 schema migrations | — |
| 3 | Phase 2 worker container scaffold + DB connection | Phase 1 |
| 4 | Phase 3 frame extractor (without LLM call) | Phase 2 |
| 5 | Phase 7 polling loop with mock helper (smoke test) | Phase 2, 3 |
| 6 | Phase 4 LLM adapter + Phase 5 normalizer | Phase 3 |
| 7 | Phase 6a auto-trigger in PHP | Phase 1 |
| 8 | Phase 6b/c REST API + admin button | Phase 6a |
| 9 | Phase 8 tag browsing UI | Phase 1, 6 |
| 10 | Phase 9 search integration | Phase 8 |
| 11 | Phase 10 human review | Phase 8 |

**Test gate between steps 5 and 6:** the worker should be able to claim jobs, extract frames, write `derived_assets` rows, and mark jobs `done` with zero LLM calls before the adapter is wired in. This validates the entire job queue and frame extraction path independently of LLM API costs.

---

### Open Questions (Updated)

- ~~Which LLM provider for v1?~~ → **decision required (Phase 0)**
- ~~Frame sampling strategy?~~ → **fixed interval for v1; scene-change in v2**
- ~~MCP server vs. direct adapter?~~ → **direct adapter for v1; MCP deferred**
- ~~Prompt design: free-form vs. structured JSON?~~ → **structured JSON with `response_format=json_object`**
- ~~Cost control: frame/token cap?~~ → **hard cap 48 frames / 8 LLM calls per job**
- ~~MCP server: separate container or embedded?~~ → **deferred to v2**
- **Remaining**: Should rejected tags be hard-deleted or soft-deleted (tombstoned with `rejected_at`)?
- **Remaining**: Confirm `video_tagger_v1` as the canonical `helper_id`?
- **Remaining**: Confirm 30-day frame retention policy?
