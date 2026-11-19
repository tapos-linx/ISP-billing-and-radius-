package com.example.ui.screens

import androidx.activity.compose.BackHandler
import androidx.compose.foundation.Canvas
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.*
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.geometry.Offset
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.Path
import androidx.compose.ui.graphics.StrokeCap
import androidx.compose.ui.graphics.drawscope.Stroke
import androidx.compose.ui.platform.testTag
import androidx.compose.ui.text.font.FontFamily
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import com.example.data.model.BackendConnectionStatus
import com.example.data.model.CitusShardHealth
import com.example.data.model.ShardHealthStatus
import com.example.data.model.computeCitusShardId
import com.example.ui.theme.*
import com.example.viewmodel.IspArchitectViewModel
import java.text.NumberFormat
import java.util.Locale

@Composable
fun NocCommandCenterScreen(
    viewModel: IspArchitectViewModel,
    modifier: Modifier = Modifier
) {
    val telemetry by viewModel.nocTelemetry.collectAsStateWithLifecycle()
    val shards by viewModel.citusShards.collectAsStateWithLifecycle()
    val selectedShardId by viewModel.selectedShardId.collectAsStateWithLifecycle()
    val cloudState by viewModel.cloudRunState.collectAsStateWithLifecycle()
    var shardLookupInput by remember { mutableStateOf("tapos_test") }

    val selectedShard = remember(shards, selectedShardId) {
        shards.find { it.shardId == selectedShardId } ?: shards.firstOrNull()
    }
    val hashedShardPreview = remember(shardLookupInput) {
        if (shardLookupInput.isBlank()) 0 else computeCitusShardId(shardLookupInput.trim())
    }

    BoxWithConstraints(
        modifier = modifier
            .fillMaxSize()
            .background(TelecomNavyDark)
    ) {
        val isWide = maxWidth >= 600.dp

        LazyColumn(
            modifier = Modifier
                .fillMaxSize()
                .padding(horizontal = 16.dp),
            verticalArrangement = Arrangement.spacedBy(14.dp)
        ) {
            item { Spacer(modifier = Modifier.height(6.dp)) }

            // Header + Cloud Run Backend Banner
            item {
                CloudRunStatusBanner(
                    cloudState = cloudState,
                    sourceLabel = telemetry.sourceLabel,
                    onConfigureClick = { viewModel.openCloudConfigDialog() },
                    onRefreshClick = { viewModel.refreshCloudRunData() }
                )
            }

            // Carrier Telemetry Metrics Grid (2x2 on phone, 4x1 on tablet)
            item {
                val formattedSessions = remember(telemetry.activeSessions) {
                    NumberFormat.getNumberInstance(Locale.US).format(telemetry.activeSessions)
                }
                val metrics = listOf(
                    TelemetryMetric("Active PPPoE", formattedSessions, "10M Scale • 128 Shards", CyanPulse, Icons.Default.Hub),
                    TelemetryMetric("Cluster Throughput", "${telemetry.clusterThroughputGbps} Gbps", "BNG Aggregate", EmeraldSla, Icons.Default.Speed),
                    TelemetryMetric("Redis Fast-Path", "${telemetry.aaaLatencyMs} ms", "${telemetry.redisHitRatePercent}% L1 Hit", AmberBilling, Icons.Default.Bolt),
                    TelemetryMetric("PON LOS Alarms", "${telemetry.losAlarmCount}", "GenieACS Telemetry", CoralDisconnect, Icons.Default.WarningAmber)
                )

                if (isWide) {
                    Row(
                        modifier = Modifier.fillMaxWidth(),
                        horizontalArrangement = Arrangement.spacedBy(10.dp)
                    ) {
                        metrics.forEach { metric ->
                            TelemetryStatCard(metric = metric, modifier = Modifier.weight(1f))
                        }
                    }
                } else {
                    Column(verticalArrangement = Arrangement.spacedBy(10.dp)) {
                        Row(
                            modifier = Modifier.fillMaxWidth(),
                            horizontalArrangement = Arrangement.spacedBy(10.dp)
                        ) {
                            TelemetryStatCard(metric = metrics[0], modifier = Modifier.weight(1f))
                            TelemetryStatCard(metric = metrics[1], modifier = Modifier.weight(1f))
                        }
                        Row(
                            modifier = Modifier.fillMaxWidth(),
                            horizontalArrangement = Arrangement.spacedBy(10.dp)
                        ) {
                            TelemetryStatCard(metric = metrics[2], modifier = Modifier.weight(1f))
                            TelemetryStatCard(metric = metrics[3], modifier = Modifier.weight(1f))
                        }
                    }
                }
            }

            // Real-Time Throughput & Storm Simulator Card
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
                        Row(
                            modifier = Modifier.fillMaxWidth(),
                            horizontalArrangement = Arrangement.SpaceBetween,
                            verticalAlignment = Alignment.CenterVertically
                        ) {
                            Column(modifier = Modifier.weight(1f)) {
                                Text(
                                    text = "Cluster Throughput & Storm Resilience",
                                    style = MaterialTheme.typography.titleMedium,
                                    color = TextPrimaryDark,
                                    fontWeight = FontWeight.Bold
                                )
                                Text(
                                    text = "FreeRADIUS rlm_redis + Citus horizontal partition telemetry",
                                    style = MaterialTheme.typography.bodySmall,
                                    color = TextSecondaryDark
                                )
                            }

                            Button(
                                onClick = { viewModel.triggerReconnectStorm() },
                                enabled = !telemetry.stormActive,
                                colors = ButtonDefaults.buttonColors(
                                    containerColor = if (telemetry.stormActive) AmberBilling else CoralDisconnect
                                ),
                                modifier = Modifier
                                    .minimumInteractiveComponentSize()
                                    .testTag("noc_storm_button")
                            ) {
                                if (telemetry.stormActive) {
                                    CircularProgressIndicator(
                                        color = TelecomNavyDark,
                                        modifier = Modifier.size(16.dp),
                                        strokeWidth = 2.dp
                                    )
                                    Spacer(modifier = Modifier.width(6.dp))
                                    Text("500k/s Storm...", color = TelecomNavyDark, fontSize = 12.sp, fontWeight = FontWeight.Bold)
                                } else {
                                    Icon(Icons.Default.Thunderstorm, contentDescription = "Simulate Reconnect Storm", modifier = Modifier.size(16.dp))
                                    Spacer(modifier = Modifier.width(6.dp))
                                    Text("Storm Test", fontSize = 12.sp, fontWeight = FontWeight.Bold)
                                }
                            }
                        }

                        Spacer(modifier = Modifier.height(12.dp))

                        ThroughputAreaChart(
                            points = telemetry.throughputHistoryGbps,
                            lineColor = if (telemetry.stormActive) AmberBilling else CyanPulse,
                            modifier = Modifier
                                .fillMaxWidth()
                                .height(110.dp)
                                .testTag("noc_throughput_chart")
                        )

                        Spacer(modifier = Modifier.height(8.dp))
                        Row(
                            modifier = Modifier.fillMaxWidth(),
                            horizontalArrangement = Arrangement.SpaceBetween
                        ) {
                            Text(
                                text = "Min: ${telemetry.throughputHistoryGbps.minOrNull()?.toInt() ?: 410} Gbps",
                                fontFamily = FontFamily.Monospace,
                                fontSize = 10.sp,
                                color = TextTertiaryDark
                            )
                            Text(
                                text = "SLA Target: < 0.4ms AAA Fast-Path",
                                fontFamily = FontFamily.Monospace,
                                fontSize = 10.sp,
                                color = EmeraldSla
                            )
                            Text(
                                text = "Peak: ${telemetry.throughputHistoryGbps.maxOrNull()?.toInt() ?: 460} Gbps",
                                fontFamily = FontFamily.Monospace,
                                fontSize = 10.sp,
                                color = CyanPulse
                            )
                        }
                    }
                }
            }

            // 128-Shard Citus Health Matrix + Shard Inspector
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
                        Row(
                            modifier = Modifier.fillMaxWidth(),
                            horizontalArrangement = Arrangement.SpaceBetween,
                            verticalAlignment = Alignment.CenterVertically
                        ) {
                            Column {
                                Text(
                                    text = "128-Shard Citus Health Matrix",
                                    style = MaterialTheme.typography.titleMedium,
                                    color = TextPrimaryDark,
                                    fontWeight = FontWeight.Bold
                                )
                                Text(
                                    text = "Tap any shard (0–127) to inspect replication & query latency",
                                    style = MaterialTheme.typography.bodySmall,
                                    color = TextSecondaryDark
                                )
                            }
                            Surface(
                                shape = RoundedCornerShape(8.dp),
                                color = EmeraldSla.copy(alpha = 0.14f)
                            ) {
                                Text(
                                    text = "${shards.count { it.status == ShardHealthStatus.HEALTHY }}/128 Healthy",
                                    fontFamily = FontFamily.Monospace,
                                    fontSize = 10.sp,
                                    color = EmeraldSla,
                                    fontWeight = FontWeight.Bold,
                                    modifier = Modifier.padding(horizontal = 8.dp, vertical = 4.dp)
                                )
                            }
                        }

                        Spacer(modifier = Modifier.height(10.dp))

                        // 16 x 8 Shard Heatmap Grid
                        Column(verticalArrangement = Arrangement.spacedBy(4.dp)) {
                            for (row in 0 until 8) {
                                Row(
                                    modifier = Modifier.fillMaxWidth(),
                                    horizontalArrangement = Arrangement.spacedBy(4.dp)
                                ) {
                                    for (col in 0 until 16) {
                                        val idx = row * 16 + col
                                        val shard = shards.getOrNull(idx)
                                        val isSelected = shard?.shardId == selectedShard?.shardId
                                        val tileColor = when (shard?.status) {
                                            ShardHealthStatus.DEGRADED -> CoralDisconnect
                                            ShardHealthStatus.ELEVATED -> AmberBilling
                                            else -> EmeraldSla
                                        }
                                        Box(
                                            modifier = Modifier
                                                .weight(1f)
                                                .aspectRatio(1f)
                                                .clip(RoundedCornerShape(3.dp))
                                                .background(tileColor.copy(alpha = if (isSelected) 0.95f else 0.42f))
                                                .then(
                                                    if (isSelected) {
                                                        Modifier.border(1.5.dp, Color.White, RoundedCornerShape(3.dp))
                                                    } else Modifier
                                                )
                                                .clickable { viewModel.selectShard(idx) }
                                                .testTag("citus_shard_$idx")
                                        )
                                    }
                                }
                            }
                        }

                        Spacer(modifier = Modifier.height(12.dp))

                        selectedShard?.let { shard ->
                            ShardInspectorBar(shard = shard)
                        }

                        Spacer(modifier = Modifier.height(10.dp))

                        // Deterministic CRC32 % 128 Key Tester
                        Row(
                            modifier = Modifier.fillMaxWidth(),
                            verticalAlignment = Alignment.CenterVertically,
                            horizontalArrangement = Arrangement.spacedBy(8.dp)
                        ) {
                            OutlinedTextField(
                                value = shardLookupInput,
                                onValueChange = { shardLookupInput = it },
                                label = { Text("CRC32 Partition Key Lookup") },
                                singleLine = true,
                                modifier = Modifier
                                    .weight(1f)
                                    .testTag("crc32_key_input")
                            )
                            Surface(
                                shape = RoundedCornerShape(10.dp),
                                color = CyanPulse.copy(alpha = 0.16f),
                                modifier = Modifier
                                    .clickable { viewModel.selectShard(hashedShardPreview) }
                                    .minimumInteractiveComponentSize()
                                    .testTag("crc32_jump_shard")
                            ) {
                                Column(
                                    modifier = Modifier.padding(horizontal = 12.dp, vertical = 8.dp),
                                    horizontalAlignment = Alignment.CenterHorizontally
                                ) {
                                    Text(
                                        text = "SHARD",
                                        fontSize = 9.sp,
                                        color = TextSecondaryDark,
                                        fontFamily = FontFamily.Monospace
                                    )
                                    Text(
                                        text = "#$hashedShardPreview",
                                        fontSize = 15.sp,
                                        fontWeight = FontWeight.Bold,
                                        color = CyanPulse,
                                        fontFamily = FontFamily.Monospace
                                    )
                                }
                            }
                        }
                    }
                }
            }

            item { Spacer(modifier = Modifier.height(24.dp)) }
        }
    }
}

