FROM ubuntu:22.04

# Set environment variables
ARG PHP_VERSION=8.1
ENV WEB_ROOT="/var/www/html"
ENV SSL_CERT_DIR="/etc/ssl/certs"
ENV SSL_PRIVATE_DIR="/etc/ssl/private"
ENV DEBIAN_FRONTEND=noninteractive

# Install dependencies
# Download and install Composer globally
RUN apt-get update && \
    apt-get install -y \
    apache2 apache2-bin apache2-dev \
    autoconf automake \
    build-essential \
    composer curl ssl-cert ca-certificates \
    iproute2 \
    libapache2-mod-security2 libcap2-bin libssl-dev libtool \
    logrotate \
    net-tools nfs-common \
    php-curl php-fpm php-mbstring php-mysql php-xml \
    pkg-config \
    tzdata \
    unzip \
    vim \
    zlib1g-dev && \
    apt-get clean && rm -rf /var/lib/apt/lists/* && \
    curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer && \
    chmod +x /usr/local/bin/composer

# PHP-FPM config
RUN printf '%s\n' \
'<FilesMatch "\.php$">' \
'    SetHandler "proxy:unix:/run/php/php${PHP_VERSION}-fpm.sock|fcgi://localhost/"' \
'</FilesMatch>' \
'DirectoryIndex index.php index.html' \
> /etc/apache2/conf-available/php-fpm.conf \
 && a2enconf php-fpm

# Configure PHP upload limits for large files (6GB)
RUN sed -i 's/upload_max_filesize = .*/upload_max_filesize = 6144M/' /etc/php/${PHP_VERSION}/fpm/php.ini && \
    sed -i 's/post_max_size = .*/post_max_size = 6144M/' /etc/php/${PHP_VERSION}/fpm/php.ini && \
    sed -i 's/max_execution_time = .*/max_execution_time = 7200/' /etc/php/${PHP_VERSION}/fpm/php.ini && \
    sed -i 's/max_input_time = .*/max_input_time = 7200/' /etc/php/${PHP_VERSION}/fpm/php.ini && \
    sed -i 's/memory_limit = .*/memory_limit = 512M/' /etc/php/${PHP_VERSION}/fpm/php.ini

# Remove default website
# Ensure Apache log, certs, modsecurity and private for htpasswd directores exist
# Enable Apache modules and SSL site
RUN rm -f /etc/apache2/sites-enabled/000-default.conf && \
    mkdir -p /var/log/apache2 && chown -R www-data:www-data /var/log/apache2 && \
    mkdir -p /etc/ssl/certs /etc/ssl/private && \
    mkdir -p /etc/modsecurity/crs && \
    mkdir -p /var/www/private && chown www-data:www-data /var/www/private && chmod 0775 /var/www/private && \
    a2enmod ssl http2 proxy proxy_fcgi headers rewrite cache cache_disk security2 remoteip && \
    a2ensite default-ssl

# Switch MPM
RUN a2dismod mpm_prefork \
 && a2enmod mpm_event http2 proxy proxy_fcgi setenvif ssl headers \
 && apt-get purge -y 'libapache2-mod-php*' || true

ARG APP_FLAVOR=stormpigs

# 1) Copy only composer manifests, install deps (from canonical webroot)
COPY webroot/composer.json webroot/composer.lock ${WEB_ROOT}/
# Set working directory to web root
WORKDIR ${WEB_ROOT}
RUN composer install \
    --no-dev --optimize-autoloader --classmap-authoritative

# 2) Copy the rest of the canonical app’s code into the web root
COPY webroot/ ${WEB_ROOT}/

# 3) Stage overlay; apply only when building GigHive flavor
COPY overlays/gighive/ /opt/gighive-overlay/
RUN if [ "$APP_FLAVOR" = "gighive" ]; then \
      cp -a /opt/gighive-overlay/. ${WEB_ROOT}/; \
    fi \
 && rm -rf /opt/gighive-overlay

# Then pull in Guzzle, optimize, set perms
# Clean up temporary SAN config file
# Ensure PHP-FPM socket directory exists
RUN composer require guzzlehttp/guzzle --no-interaction --no-progress \
 && chown -R www-data:www-data ${WEB_ROOT} && \
    rm -f /tmp/openssl_san.cnf && \
    mkdir -p /run/php && \
    chown -R www-data:www-data /run/php

# Expose ports
EXPOSE 443
