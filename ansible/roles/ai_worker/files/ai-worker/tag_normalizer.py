"""
tag_normalizer.py — validate + normalize TagResult list, then upsert into tags/taggings.
"""

import logging
import os
import re

from adapters.base import TagResult

logger = logging.getLogger(__name__)

ALLOWED_NAMESPACES = {'scene', 'object', 'activity', 'person_role'}


def normalize_name(raw: str) -> str:
    """lowercase → strip → spaces to underscores → strip non-[a-z0-9_] → truncate 128."""
    s = raw.lower().strip()
    s = s.replace(' ', '_')
    s = re.sub(r'[^a-z0-9_]', '', s)
    return s[:128]


def normalize_tags(raw_tags: list[TagResult]) -> list[TagResult]:
    """Validate and normalize a list of TagResult objects. Discards invalid entries."""
    normalized: list[TagResult] = []
    for tag in raw_tags:
        if tag.namespace not in ALLOWED_NAMESPACES:
            logger.warning("Discarding tag with unknown namespace: %r", tag.namespace)
            continue
        name = normalize_name(tag.name)
        if not name:
            logger.warning("Discarding tag with empty name after normalization (namespace=%r)", tag.namespace)
            continue
        confidence = float(tag.confidence or 0.5)
        confidence = max(0.0, min(1.0, confidence))
        start = tag.start_seconds
        end = tag.end_seconds
        if start is not None and end is not None:
            if start == end:
                start = None
                end = None
            elif start > end:
                start, end = end, start
        normalized.append(TagResult(
            namespace=tag.namespace,
            name=name,
            confidence=confidence,
            start_seconds=start,
            end_seconds=end,
        ))
    return normalized


def deduplicate_tags(tags: list[TagResult]) -> list[TagResult]:
    """
    Merge duplicates with the same (namespace, name).
    Keeps highest confidence and time union [min(start), max(end)].
    Sorts by occurrence frequency desc, then confidence desc, and caps at
    AI_MAX_TAGS_PER_ASSET (default 25).
    """
    merged: dict[tuple[str, str], TagResult] = {}
    counts: dict[tuple[str, str], int] = {}
    for tag in tags:
        key = (tag.namespace, tag.name)
        counts[key] = counts.get(key, 0) + 1
        if key not in merged:
            merged[key] = tag
            continue
        existing = merged[key]
        new_confidence = max(existing.confidence, tag.confidence)
        if existing.start_seconds is not None and tag.start_seconds is not None:
            new_start = min(existing.start_seconds, tag.start_seconds)
        else:
            new_start = existing.start_seconds or tag.start_seconds
        if existing.end_seconds is not None and tag.end_seconds is not None:
            new_end = max(existing.end_seconds, tag.end_seconds)
        else:
            new_end = existing.end_seconds or tag.end_seconds
        merged[key] = TagResult(
            namespace=tag.namespace,
            name=tag.name,
            confidence=new_confidence,
            start_seconds=new_start,
            end_seconds=new_end,
        )
    max_tags = int(os.getenv('AI_MAX_TAGS_PER_ASSET', 25))
    ranked = sorted(
        merged.values(),
        key=lambda t: (-counts[(t.namespace, t.name)], -t.confidence),
    )
    return ranked[:max_tags]


def upsert_taggings(
    conn,
    tags: list[TagResult],
    target_type: str,
    target_id: int,
    run_id: int,
) -> int:
    """
    Upsert tags + taggings for a target. Returns count of rows written.
    tags table: INSERT IGNORE (idempotent on namespace+name).
    taggings table: ON DUPLICATE KEY UPDATE (idempotent on tag+target pair).
    """
    tags = normalize_tags(tags)
    tags = deduplicate_tags(tags)
    if not tags:
        return 0

    cur = conn.cursor()
    written = 0
    try:
        for tag in tags:
            # Ensure tag row exists
            cur.execute(
                "INSERT IGNORE INTO tags (namespace, name) VALUES (%s, %s)",
                (tag.namespace, tag.name),
            )
            cur.execute(
                "SELECT id FROM tags WHERE namespace=%s AND name=%s",
                (tag.namespace, tag.name),
            )
            row = cur.fetchone()
            if not row:
                continue
            tag_id = row[0]

            # Upsert tagging
            cur.execute(
                "INSERT INTO taggings "
                "(tag_id, target_type, target_id, start_seconds, end_seconds, confidence, source, run_id) "
                "VALUES (%s, %s, %s, %s, %s, %s, 'ai', %s) "
                "ON DUPLICATE KEY UPDATE "
                "confidence=VALUES(confidence), start_seconds=VALUES(start_seconds), "
                "end_seconds=VALUES(end_seconds), run_id=VALUES(run_id)",
                (tag_id, target_type, target_id,
                 tag.start_seconds, tag.end_seconds, tag.confidence, run_id),
            )
            written += 1

        conn.commit()
    finally:
        cur.close()

    return written
