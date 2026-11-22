#!/usr/bin/env bash
# ==============================================================================
# Apeiron POS — Production Deployment Script for VPS behind Cloudflare
# Idempotent Zero-Downtime Deployment Automation
# ==============================================================================
set -euo pipefail

echo "=========================================================="
echo " Starting Apeiron POS Enterprise VPS Deployment"
echo " Date: $(date -u '+%Y-%m-%d %H:%M:%S UTC')"
echo "=========================================================="

# 1. Detect Docker Compose implementation
export COMPOSE_PROJECT_NAME="apeiron-pos"
if docker compose version >/dev/null 2>&1; then
    COMPOSE="docker compose"
elif command -v docker-compose >/dev/null 2>&1; then
    COMPOSE="docker-compose"
else
    echo "[-] Error: docker compose is not installed on this system." >&2
    exit 1
fi

# 2. Check for .env file
if [ ! -f .env ]; then
    echo "[-] Error: .env file missing in repository root." >&2
    echo "    Please create .env from .env.example before deploying." >&2
    exit 1
fi

# 3. Pull latest base images and build containers
echo "[+] Step 1/5: Building and starting production containers..."
$COMPOSE pull db redis web || true
$COMPOSE build app worker scheduler reverb

echo "[+] Starting core storage services (database & redis)..."
$COMPOSE up -d db redis

echo "[+] Waiting for database readiness..."
RETRY=0
MAX_RETRIES=30
until $COMPOSE exec -T db healthcheck.sh --connect --innodb_initialized >/dev/null 2>&1 || [ $RETRY -eq $MAX_RETRIES ]; do
    echo "    Waiting for MariaDB 11.4 to report healthy ($RETRY/$MAX_RETRIES)..."
    sleep 2
    RETRY=$((RETRY + 1))
done

if [ $RETRY -eq $MAX_RETRIES ]; then
    echo "[-] Error: MariaDB failed to initialize within 60 seconds." >&2
    exit 1
fi

echo "[+] Starting application, queue worker, scheduler, reverb, and web gateway..."
$COMPOSE up -d app worker scheduler reverb web

# 4. Sync compiled frontend assets to host public directory
echo "[+] Step 2/5: Syncing compiled frontend assets from app container to host..."
mkdir -p ./public/build
$COMPOSE cp app:/var/www/html/public/build/. ./public/build/

# 5. Run database migrations
echo "[+] Step 3/5: Executing database migrations..."
$COMPOSE exec -T app php artisan migrate --force --no-interaction

# 6. Clear and re-warm production caches
echo "[+] Step 4/5: Re-warming application caches..."
$COMPOSE exec -T app php artisan optimize:clear
$COMPOSE exec -T app php artisan config:cache
$COMPOSE exec -T app php artisan route:cache
$COMPOSE exec -T app php artisan view:cache
$COMPOSE exec -T app php artisan event:cache
$COMPOSE exec -T app sh -c 'kill -USR2 1' || true

# 7. Graceful worker, app, reverb & web restart
echo "[+] Step 5/5: Broadcasting queue worker reload signal and restarting runtime services..."
$COMPOSE exec -T app php artisan queue:restart
$COMPOSE restart app reverb web || true

# 8. Post-deployment dangling Docker image cleanup
echo "[+] Step 6/6: Pruning dangling Docker images to preserve VPS disk space..."
docker image prune -f >/dev/null 2>&1 || true

# 9. Final status verification
echo "[+] Verifying container health and readiness..."
$COMPOSE ps

echo "=========================================================="
echo " Apeiron POS Enterprise VPS Deployment Completed Successfully!"
echo "=========================================================="

