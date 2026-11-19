package com.example.viewmodel

import android.content.Context
import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import com.example.data.model.*
import com.example.data.network.CloudRunApiClient
import com.example.data.network.GeminiService
import kotlinx.coroutines.delay
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.launch
import java.security.MessageDigest
import java.text.SimpleDateFormat
import java.util.Date
import java.util.Locale
import javax.crypto.Mac
import javax.crypto.spec.SecretKeySpec
import kotlin.random.Random

enum class AppNavTab(val title: String, val shortLabel: String) {
    NOC("NOC Command Center", "NOC"),
    SUBSCRIBERS("Subscriber Portal", "Portal"),
    MFS_WEBHOOK("MFS Webhook Monitor", "MFS"),
    COA_CONTROLS("FreeRADIUS CoA (RFC 5176)", "CoA"),
    TOOLKIT("Architect Toolkit", "Toolkit")
}

enum class ToolkitSubTab(val title: String) {
    TR069_ACS("TR-069 ACS"),
    CGNAT_BTRC("BTRC CGNAT"),
    VSA_COMPILER("VSA Compiler"),
    MATRIX("28-Day Matrix"),
    POWERSHELL("PowerShell Vault"),
    ROUTER_OS("RouterOS v7 Builder"),
    AI_ARCHITECT("AI Copilot")
}

enum class MfsSimulationMode(val label: String) {
    VALID_UNBLOCK("Valid Payment (200 OK)"),
    DUPLICATE_TRX("Duplicate TrxID (409 Lock)"),
    INVALID_HMAC("Bad HMAC (403 Reject)")
}

class IspArchitectViewModel : ViewModel() {

    private val geminiService = GeminiService()
    private val cloudRunClient = CloudRunApiClient()
    private var appContext: Context? = null

    // Navigation State
    private val _currentTab = MutableStateFlow(AppNavTab.NOC)
    val currentTab: StateFlow<AppNavTab> = _currentTab.asStateFlow()

    private val _toolkitSubTab = MutableStateFlow(ToolkitSubTab.MATRIX)
    val toolkitSubTab: StateFlow<ToolkitSubTab> = _toolkitSubTab.asStateFlow()

    private val _selectedStageNumber = MutableStateFlow(1)
    val selectedStageNumber: StateFlow<Int> = _selectedStageNumber.asStateFlow()

    // Cloud Run Backend Configuration State
    private val _cloudRunState = MutableStateFlow(CloudRunConnectionState())
    val cloudRunState: StateFlow<CloudRunConnectionState> = _cloudRunState.asStateFlow()

    private val _isCloudConfigDialogOpen = MutableStateFlow(false)
    val isCloudConfigDialogOpen: StateFlow<Boolean> = _isCloudConfigDialogOpen.asStateFlow()

    // NOC Command Center State (128 Citus Shards + Telemetry)
    private val _nocTelemetry = MutableStateFlow(NocTelemetry())
    val nocTelemetry: StateFlow<NocTelemetry> = _nocTelemetry.asStateFlow()

    private val _citusShards = MutableStateFlow<List<CitusShardHealth>>(emptyList())
    val citusShards: StateFlow<List<CitusShardHealth>> = _citusShards.asStateFlow()

    private val _selectedShardId = MutableStateFlow<Int?>(0)
    val selectedShardId: StateFlow<Int?> = _selectedShardId.asStateFlow()

    // Subscriber Portal & Accounting State
    private val _subscribers = MutableStateFlow<List<Subscriber>>(emptyList())
    val subscribers: StateFlow<List<Subscriber>> = _subscribers.asStateFlow()

    private val _radAcctRecords = MutableStateFlow<List<RadAcctRecord>>(emptyList())
    val radAcctRecords: StateFlow<List<RadAcctRecord>> = _radAcctRecords.asStateFlow()

    private val _subscriberSearch = MutableStateFlow("")
    val subscriberSearch: StateFlow<String> = _subscriberSearch.asStateFlow()

    private val _subscriberStatusFilter = MutableStateFlow<SubscriberStatus?>(null)
    val subscriberStatusFilter: StateFlow<SubscriberStatus?> = _subscriberStatusFilter.asStateFlow()

    private val _subscriberDivisionFilter = MutableStateFlow("All")
    val subscriberDivisionFilter: StateFlow<String> = _subscriberDivisionFilter.asStateFlow()

    private val _selectedPortalSubscriber = MutableStateFlow<Subscriber?>(null)
    val selectedPortalSubscriber: StateFlow<Subscriber?> = _selectedPortalSubscriber.asStateFlow()

    // MFS Webhook Monitor State
    private val _mfsWebhookEvents = MutableStateFlow<List<MfsWebhookEvent>>(emptyList())
    val mfsWebhookEvents: StateFlow<List<MfsWebhookEvent>> = _mfsWebhookEvents.asStateFlow()

    private val _paymentResult = MutableStateFlow<PaymentWebhookSimulation?>(null)
    val paymentResult: StateFlow<PaymentWebhookSimulation?> = _paymentResult.asStateFlow()

    private val _isProcessingPayment = MutableStateFlow(false)
    val isProcessingPayment: StateFlow<Boolean> = _isProcessingPayment.asStateFlow()

    // FreeRADIUS CoA (RFC 5176 UDP 3799) State
    private val _coaResult = MutableStateFlow<CoaKickSimulation?>(null)
    val coaResult: StateFlow<CoaKickSimulation?> = _coaResult.asStateFlow()

