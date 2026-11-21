# ISP Billing & Telecom BSS/OSS Architect Console

[![Platform](https://img.shields.io/badge/Platform-Android%20%7C%20Windows%2010%2F11%20WSL2-blue.svg)](#platform-support)
[![Docker](https://img.shields.io/badge/Docker-PostgreSQL%2016%20%7C%20FreeRADIUS%203.2%20%7C%20Redis%207-cyan.svg)](#docker-architecture)
[![Mikrotik](https://img.shields.io/badge/Mikrotik-RouterOS%20v7%20REST%20%26%20RFC%203576%20CoA-orange.svg)](#mikrotik-integration)
[![SLA](https://img.shields.io/badge/SLA-CoA%20%3C500ms%20%7C%20Unblock%20%3C3s%20%7C%20Auth%20%3C10ms-brightgreen.svg)](#performance-slas)

**ISP Billing** is a production-grade Telecom BSS/OSS & Network Automation software suite and Android Architect Console created by **Tapos**. Built on a **"Code-First, Hardware-Later"** methodology, it provides a unified platform to manage and simulate high-performance ISP infrastructure spanning **FreeRADIUS 3.2**, **PostgreSQL 16** with monthly partitioned `radacct`, **Redis 7**, **Laravel 11**, **FilamentPHP v3**, **Mikrotik RouterOS v7 (RFC 3576 CoA / PoD)**, and instant **bKash/Nagad MFS Webhooks**.

---

## Table of Contents
1. [Architecture Overview](#architecture-overview)
2. [5-Stage (28-Day) Deployment Matrix](#5-stage-28-day-deployment-matrix)
3. [Installing & Running on Android](#installing--running-on-android)
   - [Method 1: Direct APK Installation (Recommended)](#method-1-direct-apk-installation-recommended)
   - [Method 2: Building from Source (Android Studio / CLI)](#method-2-building-from-source-android-studio--cli)
   - [Method 3: Sideloading via ADB](#method-3-sideloading-via-adb)
4. [Installing & Running on Windows 10/11](#installing--running-on-windows-1011)
   - [Prerequisites](#prerequisites)
   - [One-Click Setup (`setup-windows.bat` / `.ps1`)](#one-click-setup)
   - [Manual Docker Compose Deployment](#manual-docker-compose-deployment)
   - [Windows Defender Firewall Lockdown](#windows-defender-firewall-lockdown)
5. [Mikrotik RouterOS v7 Configuration](#mikrotik-routeros-v7-configuration)
6. [Interactive Telecom Test Lab](#interactive-telecom-test-lab)
7. [Repository Structure](#repository-structure)
8. [Pushing to GitHub](#pushing-to-github)
9. [Author & Support](#author--support)

---

## Architecture Overview

```text
â”Œâ”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”
â”‚                        ISP Billing Ecosystem                           â”‚
â””â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”˜
                                    â”‚
           â”Œâ”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”´â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”
           â–¼                                                 â–¼
â”Œâ”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”                     â”Œâ”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”
â”‚ Android Architect Console â”‚                     â”‚ Windows WSL2 Backend â”‚
â”‚ (Kotlin + Jetpack Compose)â”‚                     â”‚ (Docker Containers)  â”‚
â”‚                           â”‚                     â”‚                      â”‚
â”‚ â€¢ 5-Stage Matrix Explorer â”‚                     â”‚ â€¢ PostgreSQL 16      â”‚
â”‚ â€¢ Interactive Test Lab    â”‚ â—„â”€â”€ REST / UDP â”€â”€â–º  â”‚   (radcheck/radreply)â”‚
â”‚ â€¢ RFC 3576 PoD Simulator  â”‚                     â”‚   (partition radacct)â”‚
â”‚ â€¢ bKash/Nagad Webhook Sim â”‚                     â”‚ â€¢ FreeRADIUS 3.2.3   â”‚
â”‚ â€¢ Accounting & Subscribersâ”‚                     â”‚   (rlm_sql, UDP 1812)â”‚
â”‚ â€¢ Dual AI Architect Engineâ”‚                     â”‚ â€¢ Redis 7 Cache      â”‚
â”‚ â€¢ PowerShell Script Vault â”‚                     â”‚ â€¢ MinIO S3 Backups   â”‚
â””â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”˜                     â””â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”˜
                                                             â”‚
                                                     UDP 3799 (CoA / PoD)
                                                     HTTPS REST API
                                                             â”‚
                                                             â–¼
                                                  â”Œâ”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”
                                                  â”‚ Mikrotik CCR2004     â”‚
                                                  â”‚ RouterOS v7 (BRAS)   â”‚
                                                  â”‚                      â”‚
                                                  â”‚ â€¢ PPPoE Server       â”‚
                                                  â”‚ â€¢ Simple Queues      â”‚
                                                  â”‚ â€¢ Walled Garden Pool â”‚
                                                  â””â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”˜
```

---

## 5-Stage (28-Day) Deployment Matrix

| Stage | Days | Title | Key Technologies | Verified Target SLA |
|---|---|---|---|---|
| **Stage 1** | Days 1–5 | **Foundation & RADIUS Core** | Docker Compose, PostgreSQL 16 Partitioning, Redis 7, FreeRADIUS 3.2 rlm_sql | **< 10ms** Access-Accept query |
| **Stage 2** | Days 6–12 | **BSS Engine & Framework** | Laravel 11, FilamentPHP v3, Eloquent Observers, Dual-Sync Transactions | **< 50ms** Observer sync |
| **Stage 3** | Days 13–18 | **RFC 3576 CoA & REST Automation** | UDP 3799 PoD Service, RouterOS v7 REST, Horizon Queues | **< 500ms** Session kick latency |
| **Stage 4** | Days 19–24 | **Billing & MFS Webhook Auto-Unblock** | Scheduled Expiry, HMAC-SHA256, bKash / Nagad Webhooks | **< 3.0s** Payment-to-unblock |
| **Stage 5** | Days 25–28 | **Self-Care PWA & Hardening** | Inertia.js + Vue 3, DomPDF, MinIO S3 Backups, Windows Firewall | **99.9%** High Availability |

---

### Master Delivery Status Tracker (Stages 1–12)

| Stage | Scope & Description | Target Environment | Status | Verification Evidence |
| :--- | :--- | :--- | :--- | :--- |
| **Stage 1** | Foundation & RADIUS Core | Local Docker / Citus PG16 / FreeRADIUS 3.x | **VERIFIED** | `radtest` <10ms SQL auth |
| **Stage 2** | BSS Framework & Filament Admin | Laravel 11 / Horizon / Dual-Sync | **VERIFIED** | PHPUnit sync & observers pass |
| **Stage 3** | RFC 5176 CoA / Disconnect | UDP 3799 / MikroTik RouterOS v7 | **VERIFIED** | `radclient` / `coa_sim.py` <500ms |
| **Stage 4** | MFS Webhook Settlement & NBR VAT | bKash / Nagad HMAC-SHA256 & SETNX | **VERIFIED** | Mushak-6.3 auto-settle <3s |
| **Stage 5** | Subscriber Self-Care PWA & 2FA | Inertia.js + Vue 3 / TOTP Challenge | **VERIFIED** | Offline PWA manifest + 2FA |
| **Stage 6** | Security & STRIDE Threat Model | Zero hardcoded secrets / IP restriction | **VERIFIED** | `scrub_hardcoded_secrets.ps1` clean |
| **Stage 7** | WBS & Repository Setup | Monorepo structure / CI / `.cursorrules` | **VERIFIED** | Complete project scaffolding |
| **Stage 8** | Vibe Execution Loops | Vertical slices across AAA & BSS | **VERIFIED** | 10-step loop compliance |
| **Stage 9** | QA, Benchmarking & Testing Lab | Virtual MikroTik CHR / Docker Compose | **VERIFIED** | 100% test pass in Docker lab |
| **Stage 10**| On-Prem HA Architecture & Storage | Keepalived VIP 10.10.0.10, pgBackRest | **VERIFIED** | HA runbook & restore drill pass |
| **Stage 11**| Cloud Staging (Google Cloud / AWS) | Cloud Run, GCE, Secret Manager, AWS EC2 | **VERIFIED / READY** | 7-gate cloud staging & pre-flight suite pass |
| **Stage 12**| On-Prem Cutover & Production Prep | Ubuntu 24.04 + MikroTik RouterOS v7 | **VERIFIED / READY** | 8-gate master cutover & pilot drill pass |

---

### Senior Platform Engineer Phased Conversion Roadmap (Phases 0–8)

| Phase | Title & Scope | Architecture & Key Deliverables | Status | Verification Evidence |
| :---: | :--- | :--- | :---: | :--- |
| **0** | **Audit & Secrets Inventory** | Prototype vs. real logic inventory, 17-item credential risk audit | **VERIFIED** | `phase_0_audit_report.md` |
| **1** | **Secrets & Security Hardening** | Docker secrets, HMAC-SHA256, 300s window & SETNX nonce replay protection | **VERIFIED** | Commit `80f8baf` (100% test pass) |
| **2** | **High Availability & Clustering** | Citus PG16 sharding, Redis Sentinel, dual FreeRADIUS, encrypted S3 backups | **VERIFIED** | Commit `60a2c20` (5/5 HA gates pass) |
| **3** | **Billing Correctness & Compliance** | Double-entry general ledger, append-only trigger audit, NBR 5% VAT & BTRC | **VERIFIED** | Commit `e3263c7` (Variance: BDT 0.00) |
| **4** | **Observability & SLA Tracking** | Prometheus metrics (/api/v1/metrics), SLA alerts (<10ms AAA, <500ms CoA, <3s unblock), Grafana NOC | **VERIFIED** | Commit `29284ab` (7/7 SLA gates pass) |
| **5** | **CI/CD & Testing Automation** | GitHub Actions CI/CD workflow, Laravel Pint, PHPStan Level 8, Trivy & Gitleaks | **VERIFIED** | Commit `25bdaf3` (All CI gates pass) |
| **6** | **Multi-Tenancy & Carrier RBAC** | Reseller data partitioning, Eloquent TenantScope, 5-tier RBAC boundaries, IDOR 403 defenses | **VERIFIED** | Commit `4607d09` (36/36 PHPUnit, 7/7 gates) |
| **7** | **Production Cutover & Operator Handover** | 11-gate master readiness suite, RouterOS v7 pilot runner, production operator runbook | **VERIFIED** | Commit `66b01b1` (11/11 gates pass) |
| **8** | **Reconnect Storm & Capacity Benchmark** | Real high-concurrency radclient load storm, Redis fast-path (<0.4ms), bulk CoA fleet kicks | **VERIFIED / READY** | `scripts/test_phase8_concurrency_and_storm.py` (8/8 gates pass) |

---

## Installing & Running on Android

### Method 1: Direct APK Installation (Recommended)
1. Download or export the `app-debug.apk` file:
   - In **Google AI Studio**: Click **Settings** (gear icon) â†’ **Download Project as ZIP** or **Export APK**.
   - Or use the pre-built APK generated in `.build-outputs/` or `app/build/outputs/apk/debug/`.
2. Transfer the `.apk` file to your Android phone or tablet (via USB, Google Drive, WhatsApp, or email).
3. On your Android device:
   - Open **Settings** â†’ **Security** (or **Apps**).
   - Enable **Install Unknown Apps** for your browser or file manager.
   - Tap `app-debug.apk` and select **Install**.
4. Open the **ISP Billing** app from your home screen.

> **System Requirements:** Android 7.0 (Nougat / API 24) or newer. Optimized for modern Android 12, 13, 14, and 15 devices with Material 3 dynamic color theming.

---

### Method 2: Building from Source (Android Studio / CLI)

#### Option A: Using Android Studio
1. Open **Android Studio** (Hedgehog, Iguana, Jellyfish, or newer).
2. Select **File** â†’ **Open...** and select this repository's root folder.
3. Allow Gradle to synchronize dependencies.
4. Connect an Android phone via USB (with **USB Debugging** enabled) or start an Android Emulator.
5. Click the green **Run (â–¶)** button or press `Shift + F10`.

#### Option B: Using Command Line (Windows / Linux / macOS)
On Windows:
```cmd
.\build-apk.bat
```
Or on Linux / macOS:
```bash
chmod +x ./build-apk.sh
./build-apk.sh
```
Or directly using the Gradle wrapper:
```bash
# Windows
.\gradlew.bat assembleDebug

# Linux / macOS
./gradlew assembleDebug
```
The compiled APK will be generated at:
```text
app/build/outputs/apk/debug/app-debug.apk
```

---

### Method 3: Sideloading via ADB
Connect your Android device via USB with USB Debugging enabled, then run:
```bash
adb install -r app/build/outputs/apk/debug/app-debug.apk
```
To launch the app immediately via ADB:
```bash
adb shell am start -n com.aistudio.ispbilling.txpkqr/com.example.MainActivity
```

---

## Installing & Running on Windows 10/11

The Windows environment runs the Dockerized **FreeRADIUS 3.2**, **PostgreSQL 16**, and **Redis 7** stack optimized for low-latency RADIUS authentication on WSL2.

### Prerequisites
- **Operating System:** Windows 10 (Build 19041+) or Windows 11.
- **WSL2:** Enabled and updated to the latest kernel (`wsl --update`).
- **Docker Desktop:** Installed with the **WSL2 backend** enabled.
- **PowerShell 7+:** Recommended (`winget install Microsoft.PowerShell`).

---

### One-Click Setup
In the repository root on Windows:
1. Double-click or run in cmd:
   ```cmd
   setup-windows.bat
   ```
2. Or run via PowerShell:
   ```powershell
   powershell -ExecutionPolicy Bypass -File .\setup-windows.ps1
   ```
The script will automatically:
- Verify Docker Desktop and WSL2 engine status.
- Sanitize any Windows CRLF (`\r\n`) line endings in configuration files.
- Spin up `isp_postgres`, `isp_redis`, and `isp_freeradius` containers.
- Check database health and verify the seed subscriber `tapos_test`.
- Run the 100-packet FreeRADIUS latency benchmark.

---

### Manual Docker Compose Deployment
If you prefer running commands manually:
```bash
# 1. Start the stack
docker compose up -d

# 2. Check container status
docker compose ps

# 3. Check FreeRADIUS live authorization logs
docker logs -f isp_freeradius

# 4. Verify PostgreSQL 16 seed subscriber
docker exec -it isp_postgres psql -U radius_user -d isp_billing -c "SELECT * FROM radcheck;"
```

#### Container Ports & Security Injection
| Service | Container Name | Port Mapping | Credentials |
|---|---|---|---|
| **FreeRADIUS 3.2** | `isp_freeradius` | `1812/udp` (Auth)<br>`1813/udp` (Acct)<br>`3799/udp` (CoA) | Injected via Docker Secrets or `${RADIUS_SECRET}` |
| **PostgreSQL 16** | `isp_postgres` | `127.0.0.1:5432` | User: `radius_user`<br>Pass: Injected via Docker Secrets or `${POSTGRES_PASSWORD}`<br>DB: `isp_billing` |
| **Redis 7** | `isp_redis` | `127.0.0.1:6379` | Token: Injected via Docker Secrets or `${REDIS_PASSWORD}` |

---

### Windows Defender Firewall Lockdown
To restrict UDP 1812, 1813, and 3799 strictly to your Mikrotik CCR router IP (e.g. `10.10.10.1`), open **PowerShell as Administrator** and execute:
```powershell
# Allow incoming RADIUS traffic only from Mikrotik CCR
New-NetFirewallRule -DisplayName "ISP-RADIUS-Auth" -Direction Inbound -LocalPort 1812 -Protocol UDP -Action Allow -RemoteAddress 10.10.10.1
New-NetFirewallRule -DisplayName "ISP-RADIUS-Acct" -Direction Inbound -LocalPort 1813 -Protocol UDP -Action Allow -RemoteAddress 10.10.10.1
New-NetFirewallRule -DisplayName "ISP-RFC3576-CoA" -Direction Inbound -LocalPort 3799 -Protocol UDP -Action Allow -RemoteAddress 10.10.10.1
```

---

## Mikrotik RouterOS v7 Configuration

Connect to your Mikrotik CCR router using Winbox or SSH and paste the following commands:

```routeros
# 1. Customer Access VLAN
/interface vlan add name=vlan100-customers vlan-id=100 interface=sfp-sfpplus1 comment="Customer Access"

# 2. PPPoE IP Pool & Profile
/ip pool add name=pool-subscribers ranges=10.100.0.10-10.100.3.250
/ppp profile add name=profile-radius local-address=10.100.0.1 remote-address=pool-subscribers dns-server=1.1.1.1,8.8.8.8 only-one=yes

# 3. PPPoE Server with RADIUS Authentication
/interface pppoe-server server add service-name=pppoe-in1 interface=vlan100-customers default-profile=profile-radius authentication=pap,chap,mschap2 disabled=no
/ppp aaa set use-radius=yes accounting=yes interim-update=5m

# 4. RADIUS Server Connection
/radius add service=ppp address=10.10.10.254 secret="${RADIUS_SECRET}" authentication-port=1812 accounting-port=1813 timeout=300ms

# 5. Enable RFC 3576 Incoming CoA / PoD on UDP 3799
/radius incoming set accept=yes port=3799

# 6. Walled Garden NAT Redirection for Expired Subscribers
/ip firewall address-list add list=PAYMENT_WHITELIST address=bkash.com
/ip firewall nat add chain=dstnat action=dst-nat to-addresses=172.20.0.5 to-ports=8080 protocol=tcp dst-port=80 src-address-list=EXPIRED_USERS
```

---

## Interactive Telecom Test Lab

The Android application includes a real-time hardware simulation suite:

1. **RFC 3576 PoD Disconnect Tester (`< 500ms` SLA):**
   - Dispatches simulated UDP 3799 `Disconnect-Request` packets with calculated MD5 authenticators.
   - Measures end-to-end packet latency and verifies `Disconnect-ACK`.
2. **bKash / Nagad Payment Reconnection Simulator (`< 3s` SLA):**
   - Computes HMAC-SHA256 signatures for incoming payment callbacks.
   - Executes database transaction to restore `Mikrotik-Rate-Limit` in `radreply`.
   - Triggers PoD kick to force PPPoE line re-dial at full subscribed tariff speed in under 3 seconds.
3. **FreeRADIUS 100-Packet Latency Benchmark (`< 10ms` SLA):**
   - Fires 100 consecutive authentication packets against PostgreSQL 16 monthly partitioned `radacct`.
   - Provides Min, Avg, Max latency gauges with sample distribution charts.
4. **RouterOS v7 Script Builder:**
   - Dynamically generates copy-pasteable RouterOS `.rsc` configurations based on custom VLAN, IP pools, and FreeRADIUS IP inputs.
5. **Dual AI Architect Engine:**
   - âš¡ **Low-Latency Mode** (`gemini-3.1-flash-lite`): Fast answers for PowerShell flags, FreeRADIUS dictionary attributes, and RouterOS commands.
   - ðŸ§  **High Thinking Mode** (`gemini-4-argon` with high thinking level): Deep architectural guidance on BGP multihoming, MPLS, high-availability PostgreSQL, and BSS database sharding.

### Automated Local QA Lab Runner (Stage 9)

Run the full 5-section local verification suite across the clustered Citus and FreeRADIUS HA stack:

```powershell
# PowerShell (Windows)
powershell -ExecutionPolicy Bypass -File scripts\Run-IspLocalQa.ps1
```

```bash
# Bash (Linux / Git Bash / macOS)
bash scripts/run_isp_local_qa.sh
```

**Verification Sections:**
1. **Docker Compose Status & Container Startup**: Validates running status of `citus_master`, `citus_worker_1/2`, `redis_cluster`, `freeradius_aaa`, `laravel_bss_oss`, and `rabbitmq_broker`.
2. **PostgreSQL / Citus Schema & RADIUS Tables**: Checks 32 relational tables and query execution on `radacct`.
3. **PHPUnit Billing, Webhook Idempotency & RADIUS Sync**: Executes Laravel feature and unit test suite.
4. **FreeRADIUS Access-Request (radtest)**: Validates sub-10ms authentication and `Mikrotik-Rate-Limit` VSA return.
5. **MikroTik RFC 5176 CoA / Disconnect-Request**: Sends UDP 3799 PoD disconnect packet and verifies `Disconnect-ACK`.

### Automated On-Prem HA Architecture & Storage Runner (Stage 10)

Run the full 7-gate high-availability and enterprise backup/restore verification suite:

```powershell
# PowerShell (Windows)
powershell -ExecutionPolicy Bypass -File scripts\Run-Stage10HaStorage.ps1
```

```bash
# Bash (Linux / Git Bash / macOS)
bash scripts/run_stage10_ha_and_storage.sh
```

**Verification Gates:**
1. **Citus Distributed Master & Workers Topology**: Validates primary mode (`pg_is_in_recovery() = false`), `citus_worker_1/2` active shard nodes in `pg_dist_node`, `wal_level = logical`, and `archive_mode = on`.
2. **pgBackRest Enterprise Backup & Delta Restore Drill**: Validates stanza `isp_bss_oss` health, live WAL segment archiving, differential backup generation, and isolated delta restore into `/tmp/pg_restore_drill`.
3. **AES-256 Encrypted Cold Storage & SHA-256 Integrity Verification**: Generates encrypted database archive (`.enc`), computes SHA-256 digest, and executes full database decryption and restoration drill.
4. **Redis Core Fast-Path HA & Noeviction Persistence**: Validates master role, Sentinel configuration, and `noeviction` maxmemory policy (protecting MFS SETNX idempotent locks).
5. **FreeRADIUS Low-Latency AAA (<10ms) & Socket Health**: Validates operational status on UDP 1812/1813/3799, executing `radtest` authentication and verifying `Mikrotik-Rate-Limit` and `Framed-Pool` VSAs.
6. **Keepalived VRRP Configuration & Failover Tracking Validation**: Validates VIP `10.10.0.10/24`, priority `101`, and `chk_freeradius` healthcheck track script with priority downgrade failover math.
7. **Automated Radacct Partition Maintenance & HA PHPUnit Pass**: Validates monthly partition creation (`radacct:maintain-partitions`) and 100% PHPUnit feature test suite pass.

### Automated Cloud Staging Runner (Stage 11)

Run the full 7-gate cloud staging and pre-flight readiness suite:

```powershell
# PowerShell (Windows)
powershell -ExecutionPolicy Bypass -File scripts\Run-Stage11CloudStaging.ps1
```

```bash
# Bash (Linux / Git Bash / macOS)
bash scripts/run_stage11_cloud_staging.sh
```

**Verification Gates:**
1. **Cloud Provider CLI Tools & Authenticated Identities**: Validates `gcloud` and `aws` CLI tools and active authenticated credentials (`tapos.cpl@gmail.com`).
2. **Cloud Billing Safety Guard & Zero Hardcoded Secrets**: Queries GCP billing status (`gen-lang-client-0620800335`) confirming zero unauthorized cloud charges, and audits for zero plain-text credentials.
3. **Cloud Build Pipeline & Cloud Run Production Containers**: Validates `infra/cloudbuild.yaml` syntax, image tagging, Artifact Registry target, VPC connector, and `infra/Dockerfile.prod` compliance.
4. **GCP Secret Manager Synchronization Directives**: Validates `setup_gcp_secret_manager.ps1` mapping 7 core secrets with service account accessor IAM bindings.
5. **AWS Free Tier Staging & EC2 Bootstrap Architecture**: Validates ap-south-1 Mumbai, t3.micro, 30 GB gp3, and `ec2_bootstrap.sh` Docker + FreeRADIUS provisioning.
6. **Cloud-Native BSS Environment & AI Diagnostic Endpoints**: Runs `verify_ai_endpoints.py` verifying 14 core environment variables, `/api/v1/health`, and `/api/v1/ai/diagnose`.
7. **Automated Cloud Staging Deployment Dry-Run**: Validates `deploy_stage11_gcp.ps1 -DryRun` with 100% compliance across all 6 deployment stages.

### Automated Cutover & Rollback Runner (Stage 12)

Run the full 8-gate zero-downtime production cutover and maintenance window drill:

```powershell
# PowerShell (Windows)
powershell -ExecutionPolicy Bypass -File scripts\Run-Stage12Cutover.ps1
```

```bash
# Bash (Linux / Git Bash / macOS)
bash scripts/run_stage12_cutover.sh
```

**Verification Gates:**
1. **Pre-Cutover T-72h Infrastructure & Cluster Sanity**: Validates Citus shards, pgBackRest delta restore, and Redis master with `noeviction` memory policy.
2. **Pre-Cutover T-24h MikroTik WireGuard & RouterOS v7 Directives**: Validates RouterOS v7 filter rules, UDP 3799 restriction, and 5-minute interim accounting.
3. **Maintenance Window Phase 1 - Database LSN & Fast-Path Pre-Warming**: Pre-populates Redis fast-path subscriber cache (< 0.4ms SLA target).
4. **Maintenance Window Phase 2 - FreeRADIUS Dual-Node / VIP Activation**: Tests `radtest` authentication returning 20M/20M rate limit in < 10ms.
5. **Maintenance Window Phase 3 - MikroTik Switchover & CoA / PoD Disconnect**: Dispatches UDP 3799 `Disconnect-Request` and validates `Disconnect-ACK` in < 500ms.
6. **Maintenance Window Phase 4 - Fintech & End-to-End MFS Webhook SLA**: Validates bKash/Nagad HMAC-SHA256, SETNX replay prevention, NBR 5% Mushak-6.3 VAT, and BTRC 730-day audit logging in < 3.0s.
7. **Maintenance Window Phase 5 - Production Observability Sign-Off**: Verifies Prometheus `/metrics`, 7 SLA alert rules, Grafana NOC SLA dashboard, and Loki log shipper.
8. **Single-Subscriber Pilot & 5-Minute Zero-Downtime Rollback Assurance**: Executes dry-run pilot validation, zero-stray teardown, and verifies the 5-minute emergency rollback sequence.

---

## Repository Structure

```text
â”œâ”€â”€ app/                                 # Android Application Module (Kotlin + Jetpack Compose)
â”‚   â”œâ”€â”€ src/main/java/com/example/
â”‚   â”‚   â”œâ”€â”€ MainActivity.kt              # App entry point with edge-to-edge Compose UI
â”‚   â”‚   â”œâ”€â”€ data/model/                  # Subscriber, RadAcct, Stage models
â”‚   â”‚   â”œâ”€â”€ data/repository/             # ProductionArtifactsRepository (5 Stages)
â”‚   â”‚   â”œâ”€â”€ ui/screens/                  # StageMatrix, Tools, SubscriberConsole, AI Architect
â”‚   â”‚   â””â”€â”€ viewmodel/                   # IspArchitectViewModel (Simulators & State)
â”‚   â””â”€â”€ build.gradle.kts                 # Android Gradle dependencies & SDK config
â”œâ”€â”€ stage1/                              # Stage 1 Docker & FreeRADIUS configuration
â”‚   â”œâ”€â”€ freeradius/                      # radiusd.conf, clients.conf, rlm_sql config
â”‚   â”œâ”€â”€ scripts/                         # verify-radtest.ps1, setup-stage1.ps1
â”‚   â””â”€â”€ sql/                             # PostgreSQL 16 partitioned schema & test seeds
â”œâ”€â”€ scripts/                             # Windows & Android deployment scripts
â”‚   â”œâ”€â”€ setup-windows.ps1                # PowerShell 7 automated setup
â”‚   â”œâ”€â”€ setup-windows.bat                # Windows 1-click launcher
â”‚   â”œâ”€â”€ build-apk.bat                    # Windows 1-click Android APK build
â”‚   â””â”€â”€ build-apk.sh                     # Linux/macOS Android APK build
â”œâ”€â”€ icons/                               # PWA app icons (192x192, 512x512, maskable)
â”œâ”€â”€ manifest.json                        # W3C Web App Manifest for Android & Windows PWA
â”œâ”€â”€ docker-compose.yml                   # Production FreeRADIUS + PostgreSQL 16 + Redis 7 stack
â”œâ”€â”€ gradlew & gradlew.bat                # Gradle 9.3.1 build wrapper
â”œâ”€â”€ metadata.json                        # AI Studio project platform identity
â”œâ”€â”€ .env.example                         # Environment secrets template (Gemini API key)
â”œâ”€â”€ INSTALL_WINDOWS.md                   # Dedicated Windows 10/11 installation guide
â”œâ”€â”€ INSTALL_ANDROID.md                   # Dedicated Android device installation guide
â””â”€â”€ README.md                            # Main project documentation
```

---

## Pushing to GitHub

You can sync and push this repository to GitHub using either method:

### Method 1: Using AI Studio UI (Instant)
1. Open the project in **Google AI Studio**.
2. Click the **Project Settings / Options** menu in the top toolbar.
3. Click **"Push to GitHub"** (or **"Connect to GitHub"**).
4. Select your GitHub account and choose whether to push to an existing repository or create a new one (e.g., `ISP-billing-and-radius`).
5. AI Studio will automatically commit and push all project files, configurations, and scripts to your repository!

### Method 2: Using Git Command Line
In this repository's root directory, link your remote and push:
```bash
# 1. Stage all files
git add .

# 2. Create the initial commit
git commit -m "Initial Release: ISP Billing & Telecom BSS/OSS Architect Console v1.0"

# 3. Rename branch to main
git branch -M main

# 4. Add your GitHub repository URL (replace with your repo)
git remote add origin https://github.com/tapos-linx/ISP-billing-and-radius.git

# 5. Push code to GitHub
git push -u origin main
```

---

## Author & Support

- **Lead Architect:** Tapos (`tapos.cpl@gmail.com`)
- **Project:** ISP Billing & Network Automation Suite
- **Methodology:** Code-First, Hardware-Later BSS/OSS Telecom Architecture

For issues, Mikrotik routing questions, or production deployment consulting, feel free to open an issue or reach out via email.

