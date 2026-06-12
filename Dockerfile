# ─────────────────────────────────────────────────────────────────────────────
# imbuo-library — headless Laravel service: cron pipelines + read API.
# Used by Railway via railway.json (builder: DOCKERFILE). No frontend stage.
# ─────────────────────────────────────────────────────────────────────────────

# 8.4, not 8.3: composer.lock pulls in Symfony 8.x, which requires PHP >= 8.4
# (the dev machine is on 8.5). composer.json still allows ^8.3, but the locked
# deps need 8.4+.
FROM php:8.4-cli-bookworm AS app

# System libs + PHP extensions Laravel + Postgres need.
RUN apt-get update \
 && apt-get install -y --no-install-recommends \
      git unzip ca-certificates libpq-dev libzip-dev \
 && docker-php-ext-install -j"$(nproc)" pdo_pgsql bcmath zip opcache \
 && apt-get clean && rm -rf /var/lib/apt/lists/*

# Sensible prod opcache settings.
RUN { \
      echo 'opcache.enable=1'; \
      echo 'opcache.enable_cli=0'; \
      echo 'opcache.memory_consumption=128'; \
      echo 'opcache.max_accelerated_files=20000'; \
      echo 'opcache.validate_timestamps=0'; \
    } > /usr/local/etc/php/conf.d/opcache.ini

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
ENV COMPOSER_ALLOW_SUPERUSER=1

WORKDIR /app

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --prefer-dist --no-scripts --no-autoloader

COPY . .

RUN composer dump-autoload --optimize --no-dev --no-interaction \
 && php artisan package:discover --ansi || true

RUN chmod -R ug+rwX storage bootstrap/cache

EXPOSE 8080

# railway.json's startCommand overrides this for the web service; the scheduler
# service overrides it with `php artisan schedule:work` (dashboard setting).
CMD ["sh", "-c", "php artisan serve --host 0.0.0.0 --port 8080"]
