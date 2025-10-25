ls -l
docker-compose down -v
docker-compose build
docker-compose up -d
echo "Sleep for two minutes"
sleep 120
docker logs mysqlServer
echo "Done!"
