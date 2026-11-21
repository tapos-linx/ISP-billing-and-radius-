<#
.SYNOPSIS
    Release 2.0 (Epic 1): Wi-Fi Hotspot Voucher Verification Runner
.DESCRIPTION
    Executes the 7-gate verification suite for Release 2.0 Epic 1:
    1. Citus 128-Shard & Reference Table Verification
    2. Batch Voucher Generation API & Database Persistence
    3. Redis Fast-Path Verification & Sub-Millisecond Latency Benchmark (<0.4ms)
    4. Captive Portal Authentication & WISPr Attributes Verification
    5. 80mm ESC/POS Thermal Receipt Layout & NBR 5% VAT Verification
    6. Interim Accounting & Quota Burn Simulation
    7. Master CI/CD Regression Check (42/42 PHPUnit Tests, 215 Assertions)
#>

[CmdletBinding()]
param()

$ErrorActionPreference = "Stop"
$ScriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$RepoRoot = Split-Path -Parent $ScriptDir

Write-Host "========================================================" -ForegroundColor Cyan
Write-Host "  RELEASE 2.0 (EPIC 1): WI-FI HOTSPOT VOUCHER SUITE    " -ForegroundColor Cyan
Write-Host "========================================================" -ForegroundColor Cyan
Write-Host "Repository Root: $RepoRoot" -ForegroundColor Gray

$PythonScript = Join-Path $ScriptDir "test_release2_hotspot_voucher.py"

if (-not (Test-Path $PythonScript)) {
    Write-Error "Verification script not found: $PythonScript"
    exit 1
}

$PythonCmd = "python"
if (-not (Get-Command $PythonCmd -ErrorAction SilentlyContinue)) {
    $PythonCmd = "py"
}

Write-Host "[+] Executing Release 2.0 Epic 1 verification suite via $PythonCmd..." -ForegroundColor Yellow

$proc = Start-Process -FilePath $PythonCmd -ArgumentList @($PythonScript) -NoNewWindow -PassThru -Wait

if ($proc.ExitCode -eq 0) {
    Write-Host "`n[SUCCESS] Release 2.0 Epic 1 Hotspot Voucher Engine 100% Verified!" -ForegroundColor Green
    exit 0
} else {
    Write-Host "`n[FAILED] Verification failed with exit code $($proc.ExitCode)" -ForegroundColor Red
    exit $proc.ExitCode
}
