ubuntu@gighive:~$ docker exec -i $CONTAINER mysql -t -u root -p$MYSQL_ROOT_PASSWORD $DATABASE_NAME -e "
SELECT 'sessions' AS section, session_id, org_name, date, title
FROM sessions
WHERE session_id IN (139,141);

SELECT 'session_songs' AS section, session_id, song_id, position
FROM session_songs
WHERE session_id IN (139,141)
ORDER BY session_id, song_id;

SELECT 'files' AS section, file_id, session_id, seq, file_name
FROM files
WHERE session_id IN (139,141)
ORDER BY session_id, seq;
"
mysql: [Warning] Using a password on the command line interface can be insecure.
+----------+------------+-----------+------------+--------------------+
| section  | session_id | org_name  | date       | title              |
+----------+------------+-----------+------------+--------------------+
| sessions |        139 | StormPigs | 2026-03-18 | default 2026-03-18 |
| sessions |        141 | default   | 2026-03-18 | default 2026-03-18 |
+----------+------------+-----------+------------+--------------------+
+---------------+------------+---------+----------+
| section       | session_id | song_id | position |
+---------------+------------+---------+----------+
| session_songs |        139 |     750 |     NULL |
| session_songs |        139 |     751 |     NULL |
| session_songs |        139 |     752 |     NULL |
| session_songs |        139 |     753 |     NULL |
| session_songs |        139 |     754 |     NULL |
| session_songs |        139 |     755 |     NULL |
| session_songs |        139 |     756 |     NULL |
| session_songs |        139 |     757 |     NULL |
| session_songs |        139 |     758 |     NULL |
| session_songs |        139 |     759 |     NULL |
| session_songs |        139 |     760 |     NULL |
| session_songs |        139 |     761 |     NULL |
| session_songs |        141 |     751 |     NULL |
| session_songs |        141 |     752 |     NULL |
| session_songs |        141 |     753 |     NULL |
| session_songs |        141 |     754 |     NULL |
| session_songs |        141 |     755 |     NULL |
| session_songs |        141 |     756 |     NULL |
| session_songs |        141 |     757 |     NULL |
| session_songs |        141 |     758 |     NULL |
| session_songs |        141 |     759 |     NULL |
| session_songs |        141 |     760 |     NULL |
| session_songs |        141 |     762 |     NULL |
| session_songs |        141 |     763 |     NULL |
+---------------+------------+---------+----------+
+---------+---------+------------+------+-------------------------------------------------+
| section | file_id | session_id | seq  | file_name                                       |
+---------+---------+------------+------+-------------------------------------------------+
| files   |     700 |        141 |    1 | StormPigs20260318_1_splittheson.mp4             |
| files   |     701 |        141 |    2 | StormPigs20260318_2_36thstreetboogie.mp4        |
| files   |     702 |        141 |    3 | StormPigs20260318_3_canyoufeelit.mp4            |
| files   |     703 |        141 |    4 | StormPigs20260318_4_babyimamazed.mp4            |
| files   |     704 |        141 |    5 | StormPigs20260318_5_viewfromhalfwaydown.mp4     |
| files   |     705 |        141 |    6 | StormPigs20260318_6_serialkillerwitch.mp4       |
| files   |     706 |        141 |    7 | StormPigs20260318_7_trappedbodycountrymusic.mp4 |
| files   |     707 |        141 |    8 | StormPigs20260318_8_stretchitout.mp4            |
| files   |     708 |        141 |    9 | StormPigs20260318_9_rickieleeinterlude.mp4      |
| files   |     709 |        141 |   10 | StormPigs20260318_10_defyingtheodds.mp4         |
| files   |     710 |        141 |   11 | StormPigs20260318_11_pindrop.mp4                |
| files   |     711 |        141 |   12 | StormPigs20260318_12_fairytale.mp4              |
+---------+---------+------------+------+-------------------------------------------------+
ubuntu@gighive:~$ docker exec -i $CONTAINER mysql -t -u root -p$MYSQL_ROOT_PASSWORD $DATABASE_NAME -e "
START TRANSACTION;

DELETE FROM session_musicians
WHERE session_id IN (139, 141);

DELETE FROM session_songs
WHERE session_id IN (139, 141);

DELETE FROM files
WHERE session_id = 141;

DELETE FROM sessions
WHERE session_id IN (139, 141);

DELETE sf
FROM song_files sf
LEFT JOIN files f
  ON f.file_id = sf.file_id
LEFT JOIN songs s
  ON s.song_id = sf.song_id
WHERE f.file_id IS NULL
   OR s.song_id IS NULL;

DELETE s
FROM songs s
LEFT JOIN session_songs ss
  ON ss.song_id = s.song_id
LEFT JOIN song_files sf
  ON sf.song_id = s.song_id
WHERE ss.song_id IS NULL
  AND sf.song_id IS NULL;

COMMIT;

SELECT 'remaining_sessions' AS section, session_id, org_name, date
FROM sessions
WHERE session_id IN (139,141);

SELECT 'remaining_files' AS section, file_id, session_id, seq, file_name
FROM files
WHERE session_id IN (139,141);
"
mysql: [Warning] Using a password on the command line interface can be insecure.

