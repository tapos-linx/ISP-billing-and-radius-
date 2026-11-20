# Carrier ISP BSS/OSS Production Operator Runbook

**System:** Carrier-Grade ISP Billing, BSS/OSS & FreeRADIUS AAA Platform  
**Target Architecture:** Ubuntu 24.04 LTS (x86_64) + MikroTik RouterOS v7 (BNG / CCR2004 / CHR)  
**Database Cluster:** PostgreSQL 16 + Citus 12.x Distributed Sharding Master/Workers  
**In-Memory Store:** Redis 7.2 Core Cache & Deduplication Fast-Path  
**AAA Engine:** FreeRADIUS 3.2 Multi-Instance Cluster (RFC 2865 / RFC 2866 / RFC 5176 CoA)  
**Application Engine:** Laravel 11 BSS / OSS API + Double-Entry General Ledger + Multi-Tenant RBAC  
**Observability:** Prometheus, Grafana NOC SLA Dashboard, Grafana Loki, Promtail  

---

## 1. System Architecture Overview

```mermaid
flowchart TD
    subgraph Subscribers & BNG
        SUB["PPPoE / IPoE Subscribers\n(Fiber / GPON ONT)"]
        BNG["MikroTik RouterOS v7 BNG\n(CCR2004 / CHR)\nUDP 1812/1813 Client | UDP 3799 CoA Listener"]
    end

    subgraph High-Availability AAA Layer
        LB["AAA Load Balancer / VIP\n(Keepalived 10.10.0.10)"]
        FR1["FreeRADIUS Node 1\n(UDP 1812/1813/3799)"]
        FR2["FreeRADIUS Node 2\n(UDP 1812/1813/3799)"]
    end

    subgraph Carrier BSS / OSS Core
        BSS["Laravel 11 BSS / OSS API\n(Port 8000)\nMulti-Tenant Eloquent & RBAC Engine"]
        MFS["MFS Webhook Handler\n(bKash / Nagad HMAC-SHA256)"]
        LEDGER["Double-Entry Ledger\n(NBR Mushak-6.3 & BTRC 730d)"]
    end

    subgraph Data & Cache Tier
        CITUS_M[("Citus Master\n(isp_bss_oss Primary)")]
        CITUS_W1[("Citus Worker 1\n(Partition Shards)")]
        CITUS_W2[("Citus Worker 2\n(Partition Shards)")]
        REDIS[("Redis 7.2 Core\n(SETNX Fast-Path & Replay Cache)")]
    end

    subgraph Observability & Storage
        PROM["Prometheus 9090\n(SLA Histograms & Alerts)"]
        GRAF["Grafana 3000\n(NOC Operations Dashboard)"]
        LOKI["Grafana Loki 3100\n(Aggregated Syslog & Audit)"]
        S3[("MinIO / AWS S3\n(AES-256 Encrypted Backups)")]
    end

    SUB -->|PPPoE Discovery / Session| BNG
    BNG -->|Access-Request UDP 1812| LB
    BNG -->|Accounting-Request UDP 1813| LB
    LB --> FR1
    LB --> FR2
    FR1 -->|SQL AAA Query| CITUS_M
    FR2 -->|SQL AAA Query| CITUS_M
    CITUS_M --> CITUS_W1
    CITUS_M --> CITUS_W2
    BSS -->|REST & SQL Sync| CITUS_M
    BSS -->|Fast-Path Deduplication| REDIS
    BSS -->|RFC 5176 Disconnect-Request UDP 3799| BNG
    MFS -->|HMAC Verified Webhook| BSS
    BSS --> LEDGER
    PROM -->|Scrape /api/v1/metrics| BSS
    PROM -->|Scrape rlm_sql metrics| FR1
    GRAF --> PROM
    LOKI --> GRAF
    CITUS_M -.->|Daily Encrypted Dump| S3
```

---

## 2. Network Ports & Security Boundaries

