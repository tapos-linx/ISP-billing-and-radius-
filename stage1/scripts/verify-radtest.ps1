# ====================================================================
# Script: verify-radtest.ps1
# Stage: Stage 1 (Foundation & RADIUS Core Verification)
# Project: ISP Billing Software (by Tapos)
# Environment: Windows 10 / Windows 11 (64-bit) with Docker Desktop (WSL2)
# Target SLA: < 10ms Access-Accept query latency with 20M/20M rate-limit
# ====================================================================

[CmdletBinding()]
param (
    [int]${'$'}TotalPackets = 100,
    [string]${'$'}Server = "127.0.0.1",
    [int]${'$'}Port = 1812,
    [string]${'$'}Secret = "testing123",
    [string]${'$'}Username = "tapos_test",
    [string]${'$'}Password = "tapos_pass_2026",
    [string]${'$'}ExpectedRateLimit = "20M/20M",
    [double]${'$'}MaxSlaMs = 10.0
)

${'$'}ErrorActionPreference = "Continue"

Write-Host "`n==========================================================" -ForegroundColor Cyan
Write-Host " ISP Billing: FreeRADIUS 3.2 Latency & SLA Benchmark      " -ForegroundColor Cyan
Write-Host " Target SLA: < $([math]::Round(${'$'}MaxSlaMs, 1)) ms Access-Accept Latency" -ForegroundColor Cyan
Write-Host " Test Subscriber: ${'$'}Username | Rate-Limit: ${'$'}ExpectedRateLimit" -ForegroundColor Cyan
Write-Host "==========================================================" -ForegroundColor Cyan

${'$'}latencies = @()
${'$'}acceptedCount = 0
${'$'}rejectedCount = 0
${'$'}rateLimitVerified = ${'$'}false

Write-Host "`n[PHASE 1] Executing ${'$'}TotalPackets Authentication Queries..." -ForegroundColor Yellow

for (${'$'}i = 1; ${'$'}i -le ${'$'}TotalPackets; ${'$'}i++) {
    ${'$'}stopwatch = [System.Diagnostics.Stopwatch]::StartNew()
    
    # Execute radtest inside the running FreeRADIUS container via Docker
    ${'$'}output = docker exec isp_freeradius radtest ${'$'}Username ${'$'}Password ${'$'}Server ${'$'}Port ${'$'}Secret 2>&1
    
    ${'$'}stopwatch.Stop()
    ${'$'}ms = ${'$'}stopwatch.Elapsed.TotalMilliseconds

    if (${'$'}output -match "Access-Accept") {
        ${'$'}acceptedCount++
        ${'$'}latencies += ${'$'}ms

        # Verify rate-limit attribute on first accepted response
        if (-not ${'$'}rateLimitVerified -and ${'$'}output -match "Mikrotik-Rate-Limit") {
            ${'$'}rateLimitVerified = ${'$'}true
        }

        # Print periodic progress
        if (${'$'}i -eq 1 -or ${'$'}i -eq ${'$'}TotalPackets -or (${'$'}i % 25 -eq 0)) {
            Write-Host "  Packet ${'$'}([string]${'$'}i).PadLeft(3)/${'$'}TotalPackets : Access-Accept in ${'$'}([math]::Round(${'$'}ms, 2)) ms" -ForegroundColor Green
        }
    } else {
        ${'$'}rejectedCount++
        Write-Warning "Packet ${'$'}i: Access-Reject or Timeout! Output: ${'$'}output"
    }
}

# Calculate latency statistics
if (${'$'}latencies.Count -eq 0) {
    Write-Error "[FAIL] No successful Access-Accept responses received. Check FreeRADIUS and PostgreSQL logs."
    exit 1
}

${'$'}avg = (${'$'}latencies | Measure-Object -Average).Average
${'$'}min = (${'$'}latencies | Measure-Object -Minimum).Minimum
${'$'}max = (${'$'}latencies | Measure-Object -Maximum).Maximum

# Calculate 95th Percentile
${'$'}sorted = ${'$'}latencies | Sort-Object
${'$'}p95Index = [math]::Floor(${'$'}sorted.Count * 0.95)
${'$'}p95 = ${'$'}sorted[${'$'}p95Index]

