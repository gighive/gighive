#!/bin/bash

ENV_FILE="$GIGHIVE_HOME/ansible/roles/docker/files/apache/externalConfigs/.env"

if [ -f "$ENV_FILE" ]; then
  . "$ENV_FILE"
else
  echo "ERROR: Env file not found: $ENV_FILE" >&2
  exit 1
fi

echo "Running MySQL Query..."
sudo docker exec -i mysqlServer mysql -u "$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE" -e "SOURCE /select.sql;"

