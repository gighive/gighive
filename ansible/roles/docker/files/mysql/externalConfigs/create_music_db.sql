DROP DATABASE IF EXISTS music_db;
CREATE DATABASE music_db DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE music_db;  -- ensure subsequent statements target music_db

/****************************
 * Canonical core entities  *
 ****************************/
CREATE TABLE assets (
    asset_id INT PRIMARY KEY AUTO_INCREMENT,
    checksum_sha256 CHAR(64) NULL,
    file_type ENUM('audio','video') NOT NULL,
    file_ext VARCHAR(16) NULL,
    source_relpath VARCHAR(4096) NULL,
    size_bytes BIGINT NULL,
    mime_type VARCHAR(255) NULL,
    duration_seconds INT NULL,
    media_info JSON NULL,
    media_info_tool VARCHAR(255) NULL,
    delete_token_hash CHAR(64) NULL,
    media_created_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT uq_assets_checksum UNIQUE (checksum_sha256)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE events (
    event_id INT PRIMARY KEY AUTO_INCREMENT,
    event_date DATE NOT NULL,
    org_name VARCHAR(128) NOT NULL DEFAULT 'default',
    event_type ENUM('band','wedding','other') DEFAULT NULL,
    title VARCHAR(255) NULL,
    published_at DATETIME DEFAULT NULL,
    duration_seconds INT NULL,
    cover_image_url VARCHAR(1024) NULL,
    location VARCHAR(255) NULL,
    description TEXT NULL,
    summary TEXT NULL,
    keywords TEXT NULL,
    rating DECIMAL(2,1) NULL,
    explicit TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT uq_events_date_org UNIQUE (event_date, org_name)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE event_items (
    event_item_id INT PRIMARY KEY AUTO_INCREMENT,
    event_id INT NOT NULL,
    asset_id INT NOT NULL,
    item_type ENUM('song','loop','clip','highlight') NOT NULL DEFAULT 'clip',
    label VARCHAR(255) NULL,
    position INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_event_items_event FOREIGN KEY (event_id) REFERENCES events(event_id) ON DELETE CASCADE,
    CONSTRAINT fk_event_items_asset FOREIGN KEY (asset_id) REFERENCES assets(asset_id) ON DELETE CASCADE,
    CONSTRAINT uq_event_items_event_asset UNIQUE (event_id, asset_id)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

/********************
 * People           *
 ********************/
CREATE TABLE participants (
    participant_id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(255) NOT NULL UNIQUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE event_participants (
    event_id INT NOT NULL,
    participant_id INT NOT NULL,
    role VARCHAR(255) NULL,
    PRIMARY KEY (event_id, participant_id),
    FOREIGN KEY (event_id) REFERENCES events(event_id) ON DELETE CASCADE,
    FOREIGN KEY (participant_id) REFERENCES participants(participant_id) ON DELETE CASCADE
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

/********************
 * Auth             *
 ********************/
CREATE TABLE users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(255) NOT NULL UNIQUE,
  password_hash CHAR(60) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 0,
  activation_token CHAR(32) NULL,
  reset_token CHAR(32) NULL,
  reset_expires DATETIME NULL,
  failed_logins INT NOT NULL DEFAULT 0,
  locked_until DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

/****************************
 * AI Platform              *
 ****************************/
CREATE TABLE IF NOT EXISTS ai_jobs (
  id          BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT PRIMARY KEY,
  job_type    VARCHAR(64)      NOT NULL,
  target_type ENUM('asset','event','event_item','participant') NOT NULL,
  target_id   BIGINT UNSIGNED  NOT NULL,
  params_json JSON             NULL,
  status      ENUM('queued','running','done','failed') NOT NULL DEFAULT 'queued',
  priority    SMALLINT         NOT NULL DEFAULT 100,
  attempts    SMALLINT         NOT NULL DEFAULT 0,
  locked_by   VARCHAR(128)     NULL,
  locked_at   DATETIME         NULL,
  error_msg   TEXT             NULL,
  created_at  DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_ai_jobs_claim  (status, job_type, priority, created_at),
  KEY idx_ai_jobs_target (target_type, target_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS helper_runs (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  helper_id    VARCHAR(64)     NOT NULL,
  job_id       BIGINT UNSIGNED NOT NULL,
  version      VARCHAR(32)     NULL,
  params_json  JSON            NULL,
  status       ENUM('running','done','failed') NOT NULL DEFAULT 'running',
  error_msg    TEXT            NULL,
  metrics_json JSON            NULL,
  started_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  finished_at  DATETIME        NULL,
  KEY idx_helper_runs_job    (job_id),
  KEY idx_helper_runs_helper (helper_id),
  CONSTRAINT fk_helper_runs_job FOREIGN KEY (job_id) REFERENCES ai_jobs (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS derived_assets (
  id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  run_id           BIGINT UNSIGNED NOT NULL,
  asset_type       VARCHAR(64)     NOT NULL,
  storage_locator  VARCHAR(512)    NOT NULL,
  mime_type        VARCHAR(128)    NULL,
  created_at       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_derived_assets_run (run_id),
  CONSTRAINT fk_derived_assets_run FOREIGN KEY (run_id) REFERENCES helper_runs (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

/****************************
 * Tagging                  *
 ****************************/
CREATE TABLE IF NOT EXISTS tags (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  namespace  VARCHAR(64)  NOT NULL,
  name       VARCHAR(128) NOT NULL,
  created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_tags_ns_name (namespace, name),
  KEY idx_tags_namespace (namespace)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS taggings (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  tag_id        BIGINT UNSIGNED NOT NULL,
  target_type   ENUM('asset','event','event_item','segment') NOT NULL,
  target_id     BIGINT UNSIGNED NOT NULL,
  start_seconds FLOAT          NULL,
  end_seconds   FLOAT          NULL,
  confidence    FLOAT          NULL,
  source        ENUM('ai','human') NOT NULL DEFAULT 'ai',
  run_id        BIGINT UNSIGNED NULL,
  created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_taggings_tag_target (tag_id, target_type, target_id),
  KEY idx_taggings_target     (target_type, target_id),
  KEY idx_taggings_run        (run_id),
  KEY idx_taggings_source     (source),
  CONSTRAINT fk_taggings_tag FOREIGN KEY (tag_id) REFERENCES tags (id),
  CONSTRAINT fk_taggings_run FOREIGN KEY (run_id) REFERENCES helper_runs (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
