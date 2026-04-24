USE music_db;

-- Disable foreign key checks to safely truncate all tables
SET FOREIGN_KEY_CHECKS=0;

TRUNCATE TABLE event_participants;
TRUNCATE TABLE event_items;
TRUNCATE TABLE events;
TRUNCATE TABLE assets;
TRUNCATE TABLE participants;
TRUNCATE TABLE genres;
TRUNCATE TABLE styles;

SET FOREIGN_KEY_CHECKS=1;

-- Seed genres
INSERT IGNORE INTO genres (name) VALUES
 ('Rock'),('Jazz'),('Blues'),('Funk'),('Hip-Hop'),
 ('Classical'),('Metal'),('Pop'),('Folk'),
 ('Electronic'),('Reggae'),('Country'),
 ('Latin'),('R&B'),('Alternative'),('Experimental');

-- Seed styles
INSERT IGNORE INTO styles (name) VALUES
 ('Acoustic'),('Electric'),('Fusion'),('Improvised'),
 ('Progressive'),('Psychedelic'),('Hard'),
 ('Soft'),('Instrumental'),('Vocal');

/********************************************************************************************
 * Load data into canonical tables
 * Notes:
 *  - events.event_id       <- sessions.session_id  (1:1 identity; explicit ID preserved)
 *  - assets.asset_id       <- files.file_id         (1:1 identity; explicit ID preserved)
 *  - participants.participant_id <- participants.csv participant_id
 *  - event_items built via staging joins: song_files -> songs -> session_songs
 ********************************************************************************************/

-- 1) Events (from sessions.csv; session_id maps 1:1 to event_id)
LOAD DATA INFILE '/var/lib/mysql-files/sessions.csv'
INTO TABLE events
FIELDS TERMINATED BY ',' OPTIONALLY ENCLOSED BY '"' ESCAPED BY '\\'
LINES TERMINATED BY '\r\n'
IGNORE 1 LINES
(
  event_id,
  title,
  event_date,
  @org_name,
  @event_type,
  description,
  @image_path,
  @crew,
  location,
  @rating_raw,
  summary,
  @pub_date,
  explicit,
  @duration,
  keywords
)
SET
  org_name = COALESCE(NULLIF(@org_name, ''), 'default'),
  event_type = NULLIF(@event_type, ''),
  cover_image_url = NULLIF(@image_path, ''),
  published_at = NULLIF(@pub_date, ''),

  rating = CASE
    WHEN @rating_raw IS NULL OR @rating_raw = '' THEN NULL
    ELSE CAST(
      LEAST(
        5,
        GREATEST(
          1,
          COALESCE(
            /* numeric like '3' or '2.5' */
            CASE
              WHEN REPLACE(REPLACE(LOWER(@rating_raw),' ',''),'★','*') REGEXP '^[0-9]+(\\.[0-9]+)?$'
                THEN CAST(REPLACE(REPLACE(LOWER(@rating_raw),' ',''),'★','*') AS DECIMAL(3,1))
              ELSE NULL
            END,
            /* stars + optional half */
            (LENGTH(REPLACE(REPLACE(LOWER(@rating_raw),' ',''),'★','*'))
             - LENGTH(REPLACE(REPLACE(REPLACE(LOWER(@rating_raw),' ',''),'★','*'),'*','')))
            + CASE
                WHEN REPLACE(REPLACE(LOWER(@rating_raw),' ',''),'★','*') REGEXP '(^|[^0-9])1/2([^0-9]|$)|half'
                  THEN 0.5
                ELSE 0
              END
          )
        )
      ) AS DECIMAL(2,1)
    )
  END,

  duration_seconds = CASE
    WHEN @duration REGEXP '^[0-9]{1,2}:[0-9]{2}:[0-9]{2}$' THEN TIME_TO_SEC(STR_TO_DATE(@duration, '%H:%i:%s'))
    WHEN @duration REGEXP '^[0-9]{1,2}:[0-9]{2}$' THEN TIME_TO_SEC(STR_TO_DATE(CONCAT('00:', @duration), '%H:%i:%s'))
    WHEN @duration REGEXP '^[0-9]+$' THEN CAST(@duration AS UNSIGNED)
    ELSE NULL
  END;

