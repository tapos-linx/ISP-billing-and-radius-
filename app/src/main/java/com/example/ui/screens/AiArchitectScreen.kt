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
import androidx.compose.foundation.lazy.rememberLazyListState
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.Send
import androidx.compose.material.icons.filled.*
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.testTag
import androidx.compose.ui.text.font.FontFamily
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.example.data.model.AiChatMessage
import com.example.data.model.AiModelType
import com.example.ui.theme.*
import com.example.viewmodel.IspArchitectViewModel

@Composable
fun AiArchitectScreen(
    viewModel: IspArchitectViewModel,
    modifier: Modifier = Modifier
) {
    val activeModel by viewModel.aiModel.collectAsState()
    val chatMessages by viewModel.chatMessages.collectAsState()
    val isThinking by viewModel.isAiThinking.collectAsState()
    var inputText by remember { mutableStateOf("") }
    val listState = rememberLazyListState()
    val context = LocalContext.current

    LaunchedEffect(chatMessages.size) {
        if (chatMessages.isNotEmpty()) {
            listState.animateScrollToItem(chatMessages.size - 1)
        }
    }

    val presetPrompts = remember {
        listOf(
            "Audit Windows Defender firewall rules for UDP 1812/1813/3799",
            "Optimize PostgreSQL 16 monthly radacct partitioning",
            "Troubleshoot RFC 3576 CoA Disconnect-Request NAK on Mikrotik",
            "Configure Mikrotik CCR2004 BGP multihoming with BDIX & NTT",
            "Write BDCOM GP3600 GPON profile for 1:64 split ratio QoS"
        )
    }

    Column(
        modifier = modifier
            .fillMaxSize()
            .background(TelecomNavyDark)
            .padding(horizontal = 16.dp)
    ) {
        Spacer(modifier = Modifier.height(8.dp))

        // Top bar with Model Switcher
        Row(
            modifier = Modifier.fillMaxWidth(),
            horizontalArrangement = Arrangement.SpaceBetween,
            verticalAlignment = Alignment.CenterVertically
        ) {
            Column {
                Text(
                    text = "AI Telecom Architect",
                    style = MaterialTheme.typography.headlineSmall,
                    color = TextPrimaryDark,
                    fontWeight = FontWeight.Bold
                )
                Text(
                    text = "Dual Neural Engine: Fast Copilot & Deep Reasoning",
                    style = MaterialTheme.typography.bodySmall,
                    color = TextSecondaryDark
                )
            }

            IconButton(
                onClick = { viewModel.clearChat() },
                modifier = Modifier.testTag("clear_chat_button")
            ) {
                Icon(
                    imageVector = Icons.Default.DeleteOutline,
                    contentDescription = "Clear Chat",
                    tint = TextSecondaryDark
                )
            }
        }

        Spacer(modifier = Modifier.height(10.dp))

        // Dual Model Selection Tabs
        Card(
            modifier = Modifier.fillMaxWidth(),
            shape = RoundedCornerShape(12.dp),
            colors = CardDefaults.cardColors(containerColor = TelecomSurfaceDark),
            border = CardDefaults.outlinedCardBorder().copy(brush = androidx.compose.ui.graphics.SolidColor(TelecomBorderDark))
        ) {
            Row(
                modifier = Modifier
                    .fillMaxWidth()
                    .padding(6.dp),
                horizontalArrangement = Arrangement.spacedBy(6.dp)
            ) {
                // Button 1: gemini-3.1-flash-lite (Low latency)
                Surface(
                    shape = RoundedCornerShape(8.dp),
                    color = if (activeModel == AiModelType.FLASH_LITE) CyanPulse else Color.Transparent,
                    modifier = Modifier
                        .weight(1f)
                        .clickable { viewModel.setAiModel(AiModelType.FLASH_LITE) }
                        .testTag("select_model_flash_lite")
                ) {
                    Column(
                        modifier = Modifier.padding(vertical = 8.dp, horizontal = 6.dp),
                        horizontalAlignment = Alignment.CenterHorizontally
                    ) {
                        Text(
                            text = "⚡ Low Latency",
                            fontWeight = FontWeight.Bold,
                            fontSize = 12.sp,
                            color = if (activeModel == AiModelType.FLASH_LITE) TelecomNavyDark else TextPrimaryDark
                        )
                        Text(
                            text = "gemini-3.1-flash-lite",
                            fontFamily = FontFamily.Monospace,
                            fontSize = 10.sp,
                            color = if (activeModel == AiModelType.FLASH_LITE) TelecomNavyDark.copy(alpha = 0.8f) else TextSecondaryDark
                        )
                    }
                }

                // Button 2: gemini-4-argon [ThinkingLevel.HIGH]
                Surface(
                    shape = RoundedCornerShape(8.dp),
                    color = if (activeModel == AiModelType.ARGON_HIGH_THINKING) PurpleThinking else Color.Transparent,
                    modifier = Modifier
                        .weight(1f)
                        .clickable { viewModel.setAiModel(AiModelType.ARGON_HIGH_THINKING) }
                        .testTag("select_model_argon_thinking")
                ) {
                    Column(
                        modifier = Modifier.padding(vertical = 8.dp, horizontal = 6.dp),
                        horizontalAlignment = Alignment.CenterHorizontally
                    ) {
                        Text(
                            text = "🧠 High Thinking",
                            fontWeight = FontWeight.Bold,
                            fontSize = 12.sp,
                            color = if (activeModel == AiModelType.ARGON_HIGH_THINKING) TelecomNavyDark else TextPrimaryDark
                        )
                        Text(
                            text = "gemini-4-argon (HIGH)",
                            fontFamily = FontFamily.Monospace,
                            fontSize = 10.sp,
                            color = if (activeModel == AiModelType.ARGON_HIGH_THINKING) TelecomNavyDark.copy(alpha = 0.8f) else PurpleThinking
                        )
                    }
                }
            }
        }

        Spacer(modifier = Modifier.height(10.dp))

        // Preset Prompt Chips
        Row(
            modifier = Modifier
                .fillMaxWidth()
                .horizontalScroll(rememberScrollState()),
            horizontalArrangement = Arrangement.spacedBy(6.dp)
        ) {
            presetPrompts.forEach { preset ->
                Surface(
                    shape = RoundedCornerShape(14.dp),
                    color = TelecomCardDark,
                    border = CardDefaults.outlinedCardBorder().copy(brush = androidx.compose.ui.graphics.SolidColor(TelecomBorderDark)),
                    modifier = Modifier.clickable {
                        inputText = preset
                    }
                ) {
                    Text(
                        text = preset,
                        style = MaterialTheme.typography.labelSmall,
                        color = TextSecondaryDark,
                        modifier = Modifier.padding(horizontal = 10.dp, vertical = 5.dp)
                    )
                }
            }
        }

        Spacer(modifier = Modifier.height(10.dp))

        // Chat Message Stream
        LazyColumn(
            state = listState,
            modifier = Modifier
                .weight(1f)
                .fillMaxWidth(),
            verticalArrangement = Arrangement.spacedBy(10.dp)
        ) {
            items(chatMessages) { message ->
                ChatMessageItem(message = message, context = context)
            }

            if (isThinking) {
                item {
                    Card(
                        shape = RoundedCornerShape(12.dp),
                        colors = CardDefaults.cardColors(containerColor = TelecomCardDark),
                        modifier = Modifier.fillMaxWidth()
                    ) {
                        Row(
                            modifier = Modifier.padding(14.dp),
                            verticalAlignment = Alignment.CenterVertically
                        ) {
                            CircularProgressIndicator(
                                color = if (activeModel == AiModelType.ARGON_HIGH_THINKING) PurpleThinking else CyanPulse,
                                modifier = Modifier.size(20.dp)
                            )
                            Spacer(modifier = Modifier.width(12.dp))
                            Text(
                                text = if (activeModel == AiModelType.ARGON_HIGH_THINKING)
                                    "gemini-4-argon is analyzing deep network architecture [ThinkingLevel.HIGH]..."
                                else
                                    "gemini-3.1-flash-lite is generating low-latency response...",
                                style = MaterialTheme.typography.bodyMedium,
                                color = if (activeModel == AiModelType.ARGON_HIGH_THINKING) PurpleThinking else CyanPulse
                            )
                        }
                    }
                }
            }
        }

        Spacer(modifier = Modifier.height(8.dp))

        // Message Input Bar
        Surface(
            shape = RoundedCornerShape(24.dp),
            color = TelecomSurfaceDark,
            border = CardDefaults.outlinedCardBorder().copy(brush = androidx.compose.ui.graphics.SolidColor(TelecomBorderDark)),
            modifier = Modifier.fillMaxWidth()
        ) {
            Row(
                modifier = Modifier.padding(horizontal = 12.dp, vertical = 4.dp),
                verticalAlignment = Alignment.CenterVertically
            ) {
                TextField(
                    value = inputText,
                    onValueChange = { inputText = it },
                    placeholder = {
                        Text(
                            text = if (activeModel == AiModelType.ARGON_HIGH_THINKING)
                                "Ask complex query (gemini-4-argon thinking)..."
                            else
                                "Ask quick question (gemini-3.1-flash-lite)...",
                            fontSize = 12.sp,
                            color = TextTertiaryDark
                        )
                    },
                    modifier = Modifier
                        .weight(1f)
                        .testTag("ai_prompt_input"),
                    colors = TextFieldDefaults.colors(
                        focusedContainerColor = Color.Transparent,
                        unfocusedContainerColor = Color.Transparent,
                        focusedIndicatorColor = Color.Transparent,
                        unfocusedIndicatorColor = Color.Transparent
                    ),
                    singleLine = false,
                    maxLines = 4
                )

                IconButton(
                    onClick = {
                        val prompt = inputText.trim()
                        if (prompt.isNotEmpty()) {
                            inputText = ""
                            viewModel.sendAiPrompt(prompt)
                        }
                    },
                    enabled = inputText.isNotBlank() && !isThinking,
                    modifier = Modifier.testTag("send_ai_prompt_button")
                ) {
                    Icon(
                        imageVector = Icons.AutoMirrored.Filled.Send,
                        contentDescription = "Send prompt",
                        tint = if (inputText.isNotBlank()) {
                            if (activeModel == AiModelType.ARGON_HIGH_THINKING) PurpleThinking else CyanPulse
                        } else TextTertiaryDark
                    )
                }
            }
        }

        Spacer(modifier = Modifier.height(12.dp))
    }
}

