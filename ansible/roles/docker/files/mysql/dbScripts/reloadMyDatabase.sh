docker exec -i mysqlServer sh -c "mysql -u root -pmusiclibrary music_db < /docker-entrypoint-initdb.d/00-create_music_db.sql"
docker exec -i mysqlServer sh -c "mysql -u root -pmusiclibrary music_db < /docker-entrypoint-initdb.d/01-load_and_transform.sql"