private data class TelemetryMetric(
    val label: String,
    val value: String,
    val subtitle: String,
    val accent: Color,
    val icon: androidx.compose.ui.graphics.vector.ImageVector
)

@Composable
private fun TelemetryStatCard(metric: TelemetryMetric, modifier: Modifier = Modifier) {
    Card(
        modifier = modifier,
        shape = RoundedCornerShape(12.dp),
        colors = CardDefaults.cardColors(containerColor = TelecomCardDark),
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
                Text(
                    text = metric.label.uppercase(),
                    fontSize = 10.sp,
                    letterSpacing = 0.7.sp,
                    color = TextSecondaryDark,
                    fontWeight = FontWeight.SemiBold
                )
                Icon(
                    imageVector = metric.icon,
                    contentDescription = metric.label,
                    tint = metric.accent,
                    modifier = Modifier.size(16.dp)
                )
            }
            Spacer(modifier = Modifier.height(6.dp))
            Text(
                text = metric.value,
                fontSize = 20.sp,
                fontWeight = FontWeight.Bold,
                color = metric.accent
            )
            Spacer(modifier = Modifier.height(2.dp))
            Text(
                text = metric.subtitle,
                fontFamily = FontFamily.Monospace,
                fontSize = 10.sp,
                color = TextTertiaryDark
            )
        }
    }
}

