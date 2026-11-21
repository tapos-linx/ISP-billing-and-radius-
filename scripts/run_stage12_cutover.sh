#!/usr/bin/env bash
# ==============================================================================
# Script: scripts/run_stage12_cutover.sh
# Purpose: Stage 12 Zero-Downtime Production Cutover & Rollback Runner (Bash)
# ==============================================================================
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"
cd "${REPO_ROOT}"

PRINT_ROS=false
CLEANUP=false

while [[ $# -gt 0 ]]; do
    case "$1" in
        --print-ros)
            PRINT_ROS=true
            shift
            ;;
        --cleanup)
            CLEANUP=true
            shift
            ;;
        *)
            echo "Unknown option: $1"
            exit 1
            ;;
    esac
done

GREEN='\033[0;32m'
RED='\033[0;31m'
CYAN='\033[0;36m'
YELLOW='\033[1;33m'
NC='\033[0m'

echo -e "\n${CYAN}=== Stage 12: Zero-Downtime Production Cutover & Rollback Drill ===${NC}"

RADIUS_SECRET="secure_radius_secret_key_2026"
if [[ -f "${REPO_ROOT}/secrets/radius_secret.txt" ]]; then
    RADIUS_SECRET="$(tr -d '\r\n' < "${REPO_ROOT}/secrets/radius_secret.txt")"
fi

if [[ "${PRINT_ROS}" == "true" ]]; then
    echo -e "\n${CYAN}Printing MikroTik RouterOS v7 Cutover Configuration...${NC}"
    bash scripts/run_single_sub_pilot.sh -s 127.0.0.1 -m 127.0.0.1 -r "${RADIUS_SECRET}" --print-ros
    exit 0
fi

if [[ "${CLEANUP}" == "true" ]]; then
    echo -e "\n${CYAN}Executing Safe Pilot Teardown & Baseline Rollback...${NC}"
    bash scripts/run_single_sub_pilot.sh -s 127.0.0.1 --cleanup
    exit 0
fi

echo -e "\n${CYAN}Executing 8-Gate Master Cutover & Pilot Suite...${NC}"
python3 scripts/test_stage12_cutover_and_pilot.py

echo -e "\n${GREEN}==========================================================================${NC}"
echo -e "${GREEN}   STAGE 12 PRODUCTION CUTOVER & ROLLBACK: 100% VERIFIED & READY${NC}"
echo -e "${GREEN}==========================================================================${NC}\n"
