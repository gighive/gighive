-- Media deletion diagnostics
--
-- Purpose:
--   Identify where a "stubborn" Media Library row is coming from after deleting a media file.
--   This focuses on the session/song rows and their link to files through song_files.
--
-- Usage (example):
--   mysql -u root -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE" < media_delete_diagnostics.sql
--
-- Adjust the target title here:
SET @title := 'gighiveSetup';

SELECT '=== songs row(s) ===' AS section;
SELECT song_id, title
FROM songs
WHERE title = @title;

SELECT '=== sessions + session_songs row(s) ===' AS section;
SELECT sesh.session_id, sesh.date, sesh.org_name, s.song_id, s.title
FROM sessions sesh
JOIN session_songs ss ON ss.session_id = sesh.session_id
JOIN songs s ON s.song_id = ss.song_id
WHERE s.title = @title
ORDER BY sesh.date DESC;

SELECT '=== song_files links (should be empty after file deletion) ===' AS section;
SELECT sf.song_id, sf.file_id
FROM song_files sf
JOIN songs s ON s.song_id = sf.song_id
WHERE s.title = @title;

SELECT '=== files rows referenced by song_files (should be empty if cascade worked) ===' AS section;
SELECT f.*
FROM files f
WHERE f.file_id IN (
  SELECT sf.file_id
  FROM song_files sf
  JOIN songs s ON s.song_id = sf.song_id
  WHERE s.title = @title
);

SELECT '=== joined view (shows whether file_id is actually NULL) ===' AS section;
SELECT
  sesh.session_id,
  sesh.date,
  sesh.org_name,
  s.song_id,
  s.title,
  sf.file_id AS song_files_file_id,
  f.file_id  AS files_file_id,
  f.file_type,
  f.file_name,
  f.source_relpath,
  f.checksum_sha256
FROM sessions sesh
JOIN session_songs ss ON ss.session_id = sesh.session_id
JOIN songs s ON s.song_id = ss.song_id
LEFT JOIN song_files sf ON sf.song_id = s.song_id
LEFT JOIN files f ON f.file_id = sf.file_id
WHERE s.title = @title
ORDER BY sesh.date DESC;
