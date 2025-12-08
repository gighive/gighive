ENV_FILE="$GIGHIVE_HOME/ansible/roles/docker/files/apache/externalConfigs/.env"

if [ -f "$ENV_FILE" ]; then
  . "$ENV_FILE"
else
  echo "ERROR: Env file not found: $ENV_FILE" >&2
  exit 1
fi

docker exec -i mysqlServer sh -c "mysql -u root -p\"$MYSQL_ROOT_PASSWORD\" \"$MYSQL_DATABASE\" < /docker-entrypoint-initdb.d/00-create_music_db.sql"
docker exec -i mysqlServer sh -c "mysql -u root -p\"$MYSQL_ROOT_PASSWORD\" \"$MYSQL_DATABASE\" < /docker-entrypoint-initdb.d/01-load_and_transform.sql"
