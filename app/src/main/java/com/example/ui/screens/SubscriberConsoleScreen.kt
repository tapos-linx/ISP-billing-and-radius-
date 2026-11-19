package com.example.ui.screens

import androidx.activity.compose.BackHandler
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
import androidx.compose.ui.platform.testTag
import androidx.compose.ui.text.font.FontFamily
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import com.example.data.model.Subscriber
import com.example.data.model.SubscriberStatus
import com.example.ui.theme.*
import com.example.viewmodel.IspArchitectViewModel
import com.example.viewmodel.MfsSimulationMode

@Composable
fun SubscriberConsoleScreen(
    viewModel: IspArchitectViewModel,
    modifier: Modifier = Modifier
) {
    val subscribers by viewModel.subscribers.collectAsStateWithLifecycle()
    val radAcctRecords by viewModel.radAcctRecords.collectAsStateWithLifecycle()
    val searchQuery by viewModel.subscriberSearch.collectAsStateWithLifecycle()
    val statusFilter by viewModel.subscriberStatusFilter.collectAsStateWithLifecycle()
    val divisionFilter by viewModel.subscriberDivisionFilter.collectAsStateWithLifecycle()
    val portalSubscriber by viewModel.selectedPortalSubscriber.collectAsStateWithLifecycle()

    var showProvisionDialog by remember { mutableStateOf(false) }

    val divisions = remember { listOf("All", "Dhaka", "Chittagong", "Sylhet", "Rajshahi", "Khulna") }

    val filteredSubscribers = remember(subscribers, searchQuery, statusFilter, divisionFilter) {
        subscribers.filter { sub ->
            val matchesQuery = searchQuery.isBlank() ||
                sub.username.contains(searchQuery, ignoreCase = true) ||
                sub.ipAddress.contains(searchQuery, ignoreCase = true) ||
                sub.ontSerial.contains(searchQuery, ignoreCase = true) ||
                "shard #${sub.shardId}".contains(searchQuery, ignoreCase = true)
            val matchesStatus = statusFilter == null || sub.status == statusFilter
            val matchesDivision = divisionFilter == "All" || sub.division.equals(divisionFilter, ignoreCase = true)
            matchesQuery && matchesStatus && matchesDivision
        }
    }

    Column(
        modifier = modifier
            .fillMaxSize()
            .background(TelecomNavyDark)
            .padding(horizontal = 16.dp)
    ) {
        Spacer(modifier = Modifier.height(8.dp))

        Row(
            modifier = Modifier.fillMaxWidth(),
            horizontalArrangement = Arrangement.SpaceBetween,
            verticalAlignment = Alignment.CenterVertically
        ) {
            Column(modifier = Modifier.weight(1f)) {
                Text(
                    text = "Subscriber Portal & Directory",
                    style = MaterialTheme.typography.headlineSmall,
                    color = TextPrimaryDark,
                    fontWeight = FontWeight.Bold
                )
                Text(
                    text = "Citus 128-Shard • TR-069 ONT • BTRC RFC 6888 CGNAT",
                    style = MaterialTheme.typography.bodySmall,
                    color = TextSecondaryDark
                )
            }

            Button(
                onClick = { showProvisionDialog = true },
                colors = ButtonDefaults.buttonColors(containerColor = CyanPulse),
                contentPadding = PaddingValues(horizontal = 12.dp, vertical = 8.dp),
                modifier = Modifier
                    .minimumInteractiveComponentSize()
                    .testTag("open_provision_ont_button")
            ) {
                Icon(Icons.Default.Add, contentDescription = "Provision Subscriber", tint = TelecomNavyDark, modifier = Modifier.size(16.dp))
                Spacer(modifier = Modifier.width(4.dp))
                Text("Provision", color = TelecomNavyDark, fontWeight = FontWeight.Bold, fontSize = 12.sp)
            }
        }

        Spacer(modifier = Modifier.height(10.dp))

        // Search input
        OutlinedTextField(
            value = searchQuery,
            onValueChange = { viewModel.updateSubscriberSearch(it) },
            placeholder = { Text("Search username, IP, ONT serial, or Shard...") },
            leadingIcon = { Icon(Icons.Default.Search, contentDescription = "Search", tint = CyanPulse) },
            trailingIcon = {
                if (searchQuery.isNotEmpty()) {
                    IconButton(onClick = { viewModel.updateSubscriberSearch("") }) {
                        Icon(Icons.Default.Clear, contentDescription = "Clear search", tint = TextSecondaryDark)
                    }
                }
            },
            singleLine = true,
            modifier = Modifier
                .fillMaxWidth()
                .testTag("subscriber_search_input")
        )

        Spacer(modifier = Modifier.height(8.dp))

        // Status & Division Filters
        Row(
            modifier = Modifier
                .fillMaxWidth()
                .horizontalScroll(rememberScrollState()),
            horizontalArrangement = Arrangement.spacedBy(6.dp)
        ) {
            FilterChip(
                selected = statusFilter == null,
                onClick = { viewModel.updateSubscriberStatusFilter(null) },
                label = { Text("All (${subscribers.size})", fontSize = 11.sp) }
            )
            listOf(SubscriberStatus.ACTIVE, SubscriberStatus.EXPIRED).forEach { st ->
                FilterChip(
                    selected = statusFilter == st,
                    onClick = {
                        viewModel.updateSubscriberStatusFilter(if (statusFilter == st) null else st)
                    },
                    label = { Text(st.name, fontSize = 11.sp) }
                )
            }
            divisions.filter { it != "All" }.forEach { div ->
                FilterChip(
                    selected = divisionFilter == div,
                    onClick = {
                        viewModel.updateSubscriberDivisionFilter(if (divisionFilter == div) "All" else div)
                    },
                    label = { Text(div, fontSize = 11.sp) }
                )
            }
        }

        Spacer(modifier = Modifier.height(10.dp))

        LazyColumn(
            modifier = Modifier.fillMaxSize(),
            verticalArrangement = Arrangement.spacedBy(12.dp)
        ) {
            if (filteredSubscribers.isEmpty()) {
                item {
                    Card(
                        modifier = Modifier.fillMaxWidth(),
                        shape = RoundedCornerShape(12.dp),
                        colors = CardDefaults.cardColors(containerColor = TelecomSurfaceDark)
                    ) {
                        Column(
                            modifier = Modifier
                                .fillMaxWidth()
                                .padding(24.dp),
                            horizontalAlignment = Alignment.CenterHorizontally
                        ) {
                            Icon(Icons.Default.PersonSearch, contentDescription = null, tint = TextTertiaryDark, modifier = Modifier.size(36.dp))
                            Spacer(modifier = Modifier.height(8.dp))
                            Text("No subscribers match your filter.", color = TextSecondaryDark)
                        }
                    }
                }
            } else {
                items(filteredSubscribers, key = { it.id }) { subscriber ->
                    SubscriberCard(subscriber = subscriber, viewModel = viewModel)
                }
            }

            // ClickHouse / Partitioned radacct section
            item {
                Spacer(modifier = Modifier.height(6.dp))
                Text(
                    text = "ClickHouse / PG16 Accounting: radacct_y2026m11",
                    style = MaterialTheme.typography.titleMedium,
                    color = TextPrimaryDark,
                    fontWeight = FontWeight.Bold
                )
                Text(
                    text = "BTRC CGNAT session attribution & monthly range partition",
                    style = MaterialTheme.typography.bodySmall,
                    color = TextTertiaryDark
                )
            }

            items(radAcctRecords, key = { it.radacctId }) { record ->
                Card(
                    modifier = Modifier.fillMaxWidth(),
                    shape = RoundedCornerShape(10.dp),
                    colors = CardDefaults.cardColors(containerColor = TelecomSurfaceDark),
                    border = CardDefaults.outlinedCardBorder().copy(
                        brush = androidx.compose.ui.graphics.SolidColor(TelecomBorderDark)
                    )
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
                        Text(
                            text = "User: ${record.username} • Framed-IP: ${record.framedIpAddress} • Uptime: ${record.acctSessionTime / 3600}h",
                            style = MaterialTheme.typography.bodySmall,
                            color = TextPrimaryDark
                        )
                        val inGb = "%.2f".format(record.acctInputOctets / (1024.0 * 1024 * 1024))
                        val outGb = "%.2f".format(record.acctOutputOctets / (1024.0 * 1024 * 1024))
                        Text(
                            text = "In: $inGb GB | Out: $outGb GB (NAS: ${record.nasIpAddress})",
                            fontFamily = FontFamily.Monospace,
                            fontSize = 10.sp,
                            color = TextTertiaryDark
                        )
                    }
                }
            }

            item { Spacer(modifier = Modifier.height(24.dp)) }
        }
    }

    portalSubscriber?.let { selected ->
        SubscriberSelfCarePortalDialog(
            subscriber = selected,
            onPayWithMfs = { gw ->
                viewModel.triggerPaymentWebhook(
                    gateway = gw,
                    username = selected.username,
                    amount = selected.monthlyFee,
                    mode = MfsSimulationMode.VALID_UNBLOCK
                )
            },
            onDismiss = { viewModel.closeSubscriberPortal() }
        )
    }

    if (showProvisionDialog) {
        ProvisionOntDialog(
            onProvision = { user, div, prof, rate, fee ->
                viewModel.provisionSubscriber(user, div, prof, rate, fee)
                showProvisionDialog = false
            },
            onDismiss = { showProvisionDialog = false }
        )
    }
}

