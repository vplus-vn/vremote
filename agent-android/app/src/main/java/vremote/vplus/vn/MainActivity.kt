package vremote.vplus.vn

import android.app.Activity
import android.app.ActivityManager
import android.app.AlertDialog
import android.app.ApplicationExitInfo
import android.content.ClipData
import android.content.ClipboardManager
import android.content.Intent
import android.media.projection.MediaProjectionManager
import android.net.Uri
import android.os.Build
import android.os.Bundle
import android.provider.Settings
import android.text.InputType
import android.view.Gravity
import android.widget.*
import java.security.SecureRandom

/**
 * Device-initiated pairing agent (UltraViewer/TeamViewer style): the phone announces itself,
 * shows a stable ID + a random per-launch session password (and an optional fixed personal
 * password for unattended access), and starts screen sharing after the user grants capture.
 */
class MainActivity : Activity() {
    private val requestCode = 7001
    private val alphabet = "ABCDEFGHJKLMNPQRSTUVWXYZ23456789"
    private lateinit var baseUrlInput: EditText
    private lateinit var idView: TextView
    private lateinit var pwView: TextView
    private lateinit var personalInput: EditText
    private lateinit var requireAcceptBox: CheckBox
    private lateinit var status: TextView
    private lateinit var accessBtn: Button
    private lateinit var readyBtn: Button
    private var sessionPw = ""
    // Auto-arm screen share once per launch (see maybeAutoShare). Android security forbids a silent
    // grant across restarts, so the best we can do is pop the system dialog automatically on open.
    private var autoSharePrompted = false

    private fun prefs() = getSharedPreferences("vremote", MODE_PRIVATE)

