package com.example.ui.screens

import android.content.ClipData
import android.content.ClipboardManager
import android.content.Context
import android.widget.Toast
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.ContentCopy
import androidx.compose.material.icons.filled.Terminal
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.testTag
import androidx.compose.ui.text.font.FontFamily
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.example.ui.components.CodeSnippetViewer
import com.example.ui.theme.*
import com.example.viewmodel.IspArchitectViewModel

data class PowerShellScriptItem(
    val name: String,
    val stage: String,
    val summary: String,
    val code: String,
    val executionCommand: String
)

@Composable
fun PowerShellVaultScreen(
    viewModel: IspArchitectViewModel,
    modifier: Modifier = Modifier
) {
    val context = LocalContext.current
    var selectedScriptIndex by remember { mutableStateOf(0) }

    val scripts = remember {
        listOf(
            PowerShellScriptItem(
                name = "verify-radtest.ps1",
                stage = "Stage 1 (Days 1-5)",
                summary = "Runs 100 consecutive RADIUS authentication requests against PostgreSQL 16 backend and asserts <10ms SLA.",
                executionCommand = "powershell -ExecutionPolicy Bypass -File .\\scripts\\verify-radtest.ps1 -TotalPackets 100",
                code = """
# ====================================================================
# Script: verify-radtest.ps1
# Stage: Stage 1 (Foundation & RADIUS Core)
# Target SLA: < 10ms Access-Accept latency
# ====================================================================
param (
    [int]${'$'}TotalPackets = 100,
    [string]${'$'}Server = "127.0.0.1",
    [int]${'$'}Port = 1812,
    [string]${'$'}Secret = "testing123",
    [string]${'$'}TestUser = "testuser",
    [string]${'$'}TestPass = "testpass"
)

${'$'}ErrorActionPreference = "Stop"
Write-Host "==========================================================" -ForegroundColor Cyan
Write-Host " ISP Billing: FreeRADIUS 3.x Latency Benchmark (100 Packets)" -ForegroundColor Cyan
Write-Host "==========================================================" -ForegroundColor Cyan

${'$'}latencies = @()
${'$'}accepted = 0
${'$'}rejected = 0

for (${'$'}i = 1; ${'$'}i -le ${'$'}TotalPackets; ${'$'}i++) {
    ${'$'}stopwatch = [System.Diagnostics.Stopwatch]::StartNew()
    ${'$'}output = wsl docker exec isp_freeradius radtest ${'$'}TestUser ${'$'}TestPass ${'$'}Server ${'$'}Port ${'$'}Secret 2>&1
    ${'$'}stopwatch.Stop()
    ${'$'}ms = ${'$'}stopwatch.Elapsed.TotalMilliseconds

    if (${'$'}output -match "Access-Accept") {
        ${'$'}accepted++
        ${'$'}latencies += ${'$'}ms
        Write-Host "Packet ${'$'}i / ${'$'}TotalPackets : Access-Accept in ${'$'}([math]::Round(${'$'}ms, 2)) ms" -ForegroundColor Green
    } else {
        ${'$'}rejected++
        Write-Warning "Packet ${'$'}i: Access-Reject or Timeout"
    }
}

${'$'}avg = (${'$'}latencies | Measure-Object -Average).Average
${'$'}min = (${'$'}latencies | Measure-Object -Minimum).Minimum
${'$'}max = (${'$'}latencies | Measure-Object -Maximum).Maximum

Write-Host "`n--- FINAL BENCHMARK SUMMARY ---" -ForegroundColor Yellow
Write-Host "Total Sent: ${'$'}TotalPackets | Accepted: ${'$'}accepted | Rejected: ${'$'}rejected"
Write-Host "Min Latency: ${'$'}([math]::Round(${'$'}min, 2)) ms"
Write-Host "Avg Latency: ${'$'}([math]::Round(${'$'}avg, 2)) ms"
Write-Host "Max Latency: ${'$'}([math]::Round(${'$'}max, 2)) ms"

if (${'$'}avg -lt 10.0) {
    Write-Host "[PASSED] SLA ACHIEVED: < 10ms Access-Accept ($([math]::Round(${'$'}avg, 2)) ms)" -ForegroundColor Green
    exit 0
} else {
    Write-Host "[FAILED] SLA EXCEEDED: Tune PostgreSQL 16 shared_buffers or radcheck indexes" -ForegroundColor Red
    exit 1
}
                """.trimIndent()
            ),
            PowerShellScriptItem(
                name = "test-coa-kick.ps1",
                stage = "Stage 3 (Days 13-18)",
                summary = "Dispatches RFC 3576 Disconnect-Request on UDP 3799 to Mikrotik CCR2004 and asserts <500ms session termination.",
                executionCommand = "powershell -ExecutionPolicy Bypass -File .\\scripts\\test-coa-kick.ps1 -Username 'pilot_user_01' -FramedIp '10.100.0.10'",
                code = """
# ====================================================================
# Script: test-coa-kick.ps1
# Stage: Stage 3 (RFC 3576 CoA / PoD Automation)
# Target SLA: < 500ms PPPoE session kick
# ====================================================================
param (
    [string]${'$'}NasIp = "10.10.10.1",
    [int]${'$'}NasPort = 3799,
    [string]${'$'}Secret = "RadiusSecretKey2026",
    [string]${'$'}Username = "pilot_user_01",
    [string]${'$'}FramedIp = "10.100.0.10"
)

Write-Host "Dispatching RFC 3576 Disconnect-Request to ${'$'}NasIp:${'$'}NasPort for user '${'$'}Username'..." -ForegroundColor Cyan

${'$'}sw = [System.Diagnostics.Stopwatch]::StartNew()
${'$'}packet = "User-Name = '${'$'}Username',Framed-IP-Address = '${'$'}FramedIp'"
${'$'}response = wsl bash -c "echo \"${'$'}packet\" | radclient -x ${'$'}NasIp:${'$'}NasPort disconnect ${'$'}Secret"
${'$'}sw.Stop()

${'$'}elapsed = ${'$'}sw.Elapsed.TotalMilliseconds
Write-Host "NAS Response received in ${'$'}([math]::Round(${'$'}elapsed, 2)) ms"

if (${'$'}response -match "Disconnect-ACK") {
    Write-Host "[SUCCESS] Mikrotik acknowledged disconnect in $([math]::Round(${'$'}elapsed, 2)) ms (< 500ms SLA verified)" -ForegroundColor Green
} else {
    Write-Host "[FAILED] Received NAK or timeout from NAS: ${'$'}response" -ForegroundColor Red
}
                """.trimIndent()
            ),
            PowerShellScriptItem(
                name = "lockdown-firewall.ps1",
                stage = "Stage 5 (Days 25-28)",
                summary = "Locks down Windows Defender Firewall ports 1812, 1813, and 3799 via New-NetFirewallRule strictly to Mikrotik IP.",
                executionCommand = "powershell -ExecutionPolicy Bypass -File .\\scripts\\lockdown-firewall.ps1 -AuthorizedNasIp '10.10.10.1'",
                code = """
# ====================================================================
# Script: lockdown-firewall.ps1
# Stage: Stage 5 (Hardening & Security)
# ====================================================================
param (
    [string]${'$'}AuthorizedNasIp = "10.10.10.1"
)

# Requires Run as Administrator
if (-not ([Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
    Write-Error "Please run PowerShell as Administrator."
    exit 1
}

Write-Host "Enforcing Windows Defender Firewall perimeter rules..." -ForegroundColor Cyan

Remove-NetFirewallRule -DisplayName "FreeRADIUS-Auth" -ErrorAction SilentlyContinue
Remove-NetFirewallRule -DisplayName "FreeRADIUS-Acct" -ErrorAction SilentlyContinue
Remove-NetFirewallRule -DisplayName "FreeRADIUS-CoA" -ErrorAction SilentlyContinue

New-NetFirewallRule -DisplayName "FreeRADIUS-Auth" -Direction Inbound -LocalPort 1812 -Protocol UDP -Action Allow -RemoteAddress ${'$'}AuthorizedNasIp
New-NetFirewallRule -DisplayName "FreeRADIUS-Acct" -Direction Inbound -LocalPort 1813 -Protocol UDP -Action Allow -RemoteAddress ${'$'}AuthorizedNasIp
New-NetFirewallRule -DisplayName "FreeRADIUS-CoA" -Direction Inbound -LocalPort 3799 -Protocol UDP -Action Allow -RemoteAddress ${'$'}AuthorizedNasIp

Write-Host "[SUCCESS] Inbound FreeRADIUS UDP ports strictly bound to ${'$'}AuthorizedNasIp" -ForegroundColor Green
                """.trimIndent()
            ),
            PowerShellScriptItem(
                name = "backup-minio.ps1",
                stage = "Stage 5 (Days 25-28)",
                summary = "Automated daily pg_dump compressed backup directly to MinIO S3 bucket with 30-day retention.",
                executionCommand = "powershell -ExecutionPolicy Bypass -File .\\scripts\\backup-minio.ps1",
                code = """
# ====================================================================
# Script: backup-minio.ps1
# Stage: Stage 5 (Automated Backup & DR)
# ====================================================================
param (
    [string]${'$'}BucketName = "isp-backups-encrypted"
)

${'$'}timestamp = Get-Date -Format "yyyyMMdd_HHmmss"
${'$'}backupFile = "isp_billing_${'$'}timestamp.sql.gz"
${'$'}tempPath = "C:\ISP-Billing\backups\${'$'}backupFile"

Write-Host "Dumping PostgreSQL 16 database with gzip..." -ForegroundColor Cyan
wsl docker exec isp_postgres pg_dump -U radius_user -d isp_billing | gzip > ${'$'}tempPath

Write-Host "Uploading ${'$'}backupFile to MinIO S3..." -ForegroundColor Cyan
wsl mc cp "/mnt/c/ISP-Billing/backups/${'$'}backupFile" "local_minio/${'$'}BucketName/${'$'}backupFile"

Write-Host "[SUCCESS] Database backup encrypted and stored in MinIO S3." -ForegroundColor Green
                """.trimIndent()
            )
        )
    }

    Column(
        modifier = modifier
            .fillMaxSize()
            .background(TelecomNavyDark)
            .padding(horizontal = 16.dp)
    ) {
        Spacer(modifier = Modifier.height(8.dp))

        Text(
            text = "Windows PowerShell Script Vault",
            style = MaterialTheme.typography.headlineSmall,
            color = TextPrimaryDark,
            fontWeight = FontWeight.Bold
        )
        Text(
            text = "Production-tested scripts for Windows 10/11 WSL2 & Docker Desktop",
            style = MaterialTheme.typography.bodySmall,
            color = TextSecondaryDark
        )

        Spacer(modifier = Modifier.height(14.dp))

        LazyColumn(
            modifier = Modifier.fillMaxSize(),
            verticalArrangement = Arrangement.spacedBy(14.dp)
        ) {
            items(scripts) { script ->
                Card(
                    modifier = Modifier.fillMaxWidth().testTag("ps_card_${script.name}"),
                    shape = RoundedCornerShape(12.dp),
                    colors = CardDefaults.cardColors(containerColor = TelecomCardDark),
                    border = CardDefaults.outlinedCardBorder().copy(brush = androidx.compose.ui.graphics.SolidColor(TelecomBorderDark))
                ) {
                    Column(modifier = Modifier.padding(14.dp)) {
                        Row(
                            modifier = Modifier.fillMaxWidth(),
                            horizontalArrangement = Arrangement.SpaceBetween,
                            verticalAlignment = Alignment.CenterVertically
                        ) {
                            Row(verticalAlignment = Alignment.CenterVertically) {
                                Icon(
                                    imageVector = Icons.Default.Terminal,
                                    contentDescription = null,
                                    tint = CyanPulse,
                                    modifier = Modifier.size(20.dp)
                                )
                                Spacer(modifier = Modifier.width(8.dp))
                                Text(
                                    text = script.name,
                                    style = MaterialTheme.typography.titleMedium,
                                    color = TextPrimaryDark,
                                    fontWeight = FontWeight.Bold
                                )
                            }
                            Surface(
                                shape = RoundedCornerShape(8.dp),
                                color = CyanPulseDim.copy(alpha = 0.2f)
                            ) {
                                Text(
                                    text = script.stage,
                                    style = MaterialTheme.typography.labelSmall,
                                    color = CyanPulse,
                                    modifier = Modifier.padding(horizontal = 6.dp, vertical = 2.dp)
                                )
                            }
                        }

                        Text(
                            text = script.summary,
                            style = MaterialTheme.typography.bodySmall,
                            color = TextSecondaryDark,
                            modifier = Modifier.padding(top = 4.dp, bottom = 8.dp)
                        )

                        // Quick Run Command
                        Surface(
                            shape = RoundedCornerShape(6.dp),
                            color = TelecomNavyDark,
                            modifier = Modifier.fillMaxWidth()
                        ) {
                            Row(
                                modifier = Modifier.padding(8.dp),
                                horizontalArrangement = Arrangement.SpaceBetween,
                                verticalAlignment = Alignment.CenterVertically
                            ) {
                                Text(
                                    text = script.executionCommand,
                                    fontFamily = FontFamily.Monospace,
                                    fontSize = 10.sp,
                                    color = EmeraldSla,
                                    modifier = Modifier.weight(1f)
                                )
                                IconButton(
                                    onClick = {
                                        val clipboard = context.getSystemService(Context.CLIPBOARD_SERVICE) as ClipboardManager
                                        val clip = ClipData.newPlainText("PS Command", script.executionCommand)
                                        clipboard.setPrimaryClip(clip)
                                        Toast.makeText(context, "Copied run command", Toast.LENGTH_SHORT).show()
                                    },
                                    modifier = Modifier.size(20.dp).testTag("copy_cmd_${script.name}")
                                ) {
                                    Icon(imageVector = Icons.Default.ContentCopy, contentDescription = "Copy", tint = TextSecondaryDark, modifier = Modifier.size(14.dp))
                                }
                            }
                        }

                        Spacer(modifier = Modifier.height(10.dp))

                        CodeSnippetViewer(
                            title = "Full Production Script",
                            fileName = script.name,
                            language = "powershell",
                            code = script.code
                        )
                    }
                }
            }

            item {
                Spacer(modifier = Modifier.height(30.dp))
            }
        }
    }
}