@Composable
private fun SubscriberCard(subscriber: Subscriber, viewModel: IspArchitectViewModel) {
    val statusColor = when (subscriber.status) {
        SubscriberStatus.ACTIVE -> EmeraldSla
        SubscriberStatus.EXPIRED -> CoralDisconnect
        SubscriberStatus.SUSPENDED,
        SubscriberStatus.DISABLED -> TextTertiaryDark
    }
    val opticalColor = if (subscriber.opticalRxDbm < -25.0) CoralDisconnect else EmeraldSla

    Card(
        modifier = Modifier
            .fillMaxWidth()
            .clickable { viewModel.openSubscriberPortal(subscriber) }
            .testTag("subscriber_card_${subscriber.username}"),
        shape = RoundedCornerShape(12.dp),
        colors = CardDefaults.cardColors(containerColor = TelecomCardDark),
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
                    Row(verticalAlignment = Alignment.CenterVertically) {
                        Text(
                            text = subscriber.username,
                            style = MaterialTheme.typography.titleMedium,
                            color = TextPrimaryDark,
                            fontWeight = FontWeight.Bold
                        )
                        Spacer(modifier = Modifier.width(6.dp))
                        Surface(
                            shape = RoundedCornerShape(6.dp),
                            color = CyanPulse.copy(alpha = 0.14f)
                        ) {
                            Text(
                                text = "Shard #${subscriber.shardId}",
                                fontFamily = FontFamily.Monospace,
                                fontSize = 9.sp,
                                color = CyanPulse,
                                fontWeight = FontWeight.Bold,
                                modifier = Modifier.padding(horizontal = 6.dp, vertical = 2.dp)
                            )
                        }
                    }
                    Text(
                        text = "${subscriber.division} • ${subscriber.profile} • Tk ${subscriber.monthlyFee.toInt()}/mo",
                        style = MaterialTheme.typography.bodySmall,
                        color = TextSecondaryDark
                    )
                }

                Surface(
                    shape = RoundedCornerShape(12.dp),
                    color = statusColor.copy(alpha = 0.15f),
                    border = CardDefaults.outlinedCardBorder().copy(
                        brush = androidx.compose.ui.graphics.SolidColor(statusColor)
                    )
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

            Surface(
                shape = RoundedCornerShape(6.dp),
                color = TelecomNavyDark,
                modifier = Modifier.fillMaxWidth()
            ) {
                Column(modifier = Modifier.padding(8.dp)) {
                    Text(
                        text = "Framed-IP: ${subscriber.ipAddress} | ONT: ${subscriber.ontSerial} (${subscriber.opticalRxDbm} dBm)",
                        fontFamily = FontFamily.Monospace,
                        fontSize = 10.sp,
                        color = opticalColor
                    )
                    Text(
                        text = "CGNAT RFC 6888: ${subscriber.cgnatPublicIp} [Ports ${subscriber.cgnatPortStart}–${subscriber.cgnatPortEnd}]",
                        fontFamily = FontFamily.Monospace,
                        fontSize = 10.sp,
                        color = CyanPulse
                    )
                    Text(
                        text = "Rate: ${subscriber.rateLimit} | Expiry: ${subscriber.expiryDate}",
                        fontFamily = FontFamily.Monospace,
                        fontSize = 10.sp,
                        color = if (subscriber.status == SubscriberStatus.EXPIRED) CoralDisconnect else TextSecondaryDark
                    )
                }
            }

            Spacer(modifier = Modifier.height(10.dp))

            Row(
                modifier = Modifier.fillMaxWidth(),
                horizontalArrangement = Arrangement.spacedBy(8.dp)
            ) {
                OutlinedButton(
                    onClick = { viewModel.openSubscriberPortal(subscriber) },
                    modifier = Modifier
                        .weight(1f)
                        .minimumInteractiveComponentSize()
                        .testTag("portal_button_${subscriber.username}"),
                    contentPadding = PaddingValues(horizontal = 8.dp, vertical = 6.dp)
                ) {
                    Icon(Icons.Default.AccountCircle, contentDescription = null, modifier = Modifier.size(14.dp), tint = CyanPulse)
                    Spacer(modifier = Modifier.width(4.dp))
                    Text("Self-Care", fontSize = 11.sp, color = CyanPulse)
                }

                Button(
                    onClick = { viewModel.triggerCoaKick(subscriber.username, subscriber.ipAddress) },
                    modifier = Modifier
                        .weight(1f)
                        .minimumInteractiveComponentSize()
                        .testTag("kick_button_${subscriber.username}"),
                    colors = ButtonDefaults.buttonColors(containerColor = CoralDisconnect.copy(alpha = 0.85f)),
                    contentPadding = PaddingValues(horizontal = 8.dp, vertical = 6.dp)
                ) {
                    Icon(Icons.Default.FlashOn, contentDescription = null, modifier = Modifier.size(14.dp))
                    Spacer(modifier = Modifier.width(4.dp))
                    Text("PoD 3799", fontSize = 11.sp)
                }

                Button(
                    onClick = { viewModel.triggerPaymentWebhook("bKash", subscriber.username, subscriber.monthlyFee) },
                    modifier = Modifier
                        .weight(1f)
                        .minimumInteractiveComponentSize()
                        .testTag("reconnect_button_${subscriber.username}"),
                    colors = ButtonDefaults.buttonColors(containerColor = AmberBilling),
                    contentPadding = PaddingValues(horizontal = 8.dp, vertical = 6.dp)
                ) {
                    Icon(Icons.Default.Bolt, contentDescription = null, tint = TelecomNavyDark, modifier = Modifier.size(14.dp))
                    Spacer(modifier = Modifier.width(4.dp))
                    Text("bKash", color = TelecomNavyDark, fontSize = 11.sp, fontWeight = FontWeight.Bold)
                }
            }
        }
    }
}