    override fun onCreate(b: Bundle?) {
        super.onCreate(b)
        val p = prefs()
        // Persist any uncaught crash so it can be shown (and screenshotted) on the next launch,
        // instead of the app just vanishing. Native (SIGSEGV) crashes can't be caught here.
        Thread.getDefaultUncaughtExceptionHandler().let { prev ->
            Thread.setDefaultUncaughtExceptionHandler { th, e ->
                try { p.edit().putString("lastCrash", (e.message ?: e.toString()) + "\n" + e.stackTraceToString().take(3000)).apply() } catch (_: Throwable) {}
                prev?.uncaughtException(th, e)
            }
        }
        if (p.getString("machineCode", "").isNullOrEmpty()) {
            val aid = try { Settings.Secure.getString(contentResolver, Settings.Secure.ANDROID_ID) } catch (e: Exception) { null }
            p.edit().putString("machineCode", "android-" + (aid ?: java.util.UUID.randomUUID().toString())).apply()
        }
        // Session password is stable: generated once, reused every launch. Only "Đổi" changes it.
        sessionPw = p.getString("sessionPw", "")?.takeIf { it.isNotEmpty() } ?: randomPw().also { p.edit().putString("sessionPw", it).apply() }
        // Pull the REAL native crash (tombstone) of the previous process from Android 11's
        // ApplicationExitInfo — the definitive stack for the intermittent SIGSEGV that breadcrumbs can't pin.
        captureExitInfo(p)
        captureCrashLogcat(p)

        val root = LinearLayout(this).apply { orientation = LinearLayout.VERTICAL; setPadding(32, 40, 32, 32) }
        // Header: app name (left) + version badge in the top corner, so the running build is obvious.
        val header = LinearLayout(this).apply { orientation = LinearLayout.HORIZONTAL; gravity = Gravity.CENTER_VERTICAL }
        header.addView(TextView(this).apply { text = "VRemote Agent"; textSize = 22f; setTypeface(typeface, android.graphics.Typeface.BOLD); layoutParams = LinearLayout.LayoutParams(0, LinearLayout.LayoutParams.WRAP_CONTENT, 1f) })
        header.addView(TextView(this).apply { text = "v" + BuildConfig.VERSION_NAME; textSize = 16f; setTextColor(0xFF2563EB.toInt()); setTypeface(typeface, android.graphics.Typeface.BOLD) })
        root.addView(header)
        root.addView(TextView(this).apply { text = "Chia sẻ màn hình thiết bị này"; setTextColor(0xFF6B7280.toInt()); setPadding(0, 2, 0, 0) })

        // Crash diagnostics only appear when set (small, so they don't clutter the normal screen).
        p.getString("prevCrumb", "")?.takeIf { it.isNotEmpty() }?.let { pc ->
            root.addView(TextView(this).apply { text = "🔎 Bước cuối lần chạy trước (nghi crash): $pc"; setTextColor(0xFFB91C1C.toInt()); textSize = 12f; setPadding(0, 12, 0, 2) })
        }
        p.getString("exitInfo", "")?.takeIf { it.isNotEmpty() }?.let { ex ->
            root.addView(TextView(this).apply { text = "💥 Lỗi native lần trước (gửi kỹ thuật):\n$ex"; setTextColor(0xFFB91C1C.toInt()); textSize = 10.5f; setTextIsSelectable(true); setPadding(0, 6, 0, 2) })
        }
        p.getString("lastCrash", "")?.takeIf { it.isNotEmpty() }?.let { c ->
            root.addView(TextView(this).apply { text = "⚠ App đã dừng lần trước:\n$c"; setTextColor(0xFFB91C1C.toInt()); textSize = 11f; setPadding(0, 6, 0, 2) })
        }

        // ── Essentials (always visible) ─────────────────────────────────────────────
        root.addView(label("ID của bạn"))
        idView = TextView(this).apply { text = "…"; textSize = 30f; setTypeface(typeface, android.graphics.Typeface.BOLD) }
        root.addView(idView)

        root.addView(label("Mật khẩu (phiên)"))
        val pwRow = LinearLayout(this).apply { orientation = LinearLayout.HORIZONTAL; gravity = Gravity.CENTER_VERTICAL }
        pwView = TextView(this).apply { text = sessionPw; textSize = 26f; setTextColor(0xFF2563EB.toInt()); setTypeface(typeface, android.graphics.Typeface.BOLD); layoutParams = LinearLayout.LayoutParams(0, LinearLayout.LayoutParams.WRAP_CONTENT, 1f) }
        pwRow.addView(pwView)
        pwRow.addView(Button(this).apply { text = "Đổi"; setOnClickListener { sessionPw = randomPw(); prefs().edit().putString("sessionPw", sessionPw).apply(); pwView.text = sessionPw; announce() } })
        root.addView(pwRow)

        // The primary action. Screen share is auto-armed on launch (maybeAutoShare); this button lets the
        // user (re)grant on demand or after "Dừng chia sẻ".
        readyBtn = Button(this).apply { text = "Bật sẵn sàng chia sẻ"; setOnClickListener { startShare() } }
        root.addView(readyBtn)
        status = TextView(this).apply { text = "Đang lấy ID…"; setPadding(0, 12, 0, 4) }
        root.addView(status)

        // Accessibility is required for remote CONTROL (taps/swipes), so keep it one tap away.
        accessBtn = Button(this).apply { text = "Cho phép điều khiển (Accessibility)"; setOnClickListener { startActivity(Intent(Settings.ACTION_ACCESSIBILITY_SETTINGS)) } }
        root.addView(accessBtn)
        root.addView(Button(this).apply { text = "Cập nhật bản mới nhất"; setOnClickListener { checkForUpdate() } })

        // ── Advanced (collapsed) ────────────────────────────────────────────────────
        // Everything technical is tucked away so the main screen stays uncluttered.
        val advanced = LinearLayout(this).apply { orientation = LinearLayout.VERTICAL; visibility = android.view.View.GONE }
        val advToggle = Button(this).apply { text = "⚙ Nâng cao ▾"; setPadding(0, 8, 0, 8) }
        advToggle.setOnClickListener {
            val show = advanced.visibility != android.view.View.VISIBLE
            advanced.visibility = if (show) android.view.View.VISIBLE else android.view.View.GONE
            advToggle.text = if (show) "⚙ Nâng cao ▴" else "⚙ Nâng cao ▾"
        }
        root.addView(advToggle)
        root.addView(advanced)

        val autoShareBox = CheckBox(this).apply { text = "Tự động hỏi chia sẻ khi mở app"; isChecked = p.getBoolean("autoShare", true)
            setOnCheckedChangeListener { _, v -> prefs().edit().putBoolean("autoShare", v).apply() } }
        advanced.addView(autoShareBox)

        advanced.addView(label("Máy chủ (Base URL)"))
        baseUrlInput = EditText(this).apply { setText(p.getString("baseUrl", "https://vremote.vplus.vn")); inputType = InputType.TYPE_TEXT_VARIATION_URI }
        advanced.addView(baseUrlInput)

        advanced.addView(label("Mật khẩu riêng (unattended, tuỳ chọn)"))
        val perRow = LinearLayout(this).apply { orientation = LinearLayout.HORIZONTAL }
        personalInput = EditText(this).apply { setText(p.getString("personalPw", "")); hint = "để trống = không dùng"; layoutParams = LinearLayout.LayoutParams(0, LinearLayout.LayoutParams.WRAP_CONTENT, 1f) }
        perRow.addView(personalInput)
        perRow.addView(Button(this).apply { text = "Lưu"; setOnClickListener { prefs().edit().putString("personalPw", personalInput.text.toString()).apply(); announce(); toast("Đã lưu mật khẩu riêng") } })
        advanced.addView(perRow)

        requireAcceptBox = CheckBox(this).apply { text = "Bắt buộc hỏi “Đồng ý” khi có người kết nối"; isChecked = p.getBoolean("requireAccept", false)
            setOnCheckedChangeListener { _, v -> prefs().edit().putBoolean("requireAccept", v).apply(); announce() } }
        advanced.addView(requireAcceptBox)

        advanced.addView(Button(this).apply { text = "Dừng chia sẻ"; setOnClickListener { startAgentService(Intent(this@MainActivity, RemoteAgentService::class.java).setAction(RemoteAgentService.ACTION_STOP)); refreshReady(); toast("Đã dừng chia sẻ (vẫn nhận kết nối)") } })
        // Video transport + capture quality are adjustable in-app so a weak box can be tuned WITHOUT a rebuild.
        advanced.addView(label("Nếu app bị VĂNG hoặc lag khi kết nối: đổi Kiểu hình / hạ chất lượng rồi thử lại."))
        val vmBtn = Button(this).apply { text = videoModeLabel() }
        vmBtn.setOnClickListener {
            val next = if ((prefs().getString("videoMode", "mjpeg") ?: "mjpeg") == "mjpeg") "vp8" else "mjpeg"
            prefs().edit().putString("videoMode", next).apply()
            vmBtn.text = videoModeLabel()
            restartService(vmBtn)
            toast("Kiểu hình: ${if (next == "mjpeg") "MJPEG (an toàn)" else "VP8"}. Sẽ tự bật lại chia sẻ.")
        }
        advanced.addView(vmBtn)
        val qualBtn = Button(this).apply { text = qualityLabel() }
        qualBtn.setOnClickListener {
            // Max long-edge (px). Capture keeps device aspect ratio; higher = sharper but heavier.
            val tiers = listOf(360, 480, 640, 960, 1280, 1920)
            val cur = prefs().getInt("capMaxEdge", 640)
            val next = tiers[((tiers.indexOf(cur).takeIf { it >= 0 } ?: tiers.indexOf(640)) + 1).mod(tiers.size)]
            prefs().edit().putInt("capMaxEdge", next).apply()
            qualBtn.text = qualityLabel()
            restartService(qualBtn)
            toast("Chất lượng tối đa: ${next}px. Sẽ tự bật lại chia sẻ.")
        }
        advanced.addView(qualBtn)
        advanced.addView(Button(this).apply { text = "Bỏ giới hạn tiết kiệm pin (tuỳ chọn)"; setOnClickListener { openBatterySettings() } })
        advanced.addView(Button(this).apply { text = "Thông tin thiết bị (gửi kỹ thuật)"; setOnClickListener { showDeviceInfo() } })
        p.getString("lastCrash", "")?.takeIf { it.isNotEmpty() }?.let {
            advanced.addView(Button(this).apply { text = "Xoá thông báo lỗi"; setOnClickListener { prefs().edit().remove("lastCrash").apply(); recreate() } })
        }
        advanced.addView(label("Sau khi khởi động lại máy, mở app là tự hỏi chia sẻ (bấm “Bắt đầu ngay”)."))

        setContentView(ScrollView(this).apply { addView(root) })
        announce()
    }

