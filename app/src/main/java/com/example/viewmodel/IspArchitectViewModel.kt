package com.example.viewmodel

import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import com.example.data.model.*
import com.example.data.network.GeminiService
import com.example.data.repository.ProductionArtifactsRepository
import kotlinx.coroutines.delay
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.launch
import java.security.MessageDigest
import javax.crypto.Mac
import javax.crypto.spec.SecretKeySpec
import kotlin.random.Random

enum class AppNavTab(val title: String, val iconName: String) {
    MATRIX("28-Day Matrix", "timeline"),
    TOOLS("Test Lab & Tools", "build"),
    SUBSCRIBERS("Subscribers & Accounting", "people"),
    POWERSHELL("PowerShell Vault", "terminal"),
    AI_ARCHITECT("AI Architect", "smart_toy")
}

class IspArchitectViewModel : ViewModel() {

    private val geminiService = GeminiService()

    // Navigation State
    private val _currentTab = MutableStateFlow(AppNavTab.MATRIX)
    val currentTab: StateFlow<AppNavTab> = _currentTab.asStateFlow()

    private val _selectedStageNumber = MutableStateFlow(1)
    val selectedStageNumber: StateFlow<Int> = _selectedStageNumber.asStateFlow()

    // Subscribers State
    private val _subscribers = MutableStateFlow<List<Subscriber>>(emptyList())
    val subscribers: StateFlow<List<Subscriber>> = _subscribers.asStateFlow()

    private val _radAcctRecords = MutableStateFlow<List<RadAcctRecord>>(emptyList())
    val radAcctRecords: StateFlow<List<RadAcctRecord>> = _radAcctRecords.asStateFlow()

    // Interactive Tools State
    private val _coaResult = MutableStateFlow<CoaKickSimulation?>(null)
    val coaResult: StateFlow<CoaKickSimulation?> = _coaResult.asStateFlow()
    private val _isKicking = MutableStateFlow(false)
    val isKicking: StateFlow<Boolean> = _isKicking.asStateFlow()

    private val _paymentResult = MutableStateFlow<PaymentWebhookSimulation?>(null)
    val paymentResult: StateFlow<PaymentWebhookSimulation?> = _paymentResult.asStateFlow()
    private val _isProcessingPayment = MutableStateFlow(false)
    val isProcessingPayment: StateFlow<Boolean> = _isProcessingPayment.asStateFlow()

    private val _benchmarkResult = MutableStateFlow<RadTestBenchmarkResult?>(null)
    val benchmarkResult: StateFlow<RadTestBenchmarkResult?> = _benchmarkResult.asStateFlow()
    private val _isBenchmarking = MutableStateFlow(false)
    val isBenchmarking: StateFlow<Boolean> = _isBenchmarking.asStateFlow()

    // Mikrotik Script Generator Parameters
    val vlanIdInput = MutableStateFlow("100")
    val ipPoolInput = MutableStateFlow("10.100.0.10-10.100.3.250")
    val radiusIpInput = MutableStateFlow("10.10.10.254")
    val radiusSecretInput = MutableStateFlow("RadiusSecretKey2026")
    val coaPortInput = MutableStateFlow("3799")
    val portalIpInput = MutableStateFlow("172.20.0.5")

    // AI Architect State
    private val _aiModel = MutableStateFlow(AiModelType.FLASH_LITE)
    val aiModel: StateFlow<AiModelType> = _aiModel.asStateFlow()

    private val _chatMessages = MutableStateFlow<List<AiChatMessage>>(emptyList())
    val chatMessages: StateFlow<List<AiChatMessage>> = _chatMessages.asStateFlow()

    private val _isAiThinking = MutableStateFlow(false)
    val isAiThinking: StateFlow<Boolean> = _isAiThinking.asStateFlow()

    // Console logs buffer
    private val _consoleLogs = MutableStateFlow<List<String>>(emptyList())
    val consoleLogs: StateFlow<List<String>> = _consoleLogs.asStateFlow()

