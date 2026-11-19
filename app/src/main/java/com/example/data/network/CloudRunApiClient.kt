package com.example.data.network

import android.content.Context
import com.example.BuildConfig
import com.example.data.model.CoaActionType
import com.example.data.model.CoaKickSimulation
import com.example.data.model.MfsWebhookEvent
import com.example.data.model.NasVendor
import com.example.data.model.NocTelemetry
import com.example.data.model.Subscriber
import com.example.data.model.SubscriberStatus
import com.example.data.model.WebhookSignatureStatus
import com.example.data.model.computeCitusShardId
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.OkHttpClient
import okhttp3.Request
import okhttp3.RequestBody.Companion.toRequestBody
import org.json.JSONArray
import org.json.JSONObject
import java.util.concurrent.TimeUnit

data class CloudRunProbeResult(
    val isReachable: Boolean,
    val httpStatus: Int?,
    val latencyMs: Long,
    val message: String
)

class CloudRunApiClient {

    private val client = OkHttpClient.Builder()
        .connectTimeout(12, TimeUnit.SECONDS)
        .readTimeout(15, TimeUnit.SECONDS)
        .writeTimeout(15, TimeUnit.SECONDS)
        .build()

    fun loadSavedBaseUrl(context: Context): String {
        val prefs = context.getSharedPreferences(PREFS_NAME, Context.MODE_PRIVATE)
        val saved = prefs.getString(KEY_BASE_URL, null)
        return if (!saved.isNullOrBlank()) saved else BuildConfig.CLOUD_RUN_BASE_URL
    }

    fun loadUseCloudBackend(context: Context): Boolean {
        val prefs = context.getSharedPreferences(PREFS_NAME, Context.MODE_PRIVATE)
        return prefs.getBoolean(KEY_USE_CLOUD, BuildConfig.CLOUD_RUN_BASE_URL.isNotBlank())
    }

    fun saveConfig(context: Context, baseUrl: String, useCloudBackend: Boolean) {
        val normalized = normalizeUrl(baseUrl)
        context.getSharedPreferences(PREFS_NAME, Context.MODE_PRIVATE)
            .edit()
            .putString(KEY_BASE_URL, normalized)
            .putBoolean(KEY_USE_CLOUD, useCloudBackend)
            .apply()
    }

    fun normalizeUrl(raw: String): String {
        return raw.trim().trimEnd('/')
    }

    fun isValidHttpsUrl(url: String): Boolean {
        val normalized = normalizeUrl(url)
        return normalized.startsWith("https://", ignoreCase = true) && normalized.length > 10
    }

    suspend fun probeHealth(baseUrl: String): CloudRunProbeResult = withContext(Dispatchers.IO) {
        val normalized = normalizeUrl(baseUrl)
        if (!isValidHttpsUrl(normalized)) {
            return@withContext CloudRunProbeResult(
                isReachable = false,
                httpStatus = null,
                latencyMs = 0L,
                message = "Cloud Run URL must start with https://"
            )
        }
        val start = System.currentTimeMillis()
        try {
            val request = Request.Builder()
                .url("$normalized/health")
                .get()
                .build()
            client.newCall(request).execute().use { response ->
                val elapsed = System.currentTimeMillis() - start
                if (response.isSuccessful) {
                    CloudRunProbeResult(
                        isReachable = true,
                        httpStatus = response.code,
                        latencyMs = elapsed,
                        message = "Connected (HTTP ${response.code} in ${elapsed}ms)"
                    )
                } else {
                    CloudRunProbeResult(
                        isReachable = false,
                        httpStatus = response.code,
                        latencyMs = elapsed,
                        message = "Backend returned HTTP ${response.code}"
                    )
                }
            }
        } catch (e: Exception) {
            val elapsed = System.currentTimeMillis() - start
            CloudRunProbeResult(
                isReachable = false,
                httpStatus = null,
                latencyMs = elapsed,
                message = e.message ?: "Connection failed"
            )
        }
    }

