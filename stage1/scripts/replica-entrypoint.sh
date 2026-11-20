#!/bin/sh
set -e

echo "[REPLICA] Initializing PostgreSQL Streaming Replication Standby..."

# Wait for primary node
until nc -z -w3 postgres_primary 5432; do
  echo "Waiting for postgres_primary:5432 to become reachable..."
  sleep 1
done

# Perform pg_basebackup if data directory is empty
if [ ! -s "$PGDATA/PG_VERSION" ]; then
  echo "[REPLICA] Empty PGDATA detected. Performing pg_basebackup from postgres_primary..."
  mkdir -p "$PGDATA"
  chmod 700 "$PGDATA"
  
  PGPASSWORD="${REPLICATION_PASSWORD:-ReplSecret2026!}" pg_basebackup \
    -h postgres_primary \
    -p 5432 \
    -U repl_user \
    -D "$PGDATA" \
    -Fp -Xs -R -v

  echo "[REPLICA] pg_basebackup complete. Standby signal and connection info configured."
fi

echo "[REPLICA] Starting PostgreSQL in hot standby mode..."
exec postgres -c hot_standby=on -c primary_conninfo="host=postgres_primary port=5432 user=repl_user password=${REPLICATION_PASSWORD:-ReplSecret2026!}"
