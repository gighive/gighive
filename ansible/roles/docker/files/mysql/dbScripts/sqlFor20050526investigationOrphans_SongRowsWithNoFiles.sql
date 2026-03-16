# 27 orphan sessions are junk or expected shells
# the 92 session-song rows with no files:
docker exec -i mysqlServer mysql -t -u "$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE" <<'SQL'
SELECT
  sesh.session_id,
  sesh.date,
  sesh.org_name,
  COUNT(DISTINCT ss.song_id) AS song_count
FROM sessions sesh
LEFT JOIN session_songs ss
  ON ss.session_id = sesh.session_id
GROUP BY sesh.session_id, sesh.date, sesh.org_name
HAVING song_count = 0
ORDER BY sesh.date DESC, sesh.session_id DESC;

SELECT
  sesh.session_id,
  sesh.date,
  sesh.org_name,
  s.song_id,
  s.title,
  COUNT(sf.file_id) AS file_count
FROM sessions sesh
JOIN session_songs ss
  ON ss.session_id = sesh.session_id
JOIN songs s
  ON s.song_id = ss.song_id
LEFT JOIN song_files sf
  ON sf.song_id = s.song_id
GROUP BY sesh.session_id, sesh.date, sesh.org_name, s.song_id, s.title
HAVING file_count = 0
ORDER BY sesh.date DESC, sesh.session_id, s.song_id;
SQL