| Port / Protocol | Direction | Source | Destination | Purpose | Security Rule |
| :--- | :---: | :--- | :--- | :--- | :--- |
| **UDP 1812** | Inbound | MikroTik BNG | FreeRADIUS / LB | RADIUS Authentication | Restrict to authorized BNG NAS IPs |
| **UDP 1813** | Inbound | MikroTik BNG | FreeRADIUS / LB | RADIUS Accounting | Restrict to authorized BNG NAS IPs |
| **UDP 3799** | Outbound | Laravel BSS | MikroTik BNG | RFC 5176 CoA / Disconnect | **CRITICAL: MikroTik must drop packets from all IPs except BSS!** |
| **TCP 8000** | Inbound | Internal / LB | Laravel BSS | BSS Core API & Webhooks | Public for `/api/v1/webhook/*`, internal for `/api/*` |
| **TCP 5432** | Internal | BSS & FreeRADIUS | Citus Master / Workers | PostgreSQL Citus Cluster | Strict internal network isolation (`infra_isp_internal_net`) |
| **TCP 6379** | Internal | BSS Core | Redis Core Cluster | In-Memory Cache & Lock | Internal network only; protected mode active |
| **TCP 9090** | Internal | Admin / Grafana | Prometheus | Metrics Scrape & Alerts | Restrict to NOC subnet |
| **TCP 3000** | Inbound | NOC Engineers | Grafana | SLA Dashboard | Authenticated NOC access only |
| **TCP 3100** | Internal | Promtail | Loki | Log Ingestion | Restrict to cluster logging shippers |

> [!CAUTION]
> **RFC 5176 CoA Port UDP 3799 Security:**
> Never open UDP 3799 to `0.0.0.0/0` on any MikroTik router. Malicious actors could forge Disconnect-Requests and disrupt active subscriber sessions across your entire network. Always bind firewall accept rules specifically to the BSS server IP.

---

## 3. Daily & Day-2 Operations Guide

### 3.1 Onboarding a New Tenant / Reseller
To register a new franchise or reseller organization:
```bash
# 1. Obtain a SuperAdmin Bearer Token
AUTH_HEADER="Authorization: Bearer token_superadmin_carrier_2026"

# 2. Register Tenant Organization via API
curl -s -X POST http://localhost:8000/api/v1/tenants \
  -H "$AUTH_HEADER" \
  -H "Content-Type: application/json" \
  -d '{
    "name": "Mirpur North Franchise",
    "code": "reseller-mirpur",
    "type": "reseller",
    "contact_email": "noc@mirpur-isp.com"
  }' | jq .

# 3. Allocate a Dedicated IPv4 Subnet Pool to the Tenant
curl -s -X POST http://localhost:8000/api/v1/ip-pools \
  -H "$AUTH_HEADER" \
  -H "Content-Type: application/json" \
  -d '{
    "tenant_id": 4,
    "pool_name": "mirpur_core_pool",
    "subnet_cidr": "100.64.30.0/24",
    "gateway_ip": "100.64.30.1",
    "dns_primary": "1.1.1.1",
    "dns_secondary": "8.8.8.8"
  }' | jq .
```

### 3.2 Provisioning a New Subscriber (Reseller Scoped)
Resellers can provision subscribers strictly within their own tenant:
```bash
# Authenticate as Reseller Token
RESELLER_AUTH="Authorization: Bearer token_reseller_mirpur_2026"

curl -s -X POST http://localhost:8000/api/v1/subscribers \
  -H "$RESELLER_AUTH" \
  -H "Content-Type: application/json" \
  -d '{
    "username": "mirpur_user_01",
    "password": "SecretPassword@2026",
    "tariff_id": 1,
    "bandwidth_profile": "30M/30M",
    "vlan_id": 100,
    "status": "active"
  }' | jq .
```

### 3.3 Triggering Manual RFC 5176 Session Disconnect (PoD / CoA)
To forcibly terminate a rogue, abusive, or expired subscriber session from the BNG:
```bash
docker exec laravel_bss_oss php -r "
require '/opt/laravel_bss/vendor/autoload.php';
\$app = require_once '/opt/laravel_bss/bootstrap/app.php';
\$kernel = \$app->make(Illuminate\Contracts\Console\Kernel::class);
\$kernel->bootstrap();

\$coa = app(App\Services\Radius\RadiusCoaService::class);
\$success = \$coa->disconnectSession(
    bngIp: '10.10.0.2',
    secret: 'secure_radius_secret_key_2026',
    username: 'mirpur_user_01'
);

echo \$success ? 'DISCONNECT_ACK_RECEIVED' : 'DISCONNECT_TIMEOUT';
"
```

---

## 4. MikroTik RouterOS v7 BNG Configuration Guide

Apply the following production block to your MikroTik BNG (CCR2004, CCR1036, or CHR) via the RouterOS CLI:

