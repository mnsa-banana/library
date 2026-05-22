# ─────────────────────────────────────────────────────────────────────────────
# imbuo-parent — Laravel API + server-rendered MNSA Blade landing + the React SPA
# (built here and served as static assets by Laravel). Used by Railway via
# railway.json (builder: DOCKERFILE).
# ─────────────────────────────────────────────────────────────────────────────

# ── Stage 1: build the React SPA (frontend/) → public/build/ ─────────────────
FROM node:20-bookworm-slim AS frontend
WORKDIR /src
COPY frontend/package.json frontend/package-lock.json ./frontend/
RUN npm ci --prefix frontend
COPY frontend/ ./frontend/
# frontend/vite.config.ts writes to ../public/build (i.e. /src/public/build)
RUN npm run build --prefix frontend

# ── Stage 2: PHP runtime ─────────────────────────────────────────────────────
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

# Install PHP deps first (better layer caching). --no-scripts: package:discover
# needs the app code, which isn't here yet; we run it after COPY . . below.
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --prefer-dist --no-scripts --no-autoloader

# App code, then the built SPA.
COPY . .
COPY --from=frontend /src/public/build ./public/build

# Optimized autoloader + package:discover. (Runs `php artisan` with no env vars
# at build time — package:discover only writes bootstrap/cache/, which is fine.)
RUN composer dump-autoload --optimize --no-dev --no-interaction \
 && php artisan package:discover --ansi || true \
 && php artisan storage:link || true

# Laravel needs these writable at runtime.
RUN chmod -R ug+rwX storage bootstrap/cache

EXPOSE 8080

# Just serve, on :8080. (railway.json's startCommand overrides this; the Railway
# service also has PORT=8080 set so the proxy routes there.) Deliberately minimal:
#  - `php artisan migrate` is NOT in the start command — Railway's private DB
#    networking (postgres.railway.internal) has a startup delay, so a migrate on
#    container start hangs. Run migrations with `railway ssh --service imbuo-parent`
#    then `php artisan migrate --force`, or add a `preDeployCommand` once verified.
#  - `php artisan optimize` is NOT run — adding it made the container fail to come
#    up (undiagnosed); the app runs fine uncached.
# `php artisan serve` is the dev server — adequate for low traffic; swap for
# php-fpm+nginx or FrankenPHP for production scale.
CMD ["sh", "-c", "php artisan serve --host 0.0.0.0 --port 8080"]
