USE music_db;

-- Disable foreign key checks to safely truncate all tables
SET FOREIGN_KEY_CHECKS=0;

TRUNCATE TABLE song_files;
TRUNCATE TABLE files;
TRUNCATE TABLE session_songs;
TRUNCATE TABLE songs;
TRUNCATE TABLE session_musicians;
TRUNCATE TABLE musicians;
TRUNCATE TABLE sessions;
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
 * Load data (standardized schema)
 * Notes:
 *  - sessions.title replaces name
 *  - sessions.published_at replaces pub_date
 *  - sessions.duration_seconds is INT (convert HH:MM:SS or MM:SS)
 *  - sessions.cover_image_url replaces image_path
 *  - sessions has no 'crew' column (normalized via session_musicians); we ignore CSV crew
 *  - songs.duration_seconds replaces duration TIME
 *  - session_songs has optional position, duration_seconds (not required by CSV)
 ********************************************************************************************/

-- 1) Sessions
LOAD DATA INFILE '/var/lib/mysql-files/sessions.csv'
INTO TABLE sessions
FIELDS TERMINATED BY ',' OPTIONALLY ENCLOSED BY '"' ESCAPED BY '\\'
LINES TERMINATED BY '\r\n'
IGNORE 1 LINES
(
  session_id,
  @name,
  date,
  @org_name,
  @event_type,
  description,
  @image_path,
  @crew,         -- ignored (normalized elsewhere)
  location,
  @rating_raw,
  summary,
  @pub_date,
  explicit,
  @duration,
  keywords
)
SET
  title = NULLIF(@name, ''),
  cover_image_url = NULLIF(@image_path, ''),
  published_at = NULLIF(@pub_date, ''),
  org_name = COALESCE(NULLIF(@org_name, ''), 'default'),
  event_type = NULLIF(@event_type, ''),

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

-- 2) Musicians
LOAD DATA INFILE '/var/lib/mysql-files/musicians.csv'
INTO TABLE musicians
FIELDS TERMINATED BY ',' OPTIONALLY ENCLOSED BY '"' ESCAPED BY '\\'
LINES TERMINATED BY '\r\n'
IGNORE 1 LINES
(musician_id, name);

-- 3) Session ↔ Musician
LOAD DATA INFILE '/var/lib/mysql-files/session_musicians.csv'
INTO TABLE session_musicians
FIELDS TERMINATED BY ','
LINES TERMINATED BY '\r\n'
IGNORE 1 LINES
(session_id, musician_id);

-- 4) Songs
LOAD DATA INFILE '/var/lib/mysql-files/songs.csv'
INTO TABLE songs
FIELDS TERMINATED BY ',' OPTIONALLY ENCLOSED BY '"' ESCAPED BY '\\'
LINES TERMINATED BY '\r\n'
IGNORE 1 LINES
(song_id, title, type, @duration, @genre_id, @style_id)
SET
  duration_seconds = CASE
    WHEN @duration IS NULL OR @duration = '' THEN NULL
    WHEN @duration REGEXP '^[0-9]{1,2}:[0-9]{2}:[0-9]{2}$' THEN TIME_TO_SEC(STR_TO_DATE(@duration, '%H:%i:%s'))
    WHEN @duration REGEXP '^[0-9]{1,2}:[0-9]{2}$' THEN TIME_TO_SEC(STR_TO_DATE(CONCAT('00:', @duration), '%H:%i:%s'))
    WHEN @duration REGEXP '^[0-9]+$' THEN CAST(@duration AS UNSIGNED)
    ELSE NULL
  END,
  genre_id = NULLIF(@genre_id, ''),
  style_id = NULLIF(@style_id, '');

-- 5) Session ↔ Song
LOAD DATA INFILE '/var/lib/mysql-files/session_songs.csv'
INTO TABLE session_songs
FIELDS TERMINATED BY ','
LINES TERMINATED BY '\r\n'
IGNORE 1 LINES
(session_id, song_id);

-- 6) Files
LOAD DATA INFILE '/var/lib/mysql-files/files.csv'
INTO TABLE files
FIELDS TERMINATED BY ',' OPTIONALLY ENCLOSED BY '"' ESCAPED BY '\\'
LINES TERMINATED BY '\r\n'
IGNORE 1 LINES
(file_id, file_name, file_type, @duration_seconds)
SET duration_seconds = NULLIF(@duration_seconds, '');

-- 7) Song ↔ File
LOAD DATA INFILE '/var/lib/mysql-files/song_files.csv'
INTO TABLE song_files
FIELDS TERMINATED BY ','
LINES TERMINATED BY '\r\n'
IGNORE 1 LINES
(song_id, file_id);

