plugins { id("com.android.application"); kotlin("android") }

android { namespace = "vn.vplus.vremote.agent"; compileSdk = 35
    defaultConfig { applicationId = "vn.vplus.vremote.agent"; minSdk = 26; targetSdk = 35; versionCode = 1; versionName = "0.1.0" }
}

dependencies {
    implementation("org.jetbrains.kotlinx:kotlinx-coroutines-android:1.10.2")
    testImplementation("org.jetbrains.kotlin:kotlin-test:2.2.20")
    testImplementation("org.jetbrains.kotlinx:kotlinx-coroutines-test:1.10.2")
}
