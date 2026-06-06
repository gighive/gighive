"""
tools/ai_pipeline.py — AI job queue tools.

Tools:
  get_ai_queue_stats       — aggregate queue state by status
  get_jobs_failed          — failed jobs with asset paths and error clustering
  get_jobs_stale           — jobs stuck in 'running' (orphan detection)
  reset_jobs_retryable     — re-queue failed jobs, excluding permanent-failure patterns
"""

from __future__ import annotations

import re
from collections import Counter

import db


def register(mcp) -> None:

    @mcp.tool()
    def get_ai_queue_stats(job_type: str = "categorize_video") -> dict:
        """Aggregate AI job queue state by status.

        Returns counts per status plus a total.
        """
        rows = db.query(
            "SELECT status, COUNT(*) AS n FROM ai_jobs "
            "WHERE job_type = %s GROUP BY status",
            [job_type],
        )
        counts: dict[str, int] = {r['status']: r['n'] for r in rows}
        return {
            'queued':  counts.get('queued', 0),
            'running': counts.get('running', 0),
            'done':    counts.get('done', 0),
            'failed':  counts.get('failed', 0),
            'total':   sum(counts.values()),
            'job_type': job_type,
        }

    @mcp.tool()
    def get_jobs_failed(job_type: str = "categorize_video", limit: int = 100) -> dict:
        """Return failed AI jobs with asset paths and a grouped error summary.

        Includes a top-level error_groups dict so callers can distinguish
        permanently-failing file types (VOB, m2v) from retryable failures.
        """
        rows = db.query(
            """SELECT j.id, j.updated_at, j.error_msg, j.attempts,
                      a.source_relpath, a.file_type
               FROM ai_jobs j
               JOIN assets a ON a.asset_id = j.target_id
               WHERE j.status = 'failed'
                 AND j.job_type = %s
               ORDER BY j.updated_at DESC
               LIMIT %s""",
            [job_type, limit],
        )

        _PATTERNS = [
            ('vob_encrypted',  r'(?i)VOB'),
            ('m2v',            r'(?i)\.m2v'),
            ('utf8_codec',     r'(?i)utf-?8 codec'),
            ('ffmpeg_failed',  r'(?i)ffmpeg (failed|error|exit)'),
            ('no_video_stream',r'(?i)no video stream'),
        ]
        group_counts: Counter = Counter()
        for r in rows:
            msg = r.get('error_msg') or ''
            matched = False
            for label, pat in _PATTERNS:
                if re.search(pat, msg):
                    group_counts[label] += 1
                    matched = True
                    break
            if not matched:
                group_counts['other'] += 1

        serializable = []
        for r in rows:
            serializable.append({
                'id':            r['id'],
                'updated_at':    str(r['updated_at']),
                'error_msg':     r.get('error_msg'),
                'attempts':      r['attempts'],
                'source_relpath': r.get('source_relpath'),
                'file_type':     r.get('file_type'),
            })

        return {
            'jobs':         serializable,
            'total_failed': len(serializable),
            'error_groups': dict(group_counts),
            'job_type':     job_type,
        }

    @mcp.tool()
    def get_jobs_stale(minutes: int = 30) -> dict:
        """Return AI jobs stuck in 'running' longer than the given number of minutes.

        These are likely orphans from a crashed or killed container.
        """
        rows = db.query(
            """SELECT j.id, j.locked_by, j.locked_at, j.attempts,
                      a.source_relpath
               FROM ai_jobs j
               LEFT JOIN assets a ON a.asset_id = j.target_id
               WHERE j.status = 'running'
                 AND j.locked_at < NOW() - INTERVAL %s MINUTE
               ORDER BY j.locked_at""",
            [minutes],
        )
        serializable = []
        for r in rows:
            serializable.append({
                'id':            r['id'],
                'locked_by':     r.get('locked_by'),
                'locked_at':     str(r['locked_at']) if r.get('locked_at') else None,
                'attempts':      r['attempts'],
                'source_relpath': r.get('source_relpath'),
            })
        return {
            'stale_jobs':    serializable,
            'total_stale':   len(serializable),
            'threshold_min': minutes,
        }

    @mcp.tool()
    def reset_jobs_retryable(
        exclude_patterns: list[str] | None = None,
        dry_run: bool = True,
    ) -> dict:
        """Re-queue failed AI jobs, excluding permanently-failing error patterns.

        dry_run defaults to True — pass dry_run=false to apply the reset.
        exclude_patterns defaults to ["VOB", ".m2v"].
        Returns rows_reset, rows_excluded, and the dry_run flag.
        """
        if exclude_patterns is None:
            exclude_patterns = ['VOB', '.m2v']

        clauses = " AND ".join(
            f"error_msg NOT LIKE %s" for _ in exclude_patterns
        )
        like_params = [f'%{p}%' for p in exclude_patterns]

        count_sql = (
            "SELECT COUNT(*) AS n FROM ai_jobs "
            "WHERE status = 'failed'"
            + (f" AND {clauses}" if clauses else "")
        )
        excluded_sql = (
            "SELECT COUNT(*) AS n FROM ai_jobs "
            "WHERE status = 'failed'"
            + (
                " AND NOT (" + " AND ".join(f"error_msg NOT LIKE %s" for _ in exclude_patterns) + ")"
                if clauses else ""
            )
        )

        count_row = db.query_one(count_sql, like_params)
        rows_to_reset = count_row['n'] if count_row else 0

        excl_row = db.query_one(excluded_sql, like_params)
        rows_excluded = excl_row['n'] if excl_row else 0

        if dry_run:
            return {
                'rows_reset':    0,
                'rows_would_reset': rows_to_reset,
                'rows_excluded': rows_excluded,
                'dry_run':       True,
                'exclude_patterns': exclude_patterns,
            }

        update_sql = (
            "UPDATE ai_jobs SET status='queued', attempts=0, error_msg=NULL "
            "WHERE status = 'failed'"
            + (f" AND {clauses}" if clauses else "")
        )
        rows_reset = db.execute(update_sql, like_params)
        return {
            'rows_reset':    rows_reset,
            'rows_excluded': rows_excluded,
            'dry_run':       False,
            'exclude_patterns': exclude_patterns,
        }
