#!/bin/sh
set -e
sed "s|\${ENV:RADIUS_SECRET}|${RADIUS_SECRET}|g" /tmp/custom_raddb/clients.conf > /etc/freeradius/clients.conf
sed "s|\${ENV:DB_PASSWORD}|${DB_PASSWORD}|g" /tmp/custom_raddb/sql.conf > /etc/freeradius/mods-enabled/sql
cp /tmp/custom_raddb/radiusd.conf /etc/freeradius/radiusd.conf
ln -sf /etc/freeradius/sites-available/coa /etc/freeradius/sites-enabled/coa
chmod 640 /etc/freeradius/radiusd.conf /etc/freeradius/clients.conf /etc/freeradius/mods-enabled/sql
exec freeradius -f
