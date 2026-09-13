import org.jetbrains.kotlin.gradle.dsl.JvmTarget

plugins { id("com.android.application"); kotlin("android") }

android { namespace = "vremote.vplus.vn"; compileSdk = 35
    defaultConfig { applicationId = "vremote.vplus.vn"; minSdk = 26; targetSdk = 35; versionCode = 1; versionName = "0.0.1" }
    // BuildConfig.VERSION_NAME/VERSION_CODE are used by the in-app updater; AGP 8 leaves this off by default.
    buildFeatures { buildConfig = true }
    // Java and Kotlin must target the same JVM level or the Kotlin plugin fails the build
    // ("Inconsistent JVM-target compatibility"). Pin both to 17.
    compileOptions { sourceCompatibility = JavaVersion.VERSION_17; targetCompatibility = JavaVersion.VERSION_17 }
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
