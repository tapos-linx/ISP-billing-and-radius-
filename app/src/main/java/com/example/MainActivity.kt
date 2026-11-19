package com.example

import android.os.Bundle
import androidx.activity.ComponentActivity
import androidx.activity.compose.BackHandler
import androidx.activity.compose.setContent
import androidx.activity.enableEdgeToEdge
import androidx.activity.viewModels
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.*
import androidx.compose.material3.*
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.testTag
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import com.example.data.model.BackendConnectionStatus
import com.example.ui.screens.*
import com.example.ui.theme.*
import com.example.viewmodel.AppNavTab
import com.example.viewmodel.IspArchitectViewModel

class MainActivity : ComponentActivity() {

    private val viewModel: IspArchitectViewModel by viewModels()

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        enableEdgeToEdge()
        setContent {
            MyApplicationTheme {
                MainAppScaffold(viewModel = viewModel)
            }
        }
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun MainAppScaffold(viewModel: IspArchitectViewModel) {
    val context = LocalContext.current
    LaunchedEffect(Unit) {
        viewModel.attachContext(context)
    }

    val currentTab by viewModel.currentTab.collectAsStateWithLifecycle()
    val cloudState by viewModel.cloudRunState.collectAsStateWithLifecycle()
    val showCloudDialog by viewModel.isCloudConfigDialogOpen.collectAsStateWithLifecycle()

    BackHandler(enabled = currentTab != AppNavTab.NOC) {
        viewModel.selectTab(AppNavTab.NOC)
    }

    val navItems = listOf(
        Triple(AppNavTab.NOC, Icons.Default.Hub, "nav_tab_noc"),
        Triple(AppNavTab.SUBSCRIBERS, Icons.Default.People, "nav_tab_subscribers"),
        Triple(AppNavTab.MFS_WEBHOOK, Icons.Default.Payments, "nav_tab_mfs"),
        Triple(AppNavTab.COA_CONTROLS, Icons.Default.FlashOn, "nav_tab_coa"),
        Triple(AppNavTab.TOOLKIT, Icons.Default.Terminal, "nav_tab_toolkit")
    )

    val modeColor = when (cloudState.status) {
        BackendConnectionStatus.CONNECTED -> EmeraldSla
        BackendConnectionStatus.CONNECTING -> CyanPulse
        BackendConnectionStatus.ERROR -> CoralDisconnect
        BackendConnectionStatus.MOCK_LOCAL -> AmberBilling
    }

    BoxWithConstraints(modifier = Modifier.fillMaxSize()) {
        val useRail = maxWidth >= 600.dp

        Scaffold(
            modifier = Modifier.fillMaxSize(),
            containerColor = TelecomNavyDark,
            topBar = {
                TopAppBar(
                    title = {
                        Row(
                            verticalAlignment = Alignment.CenterVertically,
                            modifier = Modifier.fillMaxWidth()
                        ) {
                            Surface(
                                shape = CircleShape,
                                color = CyanPulse.copy(alpha = 0.2f),
                                modifier = Modifier.size(34.dp)
                            ) {
                                Box(contentAlignment = Alignment.Center) {
                                    Icon(
                                        imageVector = Icons.Default.Router,
                                        contentDescription = "ISP Billing Carrier Edition",
                                        tint = CyanPulse,
                                        modifier = Modifier.size(18.dp)
                                    )
                                }
                            }
                            Spacer(modifier = Modifier.width(10.dp))
                            Column {
                                Row(verticalAlignment = Alignment.CenterVertically) {
                                    Text(
                                        text = "ISP Billing",
                                        fontWeight = FontWeight.Bold,
                                        fontSize = 16.sp,
                                        color = TextPrimaryDark
                                    )
                                    Spacer(modifier = Modifier.width(6.dp))
                                    Surface(
                                        shape = RoundedCornerShape(4.dp),
                                        color = CyanPulse.copy(alpha = 0.16f)
                                    ) {
                                        Text(
                                            text = "CARRIER",
                                            color = CyanPulse,
                                            fontSize = 9.sp,
                                            fontWeight = FontWeight.Bold,
                                            modifier = Modifier.padding(horizontal = 5.dp, vertical = 1.dp)
                                        )
                                    }
                                }
                                Text(
                                    text = currentTab.title,
                                    fontSize = 11.sp,
                                    color = TextSecondaryDark
                                )
                            }
                        }
                    },
                    actions = {
                        Surface(
                            shape = RoundedCornerShape(12.dp),
                            color = modeColor.copy(alpha = 0.15f),
                            border = CardDefaults.outlinedCardBorder().copy(
                                brush = androidx.compose.ui.graphics.SolidColor(modeColor)
                            ),
                            modifier = Modifier
                                .padding(end = 12.dp)
                                .clickable { viewModel.openCloudConfigDialog() }
                                .minimumInteractiveComponentSize()
                                .testTag("topbar_backend_pill")
                        ) {
                            Row(
                                verticalAlignment = Alignment.CenterVertically,
                                modifier = Modifier.padding(horizontal = 10.dp, vertical = 5.dp)
                            ) {
                                Surface(
                                    shape = CircleShape,
                                    color = modeColor,
                                    modifier = Modifier.size(6.dp)
                                ) {}
                                Spacer(modifier = Modifier.width(6.dp))
                                Text(
                                    text = if (cloudState.status == BackendConnectionStatus.CONNECTED) "CLOUD RUN" else "MOCK",
                                    fontSize = 10.sp,
                                    fontWeight = FontWeight.Bold,
                                    color = modeColor
                                )
                            }
                        }
                    },
                    colors = TopAppBarDefaults.topAppBarColors(
                        containerColor = TelecomSurfaceDark
                    )
                )
            },
            bottomBar = {
                if (!useRail) {
                    NavigationBar(
                        containerColor = TelecomSurfaceDark,
                        contentColor = TextPrimaryDark,
                        windowInsets = WindowInsets.navigationBars,
                        modifier = Modifier.testTag("main_bottom_nav")
                    ) {
                        navItems.forEach { (tab, icon, tag) ->
                            NavigationBarItem(
                                selected = currentTab == tab,
                                onClick = { viewModel.selectTab(tab) },
                                icon = { Icon(icon, contentDescription = tab.title) },
                                label = { Text(tab.shortLabel, fontSize = 10.sp, maxLines = 1) },
                                colors = NavigationBarItemDefaults.colors(
                                    selectedIconColor = TelecomNavyDark,
                                    selectedTextColor = CyanPulse,
                                    indicatorColor = CyanPulse,
                                    unselectedIconColor = TextSecondaryDark,
                                    unselectedTextColor = TextSecondaryDark
                                ),
                                modifier = Modifier.testTag(tag)
                            )
                        }
                    }
                }
            }
        ) { innerPadding ->
            Row(
                modifier = Modifier
                    .fillMaxSize()
                    .padding(innerPadding)
            ) {
                if (useRail) {
                    NavigationRail(
                        containerColor = TelecomSurfaceDark,
                        contentColor = TextPrimaryDark,
                        modifier = Modifier.testTag("main_nav_rail")
                    ) {
                        Spacer(modifier = Modifier.height(8.dp))
                        navItems.forEach { (tab, icon, tag) ->
                            NavigationRailItem(
                                selected = currentTab == tab,
                                onClick = { viewModel.selectTab(tab) },
                                icon = { Icon(icon, contentDescription = tab.title) },
                                label = { Text(tab.shortLabel, fontSize = 10.sp) },
                                colors = NavigationRailItemDefaults.colors(
                                    selectedIconColor = TelecomNavyDark,
                                    selectedTextColor = CyanPulse,
                                    indicatorColor = CyanPulse,
                                    unselectedIconColor = TextSecondaryDark,
                                    unselectedTextColor = TextSecondaryDark
                                ),
                                modifier = Modifier.testTag(tag)
                            )
                        }
                    }
                }

                Box(
                    modifier = Modifier
                        .weight(1f)
                        .fillMaxHeight()
                ) {
                    when (currentTab) {
                        AppNavTab.NOC -> NocCommandCenterScreen(viewModel = viewModel)
                        AppNavTab.SUBSCRIBERS -> SubscriberConsoleScreen(viewModel = viewModel)
                        AppNavTab.MFS_WEBHOOK -> MfsWebhookMonitorScreen(viewModel = viewModel)
                        AppNavTab.COA_CONTROLS -> FreeRadiusCoaScreen(viewModel = viewModel)
                        AppNavTab.TOOLKIT -> ToolkitHubScreen(viewModel = viewModel)
                    }
                }
            }
        }
    }

    if (showCloudDialog) {
        CloudRunConfigDialog(
            viewModel = viewModel,
            onDismiss = { viewModel.closeCloudConfigDialog() }
        )
    }
}