    private val _isKicking = MutableStateFlow(false)
    val isKicking: StateFlow<Boolean> = _isKicking.asStateFlow()

    private val _benchmarkResult = MutableStateFlow<RadTestBenchmarkResult?>(null)
    val benchmarkResult: StateFlow<RadTestBenchmarkResult?> = _benchmarkResult.asStateFlow()

    private val _isBenchmarking = MutableStateFlow(false)
    val isBenchmarking: StateFlow<Boolean> = _isBenchmarking.asStateFlow()

    // RouterOS v7 Generator Parameters (non-secret lab defaults)
    val vlanIdInput = MutableStateFlow("100")
    val ipPoolInput = MutableStateFlow("10.100.0.10-10.100.3.250")
    val radiusIpInput = MutableStateFlow("10.10.10.254")
    val radiusSecretInput = MutableStateFlow("<RADIUS_SHARED_SECRET>")
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

    private val seenTrxIds = mutableSetOf("TRX90817263", "TRX90817299")

    init {
        seedCitusShards()
        seedInitialSubscribers()
        seedInitialRadAcct()
        seedInitialMfsWebhooks()
        seedInitialAiGreeting()
        appendLog("[SYSTEM INIT] Carrier Edition NOC & Portal Console Initialized (128 Citus Shards).")
        appendLog("[MODE] Interactive Local Mock State active. Configure Cloud Run URL for live telemetry.")
        appendLog("[PORTS] Auth: UDP 1812 | Acct: UDP 1813 | RFC 5176 CoA/PoD: UDP 3799.")
    }

    fun attachContext(context: Context) {
        if (appContext != null) return
        appContext = context.applicationContext
        val savedUrl = cloudRunClient.loadSavedBaseUrl(context)
        val useCloud = cloudRunClient.loadUseCloudBackend(context)
        _cloudRunState.value = _cloudRunState.value.copy(
            baseUrl = savedUrl,
            useCloudBackend = useCloud,
            status = if (useCloud && cloudRunClient.isValidHttpsUrl(savedUrl)) {
                BackendConnectionStatus.CONNECTING
            } else {
                BackendConnectionStatus.MOCK_LOCAL
            },
            statusMessage = if (useCloud && savedUrl.isNotBlank()) {
                "Configured Cloud Run endpoint: $savedUrl"
            } else {
                "Using interactive local mock state"
            }
        )
        if (useCloud && cloudRunClient.isValidHttpsUrl(savedUrl)) {
            testCloudRunConnection(savedUrl, enableCloudMode = true)
        }
    }

    fun selectTab(tab: AppNavTab) {
        _currentTab.value = tab
    }

    fun selectToolkitSubTab(subTab: ToolkitSubTab) {
        _toolkitSubTab.value = subTab
    }

    fun selectStage(stageNumber: Int) {
        _selectedStageNumber.value = stageNumber
    }

    fun openCloudConfigDialog() {
        _isCloudConfigDialogOpen.value = true
    }

    fun closeCloudConfigDialog() {
        _isCloudConfigDialogOpen.value = false
    }

    fun switchToLocalMockState() {
        appContext?.let { cloudRunClient.saveConfig(it, _cloudRunState.value.baseUrl, false) }
        _cloudRunState.value = _cloudRunState.value.copy(
            useCloudBackend = false,
            status = BackendConnectionStatus.MOCK_LOCAL,
            statusMessage = "Interactive local mock state active",
            lastCheckedLabel = nowTimestamp()
        )
        _nocTelemetry.value = _nocTelemetry.value.copy(sourceLabel = "Local Mock State")
        appendLog("[BACKEND] Switched to Interactive Local Mock State.")
    }

    fun testCloudRunConnection(rawUrl: String, enableCloudMode: Boolean = true) {
        val normalized = cloudRunClient.normalizeUrl(rawUrl)
        viewModelScope.launch {
            _cloudRunState.value = _cloudRunState.value.copy(
                baseUrl = normalized,
                useCloudBackend = enableCloudMode,
                status = BackendConnectionStatus.CONNECTING,
                statusMessage = "Probing $normalized/health..."
            )
            appendLog("[CLOUD RUN] Probing $normalized/health ...")

            val probe = cloudRunClient.probeHealth(normalized)
            appContext?.let { cloudRunClient.saveConfig(it, normalized, enableCloudMode) }

            if (probe.isReachable) {
                _cloudRunState.value = CloudRunConnectionState(
                    baseUrl = normalized,
                    useCloudBackend = enableCloudMode,
                    status = BackendConnectionStatus.CONNECTED,
                    lastCheckedLabel = nowTimestamp(),
                    lastHttpStatus = probe.httpStatus,
                    statusMessage = probe.message
                )
                appendLog("[CLOUD RUN] SUCCESS: ${probe.message}")
                refreshCloudRunData()
            } else {
                _cloudRunState.value = CloudRunConnectionState(
                    baseUrl = normalized,
                    useCloudBackend = enableCloudMode,
                    status = BackendConnectionStatus.ERROR,
                    lastCheckedLabel = nowTimestamp(),
                    lastHttpStatus = probe.httpStatus,
                    statusMessage = "${probe.message} — serving local mock fallback"
                )
                _nocTelemetry.value = _nocTelemetry.value.copy(sourceLabel = "Mock Fallback (Cloud Run unreachable)")
                appendLog("[CLOUD RUN] WARNING: ${probe.message}. Falling back to local mock state.")
            }
        }
    }

