import org.jetbrains.kotlin.gradle.dsl.JvmTarget

plugins { id("com.android.application"); kotlin("android") }

android { namespace = "vn.vplus.vremote.agent"; compileSdk = 35
    defaultConfig { applicationId = "vn.vplus.vremote.agent"; minSdk = 26; targetSdk = 35; versionCode = 1; versionName = "0.2.0" }
    // Java and Kotlin must target the same JVM level or the Kotlin plugin fails the build
    // ("Inconsistent JVM-target compatibility"). Pin both to 17.
    compileOptions { sourceCompatibility = JavaVersion.VERSION_17; targetCompatibility = JavaVersion.VERSION_17 }
}

kotlin { compilerOptions { jvmTarget.set(JvmTarget.JVM_17) } }

dependencies {
    implementation("org.jetbrains.kotlinx:kotlinx-coroutines-android:1.10.2")
    implementation("com.squareup.okhttp3:okhttp:5.1.0")
    implementation("io.github.webrtc-sdk:android:150.7871.01")
    testImplementation("org.jetbrains.kotlin:kotlin-test:2.2.20")
    testImplementation("org.jetbrains.kotlinx:kotlinx-coroutines-test:1.10.2")
}
