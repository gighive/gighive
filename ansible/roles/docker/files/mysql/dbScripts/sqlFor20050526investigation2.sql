docker exec -i mysqlServer mysql -t -u "$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE" <<'SQL'
SELECT '=== count: sessions with no songs ===' AS section;
SELECT COUNT(*) AS orphan_sessions_without_songs
FROM sessions sesh
LEFT JOIN session_songs ss
  ON ss.session_id = sesh.session_id
WHERE ss.session_id IS NULL;
 
SELECT '=== rows: sessions with no songs ===' AS section;
SELECT
  sesh.session_id,
  sesh.date,
  sesh.org_name,
  sesh.rating,
  sesh.location,
  LEFT(COALESCE(sesh.summary, ''), 120) AS summary_preview
FROM sessions sesh
LEFT JOIN session_songs ss
  ON ss.session_id = sesh.session_id
WHERE ss.session_id IS NULL
ORDER BY sesh.date DESC, sesh.session_id DESC;
 
SELECT '=== count: session-song rows with no files ===' AS section;
SELECT COUNT(*) AS session_song_rows_without_files
FROM sessions sesh
JOIN session_songs ss
  ON ss.session_id = sesh.session_id
JOIN songs s
  ON s.song_id = ss.song_id
LEFT JOIN song_files sf
  ON sf.song_id = s.song_id
WHERE sf.song_id IS NULL;
 
SELECT '=== rows: session-song rows with no files ===' AS section;
SELECT
  sesh.session_id,
  sesh.date,
  sesh.org_name,
  ss.song_id,
  s.title
FROM sessions sesh
JOIN session_songs ss
  ON ss.session_id = sesh.session_id
JOIN songs s
  ON s.song_id = ss.song_id
LEFT JOIN song_files sf
  ON sf.song_id = s.song_id
WHERE sf.song_id IS NULL
ORDER BY sesh.date DESC, sesh.session_id, ss.song_id;
 
SELECT '=== count: dangling song_files -> missing files rows ===' AS section;
SELECT COUNT(*) AS dangling_song_file_links
FROM sessions sesh
JOIN session_songs ss
  ON ss.session_id = sesh.session_id
JOIN songs s
  ON s.song_id = ss.song_id
JOIN song_files sf
  ON sf.song_id = s.song_id
LEFT JOIN files f
  ON f.file_id = sf.file_id
WHERE f.file_id IS NULL;
 
SELECT '=== rows: dangling song_files -> missing files rows ===' AS section;
SELECT
  sesh.session_id,
  sesh.date,
  sesh.org_name,
  ss.song_id,
  s.title,
  sf.file_id AS dangling_file_id
FROM sessions sesh
JOIN session_songs ss
  ON ss.session_id = sesh.session_id
JOIN songs s
  ON s.song_id = ss.song_id
JOIN song_files sf
  ON sf.song_id = s.song_id
LEFT JOIN files f
  ON f.file_id = sf.file_id
WHERE f.file_id IS NULL
ORDER BY sesh.date DESC, sesh.session_id, ss.song_id, sf.file_id;
 
SELECT '=== count: visible rows under current Media Library inner-join logic ===' AS section;
SELECT COUNT(*) AS visible_media_library_rows
FROM (
  SELECT
    sesh.session_id,
    s.song_id,
    f.file_id
  FROM sessions sesh
  JOIN session_songs ss
    ON ss.session_id = sesh.session_id
  JOIN songs s
    ON s.song_id = ss.song_id
  JOIN song_files sf
    ON sf.song_id = s.song_id
  JOIN files f
    ON f.file_id = sf.file_id
  GROUP BY sesh.session_id, s.song_id, f.file_id
) t;
 
SELECT '=== rows for 2005-05-26 / StormPigs across all completeness levels ===' AS section;
SELECT
  sesh.session_id,
  sesh.date,
  sesh.org_name,
  ss.song_id,
  s.title,
  sf.file_id AS song_files_file_id,
  f.file_id  AS files_file_id,
  f.file_type,
  f.file_name,
  f.source_relpath
FROM sessions sesh
LEFT JOIN session_songs ss
  ON ss.session_id = sesh.session_id
LEFT JOIN songs s
  ON s.song_id = ss.song_id
LEFT JOIN song_files sf
  ON sf.song_id = s.song_id
LEFT JOIN files f
  ON f.file_id = sf.file_id
WHERE sesh.date = '2005-05-26'
  AND sesh.org_name = 'StormPigs'
ORDER BY sesh.session_id, ss.song_id, sf.file_id;
SQL
