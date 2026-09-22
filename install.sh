#!/usr/bin/env bash
#
# Hack Sims — one-file Docker setup installer.
#
# Writes the whole containerised stack (.docker/, docker-compose.yml, env keys,
# Vite config, Shield seeders) into a Laravel project, then brings it up. After
# it finishes there is nothing left to configure: open the panel and log in.
#
#   ./install.sh              write everything, then `docker compose up -d --build`
#   ./install.sh --no-up      write everything, stop before starting the stack
#   ./install.sh --force      overwrite files this script would otherwise keep
#
# Safe to re-run. Every file it replaces is copied into a timestamped backup
# directory first, and steps that would clobber your own code are skipped
# unless --force is given.

set -euo pipefail

RUN_UP=1
FORCE=0

for arg in "$@"; do
    case "$arg" in
        --no-up) RUN_UP=0 ;;
        --force) FORCE=1 ;;
        -h|--help) sed -n '2,20p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
        *) printf 'Unknown option: %s (try --help)\n' "$arg" >&2; exit 2 ;;
    esac
done

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$ROOT"

BACKUP_DIR="$ROOT/.docker-setup-backup/$(date +%Y%m%d-%H%M%S)"
BACKED_UP=0
SKIPPED=()

say()  { printf '\033[1;36m==>\033[0m %s\n' "$1"; }
note() { printf '    %s\n' "$1"; }
warn() { printf '\033[1;33m  ! %s\033[0m\n' "$1"; }

# Copies a file into the backup tree before it is replaced, preserving its path
# so the backup can be diffed or restored wholesale.
backup() {
    local path="$1"
    [ -f "$path" ] || return 0
    mkdir -p "$BACKUP_DIR/$(dirname "$path")"
    cp "$path" "$BACKUP_DIR/$path"
    BACKED_UP=1
}

# write <path>  — content on stdin, so callers read as `write foo <<'EOF' ... EOF`
write() {
    local path="$1"
    mkdir -p "$(dirname "$path")"
    backup "$path"
    cat > "$path"
    note "wrote $path"
}

# keep <path> <marker> — true when the file already contains the marker and
# --force was not given, meaning this step has already been applied.
keep() {
    local path="$1" marker="$2"
    [ "$FORCE" -eq 1 ] && return 1
    [ -f "$path" ] && grep -q "$marker" "$path"
}

###############################################################################
# Preflight
###############################################################################

say "Checking the project"

[ -f artisan ]      || { warn "No artisan file — run this from a Laravel project root."; exit 1; }
[ -f composer.json ] || { warn "No composer.json here."; exit 1; }

if ! command -v docker >/dev/null 2>&1; then
    warn "Docker is not installed or not on PATH. Install Docker Desktop first."
    exit 1
fi

if ! docker compose version >/dev/null 2>&1; then
    warn "'docker compose' is unavailable. You need Docker Compose v2."
    exit 1
fi

note "Laravel project at $ROOT"

###############################################################################
# .docker/php
###############################################################################

say "Writing .docker/php"

write .docker/php/Dockerfile <<'EOF_DOCKERFILE'
# PHP 8.5 matches the platform composer.lock is resolved against, so
# `composer install` inside the container never has to re-resolve.
FROM php:8.5-fpm-bookworm

WORKDIR /var/www

# Node for the Vite asset build. Copied from the official Node image rather
# than apt — Debian's `nodejs` package is Node 18, which Vite 8 refuses to run
# on. Both images are bookworm, so the binary's glibc matches.
COPY --from=node:22-bookworm-slim /usr/local/bin/node /usr/local/bin/node
COPY --from=node:22-bookworm-slim /usr/local/lib/node_modules/npm /usr/local/lib/node_modules/npm
RUN ln -sf ../lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm \
    && ln -sf ../lib/node_modules/npm/bin/npx-cli.js /usr/local/bin/npx \
    && node -v && npm -v

