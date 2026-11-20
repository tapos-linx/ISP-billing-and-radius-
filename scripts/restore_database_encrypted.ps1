<#
.SYNOPSIS
  PowerShell Automated Decryption & Restoration of Database Backup
#>
param(
    [string]$RepoRoot = (Split-Path -Parent $PSScriptRoot),
    [string]$DbContainer = "citus_master",
    [string]$DbName = "isp_bss_oss",
    [string]$DbUser = "isp_admin",
    [string]$BackupFile = ""
)

if (-not $BackupFile) {
    $candidates = Get-ChildItem -Path (Join-Path $RepoRoot "backups") -Filter "*.tar.gz.enc" | Sort-Object LastWriteTime -Descending
    if ($candidates) {
        $BackupFile = $candidates[0].FullName
    }
}

if (-not (Test-Path $BackupFile)) {
    Write-Host "[ERROR] Backup file not found: $BackupFile" -ForegroundColor Red
    exit 1
}

$checksumFile = "$BackupFile.sha256"
if (Test-Path $checksumFile) {
    $expected = (Get-Content $checksumFile).Trim().ToLower()
    $actual = (Get-FileHash -Path $BackupFile -Algorithm SHA256).Hash.ToLower()
    if ($expected -ne $actual) {
        Write-Host "[FATAL] Checksum verification failed!" -ForegroundColor Red
        exit 1
    }
    Write-Host "[PASS] Checksum verified: $actual" -ForegroundColor Green
}

$tmpRestore = Join-Path $RepoRoot "backups\restore_tmp"
New-Item -ItemType Directory -Path $tmpRestore -Force | Out-Null

$backupBasename = Split-Path -Leaf $BackupFile
wsl bash -c "openssl enc -d -aes-256-cbc -pbkdf2 -in /mnt/c/Users/DESKTOP-SHIPU-W2/projects/ISP-billing-and-radius/backups/$backupBasename -out /mnt/c/Users/DESKTOP-SHIPU-W2/projects/ISP-billing-and-radius/backups/restore_tmp/decrypted.tar.gz -pass file:/mnt/c/Users/DESKTOP-SHIPU-W2/projects/ISP-billing-and-radius/secrets/backup_key.txt && tar -xzf /mnt/c/Users/DESKTOP-SHIPU-W2/projects/ISP-billing-and-radius/backups/restore_tmp/decrypted.tar.gz -C /mnt/c/Users/DESKTOP-SHIPU-W2/projects/ISP-billing-and-radius/backups/restore_tmp"

$sqlFile = Get-ChildItem -Path $tmpRestore -Filter "*.sql" | Select-Object -First 1
if ($sqlFile) {
    Write-Host "Restoring database from $($sqlFile.FullName)..." -ForegroundColor Cyan
    Get-Content $sqlFile.FullName | docker exec -i $DbContainer psql -U $DbUser -d $DbName
    Write-Host "[PASS] Database restored successfully." -ForegroundColor Green
}

Remove-Item -Path $tmpRestore -Recurse -Force -ErrorAction SilentlyContinue