```routeros
# ==============================================================================
# CARRIER BNG ROUTEROS v7 PRODUCTION SETUP
# Replace 192.168.1.50 with your Ubuntu 24.04 BSS / RADIUS server IP.
# ==============================================================================

# 1. Firewall Input Filter - Restrict UDP 3799 strictly to Ubuntu BSS
/ip firewall filter
add chain=input protocol=udp dst-port=3799 src-address=192.168.1.50 action=accept place-before=0 \
    comment="Allow FreeRADIUS CoA UDP 3799 from Ubuntu BSS"

# 2. Enable RFC 3576 / RFC 5176 Incoming CoA & Disconnect Listener
/radius incoming
set accept=yes port=3799

# 3. Register Primary Ubuntu 24.04 FreeRADIUS Server
/radius
add address=192.168.1.50 \
    service=ppp,hotspot,login \
    authentication-port=1812 \
    accounting-port=1813 \
    timeout=3s \
    secret="secure_radius_secret_key_2026" \
    comment="Ubuntu 24.04 FreeRADIUS Primary"

# 4. Enable PPP AAA Authentication & 5-Minute Interim Accounting
/ppp aaa
set use-radius=yes accounting=yes interim-update=5m

# 5. Define Sandbox Active & Walled Garden IP Pools
/ip pool
add name=pppoe_active_pool ranges=100.64.0.10-100.64.15.254 comment="Carrier Active CGNAT Pool"
add name=walled_garden_pool ranges=10.250.0.10-10.250.0.254 comment="Walled Garden Isolation Pool"

# 6. Walled Garden Bill-Pay Redirection NAT (Port 80/tcp -> Self-Care Portal)
/ip firewall nat
add action=dst-nat chain=dstnat dst-port=80 protocol=tcp src-address=10.250.0.0/24 \
    to-addresses=192.168.1.50 to-ports=8080 comment="Walled Garden Bill-Pay Redirect"
```

---

## 5. Secrets Management & Rotation Runbook

### 5.1 Storage Locations & Hierarchy
All production secrets must be managed through Docker Secrets mounted at `/run/secrets/` or environment files loaded with `600` permissions:
- `/run/secrets/db_password`: Citus PostgreSQL superuser password
- `/run/secrets/radius_secret`: RADIUS shared secret key
- `/run/secrets/bkash_secret`: bKash MFS webhook signing key
- `/run/secrets/nagad_secret`: Nagad MFS merchant key
- `/run/secrets/redis_token`: Redis cluster authentication password
- `/run/secrets/backup_encryption_key`: AES-256 backup encryption passphrase

### 5.2 RADIUS Secret Rotation Procedure
1. Generate a fresh 32-character high-entropy secret:
   ```bash
   openssl rand -base64 24 > secrets/radius_secret.txt
   chmod 600 secrets/radius_secret.txt
   ```
2. Update `/etc/freeradius/clients.conf` (or FreeRADIUS container mount):
   ```text
   client bng_primary {
       ipaddr = 10.10.0.2
       secret = <NEW_SECRET>
       require_message_authenticator = yes
   }
   ```
3. Update MikroTik RouterOS client secret:
   ```routeros
   /radius set [find comment~"FreeRADIUS"] secret="<NEW_SECRET>"
   ```
4. Restart FreeRADIUS daemon:
   ```bash
   docker restart freeradius_aaa
   ```
5. Verify authentication via `radtest`:
   ```bash
   radtest tapos_test Tapos@2026 127.0.0.1 0 $(cat secrets/radius_secret.txt)
   ```

---

## 6. High Availability Failover & Recovery Guide

### 6.1 PostgreSQL Citus Node Recovery
If a worker node crashes or loses network connectivity:
1. Check coordinator cluster topology:
   ```bash
   docker exec citus_master psql -U isp_admin -d isp_bss_oss -c "SELECT nodename, nodeport, isactive FROM pg_dist_node;"
   ```
2. If `citus_worker_1` is marked inactive:
   ```bash
   docker restart citus_worker_1
   # Re-activate worker in Citus metadata
   docker exec citus_master psql -U isp_admin -d isp_bss_oss -c "
   SELECT master_activate_node('citus_worker_1', 5432);
   "
   ```

### 6.2 Redis Sentinel Failover
If the Redis primary fails, Sentinel automatically elects an active replica within 3 seconds. To inspect the current active master:
```bash
docker exec redis_cluster redis-cli info replication
```

### 6.3 FreeRADIUS Load Balancer Failover
MikroTik supports multiple `/radius` entries. If Node 1 fails, the router automatically fails over to Node 2 within the 3-second timeout window. Ensure both nodes are registered in RouterOS:
```routeros
/radius add address=192.168.1.50 service=ppp timeout=3s secret="<SECRET>" comment="Node 1"
/radius add address=192.168.1.51 service=ppp timeout=3s secret="<SECRET>" comment="Node 2"
```

