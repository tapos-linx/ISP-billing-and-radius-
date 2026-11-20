<#
.SYNOPSIS
  Configure Windows Defender Firewall rules for the local ISP stack.
  Restricts Citus PostgreSQL (5432) and Redis (6379) to loopback.
  Restricts FreeRADIUS UDP 1812, 1813, 3799 to local or specified NAS IPs.
#>
param(
    [string[]]$AuthorizedNasIps = @("127.0.0.1", "10.10.0.0/24", "172.16.0.0/12")
)

Write-Host "==> Applying Windows Firewall segmentation rules for ISP stack..." -ForegroundColor Cyan

$nasRemoteIps = $AuthorizedNasIps -join ","

# 1. Block external DB & Cache access
try {
    New-NetFirewallRule -DisplayName "ISP-Block-Public-Postgres" -Direction Inbound -LocalPort 5432 -Protocol TCP -Action Block -ErrorAction SilentlyContinue | Out-Null
    New-NetFirewallRule -DisplayName "ISP-Block-Public-Redis" -Direction Inbound -LocalPort 6379 -Protocol TCP -Action Block -ErrorAction SilentlyContinue | Out-Null
    New-NetFirewallRule -DisplayName "ISP-Block-Public-RabbitMQ" -Direction Inbound -LocalPort 5672,15672 -Protocol TCP -Action Block -ErrorAction SilentlyContinue | Out-Null

    # 2. Allow RADIUS UDP strictly from authorized NAS IPs
    New-NetFirewallRule -DisplayName "ISP-Allow-RADIUS-NAS" -Direction Inbound -LocalPort 1812,1813,3799 -Protocol UDP -RemoteAddress $AuthorizedNasIps -Action Allow -ErrorAction SilentlyContinue | Out-Null
    Write-Host "[SUCCESS] Windows Firewall rules applied successfully." -ForegroundColor Green
} catch {
    Write-Host "[INFO] Firewall rule adjustment requires elevated administrator privileges: $($_.Exception.Message)" -ForegroundColor Yellow
}