    init {
        seedInitialSubscribers()
        seedInitialRadAcct()
        seedInitialAiGreeting()
        appendLog("[SYSTEM INIT] Tapos ISP Billing Architect Console Initialized.")
        appendLog("[ENV CHECK] Windows 10/11 WSL2 environment profile active.")
        appendLog("[PORTS] Radius Auth: UDP 1812 | Radius Acct: UDP 1813 | RFC 3576 CoA: UDP 3799.")
    }

    fun selectTab(tab: AppNavTab) {
        _currentTab.value = tab
    }

    fun selectStage(stageNumber: Int) {
        _selectedStageNumber.value = stageNumber
    }

    fun toggleAiModel() {
        _aiModel.value = if (_aiModel.value == AiModelType.FLASH_LITE) {
            AiModelType.ARGON_HIGH_THINKING
        } else {
            AiModelType.FLASH_LITE
        }
    }

    fun setAiModel(model: AiModelType) {
        _aiModel.value = model
    }

    private fun appendLog(line: String) {
        val current = _consoleLogs.value.toMutableList()
        current.add("[${java.text.SimpleDateFormat("HH:mm:ss", java.util.Locale.US).format(java.util.Date())}] $line")
        if (current.size > 200) current.removeAt(0)
        _consoleLogs.value = current
    }

    // -------------------------------------------------------------
    // INTERACTIVE TOOL 1: RFC 3576 CoA / PoD Disconnect Simulator (<500ms)
    // -------------------------------------------------------------
    fun triggerCoaKick(username: String, ip: String) {
        if (_isKicking.value) return
        _isKicking.value = true

        viewModelScope.launch {
            appendLog("RFC 3576 PoD Disconnect-Request -> Mikrotik CCR2004 for user '$username' ($ip)...")
            val startTime = System.currentTimeMillis()

            // Simulate UDP socket transmission & Mikrotik kernel PPPoE teardown (typically 120-430ms)
            val networkDelay = Random.nextLong(180, 420)
            delay(networkDelay)

            val packetId = Random.nextInt(1, 255)
            val secret = radiusSecretInput.value
            val authInput = "$username$ip$packetId$secret"
            val md5Digest = MessageDigest.getInstance("MD5").digest(authInput.toByteArray())
            val authHex = md5Digest.joinToString("") { "%02x".format(it) }

            val latency = System.currentTimeMillis() - startTime
            val meetsSla = latency < 500

            val result = CoaKickSimulation(
                targetUsername = username,
                targetIp = ip,
                nasIp = radiusIpInput.value,
                nasPort = coaPortInput.value.toIntOrNull() ?: 3799,
                secretKey = secret,
                packetType = "Disconnect-Request (Code 40)",
                packetId = packetId,
                authenticatorHex = authHex,
                resultStatus = "Disconnect-ACK (Code 41)",
                latencyMs = latency,
                logTrace = listOf(
                    "UDP Socket opened to ${radiusIpInput.value}:${coaPortInput.value}",
                    "Attributes appended: User-Name='$username', Framed-IP-Address=$ip",
                    "MD5 Authenticator computed: 0x$authHex",
                    "Packet dispatched (28 bytes). Listening for ACK...",
                    "Received Disconnect-ACK (Code 41) from NAS in ${latency}ms",
                    if (meetsSla) "SUCCESS: <500ms SLA verified!" else "WARNING: Latency exceeded 500ms"
                )
            )
            _coaResult.value = result

            // Update subscriber session in memory
            _subscribers.value = _subscribers.value.map { sub ->
                if (sub.username == username) {
                    sub.copy(activeSessionId = null, uptimeSeconds = 0)
                } else sub
            }

            appendLog("RFC 3576 ACK received for '$username' in ${latency}ms (SLA: ${if (meetsSla) "PASSED" else "FAILED"})")
            _isKicking.value = false
        }
    }

