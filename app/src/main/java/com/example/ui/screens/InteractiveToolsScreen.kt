package com.example.ui.screens

import android.content.ClipData
import android.content.ClipboardManager
import android.content.Context
import android.widget.Toast
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.horizontalScroll
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.*
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.testTag
import androidx.compose.ui.text.font.FontFamily
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.example.ui.components.ConsoleLogViewer
import com.example.ui.components.LatencyGauge
import com.example.ui.theme.*
import com.example.viewmodel.IspArchitectViewModel

enum class ToolSubTab(val title: String) {
    COA_KICK("RFC 3576 CoA (<500ms)"),
    BKASH_WEBHOOK("bKash Reconnect (<3s)"),
    RADIUS_BENCHMARK("RADIUS Benchmark (<10ms)"),
    MIKROTIK_GEN("RouterOS v7 Builder")
}

@Composable
fun InteractiveToolsScreen(
    viewModel: IspArchitectViewModel,
    modifier: Modifier = Modifier
) {
    var activeSubTab by remember { mutableStateOf(ToolSubTab.COA_KICK) }
    val logs by viewModel.consoleLogs.collectAsState()
    val context = LocalContext.current

    Column(
        modifier = modifier
            .fillMaxSize()
            .background(TelecomNavyDark)
            .padding(horizontal = 16.dp)
    ) {
        Spacer(modifier = Modifier.height(8.dp))

        Text(
            text = "Telecom Test Lab & Automation Tools",
            style = MaterialTheme.typography.headlineSmall,
            color = TextPrimaryDark,
            fontWeight = FontWeight.Bold
        )
        Text(
            text = "Real-time RFC 3576 CoA, Payment Gateways & Mikrotik v7 Generators",
            style = MaterialTheme.typography.bodySmall,
            color = TextSecondaryDark
        )

        Spacer(modifier = Modifier.height(12.dp))

        // Tool Selector Tabs
        Row(
            modifier = Modifier
                .fillMaxWidth()
                .horizontalScroll(rememberScrollState()),
            horizontalArrangement = Arrangement.spacedBy(8.dp)
        ) {
            ToolSubTab.values().forEach { tab ->
                val isSelected = tab == activeSubTab
                Surface(
                    shape = RoundedCornerShape(18.dp),
                    color = if (isSelected) CyanPulse else TelecomCardDark,
                    modifier = Modifier
                        .clickable { activeSubTab = tab }
                        .testTag("tool_tab_${tab.name}")
                ) {
                    Text(
                        text = tab.title,
                        fontWeight = FontWeight.SemiBold,
                        fontSize = 12.sp,
                        color = if (isSelected) TelecomNavyDark else TextPrimaryDark,
                        modifier = Modifier.padding(horizontal = 12.dp, vertical = 8.dp)
                    )
                }
            }
        }

        Spacer(modifier = Modifier.height(14.dp))

        LazyColumn(
            modifier = Modifier.weight(1f),
            verticalArrangement = Arrangement.spacedBy(14.dp)
        ) {
            when (activeSubTab) {
                ToolSubTab.COA_KICK -> {
                    item { CoaKickSection(viewModel) }
                }
                ToolSubTab.BKASH_WEBHOOK -> {
                    item { BkashWebhookSection(viewModel) }
                }
                ToolSubTab.RADIUS_BENCHMARK -> {
                    item { RadiusBenchmarkSection(viewModel) }
                }
                ToolSubTab.MIKROTIK_GEN -> {
                    item { MikrotikGeneratorSection(viewModel) }
                }
            }

            // Real-time Event Console
            item {
                Text(
                    text = "Live Execution Logs",
                    style = MaterialTheme.typography.titleMedium,
                    color = TextPrimaryDark,
                    fontWeight = FontWeight.Bold
                )
                Spacer(modifier = Modifier.height(6.dp))
                ConsoleLogViewer(logs = logs)
            }

            item {
                Spacer(modifier = Modifier.height(20.dp))
            }
        }
    }
}

