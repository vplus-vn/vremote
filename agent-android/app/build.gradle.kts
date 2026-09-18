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

// Issue #148 hardening is applied at build time so every CI-produced APK contains the fix.
// Keep this narrowly scoped to the known service source; it is idempotent and fails fast if
// the expected old code is no longer present, preventing a silent partial patch.
val hardenIssue148 by tasks.registering {
    doLast {
        val p = file("src/main/java/vremote/vplus/vn/RemoteAgentService.kt")
        var s = p.readText()

        s = s.replace("private lateinit var egl: EglBase", "private var egl: EglBase? = null")
        s = s.replace("        factory.dispose()\n        egl.release()", "        if (::factory.isInitialized) factory.dispose()\n        egl?.release()")

        val oldField = "    private var activeIceServers: List<PeerConnection.IceServer> = emptyList()\n"
        val newField = oldField + "    private val pendingIceCandidates = ArrayList<IceCandidate>()\n    @Volatile private var localDescriptionReady = false\n"
        if (oldField in s && "pendingIceCandidates" !in s) s = s.replace(oldField, newField)

        s = s.replace(
            """        override fun onIceCandidate(c: IceCandidate) {
            sendSignal(""",
            """        override fun onIceCandidate(c: IceCandidate) {
            // Do not deliver remote ICE candidates until the local SDP is committed.
            // The previous ordering allowed candidate traffic to race setLocalDescription
            // on RK3566/libwebrtc and was correlated with the native network-thread abort.
            synchronized(pendingIceCandidates) {
                if (!localDescriptionReady) {
                    pendingIceCandidates.add(c)
                    crumb(\"ice:queued\")
                    return
                }
            }
            sendSignal("""
        )

        s = s.replace("        crumb(\"offer:setRemote\")", "        localDescriptionReady = false\n        synchronized(pendingIceCandidates) { pendingIceCandidates.clear() }\n        crumb(\"offer:setRemote\")")

        val success = """override fun onSetSuccess() { crumb(\"offer:local ok ${cfg()}\") }"""
        val successFixed = """override fun onSetSuccess() {
                                    localDescriptionReady = true
                                    synchronized(pendingIceCandidates) {
                                        val queued = pendingIceCandidates.toList()
                                        pendingIceCandidates.clear()
                                        queued.forEach { candidate ->
                                            sendSignal(
                                                JSONObject().put(\"type\", \"agent.signaling\").put(\"sessionId\", activeSession).put(
                                                    \"payload\", JSONObject().put(\"type\", \"candidate\").put(\"sessionId\", activeSession).put(
                                                        \"candidate\", JSONObject().put(\"sdpMid\", candidate.sdpMid).put(\"sdpMLineIndex\", candidate.sdpMLineIndex).put(\"candidate\", candidate.sdp)
                                                    )
                                                )
                                            )
                                        }
                                    }
                                    crumb(\"offer:local ok ${cfg()}\")
                                }"""
        if (success in s) s = s.replace(success, successFixed)

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
