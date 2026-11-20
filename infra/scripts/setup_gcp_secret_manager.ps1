<#
.SYNOPSIS
  Stores ISP Billing & FreeRADIUS production/staging secrets in Google Cloud Secret Manager.
  Adheres to Google Ecosystem Base Rule: Zero hardcoded secrets in containers, git, or prompts.
#>
param(
    [string]$ProjectId = "gen-lang-client-0620800335",
    [string]$EnvFile = ".env"
)

Write-Host "=================================================================" -ForegroundColor Cyan
Write-Host " ISP Billing — Google Cloud Secret Manager Staging Synchronization" -ForegroundColor Cyan
Write-Host " Target Project: $ProjectId" -ForegroundColor Cyan
Write-Host "=================================================================" -ForegroundColor Cyan

# Ensure Secret Manager API is enabled
Write-Host "`n[1/3] Checking Secret Manager API status..." -ForegroundColor Yellow
gcloud services enable secretmanager.googleapis.com --project=$ProjectId
if ($LASTEXITCODE -ne 0) {
    Write-Host "[ERROR] Failed to enable secretmanager.googleapis.com. Ensure billing is active." -ForegroundColor Red
    exit 1
}

# Parse secrets from .env safely without echoing them
if (-not (Test-Path $EnvFile)) {
    Write-Host "[ERROR] Cannot find $EnvFile" -ForegroundColor Red
    exit 1
}

$secretsMap = @{}
Get-Content $EnvFile | ForEach-Object {
    $line = $_.Trim()
    if ($line -and -not $line.StartsWith("#") -and $line.Contains("=")) {
        $parts = $line.Split("=", 2)
        $k = $parts[0].Trim()
        $v = $parts[1].Trim().Trim('"').Trim("'")
        if ($k -in @("DB_PASSWORD", "RADIUS_SECRET", "GEMINI_API_KEY", "BKASH_APP_KEY", "BKASH_APP_SECRET", "NAGAD_MERCHANT_ID", "NAGAD_MERCHANT_KEY")) {
            $secretsMap[$k] = $v
        }
    }
}

Write-Host "`n[2/3] Upserting secrets into GCP Secret Manager..." -ForegroundColor Yellow
foreach ($key in $secretsMap.Keys) {
    $val = $secretsMap[$key]
    if (-not $val -or $val.StartsWith("REPLACE_WITH_")) {
        Write-Host "  [SKIP] $key has empty or placeholder value." -ForegroundColor DarkYellow
        continue
    }

    # Check if secret already exists
    $existing = gcloud secrets describe $key --project=$ProjectId --format="value(name)" 2>$null
    if (-not $existing) {
        Write-Host "  [CREATE] Creating secret: $key" -ForegroundColor Green
        gcloud secrets create $key --data-file=- --project=$ProjectId <<< $val 2>$null
    } else {
        Write-Host "  [UPDATE] Adding new secret version: $key" -ForegroundColor Green
        $val | gcloud secrets versions add $key --data-file=- --project=$ProjectId
    }
}

Write-Host "`n[3/3] Granting Secret Accessor permission to Cloud Run service account..." -ForegroundColor Yellow
$projectNumber = gcloud projects describe $ProjectId --format="value(projectNumber)"
$computeSa = "$projectNumber-compute@developer.gserviceaccount.com"

foreach ($key in $secretsMap.Keys) {
    gcloud secrets add-iam-policy-binding $key `
        --member="serviceAccount:$computeSa" `
        --role="roles/secretmanager.secretAccessor" `
        --project=$ProjectId --quiet | Out-Null
}

Write-Host "`n[SUCCESS] Secret Manager synchronization completed securely." -ForegroundColor Cyan
