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

function ConvertTo-WslPath([string]$winPath) {
    if ($winPath -match '^([a-zA-Z]):\\(.*)$') {
        $drive = $matches[1].ToLower()
        $rest = $matches[2] -replace '\\', '/'
        return "/mnt/$drive/$rest"
    }
    return $winPath -replace '\\', '/'
}

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
$wslBackupFile = ConvertTo-WslPath $BackupFile
$wslTmpRestore = ConvertTo-WslPath $tmpRestore
$wslKeyFile = ConvertTo-WslPath (Join-Path $RepoRoot "secrets\backup_key.txt")

wsl bash -c "openssl enc -d -aes-256-cbc -pbkdf2 -in $wslBackupFile -out $wslTmpRestore/decrypted.tar.gz -pass file:$wslKeyFile && tar -xzf $wslTmpRestore/decrypted.tar.gz -C $wslTmpRestore"

$sqlFile = Get-ChildItem -Path $tmpRestore -Filter "*.sql" | Select-Object -First 1
if ($sqlFile) {
    Write-Host "Restoring database from $($sqlFile.FullName)..." -ForegroundColor Cyan
    Get-Content $sqlFile.FullName | docker exec -i $DbContainer psql -U $DbUser -d $DbName
    
    # Ensure Citus worker cluster registration remains active
    docker exec $DbContainer psql -U $DbUser -d $DbName -c "SELECT citus_set_coordinator_host('citus_master', 5432); SELECT citus_add_node('citus_worker_1', 5432) WHERE NOT EXISTS (SELECT 1 FROM pg_dist_node WHERE nodename = 'citus_worker_1'); SELECT citus_add_node('citus_worker_2', 5432) WHERE NOT EXISTS (SELECT 1 FROM pg_dist_node WHERE nodename = 'citus_worker_2');" | Out-Null
    
    Write-Host "[PASS] Database restored successfully." -ForegroundColor Green
}

Remove-Item -Path $tmpRestore -Recurse -Force -ErrorAction SilentlyContinue