// ---------------------------------------------------------------------
// SUB-TAB 1: RFC 3576 CoA / PoD Disconnect Simulator (<500ms)
// ---------------------------------------------------------------------
@Composable
private fun CoaKickSection(viewModel: IspArchitectViewModel) {
    val coaResult by viewModel.coaResult.collectAsState()
    val isKicking by viewModel.isKicking.collectAsState()
    var targetUser by remember { mutableStateOf("mirpur_home_sub01") }
    var targetIp by remember { mutableStateOf("10.100.0.15") }

    Card(
        modifier = Modifier.fillMaxWidth(),
        shape = RoundedCornerShape(12.dp),
        colors = CardDefaults.cardColors(containerColor = TelecomSurfaceDark),
        border = CardDefaults.outlinedCardBorder().copy(brush = androidx.compose.ui.graphics.SolidColor(TelecomBorderDark))
    ) {
        Column(modifier = Modifier.padding(16.dp)) {
            Row(
                verticalAlignment = Alignment.CenterVertically,
                horizontalArrangement = Arrangement.SpaceBetween,
                modifier = Modifier.fillMaxWidth()
            ) {
                Text(
                    text = "RFC 3576 PoD Disconnect Tester",
                    style = MaterialTheme.typography.titleLarge,
                    color = TextPrimaryDark,
                    fontWeight = FontWeight.Bold
                )
                Surface(
                    shape = RoundedCornerShape(8.dp),
                    color = CoralDisconnect.copy(alpha = 0.2f)
                ) {
                    Text(
                        text = "UDP 3799",
                        color = CoralDisconnect,
                        style = MaterialTheme.typography.labelSmall,
                        modifier = Modifier.padding(horizontal = 6.dp, vertical = 2.dp)
                    )
                }
            }

            Text(
                text = "Kicks PPPoE session off Mikrotik CCR2004 via Disconnect-Request packet with MD5 authenticator.",
                style = MaterialTheme.typography.bodySmall,
                color = TextSecondaryDark,
                modifier = Modifier.padding(top = 4.dp, bottom = 12.dp)
            )

            Row(modifier = Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                OutlinedTextField(
                    value = targetUser,
                    onValueChange = { targetUser = it },
                    label = { Text("User-Name") },
                    modifier = Modifier.weight(1f).testTag("coa_username_input"),
                    singleLine = true
                )
                OutlinedTextField(
                    value = targetIp,
                    onValueChange = { targetIp = it },
                    label = { Text("Framed-IP") },
                    modifier = Modifier.weight(1f).testTag("coa_ip_input"),
                    singleLine = true
                )
            }

            Spacer(modifier = Modifier.height(12.dp))

            Button(
                onClick = { viewModel.triggerCoaKick(targetUser, targetIp) },
                enabled = !isKicking,
                modifier = Modifier
                    .fillMaxWidth()
                    .testTag("send_pod_button"),
                colors = ButtonDefaults.buttonColors(containerColor = CoralDisconnect)
            ) {
                if (isKicking) {
                    CircularProgressIndicator(color = Color.White, modifier = Modifier.size(20.dp))
                    Spacer(modifier = Modifier.width(8.dp))
                    Text("Dispatching RFC 3576 PoD...")
                } else {
                    Icon(imageVector = Icons.Default.FlashOn, contentDescription = null)
                    Spacer(modifier = Modifier.width(8.dp))
                    Text("Send Disconnect-Request (PoD Kick)")
                }
            }

            coaResult?.let { result ->
                Spacer(modifier = Modifier.height(16.dp))
                LatencyGauge(
                    title = "PPPoE Session Kick Latency",
                    currentValueMs = result.latencyMs.toDouble(),
                    slaThresholdMs = 500.0,
                    unit = "ms"
                )

                Spacer(modifier = Modifier.height(8.dp))
                Surface(
                    shape = RoundedCornerShape(8.dp),
                    color = TelecomNavyDark,
                    modifier = Modifier.fillMaxWidth()
                ) {
                    Column(modifier = Modifier.padding(10.dp)) {
                        Text(
                            text = "Authenticator: ${result.authenticatorHex}",
                            fontFamily = FontFamily.Monospace,
                            fontSize = 11.sp,
                            color = CyanPulse
                        )
                        Text(
                            text = "NAS Status: ${result.resultStatus}",
                            fontFamily = FontFamily.Monospace,
                            fontSize = 11.sp,
                            color = EmeraldSla
                        )
                    }
                }
            }
        }
    }
}

