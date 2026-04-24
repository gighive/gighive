SET NAMES utf8mb4;

SELECT '===== GENRES TABLE =====' AS "";
SELECT COUNT(*) AS "Total Rows in genres" FROM genres;
SELECT '' AS "\n";
SELECT * FROM genres LIMIT 20;
SELECT '' AS "\n\n";

SELECT '===== STYLES TABLE =====' AS "";
SELECT COUNT(*) AS "Total Rows in styles" FROM styles;
SELECT '' AS "\n";
SELECT * FROM styles LIMIT 20;
SELECT '' AS "\n\n";

SELECT '===== EVENTS TABLE =====' AS "";
SELECT COUNT(*) AS "Total Rows in events" FROM events;
SELECT '' AS "\n";
SELECT * FROM events LIMIT 20;
SELECT '' AS "\n\n";

SELECT '===== ASSETS TABLE =====' AS "";
SELECT COUNT(*) AS "Total Rows in assets" FROM assets;
SELECT '' AS "\n";
SELECT * FROM assets LIMIT 20;
SELECT '' AS "\n\n";

SELECT '===== EVENT ITEMS TABLE =====' AS "";
SELECT COUNT(*) AS "Total Rows in event_items" FROM event_items;
SELECT '' AS "\n";
SELECT * FROM event_items LIMIT 20;
SELECT '' AS "\n\n";

SELECT '===== PARTICIPANTS TABLE =====' AS "";
SELECT COUNT(*) AS "Total Rows in participants" FROM participants;
SELECT '' AS "\n";
SELECT * FROM participants LIMIT 20;
SELECT '' AS "\n\n";

SELECT '===== EVENT PARTICIPANTS TABLE =====' AS "";
SELECT COUNT(*) AS "Total Rows in event_participants" FROM event_participants;
SELECT '' AS "\n";
SELECT * FROM event_participants LIMIT 20;
SELECT '' AS "\n\n";

SELECT '===== USERS TABLE =====' AS "";
SELECT COUNT(*) AS "Total Rows in users" FROM users;
SELECT '' AS "\n";
SELECT * FROM users LIMIT 20;
SELECT '' AS "\n\n";
