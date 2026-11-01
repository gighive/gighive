# Problem: Media File Missing Duration / Media Info After Upload

**Date observed:** 2026-05-24  
**Reported by:** Admin (Section B folder import)

---

## Symptom

After a two-file folder import (Section B — Add to DB), one file appeared in the Media Library with no thumbnail, no duration, and no Media File Info:

| # | File Path | Duration | Media File Info |
|---|-----------|----------|-----------------|
| 1 | birdChase/DJI_0395.MP4 | 00:03:17 | QuickTime / MOV … |
| 2 | birdChase/DJI_0183.MP4 | *(blank)* | *(blank)* |

Both files showed `succeeded: 2, batch_state: complete_success` in the upload log.

---

## Investigation

### Step 1 — Identify the import job

Job ID from the UI: `20260524-145103-ad58d836c101`

### Step 2 — Inspect job files inside the Apache container

```bash
docker exec apacheWebServer ls -la /var/www/private/import_jobs/20260524-145103-ad58d836c101/
```

Output confirmed job directory exists with `upload_status.json`, `upload_result.json`, `upload_trace.jsonl`.

### Step 3 — Read upload_status.json

```bash
docker exec apacheWebServer cat /var/www/private/import_jobs/20260524-145103-ad58d836c101/upload_status.json | python3 -m json.tool
```

Key findings from the per-file state:

| File | State | duration_seconds | thumbnail_state |
|------|-------|-----------------|-----------------|
| DJI_0183.MP4 | `uploaded` | `null` | `pending` |
| DJI_0184.MP4 | `thumbnail_done` | `197` | `done` |

> **Note:** The second file in this upload was `DJI_0184.MP4`, not `DJI_0395.MP4` as initially assumed from the Media Library screenshot (which showed a previously-ingested file in row 1).

### Step 4 — Read upload_trace.jsonl

```bash
docker exec apacheWebServer cat /var/www/private/import_jobs/20260524-145103-ad58d836c101/upload_trace.jsonl | python3 -c "import sys,json; [print(json.dumps(json.loads(l), indent=2)) for l in sys.stdin]"
```

Relevant trace entries for `DJI_0183.MP4`:

```json
{
  "ts": "2026-05-24T14:51:45-04:00",
  "phase": "finalize_request_received",
  "source_relpath": "birdChase/DJI_0183.MP4",
  "file_type": "video",
  "size_bytes_manifest": 900186112
}
{
  "ts": "2026-05-24T14:51:50-04:00",
  "phase": "finalize_success",
  "state": "uploaded",
  "thumbnail_done": false,
  "db_done": true,
  "stored_file_name": "9547f7873e41a395068b82a9dcd05bb70fcf03183e9298b72be07d7519167483.mp4",
  "size_bytes_actual": 900186112,
  "mime_type": "video/mp4",
  "duration_seconds": null,
  "all_done": false
}
```

Key observation: `ingestComplete` **was** called (`db_done: true`) but `duration_seconds` came back `null` and `thumbnail_done: false`. The entire finalize took only ~5 seconds for an 859 MB file, consistent with ffprobe failing fast rather than successfully scanning.

### Step 5 — Confirm file on disk and run ffprobe directly

```bash
docker exec apacheWebServer ls -lh /var/www/html/video/9547f7873e41a395068b82a9dcd05bb70fcf03183e9298b72be07d7519167483.mp4
```

```
-rw-r--r-- 1 www-data www-data 859M May 24 14:51 ...mp4
```

File is present and size matches manifest (900,186,112 bytes = 858.6 MiB ≈ 859M). ✓

```bash
docker exec apacheWebServer ffprobe -v error -show_entries format=duration \
  -of default=noprint_wrappers=1:nokey=1 \
  /var/www/html/video/9547f7873e41a395068b82a9dcd05bb70fcf03183e9298b72be07d7519167483.mp4
```

```
[mov,mp4,m4a,3gp,3g2,mj2 @ 0x56fd3a4d67c0] moov atom not found
/var/www/html/video/9547f7...: Invalid data found when processing input
```

```bash
docker exec apacheWebServer ffprobe -v quiet -print_format json -show_format -show_streams \
  /var/www/html/video/9547f7873e41a395068b82a9dcd05bb70fcf03183e9298b72be07d7519167483.mp4 2>&1
```

```json
{

}
```

---

## Root Cause

**The source file `DJI_0183.MP4` is a broken/unfinalized MP4 — the `moov` atom was never written.**

In MP4/MOV format, video data (`mdat`) is written continuously during recording. The `moov` atom (which contains all metadata: duration, codec info, frame index, timestamps) is written at the **end** of the file when recording is properly stopped. If the camera loses power, crashes, or the memory card is ejected mid-recording, the `moov` atom is never written. The result is a file that appears intact by size but is unreadable by any standards-compliant player or probe tool.

This is a well-known failure mode for DJI drone cameras.

---

## Application Behavior (Correct)

The upload system behaved correctly throughout:

1. The file uploaded and stored successfully (correct checksum, correct size on disk).
2. `ingestComplete` was called, which invoked `probeDuration` → ffprobe returned nothing (error to stderr, suppressed by `@shell_exec`) → `null` stored in DB.
3. `probeMediaInfo` similarly returned `null` → no media info stored.
4. `generateVideoThumbnail` was called with `null` duration → failed to generate thumbnail → `thumbnail_done: false`, `state: "uploaded"` (not `thumbnail_done`).

**This is not a code bug.** The `null` values in the database accurately reflect the state of the source file.

---

## File Recovery Options

The video data frames are intact in the file; only the container metadata is missing. Recovery is possible but not guaranteed:

- **`ffmpeg` untruncate / `mp4recover`** — attempts to reconstruct the moov atom by scanning raw frame data.
- **`MP4Box -add`** (`gpac` suite) — can sometimes re-wrap recoverable H.264/H.265 streams.
- **DJI-specific recovery tools** — DJI provides repair utilities for their proprietary variants.

If recovery succeeds, the file can be re-uploaded and will probe correctly.

---

## Notes on Import Job File Storage

Import job JSON files (`upload_status.json`, `upload_trace.jsonl`, etc.) are stored **inside the `apacheWebServer` container** at `/var/www/private/import_jobs/{job_id}/`. This path is **not volume-mounted** to the host, so the files are lost on container restart or recreation. To inspect them, use `docker exec apacheWebServer` while the container is still running from the same lifecycle as the upload.