-- 2) Participants (from participants.csv, renamed from musicians.csv)
LOAD DATA INFILE '/var/lib/mysql-files/participants.csv'
INTO TABLE participants
FIELDS TERMINATED BY ',' OPTIONALLY ENCLOSED BY '"' ESCAPED BY '\\'
LINES TERMINATED BY '\r\n'
IGNORE 1 LINES
(participant_id, name);

-- 3) Event ↔ Participant (from event_participants.csv, renamed from session_musicians.csv)
LOAD DATA INFILE '/var/lib/mysql-files/event_participants.csv'
INTO TABLE event_participants
FIELDS TERMINATED BY ','
LINES TERMINATED BY '\r\n'
IGNORE 1 LINES
(event_id, participant_id);

-- 4) Assets (from files.csv; file_id maps 1:1 to asset_id)
LOAD DATA INFILE '/var/lib/mysql-files/files.csv'
INTO TABLE assets
FIELDS TERMINATED BY ',' OPTIONALLY ENCLOSED BY '"' ESCAPED BY ''
LINES TERMINATED BY '\r\n'
IGNORE 1 LINES
(asset_id, @file_name, @source_relpath, @checksum_sha256, file_type, @duration_raw, @media_info_raw, @media_info_tool_raw)
SET
  source_relpath = NULLIF(@source_relpath, ''),
  checksum_sha256 = NULLIF(@checksum_sha256, ''),
  duration_seconds = NULLIF(@duration_raw, ''),
  media_info = NULLIF(@media_info_raw, ''),
  media_info_tool = NULLIF(@media_info_tool_raw, ''),
  file_ext = IF(LOCATE('.', @file_name) > 0, SUBSTRING_INDEX(@file_name, '.', -1), NULL);

-- 5) Staging: song metadata (title + type only; used to build event_items labels)
CREATE TEMPORARY TABLE IF NOT EXISTS _stg_songs (
    song_id INT NOT NULL,
    title VARCHAR(255) NULL,
    type VARCHAR(50) NOT NULL DEFAULT 'song'
);

LOAD DATA INFILE '/var/lib/mysql-files/songs.csv'
INTO TABLE _stg_songs
FIELDS TERMINATED BY ',' OPTIONALLY ENCLOSED BY '"' ESCAPED BY '\\'
LINES TERMINATED BY '\r\n'
IGNORE 1 LINES
(song_id, title, type, @skip_dur, @skip_genre, @skip_style);

-- 6) Staging: session → song links
CREATE TEMPORARY TABLE IF NOT EXISTS _stg_session_songs (
    session_id INT NOT NULL,
    song_id INT NOT NULL
);

LOAD DATA INFILE '/var/lib/mysql-files/session_songs.csv'
INTO TABLE _stg_session_songs
FIELDS TERMINATED BY ','
LINES TERMINATED BY '\r\n'
IGNORE 1 LINES
(session_id, song_id);

-- 7) Staging: song → file links
CREATE TEMPORARY TABLE IF NOT EXISTS _stg_song_files (
    song_id INT NOT NULL,
    file_id INT NOT NULL
);

LOAD DATA INFILE '/var/lib/mysql-files/song_files.csv'
INTO TABLE _stg_song_files
FIELDS TERMINATED BY ','
LINES TERMINATED BY '\r\n'
IGNORE 1 LINES
(song_id, file_id);

-- 8) Build event_items via staging join: song_files -> songs -> session_songs
--    asset_id = file_id (1:1 from step 4); event_id = session_id (1:1 from step 1)
INSERT IGNORE INTO event_items (event_id, asset_id, item_type, label, position)
SELECT
    ss.session_id,
    sf.file_id,
    CASE s.type
        WHEN 'song'        THEN 'song'
        WHEN 'loop'        THEN 'loop'
        WHEN 'event_label' THEN 'clip'
        ELSE                    'clip'
    END,
    s.title,
    NULL
FROM _stg_song_files sf
JOIN _stg_songs s ON s.song_id = sf.song_id
JOIN _stg_session_songs ss ON ss.song_id = sf.song_id;

DROP TEMPORARY TABLE IF EXISTS _stg_songs;
DROP TEMPORARY TABLE IF EXISTS _stg_session_songs;
DROP TEMPORARY TABLE IF EXISTS _stg_song_files;
