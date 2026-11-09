FROM php:8.3-apache
RUN apt-get update && apt-get upgrade -y && rm -rf /var/lib/apt/lists/*
RUN docker-php-ext-install pdo_mysql \
    && printf "ServerName telemetry.gighive.app\n" > /etc/apache2/conf-available/servername.conf \
    && a2enconf servername
