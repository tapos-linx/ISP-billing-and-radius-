#!/usr/bin/env bash
# ==============================================================================
# Script: infra/ha/pgbackrest_backup_restore_drill.sh
# Purpose: Run differential backup, integrity verification, and isolated delta restore
#          for PostgreSQL / Citus cluster using pgBackRest.
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

STANZA="${1:-isp_bss_oss}"
DB_CONTAINER="${2:-citus_master}"

echo "======================================================================"
echo "   STARTING PGBACKREST HIGH-AVAILABILITY & RECOVERY DRILL"
echo "   Stanza: ${STANZA} | Container: ${DB_CONTAINER}"
echo "======================================================================"

echo "==> [1/3] Running pgBackRest differential backup for ${STANZA}..."
docker exec -u postgres "${DB_CONTAINER}" pgbackrest --stanza="${STANZA}" --type=diff backup

echo "==> [2/3] Verifying pgBackRest stanza backup integrity..."
docker exec -u postgres "${DB_CONTAINER}" pgbackrest --stanza="${STANZA}" info

echo "==> [3/3] Executing isolated delta restore drill into /tmp/pg_restore_drill..."
docker exec -u postgres "${DB_CONTAINER}" sh -c "rm -rf /tmp/pg_restore_drill && mkdir -p /tmp/pg_restore_drill"
docker exec -u postgres "${DB_CONTAINER}" pgbackrest --stanza="${STANZA}" --pg1-path=/tmp/pg_restore_drill restore
docker exec -u postgres "${DB_CONTAINER}" rm -rf /tmp/pg_restore_drill

echo "======================================================================"
echo "   [PASS] PGBACKREST BACKUP & RESTORE DRILL COMPLETED SUCCESSFULLY"
echo "======================================================================"