    override fun onResume() { super.onResume(); refreshReady() }

    /** Reflect the live "ready" state: projection held + accessibility enabled. */
    private fun refreshReady() {
        if (::accessBtn.isInitialized) accessBtn.text = if (VRemoteInputService.isEnabled()) "Đã bật điều khiển ✓" else "Cho phép điều khiển (Accessibility)"
        val held = RemoteAgentService.projectionHeld
        if (::readyBtn.isInitialized) readyBtn.text = if (held) "Sẵn sàng chia sẻ ✓ (đã cho phép)" else "Bật sẵn sàng chia sẻ"
        if (::status.isInitialized) {
            if (held) { status.text = "Sẵn sàng nhận điều khiển ✓"; status.setTextColor(0xFF16A34A.toInt()) }
            else { status.text = "Đang nhận kết nối. Bật “sẵn sàng điều khiển” 1 lần để cho phép chạm/vuốt."; status.setTextColor(0xFF6B7280.toInt()) }
        }
    }

    /** Start (or signal) the foreground agent service. Pass the projection result Intent to enable video. */
    private fun startAgentService(data: Intent?) {
        val i = data?.takeIf { it.action == RemoteAgentService.ACTION_STOP }
            ?: Intent(this, RemoteAgentService::class.java).also { if (data != null) it.putExtra(RemoteAgentService.EXTRA_RESULT_CODE, RESULT_OK).putExtra(RemoteAgentService.EXTRA_DATA, data) }
        startForegroundService(i)
    }

