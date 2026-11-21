# Carrier Operator Handover & Day-2 NOC Operations Runbook

**System:** Antigravity ISP Billing, AAA Automation & FreeRADIUS Carrier Core  
**Release:** v2.1.0 (Carrier Expansion & Enterprise Scaling)  
**Classification:** Internal NOC / Operational Engineering Manual  
**Author / Lead Architect:** Tapos (N M Hedyetullah Tapos)  

---

## 1. Master System Topology & Core Endpoints

The carrier platform operates as a multi-region, distributed BSS/OSS with automated sub-millisecond AAA fast-path resolution, columnar accounting, and ISO 20022 direct debit clearing.

```
+-----------------------------------------------------------------------------------+
|                         MULTI-REGION ANYCAST BGP MESH                            |
|                        Anycast VIP: 103.145.10.1 (BGP UP)                         |
+-----------------------------------------+-----------------------------------------+
                                          |
           +------------------------------+------------------------------+
           |                                                             |
+--------------------------+                               +--------------------------+
|  Dhaka Core POP (DC1)    |                               | Chattogram Standby (DC2) |
|  - FreeRADIUS 3.2.3      |                               | - FreeRADIUS 3.2.3       |
|  - BIRD2 BGP ASN 65001   |                               | - BIRD2 BGP ASN 65002    |
|  - Physical: 172.16.1.10 |                               | - Physical: 172.16.2.10  |
+------------+-------------+                               +------------+-------------+
             |                                                          |
             +----------------------------+-----------------------------+
                                          |
                        +-----------------+-----------------+
                        |        REDIS 7 SENTINEL HA        |
                        |      (Fast-Path Auth <0.4ms)      |
                        +-----------------+-----------------+
                                          |
              +---------------------------+---------------------------+
              |                                                       |
+-------------+-------------+                           +-------------+-------------+
|    CITUS POSTGRESQL 16    |                           |    CLICKHOUSE 24.3 IPDR   |
| (16 Shards, OLTP Billing) |                           |  (730-Day BTRC Retention) |
+---------------------------+                           +---------------------------+
```

### Key Management & Webhook Endpoints
* **NOC Dashboard:** `http://localhost:8000/noc/dashboard`
* **Carrier Operations Console:** `http://localhost:8000/noc/carrier`
* **Public Hotspot Captive Portal:** `http://localhost:8000/hotspot/login`
* **POS Voucher Thermal Print:** `http://localhost:8000/hotspot/print/{batchId}`
* **Prometheus SLA Metrics:** `http://localhost:9090`
* **Grafana NOC Telemetry:** `http://localhost:3000` (Default: `admin` / `admin`)
* **ClickHouse Forensic HTTP Query:** `http://localhost:8123`

---

## 2. 24/7 NOC Tier Escalation Matrix

| Level | Role / Team | Responsibilities | SLA Target |
|:---:|:---|:---|:---:|
| **L1** | Service Desk & First-Line NOC | Frontline customer triage, captive portal voucher re-issuance, payment confirmation via bKash/Nagad webhook search, basic ONT restart. | < 15 Mins |
| **L2** | Field Ops & Optical/RF NOC | Physical fiber breaks (OTDR distance localization), OLT GPON unconfigured ONU auto-find, PON port saturation analysis, customer drop-cable dispatch. | < 60 Mins |
| **L3** | Core Infrastructure & AAA | FreeRADIUS daemon tuning, Citus shard replication lags, RFC 3576 CoA failure investigation, ClickHouse IPDR partition TTL management. | < 30 Mins |
| **L4** | Principal Architect & Engineering | BGP Anycast routing withdrawal, cryptographic audit chain tamper alerts, database schema migrations, and zero-day disaster recovery. | < 15 Mins |

---

## 3. Daily Operational Verification Drills

### 3.1 Containerlab Virtual MikroTik RouterOS Drill
Verify end-to-end subscriber AAA handshake, CoA bandwidth kicking, and ClickHouse ingestion:
```powershell
docker exec laravel_bss_oss php artisan carrier:clab-drill
```
*Expected Result:* All 5 gates return `OK` / `PASSED` with sub-10ms RADIUS authentication and sub-500ms RFC 3576 CoA execution.

### 3.2 Audit Log Cryptographic Chain Verification
Audit all financial, billing, and AAA mutations to ensure zero tampering:
```powershell
docker exec laravel_bss_oss php artisan audit:verify-chain
```
*Expected Result:* `[OK] Audit log chain verified: 0 tamperings detected across all immutable blocks.`

### 3.3 Anycast Node Drain Drill (Pre-Maintenance)
Before taking a regional FreeRADIUS POP offline for maintenance:
```powershell
# Drain Dhaka POP (Traffic smoothly reroutes to Chattogram)
docker exec laravel_bss_oss php artisan anycast:drain --pop=dhaka-central
```

---

## 4. Disaster Recovery & Emergency Failover (RTO < 60s, RPO < 15s)

### 4.1 Primary Database Crash & pgBackRest Delta Restore
If the primary Citus coordinator suffers catastrophic disk failure:
1. Promote standby node to primary:
   ```bash
   pg_autoctl perform failover --pgdata /var/lib/postgresql/data
   ```
2. Trigger automated pgBackRest delta restore:
   ```bash
   pgbackrest --stanza=isp_cluster --delta restore
   ```
3. Restart application queue workers:
   ```bash
   docker exec laravel_bss_oss php artisan queue:restart
   ```

### 4.2 ClickHouse IPDR Forensics Query (Law Enforcement / BTRC Request)
To identify a subscriber session from an external NAT IP and port:
```sql
SELECT 
    event_time, username, framed_ip, public_nat_ip, public_port_start, public_port_end, acct_session_id
FROM isp_compliance.btrc_ipdr_records
WHERE public_nat_ip = '103.145.10.50'
  AND 10542 BETWEEN public_port_start AND public_port_end
  AND event_time BETWEEN '2026-11-20 00:00:00' AND '2026-11-21 23:59:59'
LIMIT 10;
```
*Query Execution SLA:* < 35ms across 2 years of compressed columnar records.
