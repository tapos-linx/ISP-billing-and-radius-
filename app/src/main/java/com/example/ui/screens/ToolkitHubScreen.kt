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
import androidx.compose.material.icons.filled.ContentCopy
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
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import com.example.ui.theme.*
import com.example.viewmodel.IspArchitectViewModel
import com.example.viewmodel.ToolkitSubTab

@Composable
fun ToolkitHubScreen(
    viewModel: IspArchitectViewModel,
    modifier: Modifier = Modifier
) {
    val activeSubTab by viewModel.toolkitSubTab.collectAsStateWithLifecycle()

    Column(
        modifier = modifier
            .fillMaxSize()
            .background(TelecomNavyDark)
    ) {
        Row(
            modifier = Modifier
                .fillMaxWidth()
                .background(TelecomSurfaceDark)
                .horizontalScroll(rememberScrollState())
                .padding(horizontal = 12.dp, vertical = 8.dp),
            horizontalArrangement = Arrangement.spacedBy(8.dp)
        ) {
            ToolkitSubTab.values().forEach { tab ->
                val isSelected = tab == activeSubTab
                Surface(
                    shape = RoundedCornerShape(16.dp),
                    color = if (isSelected) CyanPulse else TelecomCardDark,
                    modifier = Modifier
                        .clickable { viewModel.selectToolkitSubTab(tab) }
                        .minimumInteractiveComponentSize()
                        .testTag("toolkit_tab_${tab.name}")
                ) {
                    Box(contentAlignment = Alignment.Center, modifier = Modifier.padding(horizontal = 12.dp, vertical = 8.dp)) {
                        Text(
                            text = tab.title,
                            fontSize = 12.sp,
                            fontWeight = FontWeight.Bold,
                            color = if (isSelected) TelecomNavyDark else TextPrimaryDark
                        )
                    }
                }
            }
        }

        Box(modifier = Modifier.weight(1f).fillMaxWidth()) {
            when (activeSubTab) {
                ToolkitSubTab.TR069_ACS -> GenieAcsTr069Section(viewModel = viewModel)
                ToolkitSubTab.CGNAT_BTRC -> BtrcCgnatInspectorSection(viewModel = viewModel)
                ToolkitSubTab.VSA_COMPILER -> MultiVendorVsaCompilerSection()
                ToolkitSubTab.MATRIX -> StageMatrixScreen(viewModel = viewModel)
                ToolkitSubTab.POWERSHELL -> PowerShellVaultScreen(viewModel = viewModel)
                ToolkitSubTab.ROUTER_OS -> RouterOsBuilderSection(viewModel = viewModel)
                ToolkitSubTab.AI_ARCHITECT -> AiArchitectScreen(viewModel = viewModel)
            }
        }
    }
}

@Composable
private fun RouterOsBuilderSection(viewModel: IspArchitectViewModel) {
    val vlanId by viewModel.vlanIdInput.collectAsStateWithLifecycle()
    val ipPool by viewModel.ipPoolInput.collectAsStateWithLifecycle()
    val radiusIp by viewModel.radiusIpInput.collectAsStateWithLifecycle()
    val context = LocalContext.current

    val generatedScript = remember(vlanId, ipPool, radiusIp) {
        """
# ====================================================================
# Generated RouterOS v7 Configuration (ISP Billing Carrier Edition)
# Note: Inject RADIUS shared secret securely on the router, never in APK
# ====================================================================
/interface vlan add name=vlan$vlanId-customers vlan-id=$vlanId interface=sfp-sfpplus1 comment="Customer Access"
/ip pool add name=pool-subscribers ranges=$ipPool
/ppp profile add name=profile-radius local-address=10.100.0.1 remote-address=pool-subscribers dns-server=1.1.1.1,8.8.8.8 only-one=yes
/interface pppoe-server server add service-name=pppoe-in1 interface=vlan$vlanId-customers default-profile=profile-radius authentication=pap,chap,mschap2 disabled=no
/ppp aaa set use-radius=yes accounting=yes interim-update=5m
/radius add service=ppp address=$radiusIp secret="<SET_RADIUS_SHARED_SECRET>" authentication-port=1812 accounting-port=1813 timeout=300ms
/radius incoming set accept=yes port=3799
/ip firewall address-list add list=PAYMENT_WHITELIST address=bkash.com
/ip firewall nat add chain=dstnat action=dst-nat to-addresses=172.20.0.5 to-ports=8080 protocol=tcp dst-port=80 src-address-list=EXPIRED_USERS
        """.trimIndent()
    }

    LazyColumn(
        modifier = Modifier
            .fillMaxSize()
            .padding(16.dp),
        verticalArrangement = Arrangement.spacedBy(12.dp)
    ) {
        item {
            Text(
                text = "MikroTik RouterOS v7 Script Builder",
                style = MaterialTheme.typography.headlineSmall,
                color = TextPrimaryDark,
                fontWeight = FontWeight.Bold
            )
            Text(
                text = "Generate BNG / PPPoE / RFC 5176 UDP 3799 configuration scripts.",
                style = MaterialTheme.typography.bodySmall,
                color = TextSecondaryDark
            )
        }
        item {
            Card(
                modifier = Modifier.fillMaxWidth(),
                shape = RoundedCornerShape(12.dp),
                colors = CardDefaults.cardColors(containerColor = TelecomSurfaceDark),
                border = CardDefaults.outlinedCardBorder().copy(
                    brush = androidx.compose.ui.graphics.SolidColor(TelecomBorderDark)
                )
            ) {
                Column(modifier = Modifier.padding(14.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                    Row(modifier = Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                        OutlinedTextField(
                            value = vlanId,
                            onValueChange = { viewModel.vlanIdInput.value = it },
                            label = { Text("VLAN ID") },
                            singleLine = true,
                            modifier = Modifier.weight(1f).testTag("input_vlan")
                        )
                        OutlinedTextField(
                            value = radiusIp,
                            onValueChange = { viewModel.radiusIpInput.value = it },
                            label = { Text("FreeRADIUS IP") },
                            singleLine = true,
                            modifier = Modifier.weight(1f).testTag("input_radius_ip")
                        )
                    }
                    OutlinedTextField(
                        value = ipPool,
                        onValueChange = { viewModel.ipPoolInput.value = it },
                        label = { Text("PPPoE IP Pool") },
                        singleLine = true,
                        modifier = Modifier.fillMaxWidth().testTag("input_ip_pool")
                    )

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
                                Text("mikrotik_setup.rsc", fontFamily = FontFamily.Monospace, fontSize = 11.sp, color = CyanPulse)
                                IconButton(
                                    onClick = {
                                        val clipboard = context.getSystemService(Context.CLIPBOARD_SERVICE) as ClipboardManager
                                        clipboard.setPrimaryClip(ClipData.newPlainText("RouterOS Script", generatedScript))
                                        Toast.makeText(context, "Copied RouterOS script", Toast.LENGTH_SHORT).show()
                                    },
                                    modifier = Modifier.testTag("copy_rsc_button")
                                ) {
                                    Icon(Icons.Default.ContentCopy, contentDescription = "Copy RouterOS script", tint = CyanPulse)
                                }
                            }
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
    }
}
