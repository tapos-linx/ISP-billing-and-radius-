package com.example.ui.screens

import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.*
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.testTag
import androidx.compose.ui.text.font.FontFamily
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import com.example.data.model.MfsWebhookEvent
import com.example.data.model.WebhookSignatureStatus
import com.example.ui.components.LatencyGauge
import com.example.ui.theme.*
import com.example.viewmodel.IspArchitectViewModel
import com.example.viewmodel.MfsSimulationMode

@Composable
fun MfsWebhookMonitorScreen(
    viewModel: IspArchitectViewModel,
    modifier: Modifier = Modifier
) {
    val events by viewModel.mfsWebhookEvents.collectAsStateWithLifecycle()
    val paymentResult by viewModel.paymentResult.collectAsStateWithLifecycle()
    val isProcessing by viewModel.isProcessingPayment.collectAsStateWithLifecycle()
    val subscribers by viewModel.subscribers.collectAsStateWithLifecycle()

    var gateway by remember { mutableStateOf("bKash") }
    var selectedUsername by remember { mutableStateOf("mirpur_home_sub01") }
    var amountInput by remember { mutableStateOf("1000") }
    var simMode by remember { mutableStateOf(MfsSimulationMode.VALID_UNBLOCK) }

    LazyColumn(
        modifier = modifier
            .fillMaxSize()
            .background(TelecomNavyDark)
            .padding(horizontal = 16.dp),
        verticalArrangement = Arrangement.spacedBy(14.dp)
    ) {
        item { Spacer(modifier = Modifier.height(6.dp)) }

        item {
            Column {
                Text(
                    text = "MFS Webhook Monitor",
                    style = MaterialTheme.typography.headlineSmall,
                    color = TextPrimaryDark,
                    fontWeight = FontWeight.Bold
                )
                Text(
                    text = "bKash / Nagad HMAC-SHA256 verification • Redis trx:{id} lock • <3s Auto-Unblock",
                    style = MaterialTheme.typography.bodySmall,
                    color = TextSecondaryDark
                )
            }
        }

        // Interactive Webhook Dispatch Card
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
                        text = "Dispatch / Test MFS Callback",
                        style = MaterialTheme.typography.titleMedium,
                        color = TextPrimaryDark,
                        fontWeight = FontWeight.Bold
                    )
                    Spacer(modifier = Modifier.height(8.dp))

                    // Gateway Picker
                    Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                        listOf("bKash", "Nagad", "SSLCOMMERZ").forEach { gw ->
                            FilterChip(
                                selected = gateway == gw,
                                onClick = { gateway = gw },
                                label = { Text(gw) },
                                colors = FilterChipDefaults.filterChipColors(
                                    selectedContainerColor = AmberBilling,
                                    selectedLabelColor = TelecomNavyDark
                                ),
                                modifier = Modifier.testTag("mfs_gw_$gw")
                            )
                        }
                    }

                    Spacer(modifier = Modifier.height(8.dp))

                    // Quick subscriber chips
                    Row(
                        modifier = Modifier.fillMaxWidth(),
                        horizontalArrangement = Arrangement.spacedBy(6.dp)
                    ) {
                        subscribers.take(3).forEach { sub ->
                            val selected = selectedUsername == sub.username
                            Surface(
                                shape = RoundedCornerShape(8.dp),
                                color = if (selected) CyanPulse.copy(alpha = 0.2f) else TelecomCardDark,
                                border = CardDefaults.outlinedCardBorder().copy(
                                    brush = androidx.compose.ui.graphics.SolidColor(
                                        if (selected) CyanPulse else TelecomBorderDark
                                    )
                                ),
                                modifier = Modifier
                                    .weight(1f)
                                    .clickable {
                                        selectedUsername = sub.username
                                        amountInput = sub.monthlyFee.toInt().toString()
                                    }
                                    .minimumInteractiveComponentSize()
                            ) {
                                Column(modifier = Modifier.padding(8.dp)) {
                                    Text(
                                        text = sub.username,
                                        fontSize = 10.sp,
                                        fontFamily = FontFamily.Monospace,
                                        color = if (selected) CyanPulse else TextPrimaryDark,
                                        maxLines = 1
                                    )
                                    Text(
                                        text = sub.status.name,
                                        fontSize = 9.sp,
                                        color = if (sub.status.name == "EXPIRED") CoralDisconnect else EmeraldSla
                                    )
                                }
                            }
                        }
                    }

                    Spacer(modifier = Modifier.height(10.dp))

                    Row(
                        modifier = Modifier.fillMaxWidth(),
                        horizontalArrangement = Arrangement.spacedBy(8.dp)
                    ) {
                        OutlinedTextField(
                            value = selectedUsername,
                            onValueChange = { selectedUsername = it },
                            label = { Text("Subscriber") },
                            singleLine = true,
                            modifier = Modifier
                                .weight(1.3f)
                                .testTag("mfs_username_input")
                        )
                        OutlinedTextField(
                            value = amountInput,
                            onValueChange = { amountInput = it },
                            label = { Text("BDT") },
                            singleLine = true,
                            modifier = Modifier
                                .weight(0.7f)
                                .testTag("mfs_amount_input")
                        )
                    }

                    Spacer(modifier = Modifier.height(10.dp))

                    // Simulation Mode Chips
                    Text(
                        text = "Webhook Test Scenario",
                        style = MaterialTheme.typography.labelSmall,
                        color = TextSecondaryDark
                    )
                    Spacer(modifier = Modifier.height(4.dp))
                    Row(
                        modifier = Modifier.fillMaxWidth(),
                        horizontalArrangement = Arrangement.spacedBy(6.dp)
                    ) {
                        MfsSimulationMode.values().forEach { mode ->
                            val isSelected = simMode == mode
                            Surface(
                                shape = RoundedCornerShape(8.dp),
                                color = if (isSelected) CyanPulse else TelecomCardDark,
                                modifier = Modifier
                                    .weight(1f)
                                    .clickable { simMode = mode }
                                    .minimumInteractiveComponentSize()
                                    .testTag("mfs_mode_${mode.name}")
                            ) {
                                Box(
                                    contentAlignment = Alignment.Center,
                                    modifier = Modifier.padding(horizontal = 6.dp, vertical = 8.dp)
                                ) {
                                    Text(
                                        text = mode.label,
                                        fontSize = 10.sp,
                                        fontWeight = FontWeight.SemiBold,
                                        color = if (isSelected) TelecomNavyDark else TextPrimaryDark
                                    )
                                }
                            }
                        }
                    }

                    Spacer(modifier = Modifier.height(12.dp))

                    Button(
                        onClick = {
                            viewModel.triggerPaymentWebhook(
                                gateway = gateway,
                                username = selectedUsername,
                                amount = amountInput.toDoubleOrNull() ?: 1000.0,
                                mode = simMode
                            )
                        },
                        enabled = !isProcessing,
                        colors = ButtonDefaults.buttonColors(containerColor = AmberBilling),
                        modifier = Modifier
                            .fillMaxWidth()
                            .minimumInteractiveComponentSize()
                            .testTag("mfs_dispatch_button")
                    ) {
                        if (isProcessing) {
                            CircularProgressIndicator(color = TelecomNavyDark, modifier = Modifier.size(18.dp), strokeWidth = 2.dp)
                            Spacer(modifier = Modifier.width(8.dp))
                            Text("Verifying HMAC & Unblocking...", color = TelecomNavyDark, fontWeight = FontWeight.Bold)
                        } else {
                            Icon(Icons.Default.Payments, contentDescription = null, tint = TelecomNavyDark)
                            Spacer(modifier = Modifier.width(8.dp))
                            Text("Trigger $gateway Webhook", color = TelecomNavyDark, fontWeight = FontWeight.Bold)
                        }
                    }
                }
            }
        }

        // Latest Pipeline Stepper + LatencyGauge
        events.firstOrNull()?.let { latest ->
            item {
                AutoUnblockPipelineCard(event = latest)
            }
        }

        paymentResult?.let { result ->
            item {
                LatencyGauge(
                    title = "MFS Payment -> Line Reconnect SLA",
                    currentValueMs = result.executionTimeMs / 1000.0,
                    slaThresholdMs = 3.0,
                    unit = "s"
                )
            }
        }

        // Event Stream Header
        item {
            Row(
                modifier = Modifier.fillMaxWidth(),
                horizontalArrangement = Arrangement.SpaceBetween,
                verticalAlignment = Alignment.CenterVertically
            ) {
                Text(
                    text = "Webhook Audit Stream (${events.size})",
                    style = MaterialTheme.typography.titleMedium,
                    color = TextPrimaryDark,
                    fontWeight = FontWeight.Bold
                )
                Text(
                    text = "Zero Secrets in Client",
                    fontFamily = FontFamily.Monospace,
                    fontSize = 10.sp,
                    color = EmeraldSla
                )
            }
        }

        items(events, key = { it.id }) { event ->
            MfsEventCard(event = event)
        }

        item { Spacer(modifier = Modifier.height(24.dp)) }
    }
}