RUN apt-get update && apt-get install -y --no-install-recommends \
    ca-certificates \
    curl \
    git \
    unzip \
    procps \
    # pg_isready, used by the entrypoint to wait for the database
    postgresql-client \
    # headers for the extensions built below
    libpq-dev \
    libicu-dev \
    libzip-dev \
    libfreetype6-dev \
    libjpeg62-turbo-dev \
    libpng-dev \
    && rm -rf /var/lib/apt/lists/*

# opcache is deliberately absent: PHP 8.5 links it statically, so
# docker-php-ext-install opcache fails with "cannot stat 'modules/*'". The
# opcache.* settings in php.ini still apply.
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install \
        bcmath \
        exif \
        gd \
        intl \
        pcntl \
        pdo_pgsql \
        pgsql \
        zip

# REDIS_CLIENT=phpredis in .env expects this extension.
RUN pecl install redis && docker-php-ext-enable redis

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY php.ini /usr/local/etc/php/conf.d/zz-app.ini
COPY docker.conf /usr/local/etc/php-fpm.d/zz-docker.conf

COPY entrypoint.sh /usr/local/bin/entrypoint
COPY setup.sh /usr/local/bin/app-setup
RUN chmod +x /usr/local/bin/entrypoint /usr/local/bin/app-setup

EXPOSE 9000

ENTRYPOINT ["entrypoint"]
CMD ["php-fpm"]
EOF_DOCKERFILE

write .docker/php/entrypoint.sh <<'EOF_ENTRYPOINT'
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
EOF_ENTRYPOINT

write .docker/php/setup.sh <<'EOF_SETUP'
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
EOF_SETUP

write .docker/php/php.ini <<'EOF_PHPINI'
; Loaded as conf.d/zz-app.ini, so these win over the stock php.ini-production.

memory_limit = 512M
max_execution_time = 300

; Filament's file uploads and CSV/Excel imports. post_max_size must stay above
; upload_max_filesize to leave room for the rest of the multipart body, and
; nginx's client_max_body_size (in .docker/nginx/default.conf) above both —
; PHP rejects an oversized upload only after accepting the bytes, so the
; request appears to succeed and then arrives with an empty $_FILES.
upload_max_filesize = 64M
post_max_size = 72M
max_file_uploads = 40

; Dev defaults: revalidate on every request so an edited file takes effect
; without restarting the container.
opcache.enable = 1
opcache.validate_timestamps = 1
opcache.revalidate_freq = 0

; Surface errors in the container log rather than swallowing them.
display_errors = Off
log_errors = On
error_log = /proc/self/fd/2
EOF_PHPINI

write .docker/php/docker.conf <<'EOF_FPM'
[global]
error_log = /proc/self/fd/2
daemonize = no

[www]
listen = 0.0.0.0:9000
; listen.allowed_clients is deliberately unset. It only accepts IP addresses —
; the literal "any" makes php-fpm reject every connection — and leaving it out
; already allows any client, which here means only the compose network.

; Sent to fd/1 it never appears, so access logs go to stderr with the rest.
access.log = /proc/self/fd/1

; Keep the compose `environment:` values (DB_HOST, REDIS_HOST, …) visible to
; the workers. Without this php-fpm clears the environment and the app falls
; back to whatever .env says.
clear_env = no

; Worker warnings and notices belong in the container log too.
catch_workers_output = yes
decorate_workers_output = no
EOF_FPM

chmod +x .docker/php/entrypoint.sh .docker/php/setup.sh

###############################################################################
# .docker/nginx
###############################################################################

say "Writing .docker/nginx"

write .docker/nginx/default.conf <<'EOF_NGINX_SITE'
server {
    listen 80;
    server_name localhost;
    root /var/www/public;
    index index.php index.html;

    charset utf-8;

    # Must stay above PHP's post_max_size (72M in .docker/php/php.ini), or a
    # large upload 413s here before PHP ever sees it.
    client_max_body_size 96M;

    # Docker's embedded DNS. Needed because fastcgi_pass below goes through a
    # variable, which makes nginx resolve the upstream per request instead of
    # once at startup — otherwise a recreated php container comes back on a new
    # IP and every request 502s until nginx is restarted by hand.
    resolver 127.0.0.11 valid=10s ipv6=off;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }

    error_page 404 /index.php;

    location ~ \.php$ {
        try_files $uri =404;
        fastcgi_split_path_info ^(.+\.php)(/.+)$;
        set $upstream php:9000;
        fastcgi_pass $upstream;
        fastcgi_index index.php;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_param PATH_INFO $fastcgi_path_info;

        # Long enough for artisan-style work triggered over HTTP and for
        # Filament's larger exports.
        fastcgi_read_timeout 300;
        fastcgi_buffers 16 16k;
        fastcgi_buffer_size 32k;
    }

    # Never serve dotfiles (.env above all) even if one lands in public/.
    location ~ /\.(?!well-known).* {
        deny all;
    }
}
EOF_NGINX_SITE

write .docker/nginx/nginx.conf <<'EOF_NGINX_MAIN'
user  nginx;
worker_processes  auto;

error_log  /var/log/nginx/error.log warn;
pid        /var/run/nginx.pid;

events {
    worker_connections  1024;
}

http {
    include       /etc/nginx/mime.types;
    default_type  application/octet-stream;

    log_format  main  '$remote_addr - $remote_user [$time_local] "$request" '
                      '$status $body_bytes_sent "$http_referer" '
                      '"$http_user_agent" "$http_x_forwarded_for"';

    access_log  /var/log/nginx/access.log  main;

    sendfile        on;
    tcp_nopush      on;
    keepalive_timeout  65;

    server_tokens off;

    gzip on;
    gzip_vary on;
    gzip_min_length 1000;
    gzip_types
        application/javascript
        application/json
        image/svg+xml
        text/css
        text/plain;

    include /etc/nginx/conf.d/*.conf;
}
EOF_NGINX_MAIN

###############################################################################
# docker-compose.yml
###############################################################################

say "Writing docker-compose.yml"

write docker-compose.yml <<'EOF_COMPOSE'
# Plug and play: `docker compose up -d --build` builds the image, waits for
# Postgres, then runs the whole Laravel + Filament + Shield bootstrap in the
# one-shot `setup` service. php-fpm, nginx and vite only start once that
# finishes, so the first page you load is already migrated, seeded and built.
#
#   app       http://localhost           (Filament panel at /admin)
#   adminer   http://localhost:8080
#   vite      http://localhost:5173      (HMR, used automatically by the app)
#
# Sign in with admin@example.com / password.

x-app: &app
  build:
    context: .docker/php
    dockerfile: Dockerfile
  image: hack-sims-app
  volumes:
    - .:/var/www:cached
    # node_modules is a container-owned volume, never the host's: rollup and
    # esbuild ship platform-specific binaries, and macOS ones do not run here.
    - node_modules:/var/www/node_modules
  environment:
    APP_URL: ${APP_URL:-http://localhost}
    DB_CONNECTION: pgsql
    DB_HOST: pg_db
    DB_PORT: 5432
    DB_DATABASE: ${DB_DATABASE:-hack_sims}
    DB_USERNAME: ${DB_USERNAME:-hack_sims}
    DB_PASSWORD: ${DB_PASSWORD:-secret}
    REDIS_HOST: redis
    REDIS_PORT: 6379
    CACHE_STORE: redis
    SESSION_DRIVER: redis
    # No queue worker in this stack, so jobs run inline rather than piling up
    # in a table nothing drains.
    QUEUE_CONNECTION: sync
    DEMO_USER_PASSWORD: ${DEMO_USER_PASSWORD:-password}

services:

  ###########################
  # One-shot bootstrap
  ###########################
  setup:
    <<: *app
    container_name: hack-sims-setup
    command: app-setup
    restart: "no"
    depends_on:
      pg_db:
        condition: service_healthy
      redis:
        condition: service_healthy

  ###########################
  # PHP-FPM
  ###########################
  php:
    <<: *app
    container_name: hack-sims-php
    depends_on:
      setup:
        condition: service_completed_successfully

  ###########################
  # Nginx
  ###########################
  nginx:
    image: nginx:1.27
    container_name: hack-sims-nginx
    ports:
      - "${APP_PORT:-80}:80"
    volumes:
      - .:/var/www:cached
      - ./.docker/nginx/nginx.conf:/etc/nginx/nginx.conf:ro
      - ./.docker/nginx/default.conf:/etc/nginx/conf.d/default.conf:ro
    depends_on:
      - php

  ###########################
  # Vite dev server (HMR)
  ###########################
  vite:
    <<: *app
    container_name: hack-sims-vite
    command: npm run dev
    ports:
      - "${VITE_PORT:-5173}:5173"
    depends_on:
      setup:
        condition: service_completed_successfully

  ###########################
  # PostgreSQL
  ###########################
  pg_db:
    image: postgres:16
    container_name: hack-sims-pgdb
    environment:
      POSTGRES_DB: ${DB_DATABASE:-hack_sims}
      POSTGRES_USER: ${DB_USERNAME:-hack_sims}
      POSTGRES_PASSWORD: ${DB_PASSWORD:-secret}
    ports:
      - "${FORWARD_DB_PORT:-5432}:5432"
    volumes:
      - postgres_data:/var/lib/postgresql/data
    healthcheck:
      # setup waits on this, so migrations never race a database that is still
      # running its own first-boot initialisation.
      test: [ "CMD-SHELL", "pg_isready -U ${DB_USERNAME:-hack_sims} -d ${DB_DATABASE:-hack_sims}" ]
      interval: 5s
      timeout: 5s
      retries: 20
      start_period: 10s

  ###########################
  # Redis (cache + sessions)
  ###########################
  redis:
    image: redis:7-alpine
    container_name: hack-sims-redis
    ports:
      - "${FORWARD_REDIS_PORT:-6379}:6379"
    volumes:
      - redis_data:/data
    healthcheck:
      test: [ "CMD", "redis-cli", "ping" ]
      interval: 5s
      timeout: 3s
      retries: 20

  ###########################
  # Adminer (database UI)
  ###########################
  adminer:
    image: adminer:latest
    container_name: hack-sims-adminer
    environment:
      ADMINER_DEFAULT_SERVER: pg_db
      ADMINER_DESIGN: pepa-linha
    ports:
      - "${ADMINER_PORT:-8080}:8080"
    depends_on:
      pg_db:
        condition: service_healthy

volumes:
  postgres_data:
  redis_data:
  node_modules:
EOF_COMPOSE

###############################################################################
# Seeders
###############################################################################

say "Writing seeders"

write database/seeders/ShieldRoleSeeder.php <<'EOF_ROLESEEDER'
<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Creates the roles the panel logs into. Runs after `shield:generate`, so every
 * permission Shield discovered already exists by the time super_admin claims
 * them all.
 */
