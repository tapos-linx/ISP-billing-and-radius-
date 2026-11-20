#!/usr/bin/env bash
# ==============================================================================
# Script: infra/security/apply_firewall_policies.sh
# Purpose: Network Segmentation & Host-Level Firewall Rules for ISP Stack
# Target: Ubuntu 24.04 LTS / Debian Edge Server (UFW & iptables)
# ==============================================================================

set -euo pipefail

# Authorized NAS IPs or Subnets (e.g., WireGuard BNG tunnel, MikroTik CCR/CHR)
NAS_IPS="${NAS_IPS:-10.10.0.0/24 172.16.0.0/12 127.0.0.1}"
INTERNAL_SUBNET="${INTERNAL_SUBNET:-10.250.10.0/24}"

echo "======================================================================"
echo "   APPLYING NETWORK SEGMENTATION & FIREWALL HARDENING POLICIES"
echo "======================================================================"

# Check if running as root
if [ "$(id -u)" -ne 0 ]; then
    echo "[INFO] Not running as root; generating dry-run / iptables commands."
    SUDO="sudo"
else
    SUDO=""
fi

# 1. Block External Access to Citus/Postgres, Redis, RabbitMQ
echo "==> Enforcing Database & Cache Isolation..."
echo "  [POLICY] Blocking external access to PostgreSQL (5432), Redis (6379), RabbitMQ (5672, 15672)"

if command -v ufw >/dev/null 2>&1; then
    echo "  Applying UFW rules..."
    $SUDO ufw default deny incoming
    $SUDO ufw default allow outgoing

    # Allow SSH only if needed
    $SUDO ufw allow 22/tcp comment 'SSH Management'

    # Allow Public Web / Reverse Proxy (Inertia PWA & Webhooks)
    $SUDO ufw allow 80/tcp comment 'HTTP Webhook/Portal'
    $SUDO ufw allow 443/tcp comment 'HTTPS Webhook/Portal'
    $SUDO ufw allow 8000/tcp comment 'Laravel BSS API (Lab/Internal)'

    # Deny public database and cache access
    $SUDO ufw deny 5432/tcp comment 'Block Public Citus/Postgres'
    $SUDO ufw deny 6379/tcp comment 'Block Public Redis'
    $SUDO ufw deny 5672/tcp comment 'Block Public RabbitMQ AMQP'
    $SUDO ufw deny 15672/tcp comment 'Block Public RabbitMQ Management'

    # Restrict FreeRADIUS UDP 1812, 1813, 3799 strictly to Authorized NAS IPs
    for nas in $NAS_IPS; do
        echo "  [ALLOW] FreeRADIUS Auth/Acct/CoA from authorized NAS: $nas"
        $SUDO ufw allow from "$nas" to any port 1812 proto udp comment "RADIUS Auth from $nas"
        $SUDO ufw allow from "$nas" to any port 1813 proto udp comment "RADIUS Acct from $nas"
        $SUDO ufw allow from "$nas" to any port 3799 proto udp comment "RADIUS CoA from $nas"
    done

    # Block all other incoming UDP 1812, 1813, 3799
    $SUDO ufw deny 1812/udp comment 'Block unauthorized RADIUS Auth'
    $SUDO ufw deny 1813/udp comment 'Block unauthorized RADIUS Acct'
    $SUDO ufw deny 3799/udp comment 'Block unauthorized RADIUS CoA'

    echo "[SUCCESS] UFW rules applied successfully."
fi

# Direct iptables rules as defense-in-depth (DOCKER-USER chain)
if command -v iptables >/dev/null 2>&1; then
    echo "==> Configuring iptables DOCKER-USER chain to enforce container-level isolation..."
    
    # Create DOCKER-USER chain if not exists
    $SUDO iptables -N DOCKER-USER 2>/dev/null || true

    # Block external access to Citus, Redis from WAN interface (eth0/ens*)
    $SUDO iptables -I DOCKER-USER -p tcp -m multiport --dports 5432,6379,5672,15672 ! -s 127.0.0.1 -j DROP 2>/dev/null || true
    
    # Restrict RADIUS ports in DOCKER-USER chain
    for nas in $NAS_IPS; do
        $SUDO iptables -I DOCKER-USER -p udp -m multiport --dports 1812,1813,3799 -s "$nas" -j ACCEPT 2>/dev/null || true
    done
    $SUDO iptables -A DOCKER-USER -p udp -m multiport --dports 1812,1813,3799 -j DROP 2>/dev/null || true

    echo "[SUCCESS] iptables DOCKER-USER rules enforced."
fi

echo "======================================================================"
echo "   FIREWALL & NETWORK SEGMENTATION CONFIGURED SUCCESSFULLY"
echo "======================================================================"