    private fun openBatterySettings() {
        try { startActivity(Intent(Settings.ACTION_REQUEST_IGNORE_BATTERY_OPTIMIZATIONS).setData(Uri.parse("package:$packageName"))) }
        catch (e: Exception) { try { startActivity(Intent(Settings.ACTION_IGNORE_BATTERY_OPTIMIZATION_SETTINGS)) } catch (_: Exception) {} }
    }

    /** Register/refresh this device and show its ID + password (only hashes leave the device). */
    private fun announce() {
        val base = baseUrlInput.text.toString().trim().ifEmpty { "https://vremote.vplus.vn" }
        val p = prefs()
        p.edit().putString("baseUrl", base).apply()
        val machineCode = p.getString("machineCode", "") ?: ""
        val personal = personalInput.text.toString()
        val requireAccept = requireAcceptBox.isChecked
        Thread {
            try {
                // Upload diagnostics so support can read the box's codec caps + last crash point in the
                // console without the tester pasting anything (helps pin the first-frame encode crash).
                val exit = p.getString("exitInfo", "") ?: ""
                val crumbs = p.getString("crumbLog", "") ?: ""
                val crashLogcat = p.getString("crashLogcat", "") ?: ""
                val di = (if (exit.isNotEmpty()) "== LAST PROCESS EXIT / NATIVE TRACE ==\n$exit\n\n" else "") +
                    (if (crashLogcat.isNotEmpty()) "== BEST-EFFORT CRASH LOGCAT (may be denied by Android) ==\n$crashLogcat\n\n" else "") +
                    (if (crumbs.isNotEmpty()) "== RECENT VREMOTE BREADCRUMBS ==\n$crumbs\n\n" else "") +
                    (try { DeviceInfo.report() } catch (_: Throwable) { "" })
                val ev = ("prevCrumb=" + (p.getString("prevCrumb", "") ?: "") + " | crumb=" + (p.getString("crumb", "") ?: "") + " | flow=" + (p.getString("flow", "") ?: "") + " | boot=" + (p.getString("boot", "") ?: "") + " | exit=" + exit.lineSequence().firstOrNull().orEmpty() + " | crash=" + (p.getString("lastCrash", "") ?: "").lineSequence().firstOrNull().orEmpty()).take(500)
                val a = ApiClient.announce(base, machineCode, ApiClient.sha256(sessionPw), if (personal.isNotEmpty()) ApiClient.sha256(personal) else "", requireAccept, di, ev)
                p.edit().putString("deviceId", a.deviceId).putString("deviceToken", a.deviceToken)
                    .putString("pairingId", a.pairingId).putString("signalingUrl", ApiClient.signalingUrl(base)).apply()
                // Start the service the moment we have creds so signaling connects and the device is
                // reachable WITHOUT pressing share. Projection (video) is enabled separately, one-time.
                runOnUiThread { idView.text = fmtId(a.pairingId); startAgentService(null); refreshReady(); maybeAutoShare() }
            } catch (e: Exception) {
                runOnUiThread { status.text = "Lỗi kết nối máy chủ: " + (e.message ?: e.toString()) }
            }
        }.start()
    }