@Composable
private fun ThroughputAreaChart(
    points: List<Float>,
    lineColor: Color,
    modifier: Modifier = Modifier
) {
    Canvas(modifier = modifier) {
        if (points.size < 2) return@Canvas
        val minVal = (points.minOrNull() ?: 400f) - 10f
        val maxVal = (points.maxOrNull() ?: 470f) + 10f
        val range = (maxVal - minVal).coerceAtLeast(1f)

        val stepX = size.width / (points.size - 1)
        val linePath = Path()
        val fillPath = Path()

        points.forEachIndexed { index, value ->
            val x = index * stepX
            val y = size.height - (((value - minVal) / range) * size.height * 0.85f) - (size.height * 0.08f)
            if (index == 0) {
                linePath.moveTo(x, y)
                fillPath.moveTo(x, size.height)
                fillPath.lineTo(x, y)
            } else {
                linePath.lineTo(x, y)
                fillPath.lineTo(x, y)
            }
        }
        fillPath.lineTo(size.width, size.height)
        fillPath.close()

        // Horizontal grid lines
        for (i in 1..3) {
            val gy = size.height * (i / 4f)
            drawLine(
                color = TelecomBorderDark.copy(alpha = 0.5f),
                start = Offset(0f, gy),
                end = Offset(size.width, gy),
                strokeWidth = 1.dp.toPx()
            )
        }

        drawPath(
            path = fillPath,
            brush = Brush.verticalGradient(
                colors = listOf(lineColor.copy(alpha = 0.32f), Color.Transparent)
            )
        )
        drawPath(
            path = linePath,
            color = lineColor,
            style = Stroke(width = 2.5.dp.toPx(), cap = StrokeCap.Round)
        )
    }
}

