param(
    [string]$ZipExportPath = ""
)

$RepoDir    = "C:\Users\DESKTOP-SHIPU-W2\projects\ISP-billing-and-radius"
$TargetRepo = "https://github.com/tapos-linx/ISP-billing-and-radius.git"
$CommitMsg  = "Update from Google AI Studio ($(Get-Date -Format 'yyyy-MM-dd HH:mm'))"

Set-Location -Path $RepoDir
git remote set-url origin $TargetRepo

if ($ZipExportPath -ne "") {
    if (-not (Test-Path $ZipExportPath)) {
        Write-Error "Zip file not found: $ZipExportPath"
        exit 1
    }
    Expand-Archive -Path $ZipExportPath -DestinationPath $RepoDir -Force
}

git fetch --all
git add .

$hasChanges = git diff --cached --quiet; $LASTEXITCODE -ne 0
if ($hasChanges) {
    git commit -m $CommitMsg
} else {
    Write-Host "No new file changes to commit."
}

git push -u origin main
git status -sb
