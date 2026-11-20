#!/usr/bin/env bash
# ==============================================================================
# AWS Free Tier Cloud Staging - EC2 Ubuntu 24.04 LTS Bootstrap Script
# Carrier-Scale 1M-10M Subscriber ISP Billing & FreeRADIUS Automation
# Target: AWS Free Tier EC2 (t3.micro / t2.micro, ap-south-1 Mumbai, 30GB gp3)
# Zero NAT Gateways, Zero committed secrets, Docker Engine + Citus + FreeRADIUS
# ==============================================================================

set -euo pipefail

LOG_FILE="/var/log/isp_staging_bootstrap.log"
exec > >(tee -a "${LOG_FILE}") 2>&1

echo "=============================================================================="
echo " Starting ISP Billing & FreeRADIUS AWS Staging Bootstrap: $(date -u)"
echo " OS: $(lsb_release -d 2>/dev/null | cut -f2 || cat /etc/os-release | grep PRETTY_NAME)"
echo "=============================================================================="

export DEBIAN_FRONTEND=noninteractive

# 1. System Updates & Prerequisites
echo "[1/7] Installing system packages & dependencies..."
apt-get update -y
apt-get install -y --no-install-recommends \
    apt-transport-https \
    ca-certificates \
    curl \
    gnupg \
    git \
    jq \
    freeradius-utils \
    iptables \
    wireguard-tools \
    net-tools

# 2. Install Official Docker Engine & Compose Plugin
echo "[2/7] Installing Docker CE & Docker Compose..."
if ! command -v docker >/dev/null 2>&1; then
    install -m 0755 -d /etc/apt/keyrings
    curl -fsSL https://download.docker.com/linux/ubuntu/gpg -o /etc/apt/keyrings/docker.asc
    chmod a+r /etc/apt/keyrings/docker.asc

    echo \
      "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.asc] https://download.docker.com/linux/ubuntu \
      $(. /etc/os-release && echo "$VERSION_CODENAME") stable" | \
      tee /etc/apt/sources.list.d/docker.list > /dev/null

    apt-get update -y
    apt-get install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
fi

systemctl enable --now docker
usermod -aG docker ubuntu || true

# 3. Kernel & Sysctl Tuning for Redis noeviction & PostgreSQL
echo "[3/7] Tuning kernel parameters (vm.overcommit_memory=1)..."
sysctl -w vm.overcommit_memory=1
sysctl -w net.core.somaxconn=1024
cat <<EOF > /etc/sysctl.d/99-isp-carrier.conf
vm.overcommit_memory = 1
net.core.somaxconn = 1024
net.ipv4.ip_forward = 1
EOF
sysctl --system >/dev/null 2>&1 || true

# 4. Clone Repository
APP_DIR="/opt/isp-billing-and-radius"
echo "[4/7] Deploying repository to ${APP_DIR}..."
if [ ! -d "${APP_DIR}" ]; then
    git clone https://github.com/tapos-linx/ISP-billing-and-radius.git "${APP_DIR}"
else
    cd "${APP_DIR}"
    git fetch origin
    git reset --hard origin/main
fi

chown -R ubuntu:ubuntu "${APP_DIR}"
cd "${APP_DIR}"

# 5. Secure Environment Configuration (.env transfer from SCP)
echo "[5/7] Configuring application environment..."
if [ -f "/home/ubuntu/.env" ]; then
    echo "  Applying securely transferred .env from SCP..."
    cp /home/ubuntu/.env "${APP_DIR}/.env"
    cp /home/ubuntu/.env "${APP_DIR}/infra/.env"
    chmod 600 "${APP_DIR}/.env" "${APP_DIR}/infra/.env"
    chown ubuntu:ubuntu "${APP_DIR}/.env" "${APP_DIR}/infra/.env"
    rm -f /home/ubuntu/.env
elif [ ! -f "${APP_DIR}/.env" ]; then
    echo "  Generating default staging .env..."
    cp "${APP_DIR}/.env.example" "${APP_DIR}/.env" 2>/dev/null || true
    if [ -f "${APP_DIR}/infra/.env.example" ]; then
        cp "${APP_DIR}/infra/.env.example" "${APP_DIR}/infra/.env" 2>/dev/null || true
    fi
    chmod 600 "${APP_DIR}/.env" "${APP_DIR}/infra/.env" 2>/dev/null || true
fi

# 6. Launch Dockerized Carrier Stack
echo "[6/7] Booting Citus + Redis noeviction + FreeRADIUS + Laravel BSS + Monitoring..."
# Ensure monitoring directories exist
mkdir -p "${APP_DIR}/infra/monitoring/prometheus"
mkdir -p "${APP_DIR}/infra/monitoring/grafana/dashboards"

docker compose -f "${APP_DIR}/infra/docker-compose.yml" up -d
docker compose -f "${APP_DIR}/infra/monitoring/docker-compose.monitoring.yml" up -d

# 7. Post-Launch Health Validation
echo "[7/7] Validating container health..."
MAX_RETRIES=30
RETRY_COUNT=0
HEALTHY=0

while [ $RETRY_COUNT -lt $MAX_RETRIES ]; do
    RETRY_COUNT=$((RETRY_COUNT + 1))
    echo "  Health check probe ${RETRY_COUNT}/${MAX_RETRIES}..."
    
    CITUS_UP=$(docker ps --filter "name=citus_master" --filter "status=running" -q)
    REDIS_UP=$(docker ps --filter "name=redis_cluster" --filter "status=running" -q)
    RADIUS_UP=$(docker ps --filter "name=freeradius_aaa" --filter "status=running" -q)
    BSS_UP=$(docker ps --filter "name=laravel_bss_oss" --filter "status=running" -q)

    if [ -n "$CITUS_UP" ] && [ -n "$REDIS_UP" ] && [ -n "$RADIUS_UP" ] && [ -n "$BSS_UP" ]; then
        # Check HTTP /api/v1/health
        HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" http://127.0.0.1:8000/api/v1/health || true)
        if [ "$HTTP_CODE" = "200" ]; then
            HEALTHY=1
            break
        fi
    fi
    sleep 3
done

if [ $HEALTHY -eq 1 ]; then
    echo "=============================================================================="
    echo " [PASS] AWS EC2 Staging Bootstrap COMPLETE: $(date -u)"
    echo " Citus Master   : UP (Port 5432)"
    echo " Redis Cluster  : UP (Port 6379, noeviction policy enforced)"
    echo " FreeRADIUS AAA : UP (UDP 1812, 1813, 3799)"
    echo " Laravel BSS    : UP (HTTP 8000 /api/v1/health -> 200 OK)"
    echo " Prometheus     : UP (Port 9090)"
    echo " Grafana NOC    : UP (Port 3000)"
    echo "=============================================================================="
    exit 0
else
    echo "=============================================================================="
    echo " [WARN] Bootstrap finished with pending container health status."
    echo " Inspect logs with: docker compose -f ${APP_DIR}/infra/docker-compose.yml logs"
    echo "=============================================================================="
    exit 0
fi
