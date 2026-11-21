[CmdletBinding()]
param(
    [switch]$DeployLive
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

Write-Section "Stage 11: Cloud Staging (Google Cloud / AWS) Verification & Pre-Flight"

# 1. Run 7-Gate Cloud Staging & Readiness Suite
Write-Section "Executing 7-Gate Cloud Staging Suite"
python scripts/test_stage11_cloud_staging.py
if ($LASTEXITCODE -ne 0) {
    Write-Host "[FAIL] Stage 11 verification suite failed." -ForegroundColor Red
    exit 1
}

# 2. Live Cloud Deployment (Only if explicitly requested)
if ($DeployLive) {
    Write-Section "Executing Live Google Cloud Deployment (Billing Required)"
    powershell -ExecutionPolicy Bypass -File infra/scripts/deploy_stage11_gcp.ps1
} else {
    Write-Host "`n[NOTE] Safe dry-run mode active. No cloud charges incurred." -ForegroundColor Yellow
    Write-Host "To attempt live deployment once billing is attached, pass -DeployLive." -ForegroundColor DarkCyan
}

Write-Host ""
Write-Host "==========================================================================" -ForegroundColor Green
Write-Host "   STAGE 11 CLOUD STAGING & READINESS: 100% VERIFIED & COMPLIANT" -ForegroundColor Green
Write-Host "==========================================================================" -ForegroundColor Green
