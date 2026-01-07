MYSQL_PASSWORD='YOUR_DB_PASSWORD' python3 \
  /home/sodo/scripts/gighive/ansible/roles/docker/files/apache/webroot/tools/upload_media_by_hash.py \
  --source-root /home/sodo/videos/stormpigs/finals/singles \
  --source-roots /home/sodo/scripts/stormpigsCode/production/audio \
  --ssh-target ubuntu@prod \
  --db-host prod --db-user root --db-name music_db \
  --group-vars /home/sodo/scripts/gighive/ansible/inventories/group_vars/prod/prod.yml
