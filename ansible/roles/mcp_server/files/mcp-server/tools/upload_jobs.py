"""
tools/upload_jobs.py — Upload job state tool.

Tools:
  get_jobs_upload_ids   — list upload job IDs with status and file counts
  get_jobs_upload_state — reconcile upload job state from DB (pure SQL)
"""

from __future__ import annotations

import db


def register(mcp) -> None:

    @mcp.tool()
    def get_jobs_upload_ids(limit: int = 50) -> list[dict]:
        """List upload jobs with their job_id, status, file counts, and timestamps.

        Use this to discover job_ids for use with get_jobs_upload_state.
        Returns jobs ordered by most recent first.
        """
        rows = db.query(
            "SELECT job_id, status, total_files, started_at, completed_at "
            "FROM upload_jobs ORDER BY started_at DESC LIMIT %s",
            [limit],
        )
        return [
            {
                'job_id':       r['job_id'],
                'status':       r.get('status'),
                'total_files':  r.get('total_files'),
                'started_at':   str(r['started_at']) if r.get('started_at') else None,
                'completed_at': str(r['completed_at']) if r.get('completed_at') else None,
            }
            for r in rows
        ]

    @mcp.tool()
    def get_jobs_upload_state(job_id: str) -> dict:
        """Reconcile upload job state from the database for a given job_id.

        Buckets file states into: pending, done, already_present, failed.
        Pending includes files in 'pending' or 'uploading' state.
        Done includes 'db_done', 'thumbnail_done', and 'uploaded'.
        """
        job = db.query_one(
            "SELECT job_id, status, total_files, started_at, completed_at "
            "FROM upload_jobs WHERE job_id = %s",
            [job_id],
        )
        if job is None:
            return {
                'job_id':  job_id,
                'error':   f"No upload job found with job_id={job_id!r}",
                'pending': 0,
                'done':    0,
                'already_present': 0,
                'failed':  0,
            }

        rows = db.query(
            "SELECT state, COUNT(*) AS n "
            "FROM upload_job_files WHERE job_id = %s GROUP BY state",
            [job_id],
        )
        counts: dict[str, int] = {r['state']: r['n'] for r in rows}

        pending         = counts.get('pending', 0) + counts.get('uploading', 0)
        done            = counts.get('db_done', 0) + counts.get('thumbnail_done', 0) + counts.get('uploaded', 0)
        already_present = counts.get('already_present', 0)
        failed          = counts.get('failed', 0)

        return {
            'job_id':          job_id,
            'job_status':      job.get('status'),
            'total_files':     job.get('total_files'),
            'started_at':      str(job['started_at']) if job.get('started_at') else None,
            'completed_at':    str(job['completed_at']) if job.get('completed_at') else None,
            'pending':         pending,
            'done':            done,
            'already_present': already_present,
            'failed':          failed,
            'state_breakdown': counts,
        }
