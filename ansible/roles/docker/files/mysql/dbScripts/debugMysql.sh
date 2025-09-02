#!/bin/bash -v
# temp commands once mysql starts
sudo docker exec -it mysqlServer mysql -u root -p
sudo docker exec -it mysqlServer mysql -u root -p -e "SELECT user, host FROM mysql.user;"
sudo docker exec -it mysqlServer mysql -u appuser -p

exit
# logs?
docker logs mysqlServer
echo
# container running?
sudo docker ps -a | grep mysqlServer
echo
# connect from host
mysql -h 127.0.0.1 -u appuser -p
echo
# process running?
sudo docker exec -it mysqlServer bash -c "cat /proc/1/cmdline"
echo
# list files
sudo docker exec -it mysqlServer bash -c "ls -lah /var/lib/mysql"

exit

#manually init mysql
sudo docker exec -it mysqlServer bash
mysqld --initialize --user=mysql --datadir=/var/lib/mysql

#fixes
bash-5.1# rm -rf /var/lib/mysql/*
bash-5.1# mysqld --initialize --user=mysql --datadir=/var/lib/mysql

chown -R mysql:mysql /var/lib/mysql
chmod -R 755 /var/lib/mysql

mysqld --datadir=/var/lib/mysql --user=mysql


exit
# login
sudo docker exec -it mysqlServer mysql -u appuser -p
exit
# mysql data dir perms?
docker exec -it mysqlServer ls -lah /var/lib/mysql
# running mysql container?
docker ps -a | grep mysql


exit

docker exec -it mysqlServer mysql -uroot -p -e "SELECT User, Host FROM mysql.user;"
docker exec -it mysqlServer mysql -uroot -p -e "SHOW GRANTS FOR 'appuser'@'localhost';"
docker exec -it mysqlServer mysql -uroot -p -e "SHOW GRANTS FOR 'appuser'@'%';"
docker exec -it mysqlServer mysql -uroot -p -e "SELECT User, Host, Plugin FROM mysql.user WHERE User='appuser';"
docker logs mysqlServer | grep -i "mysql"
docker exec -it mysqlServer mysql -uroot -p -e "SHOW VARIABLES LIKE 'bind_address';"

sudo docker exec -it mysqlServer mysql -uroot -p < /docker-entrypoint-initdb.d/mysql-init.sql
sudo docker exec -it mysqlServer ls -lah /docker-entrypoint-initdb.d/

#exit
docker exec -it mysqlServer cat /docker-entrypoint-initdb.d/mysql-init.sql


#exit
docker exec -it mysqlServer ls -lah /var/lib/mysql
docker exec -it mysqlServer mysql -uroot -p -e "SOURCE /docker-entrypoint-initdb.d/mysql-init.sql;"

#exit
docker exec -it mysqlServer ls -lah /docker-entrypoint-initdb.d/
docker exec -it mysqlServer mysql -uroot -p -e "SHOW TABLES FROM mysql;"

#exit
docker exec -it mysqlServer mysql -uroot -p
mysql> SOURCE /docker-entrypoint-initdb.d/mysql-init.sql;
mysql> SELECT User, Host FROM mysql.user;
exit