@Composable
private fun AutoUnblockPipelineCard(event: MfsWebhookEvent) {
    val steps = listOf(
        "HMAC",
        "Redis Lock",
        "Citus Paid",
        "CoA 3799",
        "Online"
    )
    Card(
        modifier = Modifier.fillMaxWidth(),
        shape = RoundedCornerShape(12.dp),
        colors = CardDefaults.cardColors(containerColor = TelecomCardDark),
        border = CardDefaults.outlinedCardBorder().copy(
            brush = androidx.compose.ui.graphics.SolidColor(TelecomBorderDark)
        )
    ) {
        Column(modifier = Modifier.padding(14.dp)) {
            Row(
                modifier = Modifier.fillMaxWidth(),
                horizontalArrangement = Arrangement.SpaceBetween
            ) {
                Text(
                    text = "Pipeline: ${event.gateway} • ${event.trxId}",
                    style = MaterialTheme.typography.titleSmall,
                    color = TextPrimaryDark,
                    fontWeight = FontWeight.Bold
                )
                Text(
                    text = "HTTP ${event.httpStatus}",
                    fontFamily = FontFamily.Monospace,
                    fontSize = 11.sp,
                    fontWeight = FontWeight.Bold,
                    color = if (event.httpStatus == 200) EmeraldSla else CoralDisconnect
                )
            }
            Spacer(modifier = Modifier.height(10.dp))
            Row(
                modifier = Modifier.fillMaxWidth(),
                horizontalArrangement = Arrangement.SpaceBetween
            ) {
                steps.forEachIndexed { idx, label ->
                    val completed = idx < event.pipelineCompletedSteps
                    val dotColor = when {
                        event.httpStatus != 200 && idx == event.pipelineCompletedSteps - 1 -> CoralDisconnect
                        completed -> EmeraldSla
                        else -> TextTertiaryDark
                    }
                    Column(
                        horizontalAlignment = Alignment.CenterHorizontally,
                        modifier = Modifier.weight(1f)
                    ) {
                        Box(
                            contentAlignment = Alignment.Center,
                            modifier = Modifier
                                .size(24.dp)
                                .clip(CircleShape)
                                .background(dotColor.copy(alpha = 0.2f))
                        ) {
                            Icon(
                                imageVector = if (completed && event.httpStatus == 200) Icons.Default.Check else Icons.Default.Circle,
                                contentDescription = label,
                                tint = dotColor,
                                modifier = Modifier.size(12.dp)
                            )
                        }
                        Spacer(modifier = Modifier.height(4.dp))
                        Text(
                            text = label,
                            fontSize = 9.sp,
                            fontFamily = FontFamily.Monospace,
                            color = if (completed) TextPrimaryDark else TextTertiaryDark
                        )
                    }
                }
            }
        }
    }
}

