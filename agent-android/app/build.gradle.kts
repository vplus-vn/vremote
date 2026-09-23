import org.jetbrains.kotlin.gradle.dsl.JvmTarget

plugins { id("com.android.application"); kotlin("android") }

android { namespace = "vremote.vplus.vn"; compileSdk = 35
    defaultConfig { applicationId = "vremote.vplus.vn"; minSdk = 26; targetSdk = 35; versionCode = 27; versionName = "0.0.27" }
    buildFeatures { buildConfig = true }
    compileOptions { sourceCompatibility = JavaVersion.VERSION_17; targetCompatibility = JavaVersion.VERSION_17 }
    signingConfigs {
        create("release") {
            val ks = System.getenv("ANDROID_KEYSTORE_FILE")
            if (ks != null && file(ks).exists()) {
                storeFile = file(ks); storePassword = System.getenv("ANDROID_KEYSTORE_PASSWORD")
                keyAlias = System.getenv("ANDROID_KEY_ALIAS"); keyPassword = System.getenv("ANDROID_KEY_PASSWORD")
            }
        }
    }
    buildTypes {
        getByName("release") {
            isMinifyEnabled = false
            if (System.getenv("ANDROID_KEYSTORE_FILE") != null) signingConfig = signingConfigs.getByName("release")
        }
    }
}

kotlin { compilerOptions { jvmTarget.set(JvmTarget.JVM_17) } }

