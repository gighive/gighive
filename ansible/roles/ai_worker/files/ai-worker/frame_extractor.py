"""
frame_extractor.py — ffprobe preflight + ffmpeg frame sampling + derived_assets writes.
"""

import json
import logging
import os
import subprocess
from dataclasses import dataclass
from pathlib import Path

from adapters.base import FrameData
from db import mark_run_failed

logger = logging.getLogger(__name__)

VIDEO_MOUNT = '/data/video'
AI_ASSETS_ROOT = os.getenv('GIGHIVE_AI_ASSETS_ROOT', '/data/ai_assets')

SUPPORTED_VIDEO_MIME_TYPES = {
    m for m in json.loads(os.environ.get('UPLOAD_ALLOWED_MIMES_JSON', '[]'))
    if m.startswith('video/')
}


class MediaNotFoundError(Exception):
    pass


class MediaDecodeError(Exception):
    pass


class FrameExtractionError(Exception):
    pass


def extract_frames(conn, asset: dict, run_id: int, params: dict) -> list[FrameData]:
    """
    Extract representative frames from a video asset.

    Returns list[FrameData] with path, timestamp_seconds, and derived_asset_id.
    Raises MediaNotFoundError, MediaDecodeError, or FrameExtractionError on failure.
    """
    asset_id = asset['asset_id']
    mime_type = asset.get('mime_type') or ''
    # Files are stored as {checksum_sha256}.{file_ext} on disk; fall back to source_relpath
    sha = asset.get('checksum_sha256') or ''
    ext = (asset.get('file_ext') or 'mp4').lstrip('.')
    storage_locator = (f"{sha}.{ext}" if sha else None) or asset.get('storage_locator') or asset.get('source_relpath') or ''
    interval = int(params.get('fps_interval', int(os.getenv('AI_FRAME_INTERVAL_SECONDS', 5))))
    max_frames = int(params.get('max_frames', int(os.getenv('AI_MAX_FRAMES_PER_JOB', 48))))

    # Guard: unsupported media type (only enforced when mime_type is populated;
    # CSV-imported assets may have mime_type=NULL so we trust file_type='video' as fallback)
    if SUPPORTED_VIDEO_MIME_TYPES and mime_type and mime_type not in SUPPORTED_VIDEO_MIME_TYPES:
        msg = f"unsupported mime_type: {mime_type}"
        logger.warning("Skipping asset %s: %s", asset_id, msg)
        mark_run_failed(conn, run_id, msg)
        return []

    # Resolve path
    abs_path = os.path.join(VIDEO_MOUNT, storage_locator)
    if not os.path.isfile(abs_path):
        raise MediaNotFoundError(f"Video file not found: {abs_path}")

    # Preflight ffprobe
    diag_dir = Path(AI_ASSETS_ROOT) / 'diagnostics' / str(asset_id)
    diag_dir.mkdir(parents=True, exist_ok=True)
    ffprobe_out_path = diag_dir / 'ffprobe.json'

    probe_result = subprocess.run(
        ['ffprobe', '-v', 'quiet', '-print_format', 'json', '-show_streams', '-show_format', abs_path],
        capture_output=True, text=True,
    )
    if probe_result.returncode != 0:
        raise MediaDecodeError(f"ffprobe failed (exit {probe_result.returncode}): {probe_result.stderr[:512]}")

    probe_data = json.loads(probe_result.stdout)
    ffprobe_out_path.write_text(json.dumps(probe_data, indent=2))

    # Parse duration from ffprobe output
    duration = 0.0
    for stream in probe_data.get('streams', []):
        dur = stream.get('duration')
        if dur:
            try:
                duration = float(dur)
                break
            except (ValueError, TypeError):
                pass
    if duration <= 0:
        fmt_dur = probe_data.get('format', {}).get('duration')
        if fmt_dur:
            try:
                duration = float(fmt_dur)
            except (ValueError, TypeError):
                pass
    if duration <= 0:
        raise MediaDecodeError("Could not determine video duration from ffprobe output")

    # Calculate effective interval — distribute frames evenly across full duration
    natural_count = int(duration / interval)
    effective_interval = interval if natural_count <= max_frames else duration / max_frames
    effective_interval = max(effective_interval, 0.5)

    # Extract frames with ffmpeg
    frames_dir = Path(AI_ASSETS_ROOT) / 'frames' / str(asset_id) / str(run_id)
    frames_dir.mkdir(parents=True, exist_ok=True)
    frame_pattern = str(frames_dir / 'frame_%04d.jpg')

    ffmpeg_result = subprocess.run(
        [
            'ffmpeg', '-y', '-i', abs_path,
            '-vf', f'fps=1/{effective_interval:.4f},scale=768:-1',
            '-q:v', '3',
            frame_pattern,
        ],
        capture_output=True, text=True,
    )
    if ffmpeg_result.returncode != 0:
        raise MediaDecodeError(f"ffmpeg failed (exit {ffmpeg_result.returncode}): {ffmpeg_result.stderr[:512]}")

    frame_files = sorted(frames_dir.glob('frame_*.jpg'))
    if not frame_files:
        raise FrameExtractionError("ffmpeg produced zero output frames")

    # Write derived_assets rows and build FrameData list
    results: list[FrameData] = []
    cur = conn.cursor()
    try:
        for i, frame_path in enumerate(frame_files):
            timestamp = i * effective_interval
            rel_path = f"frames/{asset_id}/{run_id}/{frame_path.name}"
            cur.execute(
                "INSERT INTO derived_assets (run_id, asset_type, storage_locator, mime_type) "
                "VALUES (%s, 'sampled_frame', %s, 'image/jpeg')",
                (run_id, rel_path),
            )
            derived_id = cur.lastrowid
            results.append(FrameData(
                path=str(frame_path),
                timestamp_seconds=timestamp,
                derived_asset_id=derived_id,
            ))
        conn.commit()
    finally:
        cur.close()

    logger.info(
        "Extracted %d frames from asset %s (duration=%.1fs, effective_interval=%.1fs)",
        len(results), asset_id, duration, effective_interval,
    )
    return results
