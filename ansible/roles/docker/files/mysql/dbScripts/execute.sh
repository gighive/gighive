#docker exec -i mysqlServer mysql -u appuser -p"musiclibrary" music_db < update.sql 
#docker exec -i mysqlServer mysql -u appuser -p"musiclibrary" music_db < selectFiles.sql 
#docker exec -i mysqlServer mysql -u appuser -p"musiclibrary" music_db < select.sql
docker exec -i mysqlServer mysql -u appuser -p"musiclibrary" music_db < databaseListing.sql 