class ShieldRoleSeeder extends Seeder
{
    /**
     * The three case-study roles. Permissions start empty — assign them in
     * Shield's Roles UI once the resources exist.
     */
    public const DOMAIN_ROLES = ['requester', 'custodian', 'approver'];

    public function run(): void
    {
        $guard = config('auth.defaults.guard', 'web');

        $superAdmin = Role::findOrCreate(
            config('filament-shield.super_admin.name', 'super_admin'),
            $guard,
        );

        // super_admin holds every permission Shield knows about, so a newly
        // generated permission is picked up on the next seed run too.
        $superAdmin->syncPermissions(Permission::where('guard_name', $guard)->get());

        if (config('filament-shield.panel_user.enabled')) {
            Role::findOrCreate(config('filament-shield.panel_user.name', 'panel_user'), $guard);
        }

        foreach (self::DOMAIN_ROLES as $role) {
            Role::findOrCreate($role, $guard);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Artisan::call('cache:clear');
    }
}
EOF_ROLESEEDER

write database/seeders/DemoUserSeeder.php <<'EOF_USERSEEDER'
<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * One ready-to-use login per role, so a fresh `docker compose up` lands on a
 * panel you can actually sign into. Idempotent: re-seeding updates the same
 * accounts instead of duplicating them.
 */
class DemoUserSeeder extends Seeder
{
    public function run(): void
    {
        $password = env('DEMO_USER_PASSWORD', 'password');

        $accounts = [
            ['admin@example.com', 'Super Admin', config('filament-shield.super_admin.name', 'super_admin')],
            ['requester@example.com', 'Requesting Unit', 'requester'],
            ['custodian@example.com', 'Warehouse Staff', 'custodian'],
            ['approver@example.com', 'Supply Officer', 'approver'],
        ];

        foreach ($accounts as [$email, $name, $role]) {
            $user = User::updateOrCreate(
                ['email' => $email],
                [
                    'name' => $name,
                    'password' => Hash::make($password),
                    'email_verified_at' => now(),
                ],
            );

            $user->syncRoles([$role]);
        }
    }
}
EOF_USERSEEDER

if keep database/seeders/DatabaseSeeder.php 'ShieldRoleSeeder'; then
    SKIPPED+=("database/seeders/DatabaseSeeder.php — already calls ShieldRoleSeeder")
else
    write database/seeders/DatabaseSeeder.php <<'EOF_DBSEEDER'
<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            ShieldRoleSeeder::class,
            DemoUserSeeder::class,
        ]);
    }
}
EOF_DBSEEDER
fi