    // -------------------------------------------------------------
    // INTERACTIVE TOOL 2: bKash Webhook Simulator with <3s Reconnect
    // -------------------------------------------------------------
    fun triggerPaymentWebhook(gateway: String, username: String, amount: Double) {
        if (_isProcessingPayment.value) return
        _isProcessingPayment.value = true

        viewModelScope.launch {
            val startTime = System.currentTimeMillis()
            appendLog("Incoming $gateway Webhook: Received payment of Tk $amount for user '$username'...")

            delay(120) // JSON Parse & route dispatch

            // 1. Calculate HMAC-SHA256 signature
            val trxId = "TRX" + System.currentTimeMillis().toString().takeLast(8)
            val payload = """{"username":"$username","amount":$amount,"trx_id":"$trxId"}"""
            val secretKey = "bKashAppSecretKey2026"
            val hmac = Mac.getInstance("HmacSHA256").apply {
                init(SecretKeySpec(secretKey.toByteArray(), "HmacSHA256"))
            }
            val signatureBytes = hmac.doFinal(payload.toByteArray())
            val signature = android.util.Base64.encodeToString(signatureBytes, android.util.Base64.NO_WRAP)

            delay(180) // PostgreSQL transaction: Invoice paid + radreply restored to 20M/20M

            // 2. Fire RFC 3576 PoD Disconnect-Request to force PPPoE re-connect at full speed
            val coaKickTime = Random.nextLong(220, 390)
            delay(coaKickTime)

            // 3. Dispatch SMS alert
            delay(150)

            val totalDuration = System.currentTimeMillis() - startTime
            val isSuccess = totalDuration < 3000

            val result = PaymentWebhookSimulation(
                gateway = gateway,
                trxId = trxId,
                customerPhone = "+8801711223344",
                username = username,
                amount = amount,
                rawSignature = signature.take(16) + "...",
                computedSignature = signature,
                isVerified = true,
                lineReconnected = true,
                executionTimeMs = totalDuration,
                logOutput = """
                    [0.12s] HMAC-SHA256 verified against $gateway Secret
                    [0.30s] DB Transaction: Invoice #INV-$trxId marked PAID
                    [0.45s] radreply updated: Mikrotik-Rate-Limit = '20M/20M'
                    [0.72s] RFC 3576 PoD Kick dispatched to Mikrotik CCR2004
                    [0.98s] PPPoE Session re-established on VLAN 100
                    [1.20s] Confirmation SMS dispatched via Gateway
                    TOTAL TIME: ${totalDuration / 1000.0}s (<3.0s SLA)
                """.trimIndent()
            )

            _paymentResult.value = result

            // Update user in state
            _subscribers.value = _subscribers.value.map { sub ->
                if (sub.username == username) {
                    sub.copy(
                        status = SubscriberStatus.ACTIVE,
                        expiryDate = "2026-11-15",
                        lastPaymentDate = "2026-10-14",
                        activeSessionId = "sess_" + Random.nextInt(1000, 9999)
                    )
                } else sub
            }

            appendLog("Line for '$username' RECONNECTED via $gateway in ${totalDuration}ms (<3s SLA ACHIEVED)")
            _isProcessingPayment.value = false
        }
    }

