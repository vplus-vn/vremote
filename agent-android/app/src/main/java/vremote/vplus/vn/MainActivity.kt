package vremote.vplus.vn

import android.app.Activity
import android.app.AlertDialog
import android.content.ClipData
import android.content.ClipboardManager
import android.content.Intent
import android.media.projection.MediaProjectionManager
import android.net.Uri
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

        val root = LinearLayout(this).apply { orientation = LinearLayout.VERTICAL; setPadding(32, 48, 32, 32) }
        root.addView(TextView(this).apply { text = "VRemote Agent"; textSize = 22f })
        root.addView(TextView(this).apply { text = "Chia sẻ màn hình thiết bị này"; setTextColor(0xFF6B7280.toInt()) })

        p.getString("crumb", "")?.takeIf { it.isNotEmpty() }?.let { cr ->
            root.addView(TextView(this).apply { text = "🔎 Bước cuối trước khi dừng: $cr — chụp gửi kỹ thuật"; setTextColor(0xFF2563EB.toInt()); textSize = 12f; setPadding(0, 12, 0, 4) })
        }
        p.getString("lastCrash", "")?.takeIf { it.isNotEmpty() }?.let { c ->
            root.addView(TextView(this).apply { text = "⚠ App đã dừng lần trước — chụp gửi kỹ thuật:\n$c"; setTextColor(0xFFB91C1C.toInt()); textSize = 11f; setPadding(0, 16, 0, 8) })
            root.addView(Button(this).apply { text = "Xoá thông báo lỗi"; setOnClickListener { p.edit().remove("lastCrash").apply(); recreate() } })
        }

        root.addView(label("Máy chủ (Base URL)"))
        baseUrlInput = EditText(this).apply { setText(p.getString("baseUrl", "https://vremote.vplus.vn")); inputType = InputType.TYPE_TEXT_VARIATION_URI }
        root.addView(baseUrlInput)

        root.addView(label("ID của bạn"))
        idView = TextView(this).apply { text = "…"; textSize = 30f; setTypeface(typeface, android.graphics.Typeface.BOLD) }
        root.addView(idView)

        root.addView(label("Mật khẩu (phiên)"))
        val pwRow = LinearLayout(this).apply { orientation = LinearLayout.HORIZONTAL; gravity = Gravity.CENTER_VERTICAL }
        pwView = TextView(this).apply { text = sessionPw; textSize = 26f; setTextColor(0xFF2563EB.toInt()); setTypeface(typeface, android.graphics.Typeface.BOLD) }
        pwRow.addView(pwView)
        pwRow.addView(Button(this).apply { text = "Đổi"; setOnClickListener { sessionPw = randomPw(); prefs().edit().putString("sessionPw", sessionPw).apply(); pwView.text = sessionPw; announce() } })
        root.addView(pwRow)

        root.addView(label("Mật khẩu riêng (unattended, tuỳ chọn)"))
        val perRow = LinearLayout(this).apply { orientation = LinearLayout.HORIZONTAL }
        personalInput = EditText(this).apply { setText(p.getString("personalPw", "")); hint = "để trống = không dùng"; layoutParams = LinearLayout.LayoutParams(0, LinearLayout.LayoutParams.WRAP_CONTENT, 1f) }
        perRow.addView(personalInput)
        perRow.addView(Button(this).apply { text = "Lưu"; setOnClickListener { prefs().edit().putString("personalPw", personalInput.text.toString()).apply(); announce(); toast("Đã lưu mật khẩu riêng") } })
        root.addView(perRow)

        requireAcceptBox = CheckBox(this).apply { text = "Bắt buộc hỏi “Đồng ý” khi có người kết nối"; isChecked = p.getBoolean("requireAccept", false)
            setOnCheckedChangeListener { _, v -> prefs().edit().putBoolean("requireAccept", v).apply(); announce() } }
        root.addView(requireAcceptBox)

        root.addView(label("Chỉ cần bật MỘT LẦN. Sau đó console kết nối được mà không cần chạm máy."))
        readyBtn = Button(this).apply { text = "Bật sẵn sàng điều khiển (1 lần)"; setOnClickListener { startShare() } }
        root.addView(readyBtn)
        root.addView(Button(this).apply { text = "Dừng chia sẻ"; setOnClickListener { startAgentService(Intent(this@MainActivity, RemoteAgentService::class.java).setAction(RemoteAgentService.ACTION_STOP)); refreshReady(); toast("Đã dừng chia sẻ (vẫn nhận kết nối)") } })
        // Encoder mode + capture quality are adjustable in-app so a weak box can be tuned down (or a
        // stuck video pipeline switched sw<->hw) WITHOUT a rebuild. Capture keeps the DEVICE'S OWN screen
        // aspect ratio + resolution (just downscaled to the chosen quality), so it stays correct across
        // many devices. Each crash breadcrumb is tagged [mode WxH qN].
        root.addView(label("Nếu app bị VĂNG hoặc lag khi kết nối: đổi chế độ mã hoá / hạ chất lượng rồi thử lại."))
        val encBtn = Button(this).apply { text = encModeLabel() }
        encBtn.setOnClickListener {
            val next = if ((p.getString("encMode", "sw") ?: "sw") == "sw") "hw" else "sw"
            p.edit().putString("encMode", next).apply()
            encBtn.text = encModeLabel()
            restartService(encBtn)
            toast("Mã hoá: ${next.uppercase()}. Bấm “Bật sẵn sàng điều khiển” rồi kết nối lại.")
        }
        root.addView(encBtn)
        val qualBtn = Button(this).apply { text = qualityLabel() }
        qualBtn.setOnClickListener {
            // Max long-edge (px). Capture keeps device aspect ratio; higher = sharper but heavier.
            val tiers = listOf(360, 480, 640, 960, 1280, 1920)
            val cur = p.getInt("capMaxEdge", 640)
            val next = tiers[((tiers.indexOf(cur).takeIf { it >= 0 } ?: tiers.indexOf(640)) + 1).mod(tiers.size)]
            p.edit().putInt("capMaxEdge", next).apply()
            qualBtn.text = qualityLabel()
            restartService(qualBtn)
            toast("Chất lượng tối đa: ${next}px. Bấm “Bật sẵn sàng điều khiển” rồi kết nối lại.")
        }
        root.addView(qualBtn)
        root.addView(label("Điều khiển từ xa cần bật dịch vụ trợ năng VRemote."))
        accessBtn = Button(this).apply { text = "Cho phép điều khiển (Accessibility)"; setOnClickListener { startActivity(Intent(Settings.ACTION_ACCESSIBILITY_SETTINGS)) } }
        root.addView(accessBtn)
        root.addView(Button(this).apply { text = "Bỏ giới hạn tiết kiệm pin (tuỳ chọn)"; setOnClickListener { openBatterySettings() } })
        root.addView(Button(this).apply { text = "Cập nhật bản mới nhất"; setOnClickListener { checkForUpdate() } })
        root.addView(Button(this).apply { text = "Thông tin thiết bị (gửi kỹ thuật)"; setOnClickListener { showDeviceInfo() } })
        root.addView(label("Sau khi khởi động lại máy, cần bật lại “sẵn sàng điều khiển” một lần."))
        status = TextView(this).apply { text = "Đang lấy ID…"; setPadding(0, 24, 0, 0) }
        root.addView(status)

        setContentView(ScrollView(this).apply { addView(root) })
        announce()
    }

    override fun onResume() { super.onResume(); refreshReady() }

    /** Reflect the live "ready" state: projection held + accessibility enabled. */
    private fun refreshReady() {
        if (::accessBtn.isInitialized) accessBtn.text = if (VRemoteInputService.isEnabled()) "Đã bật điều khiển ✓" else "Cho phép điều khiển (Accessibility)"
        val held = RemoteAgentService.projectionHeld
        if (::readyBtn.isInitialized) readyBtn.text = if (held) "Sẵn sàng chia sẻ ✓ (đã cho phép)" else "Bật sẵn sàng điều khiển (1 lần)"
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
                val di = try { DeviceInfo.report() } catch (_: Throwable) { "" }
                val ev = ("crumb=" + (p.getString("crumb", "") ?: "") + " | boot=" + (p.getString("boot", "") ?: "") + " | crash=" + (p.getString("lastCrash", "") ?: "").lineSequence().firstOrNull().orEmpty()).take(500)
                val a = ApiClient.announce(base, machineCode, ApiClient.sha256(sessionPw), if (personal.isNotEmpty()) ApiClient.sha256(personal) else "", requireAccept, di, ev)
                p.edit().putString("deviceId", a.deviceId).putString("deviceToken", a.deviceToken)
                    .putString("pairingId", a.pairingId).putString("signalingUrl", ApiClient.signalingUrl(base)).apply()
                // Start the service the moment we have creds so signaling connects and the device is
                // reachable WITHOUT pressing share. Projection (video) is enabled separately, one-time.
                runOnUiThread { idView.text = fmtId(a.pairingId); startAgentService(null); refreshReady() }
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
        startActivityForResult(mpm.createScreenCaptureIntent(), requestCode)
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
        val txt = try { DeviceInfo.report() } catch (e: Throwable) { "Lỗi thu thập: " + (e.message ?: e.toString()) }
        try { (getSystemService(CLIPBOARD_SERVICE) as ClipboardManager).setPrimaryClip(ClipData.newPlainText("vremote-device", txt)) } catch (_: Throwable) {}
        val tv = TextView(this).apply { text = txt; textSize = 11f; setTextIsSelectable(true); setPadding(32, 24, 32, 24) }
        AlertDialog.Builder(this).setTitle("Thông tin thiết bị (đã copy)").setView(ScrollView(this).apply { addView(tv) }).setPositiveButton("Đóng", null).show()
        toast("Đã copy — dán vào issue #144")
    }
    private fun encModeLabel(): String = "Mã hoá: ${(prefs().getString("encMode", "sw") ?: "sw").uppercase()} — bấm để đổi (SW/HW)"
    private fun qualityLabel(): String = "Chất lượng: tối đa ${prefs().getInt("capMaxEdge", 640)}px — bấm để đổi"
    /** The encoder factory + capture size are read when the service (re)starts, so fully restart it to
     *  apply a new mode/quality. Projection is torn down → user re-grants with "Bật sẵn sàng". */
    private fun restartService(v: android.view.View) {
        stopService(Intent(this, RemoteAgentService::class.java))
        v.postDelayed({ startAgentService(null); refreshReady() }, 700)
    }
    private fun label(t: String) = TextView(this).apply { text = t; setTextColor(0xFF6B7280.toInt()); setPadding(0, 20, 0, 4) }
    private fun randomPw(n: Int = 6): String { val r = SecureRandom(); return (1..n).map { alphabet[r.nextInt(alphabet.length)] }.joinToString("") }
    private fun fmtId(id: String): String = id.replace(Regex("(\\d{3})(?=\\d)"), "$1 ").trim()
    private fun toast(m: String) = Toast.makeText(this, m, Toast.LENGTH_SHORT).show()
}