// ---------------------------------------------------------------------
// SUB-TAB 2: bKash / Nagad / SSLCOMMERZ Webhook Simulator (<3s)
// ---------------------------------------------------------------------
@Composable
private fun BkashWebhookSection(viewModel: IspArchitectViewModel) {
    val paymentResult by viewModel.paymentResult.collectAsState()
    val isProcessing by viewModel.isProcessingPayment.collectAsState()
    var gateway by remember { mutableStateOf("bKash") }
    var subscriberName by remember { mutableStateOf("mirpur_home_sub01") }
    var billAmount by remember { mutableStateOf("1000") }

    Card(
        modifier = Modifier.fillMaxWidth(),
        shape = RoundedCornerShape(12.dp),
        colors = CardDefaults.cardColors(containerColor = TelecomSurfaceDark),
        border = CardDefaults.outlinedCardBorder().copy(brush = androidx.compose.ui.graphics.SolidColor(TelecomBorderDark))
    ) {
        Column(modifier = Modifier.padding(16.dp)) {
            Text(
                text = "Payment Webhook & Auto-Reconnect Simulator",
                style = MaterialTheme.typography.titleLarge,
                color = TextPrimaryDark,
                fontWeight = FontWeight.Bold
            )
            Text(
                text = "Simulates HMAC-SHA256 signature verification, invoice settlement, radreply unblock, and <3s line re-dial.",
                style = MaterialTheme.typography.bodySmall,
                color = TextSecondaryDark,
                modifier = Modifier.padding(top = 4.dp, bottom = 12.dp)
            )

            // Gateway Selector Chips
            Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                listOf("bKash", "Nagad", "SSLCOMMERZ").forEach { gw ->
                    val isSelected = gw == gateway
                    FilterChip(
                        selected = isSelected,
                        onClick = { gateway = gw },
                        label = { Text(gw) },
                        colors = FilterChipDefaults.filterChipColors(
                            selectedContainerColor = AmberBilling,
                            selectedLabelColor = TelecomNavyDark
                        )
                    )
                }
            }

            Spacer(modifier = Modifier.height(10.dp))

            Row(modifier = Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                OutlinedTextField(
                    value = subscriberName,
                    onValueChange = { subscriberName = it },
                    label = { Text("Subscriber") },
                    modifier = Modifier.weight(1f).testTag("webhook_user_input"),
                    singleLine = true
                )
                OutlinedTextField(
                    value = billAmount,
                    onValueChange = { billAmount = it },
                    label = { Text("Amount (BDT)") },
                    modifier = Modifier.weight(1f).testTag("webhook_amount_input"),
                    singleLine = true
                )
            }

            Spacer(modifier = Modifier.height(12.dp))

            Button(
                onClick = {
                    viewModel.triggerPaymentWebhook(gateway, subscriberName, billAmount.toDoubleOrNull() ?: 1000.0)
                },
                enabled = !isProcessing,
                modifier = Modifier
                    .fillMaxWidth()
                    .testTag("simulate_payment_button"),
                colors = ButtonDefaults.buttonColors(containerColor = AmberBilling)
            ) {
                if (isProcessing) {
                    CircularProgressIndicator(color = TelecomNavyDark, modifier = Modifier.size(20.dp))
                    Spacer(modifier = Modifier.width(8.dp))
                    Text("Processing & Unblocking Line...", color = TelecomNavyDark)
                } else {
                    Icon(imageVector = Icons.Default.Payment, contentDescription = null, tint = TelecomNavyDark)
                    Spacer(modifier = Modifier.width(8.dp))
                    Text("Simulate $gateway Payment Callback", color = TelecomNavyDark, fontWeight = FontWeight.Bold)
                }
            }

            paymentResult?.let { result ->
                Spacer(modifier = Modifier.height(16.dp))
                LatencyGauge(
                    title = "End-to-End Reconnection Latency",
                    currentValueMs = (result.executionTimeMs / 1000.0),
                    slaThresholdMs = 3.0,
                    unit = "s"
                )

                Spacer(modifier = Modifier.height(8.dp))
                Surface(
                    shape = RoundedCornerShape(8.dp),
                    color = TelecomNavyDark,
                    modifier = Modifier.fillMaxWidth()
                ) {
                    Column(modifier = Modifier.padding(10.dp)) {
                        Text(
                            text = "TrxID: ${result.trxId} • HMAC: ${result.rawSignature}",
                            fontFamily = FontFamily.Monospace,
                            fontSize = 11.sp,
                            color = AmberBilling
                        )
                        Spacer(modifier = Modifier.height(4.dp))
                        Text(
                            text = result.logOutput,
                            fontFamily = FontFamily.Monospace,
                            fontSize = 10.sp,
                            lineHeight = 14.sp,
                            color = TextPrimaryDark
                        )
                    }
                }
            }
        }
    }
}

