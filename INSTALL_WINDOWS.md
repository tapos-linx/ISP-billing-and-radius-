# Windows 10/11 Deployment & Installation Guide

This guide provides end-to-end instructions for deploying the **ISP Billing & FreeRADIUS 3.2 + PostgreSQL 16 + Redis 7** stack on **Windows 10 or Windows 11** using **WSL2** and **Docker Desktop**.

---

## 1. System Requirements
- **Windows Version:** Windows 10 (version 2004, Build 19041 or higher) or Windows 11.
- **Virtualization:** Hyper-V / Virtual Machine Platform enabled in BIOS/UEFI.
- **RAM:** Minimum 8 GB (16 GB recommended).
- **Disk Space:** Minimum 10 GB free space on SSD.

---

## 2. Step-by-Step Installation

### Step 1: Install or Update WSL2
Open **PowerShell as Administrator** and run:
```powershell
# Install WSL if not already installed
wsl --install

# Update to latest WSL2 kernel
wsl --update

# Ensure WSL2 is the default version
wsl --set-default-version 2
```
Restart your computer if prompted by Windows.

---

### Step 2: Install Docker Desktop
1. Download **Docker Desktop for Windows** from [docker.com](https://www.docker.com/products/docker-desktop/).
2. Run the installer and ensure **"Use WSL 2 instead of Hyper-V"** is checked.
3. Open Docker Desktop → **Settings** (gear icon) → **Resources** → **WSL Integration**:
   - Check **"Enable integration with my default WSL distro"**.
4. Open PowerShell and verify Docker is running:
   ```powershell
   docker --version
   docker compose version
   ```

---

### Step 3: Clone or Extract the Repository
Download and extract the project or clone via Git:
```powershell
git clone https://github.com/YOUR_USERNAME/isp-billing-telecom-console.git
cd isp-billing-telecom-console
```

---

### Step 4: Line Ending Policy (Windows CRLF vs Unix LF)
FreeRADIUS and Linux shell scripts require **Unix LF (`\n`)** line endings. Windows often converts files to **CRLF (`\r\n`)**, which causes syntax errors in `radiusd.conf` and shell scripts.

This repository includes:
1. `.gitattributes` which forces `eol=lf` on `.sh`, `.sql`, `.conf`, `.rsc`, and `.yml` files.
2. The `docker-compose.yml` entrypoint automatically strips Windows carriage returns (`tr -d '\r'`) when mounting configuration files into the container.

To manually sanitize line endings if you edited files on Windows:
```powershell
# In PowerShell:
(Get-Content .\docker-compose.yml -Raw) -replace "`r`n", "`n" | Set-Content -NoNewline .\docker-compose.yml
```

---

### Step 5: Launch the Production Stack
You have two easy ways to start the stack on Windows:

#### Method A: One-Click Automated Script (Recommended)
Double-click `setup-windows.bat` or run:
```powershell
powershell -ExecutionPolicy Bypass -File .\setup-windows.ps1
```

#### Method B: Native Docker Compose
```powershell
docker compose up -d
```

Check running containers:
```powershell
docker compose ps
```
You should see 3 healthy containers:
- `isp_postgres` (Port `127.0.0.1:5432`)
- `isp_redis` (Port `127.0.0.1:6379`)
- `isp_freeradius` (UDP Ports `1812`, `1813`, `3799`)

---

### Step 6: Verify < 10ms Latency SLA
Run the 100-packet authentication benchmark against the PostgreSQL 16 backend:
```powershell
powershell -ExecutionPolicy Bypass -File .\stage1\scripts\verify-radtest.ps1 -TotalPackets 100 -Username "tapos_test"
```
**Expected Output:**
```text
[OK] Executing 100 radtest packets against isp_freeradius...
Avg Latency: 3.42 ms | Status: SLA ACHIEVED (<10ms) | Rate-Limit: 20M/20M
```

---

### Step 7: Configure Windows Defender Firewall
To allow your physical or virtual Mikrotik router (e.g., CCR2004 or CHR at `10.10.10.1`) to send and receive RADIUS traffic through Windows, open **PowerShell as Administrator** and run:

```powershell
# RADIUS Authentication (UDP 1812)
New-NetFirewallRule -DisplayName "ISP-RADIUS-Auth" -Direction Inbound -LocalPort 1812 -Protocol UDP -Action Allow -RemoteAddress 10.10.10.1

# RADIUS Accounting (UDP 1813)
New-NetFirewallRule -DisplayName "ISP-RADIUS-Acct" -Direction Inbound -LocalPort 1813 -Protocol UDP -Action Allow -RemoteAddress 10.10.10.1

# RFC 3576 Packet of Disconnect / CoA (UDP 3799)
New-NetFirewallRule -DisplayName "ISP-RFC3576-CoA" -Direction Inbound -LocalPort 3799 -Protocol UDP -Action Allow -RemoteAddress 10.10.10.1
```

---

## 3. Connecting to PostgreSQL Database on Windows

You can connect directly using any Windows database client like **DBeaver**, **Navicat**, or **pgAdmin**:
- **Host:** `127.0.0.1`
- **Port:** `5432`
- **Database:** `isp_billing`
- **Username:** `radius_user`
- **Password:** `SecretRadiusDbPassword2026!`

To query active monthly partitioned tables from PowerShell:
```powershell
docker exec -it isp_postgres psql -U radius_user -d isp_billing -c "SELECT * FROM radcheck;"
docker exec -it isp_postgres psql -U radius_user -d isp_billing -c "SELECT * FROM radreply;"
docker exec -it isp_postgres psql -U radius_user -d isp_billing -c "\d+ radacct"
```

---

## 4. Troubleshooting Windows Issues

### Issue 1: Port 5432 Already In Use
If you already have a local PostgreSQL instance running on Windows:
```powershell
# Check what process uses port 5432
Get-Process -Id (Get-NetTCPConnection -LocalPort 5432).OwningProcess

# Either stop local postgres service:
Stop-Service postgresql*
```

### Issue 2: Docker Desktop WSL2 Kernel Error
Run:
```powershell
wsl --shutdown
wsl --update
```
Then restart Docker Desktop.

### Issue 3: FreeRADIUS Exits with Error 1
Inspect the logs:
```powershell
docker logs isp_freeradius
```
If you see syntax errors around `\r`, ensure you ran the CRLF sanitization or use the provided `docker-compose.yml` entrypoint.
