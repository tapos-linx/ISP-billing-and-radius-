#!/usr/bin/env bash
# ==============================================================================
# Script: scripts/run_stage10_ha_and_storage.sh
# Purpose: Stage 10 On-Prem High Availability Architecture & Storage Runner
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

echo -e "\n${CYAN}=== Stage 10: On-Prem HA Architecture & Storage Verification ===${NC}"

# Support WSL2 calling docker.exe if native Linux docker is not installed
if ! command -v docker >/dev/null 2>&1; then
    if command -v docker.exe >/dev/null 2>&1; then
        docker() { docker.exe "$@"; }
    fi
elif ! docker ps >/dev/null 2>&1 && command -v docker.exe >/dev/null 2>&1; then
    docker() { docker.exe "$@"; }
fi

echo -e "${CYAN}Checking Clustered Carrier Services...${NC}"
docker compose -f infra/docker-compose.yml ps

echo -e "\n${CYAN}Executing 7-Gate HA Architecture & Storage Suite...${NC}"
python3 scripts/test_stage10_ha_and_storage.py

echo -e "\n${CYAN}Executing pgBackRest Backup & Restore Drill Script...${NC}"
bash infra/ha/pgbackrest_backup_restore_drill.sh

echo -e "\n${GREEN}==========================================================================${NC}"
echo -e "${GREEN}   STAGE 10 HA ARCHITECTURE & STORAGE: 100% VERIFIED & COMPLIANT${NC}"
echo -e "${GREEN}==========================================================================${NC}\n"
