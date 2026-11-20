<#
.SYNOPSIS
  Stage 11: Google Cloud Free Tier / Cloud Run + GCE Ubuntu 24.04 FreeRADIUS Staging Deployment
  Owner: Tapos (N M Hedyetullah Tapos)
#>
param(
    [string]$ProjectId = "gen-lang-client-0620800335",
    [string]$Region = "asia-south1",
    [string]$Zone = "asia-south1-a",
    [string]$ServiceName = "isp-billing-bss-api",
    [string]$VmName = "freeradius-vm-stage"
)

Write-Host "=================================================================" -ForegroundColor Cyan
Write-Host " ISP Billing — Stage 11 Google Cloud Staging Deployment" -ForegroundColor Cyan
Write-Host " Project: $ProjectId | Region: $Region | Zone: $Zone" -ForegroundColor Cyan
Write-Host "=================================================================" -ForegroundColor Cyan

# Step 1: Pre-flight billing check
Write-Host "`n[1/6] Checking Google Cloud Billing Status..." -ForegroundColor Yellow
$billing = gcloud billing projects describe $ProjectId --format="value(billingEnabled)" 2>$null
if ($billing -ne "True") {
    Write-Host "[BLOCKED] Billing is NOT active for project '$ProjectId'." -ForegroundColor Red
    Write-Host "Please link an active Cloud Billing account via Google Cloud Console:" -ForegroundColor Yellow
    Write-Host "https://console.cloud.google.com/billing/linkedaccount?project=$ProjectId" -ForegroundColor Cyan
    exit 1
}
Write-Host "  [PASS] Billing is active." -ForegroundColor Green

# Step 2: Enable Required APIs
Write-Host "`n[2/6] Enabling Required GCP Service APIs..." -ForegroundColor Yellow
$apis = @(
    "run.googleapis.com",
    "compute.googleapis.com",
    "artifactregistry.googleapis.com",
    "cloudbuild.googleapis.com",
    "secretmanager.googleapis.com",
    "vpcaccess.googleapis.com"
)
foreach ($api in $apis) {
    Write-Host "  Enabling $api..."
    gcloud services enable $api --project=$ProjectId
}

# Step 3: Artifact Registry Setup
Write-Host "`n[3/6] Ensuring Artifact Registry repository exists..." -ForegroundColor Yellow
$repoExists = gcloud artifacts repositories describe isp-billing --location=$Region --project=$ProjectId 2>$null
if (-not $repoExists) {
    gcloud artifacts repositories create isp-billing `
        --repository-format=docker `
        --location=$Region `
        --description="ISP Billing & FreeRADIUS Docker Repository" `
        --project=$ProjectId
}

# Step 4: GCE Ubuntu 24.04 e2-micro VM Provisioning
Write-Host "`n[4/6] Provisioning GCE e2-micro FreeRADIUS VM..." -ForegroundColor Yellow
$vmExists = gcloud compute instances describe $VmName --zone=$Zone --project=$ProjectId 2>$null
if (-not $vmExists) {
    gcloud compute instances create $VmName `
        --zone=$Zone `
        --machine-type=e2-micro `
        --image-family=ubuntu-2404-lts `
        --image-project=ubuntu-os-cloud `
        --tags=radius-server,vpn-gateway `
        --boot-disk-size=30GB `
        --boot-disk-type=pd-standard `
        --project=$ProjectId
} else {
    Write-Host "  [INFO] VM '$VmName' already exists in $Zone." -ForegroundColor Green
}

# Firewall rules for UDP 1812/1813/3799 and WireGuard 51820
$fwRadius = gcloud compute firewall-rules describe allow-freeradius-ports --project=$ProjectId 2>$null
if (-not $fwRadius) {
    gcloud compute firewall-rules create allow-freeradius-ports `
        --network=default `
        --action=ALLOW `
        --rules=udp:1812,udp:1813,udp:3799 `
        --source-ranges=10.10.0.0/24,172.16.0.0/12 `
        --target-tags=radius-server `
        --project=$ProjectId
}

# Step 5: Build and Deploy Cloud Run BSS/OSS Container
Write-Host "`n[5/6] Building and Deploying Cloud Run Service..." -ForegroundColor Yellow
gcloud builds submit --config=infra/cloudbuild.yaml --project=$ProjectId .

# Step 6: Verify Health Endpoints
Write-Host "`n[6/6] Verifying Staging Cloud Run Service..." -ForegroundColor Yellow
$serviceUrl = gcloud run services describe $ServiceName --region=$Region --project=$ProjectId --format="value(status.url)"
if ($serviceUrl) {
    Write-Host "  Service URL: $serviceUrl" -ForegroundColor Cyan
    Write-Host "  Querying $serviceUrl/api/v1/health..."
    try {
        $resp = Invoke-RestMethod -Uri "$serviceUrl/api/v1/health" -Method Get
        Write-Host "  [PASS] Staging Cloud Run Health Response: $($resp | ConvertTo-Json -Compress)" -ForegroundColor Green
    } catch {
        Write-Host "  [WARN] Health probe returned: $_" -ForegroundColor Yellow
    }
}

Write-Host "`n=================================================================" -ForegroundColor Cyan
Write-Host " Stage 11 Staging Deployment Process Completed." -ForegroundColor Cyan
Write-Host "=================================================================" -ForegroundColor Cyan
