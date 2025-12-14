SHOW COLUMNS FROM files LIKE 'media_info%';
SELECT COUNT(*) total, SUM(media_info IS NOT NULL) with_media_info FROM files;
SELECT media_info_tool, COUNT(*) FROM files GROUP BY media_info_tool;
