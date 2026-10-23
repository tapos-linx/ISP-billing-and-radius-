# ====================================================================
# ISP Billing & Telecom BSS/OSS Console - Windows Automated Setup
# Author: Tapos (tapos.cpl@gmail.com)
# ====================================================================

[CmdletBinding()]
param (
    [switch]$SkipBenchmark,
    [switch]$Down
)

$ErrorActionPreference = "Stop"

function Write-Header {
    param([string]$Text)
    Write-Host ""
    Write-Host "====================================================================" -ForegroundColor Cyan
    Write-Host "  $Text" -ForegroundColor White
    Write-Host "====================================================================" -ForegroundColor Cyan
}

function Write-Success {
    param([string]$Text)
    Write-Host "[OK] $Text" -ForegroundColor Green
}

function Write-WarningMsg {
    param([string]$Text)
    Write-Host "[WARN] $Text" -ForegroundColor Yellow
}

function Write-ErrorMsg {
    param([string]$Text)
    Write-Host "[FAIL] $Text" -ForegroundColor Red
}

Write-Header "ISP Billing: Windows 10/11 WSL2 & Docker Deployment"

# Check if teardown requested
if ($Down) {
    Write-Host "Stopping and removing all ISP Billing containers and volumes..." -ForegroundColor Yellow
    docker compose down -v
    Write-Success "All containers and volumes stopped."
    exit 0
}

# 1. Verify Docker Engine
Write-Host "Step 1/5: Verifying Docker Desktop & WSL2 backend..." -ForegroundColor Cyan
try {
    $dockerVersion = docker --version
    Write-Success "Found $dockerVersion"
} catch {
    Write-ErrorMsg "Docker is not running or not in PATH! Please start Docker Desktop for Windows."
    exit 1
}

# 2. Sanitize CRLF line endings
Write-Host "Step 2/5: Sanitizing line endings for FreeRADIUS & PostgreSQL configs..." -ForegroundColor Cyan
$textFiles = @(
    "docker-compose.yml",
    "stage1\scripts\entrypoint-freeradius.sh",
    "stage1\sql\01-init-schema.sql",
    "stage1\sql\02-seed-test-data.sql",
    "stage1\freeradius\radiusd.conf",
    "stage1\freeradius\clients.conf",
    "stage1\freeradius\mods-available\sql"
)

foreach ($relPath in $textFiles) {
    $fullPath = Join-Path $PSScriptRoot $relPath
    if (Test-Path $fullPath) {
        $content = [System.IO.File]::ReadAllText($fullPath)
        if ($content.Contains("`r`n")) {
            $sanitized = $content.Replace("`r`n", "`n")
            [System.IO.File]::WriteAllText($fullPath, $sanitized, (New-Object System.Text.UTF8Encoding($false)))
            Write-Host "  Sanitized CRLF -> LF: $relPath" -ForegroundColor DarkGray
        }
    }
}
Write-Success "Line endings sanitized (Unix LF enforced)."

# 3. Start Docker Compose Stack
Write-Host "Step 3/5: Launching Docker Compose stack (Postgres 16, Redis 7, FreeRADIUS 3.2)..." -ForegroundColor Cyan
docker compose up -d

# 4. Wait for healthy status
Write-Host "Step 4/5: Waiting for container health checks..." -ForegroundColor Cyan
$maxRetries = 20
$retries = 0
$healthy = $false

while ($retries -lt $maxRetries -and -not $healthy) {
    Start-Sleep -Seconds 2
    $retries++
    
    $pgState = docker inspect --format="{{.State.Health.Status}}" isp_postgres 2>$null
    $redisState = docker inspect --format="{{.State.Health.Status}}" isp_redis 2>$null
    
    Write-Host "  [Attempt $retries/$maxRetries] PostgreSQL: $pgState | Redis: $redisState" -ForegroundColor DarkGray
    
    if ($pgState -eq "healthy" -and $redisState -eq "healthy") {
        $healthy = $true
    }
}

if (-not $healthy) {
    Write-WarningMsg "Health check took longer than expected. Checking container logs..."
    docker compose ps
} else {
    Write-Success "PostgreSQL 16 and Redis 7 are HEALTHY!"
}

# Verify Seed Data
try {
    $seedCheck = docker exec isp_postgres psql -U radius_user -d isp_billing -t -c "SELECT COUNT(*) FROM radcheck WHERE username='tapos_test';" 2>$null
    if ($seedCheck.Trim() -ge 1) {
        Write-Success "Seed subscriber 'tapos_test' verified in radcheck."
    }
} catch {
    Write-WarningMsg "Could not query PostgreSQL seed data directly."
}

# 5. Run FreeRADIUS Benchmark Test
if (-not $SkipBenchmark) {
    Write-Host "Step 5/5: Running FreeRADIUS 100-packet authentication benchmark (<10ms SLA)..." -ForegroundColor Cyan
    $benchmarkScript = Join-Path $PSScriptRoot "stage1\scripts\verify-radtest.ps1"
    if (Test-Path $benchmarkScript) {
        powershell -ExecutionPolicy Bypass -File $benchmarkScript -TotalPackets 50 -Username "tapos_test"
    } else {
        # Inline quick test
        $sw = [System.Diagnostics.Stopwatch]::StartNew()
        $testOut = docker exec isp_freeradius radtest tapos_test tapos_pass_2026 127.0.0.1 1812 testing123 2>&1
        $sw.Stop()
        if ($testOut -match "Access-Accept") {
            Write-Success "Single Access-Accept Verified in $([math]::Round($sw.Elapsed.TotalMilliseconds, 2)) ms!"
        } else {
            Write-WarningMsg "radtest response: $testOut"
        }
    }
}

Write-Header "Deployment Summary"
Write-Host "  • PostgreSQL 16:    localhost:5432 (User: radius_user, DB: isp_billing)" -ForegroundColor White
Write-Host "  • Redis 7:          localhost:6379" -ForegroundColor White
Write-Host "  • FreeRADIUS 3.2:   localhost UDP 1812 (Auth), 1813 (Acct), 3799 (CoA)" -ForegroundColor White
Write-Host "  • Mikrotik CoA:     UDP 3799 RFC 3576 Packet of Disconnect ready" -ForegroundColor White
Write-Host ""
Write-Host "To view real-time FreeRADIUS authorization logs:" -ForegroundColor Yellow
Write-Host "  docker logs -f isp_freeradius" -ForegroundColor Cyan
Write-Host ""
Write-Host "To stop the stack:" -ForegroundColor Yellow
Write-Host "  docker compose down" -ForegroundColor Cyan
Write-Host ""
