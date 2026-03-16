# won't delete musicians
docker exec -i mysqlServer mysql -t -u "$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE" <<'SQL'
SELECT '=== precheck: target blocking session ===' AS section;
SELECT session_id, date, org_name
FROM sessions
WHERE session_id = 83;

SELECT '=== precheck: linked session_musicians rows to delete ===' AS section;
SELECT sm.session_id, sm.musician_id, m.name
FROM session_musicians sm
LEFT JOIN musicians m
  ON m.musician_id = sm.musician_id
WHERE sm.session_id = 83
ORDER BY m.name, sm.musician_id;

START TRANSACTION;

DELETE FROM session_musicians
WHERE session_id = 83;

DELETE FROM sessions
WHERE session_id = 83;

SELECT '=== post-delete check inside transaction ===' AS section;
SELECT COUNT(*) AS remaining_session_rows
FROM sessions
WHERE session_id = 83;

SELECT COUNT(*) AS remaining_session_musicians_rows
FROM session_musicians
WHERE session_id = 83;

COMMIT;

SELECT '=== final check after commit ===' AS section;
SELECT COUNT(*) AS remaining_session_rows
FROM sessions
WHERE session_id = 83;

SELECT COUNT(*) AS remaining_session_musicians_rows
FROM session_musicians
WHERE session_id = 83;
SQL
