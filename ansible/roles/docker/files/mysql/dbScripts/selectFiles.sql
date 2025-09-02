SELECT '===== JAM SESSIONS TABLE =====' AS "";
SELECT COUNT(*) AS "Total Rows in jam_sessions" FROM jam_sessions;
SELECT '' AS "\n";
SELECT * FROM jam_sessions LIMIT 300;
SELECT '' AS "\n\n";

SELECT '===== SONGS TABLE =====' AS "";
SELECT COUNT(*) AS "Total Rows in songs" FROM songs;
SELECT '' AS "\n";
SELECT * FROM songs LIMIT 300;
SELECT '' AS "\n\n";

SELECT '===== FILES TABLE =====' AS "";
SELECT COUNT(*) AS "Total Rows in files" FROM files;
SELECT '' AS "\n";
SELECT * FROM files LIMIT 300;
SELECT '' AS "\n\n";

SELECT '===== JAM SESSION SONGS TABLE =====' AS "";
SELECT COUNT(*) AS "Total Rows in jam_session_songs" FROM jam_session_songs;
SELECT '' AS "\n";
SELECT * FROM jam_session_songs LIMIT 300;
SELECT '' AS "\n\n";

SELECT '===== SONG FILES TABLE =====' AS "";
SELECT COUNT(*) AS "Total Rows in song_files" FROM song_files;
SELECT '' AS "\n";
SELECT * FROM song_files LIMIT 300;
SELECT '' AS "\n\n";

