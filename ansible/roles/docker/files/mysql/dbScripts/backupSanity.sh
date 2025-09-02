gzip -t backups/music_db_2025-08-08.sql.gz && echo "gzip OK"
zcat backups/music_db_2025-08-08.sql.gz | head -n 25

