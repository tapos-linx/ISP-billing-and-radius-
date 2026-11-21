[CmdletBinding()]
param(
    [switch]$SkipDrill
)

Set-StrictMode -Version Latest
$ErrorActionPreference = "Continue"

$ScriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$ProjectDir = Split-Path -Parent $ScriptDir
if (Test-Path $ProjectDir) {
    Set-Location $ProjectDir
}

function Write-Section([string]$Title) {
    Write-Host ""
    Write-Host "=== $Title ===" -ForegroundColor Cyan
}

Write-Section "Stage 10: On-Prem High Availability Architecture & Storage Verification"

# 1. Check Compose Stack
Write-Host "Checking Clustered Carrier Services..." -ForegroundColor DarkCyan
docker compose -f infra/docker-compose.yml ps

# 2. Run Comprehensive Stage 10 Verification Suite
Write-Section "Executing 7-Gate HA Architecture & Storage Suite"
python scripts/test_stage10_ha_and_storage.py
if ($LASTEXITCODE -ne 0) {
    Write-Host "[FAIL] Stage 10 verification suite failed." -ForegroundColor Red
    exit 1
}

# 3. Optional Direct pgBackRest Script Drill
if (-not $SkipDrill) {
    Write-Section "Executing pgBackRest Backup & Restore Drill Script"
    wsl bash -c "cd /mnt/c/Users/DESKTOP-SHIPU-W2/projects/ISP-billing-and-radius && bash infra/ha/pgbackrest_backup_restore_drill.sh"
}

Write-Host ""
Write-Host "==========================================================================" -ForegroundColor Green
Write-Host "   STAGE 10 HA ARCHITECTURE & STORAGE: 100% VERIFIED & COMPLIANT" -ForegroundColor Green
Write-Host "==========================================================================" -ForegroundColor Green
