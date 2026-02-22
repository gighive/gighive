# GigHive Intelligence Platform Framework (Multi-Helper Design)

Date: 2026-02-21  
Status: Draft / Working design doc

## Goal

Turn GigHive into a **hosting + intelligence** platform by adding a general-purpose framework that can ingest media (audio/video), run multiple AI “helpers” against it, and persist results back into the GigHive database — enabling search, analytics, and creative transformations (e.g., stylization/cartoonization) over time.

This framework is designed to:
- Start with **face detection + clustering** as the first anchor capability
- Scale to a **large set of helpers** (“a crapload of stuff”) without redesigning core plumbing
- Support today’s storage reality (host filesystem bind mounts) while staying compatible with future blob/object storage


---

## Guiding Principles

1. **Deterministic, replayable runs**  
   Every helper run produces a record you can re-run, audit, compare, and roll back.

2. **Media stays where it is**  
   Helpers operate on media referenced by GigHive (initially local filesystem paths). Migration to blob storage is a storage adapter change, not a schema rewrite.

3. **Human-in-the-loop is first-class**  
   Identity, labeling, “good/bad” decisions, and approvals are supported as native workflows.

4. **Separation of concerns**  
   - Web/UI/API: enqueue jobs, browse results, label/approve
   - AI Workers: process media, generate results/assets, write back to DB
   - Storage: abstracted “resolver” that maps DB references to readable/writable locations

5. **Extensible helper model**  
   New helpers add:
   - a `helper_id`
   - a `job_type` (or job types)
   - output(s) that map into standard result tables (tags, detections, derived assets, embeddings)


---

## High-Level Architecture

### Components

- **GigHive Web (existing)**
  - Admin UI + REST API
  - Enqueues helper jobs
  - Serves media and derived assets (thumbnails/previews)

- **GigHive DB (existing)**
  - Source of truth for jam sessions, songs, musicians, and now intelligence artifacts

- **GigHive AI Worker (new)**
  - One or more worker containers that poll for jobs
  - Run helper pipelines
  - Write results back to DB
  - Store thumbnails/derived outputs to `ai_assets` (or blob later)

### Data Flow

1. Media is ingested/registered in DB (or discovered in a scan).
2. Web/API enqueues a job (DB-backed queue).
3. Worker pulls job → validates media access (preflight) → runs helper.
4. Worker stores:
   - structured results (rows in DB)
   - derived files (thumbs, previews, stylized outputs) in `ai_assets`
5. UI presents results + review screens; user confirms labels/approvals.


---

## Storage & Path Model (Local Today, Blob Tomorrow)

### Recommended DB representation

Store **relative paths** or **storage locators** (not absolute host paths).

- `storage_backend`: `local` | `s3` | `gcs` | `azure` (future)
- `storage_locator`: e.g.
  - local: `video/jams/2025-01-03/clip001.mp4`
  - blob: `s3://bucket/key` (or `{bucket,key}` columns)
- Media roots provided via env vars in containers:
  - `GIGHIVE_VIDEO_ROOT=/data/video`
  - `GIGHIVE_AUDIO_ROOT=/data/audio`
  - `GIGHIVE_AI_ASSETS_ROOT=/data/ai_assets`

### Host bind-mount layout (current)

Example host directories (matches your current approach):
- `/home/<ansible_user>/video`  → container `/data/video` (read-only for workers)
- `/home/<ansible_user>/audio`  → container `/data/audio` (read-only for workers)
- `/home/<ansible_user>/ai_assets` → container `/data/ai_assets`
  - **rw** for workers, **ro** for web

### Derived assets directory conventions

```
ai_assets/
  faces/
    detections/<media_file_id>/<timestamp_ms>_<det_id>.jpg
    clusters/<cluster_id>/rep.jpg
  previews/
    posters/<media_file_id>/poster.jpg
    gifs/<media_file_id>/preview.gif
  transforms/
    cartoon/<transform_run_id>/output.mp4
  diagnostics/
    <media_file_id>/ffprobe.json
    <job_id>/metrics.json
```


---

## Core “Platform” Tables (Framework)

These tables are helper-agnostic plumbing that enable many helpers.

### 1) `media_files`
Registers video/audio assets known to GigHive.

