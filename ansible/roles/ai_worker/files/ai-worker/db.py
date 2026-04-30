"""
db.py — MySQL connection and job/run lifecycle helpers.
"""

import json
import logging
import os

import mysql.connector

logger = logging.getLogger(__name__)


def get_connection():
    """Create and return a new mysql-connector connection from env vars."""
    return mysql.connector.connect(
        host=os.getenv('DB_HOST', 'mysqlServer'),
        database=os.getenv('MYSQL_DATABASE', 'music_db'),
        user=os.getenv('MYSQL_USER', 'appuser'),
        password=os.getenv('MYSQL_PASSWORD', ''),
        charset='utf8mb4',
        autocommit=False,
        connection_timeout=10,
    )


def reset_stale_running_jobs(conn, worker_id: str) -> int:
    """On startup, reset any 'running' jobs NOT owned by this worker back to 'queued'.
    Handles jobs orphaned by a previous crashed/killed container."""
    cur = conn.cursor()
    try:
        cur.execute(
            "UPDATE ai_jobs SET status='queued', locked_by=NULL, locked_at=NULL, "
            "updated_at=NOW() WHERE status='running' AND locked_by != %s",
            (worker_id,),
        )
        conn.commit()
        count = cur.rowcount
        if count:
            logger.info('Reset %d stale running job(s) to queued on startup', count)
        return count
    finally:
        cur.close()


def claim_next_job(conn, job_type: str, worker_id: str) -> dict | None:
    """Atomically claim the next queued job of job_type. Returns job row or None."""
    conn.start_transaction()
    cur = conn.cursor(dictionary=True)
    try:
        cur.execute(
            "SELECT * FROM ai_jobs WHERE status='queued' AND job_type=%s "
            "ORDER BY priority ASC, created_at ASC LIMIT 1 FOR UPDATE SKIP LOCKED",
            (job_type,),
        )
        job = cur.fetchone()
        if not job:
            conn.rollback()
            return None
        cur.execute(
            "UPDATE ai_jobs SET status='running', locked_by=%s, locked_at=NOW(), "
            "attempts=attempts+1 WHERE id=%s",
            (worker_id, job['id']),
        )
        conn.commit()
        return job
    except Exception:
        conn.rollback()
        raise
    finally:
        cur.close()


def create_helper_run(conn, job: dict, helper_id: str, version: str = '1.0.0') -> dict:
    """Insert a helper_runs row with status='running'. Returns {'id': run_id}."""
    cur = conn.cursor()
    try:
        cur.execute(
            "INSERT INTO helper_runs (helper_id, job_id, version, params_json, status) "
            "VALUES (%s, %s, %s, %s, 'running')",
            (helper_id, job['id'], version, job.get('params_json')),
        )
        conn.commit()
        return {'id': cur.lastrowid}
    finally:
        cur.close()


def mark_run_done(conn, run_id: int, metrics: dict | None = None) -> None:
    """Mark a helper_run as done with optional metrics JSON."""
    cur = conn.cursor()
    try:
        cur.execute(
            "UPDATE helper_runs SET status='done', finished_at=NOW(), metrics_json=%s WHERE id=%s",
            (json.dumps(metrics) if metrics else None, run_id),
        )
        conn.commit()
    finally:
        cur.close()


def mark_run_failed(conn, run_id: int, error_msg: str) -> None:
    """Clean up derived_assets rows and mark a helper_run as failed."""
    cur = conn.cursor()
    try:
        cur.execute("DELETE FROM derived_assets WHERE run_id=%s", (run_id,))
        cur.execute(
            "UPDATE helper_runs SET status='failed', finished_at=NOW(), error_msg=%s WHERE id=%s",
            (error_msg[:65535], run_id),
        )
        conn.commit()
    finally:
        cur.close()


def mark_job_done(conn, job_id: int) -> None:
    cur = conn.cursor()
    try:
        cur.execute(
            "UPDATE ai_jobs SET status='done', updated_at=NOW() WHERE id=%s", (job_id,)
        )
        conn.commit()
    finally:
        cur.close()


def mark_job_failed(conn, job_id: int, error_msg: str, no_retry: bool = False) -> None:
    """
    Mark a job failed.

    no_retry=True  — permanent failure (e.g. MediaDecodeError); status='failed'.
    no_retry=False — transient failure; reset to 'queued' so the next claim poll
                     picks it up (dead-letter check on MAX_ATTEMPTS is in worker.py).
    """
    new_status = 'failed' if no_retry else 'queued'
    cur = conn.cursor()
    try:
        cur.execute(
            "UPDATE ai_jobs SET status=%s, error_msg=%s, locked_by=NULL, locked_at=NULL, "
            "updated_at=NOW() WHERE id=%s",
            (new_status, error_msg[:65535], job_id),
        )
        conn.commit()
    finally:
        cur.close()
