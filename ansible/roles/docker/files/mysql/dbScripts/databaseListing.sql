SELECT
    js.date       AS jam_date,
    s.title       AS song_name,
    f.file_name   AS file_name
FROM jam_sessions      js
JOIN jam_session_songs jss ON js.jam_session_id = jss.jam_session_id
JOIN songs             s  ON jss.song_id       = s.song_id
JOIN song_files        sf ON sf.song_id        = s.song_id
JOIN files             f  ON sf.file_id        = f.file_id
     AND f.file_name LIKE CONCAT('%', DATE_FORMAT(js.date, '%Y%m%d'), '_%')
WHERE js.date = '2006-08-31'
ORDER BY
    js.date DESC,
    CAST(
      SUBSTRING_INDEX(
        SUBSTRING_INDEX(f.file_name, '_', 2),
      '_', -1
    ) AS UNSIGNED);

--        js.rating AS rating,
--        js.keywords AS keywords,
--        js.duration AS duration,
--        js.location AS location,
--        js.jam_summary AS summary,
--        GROUP_CONCAT(DISTINCT m.name ORDER BY m.name SEPARATOR ', ') AS crew,
--        s.title AS song_name,
--        f.file_type AS file_type,
--        f.file_name AS file_name

--    LEFT JOIN jam_session_musicians jsm ON js.jam_session_id = jsm.jam_session_id
--    LEFT JOIN musicians m ON jsm.musician_id = m.musician_id
