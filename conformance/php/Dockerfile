FROM php:8.3-cli-bookworm

RUN apt-get update && apt-get install -y --no-install-recommends \
        libsqlite3-dev libonig-dev libxml2-dev libcurl4-openssl-dev libzip-dev libicu-dev \
    && for taskExtension in pdo_sqlite mbstring dom xml xmlwriter curl zip intl; do \
        php -r "exit(extension_loaded('$taskExtension') ? 0 : 1);" || docker-php-ext-install -j4 "$taskExtension"; \
    done \
    && rm -rf /var/lib/apt/lists/*

RUN printf 'memory_limit=512M\ndate.timezone=UTC\nopcache.enable_cli=1\n' > /usr/local/etc/php/conf.d/oidf-laboratory.ini
WORKDIR /app/.runtime/conformance/op