---

## 7. Disaster Recovery & Encrypted Backup Drill

### 7.1 Creating an On-Demand Encrypted Backup
```bash
./scripts/backup_database_encrypted.sh
```
This performs:
- Consistent multi-table logical dump (`pg_dump`).
- AES-256-CBC encryption using the key in `secrets/backup_encryption_key.txt`.
- Generates a SHA-256 checksum digest file (`.sha256`).
- Uploads the encrypted archive to MinIO / S3 bucket `isp-database-backups`.

### 7.2 Restoring from an Encrypted Backup Archive
```bash
./scripts/restore_database_encrypted.sh
```
The script automatically:
1. Locates the latest encrypted `.tar.gz.enc` archive.
2. Validates SHA-256 integrity against the digest.
3. Decrypts via OpenSSL AES-256-CBC.
4. Restores the schema and sharded data into `isp_bss_oss`.
5. Validates row counts and ledger balance.

---

## 8. Statutory Compliance & Reporting Commands

### 8.1 NBR 5% VAT Mushak-6.3 Monthly Return
Generate statutory monthly VAT return for the National Board of Revenue:
```bash
docker exec laravel_bss_oss php /opt/laravel_bss/artisan report:vat-mushak --month=2026-11
```
Output table displays:
- Tax Period (`YYYY-MM`)
- Statutory VAT Rate (`5.00%`)
- Total Settled Invoices Count
- Gross Revenue (BDT)
- Net Taxable Base (BDT)
- Total VAT Output Tax (BDT)
- NBR Mushak-6.3 Compliance Status: **COMPLIANT**

### 8.2 BTRC Regulatory Licensing Compliance Return
Generate the licensing and subscriber audit trail return for Bangladesh Telecommunication Regulatory Commission:
```bash
docker exec laravel_bss_oss php /opt/laravel_bss/artisan report:btrc-compliance --month=2026-11
```
Output table displays:
- Regulatory Period
- BTRC Operator License Tier (Nationwide ISP / Category A)
- Active Subscribers vs. Suspended Subscribers
- 730-day (2-year) audit log retention status

---

## 9. Observability & SLA Monitoring

### 9.1 Prometheus SLA Metrics
Prometheus scrapes the BSS API at `http://localhost:8000/api/v1/metrics`:
- `radius_auth_latency_seconds_bucket`: AAA SQL Auth latency (Target: `< 10ms`)
- `coa_kick_latency_seconds_bucket`: RFC 5176 UDP 3799 disconnect latency (Target: `< 500ms`)
- `payment_unblock_latency_seconds_bucket`: MFS settlement to unblock latency (Target: `< 3.0s`)

### 9.2 Prometheus Alert Rules (`/etc/prometheus/isp_sla_alerts.yml`)
1. `RadiusAuthLatencyHigh`: Triggers if p99 latency > 10ms for 2m (Severity: **Critical**).
2. `CoaKickLatencyHigh`: Triggers if p99 latency > 500ms for 2m (Severity: **Warning**).
3. `PaymentUnblockLatencyHigh`: Triggers if p99 latency > 3000ms for 3m (Severity: **Warning**).
4. `MfsWebhookHighRejectionRate`: Triggers if invalid signatures or replays spike (Severity: **Critical**).
5. `DoubleEntryLedgerUnbalanced`: Triggers if debits != credits (Severity: **Critical**).
6. `FreeRadiusDown`: Triggers if FreeRADIUS unreachable (Severity: **Critical**).
7. `PostgresReplicationLag`: Triggers if Citus worker replication lag > 10s (Severity: **Warning**).

---

## 10. Emergency Break-Glass & Troubleshooting

### 10.1 Emergency Walled Garden Unblock
If the billing database suffers a catastrophic outage, to prevent disconnecting internet service for all subscribers:
```routeros
# Disable Walled Garden redirect on MikroTik BNG
/ip firewall nat disable [find comment~"Walled Garden"]
```

### 10.2 Flushing In-Memory Deduplication Locks
If an MFS webhook fails mid-flight and leaves an unreleased transaction lock:
```bash
docker exec redis_cluster redis-cli keys "mfs:lock:*" | xargs -r docker exec redis_cluster redis-cli del
```

### 10.3 Run Complete Production Health Gate
Execute the comprehensive 11-gate test runner:
```bash
python scripts/test_phase7_production_readiness.py
```
Or execute the full CI/CD suite:
```powershell
powershell -ExecutionPolicy Bypass -File scripts\run_local_ci_checks.ps1
```
