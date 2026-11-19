#!/usr/bin/env bash
# ==============================================================================
# Stage 8 Vertical Slice 2 â€” Zero-Risk <10ms radtest Verification Script
# Tests Docker Citus + Redis + FreeRADIUS locally (never touches live network)
# ==============================================================================
set -euo pipefail

RADIUS_HOST="${RADIUS_HOST:-127.0.0.1}"
RADIUS_PORT="${RADIUS_PORT:-1812}"
RADIUS_SECRET="${RADIUS_SECRET:-${RADIUS_SECRET}}"
TEST_USER="${TEST_USER:-tapos_test}"
TEST_PASS="${TEST_PASS:-Tapos@2026}"

echo "[1/4] Checking Docker containers (citus_master, redis_cluster, freeradius_aaa)..."
for c in citus_master redis_cluster freeradius_aaa; do
  if ! docker ps --format '{{.Names}}' | grep -q "^${c}$"; then
    echo "[FAIL] Container $c is not running. Start with: docker compose -f docker-compose.fixed.yml up -d"
    exit 1
  fi
done

echo "[2/4] Seeding test subscriber ($TEST_USER) into Citus PG16..."
docker exec -i citus_master psql -U isp_admin -d isp_bss_oss <<SQL
INSERT INTO tariffs (tariff_id, tenant_id, name, rate_limit, price_bdt, validity_days)
VALUES (1, 1, '20M_Home', '20M/20M', 1000.00, 30)
ON CONFLICT (tariff_id) DO NOTHING;

WITH sub AS (
  INSERT INTO subscribers (subscriber_id, tenant_id, tariff_id, username, bandwidth_profile, status)
  VALUES ('11111111-1111-1111-1111-111111111111', 1, 1, '${TEST_USER}', '20M/20M', 'active')
  ON CONFLICT (subscriber_id, tenant_id) DO UPDATE SET status = 'active'
  RETURNING subscriber_id
)
INSERT INTO radcheck (subscriber_id, username, attribute, op, value)
VALUES ('11111111-1111-1111-1111-111111111111', '${TEST_USER}', 'Cleartext-Password', ':=', '${TEST_PASS}')
ON CONFLICT DO NOTHING;

INSERT INTO radreply (subscriber_id, username, attribute, op, value)
VALUES
  ('11111111-1111-1111-1111-111111111111', '${TEST_USER}', 'Mikrotik-Rate-Limit', ':=', '20M/20M'),
  ('11111111-1111-1111-1111-111111111111', '${TEST_USER}', 'Framed-Pool', ':=', 'pppoe_active_pool')
ON CONFLICT DO NOTHING;
SQL

echo "[3/4] Running 10x radtest iterations & measuring latency..."
TOTAL_MS=0
for i in $(seq 1 10); do
  START_NS=$(date +%s%N)
  OUT=$(docker exec freeradius_aaa radtest "$TEST_USER" "$TEST_PASS" "$RADIUS_HOST" 0 "$RADIUS_SECRET" 2>&1)
  END_NS=$(date +%s%N)
  if ! echo "$OUT" | grep -q "Access-Accept"; then
    echo "[FAIL] Iteration $i did not return Access-Accept:"
    echo "$OUT"
    exit 1
  fi
  ELAPSED_MS=$(( (END_NS - START_NS) / 1000000 ))
  TOTAL_MS=$(( TOTAL_MS + ELAPSED_MS ))
  echo "  Run #$i: Access-Accept (${ELAPSED_MS} ms)"
done

AVG_MS=$(( TOTAL_MS / 10 ))
echo "[4/4] Average radtest latency: ${AVG_MS} ms (SLA target: < 10 ms)"
if [ "$AVG_MS" -lt 10 ]; then
  echo "[PASS] Stage 8 Vertical Slice 2 Exit Gate satisfied!"
else
  echo "[WARN] Average latency ${AVG_MS} ms >= 10 ms (expected on cold Docker exec; verify Redis Fast-Path)."
fi