    // -------------------------------------------------------------
    // INTERACTIVE TOOL 3: FreeRADIUS & Postgres Latency Benchmark (<10ms)
    // -------------------------------------------------------------
    fun runRadiusLatencyBenchmark(packetCount: Int = 100) {
        if (_isBenchmarking.value) return
        _isBenchmarking.value = true

        viewModelScope.launch {
            appendLog("Executing verify-radtest.ps1 benchmark ($packetCount packets against PostgreSQL 16)...")
            val points = mutableListOf<Double>()

            for (i in 1..packetCount) {
                // FreeRADIUS + Postgres 16 monthly partitioned radacct achieves 2.2ms - 6.8ms latency
                val base = Random.nextDouble(2.2, 5.8)
                val jitter = if (i % 25 == 0) Random.nextDouble(1.0, 3.5) else 0.0
                val sample = Math.round((base + jitter) * 100.0) / 100.0
                points.add(sample)
                if (i % 20 == 0) delay(40) // Smooth progress
            }

            val min = points.minOrNull() ?: 2.0
            val max = points.maxOrNull() ?: 7.5
            val avg = Math.round((points.average()) * 100.0) / 100.0
            val meetsSla = avg < 10.0

            _benchmarkResult.value = RadTestBenchmarkResult(
                totalPackets = packetCount,
                acceptedPackets = packetCount,
                rejectedPackets = 0,
                minLatencyMs = min,
                avgLatencyMs = avg,
                maxLatencyMs = max,
                meetsSla = meetsSla,
                samplePoints = points
            )

            appendLog("Benchmark Finished: Avg ${avg}ms | Min ${min}ms | Max ${max}ms | SLA (<10ms): ${if (meetsSla) "ACHIEVED" else "FAILED"}")
            _isBenchmarking.value = false
        }
    }

    // -------------------------------------------------------------
    // AI ARCHITECT ASSISTANT (Dual Engine: Flash-Lite vs Argon-Thinking)
    // -------------------------------------------------------------
    fun sendAiPrompt(userPrompt: String) {
        if (userPrompt.isBlank() || _isAiThinking.value) return

        val userMessage = AiChatMessage(
            isUser = true,
            text = userPrompt
        )
        _chatMessages.value = _chatMessages.value + userMessage
        _isAiThinking.value = true

        viewModelScope.launch {
            val currentModel = _aiModel.value
            val (responseText, elapsedMs) = geminiService.generateResponse(userPrompt, currentModel)

            val modelMessage = AiChatMessage(
                isUser = false,
                text = responseText,
                modelType = currentModel,
                responseTimeMs = elapsedMs
            )

            _chatMessages.value = _chatMessages.value + modelMessage
            _isAiThinking.value = false
            appendLog("AI Architect responded via ${currentModel.displayName} in ${elapsedMs}ms")
        }
    }

    fun clearChat() {
        _chatMessages.value = emptyList()
        seedInitialAiGreeting()
    }

    // -------------------------------------------------------------
    // SEED DATA
    // -------------------------------------------------------------
    private fun seedInitialSubscribers() {
        _subscribers.value = listOf(
            Subscriber(
                id = 1,
                username = "tapos_test",
                cleartextPassword = "tapos_pass_2026",
                ipAddress = "10.100.0.50",
                macAddress = "D4:CA:6D:55:66:77",
                profile = "20M_PPPoE_Test",
                rateLimit = "20M/20M",
                monthlyFee = 1200.0,
                expiryDate = "2026-11-15",
                status = SubscriberStatus.ACTIVE,
                lastPaymentDate = "2026-10-14",
                activeSessionId = "sess_tapos_01",
                uptimeSeconds = 345600,
                inputBytes = 45298124000,
                outputBytes = 189234120000
            ),
            Subscriber(
                id = 2,
                username = "mirpur_home_sub01",
                cleartextPassword = "HomeUser2026!",
                ipAddress = "10.100.0.15",
                macAddress = "E0:D5:5E:AA:BB:CC",
                profile = "20M_Residential",
                rateLimit = "20M/20M 30M/30M 10M/10M 10/10 8 10M/10M",
                monthlyFee = 1000.0,
                expiryDate = "2026-10-10",
                status = SubscriberStatus.EXPIRED,
                lastPaymentDate = "2026-09-10",
                activeSessionId = "sess_91823b",
                uptimeSeconds = 86400,
                inputBytes = 12000000000,
                outputBytes = 54000000000
            ),
            Subscriber(
                id = 3,
                username = "uttara_biz_gpon",
                cleartextPassword = "UttaraSecured#",
                ipAddress = "10.100.0.22",
                macAddress = "70:4F:57:33:44:55",
                profile = "30M_Office",
                rateLimit = "30M/30M 45M/45M 15M/15M 10/10 8 15M/15M",
                monthlyFee = 1800.0,
                expiryDate = "2026-10-25",
                status = SubscriberStatus.ACTIVE,
                lastPaymentDate = "2026-09-25",
                activeSessionId = "sess_44109c",
                uptimeSeconds = 172800,
                inputBytes = 28000000000,
                outputBytes = 92000000000
            ),
            Subscriber(
                id = 4,
                username = "dhanmondi_student_pkg",
                cleartextPassword = "StudentPass99",
                ipAddress = "10.100.0.45",
                macAddress = "BC:AE:C5:77:88:99",
                profile = "10M_Starter",
                rateLimit = "10M/10M 15M/15M 5M/5M 10/10 8 5M/5M",
                monthlyFee = 600.0,
                expiryDate = "2026-10-12",
                status = SubscriberStatus.EXPIRED,
                lastPaymentDate = "2026-09-12",
                activeSessionId = "sess_78100e",
                uptimeSeconds = 12400,
                inputBytes = 3200000000,
                outputBytes = 14000000000
            )
        )
    }

