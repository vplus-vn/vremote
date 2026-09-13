package vn.vremote

import android.content.Context
import android.content.Intent
import android.net.Uri
import android.provider.Settings
import androidx.core.content.FileProvider
import okhttp3.OkHttpClient
import okhttp3.Request
import java.io.File
import java.util.concurrent.TimeUnit

/**
 * In-app self-update: download the published APK and hand it to the system package installer.
 * Synchronous — call from a background thread.
 *
 * IMPORTANT (signing): Android REFUSES to install an update whose signing certificate differs from
 * the installed app's. CI currently produces a DEBUG-signed APK with an ephemeral key per run, so
 * the installer will reject the update with a signature mismatch until the agent is shipped from a
 * stable release keystore (assembleRelease). The mechanism here works regardless — the button
 * downloads and launches the installer — but the install only succeeds once the published APK is
 * signed with a persistent key. See agent-android/README.md.
 */
object AgentUpdater {
    private val http = OkHttpClient.Builder().callTimeout(120, TimeUnit.SECONDS).build()

    /** Resolve the APK URL from the manifest against the base URL (absolute URLs are used as-is). */
    private fun apkUrl(baseUrl: String, path: String): String =
        if (path.startsWith("http")) path else baseUrl.trimEnd('/') + "/" + path.trimStart('/')

    /** Android 8+ gate: may this app request package installs? */
    fun canInstall(ctx: Context): Boolean = ctx.packageManager.canRequestPackageInstalls()

    /** Settings screen where the user grants "install unknown apps" for this app. */
    fun unknownSourcesIntent(ctx: Context): Intent =
        Intent(Settings.ACTION_MANAGE_UNKNOWN_APP_SOURCES, Uri.parse("package:" + ctx.packageName))
            .addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)

    /**
     * Download the APK to the app-specific external files dir (updates/), reporting percent
     * (0..100, or -1 when the total size is unknown). Returns the downloaded file.
     */
    fun download(ctx: Context, baseUrl: String, info: ApiClient.Latest, onProgress: (Int) -> Unit = {}): File {
        val dir = File(ctx.getExternalFilesDir(null), "updates").apply { mkdirs() }
        val out = File(dir, "vremote-agent.apk")
        val req = Request.Builder().url(apkUrl(baseUrl, info.url)).get().build()
        http.newCall(req).execute().use { r ->
            if (!r.isSuccessful) throw RuntimeException("HTTP " + r.code)
            val body = r.body ?: throw RuntimeException("empty_body")
            val total = body.contentLength()
            body.byteStream().use { input ->
                out.outputStream().use { output ->
                    val buf = ByteArray(64 * 1024); var read: Int; var done = 0L
                    while (input.read(buf).also { read = it } >= 0) {
                        output.write(buf, 0, read); done += read
                        onProgress(if (total > 0) ((done * 100) / total).toInt() else -1)
                    }
                }
            }
        }
        return out
    }

    /** Launch the system package installer for a downloaded APK via a FileProvider content:// URI. */
    fun install(ctx: Context, apk: File) {
        val uri = FileProvider.getUriForFile(ctx, ctx.packageName + ".fileprovider", apk)
        ctx.startActivity(Intent(Intent.ACTION_VIEW)
            .setDataAndType(uri, "application/vnd.android.package-archive")
            .addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION or Intent.FLAG_ACTIVITY_NEW_TASK))
    }
}
