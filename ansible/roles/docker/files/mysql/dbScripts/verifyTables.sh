#!/bin/bash
echo "Running MySQL Query..."
sudo docker exec -i mysqlServer mysql -u appuser -p"musiclibrary" music_db -e "SOURCE /select.sql;"

