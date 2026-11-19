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
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.*
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.testTag
import androidx.compose.ui.text.font.FontFamily
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import com.example.data.model.NasVendor
import com.example.data.model.Subscriber
import com.example.ui.theme.*
import com.example.viewmodel.IspArchitectViewModel

// ---------------------------------------------------------------------
// 1. GENIEACS TR-069 ONT MANAGER
// ---------------------------------------------------------------------
@Composable
fun GenieAcsTr069Section(
    viewModel: IspArchitectViewModel,
    modifier: Modifier = Modifier
) {
    val subscribers by viewModel.subscribers.collectAsStateWithLifecycle()

    LazyColumn(
        modifier = modifier
            .fillMaxSize()
            .background(TelecomNavyDark)
            .padding(horizontal = 16.dp),
        verticalArrangement = Arrangement.spacedBy(12.dp)
    ) {
        item { Spacer(modifier = Modifier.height(6.dp)) }

        item {
            Column {
                Text(
                    text = "GenieACS TR-069 ONT Console",
                    style = MaterialTheme.typography.headlineSmall,
                    color = TextPrimaryDark,
                    fontWeight = FontWeight.Bold
                )
                Text(
                    text = "CWMP NBI Auto-Provisioning • Optical Rx Power (-dBm) • LOS Triage",
                    style = MaterialTheme.typography.bodySmall,
                    color = TextSecondaryDark
                )
            }
        }

        items(subscribers, key = { it.id }) { sub ->
            val isLosRisk = sub.opticalRxDbm <= -25.0
            val signalColor = when {
                sub.opticalRxDbm <= -25.0 -> CoralDisconnect
                sub.opticalRxDbm <= -22.0 -> AmberBilling
                else -> EmeraldSla
            }
            val signalStrengthRatio = ((sub.opticalRxDbm + 32.0) / 18.0).coerceIn(0.1, 1.0).toFloat()

            Card(
                modifier = Modifier
                    .fillMaxWidth()
                    .testTag("tr069_card_${sub.ontSerial}"),
                shape = RoundedCornerShape(12.dp),
                colors = CardDefaults.cardColors(containerColor = TelecomSurfaceDark),
                border = CardDefaults.outlinedCardBorder().copy(
                    brush = androidx.compose.ui.graphics.SolidColor(
                        if (isLosRisk) CoralDisconnect.copy(alpha = 0.6f) else TelecomBorderDark
                    )
                )
            ) {
                Column(modifier = Modifier.padding(14.dp)) {
                    Row(
                        modifier = Modifier.fillMaxWidth(),
                        horizontalArrangement = Arrangement.SpaceBetween,
                        verticalAlignment = Alignment.CenterVertically
                    ) {
                        Column {
                            Text(
                                text = sub.ontSerial,
                                fontFamily = FontFamily.Monospace,
                                fontWeight = FontWeight.Bold,
                                fontSize = 14.sp,
                                color = CyanPulse
                            )
                            Text(
                                text = "Subscriber: ${sub.username} (${sub.division}) • Shard #${sub.shardId}",
                                style = MaterialTheme.typography.bodySmall,
                                color = TextSecondaryDark
                            )
                        }

                        Surface(
                            shape = RoundedCornerShape(8.dp),
                            color = signalColor.copy(alpha = 0.16f)
                        ) {
                            Text(
                                text = if (isLosRisk) "LOS ALARM" else "${sub.opticalRxDbm} dBm",
                                fontFamily = FontFamily.Monospace,
                                fontSize = 11.sp,
                                fontWeight = FontWeight.Bold,
                                color = signalColor,
                                modifier = Modifier.padding(horizontal = 8.dp, vertical = 4.dp)
                            )
                        }
                    }

                    Spacer(modifier = Modifier.height(10.dp))

                    Row(
                        modifier = Modifier.fillMaxWidth(),
                        verticalAlignment = Alignment.CenterVertically,
                        horizontalArrangement = Arrangement.spacedBy(8.dp)
                    ) {
                        Text(
                            text = "Rx Optical:",
                            fontFamily = FontFamily.Monospace,
                            fontSize = 10.sp,
                            color = TextTertiaryDark
                        )
                        LinearProgressIndicator(
                            progress = { signalStrengthRatio },
                            modifier = Modifier
                                .weight(1f)
                                .height(6.dp)
                                .clip(RoundedCornerShape(3.dp)),
                            color = signalColor,
                            trackColor = TelecomCardDark
                        )
                        Text(
                            text = "${sub.opticalRxDbm} dBm",
                            fontFamily = FontFamily.Monospace,
                            fontSize = 10.sp,
                            color = signalColor
                        )
                    }

                    Spacer(modifier = Modifier.height(10.dp))

                    Row(
                        modifier = Modifier.fillMaxWidth(),
                        horizontalArrangement = Arrangement.spacedBy(8.dp)
                    ) {
                        OutlinedButton(
                            onClick = { viewModel.triggerCwmpInform(sub.username) },
                            modifier = Modifier
                                .weight(1f)
                                .minimumInteractiveComponentSize()
                                .testTag("tr069_inform_${sub.username}"),
                            contentPadding = PaddingValues(horizontal = 6.dp, vertical = 6.dp)
                        ) {
                            Text("CWMP Inform", fontSize = 11.sp, color = CyanPulse)
                        }
                        Button(
                            onClick = { viewModel.pushTr069PppoePreset(sub.username) },
                            colors = ButtonDefaults.buttonColors(containerColor = EmeraldSla),
                            modifier = Modifier
                                .weight(1f)
                                .minimumInteractiveComponentSize()
                                .testTag("tr069_push_${sub.username}"),
                            contentPadding = PaddingValues(horizontal = 6.dp, vertical = 6.dp)
                        ) {
                            Text("Push PPPoE", fontSize = 11.sp, color = TelecomNavyDark, fontWeight = FontWeight.Bold)
                        }
                        OutlinedButton(
                            onClick = { viewModel.rebootOnt(sub.username) },
                            modifier = Modifier
                                .weight(0.8f)
                                .minimumInteractiveComponentSize()
                                .testTag("tr069_reboot_${sub.username}"),
                            contentPadding = PaddingValues(horizontal = 6.dp, vertical = 6.dp)
                        ) {
                            Text("Reboot", fontSize = 11.sp, color = AmberBilling)
                        }
                    }
                }
            }
        }

        item { Spacer(modifier = Modifier.height(24.dp)) }
    }
}