@Composable
private fun ShardInspectorBar(shard: CitusShardHealth) {
    val badgeColor = when (shard.status) {
        ShardHealthStatus.DEGRADED -> CoralDisconnect
        ShardHealthStatus.ELEVATED -> AmberBilling
        ShardHealthStatus.HEALTHY -> EmeraldSla
    }
    Surface(
        shape = RoundedCornerShape(10.dp),
        color = TelecomNavyDark,
        modifier = Modifier.fillMaxWidth()
    ) {
        Row(
            modifier = Modifier.padding(10.dp),
            horizontalArrangement = Arrangement.SpaceBetween,
            verticalAlignment = Alignment.CenterVertically
        ) {
            Column {
                Text(
                    text = "Citus Shard #${shard.shardId} • ${shard.status.name}",
                    fontFamily = FontFamily.Monospace,
                    fontSize = 12.sp,
                    fontWeight = FontWeight.Bold,
                    color = badgeColor
                )
                Text(
                    text = "Sessions: ${shard.activeSessions} | Load: ${shard.loadPercent}% | Latency: ${shard.latencyMs}ms",
                    fontFamily = FontFamily.Monospace,
                    fontSize = 10.sp,
                    color = TextSecondaryDark
                )
            }
            Surface(
                shape = RoundedCornerShape(6.dp),
                color = badgeColor.copy(alpha = 0.16f)
            ) {
                Text(
                    text = if (shard.replicaSynced) "2x Sync" else "Lagging",
                    fontFamily = FontFamily.Monospace,
                    fontSize = 10.sp,
                    color = badgeColor,
                    fontWeight = FontWeight.Bold,
                    modifier = Modifier.padding(horizontal = 8.dp, vertical = 4.dp)
                )
            }
        }
    }
}

