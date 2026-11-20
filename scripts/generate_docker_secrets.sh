#!/usr/bin/env bash
# ==============================================================================
# Script: generate_docker_secrets.sh
# Purpose: Initialize Docker Secrets directory and populate with strong random
#          cryptographic secrets or ingest from an existing .env file.
# Security: Enforces chmod 700 on secrets/ and chmod 600 on secret files.
# ==============================================================================

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"
SECRETS_DIR="${REPO_ROOT}/secrets"
INFRA_SECRETS_DIR="${REPO_ROOT}/infra/secrets"

echo "==> Initializing Docker Secrets directory..."
mkdir -p "${SECRETS_DIR}"
chmod 700 "${SECRETS_DIR}"

# Helper to generate random 32-character url-safe secret
generate_secret() {
    if command -v openssl >/dev/null 2>&1; then
        openssl rand -base64 32 | tr -d '\n\r/' | cut -c1-32
    else
        python3 -c "import secrets; print(secrets.token_urlsafe(24))"
    fi
}

# Ingest existing value from .env or infra/.env if available, otherwise generate
get_or_create_secret() {
    local secret_name="$1"
    local env_var_name="$2"
    local secret_file="${SECRETS_DIR}/${secret_name}.txt"

    if [ -f "${secret_file}" ] && [ -s "${secret_file}" ]; then
        echo "  [EXISTS] ${secret_name} secret already initialized at ${secret_file}"
        chmod 600 "${secret_file}"
        return
    fi

    local val=""
    if [ -f "${REPO_ROOT}/infra/.env" ]; then
        val=$(grep -E "^${env_var_name}=" "${REPO_ROOT}/infra/.env" 2>/dev/null | cut -d'=' -f2- | tr -d '\r\n"' || true)
    fi
    if [ -z "${val}" ] && [ -f "${REPO_ROOT}/.env" ]; then
        val=$(grep -E "^${env_var_name}=" "${REPO_ROOT}/.env" 2>/dev/null | cut -d'=' -f2- | tr -d '\r\n"' || true)
    fi

    # If value is placeholder or empty, generate a new strong secret
    if [ -z "${val}" ] || [[ "${val}" =~ ^REPLACE_WITH_ ]] || [[ "${val}" == "testing123" ]]; then
        val=$(generate_secret)
        echo "  [GENERATED] Generated new random 256-bit secret for ${secret_name}"
    else
        echo "  [INGESTED] Ingested existing ${env_var_name} into ${secret_name}.txt"
    fi

    printf "%s" "${val}" > "${secret_file}"
    chmod 600 "${secret_file}"
}

get_or_create_secret "db_password" "DB_PASSWORD"
get_or_create_secret "redis_password" "REDIS_PASSWORD"
get_or_create_secret "radius_secret" "RADIUS_SECRET"
get_or_create_secret "rabbitmq_password" "RABBITMQ_PASSWORD"
get_or_create_secret "bkash_secret" "BKASH_APP_SECRET"
get_or_create_secret "nagad_secret" "NAGAD_MERCHANT_KEY"
get_or_create_secret "gemini_api_key" "GEMINI_API_KEY"

# Ensure infra/secrets symlink or mirror exists for infra/docker-compose.yml
mkdir -p "${INFRA_SECRETS_DIR}"
chmod 700 "${INFRA_SECRETS_DIR}"
cp -f "${SECRETS_DIR}"/*.txt "${INFRA_SECRETS_DIR}/"
chmod 600 "${INFRA_SECRETS_DIR}"/*.txt

echo "==> Docker Secrets initialized successfully."
ls -la "${SECRETS_DIR}"