// ---------------------------------------------------------------------
// SUB-TAB 3: FreeRADIUS & Postgres Latency Benchmark (<10ms)
// ---------------------------------------------------------------------
@Composable
private fun RadiusBenchmarkSection(viewModel: IspArchitectViewModel) {
    val benchResult by viewModel.benchmarkResult.collectAsState()
    val isBenchmarking by viewModel.isBenchmarking.collectAsState()

    Card(
        modifier = Modifier.fillMaxWidth(),
        shape = RoundedCornerShape(12.dp),
        colors = CardDefaults.cardColors(containerColor = TelecomSurfaceDark),
        border = CardDefaults.outlinedCardBorder().copy(brush = androidx.compose.ui.graphics.SolidColor(TelecomBorderDark))
    ) {
        Column(modifier = Modifier.padding(16.dp)) {
            Text(
                text = "FreeRADIUS 3.x Latency Benchmark",
                style = MaterialTheme.typography.titleLarge,
                color = TextPrimaryDark,
                fontWeight = FontWeight.Bold
            )
            Text(
                text = "Executes 100 consecutive authentication packets against PostgreSQL 16 monthly partitioned radacct.",
                style = MaterialTheme.typography.bodySmall,
                color = TextSecondaryDark,
                modifier = Modifier.padding(top = 4.dp, bottom = 12.dp)
            )

            Button(
                onClick = { viewModel.runRadiusLatencyBenchmark(100) },
                enabled = !isBenchmarking,
                modifier = Modifier
                    .fillMaxWidth()
                    .testTag("run_benchmark_button"),
                colors = ButtonDefaults.buttonColors(containerColor = CyanPulse)
            ) {
                if (isBenchmarking) {
                    CircularProgressIndicator(color = TelecomNavyDark, modifier = Modifier.size(20.dp))
                    Spacer(modifier = Modifier.width(8.dp))
                    Text("Testing 100 Auth Packets...", color = TelecomNavyDark)
                } else {
                    Icon(imageVector = Icons.Default.Speed, contentDescription = null, tint = TelecomNavyDark)
                    Spacer(modifier = Modifier.width(8.dp))
                    Text("Run 100-Packet Latency Test", color = TelecomNavyDark, fontWeight = FontWeight.Bold)
                }
            }

            benchResult?.let { result ->
                Spacer(modifier = Modifier.height(16.dp))
                LatencyGauge(
                    title = "Average Access-Accept Latency",
                    currentValueMs = result.avgLatencyMs,
                    slaThresholdMs = 10.0,
                    unit = "ms"
                )

                Spacer(modifier = Modifier.height(12.dp))
                Row(
                    modifier = Modifier.fillMaxWidth(),
                    horizontalArrangement = Arrangement.SpaceBetween
                ) {
                    MetricChip("Min Latency", "${result.minLatencyMs} ms", EmeraldSla)
                    MetricChip("Avg Latency", "${result.avgLatencyMs} ms", CyanPulse)
                    MetricChip("Max Latency", "${result.maxLatencyMs} ms", AmberBilling)
                    MetricChip("Packets", "${result.totalPackets} (100%)", TextPrimaryDark)
                }
            }
        }
    }
}

@Composable
private fun MetricChip(label: String, value: String, color: Color) {
    Surface(
        shape = RoundedCornerShape(8.dp),
        color = TelecomNavyDark,
        modifier = Modifier.padding(horizontal = 2.dp)
    ) {
        Column(modifier = Modifier.padding(horizontal = 10.dp, vertical = 6.dp)) {
            Text(text = label, style = MaterialTheme.typography.labelSmall, color = TextTertiaryDark)
            Text(text = value, fontWeight = FontWeight.Bold, color = color, fontSize = 12.sp)
        }
    }
}

