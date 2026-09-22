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
