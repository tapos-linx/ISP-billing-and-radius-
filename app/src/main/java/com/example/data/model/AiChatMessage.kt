package com.example.data.model

enum class AiModelType(val modelId: String, val displayName: String, val badge: String) {
    FLASH_LITE(
        modelId = "gemini-3.1-flash-lite",
        displayName = "Fast Copilot (Low Latency)",
        badge = "⚡ gemini-3.1-flash-lite"
    ),
    ARGON_HIGH_THINKING(
        modelId = "gemini-4-argon",
        displayName = "Lead Architect (Deep Reasoning)",
        badge = "🧠 gemini-4-argon [ThinkingLevel.HIGH]"
    )
}

data class AiChatMessage(
    val id: String = java.util.UUID.randomUUID().toString(),
    val isUser: Boolean,
    val text: String,
    val modelType: AiModelType? = null,
    val responseTimeMs: Long? = null,
    val isThinking: Boolean = false,
    val timestamp: Long = System.currentTimeMillis()
)
