# ==============================================================================
# Apeiron POS — Multi-Tenant Enterprise ERP SaaS Tier-1 Containerfile
# Multi-Stage Build: Node.js 20 Asset Compilation + PHP 8.3 FPM Enterprise Runtime
# VPS + Cloudflare Parity Stack
# ==============================================================================

# ------------------------------------------------------------------------------
# Stage 1: Frontend Asset Compilation (Vite + Tailwind CSS v4)
# ------------------------------------------------------------------------------
FROM node:20-alpine AS frontend-builder
WORKDIR /app

COPY package*.json ./
RUN npm ci --no-audit --prefer-offline

COPY resources ./resources
COPY public ./public
COPY vite.config.js ./
RUN npm run build

# ------------------------------------------------------------------------------
# Stage 2: Enterprise PHP 8.3 FPM Production Runtime
# ------------------------------------------------------------------------------
FROM php:8.3-fpm-bookworm AS production

LABEL maintainer="Apeiron Architecture Team"
LABEL version="1.1.0"
LABEL tier="Enterprise ERP SaaS"

# Install OS libraries & MariaDB client (for database triggers & mysqldump)
RUN apt-get update && apt-get install -y --no-install-recommends \
    git \
    unzip \
    curl \
    mariadb-client \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    libzip-dev \
    libicu-dev \
    && rm -rf /var/lib/apt/lists/*

# Configure and install PHP extensions
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
        pdo_mysql \
        bcmath \
        pcntl \
        opcache \
        gd \
        zip \
        intl \
        exif

# Install Redis extension via PECL
RUN pecl install redis \
    && docker-php-ext-enable redis

# Install Composer binary from official image
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Copy PHP and OPcache configuration
COPY docker/php/php.ini /usr/local/etc/php/conf.d/99-enterprise-php.ini
COPY docker/php/opcache.ini /usr/local/etc/php/conf.d/10-opcache.ini

# Copy entrypoint script
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

WORKDIR /var/www/html

# Copy dependency definition files first (Docker layer caching)
COPY composer.json composer.lock ./

# Install vendor dependencies without running scripts
RUN composer install --no-dev --optimize-autoloader --no-interaction --no-scripts

# Copy full application source
COPY . .

# Copy compiled frontend assets from Stage 1
COPY --from=frontend-builder /app/public/build ./public/build

# Finish autoloader optimization
RUN composer dump-autoload --optimize --no-dev

# Setup directory structure & permissions
RUN mkdir -p \
    storage/app/backups \
    storage/app/private \
    storage/app/public \
    storage/framework/cache \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs \
    bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

EXPOSE 9000

HEALTHCHECK --interval=30s --timeout=5s --start-period=10s --retries=3 \
    CMD php-fpm -t || exit 1

ENTRYPOINT ["entrypoint.sh"]
CMD ["app"]