    fun refreshCloudRunData() {
        val state = _cloudRunState.value
        if (!state.useCloudBackend || !cloudRunClient.isValidHttpsUrl(state.baseUrl)) {
            refreshLocalMockTelemetry()
            return
        }
        viewModelScope.launch {
            val telemetryRes = cloudRunClient.fetchNocTelemetry(state.baseUrl)
            telemetryRes.onSuccess { remote ->
                _nocTelemetry.value = remote
                appendLog("[CLOUD RUN] Synced NOC telemetry (${remote.activeSessions} sessions).")
            }.onFailure { err ->
                appendLog("[CLOUD RUN] WARNING: NOC sync failed (${err.message}); keeping mock telemetry.")
            }

            val subsRes = cloudRunClient.fetchSubscribers(state.baseUrl)
            subsRes.onSuccess { remoteSubs ->
                if (remoteSubs.isNotEmpty()) {
                    _subscribers.value = remoteSubs
                    appendLog("[CLOUD RUN] Synced ${remoteSubs.size} subscribers from Cloud Run.")
                }
            }
        }
    }

    fun refreshLocalMockTelemetry() {
        val current = _nocTelemetry.value
        val nextThroughput = (435.0 + Random.nextDouble(0.0, 32.0))
        val nextLatency = (0.31 + Random.nextDouble(0.0, 0.14))
        val updatedHistory = (current.throughputHistoryGbps.drop(1) + nextThroughput.toFloat())
        val updatedLatHistory = (current.aaaLatencyHistoryMs.drop(1) + nextLatency.toFloat())
        _nocTelemetry.value = current.copy(
            activeSessions = current.activeSessions + Random.nextLong(-1200, 2400),
            clusterThroughputGbps = Math.round(nextThroughput * 10.0) / 10.0,
            aaaLatencyMs = Math.round(nextLatency * 100.0) / 100.0,
            throughputHistoryGbps = updatedHistory,
            aaaLatencyHistoryMs = updatedLatHistory,
            sourceLabel = "Local Mock State"
        )
    }

    // -------------------------------------------------------------
    // NOC COMMAND CENTER: 128-Shard Citus Matrix & Reconnect Storm
    // -------------------------------------------------------------
    fun selectShard(shardId: Int) {
        _selectedShardId.value = shardId
    }

    fun triggerReconnectStorm() {
        if (_nocTelemetry.value.stormActive) return
        viewModelScope.launch {
            _nocTelemetry.value = _nocTelemetry.value.copy(stormActive = true)
            appendLog("[STORM] Reconnect Storm Initiated: Simulating 500,000 PPPoE re-auth req/sec across 128 Citus shards...")

            // Stress a handful of shards transiently
            _citusShards.value = _citusShards.value.map { shard ->
                if (shard.shardId % 17 == 0) {
                    shard.copy(
                        status = ShardHealthStatus.ELEVATED,
                        loadPercent = (shard.loadPercent + 28).coerceAtMost(94),
                        latencyMs = Math.round((shard.latencyMs + 0.35) * 100.0) / 100.0
                    )
                } else shard
            }

            delay(1400)
            refreshLocalMockTelemetry()
            _nocTelemetry.value = _nocTelemetry.value.copy(
                stormActive = false,
                aaaLatencyMs = 0.39,
                redisHitRatePercent = 99.6
            )
            seedCitusShards()
            appendLog("[STORM] SUCCESS: Redis 7 Fast-Path absorbed 99.6% lookups at 0.39ms avg SLA.")
        }
    }

    // -------------------------------------------------------------
    // SUBSCRIBER PORTAL: Filtering, Inspection, Provisioning
    // -------------------------------------------------------------
    fun updateSubscriberSearch(query: String) {
        _subscriberSearch.value = query
    }

    fun updateSubscriberStatusFilter(status: SubscriberStatus?) {
        _subscriberStatusFilter.value = status
    }

    fun updateSubscriberDivisionFilter(division: String) {
        _subscriberDivisionFilter.value = division
    }

    fun openSubscriberPortal(subscriber: Subscriber) {
        _selectedPortalSubscriber.value = subscriber
    }

    fun closeSubscriberPortal() {
        _selectedPortalSubscriber.value = null
    }

    fun provisionSubscriber(
        username: String,
        division: String,
        profile: String,
        rateLimit: String,
        monthlyFee: Double
    ) {
        val cleanUser = username.trim().lowercase().replace(" ", "_")
        if (cleanUser.isEmpty()) return
        val nextId = (_subscribers.value.maxOfOrNull { it.id } ?: 100L) + 1L
        val shardId = computeCitusShardId(cleanUser)
        val portBlock = computeCgnatPortBlock(nextId.toInt())
        val newSub = Subscriber(
            id = nextId,
            username = cleanUser,
            cleartextPassword = "***",
            ipAddress = "10.100.${(nextId / 240) % 4}.${(nextId % 240) + 10}",
            macAddress = "D4:CA:6D:${"%02X".format((nextId * 7) % 255)}:${"%02X".format((nextId * 13) % 255)}:11",
            profile = profile,
            rateLimit = rateLimit,
            monthlyFee = monthlyFee,
            expiryDate = "2026-12-16",
            status = SubscriberStatus.ACTIVE,
            lastPaymentDate = "2026-11-16",
            activeSessionId = "sess_${nextId}_init",
            uptimeSeconds = 60,
            inputBytes = 128_000_000L,
            outputBytes = 512_000_000L,
            division = division,
            shardId = shardId,
            ontSerial = "HW-GPON-${1000 + nextId}",
            opticalRxDbm = -17.9,
            cgnatPublicIp = "103.112.40.${(nextId % 240) + 1}",
            cgnatPortStart = portBlock.first,
            cgnatPortEnd = portBlock.second
        )
        _subscribers.value = listOf(newSub) + _subscribers.value
        appendLog("[TR-069 / CITUS] Provisioned '$cleanUser' on Shard #$shardId ($division) | CGNAT ${newSub.cgnatPublicIp}:${portBlock.first}-${portBlock.second}")
    }

