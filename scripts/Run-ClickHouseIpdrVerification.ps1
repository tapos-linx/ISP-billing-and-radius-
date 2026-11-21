<#
.SYNOPSIS
    Release 2.0 (Epic 3): ClickHouse 2-Year BTRC IPDR Verification Runner
.DESCRIPTION
    Executes the 6-gate verification suite for Release 2.0 Epic 3:
    1. ClickHouse Cluster Health & Ping Inspection
    2. Database & 730-Day TTL Columnar Schema Verification
    3. Bulk IPDR Ingestion Throughput Simulation (Micro-Batch Ingest)
    4. Sub-Second BTRC Forensic Query Benchmark (< 50ms)
    5. Monthly Regulatory Compliance Aggregation
    6. Master Local CI Regression Pass (45/45 PHPUnit Tests, 228 Assertions)
#>

[CmdletBinding()]
param()

$ErrorActionPreference = "Stop"
$ScriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$RepoRoot = Split-Path -Parent $ScriptDir

Write-Host "========================================================" -ForegroundColor Cyan
Write-Host "  RELEASE 2.0 (EPIC 3): CLICKHOUSE BTRC IPDR SUITE     " -ForegroundColor Cyan
Write-Host "========================================================" -ForegroundColor Cyan
Write-Host "Repository Root: $RepoRoot" -ForegroundColor Gray

$PythonScript = Join-Path $ScriptDir "test_release2_clickhouse_ipdr.py"

if (-not (Test-Path $PythonScript)) {
    Write-Error "Verification script not found: $PythonScript"
    exit 1
}

$PythonCmd = "python"
if (-not (Get-Command $PythonCmd -ErrorAction SilentlyContinue)) {
    $PythonCmd = "py"
}

Write-Host "[+] Executing Release 2.0 Epic 3 verification suite via $PythonCmd..." -ForegroundColor Yellow

$proc = Start-Process -FilePath $PythonCmd -ArgumentList @($PythonScript) -NoNewWindow -PassThru -Wait

if ($proc.ExitCode -eq 0) {
    Write-Host "`n[SUCCESS] Release 2.0 Epic 3 ClickHouse IPDR Engine 100% Verified!" -ForegroundColor Green
    exit 0
} else {
    Write-Host "`n[FAILED] Verification failed with exit code $($proc.ExitCode)" -ForegroundColor Red
    exit $proc.ExitCode
}