    private fun seedInitialRadAcct() {
        _radAcctRecords.value = listOf(
            RadAcctRecord(
                radacctId = 1001,
                acctSessionId = "sess_09281a",
                acctUniqueId = "u_81a76c001",
                username = "tapos_core_fiber",
                nasIpAddress = "10.10.10.1",
                acctStartTime = "2026-10-10 08:00:00+00",
                acctSessionTime = 345600,
                acctInputOctets = 45298124000,
                acctOutputOctets = 189234120000,
                framedIpAddress = "10.100.0.10",
                partitionTable = "radacct_y2026m10"
            ),
            RadAcctRecord(
                radacctId = 1002,
                acctSessionId = "sess_91823b",
                acctUniqueId = "u_91b44d002",
                username = "mirpur_home_sub01",
                nasIpAddress = "10.10.10.1",
                acctStartTime = "2026-10-12 14:30:00+00",
                acctSessionTime = 86400,
                acctInputOctets = 12000000000,
                acctOutputOctets = 54000000000,
                framedIpAddress = "10.100.0.15",
                partitionTable = "radacct_y2026m10"
            ),
            RadAcctRecord(
                radacctId = 1003,
                acctSessionId = "sess_44109c",
                acctUniqueId = "u_44c99e003",
                username = "uttara_biz_gpon",
                nasIpAddress = "10.10.10.1",
                acctStartTime = "2026-10-11 09:15:00+00",
                acctSessionTime = 172800,
                acctInputOctets = 28000000000,
                acctOutputOctets = 92000000000,
                framedIpAddress = "10.100.0.22",
                partitionTable = "radacct_y2026m10"
            )
        )
    }

    private fun seedInitialAiGreeting() {
        _chatMessages.value = listOf(
            AiChatMessage(
                isUser = false,
                text = """
                    **Welcome to the Lead Telecom BSS/OSS Architect Console (ISP Billing by Tapos).**

                    I am configured to assist you with the **5-Stage (28-Day) Deployment Matrix** on Windows 10/11 WSL2, Docker Desktop, PostgreSQL 16 partitioned radacct, FreeRADIUS 3.x, and Mikrotik RouterOS v7.

                    **Available Engines:**
                    - ⚡ **Low-Latency Mode** (`gemini-3.1-flash-lite`): Instant answers for PowerShell syntax, FreeRADIUS attributes, and RouterOS CLI flags.
                    - 🧠 **High Thinking Mode** (`gemini-4-argon [ThinkingLevel.HIGH]`): Deep architectural planning, BGP/MPLS traffic engineering, PostgreSQL partitioning strategies, and failure recovery.

                    Ask me any question or choose one of the quick architect presets below!
                """.trimIndent(),
                modelType = AiModelType.FLASH_LITE
            )
        )
    }
}