    // -------------------------------------------------------------
    // TR-069 GENIEACS & BTRC CGNAT RFC 6888 OPERATIONS
    // -------------------------------------------------------------
    fun triggerCwmpInform(username: String) {
        _subscribers.value = _subscribers.value.map { sub ->
            if (sub.username == username) {
                val delta = Random.nextDouble(-0.4, 0.4)
                val updatedRx = Math.round(((sub.opticalRxDbm + delta).coerceIn(-29.0, -14.0)) * 10.0) / 10.0
                sub.copy(opticalRxDbm = updatedRx)
            } else sub
        }
        appendLog("[TR-069 CWMP] Inform received from ONT for '$username'. Optical Rx telemetry refreshed.")
    }

    fun pushTr069PppoePreset(username: String) {
        val sub = _subscribers.value.find { it.username == username } ?: return
        appendLog("[TR-069 NBI] Pushed PPPoE + Wi-Fi preset to ${sub.ontSerial} (Shard #${sub.shardId}) for '$username'.")
    }

    fun rebootOnt(username: String) {
        val sub = _subscribers.value.find { it.username == username } ?: return
        appendLog("[TR-069 RPC] Reboot command queued on GenieACS for ${sub.ontSerial} ('$username').")
    }

    fun reverseLookupCgnat(publicIp: String, port: Int): Subscriber? {
        val cleanIp = publicIp.trim()
        return _subscribers.value.find { sub ->
            sub.cgnatPublicIp == cleanIp && port in sub.cgnatPortStart..sub.cgnatPortEnd
        }
    }

    // -------------------------------------------------------------
    // MFS WEBHOOK MONITOR: bKash / Nagad / SSLCOMMERZ (<3s SLA)
    // -------------------------------------------------------------
    fun triggerPaymentWebhook(
        gateway: String,
        username: String,
        amount: Double,
        mode: MfsSimulationMode = MfsSimulationMode.VALID_UNBLOCK,
        customTrxId: String? = null
    ) {
        if (_isProcessingPayment.value) return
        _isProcessingPayment.value = true

        viewModelScope.launch {
            val startTime = System.currentTimeMillis()
            val trxId = customTrxId?.takeIf { it.isNotBlank() }
                ?: if (mode == MfsSimulationMode.DUPLICATE_TRX) {
                    seenTrxIds.firstOrNull() ?: "TRX90817263"
                } else {
                    "TRX" + System.currentTimeMillis().toString().takeLast(8)
                }

            val cloudState = _cloudRunState.value
            if (cloudState.useCloudBackend &&
                cloudRunClient.isValidHttpsUrl(cloudState.baseUrl) &&
                mode == MfsSimulationMode.VALID_UNBLOCK
            ) {
                appendLog("[MFS Webhook] Dispatching $gateway callback to Cloud Run (${cloudState.baseUrl})...")
                val remoteResult = cloudRunClient.dispatchMfsWebhook(
                    baseUrl = cloudState.baseUrl,
                    gateway = gateway,
                    username = username,
                    amountBdt = amount,
                    trxId = trxId
                )
                if (remoteResult.isSuccess) {
                    val event = remoteResult.getOrThrow()
                    _mfsWebhookEvents.value = listOf(event) + _mfsWebhookEvents.value
                    _paymentResult.value = PaymentWebhookSimulation(
                        gateway = event.gateway,
                        trxId = event.trxId,
                        customerPhone = event.customerPhone,
                        username = event.username,
                        amount = event.amountBdt,
                        rawSignature = "cloud-run-verified",
                        computedSignature = "server-side-hmac",
                        isVerified = event.httpStatus == 200,
                        lineReconnected = event.lineReconnected,
                        executionTimeMs = event.executionTimeMs,
                        logOutput = event.logOutput
                    )
                    if (event.lineReconnected) {
                        activateSubscriberAfterPayment(username)
                    }
                    appendLog("[MFS Webhook] SUCCESS via Cloud Run: ${event.trxId} HTTP ${event.httpStatus} in ${event.executionTimeMs}ms")
                    _isProcessingPayment.value = false
                    return@launch
                } else {
                    appendLog("[MFS Webhook] WARNING: Cloud Run call failed (${remoteResult.exceptionOrNull()?.message}); executing local mock state.")
                }
            }

            appendLog("[MFS Webhook] Incoming $gateway callback: user='$username', amount=Tk $amount, trx_id=$trxId")
            delay(110)

            // Ephemeral simulation-only demo digest (never a real merchant secret)
            val payload = """{"gateway":"$gateway","username":"$username","amount":$amount,"trx_id":"$trxId"}"""
            val ephemeralSimKey = "sim-only-ephemeral-${startTime}"
            val hmac = Mac.getInstance("HmacSHA256").apply {
                init(SecretKeySpec(ephemeralSimKey.toByteArray(), "HmacSHA256"))
            }
            val signature = android.util.Base64.encodeToString(
                hmac.doFinal(payload.toByteArray()),
                android.util.Base64.NO_WRAP
            )

            when (mode) {
                MfsSimulationMode.INVALID_HMAC -> {
                    val elapsed = System.currentTimeMillis() - startTime
                    val event = MfsWebhookEvent(
                        id = "wh_$startTime",
                        gateway = gateway,
                        trxId = trxId,
                        username = username,
                        customerPhone = "+8801711XXXXXX",
                        amountBdt = amount,
                        httpStatus = 403,
                        signatureStatus = WebhookSignatureStatus.INVALID_REJECTED,
                        pipelineCompletedSteps = 1,
                        lineReconnected = false,
                        executionTimeMs = elapsed,
                        timestampLabel = nowTimestamp(),
                        payloadPreview = payload,
                        logOutput = "[${elapsed}ms] HTTP 403 Forbidden: HMAC-SHA256 signature verification failed (mock simulation)."
                    )
                    _mfsWebhookEvents.value = listOf(event) + _mfsWebhookEvents.value
                    _paymentResult.value = PaymentWebhookSimulation(
                        gateway = gateway,
                        trxId = trxId,
                        customerPhone = event.customerPhone,
                        username = username,
                        amount = amount,
                        rawSignature = "INVALID_HEADER_SIG",
                        computedSignature = signature,
                        isVerified = false,
                        lineReconnected = false,
                        executionTimeMs = elapsed,
                        logOutput = event.logOutput
                    )
                    appendLog("[MFS Webhook] FAILED (403): Invalid HMAC signature rejected for $trxId")
                }

                MfsSimulationMode.DUPLICATE_TRX -> {
                    delay(80)
                    val elapsed = System.currentTimeMillis() - startTime
                    val event = MfsWebhookEvent(
                        id = "wh_$startTime",
                        gateway = gateway,
                        trxId = trxId,
                        username = username,
                        customerPhone = "+8801711XXXXXX",
                        amountBdt = amount,
                        httpStatus = 409,
                        signatureStatus = WebhookSignatureStatus.DUPLICATE_LOCKED,
                        pipelineCompletedSteps = 2,
                        lineReconnected = false,
                        executionTimeMs = elapsed,
                        timestampLabel = nowTimestamp(),
                        payloadPreview = payload,
                        logOutput = "[${elapsed}ms] HTTP 409 Conflict: Redis lock trx:$trxId already processed (idempotent skip)."
                    )
                    _mfsWebhookEvents.value = listOf(event) + _mfsWebhookEvents.value
                    _paymentResult.value = PaymentWebhookSimulation(
                        gateway = gateway,
                        trxId = trxId,
                        customerPhone = event.customerPhone,
                        username = username,
                        amount = amount,
                        rawSignature = signature.take(16) + "...",
                        computedSignature = signature,
                        isVerified = true,
                        lineReconnected = false,
                        executionTimeMs = elapsed,
                        logOutput = event.logOutput
                    )
                    appendLog("[MFS Webhook] WARNING (409): Redis idempotency lock blocked duplicate $trxId")
                }

                MfsSimulationMode.VALID_UNBLOCK -> {
                    seenTrxIds.add(trxId)
                    delay(160) // Citus DB PAID + Redis Fast-Path update
                    delay(Random.nextLong(210, 360)) // RFC 5176 CoA UDP 3799 unblock
                    delay(120) // SMS alert

                    val totalDuration = System.currentTimeMillis() - startTime
                    val logText = """
                        [0.11s] Mock HMAC-SHA256 check passed (server secret verified in prod)
                        [0.24s] Redis idempotency lock acquired: trx:$trxId
                        [0.36s] Citus Shard #${computeCitusShardId(username)} updated -> ACTIVE & PAID
                        [0.64s] RFC 5176 CoA dispatched on UDP 3799 -> CoA-ACK
                        [0.82s] PPPoE session re-established at subscribed tariff (<3.0s SLA)
                    """.trimIndent()

                    val event = MfsWebhookEvent(
                        id = "wh_$startTime",
                        gateway = gateway,
                        trxId = trxId,
                        username = username,
                        customerPhone = "+8801711XXXXXX",
                        amountBdt = amount,
                        httpStatus = 200,
                        signatureStatus = WebhookSignatureStatus.VERIFIED_MOCK,
                        pipelineCompletedSteps = 5,
                        lineReconnected = true,
                        executionTimeMs = totalDuration,
                        timestampLabel = nowTimestamp(),
                        payloadPreview = payload,
                        logOutput = logText
                    )
                    _mfsWebhookEvents.value = listOf(event) + _mfsWebhookEvents.value
                    _paymentResult.value = PaymentWebhookSimulation(
                        gateway = gateway,
                        trxId = trxId,
                        customerPhone = event.customerPhone,
                        username = username,
                        amount = amount,
                        rawSignature = signature.take(16) + "...",
                        computedSignature = signature,
                        isVerified = true,
                        lineReconnected = true,
                        executionTimeMs = totalDuration,
                        logOutput = logText
                    )
                    activateSubscriberAfterPayment(username)
                    appendLog("[MFS Webhook] SUCCESS: '$username' reconnected via $gateway in ${totalDuration}ms (<3s SLA ACHIEVED)")
                }
            }
            _isProcessingPayment.value = false
        }
    }

    private fun activateSubscriberAfterPayment(username: String) {
        _subscribers.value = _subscribers.value.map { sub ->
            if (sub.username == username) {
                sub.copy(
                    status = SubscriberStatus.ACTIVE,
                    expiryDate = "2026-12-16",
                    lastPaymentDate = "2026-11-16",
                    activeSessionId = "sess_" + Random.nextInt(1000, 9999)
                )
            } else sub
        }
        _selectedPortalSubscriber.value?.let { current ->
            if (current.username == username) {
                _selectedPortalSubscriber.value = _subscribers.value.find { it.username == username }
            }
        }
    }

    // -------------------------------------------------------------
    // FREERADIUS CoA CONTROLS: RFC 5176 UDP 3799 (<500ms SLA)
    // -------------------------------------------------------------
    fun triggerCoaKick(username: String, ip: String) {
        triggerCoaAction(
            username = username,
            ip = ip,
            actionType = CoaActionType.DISCONNECT_POD,
            vendor = NasVendor.MIKROTIK_CCR2004,
            rateLimitOverride = "20M/20M"
        )
    }

    fun triggerCoaAction(
        username: String,
        ip: String,
        actionType: CoaActionType,
        vendor: NasVendor,
        rateLimitOverride: String
    ) {
        if (_isKicking.value) return
        _isKicking.value = true

        viewModelScope.launch {
            val cloudState = _cloudRunState.value
            if (cloudState.useCloudBackend && cloudRunClient.isValidHttpsUrl(cloudState.baseUrl)) {
                appendLog("[RFC 5176] Dispatching ${actionType.label} to Cloud Run (${cloudState.baseUrl})...")
                val remoteRes = cloudRunClient.dispatchRadiusCoa(
                    baseUrl = cloudState.baseUrl,
                    username = username,
                    framedIp = ip,
                    actionType = actionType,
                    vendor = vendor,
                    rateLimit = rateLimitOverride
                )
                if (remoteRes.isSuccess) {
                    val res = remoteRes.getOrThrow()
                    _coaResult.value = res
                    applyCoaStateChange(username, actionType, rateLimitOverride)
                    appendLog("[RFC 5176] SUCCESS via Cloud Run: ${res.resultStatus} in ${res.latencyMs}ms")
                    _isKicking.value = false
                    return@launch
                } else {
                    appendLog("[RFC 5176] WARNING: Cloud Run unreachable (${remoteRes.exceptionOrNull()?.message}); using mock simulator.")
                }
            }

            appendLog("RFC 5176 ${actionType.label} -> ${vendor.displayName} for '$username' ($ip) on UDP 3799...")
            val startTime = System.currentTimeMillis()
            val networkDelay = Random.nextLong(140, 390)
            delay(networkDelay)

            val packetId = Random.nextInt(1, 255)
            val authInput = "$username|$ip|$packetId|${actionType.rfcCode}|$startTime"
            val md5Digest = MessageDigest.getInstance("MD5").digest(authInput.toByteArray())
            val authHex = md5Digest.joinToString("") { "%02x".format(it) }

            val latency = System.currentTimeMillis() - startTime
            val meetsSla = latency < 500
            val ackStatus = if (actionType == CoaActionType.DISCONNECT_POD) {
                "Disconnect-ACK (Code 41)"
            } else {
                "CoA-ACK (Code 44)"
            }

            val vsaLine = when (vendor) {
                NasVendor.MIKROTIK_CCR2004 -> "Mikrotik-Rate-Limit := '$rateLimitOverride'"
                NasVendor.HUAWEI_NE8000 -> "Huawei-Input-Peak-Information-Rate := '${rateLimitOverride.substringBefore("/")}'"
                NasVendor.JUNIPER_MX -> "ERX-Ingress-Policy-Name := 'POL_${rateLimitOverride.replace("/", "_")}'"
            }

            val result = CoaKickSimulation(
                targetUsername = username,
                targetIp = ip,
                nasIp = radiusIpInput.value,
                nasPort = coaPortInput.value.toIntOrNull() ?: 3799,
                secretKey = "mock-simulator-only",
                packetType = "${actionType.label} (Code ${actionType.rfcCode})",
                packetId = packetId,
                authenticatorHex = authHex,
                resultStatus = ackStatus,
                latencyMs = latency,
                logTrace = listOf(
                    "UDP/3799 socket -> ${radiusIpInput.value}:${coaPortInput.value} (${vendor.displayName})",
                    "Attributes: User-Name='$username', Framed-IP-Address=$ip",
                    "Vendor VSA: $vsaLine",
                    "RFC 5176 Message-Authenticator (sim): 0x$authHex",
                    "Received $ackStatus in ${latency}ms",
                    if (meetsSla) "SUCCESS: <500ms SLA verified!" else "WARNING: Latency exceeded 500ms"
                )
            )
            _coaResult.value = result
            applyCoaStateChange(username, actionType, rateLimitOverride)
            appendLog("RFC 5176 $ackStatus for '$username' in ${latency}ms (SLA: ${if (meetsSla) "PASSED" else "FAILED"})")
            _isKicking.value = false
        }
    }

    private fun applyCoaStateChange(username: String, actionType: CoaActionType, rateLimit: String) {
        _subscribers.value = _subscribers.value.map { sub ->
            if (sub.username != username) return@map sub
            when (actionType) {
                CoaActionType.DISCONNECT_POD -> sub.copy(activeSessionId = null, uptimeSeconds = 0)
                CoaActionType.COA_RATE_LIMIT -> sub.copy(rateLimit = rateLimit)
                CoaActionType.COA_WALLED_GARDEN -> sub.copy(
                    status = SubscriberStatus.EXPIRED,
                    rateLimit = "512k/512k (WALLED_GARDEN)"
                )
                CoaActionType.COA_RESTORE -> sub.copy(
                    status = SubscriberStatus.ACTIVE,
                    rateLimit = rateLimit,
                    activeSessionId = sub.activeSessionId ?: "sess_${Random.nextInt(1000, 9999)}"
                )
            }
        }
    }