    suspend fun fetchNocTelemetry(baseUrl: String): Result<NocTelemetry> = withContext(Dispatchers.IO) {
        val normalized = normalizeUrl(baseUrl)
        if (!isValidHttpsUrl(normalized)) {
            return@withContext Result.failure(IllegalArgumentException("Invalid HTTPS Cloud Run URL"))
        }
        try {
            val request = Request.Builder()
                .url("$normalized/api/noc/telemetry")
                .get()
                .build()
            client.newCall(request).execute().use { response ->
                val body = response.body?.string().orEmpty()
                if (!response.isSuccessful) {
                    return@withContext Result.failure(IllegalStateException("HTTP ${response.code}: $body"))
                }
                val json = JSONObject(body)
                val historyArr = json.optJSONArray("throughputHistoryGbps")
                val history = if (historyArr != null && historyArr.length() > 0) {
                    (0 until historyArr.length()).map { historyArr.optDouble(it, 450.0).toFloat() }
                } else {
                    listOf(412f, 428f, 435f, 441f, 438f, 449f, 456f, 445f, 452f, 450.4f)
                }
                Result.success(
                    NocTelemetry(
                        activeSessions = json.optLong("activeSessions", 8_241_092L),
                        clusterThroughputGbps = json.optDouble("clusterThroughputGbps", 450.4),
                        aaaLatencyMs = json.optDouble("aaaLatencyMs", 0.38),
                        losAlarmCount = json.optInt("losAlarmCount", 42),
                        redisHitRatePercent = json.optDouble("redisHitRatePercent", 99.4),
                        stormActive = json.optBoolean("stormActive", false),
                        throughputHistoryGbps = history,
                        sourceLabel = "Cloud Run ($normalized)"
                    )
                )
            }
        } catch (e: Exception) {
            Result.failure(e)
        }
    }

    suspend fun fetchSubscribers(baseUrl: String): Result<List<Subscriber>> = withContext(Dispatchers.IO) {
        val normalized = normalizeUrl(baseUrl)
        if (!isValidHttpsUrl(normalized)) {
            return@withContext Result.failure(IllegalArgumentException("Invalid HTTPS Cloud Run URL"))
        }
        try {
            val request = Request.Builder()
                .url("$normalized/api/subscribers")
                .get()
                .build()
            client.newCall(request).execute().use { response ->
                val body = response.body?.string().orEmpty()
                if (!response.isSuccessful) {
                    return@withContext Result.failure(IllegalStateException("HTTP ${response.code}"))
                }
                val array = if (body.trimStart().startsWith("[")) {
                    JSONArray(body)
                } else {
                    JSONObject(body).optJSONArray("data") ?: JSONArray()
                }
                val list = mutableListOf<Subscriber>()
                for (i in 0 until array.length()) {
                    val item = array.getJSONObject(i)
                    val username = item.optString("username", "sub_$i")
                    val statusRaw = item.optString("status", "ACTIVE").uppercase()
                    val status = runCatching { SubscriberStatus.valueOf(statusRaw) }
                        .getOrDefault(SubscriberStatus.ACTIVE)
                    list.add(
                        Subscriber(
                            id = item.optLong("id", (i + 1).toLong()),
                            username = username,
                            cleartextPassword = "***", // Never expose credentials from remote API
                            ipAddress = item.optString("ipAddress", "10.100.0.${10 + i}"),
                            macAddress = item.optString("macAddress", "D4:CA:6D:55:66:77"),
                            profile = item.optString("profile", "20M_Residential"),
                            rateLimit = item.optString("rateLimit", "20M/20M"),
                            monthlyFee = item.optDouble("monthlyFee", 1000.0),
                            expiryDate = item.optString("expiryDate", "2026-11-30"),
                            status = status,
                            lastPaymentDate = item.optString("lastPaymentDate", "2026-10-30"),
                            activeSessionId = item.optString("activeSessionId").takeIf { it.isNotBlank() },
                            uptimeSeconds = item.optLong("uptimeSeconds", 0L),
                            inputBytes = item.optLong("inputBytes", 0L),
                            outputBytes = item.optLong("outputBytes", 0L),
                            division = item.optString("division", "Dhaka"),
                            shardId = item.optInt("shardId", computeCitusShardId(username)),
                            ontSerial = item.optString("ontSerial", "HW-GPON-${1000 + i}"),
                            opticalRxDbm = item.optDouble("opticalRxDbm", -18.4)
                        )
                    )
                }
                Result.success(list)
            }
        } catch (e: Exception) {
            Result.failure(e)
        }
    }

