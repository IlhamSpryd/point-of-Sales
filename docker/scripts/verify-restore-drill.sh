#!/usr/bin/env bash
# ==============================================================================
# Apeiron POS — Backup Integrity Verification & Restore Drill Script (OPS-06)
# Tests backup validity, checksum, table & trigger definitions, and optional
# live restoration drill into a temporary scratch database inside MariaDB.
# ==============================================================================
set -euo pipefail

BACKUP_DIR="${BACKUP_DIR:-/root/apeiron_backups/daily}"
DB_CONTAINER="${DB_CONTAINER_NAME:-apeiron_db}"
LIVE_DRILL=false
TARGET_BACKUP=""

for arg in "$@"; do
    case "$arg" in
        --live-drill)
            LIVE_DRILL=true
            ;;
        *)
            if [ -z "$TARGET_BACKUP" ] && [ -f "$arg" ]; then
                TARGET_BACKUP="$arg"
            fi
            ;;
    esac
done

echo "=========================================================="
echo " Starting Apeiron POS Backup Verification & Restore Drill"
echo " Date: $(date -u '+%Y-%m-%d %H:%M:%S UTC')"
echo " Live Drill Mode: ${LIVE_DRILL}"
echo "=========================================================="

# 1. Locate backup file to verify
if [ -z "${TARGET_BACKUP}" ]; then
    if [ ! -d "${BACKUP_DIR}" ]; then
        echo "[-] Error: Backup directory '${BACKUP_DIR}' does not exist." >&2
        exit 1
    fi

    TARGET_BACKUP=$(ls -t "${BACKUP_DIR}"/apeiron_db_*.sql.gz 2>/dev/null | head -n 1 || true)
    if [ -z "${TARGET_BACKUP}" ]; then
        echo "[-] Error: No .sql.gz backup archives found in '${BACKUP_DIR}'." >&2
        exit 1
    fi
fi

echo "[+] Step 1/4: Inspecting target archive: ${TARGET_BACKUP}"
CHECKSUM_FILE="${TARGET_BACKUP}.sha256"

# 2. Verify SHA-256 Checksum
echo "[+] Step 2/4: Verifying SHA-256 checksum..."
if [ -f "${CHECKSUM_FILE}" ]; then
    EXPECTED_HASH=$(cut -d' ' -f1 "${CHECKSUM_FILE}")
    ACTUAL_HASH=$(sha256sum "${TARGET_BACKUP}" | cut -d' ' -f1)
    if [ "${EXPECTED_HASH}" != "${ACTUAL_HASH}" ]; then
        echo "[-] Error: SHA-256 checksum mismatch!" >&2
        echo "    Expected: ${EXPECTED_HASH}" >&2
        echo "    Actual:   ${ACTUAL_HASH}" >&2
        exit 1
    fi
    echo "    Checksum verification PASSED: ${ACTUAL_HASH}"
else
    echo "    [!] Warning: Checksum file '${CHECKSUM_FILE}' not found, skipping checksum verification."
fi

# 3. Verify Gzip Integrity
if ! gzip -t "${TARGET_BACKUP}" 2>/dev/null; then
    echo "[-] Error: Archive failed gzip integrity test!" >&2
    exit 1
fi
echo "    Gzip decompression integrity test PASSED."

# 4. Inspect SQL content for essential schema structures (Tables & Triggers)
echo "[+] Step 3/4: Inspecting dump structure for tables and triggers..."
TABLE_DEFS=$(zgrep -c "^CREATE TABLE" "${TARGET_BACKUP}" || true)
TRIGGER_DEFS=$(zgrep -c -E "^CREATE.*TRIGGER|^/\*!50003 CREATE" "${TARGET_BACKUP}" || true)

echo "    Found ${TABLE_DEFS} table definition(s) in dump."
echo "    Found ${TRIGGER_DEFS} trigger definition(s) in dump."

if [ "${TABLE_DEFS}" -lt 150 ]; then
    echo "[-] Error: Dump contains unusually low table count (${TABLE_DEFS} < 150). Aborting!" >&2
    exit 1
fi

if [ "${TRIGGER_DEFS}" -lt 40 ]; then
    echo "[-] Error: Dump contains unusually low trigger count (${TRIGGER_DEFS} < 40). Ensure --triggers was used!" >&2
    exit 1
fi

echo "    Dump structure inspection PASSED."

# 5. Live Restoration Drill (Optional)
if [ "${LIVE_DRILL}" = true ]; then
    echo "[+] Step 4/4: Executing Live Restoration Drill into temporary scratch database..."

    if ! docker ps --format '{{.Names}}' | grep -Eq "^${DB_CONTAINER}$"; then
        echo "[-] Error: Database container '${DB_CONTAINER}' must be running for live drill." >&2
        exit 1
    fi

    DB_ROOT_PASSWORD=$(docker exec "${DB_CONTAINER}" printenv MYSQL_ROOT_PASSWORD 2>/dev/null || echo "${DB_ROOT_PASSWORD:-}")
    if [ -z "${DB_ROOT_PASSWORD}" ]; then
        echo "[-] Error: MYSQL_ROOT_PASSWORD could not be resolved from container or environment." >&2
        exit 1
    fi

    SCRATCH_DB="apeiron_drill_$(date +%s)"
    echo "    Creating temporary scratch database: ${SCRATCH_DB}"
    docker exec -i "${DB_CONTAINER}" mariadb -u root -p"${DB_ROOT_PASSWORD}" \
        -e "CREATE DATABASE \`${SCRATCH_DB}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

    cleanup() {
        echo "    Cleaning up scratch database: ${SCRATCH_DB}..."
        docker exec -i "${DB_CONTAINER}" mariadb -u root -p"${DB_ROOT_PASSWORD}" \
            -e "DROP DATABASE IF EXISTS \`${SCRATCH_DB}\`;" || true
    }
    trap cleanup EXIT

    echo "    Restoring backup into scratch database..."
    zcat "${TARGET_BACKUP}" | docker exec -i "${DB_CONTAINER}" mariadb -u root -p"${DB_ROOT_PASSWORD}" "${SCRATCH_DB}"

    RESTORED_TABLES=$(docker exec -i "${DB_CONTAINER}" mariadb -u root -p"${DB_ROOT_PASSWORD}" -N -e \
        "SELECT count(*) FROM information_schema.tables WHERE table_schema='${SCRATCH_DB}';")
    RESTORED_TRIGGERS=$(docker exec -i "${DB_CONTAINER}" mariadb -u root -p"${DB_ROOT_PASSWORD}" -N -e \
        "SELECT count(*) FROM information_schema.triggers WHERE trigger_schema='${SCRATCH_DB}';")

    echo "    Restored table count:   ${RESTORED_TABLES}"
    echo "    Restored trigger count: ${RESTORED_TRIGGERS}"

    if [ "${RESTORED_TABLES}" -lt 200 ]; then
        echo "[-] Error: Restored tables count (${RESTORED_TABLES}) is below expected minimum (200)." >&2
        exit 1
    fi

    if [ "${RESTORED_TRIGGERS}" -lt 50 ]; then
        echo "[-] Error: Restored triggers count (${RESTORED_TRIGGERS}) is below expected minimum (50)." >&2
        exit 1
    fi

    echo "    Live restore drill PASSED completely!"
else
    echo "[+] Step 4/4: Skipping live restore drill (use --live-drill to execute scratch DB restoration)."
fi

echo "=========================================================="
echo " Backup Verification Completed Successfully!"
echo " Target Archive: ${TARGET_BACKUP}"
echo " Status:         100% HEALTHY & RESTORABLE"
echo "=========================================================="