    /** Fetch the published manifest; if it advertises a newer versionCode, download + install it. */
    private fun checkForUpdate() {
        val base = baseUrlInput.text.toString().trim().ifEmpty { "https://vremote.vplus.vn" }
        status.text = "Đang kiểm tra bản cập nhật…"
        Thread {
            try {
                val info = ApiClient.latest(base)
                if (info.versionCode <= BuildConfig.VERSION_CODE) {
                    runOnUiThread { status.text = "Đã là bản mới nhất (${BuildConfig.VERSION_NAME})."; toast("Đã là bản mới nhất") }
                    return@Thread
                }
                if (!AgentUpdater.canInstall(this)) {
                    runOnUiThread {
                        status.text = "Cần cấp quyền “Cài đặt ứng dụng không xác định”, sau đó bấm lại."
                        startActivity(AgentUpdater.unknownSourcesIntent(this))
                    }
                    return@Thread
                }
                runOnUiThread { status.text = "Đang tải bản ${info.versionName}… 0%" }
                val apk = AgentUpdater.download(this, base, info) { pct ->
                    runOnUiThread { status.text = if (pct >= 0) "Đang tải bản ${info.versionName}… $pct%" else "Đang tải bản ${info.versionName}…" }
                }
                runOnUiThread { status.text = "Đang cài đặt bản ${info.versionName}…"; AgentUpdater.install(this, apk) }
            } catch (e: Exception) {
                runOnUiThread { status.text = "Lỗi cập nhật: " + (e.message ?: e.toString()) }
            }
        }.start()
    }

    private fun startShare() {
        if ((prefs().getString("deviceId", "") ?: "").isEmpty()) { announce(); toast("Đang lấy ID, thử lại sau giây lát"); return }
        val mpm = getSystemService(MediaProjectionManager::class.java)
        try { startActivityForResult(mpm.createScreenCaptureIntent(), requestCode) } catch (_: Throwable) {}
    }

    /**
     * Auto-arm screen share once per launch. Android forbids a silent MediaProjection grant across
     * restarts (each new capture session needs a fresh user tap), so the friendliest behaviour is to
     * pop the system dialog automatically on open — the user just taps "Bắt đầu ngay" once. Opt out via
     * the "Tự động hỏi chia sẻ khi mở app" checkbox. Called after announce() has creds so the ID exists.
     */
    private fun maybeAutoShare() {
        if (autoSharePrompted) return
        if (!prefs().getBoolean("autoShare", true)) return
        if (RemoteAgentService.projectionHeld) return
        if ((prefs().getString("deviceId", "") ?: "").isEmpty()) return
        autoSharePrompted = true
        startShare()
    }

