#!/bin/sh
# One-shot bootstrap for `docker compose up -d --build`. Runs in its own
# container before php-fpm, nginx and vite start, so the app is installed,
# migrated, seeded and built by the time the first request arrives.
#
# Every step is idempotent — re-running this on an existing database updates
# rather than duplicates, so a rebuild is always safe.
set -e

cd /var/www

log() { printf '\n\033[1;36m==> %s\033[0m\n' "$1"; }

# ---------------------------------------------------------------- environment
if [ ! -f .env ]; then
    log "Creating .env from .env.example"
    cp .env.example .env
fi

# ------------------------------------------------------------------- storage
# Done before anything writes a log or a cached view, so no root-owned file
# ever lands in storage/ to block php-fpm later.
log "Preparing writable directories"
mkdir -p \
    storage/app/public \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/testing \
    storage/framework/views \
    storage/logs \
    bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache
chmod -R 775 storage bootstrap/cache

# -------------------------------------------------------------- php packages
# --no-scripts throughout this block: composer's post-autoload-dump boots
# Laravel, and on a first run the app already references Filament and Shield
# classes that are not on disk yet. Nothing boots until every package is in.
log "Installing PHP dependencies"
composer install --no-interaction --prefer-dist --no-progress --no-scripts

if ! grep -q '"filament/filament"' composer.json; then
    log "Installing Filament and Shield"
    composer require \
        filament/filament:"^5.0" \
        bezhansalleh/filament-shield:"^4.3" \
        --no-interaction --no-progress --no-scripts
fi

# Everything is present now, so this dump-autoload can safely run the scripts
# the steps above skipped, package:discover included.
composer dump-autoload --no-interaction --optimize

# ------------------------------------------------------------------- app key
# Only generated when missing, so restarting does not invalidate sessions or
# anything already encrypted with the existing key.
if ! grep -q '^APP_KEY=base64:' .env; then
    log "Generating application key"
    php artisan key:generate --force
fi

# ------------------------------------------------------------------ database
log "Waiting for PostgreSQL at ${DB_HOST}:${DB_PORT}"
until pg_isready -h "${DB_HOST}" -p "${DB_PORT}" -U "${DB_USERNAME}" -q; do
    sleep 1
done

# ------------------------------------------------------- filament and shield
# Each step is guarded by the artefact it produces, so the expensive work
# happens only on the first boot of a project that does not have it yet.
if [ ! -f app/Providers/Filament/AdminPanelProvider.php ]; then
    log "Creating the admin panel"
    php artisan filament:install --panels --no-interaction
fi

# Published directly rather than through `shield:setup`, which starts by
# deleting its own row from the migrations table — a table that does not exist
# yet on the database this stack has only just created.
if [ ! -f config/permission.php ]; then
    log "Publishing the permission config"
    php artisan vendor:publish --tag=permission-config --no-interaction
fi

if ! ls database/migrations/*_create_permission_tables.php >/dev/null 2>&1; then
    log "Publishing the permission tables migration"
    php artisan vendor:publish --tag=permission-migrations --no-interaction
fi

if [ ! -f config/filament-shield.php ]; then
    log "Publishing the Shield config"
    php artisan vendor:publish --tag=filament-shield-config --no-interaction
fi

if ! grep -q 'FilamentShieldPlugin' app/Providers/Filament/AdminPanelProvider.php; then
    log "Registering Shield on the admin panel"
    php artisan shield:install admin --no-interaction
fi

log "Running migrations"
php artisan migrate --force

# ---------------------------------------------------------- roles and access
# shield:generate must run before the seeder: it creates the permission rows
# that ShieldRoleSeeder then hands to super_admin.
log "Generating Shield permissions and policies"
php artisan shield:generate --all --panel=admin --no-interaction

log "Seeding roles and demo users"
php artisan db:seed --force

log "Linking public storage"
php artisan storage:link

# -------------------------------------------------------------------- assets
log "Installing node dependencies"
if [ -f package-lock.json ]; then
    npm ci --no-audit --no-fund
else
    npm install --no-audit --no-fund
fi

# A stale public/hot left behind by a hard-killed dev server makes Laravel
# point every asset at a Vite server that is not running.
rm -f public/hot

log "Building assets"
npm run build

# ------------------------------------------------------------------- cleanup
php artisan optimize:clear

# composer and npm ran as root; hand storage back before php-fpm's www-data
# workers need to write to it.
chown -R www-data:www-data storage bootstrap/cache
chmod -R 775 storage bootstrap/cache

log "Setup complete — sign in at ${APP_URL:-http://localhost}/admin"
printf '    admin@example.com     / %s   (super_admin)\n' "${DEMO_USER_PASSWORD:-password}"
printf '    requester@example.com / %s   (requester)\n' "${DEMO_USER_PASSWORD:-password}"
printf '    custodian@example.com / %s   (custodian)\n' "${DEMO_USER_PASSWORD:-password}"
printf '    approver@example.com  / %s   (approver)\n\n' "${DEMO_USER_PASSWORD:-password}"
