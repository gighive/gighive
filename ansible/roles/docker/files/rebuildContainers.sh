ls -l
docker compose -f docker-compose.yml down -v
docker compose -f docker-compose.yml build
docker compose -f docker-compose.yml up -d
echo "Sleep for two minutes"
sleep 120
docker logs mysqlServer
echo "Done!"