    @Deprecated("Android callback API")
    override fun onActivityResult(r: Int, c: Int, d: Intent?) {
        super.onActivityResult(r, c, d)
        if (r == requestCode && c == RESULT_OK && d != null) {
            startAgentService(d)
            status.text = "Đã bật sẵn sàng. Console kết nối được mà không cần thao tác thêm."
        }
    }

    /** Collect device + MediaCodec encoder capabilities, copy to clipboard, and show for screenshot/paste. */
    private fun showDeviceInfo() {
        val p = prefs()
        val exit = p.getString("exitInfo", "") ?: ""
        val crumbs = p.getString("crumbLog", "") ?: ""
        val txt = (if (exit.isNotEmpty()) "== LAST PROCESS EXIT / NATIVE TRACE ==\n$exit\n\n" else "") +
            (if (crumbs.isNotEmpty()) "== RECENT VREMOTE BREADCRUMBS ==\n$crumbs\n\n" else "") +
            (try { DeviceInfo.report() } catch (e: Throwable) { "Lỗi thu thập: " + (e.message ?: e.toString()) })
        try { (getSystemService(CLIPBOARD_SERVICE) as ClipboardManager).setPrimaryClip(ClipData.newPlainText("vremote-device", txt)) } catch (_: Throwable) {}
        val tv = TextView(this).apply { text = txt; textSize = 11f; setTextIsSelectable(true); setPadding(32, 24, 32, 24) }
        AlertDialog.Builder(this).setTitle("Thông tin thiết bị (đã copy)").setView(ScrollView(this).apply { addView(tv) }).setPositiveButton("Đóng", null).show()
        toast("Đã copy — gửi kỹ thuật")
    }
    private fun videoModeLabel(): String = "Kiểu hình: ${if ((prefs().getString("videoMode", "mjpeg") ?: "mjpeg") == "mjpeg") "MJPEG (an toàn)" else "VP8"} — bấm để đổi"
    private fun qualityLabel(): String = "Chất lượng: tối đa ${prefs().getInt("capMaxEdge", 640)}px — bấm để đổi"
    /** The encoder factory + capture size are read when the service (re)starts, so fully restart it to
     *  apply a new mode/quality. Projection is torn down → user re-grants with "Bật sẵn sàng". */
    private fun restartService(v: android.view.View) {
        stopService(Intent(this, RemoteAgentService::class.java))
        autoSharePrompted = false // re-arm so the encoder/quality change re-pops the share dialog
        v.postDelayed({ startAgentService(null); refreshReady(); maybeAutoShare() }, 700)
    }
    /** Read Android's historical exit record. Some OEM builds return metadata but no tombstone stream. */
    private fun captureExitInfo(p: android.content.SharedPreferences) {
        if (Build.VERSION.SDK_INT < 30) return
        try {
            val am = getSystemService(ActivityManager::class.java) ?: return
            val infos = am.getHistoricalProcessExitReasons(packageName, 0, 8)
            for (info in infos) {
                val r = info.reason
                if (r == ApplicationExitInfo.REASON_CRASH_NATIVE || r == ApplicationExitInfo.REASON_SIGNALED || r == ApplicationExitInfo.REASON_CRASH) {
                    val head = "exit r=$r st=${info.status} @${info.timestamp / 1000 % 100000} desc=${info.description ?: ""} importance=${info.importance} pssKb=${info.pss} rssKb=${info.rss}"
                    var trace = ""
                    var traceStatus = "traceInputStream unavailable"
                    try {
                        val stream = info.traceInputStream
                        if (stream != null) {
                            stream.use { ins ->
                                val full = ins.bufferedReader().readText()
                                if (full.isNotBlank()) {
                                    // Preserve useful native details even when OEM tombstone formatting differs.
                                    val lines = full.lineSequence().map { it.trim() }.filter { t ->
                                        t.isNotEmpty() && (
                                            t.contains("signal", ignoreCase = true) ||
                                            t.contains("Abort message", ignoreCase = true) ||
                                            t.contains("backtrace", ignoreCase = true) ||
                                            t.contains("tombstone", ignoreCase = true) ||
                                            t.contains("libwebrtc", ignoreCase = true) ||
                                            t.contains("libjingle", ignoreCase = true) ||
                                            t.contains("libc.so", ignoreCase = true) ||
                                            Regex("^#\\d+\\s").containsMatchIn(t) ||
                                            t.contains("pc ", ignoreCase = true) ||
                                            t.contains("lr ", ignoreCase = true)
                                        )
                                    }.take(48).joinToString("\n")
                                    trace = (lines.ifBlank { full }).takeLast(9000)
                                    traceStatus = "traceInputStream returned ${full.length} chars"
                                } else {
                                    traceStatus = "traceInputStream empty"
                                }
                            }
                        }
                    } catch (e: Throwable) {
                        traceStatus = "traceInputStream error: ${e.javaClass.simpleName}: ${e.message ?: ""}".take(300)
                    }
                    p.edit().putString("exitInfo", (head + "\ntraceStatus=" + traceStatus + "\n" + trace).take(10000)).apply()
                    return
                }
            }
            // Keep previous native-exit data until a later actual crash replaces it.
        } catch (_: Throwable) {}
    }

