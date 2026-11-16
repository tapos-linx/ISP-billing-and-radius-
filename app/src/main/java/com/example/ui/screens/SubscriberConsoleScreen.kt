package com.example.ui.screens

import androidx.compose.foundation.background
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.*
import androidx.compose.material3.*
import androidx.compose.runtime.Composable
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.testTag
import androidx.compose.ui.text.font.FontFamily
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.example.data.model.Subscriber
import com.example.data.model.SubscriberStatus
import com.example.ui.theme.*
import com.example.viewmodel.IspArchitectViewModel

@Composable
fun SubscriberConsoleScreen(
    viewModel: IspArchitectViewModel,
    modifier: Modifier = Modifier
) {
    val subscribers by viewModel.subscribers.collectAsState()
    val radAcctRecords by viewModel.radAcctRecords.collectAsState()

    Column(
        modifier = modifier
            .fillMaxSize()
            .background(TelecomNavyDark)
            .padding(horizontal = 16.dp)
    ) {
        Spacer(modifier = Modifier.height(8.dp))

        Text(
            text = "Subscribers & RADIUS Accounting",
            style = MaterialTheme.typography.headlineSmall,
            color = TextPrimaryDark,
            fontWeight = FontWeight.Bold
        )
        Text(
            text = "radcheck • radreply • Monthly Partitioned radacct (y2026m10)",
            style = MaterialTheme.typography.bodySmall,
            color = TextSecondaryDark
        )

        Spacer(modifier = Modifier.height(14.dp))

        LazyColumn(
            modifier = Modifier.fillMaxSize(),
            verticalArrangement = Arrangement.spacedBy(14.dp)
        ) {
            item {
                Row(
                    modifier = Modifier.fillMaxWidth(),
                    horizontalArrangement = Arrangement.SpaceBetween,
                    verticalAlignment = Alignment.CenterVertically
                ) {
                    Text(
                        text = "Active PPPoE Subscribers (${subscribers.size})",
                        style = MaterialTheme.typography.titleMedium,
                        color = TextPrimaryDark,
                        fontWeight = FontWeight.Bold
                    )

                    Surface(
                        shape = RoundedCornerShape(12.dp),
                        color = EmeraldSla.copy(alpha = 0.15f)
                    ) {
                        Text(
                            text = "Dual-Sync Observers Live",
                            style = MaterialTheme.typography.labelSmall,
                            color = EmeraldSla,
                            modifier = Modifier.padding(horizontal = 8.dp, vertical = 3.dp),
                            fontWeight = FontWeight.Bold
                        )
                    }
                }
            }

            items(subscribers) { subscriber ->
                SubscriberCard(subscriber = subscriber, viewModel = viewModel)
            }

            // Partitioned radacct section
            item {
                Spacer(modifier = Modifier.height(8.dp))
                Text(
                    text = "PostgreSQL 16 Partitioned radacct: radacct_y2026m10",
                    style = MaterialTheme.typography.titleMedium,
                    color = TextPrimaryDark,
                    fontWeight = FontWeight.Bold
                )
                Text(
                    text = "Range partition for 2026-10-01 to 2026-11-01 (<10ms query index)",
                    style = MaterialTheme.typography.bodySmall,
                    color = TextTertiaryDark
                )
            }

            items(radAcctRecords) { record ->
                Card(
                    modifier = Modifier.fillMaxWidth(),
                    shape = RoundedCornerShape(10.dp),
                    colors = CardDefaults.cardColors(containerColor = TelecomSurfaceDark),
                    border = CardDefaults.outlinedCardBorder().copy(brush = androidx.compose.ui.graphics.SolidColor(TelecomBorderDark))
                ) {
                    Column(modifier = Modifier.padding(12.dp)) {
                        Row(
                            modifier = Modifier.fillMaxWidth(),
                            horizontalArrangement = Arrangement.SpaceBetween
                        ) {
                            Text(
                                text = "Session: ${record.acctSessionId}",
                                fontFamily = FontFamily.Monospace,
                                fontSize = 11.sp,
                                color = CyanPulse,
                                fontWeight = FontWeight.Bold
                            )
                            Text(
                                text = record.partitionTable,
                                fontFamily = FontFamily.Monospace,
                                fontSize = 10.sp,
                                color = AmberBilling
                            )
                        }

                        Spacer(modifier = Modifier.height(4.dp))
                        Row(
                            modifier = Modifier.fillMaxWidth(),
                            horizontalArrangement = Arrangement.SpaceBetween
                        ) {
                            Text(
                                text = "User: ${record.username} • IP: ${record.framedIpAddress}",
                                style = MaterialTheme.typography.bodySmall,
                                color = TextPrimaryDark
                            )
                            Text(
                                text = "Uptime: ${record.acctSessionTime / 3600}h",
                                style = MaterialTheme.typography.bodySmall,
                                color = TextSecondaryDark
                            )
                        }

                        Spacer(modifier = Modifier.height(4.dp))
                        val inGb = "%.2f".format(record.acctInputOctets / (1024.0 * 1024 * 1024))
                        val outGb = "%.2f".format(record.acctOutputOctets / (1024.0 * 1024 * 1024))
                        Text(
                            text = "Data Transferred: In: $inGb GB | Out: $outGb GB (NAS: ${record.nasIpAddress})",
                            fontFamily = FontFamily.Monospace,
                            fontSize = 10.sp,
                            color = TextTertiaryDark
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

@Composable
private fun SubscriberCard(subscriber: Subscriber, viewModel: IspArchitectViewModel) {
    val statusColor = when (subscriber.status) {
        SubscriberStatus.ACTIVE -> EmeraldSla
        SubscriberStatus.EXPIRED -> CoralDisconnect
        SubscriberStatus.SUSPENDED -> TextTertiaryDark
    }

    Card(
        modifier = Modifier
            .fillMaxWidth()
            .testTag("subscriber_card_${subscriber.username}"),
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
                Column {
                    Text(
                        text = subscriber.username,
                        style = MaterialTheme.typography.titleMedium,
                        color = TextPrimaryDark,
                        fontWeight = FontWeight.Bold
                    )
                    Text(
                        text = "${subscriber.profile} • Tk ${subscriber.monthlyFee.toInt()}/mo",
                        style = MaterialTheme.typography.bodySmall,
                        color = TextSecondaryDark
                    )
                }

                Surface(
                    shape = RoundedCornerShape(12.dp),
                    color = statusColor.copy(alpha = 0.15f),
                    border = CardDefaults.outlinedCardBorder().copy(brush = androidx.compose.ui.graphics.SolidColor(statusColor))
                ) {
                    Text(
                        text = subscriber.status.name,
                        style = MaterialTheme.typography.labelSmall,
                        color = statusColor,
                        modifier = Modifier.padding(horizontal = 8.dp, vertical = 3.dp),
                        fontWeight = FontWeight.Bold
                    )
                }
            }

            Spacer(modifier = Modifier.height(8.dp))

            // Tech specs block
            Surface(
                shape = RoundedCornerShape(6.dp),
                color = TelecomNavyDark,
                modifier = Modifier.fillMaxWidth()
            ) {
                Column(modifier = Modifier.padding(8.dp)) {
                    Text(
                        text = "Framed-IP: ${subscriber.ipAddress} | MAC: ${subscriber.macAddress}",
                        fontFamily = FontFamily.Monospace,
                        fontSize = 11.sp,
                        color = CyanPulse
                    )
                    Text(
                        text = "Rate-Limit: ${subscriber.rateLimit}",
                        fontFamily = FontFamily.Monospace,
                        fontSize = 10.sp,
                        color = TextSecondaryDark
                    )
                    Text(
                        text = "Expiry: ${subscriber.expiryDate} (Last Paid: ${subscriber.lastPaymentDate})",
                        fontFamily = FontFamily.Monospace,
                        fontSize = 10.sp,
                        color = if (subscriber.status == SubscriberStatus.EXPIRED) CoralDisconnect else TextTertiaryDark
                    )
                }
            }

            Spacer(modifier = Modifier.height(10.dp))

            // Operator Actions
            Row(
                modifier = Modifier.fillMaxWidth(),
                horizontalArrangement = Arrangement.spacedBy(8.dp)
            ) {
                Button(
                    onClick = { viewModel.triggerCoaKick(subscriber.username, subscriber.ipAddress) },
                    modifier = Modifier.weight(1f).testTag("kick_button_${subscriber.username}"),
                    colors = ButtonDefaults.buttonColors(containerColor = CoralDisconnect.copy(alpha = 0.85f)),
                    contentPadding = PaddingValues(horizontal = 8.dp, vertical = 6.dp)
                ) {
                    Icon(imageVector = Icons.Default.FlashOn, contentDescription = null, modifier = Modifier.size(14.dp))
                    Spacer(modifier = Modifier.width(4.dp))
                    Text("Kick (PoD 3799)", fontSize = 11.sp)
                }

                Button(
                    onClick = { viewModel.triggerPaymentWebhook("bKash", subscriber.username, subscriber.monthlyFee) },
                    modifier = Modifier.weight(1f).testTag("reconnect_button_${subscriber.username}"),
                    colors = ButtonDefaults.buttonColors(containerColor = AmberBilling),
                    contentPadding = PaddingValues(horizontal = 8.dp, vertical = 6.dp)
                ) {
                    Icon(imageVector = Icons.Default.Bolt, contentDescription = null, tint = TelecomNavyDark, modifier = Modifier.size(14.dp))
                    Spacer(modifier = Modifier.width(4.dp))
                    Text("bKash Reconnect", color = TelecomNavyDark, fontSize = 11.sp, fontWeight = FontWeight.Bold)
                }
            }
        }
    }
}
