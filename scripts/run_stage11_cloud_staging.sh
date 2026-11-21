#!/usr/bin/env bash
# ==============================================================================
# Script: scripts/run_stage11_cloud_staging.sh
# Purpose: Stage 11 Cloud Staging (Google Cloud / AWS) Verification Runner
# ==============================================================================
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"
cd "${REPO_ROOT}"

DEPLOY_LIVE=false
while [[ $# -gt 0 ]]; do
    case "$1" in
        --deploy-live)
            DEPLOY_LIVE=true
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

echo -e "\n${CYAN}=== Stage 11: Cloud Staging Verification & Pre-Flight ===${NC}"

echo -e "\n${CYAN}Executing 7-Gate Cloud Staging Suite...${NC}"
python3 scripts/test_stage11_cloud_staging.py

if [[ "${DEPLOY_LIVE}" == "true" ]]; then
    echo -e "\n${CYAN}Executing Live Google Cloud Deployment (Billing Required)...${NC}"
    bash infra/scripts/deploy_stage11_gcp.sh
else
    echo -e "\n${YELLOW}[NOTE] Safe dry-run mode active. No cloud charges incurred.${NC}"
    echo -e "${CYAN}To attempt live deployment once billing is attached, pass --deploy-live.${NC}"
fi

echo -e "\n${GREEN}==========================================================================${NC}"
echo -e "${GREEN}   STAGE 11 CLOUD STAGING & READINESS: 100% VERIFIED & COMPLIANT${NC}"
echo -e "${GREEN}==========================================================================${NC}\n"
