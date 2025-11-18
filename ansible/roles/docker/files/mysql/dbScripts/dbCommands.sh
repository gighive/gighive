#!/usr/bin/env bash

set -euo pipefail

# Load MySQL connection settings from the Docker-rendered env file.
# This file is rendered by the docker role from roles/docker/templates/.env.j2
# and lives under the docker_dir path on the VM. For this script we
# assume docker_dir is "$GIGHIVE_HOME/ansible/roles/docker/files".
ENV_FILE="$GIGHIVE_HOME/ansible/roles/docker/files/apache/externalConfigs/.env"

if [ -f "$ENV_FILE" ]; then
  # shellcheck disable=SC1090
  . "$ENV_FILE"
else
  echo "ERROR: Env file not found: $ENV_FILE" >&2
  exit 1
fi

docker cp create_music_db.sql mysqlServer:/docker-entrypoint-initdb.d/00-create_music_db.sql
docker exec -i mysqlServer sh -c "mysql -u root -p\"$MYSQL_ROOT_PASSWORD\" \"$MYSQL_DATABASE\" < /docker-entrypoint-initdb.d/00-create_music_db.sql"

docker cp select.sql mysqlServer:/tmp/select.sql
docker exec -i mysqlServer sh -c "mysql -u root -p\"$MYSQL_ROOT_PASSWORD\" \"$MYSQL_DATABASE\" < /tmp/select.sql"

mysql -u "$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE" < dropDb.sql
#mysql -u "$MYSQL_USER" -p"$MYSQL_PASSWORD"  < createDb.sql
#mysql -u "$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE" < create_tables.sql
#python python_import_data_into_db.py
#mysql -u "$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE" < select.sql
sudo docker exec -it mysqlServer mysql -u "$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE" < validation.sql
#mysql -u "$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE" < jamdatabase.sql
# from docker host:
