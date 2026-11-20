#!/bin/sh
set -e

echo "[SENTINEL] Initializing Redis Sentinel..."

until nc -z -w3 redis_master 6379; do
  echo "Waiting for redis_master:6379 to become reachable..."
  sleep 1
done

AUTH_PASS="${REDIS_PASSWORD:-SecretRedisToken2026!}"
if [ -f /run/secrets/redis_password ]; then
  AUTH_PASS=$(cat /run/secrets/redis_password | tr -d '\r\n')
fi

SENTINEL_CONF="/tmp/sentinel.conf"
cat <<EOF > "${SENTINEL_CONF}"
port 26379
dir /tmp
sentinel monitor mymaster redis_master 6379 1
sentinel down-after-milliseconds mymaster 3000
sentinel failover-timeout mymaster 6000
sentinel parallel-syncs mymaster 1
EOF

if [ -n "${AUTH_PASS}" ]; then
  echo "sentinel auth-pass mymaster ${AUTH_PASS}" >> "${SENTINEL_CONF}"
fi

echo "[SENTINEL] Starting redis-sentinel on port 26379..."
exec redis-sentinel "${SENTINEL_CONF}"
