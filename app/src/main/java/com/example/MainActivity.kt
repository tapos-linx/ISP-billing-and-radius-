package com.example

import android.os.Bundle
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.activity.enableEdgeToEdge
import androidx.activity.viewModels
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.*
import androidx.compose.material3.*
import androidx.compose.runtime.Composable
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.testTag
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
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
    val currentTab by viewModel.currentTab.collectAsState()

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
                            modifier = Modifier.size(36.dp)
                        ) {
                            Box(contentAlignment = Alignment.Center) {
                                Icon(
                                    imageVector = Icons.Default.Router,
                                    contentDescription = null,
                                    tint = CyanPulse,
                                    modifier = Modifier.size(20.dp)
                                )
                            }
                        }
                        Spacer(modifier = Modifier.width(10.dp))
                        Column {
                            Row(verticalAlignment = Alignment.CenterVertically) {
                                Text(
                                    text = "ISP Billing",
                                    fontWeight = FontWeight.Bold,
                                    fontSize = 17.sp,
                                    color = TextPrimaryDark
                                )
                                Spacer(modifier = Modifier.width(6.dp))
                                Surface(
                                    shape = RoundedCornerShape(4.dp),
                                    color = AmberBilling.copy(alpha = 0.2f)
                                ) {
                                    Text(
                                        text = "by Tapos",
                                        color = AmberBilling,
                                        fontSize = 10.sp,
                                        fontWeight = FontWeight.SemiBold,
                                        modifier = Modifier.padding(horizontal = 4.dp, vertical = 1.dp)
                                    )
                                }
                            }
                            Text(
                                text = "Windows WSL2 • FreeRADIUS 3.x • RouterOS v7",
                                fontSize = 11.sp,
                                color = TextSecondaryDark
                            )
                        }
                    }
                },
                actions = {
                    Surface(
                        shape = RoundedCornerShape(12.dp),
                        color = EmeraldSla.copy(alpha = 0.15f),
                        border = CardDefaults.outlinedCardBorder().copy(brush = androidx.compose.ui.graphics.SolidColor(EmeraldSla)),
                        modifier = Modifier.padding(end = 12.dp)
                    ) {
                        Row(
                            verticalAlignment = Alignment.CenterVertically,
                            modifier = Modifier.padding(horizontal = 8.dp, vertical = 4.dp)
                        ) {
                            Surface(
                                shape = CircleShape,
                                color = EmeraldSla,
                                modifier = Modifier.size(6.dp)
                            ) {}
                            Spacer(modifier = Modifier.width(6.dp))
                            Text(
                                text = "SLA <10ms",
                                fontSize = 11.sp,
                                fontWeight = FontWeight.Bold,
                                color = EmeraldSla
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
            NavigationBar(
                containerColor = TelecomSurfaceDark,
                contentColor = TextPrimaryDark,
                windowInsets = WindowInsets.navigationBars,
                modifier = Modifier.testTag("main_bottom_nav")
            ) {
                NavigationBarItem(
                    selected = currentTab == AppNavTab.MATRIX,
                    onClick = { viewModel.selectTab(AppNavTab.MATRIX) },
                    icon = { Icon(Icons.Default.Timeline, contentDescription = "Matrix") },
                    label = { Text("Matrix", fontSize = 10.sp) },
                    colors = NavigationBarItemDefaults.colors(
                        selectedIconColor = TelecomNavyDark,
                        selectedTextColor = CyanPulse,
                        indicatorColor = CyanPulse,
                        unselectedIconColor = TextSecondaryDark,
                        unselectedTextColor = TextSecondaryDark
                    ),
                    modifier = Modifier.testTag("nav_tab_matrix")
                )

                NavigationBarItem(
                    selected = currentTab == AppNavTab.TOOLS,
                    onClick = { viewModel.selectTab(AppNavTab.TOOLS) },
                    icon = { Icon(Icons.Default.Build, contentDescription = "Test Lab") },
                    label = { Text("Test Lab", fontSize = 10.sp) },
                    colors = NavigationBarItemDefaults.colors(
                        selectedIconColor = TelecomNavyDark,
                        selectedTextColor = CyanPulse,
                        indicatorColor = CyanPulse,
                        unselectedIconColor = TextSecondaryDark,
                        unselectedTextColor = TextSecondaryDark
                    ),
                    modifier = Modifier.testTag("nav_tab_tools")
                )

                NavigationBarItem(
                    selected = currentTab == AppNavTab.SUBSCRIBERS,
                    onClick = { viewModel.selectTab(AppNavTab.SUBSCRIBERS) },
                    icon = { Icon(Icons.Default.People, contentDescription = "Subscribers") },
                    label = { Text("Subscribers", fontSize = 10.sp) },
                    colors = NavigationBarItemDefaults.colors(
                        selectedIconColor = TelecomNavyDark,
                        selectedTextColor = CyanPulse,
                        indicatorColor = CyanPulse,
                        unselectedIconColor = TextSecondaryDark,
                        unselectedTextColor = TextSecondaryDark
                    ),
                    modifier = Modifier.testTag("nav_tab_subscribers")
                )

                NavigationBarItem(
                    selected = currentTab == AppNavTab.POWERSHELL,
                    onClick = { viewModel.selectTab(AppNavTab.POWERSHELL) },
                    icon = { Icon(Icons.Default.Terminal, contentDescription = "PowerShell") },
                    label = { Text("PowerShell", fontSize = 10.sp) },
                    colors = NavigationBarItemDefaults.colors(
                        selectedIconColor = TelecomNavyDark,
                        selectedTextColor = CyanPulse,
                        indicatorColor = CyanPulse,
                        unselectedIconColor = TextSecondaryDark,
                        unselectedTextColor = TextSecondaryDark
                    ),
                    modifier = Modifier.testTag("nav_tab_powershell")
                )

                NavigationBarItem(
                    selected = currentTab == AppNavTab.AI_ARCHITECT,
                    onClick = { viewModel.selectTab(AppNavTab.AI_ARCHITECT) },
                    icon = { Icon(Icons.Default.SmartToy, contentDescription = "AI Architect") },
                    label = { Text("AI Copilot", fontSize = 10.sp) },
                    colors = NavigationBarItemDefaults.colors(
                        selectedIconColor = TelecomNavyDark,
                        selectedTextColor = CyanPulse,
                        indicatorColor = CyanPulse,
                        unselectedIconColor = TextSecondaryDark,
                        unselectedTextColor = TextSecondaryDark
                    ),
                    modifier = Modifier.testTag("nav_tab_ai")
                )
            }
        }
    ) { innerPadding ->
        Box(
            modifier = Modifier
                .fillMaxSize()
                .padding(innerPadding)
        ) {
            when (currentTab) {
                AppNavTab.MATRIX -> StageMatrixScreen(viewModel = viewModel)
                AppNavTab.TOOLS -> InteractiveToolsScreen(viewModel = viewModel)
                AppNavTab.SUBSCRIBERS -> SubscriberConsoleScreen(viewModel = viewModel)
                AppNavTab.POWERSHELL -> PowerShellVaultScreen(viewModel = viewModel)
                AppNavTab.AI_ARCHITECT -> AiArchitectScreen(viewModel = viewModel)
            }
        }
    }
}