// ---------------------------------------------------------------------
// 2. BTRC CGNAT INSPECTOR (RFC 6888 DETERMINISTIC PORT BLOCKS)
// ---------------------------------------------------------------------
@Composable
fun BtrcCgnatInspectorSection(
    viewModel: IspArchitectViewModel,
    modifier: Modifier = Modifier
) {
    val subscribers by viewModel.subscribers.collectAsStateWithLifecycle()
    var publicIpInput by remember { mutableStateOf("103.112.40.3") }
    var portInput by remember { mutableStateOf("2100") }
    var matchedSub by remember { mutableStateOf<Subscriber?>(null) }
    var lookupRan by remember { mutableStateOf(false) }

    val parsedPort = portInput.toIntOrNull() ?: 1024
    val blockSlot = ((parsedPort - 1024).coerceAtLeast(0)) / 512
    val blockStart = 1024 + (blockSlot * 512)
    val blockEnd = blockStart + 511

    LazyColumn(
        modifier = modifier
            .fillMaxSize()
            .background(TelecomNavyDark)
            .padding(horizontal = 16.dp),
        verticalArrangement = Arrangement.spacedBy(12.dp)
    ) {
        item { Spacer(modifier = Modifier.height(6.dp)) }

        item {
            Column {
                Text(
                    text = "BTRC CGNAT Inspector (RFC 6888)",
                    style = MaterialTheme.typography.headlineSmall,
                    color = TextPrimaryDark,
                    fontWeight = FontWeight.Bold
                )
                Text(
                    text = "Deterministic Public IPv4 + Port Block -> Subscriber Attribution",
                    style = MaterialTheme.typography.bodySmall,
                    color = TextSecondaryDark
                )
            }
        }

        item {
            Card(
                modifier = Modifier.fillMaxWidth(),
                shape = RoundedCornerShape(14.dp),
                colors = CardDefaults.cardColors(containerColor = TelecomSurfaceDark),
                border = CardDefaults.outlinedCardBorder().copy(
                    brush = androidx.compose.ui.graphics.SolidColor(TelecomBorderDark)
                )
            ) {
                Column(modifier = Modifier.padding(14.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
                    Text(
                        text = "Quick Load Active Subscriber Mapping",
                        style = MaterialTheme.typography.labelSmall,
                        color = TextSecondaryDark
                    )
                    Row(
                        modifier = Modifier
                            .fillMaxWidth()
                            .horizontalScroll(rememberScrollState()),
                        horizontalArrangement = Arrangement.spacedBy(6.dp)
                    ) {
                        subscribers.forEach { sub ->
                            Surface(
                                shape = RoundedCornerShape(8.dp),
                                color = TelecomCardDark,
                                modifier = Modifier
                                    .clickable {
                                        publicIpInput = sub.cgnatPublicIp
                                        portInput = (sub.cgnatPortStart + 25).toString()
                                        matchedSub = sub
                                        lookupRan = true
                                    }
                                    .minimumInteractiveComponentSize()
                            ) {
                                Text(
                                    text = "${sub.cgnatPublicIp}:${sub.cgnatPortStart}",
                                    fontFamily = FontFamily.Monospace,
                                    fontSize = 10.sp,
                                    color = CyanPulse,
                                    modifier = Modifier.padding(horizontal = 10.dp, vertical = 8.dp)
                                )
                            }
                        }
                    }

                    Row(
                        modifier = Modifier.fillMaxWidth(),
                        horizontalArrangement = Arrangement.spacedBy(8.dp)
                    ) {
                        OutlinedTextField(
                            value = publicIpInput,
                            onValueChange = { publicIpInput = it },
                            label = { Text("Public IPv4") },
                            singleLine = true,
                            modifier = Modifier
                                .weight(1.3f)
                                .testTag("cgnat_public_ip_input")
                        )
                        OutlinedTextField(
                            value = portInput,
                            onValueChange = { portInput = it },
                            label = { Text("Src Port") },
                            singleLine = true,
                            modifier = Modifier
                                .weight(0.7f)
                                .testTag("cgnat_port_input")
                        )
                    }

                    Button(
                        onClick = {
                            matchedSub = viewModel.reverseLookupCgnat(publicIpInput, parsedPort)
                            lookupRan = true
                        },
                        colors = ButtonDefaults.buttonColors(containerColor = CyanPulse),
                        modifier = Modifier
                            .fillMaxWidth()
                            .minimumInteractiveComponentSize()
                            .testTag("cgnat_trace_button")
                    ) {
                        Icon(Icons.Default.Search, contentDescription = null, tint = TelecomNavyDark)
                        Spacer(modifier = Modifier.width(8.dp))
                        Text("Trace Subscriber (RFC 6888)", color = TelecomNavyDark, fontWeight = FontWeight.Bold)
                    }

                    Surface(
                        shape = RoundedCornerShape(8.dp),
                        color = TelecomNavyDark,
                        modifier = Modifier.fillMaxWidth()
                    ) {
                        Column(modifier = Modifier.padding(10.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                            Text(
                                text = "RFC 6888 Block #$blockSlot • Port Range [$blockStart–$blockEnd]",
                                fontFamily = FontFamily.Monospace,
                                fontSize = 11.sp,
                                color = AmberBilling,
                                fontWeight = FontWeight.Bold
                            )
                            if (lookupRan) {
                                val sub = matchedSub
                                if (sub != null) {
                                    Text(
                                        text = "MATCHED: ${sub.username} (${sub.division})",
                                        fontFamily = FontFamily.Monospace,
                                        fontSize = 12.sp,
                                        color = EmeraldSla,
                                        fontWeight = FontWeight.Bold
                                    )
                                    Text(
                                        text = "Framed-IP: ${sub.ipAddress} | Citus Shard #${sub.shardId} | ONT: ${sub.ontSerial}",
                                        fontFamily = FontFamily.Monospace,
                                        fontSize = 10.sp,
                                        color = TextPrimaryDark
                                    )
                                } else {
                                    Text(
                                        text = "No active subscriber currently holds $publicIpInput:$parsedPort (Block [$blockStart-$blockEnd]).",
                                        fontFamily = FontFamily.Monospace,
                                        fontSize = 10.sp,
                                        color = TextSecondaryDark
                                    )
                                }
                            }
                        }
                    }
                }
            }
        }

        item { Spacer(modifier = Modifier.height(24.dp)) }
    }
}

// ---------------------------------------------------------------------
// 3. MULTI-VENDOR VSA TARIFF COMPILER
// ---------------------------------------------------------------------
@Composable
fun MultiVendorVsaCompilerSection(modifier: Modifier = Modifier) {
    var selectedVendor by remember { mutableStateOf(NasVendor.MIKROTIK_CCR2004) }
    var tariffName by remember { mutableStateOf("50M_Carrier_Fiber") }
    var downMbps by remember { mutableStateOf("50") }
    var upMbps by remember { mutableStateOf("50") }
    var burstPct by remember { mutableStateOf("150") }
    val context = LocalContext.current

    val compiledOutput = remember(selectedVendor, tariffName, downMbps, upMbps, burstPct) {
        val d = downMbps.toIntOrNull() ?: 50
        val u = upMbps.toIntOrNull() ?: 50
        val b = burstPct.toIntOrNull() ?: 150
        val burstDown = (d * b) / 100
        val burstUp = (u * b) / 100
        when (selectedVendor) {
            NasVendor.MIKROTIK_CCR2004 -> """
                -- MikroTik RouterOS v7 (radgroupreply)
                INSERT INTO radgroupreply (groupname, attribute, op, value) VALUES
                ('$tariffName', 'Mikrotik-Rate-Limit', ':=', '${u}M/${d}M ${burstUp}M/${burstDown}M ${u / 2}M/${d / 2}M 10/10 8 ${u / 2}M/${d / 2}M');
            """.trimIndent()

            NasVendor.HUAWEI_NE8000 -> """
                -- Huawei NE8000 BRAS VSA (kbps)
                INSERT INTO radgroupreply (groupname, attribute, op, value) VALUES
                ('$tariffName', 'Huawei-Input-Average-Rate', ':=', '${u * 1024}'),
                ('$tariffName', 'Huawei-Input-Peak-Information-Rate', ':=', '${burstUp * 1024}'),
                ('$tariffName', 'Huawei-Output-Average-Rate', ':=', '${d * 1024}'),
                ('$tariffName', 'Huawei-Output-Peak-Information-Rate', ':=', '${burstDown * 1024}');
            """.trimIndent()

            NasVendor.JUNIPER_MX -> """
                -- Juniper MX Series BNG (ERX VSA)
                INSERT INTO radgroupreply (groupname, attribute, op, value) VALUES
                ('$tariffName', 'ERX-Ingress-Policy-Name', ':=', 'POL_${u}M_UP'),
                ('$tariffName', 'ERX-Egress-Policy-Name', ':=', 'POL_${d}M_DOWN');
            """.trimIndent()
        }
    }

    LazyColumn(
        modifier = modifier
            .fillMaxSize()
            .background(TelecomNavyDark)
            .padding(horizontal = 16.dp),
        verticalArrangement = Arrangement.spacedBy(12.dp)
    ) {
        item { Spacer(modifier = Modifier.height(6.dp)) }

        item {
            Column {
                Text(
                    text = "Multi-Vendor VSA Tariff Compiler",
                    style = MaterialTheme.typography.headlineSmall,
                    color = TextPrimaryDark,
                    fontWeight = FontWeight.Bold
                )
                Text(
                    text = "Compile FreeRADIUS radgroupreply policies for MikroTik, Huawei & Juniper",
                    style = MaterialTheme.typography.bodySmall,
                    color = TextSecondaryDark
                )
            }
        }

        item {
            Card(
                modifier = Modifier.fillMaxWidth(),
                shape = RoundedCornerShape(14.dp),
                colors = CardDefaults.cardColors(containerColor = TelecomSurfaceDark),
                border = CardDefaults.outlinedCardBorder().copy(
                    brush = androidx.compose.ui.graphics.SolidColor(TelecomBorderDark)
                )
            ) {
                Column(modifier = Modifier.padding(14.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
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
                                label = { Text(vendor.displayName, fontSize = 11.sp) }
                            )
                        }
                    }

                    OutlinedTextField(
                        value = tariffName,
                        onValueChange = { tariffName = it },
                        label = { Text("Tariff Group Name") },
                        singleLine = true,
                        modifier = Modifier.fillMaxWidth()
                    )

                    Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                        OutlinedTextField(
                            value = downMbps,
                            onValueChange = { downMbps = it },
                            label = { Text("Down Mbps") },
                            singleLine = true,
                            modifier = Modifier.weight(1f)
                        )
                        OutlinedTextField(
                            value = upMbps,
                            onValueChange = { upMbps = it },
                            label = { Text("Up Mbps") },
                            singleLine = true,
                            modifier = Modifier.weight(1f)
                        )
                        OutlinedTextField(
                            value = burstPct,
                            onValueChange = { burstPct = it },
                            label = { Text("Burst %") },
                            singleLine = true,
                            modifier = Modifier.weight(1f)
                        )
                    }

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
                                    text = selectedVendor.vsaAttribute,
                                    fontFamily = FontFamily.Monospace,
                                    fontSize = 11.sp,
                                    color = CyanPulse,
                                    fontWeight = FontWeight.Bold
                                )
                                IconButton(
                                    onClick = {
                                        val cb = context.getSystemService(Context.CLIPBOARD_SERVICE) as ClipboardManager
                                        cb.setPrimaryClip(ClipData.newPlainText("VSA SQL", compiledOutput))
                                        Toast.makeText(context, "Copied VSA SQL", Toast.LENGTH_SHORT).show()
                                    },
                                    modifier = Modifier.testTag("copy_vsa_button")
                                ) {
                                    Icon(Icons.Default.ContentCopy, contentDescription = "Copy VSA SQL", tint = CyanPulse)
                                }
                            }
                            Text(
                                text = compiledOutput,
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

        item { Spacer(modifier = Modifier.height(24.dp)) }
    }
}
