package vn.vremote

import okhttp3.MediaType.Companion.toMediaTypeOrNull
import okhttp3.OkHttpClient
import okhttp3.Request
import okhttp3.RequestBody.Companion.toRequestBody
import org.json.JSONObject
import java.security.MessageDigest
import java.util.concurrent.TimeUnit

/**
 * Thin HTTP client for the device-initiated pairing API (UltraViewer/TeamViewer style).
 * Synchronous — call from a background thread. Passwords are hashed (SHA-256) before sending;
 * plaintext never leaves the device.
 */
object ApiClient {
    private val http = OkHttpClient.Builder().callTimeout(10, TimeUnit.SECONDS).build()
    private val JSON = "application/json; charset=utf-8".toMediaTypeOrNull()

    fun sha256(s: String): String =
        MessageDigest.getInstance("SHA-256").digest(s.toByteArray(Charsets.UTF_8)).joinToString("") { "%02x".format(it) }

    fun apiBase(baseUrl: String): String = baseUrl.trimEnd('/') + "/api/vremote/v1"

    /** https://host -> wss://host/agent (ws:// for plain http). */
    fun signalingUrl(baseUrl: String): String {
        val b = baseUrl.trimEnd('/')
        val ws = if (b.startsWith("https")) "wss" + b.substring(5) else if (b.startsWith("http")) "ws" + b.substring(4) else b
        return "$ws/agent"
    }

    data class Announce(val deviceId: String, val pairingId: String, val deviceToken: String)

    fun announce(baseUrl: String, machineCode: String, sessionPwHash: String, personalPwHash: String?, requireAccept: Boolean): Announce {
        val body = JSONObject()
            .put("machineCode", machineCode).put("agentVersion", "android-" + BuildConfig.VERSION_NAME).put("platform", "android")
            .put("sessionPasswordHash", sessionPwHash).put("requireAccept", requireAccept)
        if (personalPwHash != null) body.put("personalPasswordHash", personalPwHash)
        val req = Request.Builder().url(apiBase(baseUrl) + "/agent/announce")
            .post(body.toString().toRequestBody(JSON)).build()
        http.newCall(req).execute().use { r ->
            val txt = r.body?.string() ?: ""
            if (!r.isSuccessful) throw RuntimeException(parseErr(txt, r.code))
            val j = JSONObject(txt)
            return Announce(j.getString("deviceId"), j.getString("pairingId"), j.getString("deviceToken"))
        }
    }

    data class Pending(val requestId: String, val actor: String)

    fun pending(baseUrl: String, deviceId: String, deviceToken: String): Pending? {
        val req = Request.Builder().url(apiBase(baseUrl) + "/agent/" + deviceId + "/pending")
            .header("X-Device-Token", deviceToken).get().build()
        http.newCall(req).execute().use { r ->
            if (!r.isSuccessful) return null
            val p = JSONObject(r.body?.string() ?: "{}").optJSONObject("pending") ?: return null
            return Pending(p.optString("requestId"), p.optString("actor", "operator"))
        }
    }

    fun respond(baseUrl: String, deviceId: String, deviceToken: String, requestId: String, accept: Boolean) {
        val body = JSONObject().put("requestId", requestId).put("accept", accept)
        val req = Request.Builder().url(apiBase(baseUrl) + "/agent/" + deviceId + "/respond")
            .header("X-Device-Token", deviceToken).post(body.toString().toRequestBody(JSON)).build()
        http.newCall(req).execute().use { }
    }

    data class Latest(val versionCode: Int, val versionName: String, val url: String, val notes: String)

    /** Fetch the published version manifest served statically at <base>/downloads/vremote-agent.json. */
    fun latest(baseUrl: String): Latest {
        val req = Request.Builder().url(baseUrl.trimEnd('/') + "/downloads/vremote-agent.json").get().build()
        http.newCall(req).execute().use { r ->
            val txt = r.body?.string() ?: ""
            if (!r.isSuccessful) throw RuntimeException(parseErr(txt, r.code))
            val j = JSONObject(txt)
            return Latest(j.getInt("versionCode"), j.optString("versionName"),
                j.optString("url", "/downloads/vremote-agent.apk"), j.optString("notes", ""))
        }
    }

    private fun parseErr(txt: String, code: Int): String = try {
        JSONObject(txt).optJSONObject("error")?.optString("message") ?: "HTTP $code"
    } catch (e: Exception) { "HTTP $code" }
}
