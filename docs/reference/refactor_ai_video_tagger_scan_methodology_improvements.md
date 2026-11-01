# AI Video Tagger — Scan Methodology Improvement Options

> **Status:** Candidate refactors — not yet implemented.
> **Context:** Current production config uses `ai_max_frames_per_job: 96` with uniform distribution across full video duration. See `ansible/inventories/group_vars/gighive2/gighive2.yml` and `ansible/roles/ai_worker/files/ai-worker/frame_extractor.py`.

---

## Current Approach (Baseline)

Frames are extracted at a fixed interval (`AI_FRAME_INTERVAL_SECONDS`, default 5s). When the natural frame count would exceed `AI_MAX_FRAMES_PER_JOB`, the interval is scaled up so frames are distributed **uniformly across the full duration**:

```python
effective_interval = interval if natural_count <= max_frames else duration / max_frames
```

For a 108-minute video at 96 frames: one frame every ~67.5 seconds. Covers the full video but is content-blind — it samples equally through static passages and dynamic ones.

---

## Option A — Scene-Change-Based Sampling (Recommended)

### What it does
Uses ffmpeg's built-in scene-change detector to extract frames only at cuts and transitions — the moments when visual content actually changes.

```bash
ffmpeg -i input.mp4 \
  -vf "select='gt(scene,0.35)',metadata=print:file=-" \
  -vsync vfr frames/scene_%04d.jpg
```

The threshold `0.35` (35% frame difference) works well for concert/event footage. Lower = more sensitive, higher = only major cuts.

**Hybrid fallback:** If fewer than `N` scene-change frames are found (e.g. a locked-off camera recording), fall back to uniform sampling to guarantee coverage.

### Why it's better
For event/concert videos, camera cuts (wide → close-up → audience) are precisely when the visual inventory changes. This samples where the content changes, not at arbitrary clock intervals.

### Cost profile
Same or lower API cost at equal frame count — the frames sent contain more visual variety, so the LLM extracts more unique tags per frame. Fewer "duplicate-content" frames wasted.

### Implementation notes
- Replace the `ffmpeg fps=1/N` filter in `frame_extractor.py` with `select='gt(scene,THRESHOLD)'`
- Add `SCENE_CHANGE_THRESHOLD` env var (default `0.35`) to `.env.j2` and `gighive2.yml`
- Add `AI_SCENE_CHANGE_MIN_FRAMES` (default `24`) — minimum frames before fallback to uniform
- Scene detection outputs can be significantly more than `max_frames`; subsample evenly if over the cap

### Industry precedent
AWS Rekognition Video, Azure Video Indexer, and Google Video AI all use scene-boundary detection as the primary sampling strategy before dense analysis.

---

## Option B — Hierarchical Two-Pass Analysis

### What it does
Runs two separate LLM calls per video:

| Pass | Frames | Interval | Purpose |
|------|--------|----------|---------|
| 1 | 24 uniform across full duration | `duration / 24` | Broad categorization: venue type, instrument families, crowd size, event type |
| 2 | 24 dense from first 10 minutes | every 25s | Fine-grained: specific instruments, performer names on gear, music stands, lighting rigs |

Pass 2 targets the opening segment because event setups and introductions contain the densest identifying information (stage layout, gear, signage).

### Why it's better
Pass 1 is cheap and gives broad tags for filtering. Pass 2 is targeted and gives fine-grained tags that uniform sparse sampling misses in long recordings. Each pass uses a focused prompt, reducing LLM confusion from mixed visual contexts.

### Cost profile
~2× API cost (two calls). Pass 1 result can gate Pass 2 — e.g. skip Pass 2 for `file_type=audio` assets that were miscategorized.

### Implementation notes
- Requires two sequential `adapter.analyze_frames()` calls in `video_tagger.py`
- Pass 1 prompt: "Identify broad categories: venue, event type, instrumentation, crowd"
- Pass 2 prompt: "Identify specific objects, text, branding, individual instruments visible"
- Tags from both passes merged via existing `upsert_taggings()` with different `source` values

---

## Option C — MPEG I-Frame Extraction

### What it does
Extracts only the MPEG I-frames (intra-coded reference frames) from the video stream. These are the frames the codec independently encodes without referencing other frames — they naturally cluster at scene changes and at regular GOP (Group of Pictures) intervals.

```bash
ffmpeg -i input.mp4 \
  -vf "select='eq(pict_type,I)'" \
  -vsync vfr frames/iframe_%04d.jpg
```

### Why it's better
- **Zero scene detection overhead** — leverages information already baked into the codec
- I-frames at scene cuts are higher-quality (more bits allocated by the encoder)
- Uniform GOP I-frames (every ~250 frames at typical bitrates) provide baseline coverage between cuts
- Works on any video format without needing to run a full decode pass first

### Cost profile
I-frame count varies widely: 200–2000+ for a 90-minute concert. Subsample to `max_frames` using uniform stride after extraction. No change to LLM API cost.

### Caveats
- Constant-bitrate or heavily re-encoded files may have irregular I-frame distribution
- Some streaming/transcode pipelines produce only keyframes at chapter marks; these files would need fallback
- Add `AI_IFRAME_FALLBACK_INTERVAL` (default `60`) — if fewer than `AI_SCENE_CHANGE_MIN_FRAMES` I-frames are found, fall back to uniform sampling

### Implementation notes
- Replace `fps=1/N` with `select='eq(pict_type,I)'` filter in `frame_extractor.py`
- Use `ffprobe -select_streams v -show_packets -show_entries packet=pts_time,flags` to count I-frames before committing to extraction (cheap preflight)
- Add `AI_SAMPLING_STRATEGY` env var: `uniform` (current), `scene_change`, `iframe`, `two_pass`

---

## Comparison Matrix

| Strategy | Coverage | Content Awareness | API Cost | Implementation Complexity |
|----------|----------|-------------------|----------|--------------------------|
| Uniform (current) | ✅ Full | ❌ None | Baseline | Done |
| Scene-change (A) | ✅ Full | ✅ High | Baseline | Medium |
| Two-pass (B) | ✅ Full | ✅ Medium | 2× | Medium |
| I-frame (C) | ✅ Full | ✅ Medium | Baseline | Low |
| Scene-change + I-frame hybrid | ✅ Full | ✅ Very High | Baseline | High |

## Recommended Implementation Order

1. **Option C (I-frame)** — lowest risk, drop-in replacement, no new dependencies
2. **Option A (Scene-change)** — best quality uplift, still a single pass
3. **Option B (Two-pass)** — when per-tag precision is needed for search/browse features

## Related Files

- `ansible/roles/ai_worker/files/ai-worker/frame_extractor.py` — sampling logic
- `ansible/roles/docker/templates/.env.j2` — env var definitions
- `ansible/inventories/group_vars/gighive2/gighive2.yml` — tuning parameters
- `docs/feature_ai_video_tagger.md` — main feature documentation
