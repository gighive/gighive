docker cp create_music_db.sql mysqlServer:/docker-entrypoint-initdb.d/00-create_music_db.sql
docker exec -i mysqlServer sh -c "mysql -u root -pmusiclibrary music_db < /docker-entrypoint-initdb.d/00-create_music_db.sql"

docker cp select.sql mysqlServer:/tmp/select.sql
docker exec -i mysqlServer sh -c "mysql -u root -pmusiclibrary music_db < /tmp/select.sql"

mysql -u appuser -p music_db < dropDb.sql
#mysql -u appuser -p  < createDb.sql
#mysql -u appuser -p music_db < create_tables.sql
#python python_import_data_into_db.py
#mysql -u appuser -p music_db < select.sql
sudo docker exec -it mysqlServer mysql -u appuser -p music_db < validation.sql 
#mysql -u appuser -p music_db < jamdatabase.sql 
# from docker host:
