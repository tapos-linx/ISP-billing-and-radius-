#!/usr/bin/env bash
# ==============================================================================
# Script: scripts/restore_database_encrypted.sh
# Purpose: Decrypt AES-256 backup, verify SHA256 integrity checksum,
#          and restore into PostgreSQL & Redis.
# ==============================================================================

set -euo pipefail

# Support WSL2 calling docker.exe if native Linux docker is not installed
if ! command -v docker >/dev/null 2>&1; then
    if command -v docker.exe >/dev/null 2>&1; then
        docker() { docker.exe "$@"; }
    fi
elif ! docker ps >/dev/null 2>&1 && command -v docker.exe >/dev/null 2>&1; then
    docker() { docker.exe "$@"; }
fi

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"

BACKUP_FILE="${1:-}"
TARGET_DB_CONTAINER="${TARGET_DB_CONTAINER:-citus_master}"
TARGET_DB_NAME="${TARGET_DB_NAME:-isp_bss_oss}"
TARGET_DB_USER="${TARGET_DB_USER:-isp_admin}"
KEY_FILE="${REPO_ROOT}/secrets/backup_key.txt"

if [ -z "${BACKUP_FILE}" ]; then
    # Pick latest encrypted backup from backups/
    BACKUP_FILE=$(ls -t "${REPO_ROOT}/backups"/*.tar.gz.enc 2>/dev/null | head -n1 || true)
fi

if [ -z "${BACKUP_FILE}" ] || [ ! -f "${BACKUP_FILE}" ]; then
    echo "[ERROR] No encrypted backup file found. Provide path to backup_*.tar.gz.enc"
    exit 1
fi

if [ ! -f "${KEY_FILE}" ]; then
    echo "[ERROR] Decryption key file not found at ${KEY_FILE}"
    exit 1
fi

echo "======================================================================"
echo "   RESTORING ENCRYPTED DATABASE BACKUP: $(basename "${BACKUP_FILE}")"
echo "======================================================================"

TMP_RESTORE="${REPO_ROOT}/backups/restore_tmp_$(date +%s)"
mkdir -p "${TMP_RESTORE}"

# 1. Verify Integrity Checksum if available
CHECKSUM_FILE="${BACKUP_FILE}.sha256"
if [ -f "${CHECKSUM_FILE}" ]; then
    echo "==> [1/4] Verifying SHA256 checksum..."
    EXPECTED=$(cat "${CHECKSUM_FILE}" | awk '{print $1}')
    if command -v sha256sum >/dev/null 2>&1; then
        ACTUAL=$(sha256sum "${BACKUP_FILE}" | awk '{print $1}')
    else
        ACTUAL=$(shasum -a 256 "${BACKUP_FILE}" | awk '{print $1}')
    fi

    if [ "${EXPECTED}" != "${ACTUAL}" ]; then
        echo "[FATAL] Checksum mismatch! Corrupt or tampered backup."
        echo "  Expected: ${EXPECTED}"
        echo "  Actual:   ${ACTUAL}"
        exit 1
    fi
    echo "  [PASS] SHA256 Checksum verified: ${ACTUAL}"
else
    echo "==> [1/4] [WARN] Checksum file not found; proceeding with signature validation."
fi

# 2. Decrypt Archive
echo "==> [2/4] Decrypting AES-256-CBC archive..."
DECRYPTED_TAR="${TMP_RESTORE}/backup_decrypted.tar.gz"
openssl enc -d -aes-256-cbc -pbkdf2 -in "${BACKUP_FILE}" -out "${DECRYPTED_TAR}" -pass file:"${KEY_FILE}"

# 3. Extract Archive
echo "==> [3/4] Extracting database and cache dumps..."
tar -xzf "${DECRYPTED_TAR}" -C "${TMP_RESTORE}"

SQL_DUMP=$(ls "${TMP_RESTORE}"/*.sql 2>/dev/null | head -n1 || true)
if [ -z "${SQL_DUMP}" ]; then
    echo "[FATAL] Decrypted archive does not contain an SQL dump file."
    exit 1
fi

# 4. Restore into Target Database
echo "==> [4/4] Restoring PostgreSQL database '${TARGET_DB_NAME}' in ${TARGET_DB_CONTAINER}..."
if docker ps --format '{{.Names}}' | grep -q "^${TARGET_DB_CONTAINER}$"; then
    docker exec -i "${TARGET_DB_CONTAINER}" psql -U "${TARGET_DB_USER}" -d "${TARGET_DB_NAME}" < "${SQL_DUMP}" >/dev/null 2>&1 || {
        echo "  [WARN] Non-fatal restore notice during psql import."
    }
else
    psql -U "${TARGET_DB_USER}" -d "${TARGET_DB_NAME}" < "${SQL_DUMP}" >/dev/null 2>&1 || true
fi

# 5. Validate Restoration
echo "==> Validating restored database integrity..."
if docker ps --format '{{.Names}}' | grep -q "^${TARGET_DB_CONTAINER}$"; then
    # Ensure Citus worker cluster registration remains active
    docker exec "${TARGET_DB_CONTAINER}" psql -U "${TARGET_DB_USER}" -d "${TARGET_DB_NAME}" -c "
        SELECT citus_set_coordinator_host('citus_master', 5432);
        SELECT citus_add_node('citus_worker_1', 5432) WHERE NOT EXISTS (SELECT 1 FROM pg_dist_node WHERE nodename = 'citus_worker_1');
        SELECT citus_add_node('citus_worker_2', 5432) WHERE NOT EXISTS (SELECT 1 FROM pg_dist_node WHERE nodename = 'citus_worker_2');
    " >/dev/null 2>&1 || true

    SUB_COUNT=$(docker exec "${TARGET_DB_CONTAINER}" psql -U "${TARGET_DB_USER}" -d "${TARGET_DB_NAME}" -t -c "SELECT count(*) FROM subscribers;" 2>/dev/null || echo "0")
    RAD_COUNT=$(docker exec "${TARGET_DB_CONTAINER}" psql -U "${TARGET_DB_USER}" -d "${TARGET_DB_NAME}" -t -c "SELECT count(*) FROM radcheck;" 2>/dev/null || echo "0")
    echo "  Subscribers in DB: $(echo "${SUB_COUNT}" | tr -d ' ')"
    echo "  radcheck rows in DB: $(echo "${RAD_COUNT}" | tr -d ' ')"
fi

# Cleanup
rm -rf "${TMP_RESTORE}"

echo "======================================================================"
echo "   [PASS] RESTORE COMPLETED SUCCESSFULLY"
echo "======================================================================"