Suggested key fields:
- `id`
- `media_type` ENUM('video','audio')
- `storage_backend` VARCHAR
- `storage_locator` TEXT  (or `relative_path`)
- `duration_seconds`, `width`, `height`, `fps`
- `codec_video`, `codec_audio`
- `sha256` (optional, for dedupe)
- `created_at`, `updated_at`

### 2) `media_file_links`
Connect media to domain entities.

- `id`
- `media_file_id`
- `entity_type` ENUM('jam_session','song','musician')
- `entity_id`
- `role` ENUM('primary','broll','single','loop') (optional)

### 3) `ai_jobs` (DB-backed queue)
A generic job queue for all helpers.

- `id`
- `job_type` VARCHAR (e.g., `index_video_faces`, `cluster_faces`, `stylize_cartoon`)
- `target_type` ENUM('media_file','jam_session','song','musician') (optional)
- `target_id` BIGINT (optional)
- `params_json` JSON (helper config: fps sampling, thresholds, model version)
- `status` ENUM('queued','running','failed','done')
- `priority` INT default 100
- `attempts` INT default 0
- `locked_by` VARCHAR nullable
- `locked_at` DATETIME nullable
- `error_message` TEXT nullable
- `metrics_json` JSON nullable
- timestamps

**Locking pattern:** atomic “claim” update:
- worker selects one queued job (priority+created_at)
- updates status=running, locked_by, locked_at in one transaction
- prevents double-processing across multiple workers

### 4) `helper_runs`
Records each execution of a helper (for audit + reproducibility).

- `id`
- `helper_id` VARCHAR (e.g., `faces_v1`, `energy_v1`)
- `job_id` FK to `ai_jobs`
- `version` VARCHAR (code/model version)
- `params_json` JSON
- `status`, `metrics_json`, timestamps

### 5) `derived_assets`
A generic table for any file produced by helpers.

- `id`
- `run_id` FK to `helper_runs`
- `asset_type` VARCHAR (e.g., `face_thumb`, `cluster_rep`, `stylized_video`, `poster_frame`)
- `storage_backend`
- `storage_locator` (relative path today)
- `mime_type`
- `width`, `height`, `duration_seconds` (nullable)
- `created_at`

### 6) `tags` and `taggings`
A unified tagging model for “categorize everything.”

- `tags`: `id`, `namespace`, `name` (e.g., namespace=`scene`, name=`crowd`)
- `taggings`: link tags to targets with optional time ranges
  - `id`
  - `tag_id`
  - `target_type` ENUM('media_file','jam_session','song','segment')
  - `target_id`
  - `start_seconds`, `end_seconds` nullable
  - `confidence` float nullable
  - `source` ENUM('ai','human')
  - `run_id` FK nullable

### 7) `embeddings` (optional, but platform-enabling)
A generic way to store vectors for later search/clustering.

- `id`
- `run_id`
- `embedding_type` (e.g., `face`, `scene`, `audio`)
- `target_type`, `target_id`
- `start_seconds`, `end_seconds`
- `vector` BLOB (or external vector DB later)
- indexes as feasible


---

## Face Indexing Helper (First Anchor Helper)

### Why it’s first
Identity becomes the join key for:
- musician-based search
- co-appearance graphs
- per-person highlights
- per-person transformations (cartoonizing bandmates, etc.)

### Face helper outputs
- `face_detections` (raw)
- `face_clusters` (grouped)
- `derived_assets` thumbnails (for UI review)
- (optional) face embeddings in `embeddings`

#### `face_detections`
- `id`
- `media_file_id`
- `timestamp_seconds`
- bbox fields
- `confidence`
- `cluster_id` nullable
- `derived_asset_id` (face thumb)

#### `face_clusters`
- `id`
- `representative_asset_id`
- `assigned_musician_id` nullable (human-confirmed)
- `detection_count`
- `created_at`, `updated_at`

### Human-in-the-loop workflow
1. UI shows clusters with thumbnails
2. User assigns cluster → musician (or creates musician)
3. System records assignment + provenance

**Key UI actions:**
- assign
- unassign
- merge clusters
- split cluster (advanced later)


---

## Helper Registry Model

Each helper declares:
- `helper_id`
- supported `job_type`s
- required inputs (media type, access)
- outputs (tables/asset types)
- default params + allowed overrides
- versioning

