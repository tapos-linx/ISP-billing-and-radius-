#!/bin/sh
set -e

# Support Docker Secrets injection with environment fallback
if [ -f "/run/secrets/radius_secret" ]; then
    RADIUS_SECRET=$(cat /run/secrets/radius_secret | tr -d '\r\n')
fi
if [ -f "/run/secrets/db_password" ]; then
    DB_PASSWORD=$(cat /run/secrets/db_password | tr -d '\r\n')
fi

sed "s|\${ENV:RADIUS_SECRET}|${RADIUS_SECRET}|g" /tmp/custom_raddb/clients.conf > /etc/freeradius/clients.conf
sed "s|\${ENV:DB_PASSWORD}|${DB_PASSWORD}|g" /tmp/custom_raddb/sql.conf > /etc/freeradius/mods-enabled/sql
cp /tmp/custom_raddb/radiusd.conf /etc/freeradius/radiusd.conf
ln -sf /etc/freeradius/sites-available/coa /etc/freeradius/sites-enabled/coa
chmod 640 /etc/freeradius/radiusd.conf /etc/freeradius/clients.conf /etc/freeradius/mods-enabled/sql
exec freeradius -f
