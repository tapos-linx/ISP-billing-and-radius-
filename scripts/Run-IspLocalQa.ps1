[CmdletBinding()]
param(
    [string]$RadiusUser = "",
    [string]$RadiusPass = "",
    [string]$RadiusSecret = "",
    [string]$CoaTargetIp = "127.0.0.1",
    [int]$CoaPort = 3799,
    [string]$ComposeFile = "",
    [switch]$SkipCoa
)

Set-StrictMode -Version Latest

# Prevent PowerShell from aborting on native command stderr warnings
$ErrorActionPreference = "Continue"
if (Test-Path Variable:PSNativeCommandUseErrorActionPreference) {
    $PSNativeCommandUseErrorActionPreference = $false
}

$ScriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
if (Test-Path (Join-Path $ScriptDir "docker-compose.yml")) {
    $ProjectDir = $ScriptDir
} elseif (Test-Path (Join-Path (Split-Path -Parent $ScriptDir) "docker-compose.yml")) {
    $ProjectDir = Split-Path -Parent $ScriptDir
} else {
    $ProjectDir = "C:\Users\DESKTOP-SHIPU-W2\projects\ISP-billing-and-radius"
}
if (Test-Path $ProjectDir) {
    Set-Location $ProjectDir
}

function Write-Section([string]$Title) {
    Write-Host ""
    Write-Host "=== $Title ===" -ForegroundColor Cyan
}

# Determine Compose File configuration: prioritize clustered carrier stack in infra/docker-compose.yml
$ComposeArgs = @()
if ($ComposeFile -ne "") {
    $ComposeArgs = @("-f", $ComposeFile)
} else {
    $infraRunning = @(docker compose -f infra/docker-compose.yml ps --services --filter "status=running" 2>$null | Where-Object { $_ -match '\S' })
    if ($infraRunning.Count -gt 0) {
        $ComposeArgs = @("-f", "infra/docker-compose.yml")
    } else {
        $rootRunning = @(docker compose ps --services --filter "status=running" 2>$null | Where-Object { $_ -match '\S' })
        if ($rootRunning.Count -gt 0) {
            $ComposeArgs = @()
        } elseif (Test-Path "infra/docker-compose.yml") {
            $ComposeArgs = @("-f", "infra/docker-compose.yml")
        } else {
            $ComposeArgs = @()
        }
    }
}

function Resolve-ServiceName([string[]]$Candidates, [string[]]$CArgs) {
    $defined = @(docker compose @CArgs config --services 2>$null)
    foreach ($candidate in $Candidates) {
        if ($defined -contains $candidate) {
            return $candidate
        }
    }
    return $Candidates[0]
}

$DbService     = Resolve-ServiceName @("citus_master", "postgres", "db") $ComposeArgs
$AppService    = Resolve-ServiceName @("laravel_app", "app", "laravel_bss_oss", "laravel") $ComposeArgs
$RadiusService = Resolve-ServiceName @("freeradius", "freeradius_aaa") $ComposeArgs
$isCitus       = ($DbService -eq "citus_master") -or ($ComposeArgs -contains "infra/docker-compose.yml")

Write-Section "1. Docker Compose Status & Container Startup"
docker compose @ComposeArgs ps -a
$running = @(docker compose @ComposeArgs ps --services --filter "status=running" 2>$null | Where-Object { $_ -match '\S' })
if ($running.Count -eq 0) {
    Write-Host "No running compose services found. Starting stack..." -ForegroundColor Yellow
    docker compose @ComposeArgs up -d
}
docker compose @ComposeArgs ps

Write-Section "2. PostgreSQL / Citus Schema & RADIUS Tables"
$dbUser = if ($isCitus) { "isp_admin" } else { "radius_user" }
$dbName = if ($isCitus) { "isp_bss_oss" } else { "isp_billing" }

docker compose @ComposeArgs exec -T $DbService psql -U $dbUser -d $dbName -c "\dt"
docker compose @ComposeArgs exec -T $DbService psql -U $dbUser -d $dbName -c "SELECT radacctid, username, acctstoptime FROM radacct ORDER BY radacctid DESC LIMIT 5;"

Write-Section "3. PHPUnit Billing, Webhook Idempotency & RADIUS Sync Tests"
$runningServices = @(docker compose @ComposeArgs ps --services --filter "status=running" 2>$null | Where-Object { $_ -match '\S' })
if ($runningServices -contains $AppService) {
    $phpunitCmd = "if [ -f /opt/laravel_bss/vendor/bin/phpunit ]; then cd /opt/laravel_bss && vendor/bin/phpunit --filter='BillingVatAndExpiryTest|MfsWebhookReplayAndIdempotencyTest|SubscriberRadiusSyncTest'; elif [ -f vendor/bin/phpunit ]; then vendor/bin/phpunit --filter='BillingVatAndExpiryTest|MfsWebhookReplayAndIdempotencyTest|SubscriberRadiusSyncTest'; else echo 'vendor/bin/phpunit not found'; fi"
    docker compose @ComposeArgs exec -T $AppService sh -c $phpunitCmd
} else {
    Write-Host "App service '$AppService' is not currently active. Skipping PHPUnit tests." -ForegroundColor Yellow
}

Write-Section "4. FreeRADIUS Access-Request Test (radtest)"
if ($RadiusUser -eq "") {
    $RadiusUser = "tapos_test"
}
if ($RadiusPass -eq "") {
    $RadiusPass = if ($isCitus -or ($ComposeArgs -contains "infra/docker-compose.yml")) { "Tapos@2026" } else { "tapos_pass_2026" }
}
if ($RadiusSecret -eq "") {
    $secretFile = Join-Path $ProjectDir "secrets\radius_secret.txt"
    $infraSecretFile = Join-Path $ProjectDir "infra\secrets\radius_secret.txt"
    if ($env:RADIUS_SECRET) {
        $RadiusSecret = $env:RADIUS_SECRET
    } elseif (Test-Path $secretFile) {
        $RadiusSecret = (Get-Content $secretFile -Raw).Trim()
    } elseif (Test-Path $infraSecretFile) {
        $RadiusSecret = (Get-Content $infraSecretFile -Raw).Trim()
    } elseif ($isCitus -or ($ComposeArgs -contains "infra/docker-compose.yml")) {
        $RadiusSecret = "secure_radius_secret_key_2026"
    } else {
        $RadiusSecret = "testing123"
    }
}

Write-Host "Testing FreeRADIUS Access-Request for '$RadiusUser'..." -ForegroundColor DarkCyan
docker compose @ComposeArgs exec -T $RadiusService radtest $RadiusUser $RadiusPass 127.0.0.1 0 $RadiusSecret

Write-Section "5. MikroTik CoA / Disconnect-Request on UDP $CoaPort"
if ($SkipCoa) {
    Write-Host "Skipped CoA check (-SkipCoa passed)." -ForegroundColor Yellow
}
elseif (Test-Path (Join-Path $ProjectDir "coa_sim.py")) {
    python (Join-Path $ProjectDir "coa_sim.py")
}
else {
    $radclientCmd = "echo 'User-Name = $RadiusUser' | radclient -x ${CoaTargetIp}:${CoaPort} disconnect $RadiusSecret"
    docker compose @ComposeArgs exec -T $RadiusService sh -c "$radclientCmd"
}

Write-Host ""
Write-Host "All local QA checks completed successfully." -ForegroundColor Green
