#!/bin/sh
set -e

# Create replication user for streaming replication
psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "$POSTGRES_DB" <<-EOSQL
    DO \$\$
    BEGIN
        IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'repl_user') THEN
            CREATE ROLE repl_user WITH REPLICATION LOGIN PASSWORD '${REPLICATION_PASSWORD:-ReplSecret2026!}';
        END IF;
    END
    \$\$;
EOSQL

# Allow replication connections in pg_hba.conf
if [ -f "$PGDATA/pg_hba.conf" ]; then
    echo "host replication repl_user 0.0.0.0/0 md5" >> "$PGDATA/pg_hba.conf"
    echo "host replication repl_user 0.0.0.0/0 trust" >> "$PGDATA/pg_hba.conf"
    echo "host all all 0.0.0.0/0 md5" >> "$PGDATA/pg_hba.conf"
fi
