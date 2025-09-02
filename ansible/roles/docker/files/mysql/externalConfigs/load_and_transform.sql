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

-- then your existing LOAD DATA INFILE statements…

-- 1) Jam Sessions (with quoted fields, NULL fix, and CRLF terminators)
LOAD DATA INFILE '/var/lib/mysql-files/sessions.csv'
INTO TABLE sessions
FIELDS 
  TERMINATED BY ',' 
  OPTIONALLY ENCLOSED BY '"' 
  ESCAPED BY '\\'
LINES TERMINATED BY '\r\n'
IGNORE 1 LINES
(
  session_id,
  name,
  date,
  description,
  image_path,
  crew,
  location,
  rating,
  summary,
  @pub_date,
  explicit,
  @duration,
  keywords
)
SET
  pub_date = NULLIF(@pub_date, ''),
  duration = NULLIF(@duration, '');

-- 2) Musicians
LOAD DATA INFILE '/var/lib/mysql-files/musicians.csv'
INTO TABLE musicians
FIELDS TERMINATED BY ',' 
OPTIONALLY ENCLOSED BY '"' 
ESCAPED BY '\\'
LINES TERMINATED BY '\r\n'
IGNORE 1 LINES
(musician_id, name);

-- 3) Jam Session ↔ Musician
LOAD DATA INFILE '/var/lib/mysql-files/session_musicians.csv'
INTO TABLE session_musicians
FIELDS TERMINATED BY ',' 
LINES TERMINATED BY '\r\n'
IGNORE 1 LINES
(session_id, musician_id);

-- 4) Songs & Loops
LOAD DATA INFILE '/var/lib/mysql-files/songs.csv'
INTO TABLE songs
FIELDS TERMINATED BY ',' 
OPTIONALLY ENCLOSED BY '"' 
ESCAPED BY '\\'
LINES TERMINATED BY '\r\n'
IGNORE 1 LINES
(song_id, title, type);

-- 5) Jam Session ↔ Song
LOAD DATA INFILE '/var/lib/mysql-files/session_songs.csv'
INTO TABLE session_songs
FIELDS TERMINATED BY ',' 
LINES TERMINATED BY '\r\n'
IGNORE 1 LINES
(session_id, song_id);

-- 6) Files
LOAD DATA INFILE '/var/lib/mysql-files/files.csv'
INTO TABLE files
FIELDS TERMINATED BY ',' 
OPTIONALLY ENCLOSED BY '"' 
ESCAPED BY '\\'
LINES TERMINATED BY '\r\n'
IGNORE 1 LINES
(file_id, file_name, file_type);

-- 7) Song ↔ File
LOAD DATA INFILE '/var/lib/mysql-files/song_files.csv'
INTO TABLE song_files
FIELDS TERMINATED BY ',' 
LINES TERMINATED BY '\r\n'
IGNORE 1 LINES
(song_id, file_id);

