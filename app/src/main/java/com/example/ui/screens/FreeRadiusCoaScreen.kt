package com.example.ui.screens

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
import androidx.compose.ui.platform.testTag
import androidx.compose.ui.text.font.FontFamily
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import com.example.data.model.CoaActionType
import com.example.data.model.NasVendor
import com.example.ui.components.ConsoleLogViewer
import com.example.ui.components.LatencyGauge
import com.example.ui.theme.*
import com.example.viewmodel.IspArchitectViewModel

@Composable
fun FreeRadiusCoaScreen(
    viewModel: IspArchitectViewModel,
    modifier: Modifier = Modifier
) {
    val coaResult by viewModel.coaResult.collectAsStateWithLifecycle()
    val isKicking by viewModel.isKicking.collectAsStateWithLifecycle()
    val benchResult by viewModel.benchmarkResult.collectAsStateWithLifecycle()
    val isBenchmarking by viewModel.isBenchmarking.collectAsStateWithLifecycle()
    val subscribers by viewModel.subscribers.collectAsStateWithLifecycle()
    val logs by viewModel.consoleLogs.collectAsStateWithLifecycle()

    var selectedAction by remember { mutableStateOf(CoaActionType.DISCONNECT_POD) }
    var selectedVendor by remember { mutableStateOf(NasVendor.MIKROTIK_CCR2004) }
    var targetUser by remember { mutableStateOf("mirpur_home_sub01") }
    var targetIp by remember { mutableStateOf("10.100.0.15") }
    var rateLimitInput by remember { mutableStateOf("20M/20M") }

    val vsaPreview = remember(selectedAction, selectedVendor, targetUser, targetIp, rateLimitInput) {
        val effectiveRate = if (selectedAction == CoaActionType.COA_WALLED_GARDEN) "512k/512k" else rateLimitInput
        val vendorAttr = when (selectedVendor) {
            NasVendor.MIKROTIK_CCR2004 -> "Mikrotik-Rate-Limit = \"$effectiveRate\""
            NasVendor.HUAWEI_NE8000 -> "Huawei-Input-Peak-Information-Rate = \"$effectiveRate\""
            NasVendor.JUNIPER_MX -> "ERX-Ingress-Policy-Name = \"POL_${effectiveRate.replace("/", "_")}\""
        }
        val extra = when (selectedAction) {
            CoaActionType.DISCONNECT_POD -> "# Packet Code 40: Disconnect-Request"
            CoaActionType.COA_WALLED_GARDEN -> "Mikrotik-Address-List = \"EXPIRED_USERS\"\n$vendorAttr"
            else -> vendorAttr
        }
        """
            User-Name = "$targetUser"
            Framed-IP-Address = $targetIp
            NAS-Port-Type = Ethernet
            $extra
        """.trimIndent()
    }

    LazyColumn(
        modifier = modifier
            .fillMaxSize()
            .background(TelecomNavyDark)
            .padding(horizontal = 16.dp),
        verticalArrangement = Arrangement.spacedBy(14.dp)
    ) {
        item { Spacer(modifier = Modifier.height(6.dp)) }

        item {
            Row(
                modifier = Modifier.fillMaxWidth(),
                horizontalArrangement = Arrangement.SpaceBetween,
                verticalAlignment = Alignment.CenterVertically
            ) {
                Column(modifier = Modifier.weight(1f)) {
                    Text(
                        text = "FreeRADIUS CoA Controls",
                        style = MaterialTheme.typography.headlineSmall,
                        color = TextPrimaryDark,
                        fontWeight = FontWeight.Bold
                    )
                    Text(
                        text = "RFC 5176 Dynamic Authorization • UDP Port 3799 • <500ms SLA",
                        style = MaterialTheme.typography.bodySmall,
                        color = TextSecondaryDark
                    )
                }
                Surface(
                    shape = RoundedCornerShape(8.dp),
                    color = CoralDisconnect.copy(alpha = 0.18f)
                ) {
                    Text(
                        text = "UDP 3799",
                        fontFamily = FontFamily.Monospace,
                        fontSize = 11.sp,
                        fontWeight = FontWeight.Bold,
                        color = CoralDisconnect,
                        modifier = Modifier.padding(horizontal = 8.dp, vertical = 4.dp)
                    )
                }
            }
        }

        // RFC 5176 Packet Builder & Dispatch Card
        item {
            Card(
                modifier = Modifier.fillMaxWidth(),
                shape = RoundedCornerShape(14.dp),
                colors = CardDefaults.cardColors(containerColor = TelecomSurfaceDark),
                border = CardDefaults.outlinedCardBorder().copy(
                    brush = androidx.compose.ui.graphics.SolidColor(TelecomBorderDark)
                )
            ) {
                Column(modifier = Modifier.padding(14.dp)) {
                    Text(
                        text = "1. RFC 5176 Packet Type",
                        style = MaterialTheme.typography.labelLarge,
                        color = TextPrimaryDark,
                        fontWeight = FontWeight.Bold
                    )
                    Spacer(modifier = Modifier.height(6.dp))
                    Row(
                        modifier = Modifier
                            .fillMaxWidth()
                            .horizontalScroll(rememberScrollState()),
                        horizontalArrangement = Arrangement.spacedBy(8.dp)
                    ) {
                        CoaActionType.values().forEach { action ->
                            val isSelected = selectedAction == action
                            Surface(
                                shape = RoundedCornerShape(10.dp),
                                color = if (isSelected) CyanPulse else TelecomCardDark,
                                modifier = Modifier
                                    .clickable { selectedAction = action }
                                    .minimumInteractiveComponentSize()
                                    .testTag("coa_action_${action.name}")
                            ) {
                                Column(modifier = Modifier.padding(horizontal = 10.dp, vertical = 8.dp)) {
                                    Text(
                                        text = action.label,
                                        fontSize = 11.sp,
                                        fontWeight = FontWeight.Bold,
                                        color = if (isSelected) TelecomNavyDark else TextPrimaryDark
                                    )
                                    Text(
                                        text = action.codeLabel,
                                        fontSize = 9.sp,
                                        fontFamily = FontFamily.Monospace,
                                        color = if (isSelected) TelecomNavyDark.copy(alpha = 0.8f) else TextSecondaryDark
                                    )
                                }
                            }
                        }
                    }

                    Spacer(modifier = Modifier.height(12.dp))
                    Text(
                        text = "2. Target BRAS / NAS Vendor",
                        style = MaterialTheme.typography.labelLarge,
                        color = TextPrimaryDark,
                        fontWeight = FontWeight.Bold
                    )
                    Spacer(modifier = Modifier.height(6.dp))
                    Row(
                        modifier = Modifier
                            .fillMaxWidth()
                            .horizontalScroll(rememberScrollState()),
                        horizontalArrangement = Arrangement.spacedBy(8.dp)
                    ) {
                        NasVendor.values().forEach { vendor ->
                            FilterChip(
                                selected = selectedVendor == vendor,
                                onClick = { selectedVendor = vendor },
                                label = { Text(vendor.displayName, fontSize = 11.sp) },
                                colors = FilterChipDefaults.filterChipColors(
                                    selectedContainerColor = EmeraldSla,
                                    selectedLabelColor = TelecomNavyDark
                                ),
                                modifier = Modifier.testTag("coa_vendor_${vendor.name}")
                            )
                        }
                    }

                    Spacer(modifier = Modifier.height(10.dp))
                    Text(
                        text = "3. Quick Select Subscriber",
                        style = MaterialTheme.typography.labelSmall,
                        color = TextSecondaryDark
                    )
                    Spacer(modifier = Modifier.height(4.dp))
                    Row(
                        modifier = Modifier
                            .fillMaxWidth()
                            .horizontalScroll(rememberScrollState()),
                        horizontalArrangement = Arrangement.spacedBy(6.dp)
                    ) {
                        subscribers.forEach { sub ->
                            val isSelected = targetUser == sub.username
                            Surface(
                                shape = RoundedCornerShape(8.dp),
                                color = if (isSelected) CyanPulse.copy(alpha = 0.2f) else TelecomCardDark,
                                modifier = Modifier
                                    .clickable {
                                        targetUser = sub.username
                                        targetIp = sub.ipAddress
                                    }
                                    .minimumInteractiveComponentSize()
                            ) {
                                Text(
                                    text = "${sub.username} (${sub.ipAddress})",
                                    fontFamily = FontFamily.Monospace,
                                    fontSize = 10.sp,
                                    color = if (isSelected) CyanPulse else TextPrimaryDark,
                                    modifier = Modifier.padding(horizontal = 10.dp, vertical = 8.dp)
                                )
                            }
                        }
                    }

                    Spacer(modifier = Modifier.height(10.dp))

                    Row(
                        modifier = Modifier.fillMaxWidth(),
                        horizontalArrangement = Arrangement.spacedBy(8.dp)
                    ) {
                        OutlinedTextField(
                            value = targetUser,
                            onValueChange = { targetUser = it },
                            label = { Text("User-Name") },
                            singleLine = true,
                            modifier = Modifier
                                .weight(1f)
                                .testTag("coa_username_input")
                        )
                        OutlinedTextField(
                            value = targetIp,
                            onValueChange = { targetIp = it },
                            label = { Text("Framed-IP") },
                            singleLine = true,
                            modifier = Modifier
                                .weight(1f)
                                .testTag("coa_ip_input")
                        )
                    }

                    if (selectedAction == CoaActionType.COA_RATE_LIMIT || selectedAction == CoaActionType.COA_RESTORE) {
                        Spacer(modifier = Modifier.height(8.dp))
                        OutlinedTextField(
                            value = rateLimitInput,
                            onValueChange = { rateLimitInput = it },
                            label = { Text("Target Rate-Limit (e.g. 50M/50M)") },
                            singleLine = true,
                            modifier = Modifier
                                .fillMaxWidth()
                                .testTag("coa_ratelimit_input")
                        )
                    }

                    Spacer(modifier = Modifier.height(10.dp))

                    // VSA Attribute Preview
                    Surface(
                        shape = RoundedCornerShape(8.dp),
                        color = TelecomNavyDark,
                        modifier = Modifier.fillMaxWidth()
                    ) {
                        Column(modifier = Modifier.padding(10.dp)) {
                            Text(
                                text = "RFC 5176 radclient Packet Preview (${selectedVendor.vsaAttribute})",
                                fontFamily = FontFamily.Monospace,
                                fontSize = 10.sp,
                                color = CyanPulse,
                                fontWeight = FontWeight.Bold
                            )
                            Spacer(modifier = Modifier.height(4.dp))
                            Text(
                                text = vsaPreview,
                                fontFamily = FontFamily.Monospace,
                                fontSize = 10.sp,
                                lineHeight = 14.sp,
                                color = TextPrimaryDark
                            )
                        }
                    }

                    Spacer(modifier = Modifier.height(12.dp))

                    val btnColor = if (selectedAction == CoaActionType.DISCONNECT_POD ||
                        selectedAction == CoaActionType.COA_WALLED_GARDEN
                    ) CoralDisconnect else EmeraldSla
                    val btnTextColor = if (btnColor == EmeraldSla) TelecomNavyDark else Color.White

                    Button(
                        onClick = {
                            viewModel.triggerCoaAction(
                                username = targetUser,
                                ip = targetIp,
                                actionType = selectedAction,
                                vendor = selectedVendor,
                                rateLimitOverride = rateLimitInput
                            )
                        },
                        enabled = !isKicking,
                        colors = ButtonDefaults.buttonColors(containerColor = btnColor),
                        modifier = Modifier
                            .fillMaxWidth()
                            .minimumInteractiveComponentSize()
                            .testTag("send_pod_button")
                    ) {
                        if (isKicking) {
                            CircularProgressIndicator(color = btnTextColor, modifier = Modifier.size(18.dp), strokeWidth = 2.dp)
                            Spacer(modifier = Modifier.width(8.dp))
                            Text("Dispatching RFC 5176 UDP 3799...", color = btnTextColor, fontWeight = FontWeight.Bold)
                        } else {
                            Icon(Icons.Default.FlashOn, contentDescription = null, tint = btnTextColor)
                            Spacer(modifier = Modifier.width(8.dp))
                            Text("Dispatch ${selectedAction.label}", color = btnTextColor, fontWeight = FontWeight.Bold)
                        }
                    }
                }
            }
        }

        coaResult?.let { result ->
            item {
                LatencyGauge(
                    title = "RFC 5176 CoA / PoD Execution Latency",
                    currentValueMs = result.latencyMs.toDouble(),
                    slaThresholdMs = 500.0,
                    unit = "ms"
                )
            }
            item {
                Card(
                    modifier = Modifier.fillMaxWidth(),
                    shape = RoundedCornerShape(12.dp),
                    colors = CardDefaults.cardColors(containerColor = TelecomCardDark),
                    border = CardDefaults.outlinedCardBorder().copy(
                        brush = androidx.compose.ui.graphics.SolidColor(TelecomBorderDark)
                    )
                ) {
                    Column(modifier = Modifier.padding(12.dp)) {
                        Text(
                            text = "${result.packetType} -> ${result.resultStatus}",
                            fontFamily = FontFamily.Monospace,
                            fontSize = 12.sp,
                            fontWeight = FontWeight.Bold,
                            color = EmeraldSla
                        )
                        Spacer(modifier = Modifier.height(4.dp))
                        Text(
                            text = "Msg-Auth: ${result.authenticatorHex}",
                            fontFamily = FontFamily.Monospace,
                            fontSize = 10.sp,
                            color = CyanPulse
                        )
                        Spacer(modifier = Modifier.height(6.dp))
                        result.logTrace.forEach { trace ->
                            Text(
                                text = "• $trace",
                                fontFamily = FontFamily.Monospace,
                                fontSize = 10.sp,
                                color = TextSecondaryDark
                            )
                        }
                    }
                }
            }
        }

        // Fast-Path 100-Packet Benchmark
        item {
            Card(
                modifier = Modifier.fillMaxWidth(),
                shape = RoundedCornerShape(12.dp),
                colors = CardDefaults.cardColors(containerColor = TelecomSurfaceDark),
                border = CardDefaults.outlinedCardBorder().copy(
                    brush = androidx.compose.ui.graphics.SolidColor(TelecomBorderDark)
                )
            ) {
                Column(modifier = Modifier.padding(14.dp)) {
                    Text(
                        text = "FreeRADIUS rlm_redis + Citus 100-Packet Benchmark",
                        style = MaterialTheme.typography.titleMedium,
                        color = TextPrimaryDark,
                        fontWeight = FontWeight.Bold
                    )
                    Text(
                        text = "Measures Access-Accept latency across Redis L1 Fast-Path & PG16 fallback.",
                        style = MaterialTheme.typography.bodySmall,
                        color = TextSecondaryDark
                    )
                    Spacer(modifier = Modifier.height(10.dp))
                    Button(
                        onClick = { viewModel.runRadiusLatencyBenchmark(100) },
                        enabled = !isBenchmarking,
                        colors = ButtonDefaults.buttonColors(containerColor = CyanPulse),
                        modifier = Modifier
                            .fillMaxWidth()
                            .minimumInteractiveComponentSize()
                            .testTag("run_benchmark_button")
                    ) {
                        if (isBenchmarking) {
                            CircularProgressIndicator(color = TelecomNavyDark, modifier = Modifier.size(18.dp), strokeWidth = 2.dp)
                            Spacer(modifier = Modifier.width(8.dp))
                            Text("Benchmarking 100 Auth Packets...", color = TelecomNavyDark)
                        } else {
                            Icon(Icons.Default.Speed, contentDescription = null, tint = TelecomNavyDark)
                            Spacer(modifier = Modifier.width(8.dp))
                            Text("Run 100-Packet Fast-Path Benchmark", color = TelecomNavyDark, fontWeight = FontWeight.Bold)
                        }
                    }

                    benchResult?.let { bench ->
                        Spacer(modifier = Modifier.height(12.dp))
                        LatencyGauge(
                            title = "Average Access-Accept Latency",
                            currentValueMs = bench.avgLatencyMs,
                            slaThresholdMs = 10.0,
                            unit = "ms"
                        )
                    }
                }
            }
        }

        item {
            Text(
                text = "Live AAA / CoA Execution Console",
                style = MaterialTheme.typography.titleMedium,
                color = TextPrimaryDark,
                fontWeight = FontWeight.Bold
            )
            Spacer(modifier = Modifier.height(6.dp))
            ConsoleLogViewer(logs = logs)
        }

        item { Spacer(modifier = Modifier.height(24.dp)) }
    }
}