@Composable
private fun SubscriberSelfCarePortalDialog(
    subscriber: Subscriber,
    onPayWithMfs: (String) -> Unit,
    onDismiss: () -> Unit
) {
    BackHandler(onBack = onDismiss)
    val inGb = "%.2f".format(subscriber.inputBytes / (1024.0 * 1024 * 1024))
    val outGb = "%.2f".format(subscriber.outputBytes / (1024.0 * 1024 * 1024))

    AlertDialog(
        onDismissRequest = onDismiss,
        containerColor = TelecomSurfaceDark,
        titleContentColor = TextPrimaryDark,
        textContentColor = TextSecondaryDark,
        title = {
            Column {
                Text("Subscriber Self-Care Portal", fontWeight = FontWeight.Bold, fontSize = 17.sp)
                Text(
                    text = "${subscriber.username} • Citus Shard #${subscriber.shardId}",
                    fontFamily = FontFamily.Monospace,
                    fontSize = 11.sp,
                    color = CyanPulse
                )
            }
        },
        text = {
            Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                Surface(shape = RoundedCornerShape(8.dp), color = TelecomNavyDark, modifier = Modifier.fillMaxWidth()) {
                    Column(modifier = Modifier.padding(10.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                        Text("Account Status: ${subscriber.status.name}", color = if (subscriber.status == SubscriberStatus.ACTIVE) EmeraldSla else CoralDisconnect, fontWeight = FontWeight.Bold, fontSize = 12.sp)
                        Text("Package: ${subscriber.profile} (Tk ${subscriber.monthlyFee.toInt()}/mo)", fontSize = 11.sp, color = TextPrimaryDark)
                        Text("Billing Cycle Expiry: ${subscriber.expiryDate}", fontSize = 11.sp, color = TextSecondaryDark)
                        Text("Session Usage: ↓ $outGb GB / ↑ $inGb GB", fontFamily = FontFamily.Monospace, fontSize = 11.sp, color = CyanPulse)
                        Text("TR-069 ONT: ${subscriber.ontSerial} (${subscriber.opticalRxDbm} dBm)", fontFamily = FontFamily.Monospace, fontSize = 10.sp, color = TextSecondaryDark)
                        Text("BTRC CGNAT: ${subscriber.cgnatPublicIp}:${subscriber.cgnatPortStart}-${subscriber.cgnatPortEnd}", fontFamily = FontFamily.Monospace, fontSize = 10.sp, color = AmberBilling)
                    }
                }
                Text("Instant MFS Bill Settlement (<3s Auto-Unblock):", fontSize = 11.sp, color = TextPrimaryDark, fontWeight = FontWeight.SemiBold)
                Row(modifier = Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    Button(
                        onClick = { onPayWithMfs("bKash") },
                        colors = ButtonDefaults.buttonColors(containerColor = AmberBilling),
                        modifier = Modifier.weight(1f).testTag("portal_pay_bkash")
                    ) {
                        Text("Pay bKash", color = TelecomNavyDark, fontWeight = FontWeight.Bold, fontSize = 12.sp)
                    }
                    Button(
                        onClick = { onPayWithMfs("Nagad") },
                        colors = ButtonDefaults.buttonColors(containerColor = EmeraldSla),
                        modifier = Modifier.weight(1f).testTag("portal_pay_nagad")
                    ) {
                        Text("Pay Nagad", color = TelecomNavyDark, fontWeight = FontWeight.Bold, fontSize = 12.sp)
                    }
                }
            }
        },
        confirmButton = {
            TextButton(onClick = onDismiss) {
                Text("Close", color = CyanPulse)
            }
        }
    )
}

