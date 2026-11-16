package com.example.data.model

enum class SubscriberStatus(val value: String) {
    ACTIVE("active"),
    EXPIRED("expired"),
    SUSPENDED("suspended")
}

data class Subscriber(
    val id: Long,
    val username: String,
    val cleartextPassword: String,
    val ipAddress: String,
    val macAddress: String,
    val profile: String,
    val rateLimit: String, // e.g. "20M/20M 30M/30M 10M/10M 10/10 8 10M/10M"
    val monthlyFee: Double,
    val expiryDate: String,
    val status: SubscriberStatus,
    val lastPaymentDate: String,
    val activeSessionId: String? = null,
    val uptimeSeconds: Long = 0,
    val inputBytes: Long = 0,
    val outputBytes: Long = 0
)

data class RadAcctRecord(
    val radacctId: Long,
    val acctSessionId: String,
    val acctUniqueId: String,
    val username: String,
    val nasIpAddress: String,
    val acctStartTime: String,
    val acctStopTime: String? = null,
    val acctSessionTime: Long = 0,
    val acctInputOctets: Long = 0,
    val acctOutputOctets: Long = 0,
    val framedIpAddress: String,
    val partitionTable: String // e.g. "radacct_y2026m10"
)

data class PaymentWebhookSimulation(
    val gateway: String, // "bKash", "Nagad", "SSLCOMMERZ"
    val trxId: String,
    val customerPhone: String,
    val username: String,
    val amount: Double,
    val rawSignature: String,
    val computedSignature: String,
    val isVerified: Boolean,
    val lineReconnected: Boolean,
    val executionTimeMs: Long,
    val logOutput: String
)

data class CoaKickSimulation(
    val targetUsername: String,
    val targetIp: String,
    val nasIp: String,
    val nasPort: Int = 3799,
    val secretKey: String,
    val packetType: String = "Disconnect-Request (PoD)",
    val packetId: Int,
    val authenticatorHex: String,
    val resultStatus: String, // "Disconnect-ACK" or "Disconnect-NAK"
    val latencyMs: Long,
    val logTrace: List<String>
)

data class RadTestBenchmarkResult(
    val totalPackets: Int,
    val acceptedPackets: Int,
    val rejectedPackets: Int,
    val minLatencyMs: Double,
    val avgLatencyMs: Double,
    val maxLatencyMs: Double,
    val meetsSla: Boolean, // < 10ms
    val samplePoints: List<Double>
)
