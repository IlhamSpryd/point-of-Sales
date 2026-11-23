#!/usr/bin/env bash
# ==============================================================================
# Apeiron Systems — Enterprise MariaDB Automated Backup Script (Wave 3D)
# Target: Production VPS (103.247.11.166) | /var/backups/mariadb/
# Safety: --single-transaction (Zero locks / non-blocking InnoDB read)
# Compression: gzip -9 | Integrity: sha256 checksum | Retention: 14 days
# ==============================================================================
set -euo pipefail

BACKUP_DIR="${BACKUP_DIR:-/var/backups/mariadb}"
PROJECT_DIR="${PROJECT_DIR:-/var/www/point-of-sales}"
RETENTION_DAYS="${RETENTION_DAYS:-14}"
TIMESTAMP=$(date '+%Y%m%d_%H%M%S')
LOG_TAG="[Apeiron-DB-Backup]"

echo "${LOG_TAG} Starting MariaDB backup at $(date '+%Y-%m-%d %H:%M:%S')..."

# Load credentials from production .env if available and not already set
if [ -f "${PROJECT_DIR}/.env" ]; then
    # Safely source DB credentials without executing arbitrary code
    DB_DATABASE=$(grep -E '^DB_DATABASE=' "${PROJECT_DIR}/.env" | cut -d '=' -f2- | tr -d '"'\'' ' || echo "apeiron_pos")
    DB_USERNAME=$(grep -E '^DB_USERNAME=' "${PROJECT_DIR}/.env" | cut -d '=' -f2- | tr -d '"'\'' ' || echo "apeiron")
    DB_PASSWORD=$(grep -E '^DB_PASSWORD=' "${PROJECT_DIR}/.env" | cut -d '=' -f2- | tr -d '"'\'' ' || echo "apeiron_secret")
    DB_ROOT_PASSWORD=$(grep -E '^DB_ROOT_PASSWORD=' "${PROJECT_DIR}/.env" | cut -d '=' -f2- | tr -d '"'\'' ' || echo "${DB_PASSWORD}")
fi

DB_NAME="${DB_DATABASE:-apeiron_pos}"
DB_USER="${DB_ROOT_USER:-root}"
DB_PASS="${DB_ROOT_PASSWORD:-${DB_PASSWORD:-root_secret}}"
CONTAINER_NAME="${DB_CONTAINER:-apeiron_db}"

mkdir -p "${BACKUP_DIR}"
chmod 700 "${BACKUP_DIR}"

BACKUP_FILE="${BACKUP_DIR}/db_apeiron_${TIMESTAMP}.sql.gz"
SHA_FILE="${BACKUP_FILE}.sha256"

# Execute single-transaction backup via running docker container or local host client
if command -v docker >/dev/null 2>&1 && docker ps --format '{{.Names}}' | grep -q "^${CONTAINER_NAME}$"; then
    echo "${LOG_TAG} Running mysqldump inside Docker container '${CONTAINER_NAME}'..."
    docker exec "${CONTAINER_NAME}" mariadb-dump \
        -u"${DB_USER}" \
        -p"${DB_PASS}" \
        --single-transaction \
        --quick \
        --routines \
        --triggers \
        --events \
        "${DB_NAME}" | gzip -9 > "${BACKUP_FILE}"
elif command -v mariadb-dump >/dev/null 2>&1; then
    echo "${LOG_TAG} Running host mariadb-dump client..."
    mariadb-dump \
        -u"${DB_USER}" \
        -p"${DB_PASS}" \
        --single-transaction \
        --quick \
        --routines \
        --triggers \
        --events \
        "${DB_NAME}" | gzip -9 > "${BACKUP_FILE}"
elif command -v mysqldump >/dev/null 2>&1; then
    echo "${LOG_TAG} Running host mysqldump client..."
    mysqldump \
        -u"${DB_USER}" \
        -p"${DB_PASS}" \
        --single-transaction \
        --quick \
        --routines \
        --triggers \
        --events \
        "${DB_NAME}" | gzip -9 > "${BACKUP_FILE}"
else
    echo "[-] Error: Neither docker container '${CONTAINER_NAME}' nor local mysqldump client found." >&2
    exit 1
fi

# Verify backup size is non-zero
FILE_SIZE=$(stat -c%s "${BACKUP_FILE}" 2>/dev/null || stat -f%z "${BACKUP_FILE}" 2>/dev/null || wc -c < "${BACKUP_FILE}")
if [ "${FILE_SIZE}" -le 100 ]; then
    echo "[-] Error: Backup file '${BACKUP_FILE}' is corrupted or empty (${FILE_SIZE} bytes)." >&2
    rm -f "${BACKUP_FILE}"
    exit 1
fi

# Compute SHA-256 checksum and enforce strict file permissions
(cd "${BACKUP_DIR}" && sha256sum "$(basename "${BACKUP_FILE}")" > "$(basename "${SHA_FILE}")")
chmod 600 "${BACKUP_FILE}" "${SHA_FILE}"

echo "[+] ${LOG_TAG} Backup completed successfully: ${BACKUP_FILE} ($(numfmt --to=iec-i --suffix=B "${FILE_SIZE}" 2>/dev/null || echo "${FILE_SIZE} bytes"))"
echo "[+] ${LOG_TAG} SHA256: $(cat "${SHA_FILE}")"

# Retention policy: prune backups older than 14 days
echo "${LOG_TAG} Applying ${RETENTION_DAYS}-day retention cleanup in ${BACKUP_DIR}..."
PRUNED_COUNT=$(find "${BACKUP_DIR}" -name "db_apeiron_*.sql.gz*" -type f -mtime +"${RETENTION_DAYS}" | wc -l)
find "${BACKUP_DIR}" -name "db_apeiron_*.sql.gz*" -type f -mtime +"${RETENTION_DAYS}" -delete
echo "[+] ${LOG_TAG} Retention cleanup completed (${PRUNED_COUNT} stale files pruned)."

