ARG PHP_FLAVOR=8.3-fpm-bookworm
FROM php:${PHP_FLAVOR}
RUN apt-get update && apt-get install -y --no-install-recommends \
    libsqlite3-dev libonig-dev libxml2-dev libcurl4-openssl-dev libzip-dev libicu-dev libpq-dev ca-certificates \
    && for taskExtension in pdo_sqlite pdo_mysql pdo_pgsql pcntl mbstring dom xml xmlwriter curl zip intl opcache; do \
        php -r "exit(extension_loaded('$taskExtension') ? 0 : 1);" || docker-php-ext-install -j4 "$taskExtension"; \
    done && rm -rf /var/lib/apt/lists/*
RUN printf 'memory_limit=256M\ndate.timezone=UTC\nexpose_php=Off\nopcache.enable=1\nopcache.validate_timestamps=1\n' > /usr/local/etc/php/conf.d/deployment-laboratory.ini \
    && if command -v a2enmod; then a2enmod rewrite headers; fi
WORKDIR /app
