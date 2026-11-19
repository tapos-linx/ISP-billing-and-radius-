#!/usr/bin/env bash
set -euo pipefail

STANZA="isp_bss_oss"
echo "[1/3] Running pgBackRest differential backup for radacct/radcheck/radreply..."
docker exec citus_master pgbackrest --stanza="$STANZA" --type=diff backup

echo "[2/3] Verifying backup integrity..."
docker exec citus_master pgbackrest --stanza="$STANZA" info

echo "[3/3] Executing isolated restore drill into /tmp/pg_restore_drill..."
rm -rf /tmp/pg_restore_drill && mkdir -p /tmp/pg_restore_drill
docker exec citus_master pgbackrest --stanza="$STANZA" --pg1-path=/tmp/pg_restore_drill --delta restore
echo "[PASS] pgBackRest backup and restore drill completed."