    /**
     * Best-effort fallback for OEMs that expose ApplicationExitInfo metadata but no trace stream.
     * Android may deny logcat access to ordinary apps; record that explicitly rather than failing.
     * Only crash-related lines for this package / native stack markers are retained.
     */
    private fun captureCrashLogcat(p: android.content.SharedPreferences) {
        try {
            val process = ProcessBuilder("logcat", "-b", "crash", "-d", "-v", "threadtime")
                .redirectErrorStream(true).start()
            val output = process.inputStream.bufferedReader().use { it.readText().takeLast(30000) }
            val finished = try { process.waitFor(2, java.util.concurrent.TimeUnit.SECONDS) } catch (_: Throwable) { false }
            if (!finished) process.destroyForcibly()
            val lines = output.lineSequence().toList()
            val relevant = lines.filter { line ->
                val lower = line.lowercase()
                (lower.contains(packageName.lowercase()) ||
                 lower.contains("fatal signal") || lower.contains("abort message") ||
                 lower.contains("tombstone") || lower.contains("debuggerd") ||
                 lower.contains("libwebrtc") || lower.contains("libjingle") ||
                 lower.contains("signal 6") || lower.contains("signal 11") ||
                 Regex("^.*#\\d+\\s").containsMatchIn(line))
            }.takeLast(100).joinToString("\n")
            val status = if (output.isBlank()) "logcat crash buffer empty or access denied"
                         else if (relevant.isBlank()) "logcat readable; no matching crash lines"
                         else "captured ${relevant.length} chars"
            p.edit().putString("crashLogcat", ("status=" + status + "\n" + relevant).take(9000)).apply()
        } catch (e: Throwable) {
            p.edit().putString("crashLogcat", "logcat unavailable: ${e.javaClass.simpleName}: ${e.message ?: ""}".take(500)).apply()
        }
    }
    private fun label(t: String) = TextView(this).apply { text = t; setTextColor(0xFF6B7280.toInt()); setPadding(0, 20, 0, 4) }
    private fun randomPw(n: Int = 6): String { val r = SecureRandom(); return (1..n).map { alphabet[r.nextInt(alphabet.length)] }.joinToString("") }
    private fun fmtId(id: String): String = id.replace(Regex("(\\d{3})(?=\\d)"), "$1 ").trim()
    private fun toast(m: String) = Toast.makeText(this, m, Toast.LENGTH_SHORT).show()
}
