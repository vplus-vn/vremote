package vn.vplus.vremote.agent

import android.app.Activity
import android.content.Intent
import android.media.projection.MediaProjectionManager
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
    private var sessionPw = ""

    private fun prefs() = getSharedPreferences("vremote", MODE_PRIVATE)

    override fun onCreate(b: Bundle?) {
        super.onCreate(b)
        val p = prefs()
        if (p.getString("machineCode", "").isNullOrEmpty()) {
            val aid = try { Settings.Secure.getString(contentResolver, Settings.Secure.ANDROID_ID) } catch (e: Exception) { null }
            p.edit().putString("machineCode", "android-" + (aid ?: java.util.UUID.randomUUID().toString())).apply()
        }
        // Session password is stable: generated once, reused every launch. Only "Đổi" changes it.
        sessionPw = p.getString("sessionPw", "")?.takeIf { it.isNotEmpty() } ?: randomPw().also { p.edit().putString("sessionPw", it).apply() }

        val root = LinearLayout(this).apply { orientation = LinearLayout.VERTICAL; setPadding(32, 48, 32, 32) }
        root.addView(TextView(this).apply { text = "VRemote Agent"; textSize = 22f })
        root.addView(TextView(this).apply { text = "Chia sẻ màn hình thiết bị này"; setTextColor(0xFF6B7280.toInt()) })

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

        root.addView(Button(this).apply { text = "Bắt đầu chia sẻ màn hình"; setOnClickListener { startShare() } })
        status = TextView(this).apply { text = "Đang lấy ID…"; setPadding(0, 24, 0, 0) }
        root.addView(status)

        setContentView(ScrollView(this).apply { addView(root) })
        announce()
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
                val a = ApiClient.announce(base, machineCode, ApiClient.sha256(sessionPw), if (personal.isNotEmpty()) ApiClient.sha256(personal) else "", requireAccept)
                p.edit().putString("deviceId", a.deviceId).putString("deviceToken", a.deviceToken)
                    .putString("pairingId", a.pairingId).putString("signalingUrl", ApiClient.signalingUrl(base)).apply()
                runOnUiThread { idView.text = fmtId(a.pairingId); status.text = "Sẵn sàng. Bấm “Bắt đầu chia sẻ màn hình”." }
            } catch (e: Exception) {
                runOnUiThread { status.text = "Lỗi kết nối máy chủ: " + (e.message ?: e.toString()) }
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
            startForegroundService(Intent(this, RemoteAgentService::class.java)
                .putExtra(RemoteAgentService.EXTRA_RESULT_CODE, c).putExtra(RemoteAgentService.EXTRA_DATA, d))
            status.text = "Đang chia sẻ. Nhập ID + mật khẩu ở console để xem."
        }
    }

    private fun label(t: String) = TextView(this).apply { text = t; setTextColor(0xFF6B7280.toInt()); setPadding(0, 20, 0, 4) }
    private fun randomPw(n: Int = 6): String { val r = SecureRandom(); return (1..n).map { alphabet[r.nextInt(alphabet.length)] }.joinToString("") }
    private fun fmtId(id: String): String = id.replace(Regex("(\\d{3})(?=\\d)"), "$1 ").trim()
    private fun toast(m: String) = Toast.makeText(this, m, Toast.LENGTH_SHORT).show()
}