// Issue #148 hardening is applied at build time so the CI-produced APK contains the fix.
// The task is idempotent and fails fast if the expected service source is missing.
val hardenIssue148 by tasks.registering {
    doLast {
        val p = file("src/main/java/vremote/vplus/vn/RemoteAgentService.kt")
        var s = p.readText()

        s = s.replace("private lateinit var egl: EglBase", "private var egl: EglBase? = null")
        s = s.replace("        factory.dispose()\n        egl.release()", "        if (::factory.isInitialized) factory.dispose()\n        egl?.release()")

        val oldField = "    private var activeIceServers: List<PeerConnection.IceServer> = emptyList()\n"
        val newField = oldField + "    private val pendingIceCandidates = ArrayList<IceCandidate>()\n    private val pendingRemoteCandidates = ArrayList<IceCandidate>()\n    @Volatile private var localDescriptionReady = false\n    @Volatile private var remoteDescriptionReady = false\n"
        if (oldField in s && "pendingIceCandidates" !in s) s = s.replace(oldField, newField)

        val oldIce = """        override fun onIceCandidate(c: IceCandidate) {
            sendSignal("""
        val newIce = """        override fun onIceCandidate(c: IceCandidate) {
            synchronized(pendingIceCandidates) {
                if (!localDescriptionReady) {
                    pendingIceCandidates.add(c)
                    crumb("ice:queued-local")
                    return
                }
            }
            sendSignal("""
        if (oldIce in s && "ice:queued-local" !in s) s = s.replace(oldIce, newIce)

        val oldHandleCandidate = """    private fun handleCandidate(m: JSONObject) {
        val c = m.optJSONObject("candidate") ?: return
        peer?.addIceCandidate(IceCandidate(c.optString("sdpMid"), c.optInt("sdpMLineIndex"), c.optString("candidate")))
    }
"""
        val newHandleCandidate = """    private fun handleCandidate(m: JSONObject) {
        val c = m.optJSONObject("candidate") ?: return
        val candidate = IceCandidate(c.optString("sdpMid"), c.optInt("sdpMLineIndex"), c.optString("candidate"))
        synchronized(pendingRemoteCandidates) {
            val pc = peer
            if (pc == null || !remoteDescriptionReady) {
                pendingRemoteCandidates.add(candidate)
                crumb("ice:queued-remote")
                return
            }
            try { pc.addIceCandidate(candidate) } catch (_: Throwable) { crumb("ice:add-remote-fail") }
        }
    }
"""
        if (oldHandleCandidate in s && "ice:queued-remote" !in s) s = s.replace(oldHandleCandidate, newHandleCandidate)

        val oldRemote = "        crumb(\"offer:setRemote\")"
        val newRemote = "        localDescriptionReady = false\n        remoteDescriptionReady = false\n        synchronized(pendingIceCandidates) { pendingIceCandidates.clear() }\n        crumb(\"offer:setRemote\")"
        if (oldRemote in s && "remoteDescriptionReady = false" !in s) s = s.replace(oldRemote, newRemote)

        val oldRemoteSuccess = "override fun onSetSuccess() {\n                crumb(\"offer:remote ok\")\n                pc.createAnswer"
        val newRemoteSuccess = """override fun onSetSuccess() {
                remoteDescriptionReady = true
                synchronized(pendingRemoteCandidates) {
                    val queued = pendingRemoteCandidates.toList()
                    pendingRemoteCandidates.clear()
                    queued.forEach { candidate ->
                        try { pc.addIceCandidate(candidate) } catch (_: Throwable) { crumb("ice:add-queued-fail") }
                    }
                }
                crumb("offer:remote ok")
                pc.createAnswer"""
        if (oldRemoteSuccess in s) s = s.replace(oldRemoteSuccess, newRemoteSuccess)

        val oldSuccess = "override fun onSetSuccess() { crumb(\"offer:local ok ${'$'}{cfg()}\") }"
        val newSuccess = """override fun onSetSuccess() {
                                    localDescriptionReady = true
                                    synchronized(pendingIceCandidates) {
                                        val queued = pendingIceCandidates.toList()
                                        pendingIceCandidates.clear()
                                        queued.forEach { candidate ->
                                            sendSignal(
                                                JSONObject().put("type", "agent.signaling").put("sessionId", activeSession).put(
                                                    "payload", JSONObject().put("type", "candidate").put("sessionId", activeSession).put(
                                                        "candidate", JSONObject().put("sdpMid", candidate.sdpMid).put("sdpMLineIndex", candidate.sdpMLineIndex).put("candidate", candidate.sdp)
                                                    )
                                                )
                                            )
                                        }
                                    }
                                    sendSignal(
                                        JSONObject().put("type", "agent.signaling").put("sessionId", activeSession).put(
                                            "payload", JSONObject().put("type", "answer").put("sessionId", activeSession).put("sdp", fixed.description)
                                        )
                                    )
                                    crumb("offer:local ok ${'$'}{cfg()}")
                                }"""
        if (oldSuccess in s) s = s.replace(oldSuccess, newSuccess)

        val oldAnswerSend = """                            sendSignal(
                                JSONObject().put("type", "agent.signaling").put("sessionId", activeSession).put(
                                    "payload", JSONObject().put("type", "answer").put("sessionId", activeSession).put("sdp", fixed.description)
                                )
                            )
"""
        if (oldAnswerSend in s) s = s.replace(oldAnswerSend, "")

        // Critical MJPEG SDP fix: reject the offered video m-line instead of deleting it.
        // Deleting it leaves the BUNDLE group / m-line ordering inconsistent and can trigger
        // libwebrtc native aborts on the network thread immediately after local SDP succeeds.
        val oldVideoStrip = """            for (line in lines) {
                if (line.startsWith("m=")) inVideo = line.startsWith("m=video")
                if (inVideo) continue
                out.add(line)
            }
            out.joinToString("\\r\\n")
"""
        val newVideoReject = """            for (line in lines) {
                if (line.startsWith("m=")) {
                    inVideo = line.startsWith("m=video")
                    if (inVideo) {
                        val parts = line.split(" ").toMutableList()
                        if (parts.size > 1) parts[1] = "0"
                        out.add(parts.joinToString(" "))
                        continue
                    }
                }
                out.add(line)
            }
            out.joinToString("\\r\\n")
"""
        if (oldVideoStrip in s && "parts[1] = \"0\"" !in s) s = s.replace(oldVideoStrip, newVideoReject)

        val oldEnd = "        activeSession = null\n        activeCapability = null\n"
        val newEnd = "        activeSession = null\n        activeCapability = null\n        remoteDescriptionReady = false\n        localDescriptionReady = false\n        synchronized(pendingRemoteCandidates) { pendingRemoteCandidates.clear() }\n        synchronized(pendingIceCandidates) { pendingIceCandidates.clear() }\n"
        if (oldEnd in s && "pendingRemoteCandidates.clear()" !in s) s = s.replace(oldEnd, newEnd)

        p.writeText(s)
    }
}

tasks.named("preBuild").configure { dependsOn(hardenIssue148) }

dependencies {
    implementation("androidx.core:core:1.13.1")
    implementation("org.jetbrains.kotlinx:kotlinx-coroutines-android:1.10.2")
    implementation("com.squareup.okhttp3:okhttp:5.1.0")
    implementation("io.github.webrtc-sdk:android:150.7871.01")
    testImplementation("org.jetbrains.kotlin:kotlin-test:2.2.20")
    testImplementation("org.jetbrains.kotlinx:kotlinx-coroutines-test:1.10.2")
}