@Composable
private fun CloudRunStatusBanner(
    cloudState: com.example.data.model.CloudRunConnectionState,
    sourceLabel: String,
    onConfigureClick: () -> Unit,
    onRefreshClick: () -> Unit
) {
    val statusColor = when (cloudState.status) {
        BackendConnectionStatus.CONNECTED -> EmeraldSla
        BackendConnectionStatus.CONNECTING -> CyanPulse
        BackendConnectionStatus.ERROR -> CoralDisconnect
        BackendConnectionStatus.MOCK_LOCAL -> AmberBilling
    }

    Card(
        modifier = Modifier.fillMaxWidth(),
        shape = RoundedCornerShape(12.dp),
        colors = CardDefaults.cardColors(containerColor = TelecomSurfaceDark),
        border = CardDefaults.outlinedCardBorder().copy(
            brush = androidx.compose.ui.graphics.SolidColor(statusColor.copy(alpha = 0.45f))
        )
    ) {
        Row(
            modifier = Modifier
                .fillMaxWidth()
                .padding(12.dp),
            verticalAlignment = Alignment.CenterVertically,
            horizontalArrangement = Arrangement.SpaceBetween
        ) {
            Row(
                verticalAlignment = Alignment.CenterVertically,
                modifier = Modifier.weight(1f)
            ) {
                Box(
                    modifier = Modifier
                        .size(10.dp)
                        .clip(CircleShape)
                        .background(statusColor)
                )
                Spacer(modifier = Modifier.width(10.dp))
                Column {
                    Text(
                        text = "Data Source: ${cloudState.status.label}",
                        style = MaterialTheme.typography.titleSmall,
                        color = TextPrimaryDark,
                        fontWeight = FontWeight.Bold
                    )
                    Text(
                        text = if (cloudState.baseUrl.isNotBlank() && cloudState.useCloudBackend) {
                            "${cloudState.baseUrl} • ${cloudState.statusMessage}"
                        } else {
                            sourceLabel
                        },
                        style = MaterialTheme.typography.bodySmall,
                        color = TextSecondaryDark,
                        maxLines = 1
                    )
                }
            }

            Row(horizontalArrangement = Arrangement.spacedBy(4.dp)) {
                IconButton(
                    onClick = onRefreshClick,
                    modifier = Modifier
                        .minimumInteractiveComponentSize()
                        .testTag("noc_refresh_button")
                ) {
                    Icon(
                        imageVector = Icons.Default.Refresh,
                        contentDescription = "Refresh NOC Telemetry",
                        tint = CyanPulse
                    )
                }
                OutlinedButton(
                    onClick = onConfigureClick,
                    modifier = Modifier
                        .minimumInteractiveComponentSize()
                        .testTag("noc_configure_cloud_run"),
                    contentPadding = PaddingValues(horizontal = 10.dp, vertical = 6.dp)
                ) {
                    Icon(Icons.Default.Cloud, contentDescription = null, modifier = Modifier.size(15.dp), tint = CyanPulse)
                    Spacer(modifier = Modifier.width(4.dp))
                    Text("Cloud Run", fontSize = 11.sp, color = CyanPulse)
                }
            }
        }
    }
}

