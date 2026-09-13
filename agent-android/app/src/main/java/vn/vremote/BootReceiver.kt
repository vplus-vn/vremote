package vn.vremote

import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent

/**
 * Re-start the agent after reboot so signaling reconnects and the device is reachable again
 * without opening the app. NOTE: the MediaProjection consent token is lost on reboot, so the
 * FIRST session after a reboot needs one re-consent (unavoidable for a non-privileged app);
 * the AccessibilityService (input) survives reboot once enabled.
 */
class BootReceiver : BroadcastReceiver() {
    override fun onReceive(c: Context, i: Intent) {
        if (i.action != Intent.ACTION_BOOT_COMPLETED) return
        val p = c.getSharedPreferences("vremote", Context.MODE_PRIVATE)
        val id = p.getString("deviceId", "") ?: ""
        val tok = p.getString("deviceToken", "") ?: ""
        if (id.isEmpty() || tok.isEmpty()) return
        try { c.startForegroundService(Intent(c, RemoteAgentService::class.java)) } catch (_: Throwable) {}
    }
}
