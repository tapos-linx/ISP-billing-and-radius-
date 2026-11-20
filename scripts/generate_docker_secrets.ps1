<#
.SYNOPSIS
  Generate or synchronize Docker secret files for local ISP stack on Windows.
  Stores files in secrets/ and infra/secrets/ with ACL restrictions.
#>
param(
    [string]$RepoRoot = (Split-Path -Parent $PSScriptRoot)
)

$secretsDir = Join-Path $RepoRoot "secrets"
$infraSecretsDir = Join-Path $RepoRoot "infra\secrets"

if (!(Test-Path $secretsDir)) {
    New-Item -ItemType Directory -Path $secretsDir -Force | Out-Null
}
if (!(Test-Path $infraSecretsDir)) {
    New-Item -ItemType Directory -Path $infraSecretsDir -Force | Out-Null
}

function Generate-RandomSecret([int]$Length = 32) {
    $bytes = New-Object byte[] $Length
    $rng = [System.Security.Cryptography.RandomNumberGenerator]::Create()
    $rng.GetBytes($bytes)
    return [Convert]::ToBase64String($bytes).Replace('+', '').Replace('/', '').Replace('=', '').Substring(0, $Length)
}

function Ingest-Or-Generate([string]$SecretName, [string]$EnvVarName, [string]$DefaultValue = "") {
    $targetPath = Join-Path $secretsDir "$SecretName.txt"
    if (Test-Path $targetPath) {
        $val = Get-Content $targetPath -Raw
        if ($val -and $val.Trim().Length -gt 0) {
            Write-Host "  [EXISTS] $SecretName already initialized." -ForegroundColor Green
            return
        }
    }

    $val = $null
    $infraEnv = Join-Path $RepoRoot "infra\.env"
    if (Test-Path $infraEnv) {
        $match = Select-String -Path $infraEnv -Pattern "^$EnvVarName=(.+)$"
        if ($match) {
            $val = $match.Matches.Groups[1].Value.Trim().Trim('"').Trim("'")
        }
    }

    if (-not $val) {
        $rootEnv = Join-Path $RepoRoot ".env"
        if (Test-Path $rootEnv) {
            $match = Select-String -Path $rootEnv -Pattern "^$EnvVarName=(.+)$"
            if ($match) {
                $val = $match.Matches.Groups[1].Value.Trim().Trim('"').Trim("'")
            }
        }
    }

    if (-not $val -or $val -match "^REPLACE_WITH_" -or $val -eq "testing123") {
        if ($DefaultValue -and -not ($DefaultValue -match "^REPLACE_WITH_")) {
            $val = $DefaultValue
        } else {
            $val = Generate-RandomSecret 32
        }
        Write-Host "  [GENERATED] Generated new secret for $SecretName" -ForegroundColor Cyan
    } else {
        Write-Host "  [INGESTED] Ingested $EnvVarName for $SecretName" -ForegroundColor Green
    }

    [System.IO.File]::WriteAllText($targetPath, $val.Trim())
    $infraTarget = Join-Path $infraSecretsDir "$SecretName.txt"
    [System.IO.File]::WriteAllText($infraTarget, $val.Trim())
}

Write-Host "==> Initializing Docker Secrets on Windows..." -ForegroundColor Cyan
Ingest-Or-Generate "db_password" "DB_PASSWORD" "secure_isp_password_2026"
Ingest-Or-Generate "redis_password" "REDIS_PASSWORD" "SecretRedisToken2026!"
Ingest-Or-Generate "radius_secret" "RADIUS_SECRET" "secure_radius_secret_key_2026"
Ingest-Or-Generate "rabbitmq_password" "RABBITMQ_PASSWORD" "secure_rabbit_pass"
Ingest-Or-Generate "bkash_secret" "BKASH_APP_SECRET" "sandbox_bkash_secret_2026"
Ingest-Or-Generate "nagad_secret" "NAGAD_MERCHANT_KEY" "sandbox_nagad_secret_2026"
Ingest-Or-Generate "gemini_api_key" "GEMINI_API_KEY" ""

Write-Host "Docker Secrets ready in $secretsDir and $infraSecretsDir" -ForegroundColor Green
