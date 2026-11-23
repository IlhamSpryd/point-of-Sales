#!/usr/bin/env bash
# ==============================================================================
# Apeiron POS — Automated MariaDB Docker Daily Backup Script (OPS-06)
# Target: MariaDB 11.4 Docker container (apeiron_db)
# Features: Zero-lock downtime, triggers/routines/events preservation,
#           gzip -9 compression, SHA-256 checksum, automated retention pruning.
# ==============================================================================
set -euo pipefail

BACKUP_DIR="${BACKUP_DIR:-/root/apeiron_backups/daily}"
RETENTION_DAYS="${RETENTION_DAYS:-14}"
DB_CONTAINER="${DB_CONTAINER_NAME:-apeiron_db}"

echo "=========================================================="
echo " Starting Apeiron POS Automated Database Backup"
echo " Date: $(date -u '+%Y-%m-%d %H:%M:%S UTC')"
echo " Target Container: ${DB_CONTAINER}"
echo " Backup Directory: ${BACKUP_DIR}"
echo " Retention Days:   ${RETENTION_DAYS} days"
echo "=========================================================="

# 1. Ensure target container is running
if ! docker ps --format '{{.Names}}' | grep -Eq "^${DB_CONTAINER}$"; then
    echo "[-] Error: Database container '${DB_CONTAINER}' is not running." >&2
    exit 1
fi

# 2. Extract database credentials from running container or environment
DB_ROOT_PASSWORD=$(docker exec "${DB_CONTAINER}" printenv MYSQL_ROOT_PASSWORD 2>/dev/null || echo "${DB_ROOT_PASSWORD:-}")
DB_NAME=$(docker exec "${DB_CONTAINER}" printenv MYSQL_DATABASE 2>/dev/null || echo "${DB_DATABASE:-point_of_sales}")

if [ -z "${DB_ROOT_PASSWORD}" ]; then
    echo "[-] Error: MYSQL_ROOT_PASSWORD could not be resolved from container or environment." >&2
    exit 1
fi

# 3. Detect dump tool inside container (mariadb-dump preferred with fallback to mysqldump)
if docker exec "${DB_CONTAINER}" which mariadb-dump >/dev/null 2>&1; then
    DUMP_BIN="mariadb-dump"
elif docker exec "${DB_CONTAINER}" which mysqldump >/dev/null 2>&1; then
    DUMP_BIN="mysqldump"
else
    echo "[-] Error: Neither mariadb-dump nor mysqldump found inside '${DB_CONTAINER}'." >&2
    exit 1
fi

# 4. Prepare backup output directory and filename
mkdir -p "${BACKUP_DIR}"
TIMESTAMP=$(date '+%Y%m%d_%H%M%S')
BACKUP_FILE="${BACKUP_DIR}/apeiron_db_${TIMESTAMP}.sql.gz"
CHECKSUM_FILE="${BACKUP_FILE}.sha256"

echo "[+] Step 1/4: Dumping database '${DB_NAME}' via ${DUMP_BIN} (single-transaction, zero-lock)..."
# All 232 tables, 57 triggers, routines, and events dumped without write lock contention
docker exec -i -e MYSQL_PWD="${DB_ROOT_PASSWORD}" "${DB_CONTAINER}" "${DUMP_BIN}" \
    -u root \
    --triggers \
    --routines \
    --events \
    --single-transaction \
    --quick \
    --lock-tables=false \
    "${DB_NAME}" | gzip -9 > "${BACKUP_FILE}"

# 5. Verify archive existence and non-empty size
echo "[+] Step 2/4: Verifying backup archive integrity..."
if [ ! -s "${BACKUP_FILE}" ]; then
    echo "[-] Error: Backup file '${BACKUP_FILE}' is missing or empty (0 bytes)!" >&2
    rm -f "${BACKUP_FILE}"
    exit 1
fi

# Gzip archive integrity test
if ! gzip -t "${BACKUP_FILE}" 2>/dev/null; then
    echo "[-] Error: Backup archive '${BACKUP_FILE}' failed gzip integrity test!" >&2
    exit 1
fi

FILE_SIZE=$(du -h "${BACKUP_FILE}" | cut -f1)
echo "    Archive verified OK! Size: ${FILE_SIZE}"

# 6. Generate SHA-256 checksum
echo "[+] Step 3/4: Generating SHA-256 checksum file..."
sha256sum "${BACKUP_FILE}" > "${CHECKSUM_FILE}"
SHA256_HASH=$(cut -d' ' -f1 "${CHECKSUM_FILE}")
echo "    SHA-256: ${SHA256_HASH}"