###############################################################################
# Application code
###############################################################################

say "Wiring roles into the application"

if keep app/Models/User.php 'HasRoles'; then
    SKIPPED+=("app/Models/User.php — already uses HasRoles")
else
    write app/Models/User.php <<'EOF_USER'
<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * Gate entry to a Filament panel. Shield's permissions decide what a user
     * may do once inside; this only decides who may open the panel at all, so
     * an account with no role assigned cannot reach the admin area.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return $this->roles()->exists();
    }
}
EOF_USER
fi

if keep app/Providers/AppServiceProvider.php 'enforcePolicies'; then
    SKIPPED+=("app/Providers/AppServiceProvider.php — already calls enforcePolicies()")
elif [ -f app/Providers/AppServiceProvider.php ] && ! grep -qE 'public function boot\(\): void\s*$' app/Providers/AppServiceProvider.php; then
    warn "app/Providers/AppServiceProvider.php looks customised — not overwriting."
    note "Add this to its boot() method yourself:"
    note "    \\BezhanSalleh\\FilamentShield\\Facades\\FilamentShield::enforcePolicies();"
else
    write app/Providers/AppServiceProvider.php <<'EOF_PROVIDER'
<?php

namespace App\Providers;

use BezhanSalleh\FilamentShield\Facades\FilamentShield;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Registers the Shield-generated policies that sit outside Laravel's
        // policy discovery — RolePolicy for Spatie's vendor Role model, and any
        // future resource whose policy Shield reports as "requires registration".
        //
        // Guarded because this provider boots during `composer install` on a
        // checkout where Shield has not been pulled in yet; without the check
        // that first install dies before it can install the package.
        if (class_exists(FilamentShield::class)) {
            FilamentShield::enforcePolicies();
        }
    }
}
EOF_PROVIDER
fi

