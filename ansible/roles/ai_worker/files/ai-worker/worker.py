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
JOB_TYPE = 'categorize_video'

_shutdown = False


def _sigterm_handler(signum, frame):
    global _shutdown
    logger.info('SIGTERM received — finishing current job then exiting')
    _shutdown = True


signal.signal(signal.SIGTERM, _sigterm_handler)

WORKER_ID = f"{socket.gethostname()}:{uuid.uuid4().hex[:8]}"


def main():
    logger.info('AI worker starting (id=%s, poll_interval=%ds)', WORKER_ID, POLL_INTERVAL)

    startup_conn = db.get_connection()
    db.reset_stale_running_jobs(startup_conn, WORKER_ID)
    startup_conn.close()

    adapter = OpenAIAdapter()

    while not _shutdown:
        conn = None
        job = None
        run_id = None
        try:
            conn = db.get_connection()
            job = db.claim_next_job(conn, JOB_TYPE, WORKER_ID)

            if not job:
                conn.close()
                time.sleep(POLL_INTERVAL)
                continue

            logger.info('Claimed job id=%s target=%s/%s', job['id'], job['target_type'], job['target_id'])

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

    logger.info('AI worker shut down cleanly')


if __name__ == '__main__':
    main()
