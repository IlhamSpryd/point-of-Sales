FROM php:8.2-cli

# Install dependensi server, driver PostgreSQL, dan Node.js (untuk Tailwind)
RUN apt-get update && apt-get install -y git unzip libpq-dev nodejs npm
RUN docker-php-ext-install pdo pdo_pgsql

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Atur folder kerja
WORKDIR /app
COPY . .

# Install paket Laravel dan proses tampilan UI Kasir
RUN composer install --no-dev --optimize-autoloader
RUN npm install && npm run build

# Beritahu Render port berapa yang harus dibuka
CMD php artisan serve --host=0.0.0.0 --port=${PORT:-8000}