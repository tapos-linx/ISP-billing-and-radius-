package com.example.data.model

enum class ShardHealthStatus {
    HEALTHY,
    ELEVATED,
    DEGRADED
}

data class CitusShardHealth(
    val shardId: Int,
    val status: ShardHealthStatus,
    val latencyMs: Double,
    val loadPercent: Int,
    val activeSessions: Int,
    val replicaSynced: Boolean = true
)

data class NocTelemetry(
    val activeSessions: Long = 8_241_092L,
    val clusterThroughputGbps: Double = 450.4,
    val aaaLatencyMs: Double = 0.38,
    val losAlarmCount: Int = 42,
    val redisHitRatePercent: Double = 99.4,
    val stormActive: Boolean = false,
    val throughputHistoryGbps: List<Float> = listOf(412f, 428f, 435f, 441f, 438f, 449f, 456f, 445f, 452f, 450.4f),
    val aaaLatencyHistoryMs: List<Float> = listOf(0.41f, 0.39f, 0.42f, 0.37f, 0.36f, 0.39f, 0.38f, 0.35f, 0.38f, 0.38f),
    val sourceLabel: String = "Local Mock State"
)

enum class WebhookSignatureStatus(val label: String) {
    VERIFIED_MOCK("HMAC Verified (Mock Sim)"),
    VERIFIED_SERVER("HMAC Verified (Cloud Run)"),
    INVALID_REJECTED("403 HMAC Mismatch"),
    DUPLICATE_LOCKED("409 Idempotency Locked")
}

data class MfsWebhookEvent(
    val id: String,
    val gateway: String,
    val trxId: String,
    val username: String,
    val customerPhone: String,
    val amountBdt: Double,
    val httpStatus: Int,
    val signatureStatus: WebhookSignatureStatus,
    val pipelineCompletedSteps: Int, // 1..5
    val lineReconnected: Boolean,
    val executionTimeMs: Long,
    val timestampLabel: String,
    val payloadPreview: String,
    val logOutput: String
)

enum class CoaActionType(val label: String, val codeLabel: String, val rfcCode: Int) {
    DISCONNECT_POD("Disconnect-Request (PoD)", "Code 40 • Terminate Session", 40),
    COA_RATE_LIMIT("CoA Rate-Limit Update", "Code 43 • Dynamic Bandwidth", 43),
    COA_WALLED_GARDEN("CoA Walled-Garden", "Code 43 • Redirect Expired", 43),
    COA_RESTORE("CoA Restore Tariff", "Code 43 • Unblock Full Speed", 43)
}

enum class NasVendor(val displayName: String, val vsaAttribute: String) {
    MIKROTIK_CCR2004("MikroTik CCR2004 (RouterOS v7)", "Mikrotik-Rate-Limit"),
    HUAWEI_NE8000("Huawei NE8000 (BRAS)", "Huawei-Input-Peak-Information-Rate"),
    JUNIPER_MX("Juniper MX Series (BNG)", "ERX-Ingress-Policy-Name")
}

enum class BackendConnectionStatus(val label: String) {
    MOCK_LOCAL("Mock State"),
    CONNECTING("Probing..."),
    CONNECTED("Cloud Run Live"),
    ERROR("Cloud Run Error")
}

data class CloudRunConnectionState(
    val baseUrl: String = "",
    val useCloudBackend: Boolean = false,
    val status: BackendConnectionStatus = BackendConnectionStatus.MOCK_LOCAL,
    val lastCheckedLabel: String = "Not tested",
    val lastHttpStatus: Int? = null,
    val statusMessage: String = "Using interactive local mock state"
)
