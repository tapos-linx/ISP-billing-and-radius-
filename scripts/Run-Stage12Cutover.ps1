[CmdletBinding()]
param(
    [switch]$PrintRos,
    [switch]$Cleanup
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

Write-Section "Stage 12: Zero-Downtime Production Cutover & Rollback Drill"

$secretFile = Join-Path $ProjectDir "secrets\radius_secret.txt"
$radiusSecret = if (Test-Path $secretFile) { (Get-Content $secretFile -Raw).Trim() } else { "secure_radius_secret_key_2026" }

if ($PrintRos) {
    Write-Section "Printing MikroTik RouterOS v7 Cutover Configuration"
    wsl bash -c "cd /mnt/c/Users/DESKTOP-SHIPU-W2/projects/ISP-billing-and-radius && bash scripts/run_single_sub_pilot.sh -s 127.0.0.1 -m 127.0.0.1 -r $radiusSecret --print-ros"
    exit 0
}

if ($Cleanup) {
    Write-Section "Executing Safe Pilot Teardown & Baseline Rollback"
    wsl bash -c "cd /mnt/c/Users/DESKTOP-SHIPU-W2/projects/ISP-billing-and-radius && bash scripts/run_single_sub_pilot.sh -s 127.0.0.1 --cleanup"
    exit 0
}

# Run 8-Gate Master Cutover & Maintenance Window Drill
Write-Section "Executing 8-Gate Master Cutover & Pilot Suite"
python scripts/test_stage12_cutover_and_pilot.py
if ($LASTEXITCODE -ne 0) {
    Write-Host "[FAIL] Stage 12 cutover verification suite failed." -ForegroundColor Red
    exit 1
}

Write-Host ""
Write-Host "==========================================================================" -ForegroundColor Green
Write-Host "   STAGE 12 PRODUCTION CUTOVER & ROLLBACK: 100% VERIFIED & READY" -ForegroundColor Green
Write-Host "==========================================================================" -ForegroundColor Green