@Composable
private fun ProvisionOntDialog(
    onProvision: (String, String, String, String, Double) -> Unit,
    onDismiss: () -> Unit
) {
    BackHandler(onBack = onDismiss)
    var username by remember { mutableStateOf("dhaka_fiber_new") }
    var division by remember { mutableStateOf("Dhaka") }
    var profile by remember { mutableStateOf("30M_Fiber_Carrier") }
    var rateLimit by remember { mutableStateOf("30M/30M") }
    var fee by remember { mutableStateOf("1500") }

    AlertDialog(
        onDismissRequest = onDismiss,
        containerColor = TelecomSurfaceDark,
        titleContentColor = TextPrimaryDark,
        title = { Text("TR-069 ONT Provisioning", fontWeight = FontWeight.Bold, fontSize = 17.sp) },
        text = {
            Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                OutlinedTextField(value = username, onValueChange = { username = it }, label = { Text("PPPoE Username") }, singleLine = true)
                OutlinedTextField(value = division, onValueChange = { division = it }, label = { Text("Division (Dhaka/Chittagong/...)") }, singleLine = true)
                OutlinedTextField(value = profile, onValueChange = { profile = it }, label = { Text("Tariff Profile") }, singleLine = true)
                Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    OutlinedTextField(value = rateLimit, onValueChange = { rateLimit = it }, label = { Text("Rate Limit") }, singleLine = true, modifier = Modifier.weight(1f))
                    OutlinedTextField(value = fee, onValueChange = { fee = it }, label = { Text("BDT") }, singleLine = true, modifier = Modifier.weight(0.7f))
                }
            }
        },
        confirmButton = {
            Button(
                onClick = { onProvision(username, division, profile, rateLimit, fee.toDoubleOrNull() ?: 1500.0) },
                colors = ButtonDefaults.buttonColors(containerColor = CyanPulse),
                modifier = Modifier.testTag("confirm_provision_button")
            ) {
                Text("Assign Shard & Provision", color = TelecomNavyDark, fontWeight = FontWeight.Bold)
            }
        },
        dismissButton = {
            TextButton(onClick = onDismiss) {
                Text("Cancel", color = TextSecondaryDark)
            }
        }
    )
}
