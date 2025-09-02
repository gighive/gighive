# replace <mysql_container> and mydb; this writes to the host path after ">"
PASSWORD=musiclibrary
CONTAINER=mysqlServer
USER=root
DB=music_db
docker exec -e MYSQL_PWD="$PASSWORD" -i $CONTAINER \
  mysqldump -u$USER --single-transaction --quick --lock-tables=0 \
  --routines --events --triggers --default-character-set=utf8mb4 \
  --databases $DB \
| gzip > backups/"$DB"_$(date +%F).sql.gz