    suspend fun dispatchMfsWebhook(
        baseUrl: String,
        gateway: String,
        username: String,
        amountBdt: Double,
        trxId: String
    ): Result<MfsWebhookEvent> = withContext(Dispatchers.IO) {
        val normalized = normalizeUrl(baseUrl)
        if (!isValidHttpsUrl(normalized)) {
            return@withContext Result.failure(IllegalArgumentException("Invalid HTTPS Cloud Run URL"))
        }
        val start = System.currentTimeMillis()
        try {
            val payload = JSONObject().apply {
                put("gateway", gateway)
                put("username", username)
                put("amount", amountBdt)
                put("trx_id", trxId)
            }
            val request = Request.Builder()
                .url("$normalized/api/webhooks/mfs")
                .post(payload.toString().toRequestBody(JSON_MEDIA_TYPE))
                .build()
            client.newCall(request).execute().use { response ->
                val elapsed = System.currentTimeMillis() - start
                val body = response.body?.string().orEmpty()
                if (!response.isSuccessful && response.code != 403 && response.code != 409) {
                    return@withContext Result.failure(IllegalStateException("HTTP ${response.code}: $body"))
                }
                val sigStatus = when (response.code) {
                    403 -> WebhookSignatureStatus.INVALID_REJECTED
                    409 -> WebhookSignatureStatus.DUPLICATE_LOCKED
                    else -> WebhookSignatureStatus.VERIFIED_SERVER
                }
                Result.success(
                    MfsWebhookEvent(
                        id = "wh_${System.currentTimeMillis()}",
                        gateway = gateway,
                        trxId = trxId,
                        username = username,
                        customerPhone = "+8801711XXXXXX",
                        amountBdt = amountBdt,
                        httpStatus = response.code,
                        signatureStatus = sigStatus,
                        pipelineCompletedSteps = if (response.isSuccessful) 5 else 1,
                        lineReconnected = response.isSuccessful,
                        executionTimeMs = elapsed,
                        timestampLabel = java.text.SimpleDateFormat("HH:mm:ss", java.util.Locale.US)
                            .format(java.util.Date()),
                        payloadPreview = payload.toString(),
                        logOutput = "Cloud Run HTTP ${response.code} (${elapsed}ms): $body"
                    )
                )
            }
        } catch (e: Exception) {
            Result.failure(e)
        }
    }

    suspend fun dispatchRadiusCoa(
        baseUrl: String,
        username: String,
        framedIp: String,
        actionType: CoaActionType,
        vendor: NasVendor,
        rateLimit: String
    ): Result<CoaKickSimulation> = withContext(Dispatchers.IO) {
        val normalized = normalizeUrl(baseUrl)
        if (!isValidHttpsUrl(normalized)) {
            return@withContext Result.failure(IllegalArgumentException("Invalid HTTPS Cloud Run URL"))
        }
        val start = System.currentTimeMillis()
        try {
            val payload = JSONObject().apply {
                put("username", username)
                put("framedIp", framedIp)
                put("action", actionType.name)
                put("rfcCode", actionType.rfcCode)
                put("vendor", vendor.name)
                put("rateLimit", rateLimit)
            }
            val request = Request.Builder()
                .url("$normalized/api/radius/coa")
                .post(payload.toString().toRequestBody(JSON_MEDIA_TYPE))
                .build()
            client.newCall(request).execute().use { response ->
                val elapsed = System.currentTimeMillis() - start
                val body = response.body?.string().orEmpty()
                if (!response.isSuccessful) {
                    return@withContext Result.failure(IllegalStateException("HTTP ${response.code}: $body"))
                }
                val json = runCatching { JSONObject(body) }.getOrNull()
                Result.success(
                    CoaKickSimulation(
                        targetUsername = username,
                        targetIp = framedIp,
                        nasIp = json?.optString("nasIp", "Cloud Run BRAS Proxy") ?: "Cloud Run BRAS Proxy",
                        nasPort = 3799,
                        secretKey = "server-side-secret",
                        packetType = "${actionType.label} (RFC 5176)",
                        packetId = json?.optInt("packetId", 1) ?: 1,
                        authenticatorHex = json?.optString("authenticator", "server-computed") ?: "server-computed",
                        resultStatus = json?.optString("status", "CoA-ACK") ?: "CoA-ACK",
                        latencyMs = elapsed,
                        logTrace = listOf(
                            "POST $normalized/api/radius/coa -> HTTP ${response.code}",
                            "Vendor: ${vendor.displayName} (${vendor.vsaAttribute})",
                            "Response (${elapsed}ms): $body"
                        )
                    )
                )
            }
        } catch (e: Exception) {
            Result.failure(e)
        }
    }

    companion object {
        private const val PREFS_NAME = "cloud_run_backend_prefs"
        private const val KEY_BASE_URL = "cloud_run_base_url"
        private const val KEY_USE_CLOUD = "use_cloud_backend"
        private val JSON_MEDIA_TYPE = "application/json; charset=utf-8".toMediaType()
    }
}
