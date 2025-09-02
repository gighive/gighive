    SELECT 
        js.date AS jam_date,
        js.rating AS rating,
        js.keywords AS keywords,
        js.duration AS duration,
        js.location AS location,
        js.jam_summary AS summary,
        s.title AS song_name,
        --s.file_type AS file_type,
        --s.file_path AS file_path
    FROM jam_sessions js
    JOIN jam_session_songs jss ON js.id = jss.jam_session_id
    JOIN songs s ON jss.song_id = s.id
    ORDER BY js.date DESC
