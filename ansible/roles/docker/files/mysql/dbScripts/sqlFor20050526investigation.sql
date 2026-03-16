docker exec -i mysqlServer mysql -t -u "$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE" <<'SQL'
SELECT '=== sessions rows ===' AS section;
SELECT
  session_id,
  date,
  org_name,
  rating,
  location,
  LEFT(summary, 120) AS summary_preview
FROM sessions
WHERE date = '2005-05-26'
  AND org_name = 'StormPigs'
ORDER BY session_id;

SELECT '=== session -> songs ===' AS section;
SELECT
  sesh.session_id,
  ss.song_id,
  s.title
FROM sessions sesh
LEFT JOIN session_songs ss
  ON ss.session_id = sesh.session_id
LEFT JOIN songs s
  ON s.song_id = ss.song_id
WHERE sesh.date = '2005-05-26'
  AND sesh.org_name = 'StormPigs'
ORDER BY sesh.session_id, ss.song_id;

SELECT '=== session -> songs -> files (diagnostic) ===' AS section;
SELECT
  sesh.session_id,
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

SELECT '=== current Media Library-style inner-join result ===' AS section;
SELECT
  sesh.session_id,
  s.song_id,
  f.file_id,
  sesh.date,
  sesh.org_name,
  s.title,
  f.file_name
FROM sessions sesh
JOIN session_songs ss
  ON ss.session_id = sesh.session_id
JOIN songs s
  ON s.song_id = ss.song_id
JOIN song_files sf
  ON sf.song_id = s.song_id
JOIN files f
  ON f.file_id = sf.file_id
WHERE sesh.date = '2005-05-26'
  AND sesh.org_name = 'StormPigs'
ORDER BY sesh.session_id, s.song_id, f.file_id;
SQL