@Composable
fun CloudRunConfigDialog(
    viewModel: IspArchitectViewModel,
    onDismiss: () -> Unit
) {
    val cloudState by viewModel.cloudRunState.collectAsStateWithLifecycle()
    var urlInput by remember(cloudState.baseUrl) { mutableStateOf(cloudState.baseUrl) }

    BackHandler(onBack = onDismiss)

    AlertDialog(
        onDismissRequest = onDismiss,
        containerColor = TelecomSurfaceDark,
        titleContentColor = TextPrimaryDark,
        textContentColor = TextSecondaryDark,
        title = {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Icon(Icons.Default.CloudQueue, contentDescription = null, tint = CyanPulse)
                Spacer(modifier = Modifier.width(8.dp))
                Text("Cloud Run Backend Endpoint", fontWeight = FontWeight.Bold, fontSize = 17.sp)
            }
        },
        text = {
            Column(verticalArrangement = Arrangement.spacedBy(10.dp)) {
                Text(
                    text = "Configure your HTTPS Cloud Run service URL. Leave blank or choose Mock State for offline local simulation. All secrets stay server-side.",
                    style = MaterialTheme.typography.bodySmall,
                    color = TextSecondaryDark
                )

                OutlinedTextField(
                    value = urlInput,
                    onValueChange = { urlInput = it },
                    label = { Text("Cloud Run HTTPS Base URL") },
                    placeholder = { Text("https://isp-billing-api-xyz.a.run.app") },
                    singleLine = true,
                    modifier = Modifier
                        .fillMaxWidth()
                        .testTag("cloud_run_url_input")
                )

                Surface(
                    shape = RoundedCornerShape(8.dp),
                    color = TelecomNavyDark,
                    modifier = Modifier.fillMaxWidth()
                ) {
                    Column(modifier = Modifier.padding(10.dp)) {
                        Text(
                            text = "Status: ${cloudState.status.label}",
                            fontFamily = FontFamily.Monospace,
                            fontSize = 11.sp,
                            color = CyanPulse,
                            fontWeight = FontWeight.Bold
                        )
                        Text(
                            text = "${cloudState.statusMessage} (${cloudState.lastCheckedLabel})",
                            fontFamily = FontFamily.Monospace,
                            fontSize = 10.sp,
                            color = TextSecondaryDark
                        )
                    }
                }
            }
        },
        confirmButton = {
            Button(
                onClick = {
                    viewModel.testCloudRunConnection(urlInput, enableCloudMode = true)
                },
                colors = ButtonDefaults.buttonColors(containerColor = CyanPulse),
                modifier = Modifier
                    .minimumInteractiveComponentSize()
                    .testTag("test_save_cloud_run_button")
            ) {
                Text("Test & Connect", color = TelecomNavyDark, fontWeight = FontWeight.Bold)
            }
        },
        dismissButton = {
            Row(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                TextButton(
                    onClick = {
                        viewModel.switchToLocalMockState()
                        onDismiss()
                    },
                    modifier = Modifier.testTag("use_mock_state_button")
                ) {
                    Text("Use Mock State", color = AmberBilling)
                }
                TextButton(onClick = onDismiss) {
                    Text("Close", color = TextSecondaryDark)
                }
            }
        }
    )
}
