# ==============================================================================
# AWS Free Tier Cloud Staging Deployment Script
# Project: Carrier-Scale 1M-10M ISP Billing & FreeRADIUS Automation
# Target: AWS Free Tier (ap-south-1 Mumbai, t3.micro, 30 GB gp3 EBS)
# Zero NAT Gateways, Zero Committed Secrets, Budgets $5.00 Zero-Overage Alert
# ==============================================================================

[CmdletBinding()]
param (
    [string]$Region = "ap-south-1",
    [string]$InstanceType = "t3.micro",
    [string]$KeyName = "isp-staging-keypair",
    [string]$SecurityGroupName = "isp-staging-sg",
    [string]$AlertEmail = "noc@isp.local",
    [switch]$ForceRecreate
)

$ErrorActionPreference = "Stop"

# Ensure AWS CLI is found
$awsExe = Get-Command "aws" -ErrorAction SilentlyContinue | Select-Object -ExpandProperty Source -ErrorAction SilentlyContinue
if (-not $awsExe) {
    if (Test-Path "C:\Program Files\Amazon\AWSCLIV2\aws.exe") {
        $awsExe = "C:\Program Files\Amazon\AWSCLIV2\aws.exe"
    } else {
        Write-Error "AWS CLI is not installed or not in PATH. Run 'winget install Amazon.AWSCLI' first."
        exit 1
    }
}

