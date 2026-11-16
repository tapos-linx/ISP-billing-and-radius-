# ====================================================================
# Script: setup-stage1.ps1
# Stage: Stage 1 (Foundation & RADIUS Core)
# Project: ISP Billing Software (by Tapos)
# Environment: Windows 10 / Windows 11 (64-bit) with Docker Desktop (WSL2)
# ====================================================================

[CmdletBinding()]
param (
    [switch]${'$'}ForceRebuild
)

${'$'}ErrorActionPreference = "Stop"

Write-Host "==========================================================" -ForegroundColor Cyan
Write-Host " ISP Billing: Stage 1 Automated Setup (WSL2 & Docker)     " -ForegroundColor Cyan
Write-Host "==========================================================" -ForegroundColor Cyan

# 1. Verify Docker Engine is running
Write-Host "`n[1/5] Verifying Docker Desktop & WSL2 backend..." -ForegroundColor Yellow
try {
    ${'$'}dockerVersion = docker version --format '{{.Server.Version}}' 2>${'$'}null
    Write-Host "[OK] Docker Engine is running (v${'$'}dockerVersion)" -ForegroundColor Green
} catch {
    Write-Error "[FAIL] Docker is not running. Please start Docker Desktop (WSL2 backend) and retry."
    exit 1
}

# 2. Sanitize CRLF (\r) from SQL, shell scripts, and configs to prevent container errors
Write-Host "`n[2/5] Stripping Windows CRLF line endings from configs & SQL..." -ForegroundColor Yellow
${'$'}filesToSanitize = @(
    "sql\01-init-schema.sql",
    "sql\02-seed-test-data.sql",
    "freeradius\radiusd.conf",
    "freeradius\clients.conf",
    "freeradius\mods-available\sql",
    "scripts\entrypoint-freeradius.sh"
)

${'$'}scriptRoot = ${'$'}PSScriptRoot
if (-not ${'$'}scriptRoot) { ${'$'}scriptRoot = Split-Path -Parent ${'$'}MyInvocation.MyCommand.Definition }
${'$'}stage1Dir = Split-Path -Parent ${'$'}scriptRoot

foreach (${'$'}file in ${'$'}filesToSanitize) {
    ${'$'}fullPath = Join-Path ${'$'}stage1Dir ${'$'}file
    if (Test-Path ${'$'}fullPath) {
        ${'$'}content = [System.IO.File]::ReadAllText(${'$'}fullPath)
        ${'$'}cleanContent = ${'$'}content.Replace("`r`n", "`n").Replace("`r", "`n")
        [System.IO.File]::WriteAllText(${'$'}fullPath, ${'$'}cleanContent, [System.Text.UTF8Encoding]::new(${'$'}false))
        Write-Host "  -> Sanitized: ${'$'}file" -ForegroundColor Gray
    }
}

# 3. Launch Docker Compose Stack
Write-Host "`n[3/5] Starting Docker Compose Stack (PostgreSQL 16, Redis 7, FreeRADIUS 3.2)..." -ForegroundColor Yellow
Set-Location ${'$'}stage1Dir

${'$'}composeCmd = if (${'$'}ForceRebuild) { "docker compose up -d --build --force-recreate" } else { "docker compose up -d" }
Invoke-Expression ${'$'}composeCmd

# 4. Wait for Service Health Checks
Write-Host "`n[4/5] Awaiting container health checks..." -ForegroundColor Yellow

${'$'}maxAttempts = 30
${'$'}attempt = 0
${'$'}healthy = ${'$'}false

while (-not ${'$'}healthy -and ${'$'}attempt -lt ${'$'}maxAttempts) {
    ${'$'}attempt++
    Start-Sleep -Seconds 2
    ${'$'}pgHealth = docker inspect --format='{{json .State.Health.Status}}' isp_postgres 2>${'$'}null
    ${'$'}redisHealth = docker inspect --format='{{json .State.Health.Status}}' isp_redis 2>${'$'}null
    
    if (${'$'}pgHealth -match "healthy" -and ${'$'}redisHealth -match "healthy") {
        ${'$'}healthy = ${'$'}true
        Write-Host "[OK] PostgreSQL 16 and Redis 7 are HEALTHY!" -ForegroundColor Green
    } else {
        Write-Host "  Attempt ${'$'}attempt/${'$'}maxAttempts: Postgres=${'$'}pgHealth, Redis=${'$'}redisHealth" -ForegroundColor Gray
    }
}

if (-not ${'$'}healthy) {
    Write-Warning "[WARN] Timed out waiting for automatic healthcheck. Checking port connectivity..."
}

# 5. Verify Seed User `tapos_test` in PostgreSQL 16
Write-Host "`n[5/5] Verifying seeded PPPoE subscriber 'tapos_test' in PostgreSQL..." -ForegroundColor Yellow
Start-Sleep -Seconds 2

${'$'}userCheck = docker exec isp_postgres psql -U radius_user -d isp_billing -t -c "SELECT username, value FROM radcheck WHERE username='tapos_test';" 2>${'$'}null
${'$'}rateCheck = docker exec isp_postgres psql -U radius_user -d isp_billing -t -c "SELECT attribute, value FROM radreply WHERE username='tapos_test' AND attribute='Mikrotik-Rate-Limit';" 2>${'$'}null

if (${'$'}userCheck -match "tapos_test" -and ${'$'}rateCheck -match "20M/20M") {
    Write-Host "[SUCCESS] Seed user verified in database:" -ForegroundColor Green
    Write-Host "  -> User: tapos_test" -ForegroundColor Cyan
    Write-Host "  -> Rate-Limit: 20M/20M" -ForegroundColor Cyan
} else {
    Write-Warning "Seed user not found yet. Applying seed script directly..."
    docker exec -i isp_postgres psql -U radius_user -d isp_billing < (Join-Path ${'$'}stage1Dir "sql\02-seed-test-data.sql")
    Write-Host "[OK] Seed script applied directly." -ForegroundColor Green
}

Write-Host "`n==========================================================" -ForegroundColor Green
Write-Host " Stage 1 Setup Complete! Next Step: Run verify-radtest.ps1 " -ForegroundColor Green
Write-Host " Command: .\scripts\verify-radtest.ps1 -TotalPackets 100    " -ForegroundColor Cyan
Write-Host "==========================================================" -ForegroundColor Green
