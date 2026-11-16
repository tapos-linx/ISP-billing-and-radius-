#!/bin/sh
set -e

# ====================================================================
# FreeRADIUS 3.2 Container Entrypoint Script
# Strips Windows CRLF (\r) and initializes rlm_sql with PostgreSQL
# ====================================================================

echo "[INIT] Sanitizing configuration line endings from Windows CRLF..."

# Sanitize radiusd.conf, clients.conf, and mods-available/sql
if [ -f /etc/raddb/radiusd.conf ]; then
    tr -d '\r' < /etc/raddb/radiusd.conf > /tmp/radiusd.conf && cp /tmp/radiusd.conf /etc/raddb/radiusd.conf
fi

if [ -f /etc/raddb/clients.conf ]; then
    tr -d '\r' < /etc/raddb/clients.conf > /tmp/clients.conf && cp /tmp/clients.conf /etc/raddb/clients.conf
fi

if [ -f /etc/raddb/mods-available/sql ]; then
    mkdir -p /etc/raddb/mods-enabled
    tr -d '\r' < /etc/raddb/mods-available/sql > /etc/raddb/mods-enabled/sql
fi

# Enable SQL module in default site if not present
if [ -f /etc/raddb/sites-available/default ]; then
    tr -d '\r' < /etc/raddb/sites-available/default > /tmp/default_site && cp /tmp/default_site /etc/raddb/sites-available/default
fi

# Wait for PostgreSQL port 5432
echo "[WAIT] Testing connection to PostgreSQL (postgres:5432)..."
until nc -z -v -w3 postgres 5432 2>/dev/null; do
    echo "PostgreSQL is not ready yet. Retrying in 1s..."
    sleep 1
done

echo "[READY] PostgreSQL 16 is online! Launching FreeRADIUS 3.2..."
exec radiusd -f -l stdout