// ---------------------------------------------------------------------
// SUB-TAB 4: Mikrotik RouterOS v7 Script Builder
// ---------------------------------------------------------------------
@Composable
private fun MikrotikGeneratorSection(viewModel: IspArchitectViewModel) {
    val vlanId by viewModel.vlanIdInput.collectAsState()
    val ipPool by viewModel.ipPoolInput.collectAsState()
    val radiusIp by viewModel.radiusIpInput.collectAsState()
    val radiusSecret by viewModel.radiusSecretInput.collectAsState()
    val context = LocalContext.current

    val generatedScript = remember(vlanId, ipPool, radiusIp, radiusSecret) {
        """
# ====================================================================
# Generated RouterOS v7 Configuration (Tapos ISP Billing Automation)
# ====================================================================
/interface vlan add name=vlan$vlanId-customers vlan-id=$vlanId interface=sfp-sfpplus1 comment="Customer Access"
/ip pool add name=pool-subscribers ranges=$ipPool
/ppp profile add name=profile-radius local-address=10.100.0.1 remote-address=pool-subscribers dns-server=1.1.1.1,8.8.8.8 only-one=yes
/interface pppoe-server server add service-name=pppoe-in1 interface=vlan$vlanId-customers default-profile=profile-radius authentication=pap,chap,mschap2 disabled=no
/ppp aaa set use-radius=yes accounting=yes interim-update=5m
/radius add service=ppp address=$radiusIp secret="$radiusSecret" authentication-port=1812 accounting-port=1813 timeout=300ms
/radius incoming set accept=yes port=3799
/ip firewall address-list add list=PAYMENT_WHITELIST address=bkash.com
/ip firewall nat add chain=dstnat action=dst-nat to-addresses=172.20.0.5 to-ports=8080 protocol=tcp dst-port=80 src-address-list=EXPIRED_USERS
        """.trimIndent()
    }

    Card(
        modifier = Modifier.fillMaxWidth(),
        shape = RoundedCornerShape(12.dp),
        colors = CardDefaults.cardColors(containerColor = TelecomSurfaceDark),
        border = CardDefaults.outlinedCardBorder().copy(brush = androidx.compose.ui.graphics.SolidColor(TelecomBorderDark))
    ) {
        Column(modifier = Modifier.padding(16.dp)) {
            Text(
                text = "Mikrotik RouterOS v7 Script Generator",
                style = MaterialTheme.typography.titleLarge,
                color = TextPrimaryDark,
                fontWeight = FontWeight.Bold
            )
            Text(
                text = "Customize VLAN, IP pool, and FreeRADIUS IP to output copy-pasteable RouterOS v7 commands.",
                style = MaterialTheme.typography.bodySmall,
                color = TextSecondaryDark,
                modifier = Modifier.padding(top = 4.dp, bottom = 12.dp)
            )

            Row(modifier = Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                OutlinedTextField(
                    value = vlanId,
                    onValueChange = { viewModel.vlanIdInput.value = it },
                    label = { Text("VLAN ID") },
                    modifier = Modifier.weight(1f).testTag("input_vlan"),
                    singleLine = true
                )
                OutlinedTextField(
                    value = radiusIp,
                    onValueChange = { viewModel.radiusIpInput.value = it },
                    label = { Text("FreeRADIUS IP") },
                    modifier = Modifier.weight(1f).testTag("input_radius_ip"),
                    singleLine = true
                )
            }

            Spacer(modifier = Modifier.height(8.dp))

            OutlinedTextField(
                value = ipPool,
                onValueChange = { viewModel.ipPoolInput.value = it },
                label = { Text("PPPoE Subscriber Pool Range") },
                modifier = Modifier.fillMaxWidth().testTag("input_ip_pool"),
                singleLine = true
            )

            Spacer(modifier = Modifier.height(12.dp))

            Surface(
                shape = RoundedCornerShape(8.dp),
                color = TelecomNavyDark,
                modifier = Modifier.fillMaxWidth()
            ) {
                Column(modifier = Modifier.padding(10.dp)) {
                    Row(
                        modifier = Modifier.fillMaxWidth(),
                        horizontalArrangement = Arrangement.SpaceBetween,
                        verticalAlignment = Alignment.CenterVertically
                    ) {
                        Text(
                            text = "mikrotik_setup.rsc",
                            fontFamily = FontFamily.Monospace,
                            fontSize = 11.sp,
                            color = CyanPulse
                        )
                        IconButton(
                            onClick = {
                                val clipboard = context.getSystemService(Context.CLIPBOARD_SERVICE) as ClipboardManager
                                val clip = ClipData.newPlainText("RouterOS Script", generatedScript)
                                clipboard.setPrimaryClip(clip)
                                Toast.makeText(context, "Copied RouterOS script", Toast.LENGTH_SHORT).show()
                            },
                            modifier = Modifier.size(24.dp).testTag("copy_rsc_button")
                        ) {
                            Icon(imageVector = Icons.Default.ContentCopy, contentDescription = "Copy", tint = CyanPulse)
                        }
                    }
                    Spacer(modifier = Modifier.height(4.dp))
                    Text(
                        text = generatedScript,
                        fontFamily = FontFamily.Monospace,
                        fontSize = 10.sp,
                        lineHeight = 14.sp,
                        color = TextPrimaryDark
                    )
                }
            }
        }
    }
}
