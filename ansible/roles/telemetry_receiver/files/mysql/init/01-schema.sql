CREATE DATABASE IF NOT EXISTS installation_telemetry
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE installation_telemetry;

CREATE TABLE IF NOT EXISTS installation_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  event_name VARCHAR(32) NOT NULL,
  app_version VARCHAR(64) NOT NULL,
  install_channel VARCHAR(32) NOT NULL,
  install_method VARCHAR(64) NOT NULL,
  app_flavor VARCHAR(64) NOT NULL,
  install_id VARCHAR(64) NOT NULL,
  event_timestamp DATETIME NOT NULL,
  country_code CHAR(2) DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_install_id (install_id),
  KEY idx_event_name (event_name),
  KEY idx_event_timestamp (event_timestamp),
  KEY idx_country_code (country_code)
);

GRANT ALL PRIVILEGES ON installation_telemetry.* TO 'telemetry_app'@'%';
FLUSH PRIVILEGES;
