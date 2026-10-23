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
┌────────────────────────────────────────────────────────────────────────┐
│                        ISP Billing Ecosystem                           │
└────────────────────────────────────────────────────────────────────────┘
                                    │
           ┌────────────────────────┴────────────────────────┐
           ▼                                                 ▼
┌───────────────────────────┐                     ┌──────────────────────┐
│ Android Architect Console │                     │ Windows WSL2 Backend │
│ (Kotlin + Jetpack Compose)│                     │ (Docker Containers)  │
│                           │                     │                      │
│ • 5-Stage Matrix Explorer │                     │ • PostgreSQL 16      │
│ • Interactive Test Lab    │ ◄── REST / UDP ──►  │   (radcheck/radreply)│
│ • RFC 3576 PoD Simulator  │                     │   (partition radacct)│
│ • bKash/Nagad Webhook Sim │                     │ • FreeRADIUS 3.2.3   │
│ • Accounting & Subscribers│                     │   (rlm_sql, UDP 1812)│
│ • Dual AI Architect Engine│                     │ • Redis 7 Cache      │
│ • PowerShell Script Vault │                     │ • MinIO S3 Backups   │
└───────────────────────────┘                     └──────────────────────┘
                                                             │
                                                     UDP 3799 (CoA / PoD)
                                                     HTTPS REST API
                                                             │
                                                             ▼
                                                  ┌──────────────────────┐
                                                  │ Mikrotik CCR2004     │
                                                  │ RouterOS v7 (BRAS)   │
                                                  │                      │
                                                  │ • PPPoE Server       │
                                                  │ • Simple Queues      │
                                                  │ • Walled Garden Pool │
                                                  └──────────────────────┘
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

## Installing & Running on Android

### Method 1: Direct APK Installation (Recommended)
1. Download or export the `app-debug.apk` file:
   - In **Google AI Studio**: Click **Settings** (gear icon) → **Download Project as ZIP** or **Export APK**.
   - Or use the pre-built APK generated in `.build-outputs/` or `app/build/outputs/apk/debug/`.
2. Transfer the `.apk` file to your Android phone or tablet (via USB, Google Drive, WhatsApp, or email).
3. On your Android device:
   - Open **Settings** → **Security** (or **Apps**).
   - Enable **Install Unknown Apps** for your browser or file manager.
   - Tap `app-debug.apk` and select **Install**.
4. Open the **ISP Billing** app from your home screen.

> **System Requirements:** Android 7.0 (Nougat / API 24) or newer. Optimized for modern Android 12, 13, 14, and 15 devices with Material 3 dynamic color theming.

---

### Method 2: Building from Source (Android Studio / CLI)

#### Option A: Using Android Studio
1. Open **Android Studio** (Hedgehog, Iguana, Jellyfish, or newer).
2. Select **File** → **Open...** and select this repository's root folder.
3. Allow Gradle to synchronize dependencies.
4. Connect an Android phone via USB (with **USB Debugging** enabled) or start an Android Emulator.
5. Click the green **Run (▶)** button or press `Shift + F10`.

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

#### Default Container Credentials & Ports
| Service | Container Name | Port Mapping | Credentials |
|---|---|---|---|
| **FreeRADIUS 3.2** | `isp_freeradius` | `1812/udp` (Auth)<br>`1813/udp` (Acct)<br>`3799/udp` (CoA) | Secret: `testing123` / `RadiusSecretKey2026` |
| **PostgreSQL 16** | `isp_postgres` | `127.0.0.1:5432` | User: `radius_user`<br>Pass: `SecretRadiusDbPassword2026!`<br>DB: `isp_billing` |
| **Redis 7** | `isp_redis` | `127.0.0.1:6379` | Token: `SecretRedisToken2026!` |

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
/radius add service=ppp address=10.10.10.254 secret="RadiusSecretKey2026" authentication-port=1812 accounting-port=1813 timeout=300ms

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
   - ⚡ **Low-Latency Mode** (`gemini-3.1-flash-lite`): Fast answers for PowerShell flags, FreeRADIUS dictionary attributes, and RouterOS commands.
   - 🧠 **High Thinking Mode** (`gemini-4-argon` with high thinking level): Deep architectural guidance on BGP multihoming, MPLS, high-availability PostgreSQL, and BSS database sharding.

---

## Repository Structure

```text
├── app/                                 # Android Application Module (Kotlin + Jetpack Compose)
│   ├── src/main/java/com/example/
│   │   ├── MainActivity.kt              # App entry point with edge-to-edge Compose UI
│   │   ├── data/model/                  # Subscriber, RadAcct, Stage models
│   │   ├── data/repository/             # ProductionArtifactsRepository (5 Stages)
│   │   ├── ui/screens/                  # StageMatrix, Tools, SubscriberConsole, AI Architect
│   │   └── viewmodel/                   # IspArchitectViewModel (Simulators & State)
│   └── build.gradle.kts                 # Android Gradle dependencies & SDK config
├── stage1/                              # Stage 1 Docker & FreeRADIUS configuration
│   ├── freeradius/                      # radiusd.conf, clients.conf, rlm_sql config
│   ├── scripts/                         # verify-radtest.ps1, setup-stage1.ps1
│   └── sql/                             # PostgreSQL 16 partitioned schema & test seeds
├── scripts/                             # Windows & Android deployment scripts
│   ├── setup-windows.ps1                # PowerShell 7 automated setup
│   ├── setup-windows.bat                # Windows 1-click launcher
│   ├── build-apk.bat                    # Windows 1-click Android APK build
│   └── build-apk.sh                     # Linux/macOS Android APK build
├── docker-compose.yml                   # Production FreeRADIUS + PostgreSQL 16 + Redis 7 stack
├── gradlew & gradlew.bat                # Gradle 9.3.1 build wrapper
├── metadata.json                        # AI Studio project platform identity
├── .env.example                         # Environment secrets template (Gemini API key)
├── INSTALL_WINDOWS.md                   # Dedicated Windows 10/11 installation guide
├── INSTALL_ANDROID.md                   # Dedicated Android device installation guide
└── README.md                            # Main project documentation
```

---

## Pushing to GitHub

You can sync and push this repository to GitHub using either method:

### Method 1: Using AI Studio UI (Instant)
1. Open the project in **Google AI Studio**.
2. Click the **Project Settings / Options** menu in the top toolbar.
3. Click **"Push to GitHub"** (or **"Connect to GitHub"**).
4. Select your GitHub account and choose whether to push to an existing repository or create a new one (e.g., `isp-billing-telecom-console`).
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
git remote add origin https://github.com/YOUR_USERNAME/isp-billing-telecom-console.git

# 5. Push code to GitHub
git push -u origin main
```

---

## Author & Support

- **Lead Architect:** Tapos (`tapos.cpl@gmail.com`)
- **Project:** ISP Billing & Network Automation Suite
- **Methodology:** Code-First, Hardware-Later BSS/OSS Telecom Architecture

For issues, Mikrotik routing questions, or production deployment consulting, feel free to open an issue or reach out via email.