###############################################################################
# Environment
###############################################################################

say "Setting environment defaults"

# set_env <file> <key> <value> — replaces the key's line wherever it is (even
# commented out), or appends it when the key is absent.
set_env() {
    local file="$1" key="$2" value="$3" tmp
    tmp="$(mktemp)"
    if grep -qE "^#? *${key}=" "$file"; then
        awk -v k="$key" -v v="$value" '
            $0 ~ "^#? *" k "=" && !done { print k "=" v; done = 1; next }
            { print }
        ' "$file" > "$tmp"
        mv "$tmp" "$file"
    else
        rm -f "$tmp"
        printf '%s=%s\n' "$key" "$value" >> "$file"
    fi
}

apply_env() {
    local file="$1"
    backup "$file"

    set_env "$file" APP_URL http://localhost
    set_env "$file" DB_CONNECTION pgsql
    set_env "$file" DB_HOST pg_db
    set_env "$file" DB_PORT 5432
    set_env "$file" DB_DATABASE hack_sims
    set_env "$file" DB_USERNAME hack_sims
    set_env "$file" DB_PASSWORD secret
    set_env "$file" REDIS_HOST redis
    set_env "$file" REDIS_PORT 6379
    set_env "$file" CACHE_STORE redis
    set_env "$file" SESSION_DRIVER redis
    set_env "$file" QUEUE_CONNECTION sync

    # Read by compose itself, not by Laravel: the host-side port mappings and
    # the password every seeded account gets.
    set_env "$file" APP_PORT 80
    set_env "$file" VITE_PORT 5173
    set_env "$file" ADMINER_PORT 8080
    set_env "$file" FORWARD_DB_PORT 5432
    set_env "$file" FORWARD_REDIS_PORT 6379
    set_env "$file" DEMO_USER_PASSWORD password

    note "updated $file"
}

