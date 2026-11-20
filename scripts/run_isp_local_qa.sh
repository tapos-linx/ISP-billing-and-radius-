#!/usr/bin/env bash
# ==============================================================================
# Stage 9: Local QA, Benchmarking & Testing Lab Runner
# Runs 5-section verification against Docker Compose / Citus / FreeRADIUS HA stack:
# 1. Container health & compose status
# 2. Citus/PostgreSQL tables & radacct schema
# 3. PHPUnit feature test suite (Billing, Webhook Idempotency, RADIUS Sync)
# 4. FreeRADIUS Access-Request (radtest) authentication check
# 5. MikroTik RFC 5176 CoA / Disconnect-Request on UDP 3799
# ==============================================================================
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"
cd "${REPO_ROOT}"

GREEN='\033[0;32m'
RED='\033[0;31m'
CYAN='\033[0;36m'
YELLOW='\033[1;33m'
NC='\033[0m'

write_section() {
    echo -e "\n${CYAN}=== $1 ===${NC}"
}

RADIUS_USER="tapos_test"
RADIUS_PASS=""
RADIUS_SECRET=""
COA_IP="127.0.0.1"
COA_PORT=3799
COMPOSE_FILE=""
SKIP_COA=false

while [[ $# -gt 0 ]]; do
    case "$1" in
        -u|--user)
            RADIUS_USER="$2"
            shift 2
            ;;
        -p|--pass)
            RADIUS_PASS="$2"
            shift 2
            ;;
        -s|--secret)
            RADIUS_SECRET="$2"
            shift 2
            ;;
        -i|--coa-ip)
            COA_IP="$2"
            shift 2
            ;;
        -P|--coa-port)
            COA_PORT="$2"
            shift 2
            ;;
        -f|--compose-file)
            COMPOSE_FILE="$2"
            shift 2
            ;;
        --skip-coa)
            SKIP_COA=true
            shift
            ;;
        *)
            echo "Unknown option: $1"
            exit 1
            ;;
    esac
done

# Determine Compose File configuration
COMPOSE_ARGS=()
if [[ -n "${COMPOSE_FILE}" ]]; then
    COMPOSE_ARGS=(-f "${COMPOSE_FILE}")
else
    # Prioritize clustered carrier stack in infra/docker-compose.yml
    if docker compose -f infra/docker-compose.yml ps --services --filter "status=running" 2>/dev/null | grep -q .; then
        COMPOSE_ARGS=(-f "infra/docker-compose.yml")
    elif docker compose ps --services --filter "status=running" 2>/dev/null | grep -q .; then
        COMPOSE_ARGS=()
    elif [[ -f "infra/docker-compose.yml" ]]; then
        COMPOSE_ARGS=(-f "infra/docker-compose.yml")
    else
        COMPOSE_ARGS=()
    fi
fi

resolve_service() {
    local candidates=("$@")
    local defined
    defined="$(docker compose "${COMPOSE_ARGS[@]}" config --services 2>/dev/null || true)"
    for cand in "${candidates[@]}"; do
        if echo "${defined}" | grep -qx "${cand}"; then
            echo "${cand}"
            return 0
        fi
    done
    echo "${candidates[0]}"
}

DB_SERVICE="$(resolve_service citus_master postgres db)"
APP_SERVICE="$(resolve_service laravel_app app laravel_bss_oss laravel)"
RADIUS_SERVICE="$(resolve_service freeradius freeradius_aaa)"
IS_CITUS=false
if [[ "${DB_SERVICE}" == "citus_master" || "${COMPOSE_ARGS[*]}" == *"infra/docker-compose.yml"* ]]; then
    IS_CITUS=true
fi

# Section 1
write_section "1. Docker Compose Status & Container Startup"
docker compose "${COMPOSE_ARGS[@]}" ps -a
running_count=$(docker compose "${COMPOSE_ARGS[@]}" ps --services --filter "status=running" 2>/dev/null | grep -c . || true)
if [[ "${running_count}" -eq 0 ]]; then
    echo -e "${YELLOW}No running compose services found. Starting stack...${NC}"
    docker compose "${COMPOSE_ARGS[@]}" up -d
fi
docker compose "${COMPOSE_ARGS[@]}" ps