# 7. Retention policy pruning
echo "[+] Step 4/5: Pruning backups older than ${RETENTION_DAYS} days..."
PRUNED_COUNT=$(find "${BACKUP_DIR}" -type f \( -name "apeiron_db_*.sql.gz" -o -name "apeiron_db_*.sql.gz.sha256" \) -mtime "+${RETENTION_DAYS}" | wc -l)
find "${BACKUP_DIR}" -type f \( -name "apeiron_db_*.sql.gz" -o -name "apeiron_db_*.sql.gz.sha256" \) -mtime "+${RETENTION_DAYS}" -delete
echo "    Pruned ${PRUNED_COUNT} expired backup file(s)."

# 8. Optional Off-Site Cloud Storage Backup Sync (OPS-06)
echo "[+] Step 5/5: Off-site Cloud Storage Backup Sync (Cloudflare R2 / S3)..."
APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
if [ -f "${APP_DIR}/.env" ]; then
    # Safely import OFFSITE variables without evaluating executable commands
    OFFSITE_ENABLED_VAR=$(grep -E '^OFFSITE_BACKUP_ENABLED=' "${APP_DIR}/.env" | cut -d '=' -f2- | tr -d ' "\047' || true)
    OFFSITE_RCLONE_VAR=$(grep -E '^OFFSITE_RCLONE_REMOTE=' "${APP_DIR}/.env" | cut -d '=' -f2- | tr -d ' "\047' || true)
    OFFSITE_S3_VAR=$(grep -E '^OFFSITE_S3_BUCKET=' "${APP_DIR}/.env" | cut -d '=' -f2- | tr -d ' "\047' || true)
    OFFSITE_DAYS_VAR=$(grep -E '^OFFSITE_RETENTION_DAYS=' "${APP_DIR}/.env" | cut -d '=' -f2- | tr -d ' "\047' || true)

    OFFSITE_BACKUP_ENABLED="${OFFSITE_BACKUP_ENABLED:-${OFFSITE_ENABLED_VAR:-false}}"
    OFFSITE_RCLONE_REMOTE="${OFFSITE_RCLONE_REMOTE:-${OFFSITE_RCLONE_VAR:-}}"
    OFFSITE_S3_BUCKET="${OFFSITE_S3_BUCKET:-${OFFSITE_S3_VAR:-}}"
    OFFSITE_RETENTION_DAYS="${OFFSITE_RETENTION_DAYS:-${OFFSITE_DAYS_VAR:-30}}"
fi

OFFSITE_BACKUP_ENABLED="${OFFSITE_BACKUP_ENABLED:-false}"
OFFSITE_RETENTION_DAYS="${OFFSITE_RETENTION_DAYS:-30}"

if [ "${OFFSITE_BACKUP_ENABLED}" = "true" ]; then
    if [ -n "${OFFSITE_RCLONE_REMOTE:-}" ]; then
        if command -v rclone >/dev/null 2>&1; then
            echo "    Syncing backup and checksum to rclone remote: ${OFFSITE_RCLONE_REMOTE}..."
            rclone copyto "${BACKUP_FILE}" "${OFFSITE_RCLONE_REMOTE}/$(basename "${BACKUP_FILE}")"
            rclone copyto "${CHECKSUM_FILE}" "${OFFSITE_RCLONE_REMOTE}/$(basename "${CHECKSUM_FILE}")"
            echo "    Pruning remote backups older than ${OFFSITE_RETENTION_DAYS} days..."
            rclone delete --min-age "${OFFSITE_RETENTION_DAYS}d" "${OFFSITE_RCLONE_REMOTE}" || true
            echo "    ✓ Off-site R2/S3 upload and retention pruning completed successfully."
        else
            echo "    [-] Warning: OFFSITE_BACKUP_ENABLED=true but 'rclone' binary is not installed on host." >&2
        fi
    elif [ -n "${OFFSITE_S3_BUCKET:-}" ]; then
        if command -v aws >/dev/null 2>&1; then
            echo "    Syncing backup and checksum to AWS S3 bucket: ${OFFSITE_S3_BUCKET}..."
            aws s3 cp "${BACKUP_FILE}" "s3://${OFFSITE_S3_BUCKET}/$(basename "${BACKUP_FILE}")"
            aws s3 cp "${CHECKSUM_FILE}" "s3://${OFFSITE_S3_BUCKET}/$(basename "${CHECKSUM_FILE}")"
            echo "    ✓ Off-site S3 upload completed successfully."
        else
            echo "    [-] Warning: OFFSITE_BACKUP_ENABLED=true but 'aws' CLI binary is not installed on host." >&2
        fi
    else
        echo "    [-] Warning: OFFSITE_BACKUP_ENABLED=true but neither OFFSITE_RCLONE_REMOTE nor OFFSITE_S3_BUCKET is configured." >&2
    fi
else
    echo "    [i] Off-site backup disabled (local backup retained)."
fi

echo "=========================================================="
echo " Database Backup Succeeded deterministically!"
echo " Output:   ${BACKUP_FILE} (${FILE_SIZE})"
echo " Checksum: ${CHECKSUM_FILE}"
echo "=========================================================="