    // -------------------------------------------------------------
    // FreeRADIUS & Postgres Latency Benchmark (<10ms / <0.4ms Redis)
    // -------------------------------------------------------------
    fun runRadiusLatencyBenchmark(packetCount: Int = 100) {
        if (_isBenchmarking.value) return
        _isBenchmarking.value = true

        viewModelScope.launch {
            appendLog("Executing FreeRADIUS Fast-Path benchmark ($packetCount packets: Redis 7 + Citus/PG16)...")
            val points = mutableListOf<Double>()

            for (i in 1..packetCount) {
                val base = Random.nextDouble(0.28, 0.58)
                val jitter = if (i % 25 == 0) Random.nextDouble(1.5, 4.2) else 0.0
                val sample = Math.round((base + jitter) * 100.0) / 100.0
                points.add(sample)
                if (i % 20 == 0) delay(35)
            }

            val min = points.minOrNull() ?: 0.28
            val max = points.maxOrNull() ?: 4.8
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
    // AI ARCHITECT ASSISTANT
    // -------------------------------------------------------------
    fun setAiModel(model: AiModelType) {
        _aiModel.value = model
    }

    fun sendAiPrompt(userPrompt: String) {
        if (userPrompt.isBlank() || _isAiThinking.value) return
        val userMessage = AiChatMessage(isUser = true, text = userPrompt)
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

    private fun appendLog(line: String) {
        val current = _consoleLogs.value.toMutableList()
        current.add("[${nowTimestamp()}] $line")
        if (current.size > 200) current.removeAt(0)
        _consoleLogs.value = current
    }

    private fun nowTimestamp(): String {
        return SimpleDateFormat("HH:mm:ss", Locale.US).format(Date())
    }

    // -------------------------------------------------------------
    // SEED DATA (Local Mock State)
    // -------------------------------------------------------------
    private fun seedCitusShards() {
        _citusShards.value = (0 until 128).map { id ->
            val load = 22 + ((id * 17) % 58)
            val status = when {
                id == 47 || id == 103 -> ShardHealthStatus.DEGRADED
                load > 72 -> ShardHealthStatus.ELEVATED
                else -> ShardHealthStatus.HEALTHY
            }
            val latency = when (status) {
                ShardHealthStatus.DEGRADED -> 4.6
                ShardHealthStatus.ELEVATED -> 1.8
                ShardHealthStatus.HEALTHY -> 0.34 + ((id % 9) * 0.03)
            }
            CitusShardHealth(
                shardId = id,
                status = status,
                latencyMs = Math.round(latency * 100.0) / 100.0,
                loadPercent = load,
                activeSessions = 58_000 + ((id * 431) % 14_000),
                replicaSynced = status != ShardHealthStatus.DEGRADED
            )
        }
    }

    private fun seedInitialSubscribers() {
        _subscribers.value = listOf(
            Subscriber(
                id = 1,
                username = "tapos_test",
                cleartextPassword = "***",
                ipAddress = "10.100.0.50",
                macAddress = "D4:CA:6D:55:66:77",
                profile = "20M_PPPoE_Carrier",
                rateLimit = "20M/20M",
                monthlyFee = 1200.0,
                expiryDate = "2026-12-15",
                status = SubscriberStatus.ACTIVE,
                lastPaymentDate = "2026-11-14",
                activeSessionId = "sess_tapos_01",
                uptimeSeconds = 345600,
                inputBytes = 45298124000,
                outputBytes = 189234120000,
                division = "Dhaka",
                ontSerial = "HW-GPON-4821",
                opticalRxDbm = -17.8
            ),
            Subscriber(
                id = 2,
                username = "mirpur_home_sub01",
                cleartextPassword = "***",
                ipAddress = "10.100.0.15",
                macAddress = "E0:D5:5E:AA:BB:CC",
                profile = "20M_Residential",
                rateLimit = "512k/512k (WALLED_GARDEN)",
                monthlyFee = 1000.0,
                expiryDate = "2026-11-10",
                status = SubscriberStatus.EXPIRED,
                lastPaymentDate = "2026-10-10",
                activeSessionId = "sess_91823b",
                uptimeSeconds = 86400,
                inputBytes = 12000000000,
                outputBytes = 54000000000,
                division = "Dhaka",
                ontSerial = "ZTE-F660-9012",
                opticalRxDbm = -21.3
            ),
            Subscriber(
                id = 3,
                username = "uttara_biz_gpon",
                cleartextPassword = "***",
                ipAddress = "10.100.0.22",
                macAddress = "70:4F:57:33:44:55",
                profile = "50M_Dedicated_BDIX",
                rateLimit = "50M/50M 75M/75M 25M/25M 10/10 8 25M/25M",
                monthlyFee = 2500.0,
                expiryDate = "2026-12-25",
                status = SubscriberStatus.ACTIVE,
                lastPaymentDate = "2026-11-05",
                activeSessionId = "sess_44109c",
                uptimeSeconds = 172800,
                inputBytes = 28000000000,
                outputBytes = 92000000000,
                division = "Dhaka",
                ontSerial = "VSOL-V2802-331",
                opticalRxDbm = -16.2
            ),
            Subscriber(
                id = 4,
                username = "ctg_port_corp04",
                cleartextPassword = "***",
                ipAddress = "10.100.1.45",
                macAddress = "BC:AE:C5:77:88:99",
                profile = "10M_Starter",
                rateLimit = "10M/10M 15M/15M 5M/5M 10/10 8 5M/5M",
                monthlyFee = 600.0,
                expiryDate = "2026-11-12",
                status = SubscriberStatus.EXPIRED,
                lastPaymentDate = "2026-10-12",
                activeSessionId = "sess_78100e",
                uptimeSeconds = 12400,
                inputBytes = 3200000000,
                outputBytes = 14000000000,
                division = "Chittagong",
                ontSerial = "HW-HG8245H-711",
                opticalRxDbm = -27.9
            ),
            Subscriber(
                id = 5,
                username = "sylhet_tea_valley",
                cleartextPassword = "***",
                ipAddress = "10.100.2.18",
                macAddress = "A4:2B:8C:12:44:9A",
                profile = "30M_Fiber_Plus",
                rateLimit = "30M/30M",
                monthlyFee = 1500.0,
                expiryDate = "2026-12-20",
                status = SubscriberStatus.ACTIVE,
                lastPaymentDate = "2026-11-01",
                activeSessionId = "sess_55201f",
                uptimeSeconds = 259200,
                inputBytes = 18500000000,
                outputBytes = 74200000000,
                division = "Sylhet",
                ontSerial = "ZTE-F670L-219",
                opticalRxDbm = -19.1
            )
        )
    }

    private fun seedInitialRadAcct() {
        _radAcctRecords.value = listOf(
            RadAcctRecord(
                radacctId = 1001,
                acctSessionId = "sess_09281a",
                acctUniqueId = "u_81a76c001",
                username = "tapos_test",
                nasIpAddress = "10.10.10.1",
                acctStartTime = "2026-11-10 08:00:00+00",
                acctSessionTime = 345600,
                acctInputOctets = 45298124000,
                acctOutputOctets = 189234120000,
                framedIpAddress = "10.100.0.50",
                partitionTable = "radacct_y2026m11"
            ),
            RadAcctRecord(
                radacctId = 1002,
                acctSessionId = "sess_91823b",
                acctUniqueId = "u_91b44d002",
                username = "mirpur_home_sub01",
                nasIpAddress = "10.10.10.1",
                acctStartTime = "2026-11-12 14:30:00+00",
                acctSessionTime = 86400,
                acctInputOctets = 12000000000,
                acctOutputOctets = 54000000000,
                framedIpAddress = "10.100.0.15",
                partitionTable = "radacct_y2026m11"
            ),
            RadAcctRecord(
                radacctId = 1003,
                acctSessionId = "sess_44109c",
                acctUniqueId = "u_44c99e003",
                username = "uttara_biz_gpon",
                nasIpAddress = "10.10.10.1",
                acctStartTime = "2026-11-11 09:15:00+00",
                acctSessionTime = 172800,
                acctInputOctets = 28000000000,
                acctOutputOctets = 92000000000,
                framedIpAddress = "10.100.0.22",
                partitionTable = "radacct_y2026m11"
            )
        )
    }

    private fun seedInitialMfsWebhooks() {
        _mfsWebhookEvents.value = listOf(
            MfsWebhookEvent(
                id = "wh_seed_1",
                gateway = "bKash",
                trxId = "TRX90817263",
                username = "tapos_test",
                customerPhone = "+8801711XXXXXX",
                amountBdt = 1200.0,
                httpStatus = 200,
                signatureStatus = WebhookSignatureStatus.VERIFIED_MOCK,
                pipelineCompletedSteps = 5,
                lineReconnected = true,
                executionTimeMs = 840,
                timestampLabel = "10:12:04",
                payloadPreview = """{"gateway":"bKash","username":"tapos_test","amount":1200,"trx_id":"TRX90817263"}""",
                logOutput = "Invoice #INV-90817263 marked PAID -> RFC 5176 CoA-ACK in 210ms -> Line active."
            ),
            MfsWebhookEvent(
                id = "wh_seed_2",
                gateway = "Nagad",
                trxId = "TRX90817299",
                username = "uttara_biz_gpon",
                customerPhone = "+8801819XXXXXX",
                amountBdt = 2500.0,
                httpStatus = 200,
                signatureStatus = WebhookSignatureStatus.VERIFIED_MOCK,
                pipelineCompletedSteps = 5,
                lineReconnected = true,
                executionTimeMs = 910,
                timestampLabel = "09:48:21",
                payloadPreview = """{"gateway":"Nagad","username":"uttara_biz_gpon","amount":2500,"trx_id":"TRX90817299"}""",
                logOutput = "Invoice #INV-90817299 marked PAID -> RFC 5176 CoA-ACK in 245ms -> 50M restored."
            )
        )
    }

    private fun seedInitialAiGreeting() {
        _chatMessages.value = listOf(
            AiChatMessage(
                isUser = false,
                text = """
                    **Welcome to the Carrier Edition Telecom Architect Console.**

                    Configured for 5M–10M Subscriber Scale across **128 Citus Shards**, **Redis 7 Fast-Path (<0.4ms)**, **ClickHouse Accounting**, **bKash/Nagad MFS Webhooks**, and **RFC 5176 CoA (UDP 3799)**.
                """.trimIndent(),
                modelType = AiModelType.FLASH_LITE
            )
        )
    }
}
