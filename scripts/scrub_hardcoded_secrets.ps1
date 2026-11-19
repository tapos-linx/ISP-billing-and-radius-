<#
.SYNOPSIS
  Scans README.md, INSTALL_*.md, stage1/, stage2/, and config files for hardcoded secrets.
  Replaces common hardcoded passwords/keys with ${ENV_VAR} placeholders (never touches .env).
#>
param(
    [string]$RepoRoot = "."
)

$patterns = @(
    @{ Name = "RadiusSecretKey2026"; Regex = "RadiusSecretKey2026"; Replacement = '${RADIUS_SECRET}' },
    @{ Name = "secure_radius_secret_key_2026"; Regex = "secure_radius_secret_key_2026"; Replacement = '${RADIUS_SECRET}' },
    @{ Name = "secure_isp_password_2026"; Regex = "secure_isp_password_2026"; Replacement = '${DB_PASSWORD}' },
    @{ Name = "secure_rabbit_pass"; Regex = "secure_rabbit_pass"; Replacement = '${RABBITMQ_PASSWORD}' },
    @{ Name = "GeminiAPIKey"; Regex = "AIza[0-9A-Za-z\-_]{35}"; Replacement = '${GEMINI_API_KEY}' }
)

$targets = Get-ChildItem -Path $RepoRoot -Recurse -File -Include *.md,*.yml,*.yaml,*.conf,*.php,*.sh,*.rsc |
    Where-Object { $_.FullName -notmatch "\\\.git\\" -and $_.Name -ne ".env" }

$hits = 0
foreach ($file in $targets) {
    $content = Get-Content $file.FullName -Raw
    $updated = $content
    foreach ($p in $patterns) {
        if ($updated -match $p.Regex) {
            Write-Host "[MATCH] $($p.Name) in $($file.FullName)" -ForegroundColor Yellow
            $updated = $updated -replace $p.Regex, $p.Replacement
            $hits++
        }
    }
    if ($updated -ne $content) {
        Set-Content -Path $file.FullName -Value $updated -Encoding UTF8
        Write-Host "[FIXED] Scrubbed $($file.FullName)" -ForegroundColor Green
    }
}

Write-Host "`nAudit complete. Total secret patterns replaced: $hits" -ForegroundColor Cyan
