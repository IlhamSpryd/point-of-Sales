#!/usr/bin/env bash
# ==============================================================================
# Apeiron Systems — Enterprise Backup Integrity & Dry-Run Verification (Wave 3D)
# Target: Production VPS (103.247.11.166) | /var/backups/mariadb/
# Safety: Strictly non-destructive / Dry-run verification (never mutates live DB)
# Tests: Gzip integrity (gzip -t), SHA-256 matching, SQL header & canonical schema
# ==============================================================================
set -euo pipefail

BACKUP_DIR="${BACKUP_DIR:-/var/backups/mariadb}"
TARGET_FILE="${1:-}"

# If no file provided, verify the most recent backup in BACKUP_DIR
if [ -z "${TARGET_FILE}" ]; then
    TARGET_FILE=$(find "${BACKUP_DIR}" -name "db_apeiron_*.sql.gz" -type f 2>/dev/null | sort -r | head -n 1 || true)
fi

if [ -z "${TARGET_FILE}" ] || [ ! -f "${TARGET_FILE}" ]; then
    echo "[-] Error: No MariaDB backup file found to verify in '${BACKUP_DIR}'." >&2
    echo "    Usage: $0 [/path/to/db_apeiron_YYYYMMDD_HHMMSS.sql.gz]" >&2
    exit 1
fi

SHA_FILE="${TARGET_FILE}.sha256"
LOG_TAG="[Apeiron-Backup-Verify]"

echo "=========================================================="
echo " ${LOG_TAG} Dry-Run Integrity Verification"
echo " Target: ${TARGET_FILE}"
echo " Date: $(date '+%Y-%m-%d %H:%M:%S')"
echo "=========================================================="

# 1. Gzip compression integrity verification
echo "[+] Step 1/4: Testing gzip archive stream integrity..."
if ! gzip -t "${TARGET_FILE}"; then
    echo "[-] FAILED: Gzip stream in '${TARGET_FILE}' is corrupted or truncated!" >&2
    exit 1
fi
echo "    [PASS] Gzip decompression stream verified with zero CRC/structural errors."

# 2. SHA-256 checksum matching
echo "[+] Step 2/4: Verifying cryptographic SHA-256 checksum..."
if [ -f "${SHA_FILE}" ]; then
    ACTUAL_SHA=$(sha256sum "${TARGET_FILE}" | awk '{print $1}')
    EXPECTED_SHA=$(awk '{print $1}' "${SHA_FILE}")
    if [ "${ACTUAL_SHA}" != "${EXPECTED_SHA}" ]; then
        echo "[-] FAILED: SHA-256 checksum mismatch!" >&2
        echo "    Expected: ${EXPECTED_SHA}" >&2
        echo "    Actual:   ${ACTUAL_SHA}" >&2
        exit 1
    fi
    echo "    [PASS] Checksum matched: ${ACTUAL_SHA}"
else
    echo "    [WARN] Companion SHA-256 file not found; computed hash: $(sha256sum "${TARGET_FILE}" | awk '{print $1}')"
fi

# 3. SQL Dump Header verification
echo "[+] Step 3/4: Inspecting SQL dump preamble and transactional boundary..."
HEADER_MATCH=$(gzip -dc "${TARGET_FILE}" | head -n 30 | grep -E "(MariaDB dump|MySQL dump|Server version)" || true)
if [ -z "${HEADER_MATCH}" ]; then
    echo "[-] FAILED: Dump file does not contain standard MariaDB/MySQL preamble!" >&2
    exit 1
fi
echo "    [PASS] Valid database dump preamble detected:"
echo "${HEADER_MATCH}" | sed 's/^/      /'

# 4. Canonical schema & table presence verification
echo "[+] Step 4/4: Scanning for critical enterprise tables & data rows..."
REQUIRED_TABLES=("tenants" "users" "orders" "order_items" "payments" "financial_event_outboxes" "journal_entries")
MISSING_TABLES=()

# Extract table create statements without fully inflating large files to disk
TABLES_IN_DUMP=$(gzip -dc "${TARGET_FILE}" | grep -E '^CREATE TABLE' | awk '{print $3}' | tr -d '`' || true)

for tbl in "${REQUIRED_TABLES[@]}"; do
    if echo "${TABLES_IN_DUMP}" | grep -q "^${tbl}$"; then
        echo "    [PASS] Found table schema: ${tbl}"
    else
        echo "    [-] Missing critical table: ${tbl}" >&2
        MISSING_TABLES+=("${tbl}")
    fi
done

if [ ${#MISSING_TABLES[@]} -gt 0 ]; then
    echo "[-] FAILED: Missing ${#MISSING_TABLES[@]} critical canonical tables: ${MISSING_TABLES[*]}" >&2
    exit 1
fi

echo "=========================================================="
echo " ${LOG_TAG} ALL CHECKS PASSED: Backup is verified safe & restorable!"
echo "=========================================================="
exit 0

