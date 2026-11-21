<#
.SYNOPSIS
    Stage 13: Evolution & Day-2 Carrier Operations Runner
.DESCRIPTION
    Executes the 7-gate verification suite for Stage 13:
    1. Evolution Directory & Documentation Structure
    2. Release 2.0 Architectural Specifications (Vouchers, SIP CDRs, ClickHouse IPDR)
    3. Technical Debt Catalog & Prioritization Integrity
    4. Incident Postmortem Engine & Blameless Runbook Validation
    5. Day-2 Operational Cluster Health & Docker Fleet Inspection
    6. Master CI Regression Pass (36 PHPUnit Tests, 179 Assertions)
    7. Plan, Readme & Changelog Synchronization
#>

[CmdletBinding()]
param(
    [switch]$SkipRegression = $false
)

$ErrorActionPreference = "Stop"
$ScriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$RepoRoot = Split-Path -Parent $ScriptDir

Write-Host "========================================================" -ForegroundColor Cyan
Write-Host "  ISP BILLING: STAGE 13 EVOLUTION & DAY-2 OPERATIONS   " -ForegroundColor Cyan
Write-Host "========================================================" -ForegroundColor Cyan
Write-Host "Repository Root: $RepoRoot" -ForegroundColor Gray

$PythonScript = Join-Path $ScriptDir "test_stage13_evolution_and_day2_ops.py"

if (-not (Test-Path $PythonScript)) {
    Write-Error "Verification script not found: $PythonScript"
    exit 1
}

# Find Python executable
$PythonCmd = "python"
if (-not (Get-Command $PythonCmd -ErrorAction SilentlyContinue)) {
    $PythonCmd = "py"
}

Write-Host "[+] Executing Stage 13 verification suite via $PythonCmd..." -ForegroundColor Yellow

$proc = Start-Process -FilePath $PythonCmd -ArgumentList @($PythonScript) -NoNewWindow -PassThru -Wait

if ($proc.ExitCode -eq 0) {
    Write-Host "`n[SUCCESS] Stage 13 Evolution & Day-2 Operations 100% Verified!" -ForegroundColor Green
    exit 0
} else {
    Write-Host "`n[FAILED] Stage 13 verification failed with exit code $($proc.ExitCode)" -ForegroundColor Red
    exit $proc.ExitCode
}