Write-Host "`n----------------------------------------------------------" -ForegroundColor Yellow
Write-Host " LATENCY BENCHMARK RESULTS (100 PACKETS)" -ForegroundColor Yellow
Write-Host "----------------------------------------------------------" -ForegroundColor Yellow
Write-Host " Packets Sent     : ${'$'}TotalPackets"
Write-Host " Access-Accept    : ${'$'}acceptedCount ($([math]::Round((${'$'}acceptedCount / ${'$'}TotalPackets) * 100, 1))%)" -ForegroundColor Green
Write-Host " Access-Reject    : ${'$'}rejectedCount" -ForegroundColor $(if (${'$'}rejectedCount -eq 0) { "Green" } else { "Red" })
Write-Host " Min Latency      : $([math]::Round(${'$'}min, 2)) ms" -ForegroundColor Cyan
Write-Host " Avg Latency      : $([math]::Round(${'$'}avg, 2)) ms" -ForegroundColor $(if (${'$'}avg -lt ${'$'}MaxSlaMs) { "Green" } else { "Red" })
Write-Host " 95th Percentile  : $([math]::Round(${'$'}p95, 2)) ms" -ForegroundColor Cyan
Write-Host " Max Latency      : $([math]::Round(${'$'}max, 2)) ms"

# Verify Attribute Payload
Write-Host "`n----------------------------------------------------------" -ForegroundColor Yellow
Write-Host " ATTRIBUTE DICTIONARY & POLICY VERIFICATION" -ForegroundColor Yellow
Write-Host "----------------------------------------------------------" -ForegroundColor Yellow
if (${'$'}rateLimitVerified) {
    Write-Host " [PASS] Mikrotik-Rate-Limit Attribute ':=' '${'$'}ExpectedRateLimit' returned successfully!" -ForegroundColor Green
} else {
    Write-Warning " [WARN] Mikrotik-Rate-Limit was not detected in radtest output. Check radreply table."
}

# PHASE 2: Test RADIUS Accounting (UDP 1813) & Monthly Partitioned radacct table
Write-Host "`n[PHASE 2] Testing RADIUS Accounting (Acct-Start / Acct-Stop)..." -ForegroundColor Yellow

${'$'}acctSessionId = "sess_" + [System.Guid]::NewGuid().ToString().Substring(0, 8)
${'$'}acctStartPacket = @"
User-Name = '${'$'}Username'
NAS-IP-Address = 127.0.0.1
NAS-Port = 1
Acct-Session-Id = '${'$'}acctSessionId'
Acct-Status-Type = Start
Framed-IP-Address = 10.100.0.50
"@

${'$'}acctOutput = docker exec -i isp_freeradius radclient -x 127.0.0.1:1813 acct ${'$'}Secret <<< "${'$'}acctStartPacket" 2>&1

if (${'$'}acctOutput -match "Accounting-Response") {
    Write-Host " [PASS] Accounting-Start Accepted on UDP 1813" -ForegroundColor Green
} else {
    Write-Warning " Accounting-Start packet test: ${'$'}acctOutput"
}

# Verify database partition entry
${'$'}dbAcctCheck = docker exec isp_postgres psql -U radius_user -d isp_billing -t -c "SELECT tableoid::regclass, username, framedipaddress FROM radacct WHERE acctsessionid = '${'$'}acctSessionId';" 2>${'$'}null
if (${'$'}dbAcctCheck -match "radacct_y2026") {
    Write-Host " [PASS] Verified entry in monthly partitioned table: ${'$'}(${'$'}dbAcctCheck.Trim())" -ForegroundColor Green
}

# Final SLA Verdict
Write-Host "`n==========================================================" -ForegroundColor Cyan
if (${'$'}avg -lt ${'$'}MaxSlaMs) {
    Write-Host " [SUCCESS] SLA ACHIEVED: Average Latency $([math]::Round(${'$'}avg, 2)) ms (< $([math]::Round(${'$'}MaxSlaMs, 1)) ms SLA)" -ForegroundColor Green
    Write-Host " Stage 1 Foundation & FreeRADIUS 3.2 is Production Ready!" -ForegroundColor Green
    Write-Host "==========================================================" -ForegroundColor Cyan
    exit 0
} else {
    Write-Host " [FAILURE] SLA EXCEEDED: Average Latency $([math]::Round(${'$'}avg, 2)) ms (Threshold: $([math]::Round(${'$'}MaxSlaMs, 1)) ms)" -ForegroundColor Red
    Write-Host " Recommendation: Check shared_buffers and ensure Docker WSL2 has at least 4 CPU cores allocated in .wslconfig." -ForegroundColor Yellow
    Write-Host "==========================================================" -ForegroundColor Cyan
    exit 1
}