[ -f .env.example ] && apply_env .env.example
[ -f .env ] && apply_env .env

###############################################################################
# Vite
###############################################################################

say "Configuring Vite for the container"

if keep vite.config.js "host: '0.0.0.0'"; then
    SKIPPED+=("vite.config.js — already configured for the container")
elif [ -f vite.config.js ]; then
    write vite.config.js <<'EOF_VITE'
import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
            // No `fonts:` entry on purpose. The skeleton's bunny('Instrument
            // Sans') helper downloads the face from fonts.bunny.net during
            // `npm run build`, so a blocked or offline network fails the whole
            // container bootstrap. resources/css/app.css falls back to the
            // system sans stack. To restore it, re-add:
            //     import { bunny } from 'laravel-vite-plugin/fonts';
            //     fonts: [bunny('Instrument Sans', { weights: [400, 500, 600] })],
        }),
        tailwindcss(),
    ],
    server: {
        // The dev server runs inside the `vite` container, so it has to listen
        // on every interface for the published port to reach it. The browser
        // still talks to it as localhost, which is what hmr.host announces.
        host: '0.0.0.0',
        port: 5173,
        strictPort: true,
        hmr: {
            host: 'localhost',
        },
        watch: {
            // File events do not cross the macOS/Windows bind mount, so the
            // watcher has to poll to notice an edit made on the host.
            usePolling: true,
            interval: 300,
            ignored: ['**/storage/framework/views/**', '**/vendor/**'],
        },
    },
});
EOF_VITE
else
    warn "No vite.config.js found — skipping."
fi

###############################################################################
# .gitignore
###############################################################################

# Each rule is guarded on its own marker: a single guard covering the whole
# block silently skips the rest once any one line is already present.
ignore_rule() {
    local marker="$1" comment="$2"
    grep -qF "$marker" .gitignore && return 0
    backup .gitignore
    printf '\n%s\n%s\n' "$comment" "$marker" >> .gitignore
    note "ignored $marker"
}

if [ -f .gitignore ]; then
    say "Extending .gitignore"
    ignore_rule '/public/css/filament'   '# Filament publishes its compiled assets into public/ on every install.'
    ignore_rule '/public/js/filament'    '# Filament publishes its compiled assets into public/ on every install.'
    ignore_rule '/public/fonts/filament' '# Filament publishes its compiled assets into public/ on every install.'
    ignore_rule '/.docker-setup-backup'  '# Backups written by install.sh'
fi

###############################################################################
# Report
###############################################################################

echo
say "Configuration written"

if [ ${#SKIPPED[@]} -gt 0 ]; then
    note "Left alone (already applied — use --force to overwrite):"
    for s in "${SKIPPED[@]}"; do note "  · $s"; done
fi

if [ "$BACKED_UP" -eq 1 ]; then
    note "Replaced files backed up to ${BACKUP_DIR#$ROOT/}"
fi

if ! docker compose config --quiet; then
    warn "docker compose rejected the generated file — stopping before starting anything."
    exit 1
fi
note "docker compose config validates"

if [ "$RUN_UP" -eq 0 ]; then
    echo
    say "Done. Start the stack with:"
    note "docker compose up -d --build"
    exit 0
fi

echo
say "Building and starting the stack (first run takes a few minutes)"
docker compose up -d --build

echo
say "Ready"
note "Panel    http://localhost/admin    admin@example.com / password"
note "Adminer  http://localhost:8080"
note "Logs     docker compose logs -f setup"
