#!/usr/bin/env bash
# ==============================================================================
# Script: infra/scripts/register_citus_workers.sh
# Purpose: Idempotent registration of Citus worker nodes on citus_master
# ==============================================================================
set -euo pipefail

docker() {
    if command -v docker.exe >/dev/null 2>&1 && docker.exe ps >/dev/null 2>&1; then
        docker.exe "$@"
    else
        command docker "$@"
    fi
}

DB_USER="${DB_USER:-isp_admin}"
DB_NAME="${DB_NAME:-isp_bss_oss}"
DB_PASS="${DB_PASSWORD:-secure_isp_password_2026}"

echo "Configuring Citus coordinator host and credentials..."
docker exec -u postgres citus_master sh -c "echo '*:*:*:${DB_USER}:${DB_PASS}' > /var/lib/postgresql/.pgpass && chmod 600 /var/lib/postgresql/.pgpass"
docker exec -u postgres citus_worker_1 sh -c "echo '*:*:*:${DB_USER}:${DB_PASS}' > /var/lib/postgresql/.pgpass && chmod 600 /var/lib/postgresql/.pgpass" 2>/dev/null || true
docker exec -u postgres citus_worker_2 sh -c "echo '*:*:*:${DB_USER}:${DB_PASS}' > /var/lib/postgresql/.pgpass && chmod 600 /var/lib/postgresql/.pgpass" 2>/dev/null || true

docker exec citus_master psql -U "${DB_USER}" -d "${DB_NAME}" -q -c "SELECT citus_set_coordinator_host('citus_master', 5432);"

echo "Registering citus_worker_1 and citus_worker_2..."
docker exec citus_master psql -U "${DB_USER}" -d "${DB_NAME}" -q -c "
DO \$\$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_dist_node WHERE nodename = 'citus_worker_1') THEN
        PERFORM citus_add_node('citus_worker_1', 5432);
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_dist_node WHERE nodename = 'citus_worker_2') THEN
        PERFORM citus_add_node('citus_worker_2', 5432);
    END IF;
END \$\$;
"

echo "Active Citus Worker Nodes:"
docker exec citus_master psql -U "${DB_USER}" -d "${DB_NAME}" -c "SELECT * FROM citus_get_active_worker_nodes();"