@Composable
private fun ChatMessageItem(message: AiChatMessage, context: Context) {
    val isUser = message.isUser

    Column(
        modifier = Modifier.fillMaxWidth(),
        horizontalAlignment = if (isUser) Alignment.End else Alignment.Start
    ) {
        Card(
            shape = RoundedCornerShape(
                topStart = 12.dp,
                topEnd = 12.dp,
                bottomStart = if (isUser) 12.dp else 2.dp,
                bottomEnd = if (isUser) 2.dp else 12.dp
            ),
            colors = CardDefaults.cardColors(
                containerColor = if (isUser) TelecomBorderDark else TelecomSurfaceDark
            ),
            border = CardDefaults.outlinedCardBorder().copy(
                brush = androidx.compose.ui.graphics.SolidColor(
                    if (isUser) CyanPulseDim.copy(alpha = 0.5f) else TelecomBorderDark
                )
            ),
            modifier = Modifier
                .widthIn(max = 340.dp)
                .testTag(if (isUser) "user_message" else "ai_message")
        ) {
            Column(modifier = Modifier.padding(12.dp)) {
                if (!isUser && message.modelType != null) {
                    Row(
                        modifier = Modifier.fillMaxWidth(),
                        horizontalArrangement = Arrangement.SpaceBetween,
                        verticalAlignment = Alignment.CenterVertically
                    ) {
                        Surface(
                            shape = RoundedCornerShape(4.dp),
                            color = if (message.modelType == AiModelType.ARGON_HIGH_THINKING)
                                PurpleThinking.copy(alpha = 0.2f)
                            else
                                CyanPulse.copy(alpha = 0.2f)
                        ) {
                            Text(
                                text = message.modelType.badge,
                                style = MaterialTheme.typography.labelSmall,
                                color = if (message.modelType == AiModelType.ARGON_HIGH_THINKING) PurpleThinking else CyanPulse,
                                modifier = Modifier.padding(horizontal = 6.dp, vertical = 2.dp)
                            )
                        }

                        if (message.responseTimeMs != null) {
                            Text(
                                text = "${message.responseTimeMs} ms",
                                fontFamily = FontFamily.Monospace,
                                fontSize = 10.sp,
                                color = TextTertiaryDark
                            )
                        }
                    }
                    Spacer(modifier = Modifier.height(6.dp))
                }

                Text(
                    text = message.text,
                    style = MaterialTheme.typography.bodyMedium,
                    color = TextPrimaryDark,
                    lineHeight = 19.sp
                )

                if (!isUser) {
                    Spacer(modifier = Modifier.height(6.dp))
                    Row(
                        modifier = Modifier.fillMaxWidth(),
                        horizontalArrangement = Arrangement.End
                    ) {
                        IconButton(
                            onClick = {
                                val clipboard = context.getSystemService(Context.CLIPBOARD_SERVICE) as ClipboardManager
                                val clip = ClipData.newPlainText("AI Response", message.text)
                                clipboard.setPrimaryClip(clip)
                                Toast.makeText(context, "Copied response", Toast.LENGTH_SHORT).show()
                            },
                            modifier = Modifier.size(24.dp)
                        ) {
                            Icon(
                                imageVector = Icons.Default.ContentCopy,
                                contentDescription = "Copy message",
                                tint = TextSecondaryDark,
                                modifier = Modifier.size(14.dp)
                            )
                        }
                    }
                }
            }
        }
    }
}
