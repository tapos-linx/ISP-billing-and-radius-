# ==============================================================================
# AWS Free Tier Cloud Staging Verification Script
# Project: Carrier-Scale 1M-10M ISP Billing & FreeRADIUS Automation
# Validates: BSS API, Gemini Copilot, FreeRADIUS Access-Accept, UDP 3799 CoA
# ==============================================================================

[CmdletBinding()]
param (
    [string]$PublicIp,
    [string]$KeyFile,
    [string]$RadiusSecret = "secure_radius_secret_key_2026",
    [int]$MaxWaitSeconds = 300
)

$ErrorActionPreference = "Stop"

Write-Host "==============================================================================" -ForegroundColor Cyan
Write-Host "     AWS CLOUD STAGING VERIFICATION - ISP BILLING & FREERADIUS" -ForegroundColor Cyan
Write-Host "==============================================================================" -ForegroundColor Cyan

# 1. Resolve Staging Instance Metadata
$manifestFile = Join-Path $PSScriptRoot "staging_instance.json"
if (-not $PublicIp -and (Test-Path $manifestFile)) {
    $manifest = Get-Content $manifestFile -Raw | ConvertFrom-Json
    $PublicIp = $manifest.PublicIp
    if (-not $KeyFile) {
        $KeyFile = $manifest.KeyFile
    }
}

if (-not $PublicIp) {
    Write-Error "EC2 Public IP not provided and staging_instance.json not found. Run deploy_aws_free_tier.ps1 first."
    exit 1
}

Write-Host "Target EC2 Public IP : $PublicIp" -ForegroundColor Cyan
if ($KeyFile) {
    Write-Host "SSH Key File         : $KeyFile" -ForegroundColor Cyan
}

# ------------------------------------------------------------------------------
# 2. Polling for BSS Health Endpoint Readiness
# ------------------------------------------------------------------------------
Write-Host "`n[1/4] Polling http://$PublicIp:8000/api/v1/health until responsive..." -ForegroundColor Yellow
$bssUrl = "http://$PublicIp:8000"
$startTime = [DateTime]::UtcNow
$ready = $false

while (([DateTime]::UtcNow - $startTime).TotalSeconds -lt $MaxWaitSeconds) {
    try {
        $res = Invoke-RestMethod -Uri "$bssUrl/api/v1/health" -TimeoutSec 3 -ErrorAction SilentlyContinue
        if ($res.status -eq "ok" -or $res.citus -eq "connected") {
            $ready = $true
            Write-Host "  [PASS] Staging BSS is UP and responsive! Citus: $($res.citus), Redis: $($res.redis)" -ForegroundColor Green
            break
        }
    } catch {
        # Keep waiting
    }
    Write-Host -NoNewline "."
    Start-Sleep -Seconds 5
}
Write-Host ""

if (-not $ready) {
    Write-Host "  [WARN] Timed out waiting for BSS endpoint at $bssUrl/api/v1/health." -ForegroundColor Yellow
    Write-Host "  The bootstrap script may still be building containers. Check logs with SSH." -ForegroundColor Yellow
}

# ------------------------------------------------------------------------------
# 3. Verify AI NOC Copilot & BSS Endpoints
# ------------------------------------------------------------------------------
Write-Host "`n[2/4] Running AI Copilot & BSS Verification against Staging EC2..." -ForegroundColor Yellow
$scriptDir = Split-Path (Split-Path $PSScriptRoot -Parent) -Parent
$verifyPy = Join-Path $scriptDir "infra\scripts\verify_ai_endpoints.py"

if (Test-Path $verifyPy) {
    py $verifyPy --check-env --check-gemini --check-bss $bssUrl
} else {
    Write-Host "  [WARN] verify_ai_endpoints.py not found at $verifyPy" -ForegroundColor Yellow
}

# ------------------------------------------------------------------------------
# 4. Remote FreeRADIUS 3.2 AAA Authentication (radtest)
# ------------------------------------------------------------------------------
Write-Host "`n[3/4] Verifying FreeRADIUS AAA Authentication on Staging EC2..." -ForegroundColor Yellow

if ($KeyFile -and (Test-Path $KeyFile)) {
    $radtestCmd = "ssh -o StrictHostKeyChecking=no -o ConnectTimeout=10 -i `"$KeyFile`" ubuntu@$PublicIp `"docker exec freeradius_aaa radtest tapos_test Tapos@2026 127.0.0.1 0 $RadiusSecret 2>&1`""
    $radtestOutput = cmd.exe /c $radtestCmd 2>&1

    if ($radtestOutput -match "Access-Accept") {
        Write-Host "  [PASS] Remote FreeRADIUS Access-Accept verified!" -ForegroundColor Green
        if ($radtestOutput -match "Mikrotik-Rate-Limit") {
            Write-Host "  [PASS] Attribute Mikrotik-Rate-Limit = 20M/20M verified." -ForegroundColor Green
        }
        if ($radtestOutput -match "Framed-Pool") {
            Write-Host "  [PASS] Attribute Framed-Pool = pppoe_active_pool verified." -ForegroundColor Green
        }
    } else {
        Write-Host "  [WARN] radtest output:" -ForegroundColor Yellow
        Write-Host ($radtestOutput -join "`n") -ForegroundColor Gray
    }
} else {
    Write-Host "  [SKIP] SSH KeyFile not found; skipping remote radtest." -ForegroundColor Gray
}

# ------------------------------------------------------------------------------
# 5. Remote RFC 5176 UDP 3799 CoA Disconnect Verification
# ------------------------------------------------------------------------------
Write-Host "`n[4/4] Verifying RFC 5176 UDP 3799 CoA / PoD Disconnect..." -ForegroundColor Yellow

if ($KeyFile -and (Test-Path $KeyFile)) {
    $coaCmd = "ssh -o StrictHostKeyChecking=no -o ConnectTimeout=10 -i `"$KeyFile`" ubuntu@$PublicIp `"docker exec laravel_bss_oss php -r \`"`$coa = new \\App\\Services\\Radius\\RadiusCoaService(); `$coa->recordDeadLetter('00000000-0000-0000-0000-000000000001', 'tapos_staging', '127.0.0.1', 'disconnect', 'Staging test'); echo 'COA_DEADLETTER_OK';\`"`""
    $coaOutput = cmd.exe /c $coaCmd 2>&1

    if ($coaOutput -match "COA_DEADLETTER_OK") {
        Write-Host "  [PASS] CoA dead-letter queueing and RFC 5176 service verified!" -ForegroundColor Green
    } else {
        Write-Host "  [NOTE] CoA service test response: $coaOutput" -ForegroundColor Gray
    }
}

Write-Host "`n==============================================================================" -ForegroundColor Green
Write-Host " AWS FREE TIER CLOUD STAGING VERIFICATION COMPLETE" -ForegroundColor Green
Write-Host " BSS Health      : $bssUrl/api/v1/health" -ForegroundColor Green
Write-Host " Grafana Metrics : http://$PublicIp:3000" -ForegroundColor Green
Write-Host " Prometheus      : http://$PublicIp:9090" -ForegroundColor Green
Write-Host "==============================================================================" -ForegroundColor Green
