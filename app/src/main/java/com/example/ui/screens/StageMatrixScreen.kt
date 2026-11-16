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
import androidx.compose.material.icons.filled.CheckCircle
import androidx.compose.material.icons.filled.ContentCopy
import androidx.compose.material.icons.filled.Terminal
import androidx.compose.material3.*
import androidx.compose.runtime.Composable
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.testTag
import androidx.compose.ui.text.font.FontFamily
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.example.data.model.DeploymentStage
import com.example.data.repository.ProductionArtifactsRepository
import com.example.ui.components.CodeSnippetViewer
import com.example.ui.theme.*
import com.example.viewmodel.IspArchitectViewModel

@Composable
fun StageMatrixScreen(
    viewModel: IspArchitectViewModel,
    modifier: Modifier = Modifier
) {
    val selectedStageNumber by viewModel.selectedStageNumber.collectAsState()
    val stages = ProductionArtifactsRepository.stages
    val activeStage = stages.find { it.stageNumber == selectedStageNumber } ?: stages.first()
    val context = LocalContext.current

    Column(
        modifier = modifier
            .fillMaxSize()
            .background(TelecomNavyDark)
            .padding(horizontal = 16.dp)
    ) {
        Spacer(modifier = Modifier.height(8.dp))

        // Screen Header
        Text(
            text = "5-Stage (28-Day) Deployment Matrix",
            style = MaterialTheme.typography.headlineSmall,
            color = TextPrimaryDark,
            fontWeight = FontWeight.Bold
        )
        Text(
            text = "Windows 10/11 WSL2 • Docker • FreeRADIUS 3.x • Mikrotik RouterOS v7",
            style = MaterialTheme.typography.bodySmall,
            color = TextSecondaryDark
        )

        Spacer(modifier = Modifier.height(12.dp))

        // Horizontal Stage Selector Tabs
        Row(
            modifier = Modifier
                .fillMaxWidth()
                .horizontalScroll(rememberScrollState()),
            horizontalArrangement = Arrangement.spacedBy(8.dp)
        ) {
            stages.forEach { stage ->
                val isSelected = stage.stageNumber == selectedStageNumber
                Surface(
                    shape = RoundedCornerShape(20.dp),
                    color = if (isSelected) CyanPulse else TelecomCardDark,
                    border = CardDefaults.outlinedCardBorder().copy(
                        brush = androidx.compose.ui.graphics.SolidColor(if (isSelected) CyanPulse else TelecomBorderDark)
                    ),
                    modifier = Modifier
                        .clickable { viewModel.selectStage(stage.stageNumber) }
                        .testTag("stage_tab_${stage.stageNumber}")
                ) {
                    Row(
                        modifier = Modifier.padding(horizontal = 14.dp, vertical = 8.dp),
                        verticalAlignment = Alignment.CenterVertically
                    ) {
                        Text(
                            text = "Stage ${stage.stageNumber}",
                            fontWeight = FontWeight.Bold,
                            color = if (isSelected) TelecomNavyDark else TextPrimaryDark,
                            fontSize = 13.sp
                        )
                        Text(
                            text = " (${stage.daysRange})",
                            color = if (isSelected) TelecomNavyDark.copy(alpha = 0.8f) else TextSecondaryDark,
                            fontSize = 11.sp
                        )
                    }
                }
            }
        }

        Spacer(modifier = Modifier.height(14.dp))

        // Stage Overview & Deliverables
        LazyColumn(
            modifier = Modifier.fillMaxSize(),
            verticalArrangement = Arrangement.spacedBy(16.dp)
        ) {
            // Stage Banner Card
            item {
                Card(
                    modifier = Modifier.fillMaxWidth(),
                    shape = RoundedCornerShape(12.dp),
                    colors = CardDefaults.cardColors(containerColor = TelecomSurfaceDark),
                    border = CardDefaults.outlinedCardBorder().copy(brush = androidx.compose.ui.graphics.SolidColor(TelecomBorderDark))
                ) {
                    Column(modifier = Modifier.padding(16.dp)) {
                        Row(
                            modifier = Modifier.fillMaxWidth(),
                            horizontalArrangement = Arrangement.SpaceBetween,
                            verticalAlignment = Alignment.CenterVertically
                        ) {
                            Text(
                                text = "STAGE ${activeStage.stageNumber}: ${activeStage.daysRange.uppercase()}",
                                style = MaterialTheme.typography.labelSmall,
                                color = CyanPulse,
                                fontWeight = FontWeight.Bold
                            )

                            // Target SLA Pill
                            Surface(
                                shape = RoundedCornerShape(12.dp),
                                color = EmeraldSla.copy(alpha = 0.15f),
                                border = CardDefaults.outlinedCardBorder().copy(brush = androidx.compose.ui.graphics.SolidColor(EmeraldSla))
                            ) {
                                Text(
                                    text = activeStage.targetSla,
                                    style = MaterialTheme.typography.labelSmall,
                                    color = EmeraldSla,
                                    fontWeight = FontWeight.Bold,
                                    modifier = Modifier.padding(horizontal = 8.dp, vertical = 3.dp)
                                )
                            }
                        }

                        Spacer(modifier = Modifier.height(6.dp))
                        Text(
                            text = activeStage.title,
                            style = MaterialTheme.typography.titleLarge,
                            color = TextPrimaryDark,
                            fontWeight = FontWeight.Bold
                        )
                        Text(
                            text = activeStage.subtitle,
                            style = MaterialTheme.typography.bodyMedium,
                            color = AmberBilling
                        )

                        Spacer(modifier = Modifier.height(10.dp))
                        Text(
                            text = activeStage.summary,
                            style = MaterialTheme.typography.bodyMedium,
                            color = TextSecondaryDark
                        )

                        Spacer(modifier = Modifier.height(12.dp))
                        // Technology badges
                        Row(
                            modifier = Modifier
                                .fillMaxWidth()
                                .horizontalScroll(rememberScrollState()),
                            horizontalArrangement = Arrangement.spacedBy(6.dp)
                        ) {
                            activeStage.technologies.forEach { tech ->
                                Surface(
                                    shape = RoundedCornerShape(6.dp),
                                    color = TelecomCardDark
                                ) {
                                    Text(
                                        text = tech,
                                        style = MaterialTheme.typography.labelSmall,
                                        color = TextSecondaryDark,
                                        modifier = Modifier.padding(horizontal = 8.dp, vertical = 4.dp)
                                    )
                                }
                            }
                        }
                    }
                }
            }

            // Production Code Artifacts
            item {
                Text(
                    text = "Production Code Artifacts (${activeStage.artifacts.size})",
                    style = MaterialTheme.typography.titleMedium,
                    color = TextPrimaryDark,
                    fontWeight = FontWeight.Bold
                )
            }

            items(activeStage.artifacts) { artifact ->
                CodeSnippetViewer(
                    title = artifact.title,
                    fileName = artifact.fileName,
                    language = artifact.language,
                    code = artifact.codeContent
                )
            }

            // Exact Windows PowerShell Verification Steps
            item {
                Text(
                    text = "Exact Windows PowerShell Verification Commands",
                    style = MaterialTheme.typography.titleMedium,
                    color = TextPrimaryDark,
                    fontWeight = FontWeight.Bold
                )
            }

            items(activeStage.verificationSteps) { step ->
                Card(
                    modifier = Modifier.fillMaxWidth(),
                    shape = RoundedCornerShape(12.dp),
                    colors = CardDefaults.cardColors(containerColor = TelecomCardDark),
                    border = CardDefaults.outlinedCardBorder().copy(brush = androidx.compose.ui.graphics.SolidColor(TelecomBorderDark))
                ) {
                    Column(modifier = Modifier.padding(14.dp)) {
                        Row(verticalAlignment = Alignment.CenterVertically) {
                            Icon(
                                imageVector = Icons.Default.Terminal,
                                contentDescription = null,
                                tint = CyanPulse,
                                modifier = Modifier.size(18.dp)
                            )
                            Spacer(modifier = Modifier.width(8.dp))
                            Text(
                                text = "Step ${step.stepNumber}: ${step.title}",
                                style = MaterialTheme.typography.titleMedium,
                                color = TextPrimaryDark,
                                fontWeight = FontWeight.SemiBold
                            )
                        }

                        Spacer(modifier = Modifier.height(10.dp))

                        // PowerShell Command Box with Copy button
                        Surface(
                            shape = RoundedCornerShape(8.dp),
                            color = TelecomNavyDark,
                            modifier = Modifier.fillMaxWidth()
                        ) {
                            Row(
                                modifier = Modifier.padding(10.dp),
                                horizontalArrangement = Arrangement.SpaceBetween,
                                verticalAlignment = Alignment.CenterVertically
                            ) {
                                Text(
                                    text = step.powerShellCommand.trim(),
                                    fontFamily = FontFamily.Monospace,
                                    fontSize = 11.sp,
                                    color = CyanPulse,
                                    modifier = Modifier.weight(1f)
                                )
                                IconButton(
                                    onClick = {
                                        val clipboard = context.getSystemService(Context.CLIPBOARD_SERVICE) as ClipboardManager
                                        val clip = ClipData.newPlainText("PS Command", step.powerShellCommand.trim())
                                        clipboard.setPrimaryClip(clip)
                                        Toast.makeText(context, "Copied PowerShell command", Toast.LENGTH_SHORT).show()
                                    },
                                    modifier = Modifier.testTag("copy_step_${step.stepNumber}")
                                ) {
                                    Icon(
                                        imageVector = Icons.Default.ContentCopy,
                                        contentDescription = "Copy command",
                                        tint = TextSecondaryDark,
                                        modifier = Modifier.size(16.dp)
                                    )
                                }
                            }
                        }

                        Spacer(modifier = Modifier.height(8.dp))

                        Row(verticalAlignment = Alignment.CenterVertically) {
                            Icon(
                                imageVector = Icons.Default.CheckCircle,
                                contentDescription = null,
                                tint = EmeraldSla,
                                modifier = Modifier.size(14.dp)
                            )
                            Spacer(modifier = Modifier.width(6.dp))
                            Text(
                                text = "Expected Output: ${step.expectedOutput}",
                                style = MaterialTheme.typography.bodySmall,
                                color = EmeraldSla
                            )
                        }

                        if (step.notes.isNotEmpty()) {
                            Text(
                                text = "Notes: ${step.notes}",
                                style = MaterialTheme.typography.bodySmall,
                                color = TextTertiaryDark,
                                modifier = Modifier.padding(top = 4.dp)
                            )
                        }
                    }
                }
            }

            item {
                Spacer(modifier = Modifier.height(30.dp))
            }
        }
    }
}