@Composable
private fun MfsEventCard(event: MfsWebhookEvent) {
    val badgeColor = when (event.signatureStatus) {
        WebhookSignatureStatus.VERIFIED_SERVER,
        WebhookSignatureStatus.VERIFIED_MOCK -> EmeraldSla
        WebhookSignatureStatus.DUPLICATE_LOCKED -> AmberBilling
        WebhookSignatureStatus.INVALID_REJECTED -> CoralDisconnect
    }

    Card(
        modifier = Modifier
            .fillMaxWidth()
            .testTag("mfs_event_${event.trxId}"),
        shape = RoundedCornerShape(12.dp),
        colors = CardDefaults.cardColors(containerColor = TelecomSurfaceDark),
        border = CardDefaults.outlinedCardBorder().copy(
            brush = androidx.compose.ui.graphics.SolidColor(TelecomBorderDark)
        )
    ) {
        Column(modifier = Modifier.padding(12.dp)) {
            Row(
                modifier = Modifier.fillMaxWidth(),
                horizontalArrangement = Arrangement.SpaceBetween,
                verticalAlignment = Alignment.CenterVertically
            ) {
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Surface(
                        shape = RoundedCornerShape(6.dp),
                        color = AmberBilling.copy(alpha = 0.18f)
                    ) {
                        Text(
                            text = event.gateway,
                            fontWeight = FontWeight.Bold,
                            fontSize = 11.sp,
                            color = AmberBilling,
                            modifier = Modifier.padding(horizontal = 8.dp, vertical = 3.dp)
                        )
                    }
                    Spacer(modifier = Modifier.width(8.dp))
                    Text(
                        text = event.trxId,
                        fontFamily = FontFamily.Monospace,
                        fontSize = 12.sp,
                        fontWeight = FontWeight.Bold,
                        color = TextPrimaryDark
                    )
                }

                Surface(
                    shape = RoundedCornerShape(8.dp),
                    color = badgeColor.copy(alpha = 0.16f)
                ) {
                    Text(
                        text = event.signatureStatus.label,
                        fontFamily = FontFamily.Monospace,
                        fontSize = 9.sp,
                        fontWeight = FontWeight.Bold,
                        color = badgeColor,
                        modifier = Modifier.padding(horizontal = 6.dp, vertical = 3.dp)
                    )
                }
            }

            Spacer(modifier = Modifier.height(6.dp))
            Text(
                text = "Subscriber: ${event.username} • Tk ${event.amountBdt.toInt()} • ${event.executionTimeMs}ms @ ${event.timestampLabel}",
                style = MaterialTheme.typography.bodySmall,
                color = TextSecondaryDark
            )

            Spacer(modifier = Modifier.height(6.dp))
            Surface(
                shape = RoundedCornerShape(6.dp),
                color = TelecomNavyDark,
                modifier = Modifier.fillMaxWidth()
            ) {
                Text(
                    text = event.logOutput,
                    fontFamily = FontFamily.Monospace,
                    fontSize = 10.sp,
                    lineHeight = 14.sp,
                    color = TextPrimaryDark,
                    modifier = Modifier.padding(8.dp)
                )
            }
        }
    }
}