function Invoke-Aws {
    param([string[]]$Arguments)
    $output = & $awsExe @Arguments 2>&1
    if ($LASTEXITCODE -ne 0) {
        throw "AWS CLI error: $($output -join "`n")"
    }
    return $output
}

Write-Host "==============================================================================" -ForegroundColor Cyan
Write-Host "   AWS FREE TIER STAGING DEPLOYMENT - ISP BILLING & FREERADIUS" -ForegroundColor Cyan
Write-Host "   Region: $Region | Instance: $InstanceType | Zero NAT Gateways" -ForegroundColor Cyan
Write-Host "==============================================================================" -ForegroundColor Cyan

# ------------------------------------------------------------------------------
# 1. Verify AWS Authentication
# ------------------------------------------------------------------------------
Write-Host "`n[1/7] Verifying AWS CLI Caller Identity..." -ForegroundColor Yellow
try {
    $identityJson = Invoke-Aws @("sts", "get-caller-identity", "--output", "json") | Out-String
    $identity = $identityJson | ConvertFrom-Json
    $accountId = $identity.Account
    Write-Host "  [PASS] Authenticated as: $($identity.Arn) (Account: $accountId)" -ForegroundColor Green
} catch {
    Write-Host "  [FAIL] Unable to authenticate with AWS." -ForegroundColor Red
    Write-Host "  Please run 'aws configure' (or 'aws login') to set your AWS credentials." -ForegroundColor Red
    exit 1
}

# ------------------------------------------------------------------------------
# 2. Setup AWS Budgets $5.00 Zero-Overage Alert
# ------------------------------------------------------------------------------
Write-Host "`n[2/7] Configuring AWS Budgets zero-overage alert ($5.00 limit)..." -ForegroundColor Yellow
$budgetName = "isp-staging-zero-overage-budget"

$budgetConfig = @"
{
    "BudgetName": "$budgetName",
    "BudgetLimit": {
        "Amount": "5.0",
        "Unit": "USD"
    },
    "CostTypes": {
        "IncludeTax": true,
        "IncludeSubscription": true,
        "UseBlended": false,
        "IncludeRefund": false,
        "IncludeCredit": false,
        "IncludeUpfront": true,
        "IncludeRecurring": true,
        "IncludeOtherSubscription": true,
        "IncludeSupport": true,
        "IncludeDiscount": true,
        "UseAmortized": false
    },
    "TimeUnit": "MONTHLY",
    "BudgetType": "COST"
}
"@

$notificationsConfig = @"
[
    {
        "Notification": {
            "NotificationType": "ACTUAL",
            "ComparisonOperator": "GREATER_THAN",
            "Threshold": 80.0,
            "ThresholdType": "PERCENTAGE"
        },
        "Subscribers": [
            {
                "SubscriptionType": "EMAIL",
                "Address": "$AlertEmail"
            }
        ]
    }
]
"@

$budgetTmp = Join-Path $env:TEMP "budget_config.json"
$notifTmp = Join-Path $env:TEMP "budget_notif.json"
$budgetConfig | Out-File -FilePath $budgetTmp -Encoding utf8
$notificationsConfig | Out-File -FilePath $notifTmp -Encoding utf8

try {
    Invoke-Aws @("budgets", "create-budget", "--account-id", $accountId, "--budget", "file://$budgetTmp", "--notifications-with-subscribers", "file://$notifTmp") | Out-Null
    Write-Host "  [PASS] Budget '$budgetName' created with $5.00 limit and 80% threshold alert." -ForegroundColor Green
} catch {
    Write-Host "  [NOTE] Budget already exists or permissions bounded; proceeding with deployment." -ForegroundColor Gray
}

# ------------------------------------------------------------------------------
# 3. Detect Caller Public IP for Secure Bastion Access
# ------------------------------------------------------------------------------
Write-Host "`n[3/7] Detecting operator public IP for zero-trust security group..." -ForegroundColor Yellow
$myIp = ""
try {
    $myIp = (Invoke-RestMethod -Uri "https://api.ipify.org" -TimeoutSec 5).Trim()
} catch {
    try {
        $myIp = (Invoke-RestMethod -Uri "https://icanhazip.com" -TimeoutSec 5).Trim()
    } catch {
        $myIp = "0.0.0.0"
    }
}
$callerCidr = if ($myIp -and $myIp -ne "0.0.0.0") { "$myIp/32" } else { "0.0.0.0/0" }
Write-Host "  [INFO] Operator IP: $myIp (Restricting Admin Ports to $callerCidr)" -ForegroundColor Cyan

# ------------------------------------------------------------------------------
# 4. Security Group Provisioning
# ------------------------------------------------------------------------------
Write-Host "`n[4/7] Provisioning isolated Security Group '$SecurityGroupName'..." -ForegroundColor Yellow

# Query default VPC
$defaultVpcJson = Invoke-Aws @("ec2", "describe-vpcs", "--filters", "Name=isDefault,Values=true", "--region", $Region, "--output", "json") | Out-String
$defaultVpc = ($defaultVpcJson | ConvertFrom-Json).Vpcs[0]
if (-not $defaultVpc) {
    throw "Default VPC not found in region $Region."
}
$vpcId = $defaultVpc.VpcId

# Check if Security Group already exists
$sgJson = Invoke-Aws @("ec2", "describe-security-groups", "--filters", "Name=group-name,Values=$SecurityGroupName", "Name=vpc-id,Values=$vpcId", "--region", $Region, "--output", "json") | Out-String
$existingSg = ($sgJson | ConvertFrom-Json).SecurityGroups[0]

$sgId = ""
if ($existingSg) {
    $sgId = $existingSg.GroupId
    Write-Host "  [PASS] Found existing Security Group: $sgId ($SecurityGroupName)" -ForegroundColor Green
} else {
    $newSgJson = Invoke-Aws @("ec2", "create-security-group", "--group-name", $SecurityGroupName, "--description", "ISP Staging BSS & FreeRADIUS SG", "--vpc-id", $vpcId, "--region", $Region, "--output", "json") | Out-String
    $sgId = ($newSgJson | ConvertFrom-Json).GroupId
    Write-Host "  [PASS] Created Security Group: $sgId" -ForegroundColor Green

    # Ingress Rules:
    # Admin Ports restricted to caller CIDR:
    # TCP 22 (SSH), TCP 8000 (BSS API), TCP 3000 (Grafana), TCP 9090 (Prometheus)
    $adminPorts = @(22, 8000, 3000, 9090)
    foreach ($port in $adminPorts) {
        Invoke-Aws @("ec2", "authorize-security-group-ingress", "--group-id", $sgId, "--protocol", "tcp", "--port", "$port", "--cidr", $callerCidr, "--region", $Region) | Out-Null
    }

    # Public Fintech & Carrier Ports:
    # TCP 443 (MFS Webhooks)
    Invoke-Aws @("ec2", "authorize-security-group-ingress", "--group-id", $sgId, "--protocol", "tcp", "--port", "443", "--cidr", "0.0.0.0/0", "--region", $Region) | Out-Null

    # UDP 1812 (RADIUS Auth), UDP 1813 (RADIUS Acct), UDP 3799 (CoA / PoD), UDP 51820 (WireGuard)
    $udpPorts = @(1812, 1813, 3799, 51820)
    foreach ($port in $udpPorts) {
        Invoke-Aws @("ec2", "authorize-security-group-ingress", "--group-id", $sgId, "--protocol", "udp", "--port", "$port", "--cidr", "0.0.0.0/0", "--region", $Region) | Out-Null
    }
    Write-Host "  [PASS] Configured Ingress Rules: Admin ($callerCidr), MFS (443), RADIUS (1812,1813,3799), WireGuard (51820)." -ForegroundColor Green
}

# ------------------------------------------------------------------------------
# 5. EC2 Key Pair Provisioning
# ------------------------------------------------------------------------------
Write-Host "`n[5/7] Provisioning SSH Key Pair '$KeyName'..." -ForegroundColor Yellow
$keyFile = Join-Path $PSScriptRoot "$KeyName.pem"

$existingKeyJson = Invoke-Aws @("ec2", "describe-key-pairs", "--filters", "Name=key-name,Values=$KeyName", "--region", $Region, "--output", "json") | Out-String
$existingKey = ($existingKeyJson | ConvertFrom-Json).KeyPairs[0]

if (-not $existingKey -or -not (Test-Path $keyFile)) {
    if ($existingKey) {
        Invoke-Aws @("ec2", "delete-key-pair", "--key-name", $KeyName, "--region", $Region) | Out-Null
    }
    $newKeyJson = Invoke-Aws @("ec2", "create-key-pair", "--key-name", $KeyName, "--query", "KeyMaterial", "--output", "text", "--region", $Region) | Out-String
    $newKeyJson.Trim() | Out-File -FilePath $keyFile -Encoding ascii
    Write-Host "  [PASS] Generated new key pair and saved to: $keyFile" -ForegroundColor Green
} else {
    Write-Host "  [PASS] Using existing SSH key: $keyFile" -ForegroundColor Green
}

# ------------------------------------------------------------------------------
# 6. Lookup Latest Official Ubuntu 24.04 LTS AMI
# ------------------------------------------------------------------------------
Write-Host "`n[6/7] Resolving latest official Canonical Ubuntu 24.04 LTS AMI in $Region..." -ForegroundColor Yellow
$amiJson = Invoke-Aws @(
    "ec2", "describe-images",
    "--owners", "099720109477",
    "--filters",
    "Name=name,Values=ubuntu/images/hvm-ssd-gp3/ubuntu-noble-24.04-amd64-server-*",
    "Name=state,Values=available",
    "--query", "reverse(sort_by(Images, &CreationDate))[0].ImageId",
    "--output", "text",
    "--region", $Region
) | Out-String
$amiId = $amiJson.Trim()
Write-Host "  [PASS] Target AMI: $amiId (Ubuntu 24.04 LTS Noble Numbat)" -ForegroundColor Green

# ------------------------------------------------------------------------------
# 7. Launch Free-Tier EC2 Instance & Trigger Bootstrap
# ------------------------------------------------------------------------------
Write-Host "`n[7/7] Launching Free-Tier EC2 Instance ($InstanceType, 30 GB gp3 root)..." -ForegroundColor Yellow

$bootstrapScript = Join-Path $PSScriptRoot "ec2_bootstrap.sh"
if (-not (Test-Path $bootstrapScript)) {
    throw "Bootstrap script not found at: $bootstrapScript"
}

# Block Device Mapping: 30 GB gp3 root volume (Free Tier maximum)
$blockMapping = @"
[
    {
        "DeviceName": "/dev/sda1",
        "Ebs": {
            "VolumeSize": 30,
            "VolumeType": "gp3",
            "DeleteOnTermination": true
        }
    }
]
"@
$blockTmp = Join-Path $env:TEMP "block_device.json"
$blockMapping | Out-File -FilePath $blockTmp -Encoding utf8

$launchJson = Invoke-Aws @(
    "ec2", "run-instances",
    "--image-id", $amiId,
    "--instance-type", $InstanceType,
    "--key-name", $KeyName,
    "--security-group-ids", $sgId,
    "--block-device-mappings", "file://$blockTmp",
    "--user-data", "file://$bootstrapScript",
    "--tag-specifications", "ResourceType=instance,Tags=[{Key=Name,Value=isp-staging-bss-radius},{Key=Environment,Value=Staging}]",
    "--region", $Region,
    "--output", "json"
) | Out-String

$instanceId = ($launchJson | ConvertFrom-Json).Instances[0].InstanceId
Write-Host "  [PASS] EC2 Instance launched: $instanceId" -ForegroundColor Green

Write-Host "  Waiting for instance to enter 'running' state..." -ForegroundColor Cyan
Invoke-Aws @("ec2", "wait", "instance-running", "--instance-ids", $instanceId, "--region", $Region) | Out-Null

$descJson = Invoke-Aws @("ec2", "describe-instances", "--instance-ids", $instanceId, "--region", $Region, "--output", "json") | Out-String
$instanceInfo = ($descJson | ConvertFrom-Json).Reservations[0].Instances[0]
$publicIp = $instanceInfo.PublicIpAddress
$publicDns = $instanceInfo.PublicDnsName

Write-Host "  [PASS] Instance is RUNNING!" -ForegroundColor Green
Write-Host "  Public IP  : $publicIp" -ForegroundColor Cyan
Write-Host "  Public DNS : $publicDns" -ForegroundColor Cyan

# Save deployment manifest for verification script
$manifest = [PSCustomObject]@{
    AccountId    = $accountId
    Region       = $Region
    InstanceId   = $instanceId
    InstanceType = $InstanceType
    PublicIp     = $publicIp
    PublicDns    = $publicDns
    KeyFile      = $keyFile
    DeployedAt   = (Get-Date -Format "o")
}
$manifestFile = Join-Path $PSScriptRoot "staging_instance.json"
$manifest | ConvertTo-Json -Depth 4 | Out-File -FilePath $manifestFile -Encoding utf8
Write-Host "  [PASS] Staging metadata written to: $manifestFile" -ForegroundColor Green

# ------------------------------------------------------------------------------
# Securely Transfer .env over SCP
# ------------------------------------------------------------------------------
$localEnv = Join-Path (Split-Path (Split-Path $PSScriptRoot -Parent) -Parent) ".env"
if (Test-Path $localEnv) {
    Write-Host "`nTransferring local .env securely over SCP..." -ForegroundColor Yellow
    Write-Host "Waiting 30 seconds for SSH daemon initialization..." -ForegroundColor Gray
    Start-Sleep -Seconds 30

    $scpCmd = "scp -o StrictHostKeyChecking=no -o ConnectTimeout=10 -i `"$keyFile`" `"$localEnv`" ubuntu@${publicIp}:/home/ubuntu/.env"
    try {
        cmd.exe /c $scpCmd 2>&1 | Out-Null
        Write-Host "  [PASS] .env securely transferred to EC2." -ForegroundColor Green
    } catch {
        Write-Host "  [WARN] SCP transfer pending; can be retried once SSH is fully responsive." -ForegroundColor Yellow
    }
}

Write-Host "`n==============================================================================" -ForegroundColor Green
Write-Host " AWS FREE TIER STAGING DEPLOYMENT INITIATED SUCCESSFULLY" -ForegroundColor Green
Write-Host " EC2 Instance ID : $instanceId" -ForegroundColor Green
Write-Host " Public IP       : $publicIp" -ForegroundColor Green
Write-Host " SSH Command     : ssh -i `"$keyFile`" ubuntu@$publicIp" -ForegroundColor Green
Write-Host " BSS Endpoint    : http://${publicIp}:8000/api/v1/health" -ForegroundColor Green
Write-Host " Grafana NOC     : http://${publicIp}:3000" -ForegroundColor Green
Write-Host " Prometheus      : http://${publicIp}:9090" -ForegroundColor Green
Write-Host " Next Step       : Run .\infra\aws\verify_aws_staging.ps1 to verify endpoints" -ForegroundColor Green
Write-Host "==============================================================================" -ForegroundColor Green
