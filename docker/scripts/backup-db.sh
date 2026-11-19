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
docker exec -i "${DB_CONTAINER}" "${DUMP_BIN}" \
    -u root -p"${DB_ROOT_PASSWORD}" \
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
echo "[+] Step 4/4: Pruning backups older than ${RETENTION_DAYS} days..."
PRUNED_COUNT=$(find "${BACKUP_DIR}" -type f \( -name "apeiron_db_*.sql.gz" -o -name "apeiron_db_*.sql.gz.sha256" \) -mtime "+${RETENTION_DAYS}" | wc -l)
find "${BACKUP_DIR}" -type f \( -name "apeiron_db_*.sql.gz" -o -name "apeiron_db_*.sql.gz.sha256" \) -mtime "+${RETENTION_DAYS}" -delete
echo "    Pruned ${PRUNED_COUNT} expired backup file(s)."

echo "=========================================================="
echo " Database Backup Succeeded deterministically!"
echo " Output:   ${BACKUP_FILE} (${FILE_SIZE})"
echo " Checksum: ${CHECKSUM_FILE}"
echo "=========================================================="
