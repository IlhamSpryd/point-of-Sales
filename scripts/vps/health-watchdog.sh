#!/usr/bin/env bash
# ==============================================================================
# Apeiron Systems — Production VPS Health Watchdog Daemon (Wave 3D)
# Target: Production VPS (103.247.11.166) | Cron: every 5 minutes
# Checks: /health/ready endpoint (Database, Redis, Storage readiness)
# Action: 3x consecutive failures trigger container restart & alert log
# ==============================================================================
set -euo pipefail

LOG_FILE="${LOG_FILE:-/var/log/apeiron-watchdog.log}"
STATE_FILE="${STATE_FILE:-/tmp/apeiron_health_watchdog_failures}"
PROJECT_DIR="${PROJECT_DIR:-/var/www/point-of-sales}"
MAX_FAILURES="${MAX_FAILURES:-3}"
TIMEOUT_SECS="${TIMEOUT_SECS:-10}"

# Probe targets: check local web container port first, then public HTTPS
PROBE_URLS=(
    "http://127.0.0.1:80/health/ready"
    "http://127.0.0.1:8080/health/ready"
    "http://127.0.0.1:80/up"
    "https://app.apeironsystem.web.id/health/ready"
)

log_msg() {
    local level="$1"
    local msg="$2"
    local ts
    ts=$(date '+%Y-%m-%d %H:%M:%S UTC')
    echo "[${ts}] [${level}] ${msg}" | tee -a "${LOG_FILE}" 2>/dev/null || echo "[${ts}] [${level}] ${msg}"
}

# Determine if at least one probe succeeds
PROBE_SUCCESS=false
HTTP_CODE="000"

for url in "${PROBE_URLS[@]}"; do
    HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" --max-time "${TIMEOUT_SECS}" "${url}" || true)
    if [ "${HTTP_CODE}" = "200" ]; then
        PROBE_SUCCESS=true
        ACTIVE_URL="${url}"
        break
    fi
done

CURRENT_FAILURES=0
if [ -f "${STATE_FILE}" ]; then
    CURRENT_FAILURES=$(cat "${STATE_FILE}" 2>/dev/null || echo "0")
fi

if [ "${PROBE_SUCCESS}" = "true" ]; then
    if [ "${CURRENT_FAILURES}" -gt 0 ]; then
        log_msg "INFO" "Health watchdog recovered: probe succeeded on ${ACTIVE_URL} (HTTP 200). Resetting failure counter."
        echo "0" > "${STATE_FILE}"
    fi
    exit 0
fi

# Failure handling
CURRENT_FAILURES=$((CURRENT_FAILURES + 1))
echo "${CURRENT_FAILURES}" > "${STATE_FILE}"

log_msg "WARN" "Health probe failed (HTTP ${HTTP_CODE}). Consecutive failure count: ${CURRENT_FAILURES}/${MAX_FAILURES}."

if [ "${CURRENT_FAILURES}" -ge "${MAX_FAILURES}" ]; then
    log_msg "CRITICAL" "Health probe failed ${CURRENT_FAILURES} consecutive times! Initiating emergency container recovery..."

    cd "${PROJECT_DIR}" 2>/dev/null || true

    if command -v docker >/dev/null 2>&1; then
        if docker compose version >/dev/null 2>&1; then
            docker compose restart app web reverb || true
        elif command -v docker-compose >/dev/null 2>&1; then
            docker-compose restart app web reverb || true
        else
            docker restart apeiron_app apeiron_web apeiron_reverb 2>/dev/null || true
        fi
        log_msg "INFO" "Emergency container restart executed for services: app, web, reverb."
    else
        log_msg "ERROR" "Docker command not available; cannot restart containers."
    fi

    # Reset failure counter after recovery trigger
    echo "0" > "${STATE_FILE}"
fi

