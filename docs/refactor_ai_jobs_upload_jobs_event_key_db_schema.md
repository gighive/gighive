# DB Schema: ai_jobs source, upload_jobs tables, event_key

**Date:** 2026-06-05  
**Status:** Not started  
**Prerequisite for:**
- `docs/refactor_ai_jobs_new_column_source.md`
- `docs/refactor_upload_jobs_from_json_to_db.md`
- `docs/refactor_ensure_event_add_event_key.md`

---

## Overview

Three independent schema additions that are all purely additive (no DROP, no destructive
MODIFY, no backfill except `event_key`). Applied in a single manual session on each
environment before implementing the PHP code changes in the three refactor docs above.

`create_music_db.sql` is also updated with all three additions for future clean installs.

---

## Manual Apply Script (existing envs: lab, staging, prod)

Connect to the MySQL container:

```bash
docker exec -i <mysql_container> mysql -u root -p<password> music_db
```

Then run the following in order:

```sql
-- =============================================================
-- 1. ai_jobs: add source column (nullable, no backfill needed)
-- =============================================================
ALTER TABLE ai_jobs
    ADD COLUMN source VARCHAR(64) NULL AFTER job_type;

-- =============================================================
-- 2. upload_jobs + upload_job_files: two new tables
--    Nothing references these until Phase 2 of the upload_jobs
--    refactor; safe to create before any code lands.
-- =============================================================
CREATE TABLE IF NOT EXISTS upload_jobs (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    job_id       VARCHAR(64)  NOT NULL,
    job_type     VARCHAR(32)  NOT NULL DEFAULT 'manifest_import',
    status       VARCHAR(32)  NOT NULL DEFAULT 'in_progress',
    total_files  INT UNSIGNED NOT NULL DEFAULT 0,
    started_at   DATETIME     NOT NULL,
    completed_at DATETIME     NULL,
    UNIQUE KEY uq_upload_jobs_job_id (job_id),
    INDEX        idx_upload_jobs_started (started_at),
    INDEX        idx_upload_jobs_status  (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS upload_job_files (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    job_id          VARCHAR(64)  NOT NULL,
    checksum_sha256 CHAR(64)     NOT NULL,
    source_relpath  VARCHAR(512) NOT NULL,
    file_type       VARCHAR(16)  NULL,
    size_bytes      BIGINT UNSIGNED NULL,
    state           VARCHAR(32)  NOT NULL DEFAULT 'pending',
    media_state     VARCHAR(16)  NULL,
    thumbnail_state VARCHAR(16)  NULL,
    db_state        VARCHAR(16)  NULL,
    file_name       VARCHAR(512) NOT NULL DEFAULT '',
    error           TEXT         NULL,
    last_error      TEXT         NULL,
    retryable       TINYINT(1)   NULL,
    failure_code    VARCHAR(64)  NULL,
    last_failed_at  DATETIME     NULL,
    retry_count     TINYINT UNSIGNED NOT NULL DEFAULT 0,
    diagnostics     JSON         NULL,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_upload_job_file (job_id, checksum_sha256),
    INDEX idx_upload_job_files_job_id (job_id),
    INDEX idx_upload_job_files_state  (job_id, state),
    CONSTRAINT fk_upload_job_files_job FOREIGN KEY (job_id) REFERENCES upload_jobs(job_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================
-- 3. events: add event_key and backfill existing rows
--    Run last — this is the only step that modifies existing rows.
-- =============================================================
ALTER TABLE events ADD COLUMN event_key CHAR(36) NULL AFTER event_id;
UPDATE events SET event_key = UUID() WHERE event_key IS NULL;
ALTER TABLE events MODIFY COLUMN event_key CHAR(36) NOT NULL;
ALTER TABLE events ADD CONSTRAINT uq_events_key UNIQUE (event_key);
```

---

## Verification

Run after the script completes. All four must pass before proceeding to any PHP code
changes in the three refactor docs.

```sql
SHOW COLUMNS FROM ai_jobs LIKE 'source';
-- expected: VARCHAR(64) / YES / NULL

SHOW TABLES LIKE 'upload_job%';
-- expected: upload_job_files, upload_jobs

SELECT COUNT(*) FROM events WHERE event_key IS NULL;
-- expected: 0

SELECT event_key, COUNT(*) AS n
FROM events GROUP BY event_key HAVING n > 1;
-- expected: 0 rows
```

---

## create_music_db.sql Changes

For fresh installs, `create_music_db.sql` is updated directly (no migration script needed
— zero users, reload from CSV). Three additions:

| Table | Change |
|-------|--------|
| `events` | `event_key CHAR(36) NOT NULL` after `event_id`; `CONSTRAINT uq_events_key UNIQUE (event_key)` |
| `ai_jobs` | `source VARCHAR(64) NULL` after `job_type` |
| `upload_jobs` | New table — Upload Jobs section |
| `upload_job_files` | New table — Upload Jobs section |

---

## Related Docs

- `docs/refactor_ai_jobs_new_column_source.md` — PHP code changes for `source` column
- `docs/refactor_upload_jobs_from_json_to_db.md` — PHP code changes for upload job state (Phases 2–4)
- `docs/refactor_ensure_event_add_event_key.md` — PHP code changes for `event_key` stable identity
