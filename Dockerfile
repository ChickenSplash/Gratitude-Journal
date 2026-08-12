# ─── Base: the PHP both stages build on ───────────────────────────
FROM php:8.4-apache AS base

# pdo_sqlite and sqlite3 are compiled into the official image already, so the
# opcode cache is the only extension worth adding.
RUN docker-php-ext-install -j"$(nproc)" opcache

# ─── Dependencies: composer never reaches the runtime image ───────
FROM base AS vendor

RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends unzip; \
    rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

WORKDIR /app

# Copied on their own first, so editing application code doesn't invalidate the
# layer holding a few thousand vendor files.
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --prefer-dist \
      --no-scripts --no-autoloader

COPY . .
RUN composer dump-autoload --no-dev --optimize --classmap-authoritative

# ─── Runtime ──────────────────────────────────────────────────────
FROM base AS runtime

ENV APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr \
    DB_CONNECTION=sqlite \
    DATA_DIR=/data \
    DB_DATABASE=/data/journal.sqlite \
    SESSION_DRIVER=database \
    CACHE_STORE=database \
    QUEUE_CONNECTION=sync

COPY docker/php.ini /usr/local/etc/php/conf.d/journal.ini
COPY docker/apache.conf /etc/apache2/sites-available/000-default.conf

RUN set -eux; \
    a2enmod rewrite; \
    sed -i 's/^Listen 80$/Listen 3000/' /etc/apache2/ports.conf; \
    printf 'ServerTokens Prod\nServerSignature Off\nServerName localhost\n' \
      > /etc/apache2/conf-available/journal-hardening.conf; \
    a2enconf journal-hardening

WORKDIR /var/www/html

COPY --from=vendor /app/vendor ./vendor
COPY . .
COPY docker/entrypoint.sh /usr/local/bin/entrypoint

# DATA_DIR is expected to be a mount from the host (see docker-compose.yml), so
# the SQLite file survives image rebuilds. Deliberately no VOLUME instruction:
# it would make a bare `docker run` spawn a throwaway anonymous volume here,
# quietly writing the journal somewhere nobody goes looking.
RUN set -eux; \
    chmod +x /usr/local/bin/entrypoint; \
    mkdir -p /data bootstrap/cache \
             storage/framework/cache/data storage/framework/sessions \
             storage/framework/views storage/logs; \
    chown -R www-data:www-data /data storage bootstrap/cache

EXPOSE 3000

HEALTHCHECK --interval=30s --timeout=3s --start-period=20s \
  CMD php -r 'exit(@file_get_contents("http://127.0.0.1:3000/up") === false ? 1 : 0);'

# Starts as root so the entrypoint can line the container's uid up with the
# host's before handing over to Apache, which runs as www-data throughout.
ENTRYPOINT ["entrypoint"]
CMD ["apache2-foreground"]
