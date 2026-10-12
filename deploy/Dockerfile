# One image for both sites: Apache with PHP, the code, and drush. The site is
# chosen by the hostname (web/sites/sites.php), everything that differs between
# environments comes from the environment (config/settings.env.php).
#
#   docker build -f deploy/Dockerfile -t import-engine .
#
# Multi-arch (amd64 and arm64) builds are made by .github/workflows/image.yml.

FROM php:8.4-apache AS base

COPY --from=ghcr.io/mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/
RUN apt-get update \
    && apt-get install -y --no-install-recommends mariadb-client unzip git \
    && rm -rf /var/lib/apt/lists/* \
    && install-php-extensions gd opcache pdo_mysql zip intl \
    && a2enmod rewrite headers \
    && sed -ri 's!/var/www/html!/var/www/html/web!g' /etc/apache2/sites-available/*.conf /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf \
    && sed -ri 's!AllowOverride None!AllowOverride All!' /etc/apache2/apache2.conf

COPY deploy/docker/php.ini /usr/local/etc/php/conf.d/zz-import-engine.ini

# The code and its dependencies, without the tools for development.
FROM base AS build
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /var/www/html
COPY . .
RUN COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --optimize-autoloader --no-interaction \
    && rm -rf /root/.composer /root/.cache

FROM base AS runtime
WORKDIR /var/www/html
COPY --from=build /var/www/html /var/www/html
COPY deploy/docker/bootstrap.sh deploy/docker/seed-site-a.php deploy/docker/seed-site-b.php /opt/import-engine/
RUN chmod +x /opt/import-engine/bootstrap.sh \
    && mkdir -p web/sites/site_a/files web/sites/site_b/files \
    && chown -R www-data:www-data web/sites/site_a/files web/sites/site_b/files
# Not as root: Apache listens on 8080 and runs as www-data, and so do drush and
# the jobs (the Kubernetes manifests set the same user).
RUN sed -ri 's/^Listen 80$/Listen 8080/' /etc/apache2/ports.conf \
    && sed -ri 's/<VirtualHost \*:80>/<VirtualHost *:8080>/' /etc/apache2/sites-available/*.conf \
    && mkdir -p /var/run/apache2 /var/lock/apache2 /var/log/apache2 \
    && chown -R www-data:www-data /var/run/apache2 /var/lock/apache2 /var/log/apache2
ENV PATH="/var/www/html/vendor/bin:${PATH}" HOME=/tmp
USER www-data
EXPOSE 8080
