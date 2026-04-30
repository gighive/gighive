"""
helpers/video_tagger.py — Orchestrate frame extraction + LLM vision + tag normalization.
"""

from __future__ import annotations

import logging

from db import mark_run_done, mark_run_failed
from frame_extractor import MediaDecodeError, MediaNotFoundError, extract_frames
from tag_normalizer import upsert_taggings

logger = logging.getLogger(__name__)

HELPER_ID = 'video_tagger_v1'
HELPER_VERSION = '1.0.0'


def run(conn, job: dict, run_id: int, adapter) -> None:
    """
    Full pipeline for a single categorize_video job.

    1. Resolve asset from DB.
    2. Extract frames → derived_assets rows.
    3. Call LLM adapter.
    4. Normalize + upsert tags.
    5. Mark run done (or failed on exception).
    """
    asset_id = int(job['target_id'])
    params: dict = {}
    if job.get('params_json'):
        import json as _json
        try:
            params = _json.loads(job['params_json'])
        except Exception:
            pass

    # Fetch asset metadata
    cur = conn.cursor(dictionary=True)
    try:
        cur.execute(
            "SELECT asset_id, mime_type, source_relpath, file_type, checksum_sha256, file_ext FROM assets WHERE asset_id=%s",
            (asset_id,),
        )
        asset = cur.fetchone()
    finally:
        cur.close()

    if not asset:
        msg = f"Asset {asset_id} not found in assets table"
        logger.error(msg)
        mark_run_failed(conn, run_id, msg)
        raise ValueError(msg)

    if asset.get('file_type') != 'video':
        msg = f"Asset {asset_id} is not a video (file_type={asset.get('file_type')!r})"
        logger.warning(msg)
        mark_run_failed(conn, run_id, msg)
        raise ValueError(msg)

    # Extract frames
    try:
        frames = extract_frames(conn, asset, run_id, params)
    except (MediaNotFoundError, MediaDecodeError) as exc:
        msg = str(exc)
        logger.error("Frame extraction failed for asset %s: %s", asset_id, msg)
        mark_run_failed(conn, run_id, msg)
        raise

    if not frames:
        logger.info("No frames extracted for asset %s; marking done with no tags", asset_id)
        mark_run_done(conn, run_id, {'frames': 0, 'tags': 0})
        return

    # Analyze with LLM
    try:
        raw_tags = adapter.analyze_frames(frames)
    except Exception as exc:
        msg = f"LLM adapter error: {exc}"
        logger.error(msg)
        mark_run_failed(conn, run_id, msg)
        raise

    # Normalize + persist
    written = upsert_taggings(conn, raw_tags, 'asset', asset_id, run_id)

    metrics = {'frames': len(frames), 'raw_tags': len(raw_tags), 'written': written}
    mark_run_done(conn, run_id, metrics)
    logger.info(
        "video_tagger_v1 done for asset %s: frames=%d raw_tags=%d written=%d",
        asset_id, len(frames), len(raw_tags), written,
    )
