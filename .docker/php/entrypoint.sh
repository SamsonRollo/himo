#!/bin/sh
# Entrypoint for the long-running php-fpm and vite containers. The heavy
# bootstrap lives in setup.sh, which compose runs once in its own container;
# this only guarantees the writable directories exist and are owned by
# www-data before the process starts.
set -e

mkdir -p \
    /var/www/storage/app/public \
    /var/www/storage/framework/cache/data \
    /var/www/storage/framework/sessions \
    /var/www/storage/framework/views \
    /var/www/storage/logs \
    /var/www/bootstrap/cache

chown -R www-data:www-data /var/www/storage /var/www/bootstrap/cache
chmod -R 775 /var/www/storage /var/www/bootstrap/cache

exec "$@"
