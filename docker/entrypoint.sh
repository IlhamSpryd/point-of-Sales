#!/bin/sh
set -e

ROLE="${1:-app}"

if [ "$ROLE" = "app" ]; then
    echo "=== Starting Apeiron Enterprise Application (PHP-FPM) ==="
    
    # Sync built frontend assets into public/build if present
    if [ -d /var/www/html/public_dist_build ]; then
        mkdir -p /var/www/html/public/build
        cp -rf /var/www/html/public_dist_build/* /var/www/html/public/build/ 2>/dev/null || true
    fi

    # Ensure storage permissions for www-data
    chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache 2>/dev/null || true
    chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache 2>/dev/null || true
    
    exec php-fpm -F
elif [ "$ROLE" = "worker" ]; then
    echo "=== Starting Apeiron Enterprise Queue Worker ==="
    exec php /var/www/html/artisan queue:work --tries=3 --timeout=90 --sleep=3 --rest=1 --max-jobs=1000 --max-time=3600
elif [ "$ROLE" = "scheduler" ]; then
    echo "=== Starting Apeiron Enterprise Scheduler Daemon ==="
    while true; do
        php /var/www/html/artisan schedule:run --verbose --no-interaction &
        sleep 60
    done
elif [ "$ROLE" = "reverb" ]; then
    echo "=== Starting Apeiron Enterprise Reverb WebSocket Server ==="
    exec php /var/www/html/artisan reverb:start --host=0.0.0.0 --port=8080
else
    exec "$@"
fi

