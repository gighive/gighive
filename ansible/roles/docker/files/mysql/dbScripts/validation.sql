USE music_db;
SHOW TABLES;
-- 1. Count the total number of Jam Sessions imported
SELECT COUNT(*) AS total_jam_sessions FROM Jam_Sessions;

-- 2. Count the total number of Songs imported
SELECT COUNT(*) AS total_songs FROM Songs;

-- 3. Count the total number of Files imported
SELECT COUNT(*) AS total_files FROM Files;

-- 4. Verify the number of Songs per Jam Session
SELECT js.jam_session_id, js.name, js.date, COUNT(jss.song_id) AS song_count
FROM Jam_Sessions js
LEFT JOIN jam_session_songs jss ON js.jam_session_id = jss.jam_session_id
GROUP BY js.jam_session_id, js.name, js.date
ORDER BY js.date DESC
LIMIT 10;  -- Display 10 most recent jam sessions

-- 5. Verify the number of Files per Song
SELECT s.song_id, s.title, COUNT(sf.file_id) AS file_count
FROM Songs s
LEFT JOIN song_files sf ON s.song_id = sf.song_id
GROUP BY s.song_id, s.title
ORDER BY file_count DESC
LIMIT 10;  -- Display 10 songs with the most files

-- 6. Identify Songs without associated Files
SELECT s.song_id, s.title, js.name AS jam_session, js.date
FROM Songs s
LEFT JOIN song_files sf ON s.song_id = sf.song_id
LEFT JOIN Jam_Sessions js ON s.jam_session_id = js.jam_session_id
WHERE sf.file_id IS NULL
ORDER BY js.date DESC
LIMIT 10;

-- 7. Identify Files not linked to any Song
SELECT f.file_id, f.file_path, f.file_type
FROM Files f
LEFT JOIN song_files sf ON f.file_id = sf.file_id
WHERE sf.song_id IS NULL
LIMIT 10;

-- 8. Sample Data: Recent Jam Sessions with Songs and Files
SELECT js.name AS jam_session, js.date, s.title AS song, f.file_path, f.file_type
FROM Jam_Sessions js
LEFT JOIN jam_session_songs jss ON js.jam_session_id = jss.jam_session_id
LEFT JOIN Songs s ON jss.song_id = s.song_id
LEFT JOIN song_files sf ON s.song_id = sf.song_id
LEFT JOIN Files f ON sf.file_id = f.file_id
ORDER BY js.date DESC
LIMIT 10;

