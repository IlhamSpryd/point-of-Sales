# ==============================================================================
# Apeiron POS — Multi-Tenant Enterprise ERP SaaS Tier-1 Containerfile
# Multi-Stage Build: Node.js 20 Asset Compilation + PHP 8.3 Enterprise Runtime
# ==============================================================================

# ------------------------------------------------------------------------------
# Stage 1: Frontend Asset Compilation (Vite + Tailwind CSS)
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
# Stage 2: Enterprise PHP 8.3 Production Runtime
# ------------------------------------------------------------------------------
FROM php:8.3-cli AS production

# Label metadata
LABEL maintainer="Apeiron Architecture Team"
LABEL version="1.0.0"
LABEL tier="Enterprise ERP SaaS"

# Install system dependencies & MariaDB client (for EnterpriseBackupCommand mysqldump)
RUN apt-get update && apt-get install -y --no-install-recommends \
    git \
    unzip \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    libzip-dev \
    mariadb-client \
    curl \
    && rm -rf /var/lib/apt/lists/*

# Install & configure PHP extensions: pdo_mysql, bcmath, pcntl, gd, zip, opcache
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
        pdo_mysql \
        bcmath \
        pcntl \
        opcache \
        gd \
        zip

# Install Redis extension via PECL
RUN pecl install redis \
    && docker-php-ext-enable redis

# OPcache production configuration (Enterprise throughput & latency)
RUN { \
    echo 'opcache.enable=1'; \
    echo 'opcache.enable_cli=1'; \
    echo 'opcache.memory_consumption=256'; \
    echo 'opcache.interned_strings_buffer=16'; \
    echo 'opcache.max_accelerated_files=20000'; \
    echo 'opcache.revalidate_freq=0'; \
    echo 'opcache.validate_timestamps=0'; \
    echo 'opcache.save_comments=1'; \
    echo 'opcache.fast_shutdown=1'; \
} > /usr/local/etc/php/conf.d/opcache-recommended.ini

# PHP memory limit & upload tuning
RUN { \
    echo 'memory_limit = 512M'; \
    echo 'upload_max_filesize = 25M'; \
    echo 'post_max_size = 30M'; \
    echo 'max_execution_time = 120'; \
} > /usr/local/etc/php/conf.d/enterprise-overrides.ini

# Install Composer binary from official image
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Copy dependency definition files first (leverage Docker layer caching)
COPY composer.json composer.lock ./

# Install vendor packages without running scripts or dev dependencies
RUN composer install --no-dev --optimize-autoloader --no-interaction --no-scripts

# Copy full application source code
COPY . .

# Copy compiled frontend assets from Stage 1
COPY --from=frontend-builder /app/public/build ./public/build

# Finish autoloader and package discovery
RUN composer dump-autoload --optimize --no-dev

# Setup directory permissions for storage, cache, and backups
RUN mkdir -p storage/app/backups storage/app/private storage/app/public bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

# Container Healthcheck (Laravel /up route)
HEALTHCHECK --interval=30s --timeout=5s --start-period=10s --retries=3 \
    CMD curl -f http://127.0.0.1:${PORT:-8000}/up || exit 1

EXPOSE 8000

# Default entrypoint starts PHP HTTP server for cloud deployments (Render/PaaS/Containers)
# For dedicated workers, override CMD with: php artisan queue:work --queue=webhooks,exports,default
# For scheduler daemon, override CMD with: php artisan schedule:work
CMD php artisan serve --host=0.0.0.0 --port=${PORT:-8000}