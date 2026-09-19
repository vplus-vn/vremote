import org.jetbrains.kotlin.gradle.dsl.JvmTarget

plugins { id("com.android.application"); kotlin("android") }

android { namespace = "vremote.vplus.vn"; compileSdk = 35
    defaultConfig { applicationId = "vremote.vplus.vn"; minSdk = 26; targetSdk = 35; versionCode = 26; versionName = "0.0.26" }
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
        val newField = oldField + "    private val pendingIceCandidates = ArrayList<IceCandidate>()\n    @Volatile private var localDescriptionReady = false\n"
        if (oldField in s && "pendingIceCandidates" !in s) s = s.replace(oldField, newField)

        val oldIce = """        override fun onIceCandidate(c: IceCandidate) {
            sendSignal("""
        val newIce = """        override fun onIceCandidate(c: IceCandidate) {
            synchronized(pendingIceCandidates) {
                if (!localDescriptionReady) {
                    pendingIceCandidates.add(c)
                    crumb("ice:queued")
                    return
                }
            }
            sendSignal("""
        if (oldIce in s && "ice:queued" !in s) s = s.replace(oldIce, newIce)

        val oldRemote = "        crumb(\"offer:setRemote\")"
        val newRemote = "        localDescriptionReady = false\n        synchronized(pendingIceCandidates) { pendingIceCandidates.clear() }\n        crumb(\"offer:setRemote\")"
        if (oldRemote in s && "pendingIceCandidates.clear()" !in s) s = s.replace(oldRemote, newRemote)

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
                                    crumb("offer:local ok ${'$'}{cfg()}")
                                }"""
        if (oldSuccess in s) s = s.replace(oldSuccess, newSuccess)

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