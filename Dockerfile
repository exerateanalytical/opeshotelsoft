# syntax=docker/dockerfile:1.7
# OpesHotelSoft production image: one image runs php-fpm (app), queue worker and scheduler.
# nginx uses the "web" target, which carries only public/ (static assets) and the nginx config.

ARG PHP_VERSION=8.3
ARG NODE_VERSION=22

############################
# 1. Composer dependencies #
############################
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --no-interaction --prefer-dist --ignore-platform-reqs
COPY . .
RUN composer dump-autoload --no-dev --optimize --classmap-authoritative --no-scripts

########################
# 2. Front-end assets  #
########################
FROM node:${NODE_VERSION}-alpine AS assets
WORKDIR /app
COPY package.json package-lock.json* ./
RUN npm ci --no-audit --no-fund
COPY . .
# Some Vite plugins read vendor/ (e.g. ziggy); copy it in case.
COPY --from=vendor /app/vendor ./vendor
RUN npm run build

########################
# 3. PHP-FPM runtime   #
########################
FROM php:${PHP_VERSION}-fpm-alpine AS app

# install-php-extensions handles build deps and cleans up after itself.
COPY --from=mlocati/php-extension-installer:2 /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions pdo_pgsql pgsql intl gd zip bcmath redis opcache pcntl exif \
 && apk add --no-cache postgresql16-client openssl fcgi tini \
 && mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

COPY docker/php/php.ini      $PHP_INI_DIR/conf.d/zz-opeshotelsoft.ini
COPY docker/php/www.conf     /usr/local/etc/php-fpm.d/zz-www.conf
COPY docker/entrypoint.sh    /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint

WORKDIR /var/www/html
COPY --chown=www-data:www-data . .
COPY --chown=www-data:www-data --from=vendor /app/vendor ./vendor
COPY --chown=www-data:www-data --from=assets /app/public/build ./public/build
RUN rm -rf node_modules tests .env \
 && mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs storage/app/backups bootstrap/cache \
 && chown -R www-data:www-data storage bootstrap/cache

USER www-data
EXPOSE 9000
HEALTHCHECK --interval=30s --timeout=5s --start-period=30s --retries=3 \
  CMD REDIRECT_STATUS=1 SCRIPT_NAME=/ping SCRIPT_FILENAME=/ping REQUEST_METHOD=GET cgi-fcgi -bind -connect 127.0.0.1:9000 | grep -q pong || exit 1
ENTRYPOINT ["/sbin/tini", "--", "/usr/local/bin/entrypoint"]
CMD ["php-fpm"]

########################
# 4. nginx (web)       #
########################
FROM nginx:1.27-alpine AS web
COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf
COPY --from=app /var/www/html/public /var/www/html/public
EXPOSE 80
HEALTHCHECK --interval=30s --timeout=5s --retries=3 CMD wget -qO- http://127.0.0.1/nginx-health || exit 1
