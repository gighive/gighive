"""
worker.py — Main polling loop. Claims ai_jobs rows and dispatches to helpers.
"""

from __future__ import annotations

import logging
import os
import signal
import socket
import time
import uuid

import db
from adapters.openai_adapter import OpenAIAdapter
from frame_extractor import MediaDecodeError, MediaNotFoundError
from helpers import video_tagger

logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s %(levelname)-8s %(name)s %(message)s',
)
logger = logging.getLogger('worker')

POLL_INTERVAL = int(os.getenv('AI_WORKER_POLL_INTERVAL', '5'))
MAX_ATTEMPTS = int(os.getenv('AI_WORKER_MAX_ATTEMPTS', '3'))
WORKER_CONCURRENCY = max(1, int(os.getenv('AI_WORKER_CONCURRENCY', '1')))
JOB_TYPE = 'categorize_video'

_shutdown = False


def _sigterm_handler(signum, frame):
    global _shutdown
    logger.info('SIGTERM received — finishing current job then exiting')
    _shutdown = True


signal.signal(signal.SIGTERM, _sigterm_handler)

WORKER_ID = f"{socket.gethostname()}:{uuid.uuid4().hex[:8]}"


def _worker_thread(thread_id: int, adapter) -> None:
    worker_label = f"{WORKER_ID}:{thread_id}"
    logger.info('Worker thread %s started', worker_label)
    while not _shutdown:
        conn = None
        job = None
        run_id = None
        try:
            conn = db.get_connection()
            job = db.claim_next_job(conn, JOB_TYPE, worker_label)

            if not job:
                conn.close()
                time.sleep(POLL_INTERVAL)
                continue

            logger.info('Thread %d claimed job id=%s target=%s/%s',
                        thread_id, job['id'], job['target_type'], job['target_id'])

            # Dead-letter check
            if int(job.get('attempts', 1)) > MAX_ATTEMPTS:
                db.mark_job_failed(conn, job['id'], f'Exceeded max attempts ({MAX_ATTEMPTS})', no_retry=True)
                conn.close()
                continue

            run = db.create_helper_run(conn, job, video_tagger.HELPER_ID, video_tagger.HELPER_VERSION)
            run_id = run['id']

            if job['job_type'] == JOB_TYPE:
                video_tagger.run(conn, job, run_id, adapter)
            else:
                raise NotImplementedError(f"Unknown job_type: {job['job_type']}")

            db.mark_job_done(conn, job['id'])
            conn.close()

        except (MediaNotFoundError, MediaDecodeError, ValueError) as exc:
            logger.error('Permanent failure for job %s: %s', job['id'] if job else '?', exc)
            if conn and job:
                if run_id:
                    db.mark_run_failed(conn, run_id, str(exc))
                db.mark_job_failed(conn, job['id'], str(exc), no_retry=True)
            if conn:
                conn.close()

        except Exception as exc:
            logger.exception('Transient error for job %s: %s', job['id'] if job else '?', exc)
            if conn and job:
                if run_id:
                    db.mark_run_failed(conn, run_id, str(exc))
                db.mark_job_failed(conn, job['id'], str(exc), no_retry=False)
            if conn:
                try:
                    conn.close()
                except Exception:
                    pass
            time.sleep(POLL_INTERVAL)

    logger.info('Worker thread %s shut down', worker_label)


def _wait_for_db(max_attempts: int = 12, backoff: int = 5):
    """Retry MySQL connection at startup; gives the DB container time to become ready."""
    for attempt in range(1, max_attempts + 1):
        try:
            return db.get_connection()
        except Exception as exc:
            if attempt >= max_attempts:
                raise
            logger.warning('DB not ready (attempt %d/%d): %s — retrying in %ds',
                           attempt, max_attempts, exc, backoff)
            time.sleep(backoff)


def main():
    if os.getenv('AI_WORKER_ENABLED', 'false').lower() not in ('1', 'true', 'yes'):
        logger.info('AI_WORKER_ENABLED is not true — exiting cleanly')
        return

    logger.info('AI worker starting (id=%s, threads=%d, poll_interval=%ds)',
                WORKER_ID, WORKER_CONCURRENCY, POLL_INTERVAL)

    startup_conn = _wait_for_db()
    db.reset_stale_running_jobs(startup_conn, WORKER_ID)
    startup_conn.close()

    if WORKER_CONCURRENCY <= 1:
        adapter = OpenAIAdapter()
        _worker_thread(0, adapter)
    else:
        from concurrent.futures import ThreadPoolExecutor
        adapters = [OpenAIAdapter() for _ in range(WORKER_CONCURRENCY)]
        with ThreadPoolExecutor(max_workers=WORKER_CONCURRENCY) as ex:
            futures = [ex.submit(_worker_thread, i, adapters[i]) for i in range(WORKER_CONCURRENCY)]
            for f in futures:
                try:
                    f.result()
                except Exception as exc:
                    logger.error('Worker thread raised fatal exception: %s', exc)

    logger.info('AI worker shut down cleanly')


if __name__ == '__main__':
    main()
