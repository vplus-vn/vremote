import org.jetbrains.kotlin.gradle.dsl.JvmTarget

plugins { id("com.android.application"); kotlin("android") }

android { namespace = "vremote.vplus.vn"; compileSdk = 35
    defaultConfig { applicationId = "vremote.vplus.vn"; minSdk = 26; targetSdk = 35; versionCode = 12; versionName = "0.0.12" }
    // BuildConfig.VERSION_NAME/VERSION_CODE are used by the in-app updater; AGP 8 leaves this off by default.
    buildFeatures { buildConfig = true }
    // Java and Kotlin must target the same JVM level or the Kotlin plugin fails the build
    // ("Inconsistent JVM-target compatibility"). Pin both to 17.
    compileOptions { sourceCompatibility = JavaVersion.VERSION_17; targetCompatibility = JavaVersion.VERSION_17 }
    // Stable release signing (so the in-app updater can install over the top — an update only
    // installs if the new APK's signature matches the installed one). CI decodes a keystore secret
    // to ANDROID_KEYSTORE_FILE and builds `assembleRelease`; without the secret it stays unset and
    // CI builds `assembleDebug` instead (ephemeral debug key — updates then require a reinstall).
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

dependencies {
    implementation("androidx.core:core:1.13.1") // FileProvider for the self-update installer
    implementation("org.jetbrains.kotlinx:kotlinx-coroutines-android:1.10.2")
    implementation("com.squareup.okhttp3:okhttp:5.1.0")
    implementation("io.github.webrtc-sdk:android:150.7871.01")
    testImplementation("org.jetbrains.kotlin:kotlin-test:2.2.20")
    testImplementation("org.jetbrains.kotlinx:kotlinx-coroutines-test:1.10.2")
}
