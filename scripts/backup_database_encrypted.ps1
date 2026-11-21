<#
.SYNOPSIS
  PowerShell Automated Encrypted Database Backup & MinIO/S3 Upload
#>
param(
    [string]$RepoRoot = (Split-Path -Parent $PSScriptRoot),
    [string]$DbContainer = "citus_master",
    [string]$DbName = "isp_bss_oss",
    [string]$DbUser = "isp_admin",
    [string]$S3Bucket = "isp-encrypted-backups",
    [string]$S3Endpoint = "http://127.0.0.1:9000"
)

function ConvertTo-WslPath([string]$winPath) {
    if ($winPath -match '^([a-zA-Z]):\\(.*)$') {
        $drive = $matches[1].ToLower()
        $rest = $matches[2] -replace '\\', '/'
        return "/mnt/$drive/$rest"
    }
    return $winPath -replace '\\', '/'
}

$timestamp = Get-Date -Format "yyyyMMdd_HHmmss"
$backupDir = Join-Path $RepoRoot "backups"
$tmpStage = Join-Path $backupDir "tmp_$timestamp"
New-Item -ItemType Directory -Path $tmpStage -Force | Out-Null

$keyFile = Join-Path $RepoRoot "secrets\backup_key.txt"
if (!(Test-Path $keyFile)) {
    $bytes = New-Object byte[] 32
    [System.Security.Cryptography.RandomNumberGenerator]::Create().GetBytes($bytes)
    $keyVal = [Convert]::ToBase64String($bytes)
    [System.IO.File]::WriteAllText($keyFile, $keyVal.Trim())
}

Write-Host "==> Starting Encrypted Database Backup ($timestamp)..." -ForegroundColor Cyan

# 1. Dump database
$dumpSql = Join-Path $tmpStage "database_${DbName}_${timestamp}.sql"
$running = docker ps --format "{{.Names}}"
if ($running -contains $DbContainer) {
    docker exec $DbContainer pg_dump -U $DbUser -d $DbName --clean --if-exists | Out-File -FilePath $dumpSql -Encoding utf8
} else {
    docker exec isp_postgres pg_dump -U radius_user -d isp_billing --clean --if-exists | Out-File -FilePath $dumpSql -Encoding utf8
}

# 2. Compress & Encrypt with OpenSSL (via WSL or native)
$tarFile = Join-Path $tmpStage "backup_${timestamp}.tar.gz"
$encFile = Join-Path $backupDir "backup_${timestamp}.tar.gz.enc"
$checksumFile = "$encFile.sha256"

$wslTmpStage = ConvertTo-WslPath $tmpStage
$wslBackupDir = ConvertTo-WslPath $backupDir
$wslKeyFile = ConvertTo-WslPath $keyFile

wsl bash -c "tar -czf $wslTmpStage/backup_$timestamp.tar.gz -C $wslTmpStage database_${DbName}_${timestamp}.sql && openssl enc -aes-256-cbc -salt -pbkdf2 -in $wslTmpStage/backup_$timestamp.tar.gz -out $wslBackupDir/backup_$timestamp.tar.gz.enc -pass file:$wslKeyFile"

if (Test-Path $encFile) {
    $hash = (Get-FileHash -Path $encFile -Algorithm SHA256).Hash.ToLower()
    Set-Content -Path $checksumFile -Value $hash
    Write-Host "[SUCCESS] Backup encrypted: $encFile" -ForegroundColor Green
    Write-Host "SHA256: $hash" -ForegroundColor DarkCyan
}

Remove-Item -Path $tmpStage -Recurse -Force -ErrorAction SilentlyContinue