Example helper ids:
- `faces_v1`
- `energy_v1`
- `scenes_v1`
- `stylize_cartoon_v1`
- `audio_cleanup_v1`


---

## Initial Helper Catalog (Starter Set)

### Categorization Helpers
1. **Faces / Identity**
   - detect faces, embed, cluster, label
2. **Scene / Shot Segmentation**
   - detect cut points, stable segments, “good edit points”
3. **Energy / Motion Scoring**
   - audio loudness + motion magnitude → “hype score”
4. **Context Tagging**
   - crowd vs rehearsal vs studio vs stage
   - closeup vs wide vs selfie
5. **Instrument Presence (later)**
   - “drums visible”, “guitar visible”, etc.

### Manipulation Helpers
1. **Auto Reframe / Smart Crop**
   - center on face cluster or bandmates
2. **Stylization / Cartoonize (later)**
   - produce stylized outputs per person/segment
3. **Highlight Reel Builder**
   - build reels from tagged segments by musician/jam/session
4. **Poster Frame / Thumbnail Generator**
   - pick best stills for UI display

### Audio Helpers
1. **Loudness normalization suggestions**
2. **Noise reduction / cleanup (optional)**
3. **Segmenting by energy peaks**
4. **Loop detection / beat alignment (later)**


---

## REST API Shape (Helper-Agnostic)

### Jobs
- `POST /api/v1/ai/jobs`
- `GET /api/v1/ai/jobs?status=...`
- `GET /api/v1/ai/jobs/{id}`

### Runs
- `GET /api/v1/ai/runs?helper_id=faces_v1`
- `GET /api/v1/ai/runs/{id}`

### Assets
- `GET /api/v1/ai/assets/{id}` (metadata)
- `GET /ai/assets/...` (static serving) or gated route

### Tags
- `GET /api/v1/tags`
- `GET /api/v1/taggings?target_type=...`

### Face-specific (first helper)
- `GET /api/v1/ai/face-clusters?assigned=false`
- `POST /api/v1/ai/face-clusters/{id}/assign`
- `POST /api/v1/ai/face-clusters/{id}/merge`
- `POST /api/v1/ai/face-clusters/{id}/split` (later)


---

## Operational Concerns

### Preflight diagnostics (must-have)
For every media file before processing:
- `ffprobe` results saved to DB and `ai_assets/diagnostics`
- detect decode failures early
- store “processable=true/false” flags + error reasons

### Idempotency
Jobs should be safe to retry:
- use `helper_runs` + uniqueness constraints (e.g., only one `faces_v1` run per media_file_id per version unless forced)

### Observability
Store per-job metrics:
- frames processed
- faces detected
- elapsed seconds
- CPU/GPU usage (optional)
- errors

### Security
- AI worker mounts original media **read-only**
- Derived asset serving should avoid arbitrary path traversal (serve by ID or strict mapping)


---

## Suggested MVP Milestones (Framework-First)

1. **Framework plumbing**
   - `media_files`, `ai_jobs`, `helper_runs`, `derived_assets`
   - worker can claim jobs reliably
   - preflight `ffprobe` stored

2. **Faces v1**
   - frame sampling
   - face detections + thumbnails
   - clustering + cluster thumbnails

3. **Labeling UI**
   - assign clusters to musicians
   - query appearances by musician

4. **Add 1–2 more helpers**
   - energy scoring
   - poster frame generator
   - (optional) simple highlight reel builder using the intelligence outputs


---

## Notes / Decisions to Confirm Later

- Vector storage: BLOB in MySQL for v1 vs external vector DB later  
- Whether to store embeddings at all in v1 (recommended if clustering needs it)
- Job concurrency limits + worker scaling approach
- Serving derived assets: static route vs controller-mediated access


---

## Appendix: Your Current Host-Backed Media Layout

Current docker-compose mounts (host → container):
- `/home/{{ ansible_user }}/audio` → `{{ media_search_dir_audio }}`
- `/home/{{ ansible_user }}/video` → `{{ media_search_dir_video }}`

Recommended additions:
- `/home/{{ ansible_user }}/ai_assets` → `/data/ai_assets`
- mount `:ro` for media in AI worker, `:rw` for ai_assets in AI worker