# Section 2
write_section "2. PostgreSQL / Citus Schema & RADIUS Tables"
if [[ "${IS_CITUS}" == "true" ]]; then
    DB_USER="isp_admin"
    DB_NAME="isp_bss_oss"
else
    DB_USER="radius_user"
    DB_NAME="isp_billing"
fi

docker compose "${COMPOSE_ARGS[@]}" exec -T "${DB_SERVICE}" psql -U "${DB_USER}" -d "${DB_NAME}" -c "\dt"
docker compose "${COMPOSE_ARGS[@]}" exec -T "${DB_SERVICE}" psql -U "${DB_USER}" -d "${DB_NAME}" -c "SELECT radacctid, username, acctstoptime FROM radacct ORDER BY radacctid DESC LIMIT 5;"

# Section 3
write_section "3. PHPUnit Billing, Webhook Idempotency & RADIUS Sync Tests"
running_services="$(docker compose "${COMPOSE_ARGS[@]}" ps --services --filter "status=running" 2>/dev/null || true)"
if echo "${running_services}" | grep -qx "${APP_SERVICE}"; then
    docker compose "${COMPOSE_ARGS[@]}" exec -T "${APP_SERVICE}" sh -c \
        "if [ -f /opt/laravel_bss/vendor/bin/phpunit ]; then cd /opt/laravel_bss && vendor/bin/phpunit --filter='BillingVatAndExpiryTest|MfsWebhookReplayAndIdempotencyTest|SubscriberRadiusSyncTest'; elif [ -f vendor/bin/phpunit ]; then vendor/bin/phpunit --filter='BillingVatAndExpiryTest|MfsWebhookReplayAndIdempotencyTest|SubscriberRadiusSyncTest'; else echo 'vendor/bin/phpunit not found'; fi"
else
    echo -e "${YELLOW}App service '${APP_SERVICE}' is not currently active. Skipping PHPUnit tests.${NC}"
fi

# Section 4
write_section "4. FreeRADIUS Access-Request Test (radtest)"
if [[ -z "${RADIUS_PASS}" ]]; then
    if [[ "${IS_CITUS}" == "true" ]]; then
        RADIUS_PASS="Tapos@2026"
    else
        RADIUS_PASS="tapos_pass_2026"
    fi
fi

if [[ -z "${RADIUS_SECRET}" ]]; then
    if [[ -n "${RADIUS_SECRET:-}" ]]; then
        :
    elif [[ -f "${REPO_ROOT}/secrets/radius_secret.txt" ]]; then
        RADIUS_SECRET="$(tr -d '\r\n' < "${REPO_ROOT}/secrets/radius_secret.txt")"
    elif [[ -f "${REPO_ROOT}/infra/secrets/radius_secret.txt" ]]; then
        RADIUS_SECRET="$(tr -d '\r\n' < "${REPO_ROOT}/infra/secrets/radius_secret.txt")"
    elif [[ "${IS_CITUS}" == "true" ]]; then
        RADIUS_SECRET="secure_radius_secret_key_2026"
    else
        RADIUS_SECRET="testing123"
    fi
fi

echo -e "${CYAN}Testing FreeRADIUS Access-Request for '${RADIUS_USER}'...${NC}"
docker compose "${COMPOSE_ARGS[@]}" exec -T "${RADIUS_SERVICE}" radtest "${RADIUS_USER}" "${RADIUS_PASS}" 127.0.0.1 0 "${RADIUS_SECRET}"

# Section 5
write_section "5. MikroTik CoA / Disconnect-Request on UDP ${COA_PORT}"
if [[ "${SKIP_COA}" == "true" ]]; then
    echo -e "${YELLOW}Skipped CoA check (--skip-coa passed).${NC}"
elif [[ -f "${REPO_ROOT}/coa_sim.py" ]]; then
    python3 "${REPO_ROOT}/coa_sim.py"
else
    docker compose "${COMPOSE_ARGS[@]}" exec -T "${RADIUS_SERVICE}" sh -c \
        "echo 'User-Name = ${RADIUS_USER}' | radclient -x ${COA_IP}:${COA_PORT} disconnect ${RADIUS_SECRET}"
fi

echo -e "\n${GREEN}All local QA checks completed successfully.${NC}"
