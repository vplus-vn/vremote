package vn.vremote

import android.accessibilityservice.AccessibilityService
import android.accessibilityservice.GestureDescription
import android.graphics.Path
import android.os.Bundle
import android.view.accessibility.AccessibilityEvent
import android.view.accessibility.AccessibilityNodeInfo

/**
 * AccessibilityService used purely for remote input injection (tap/swipe/long-press/global
 * actions/text). RemoteAgentService dispatches decoded data-channel events here via `instance`.
 */
class VRemoteInputService : AccessibilityService() {
    override fun onServiceConnected() { super.onServiceConnected(); instance = this }
    override fun onUnbind(intent: android.content.Intent?): Boolean { instance = null; return super.onUnbind(intent) }
    override fun onDestroy() { instance = null; super.onDestroy() }
    override fun onAccessibilityEvent(event: AccessibilityEvent?) {}
    override fun onInterrupt() {}

    fun tap(x: Int, y: Int) { stroke(Path().apply { moveTo(x.toFloat(), y.toFloat()); lineTo(x.toFloat(), y.toFloat()) }, 0L, 50L) }
    fun swipe(x1: Int, y1: Int, x2: Int, y2: Int, durationMs: Long) { stroke(Path().apply { moveTo(x1.toFloat(), y1.toFloat()); lineTo(x2.toFloat(), y2.toFloat()) }, 0L, durationMs.coerceIn(1L, 60000L)) }
    fun longPress(x: Int, y: Int) { stroke(Path().apply { moveTo(x.toFloat(), y.toFloat()); lineTo(x.toFloat(), y.toFloat()) }, 0L, 600L) }

    private fun stroke(path: Path, start: Long, duration: Long) {
        try {
            val g = GestureDescription.Builder().addStroke(GestureDescription.StrokeDescription(path, start, duration.coerceAtLeast(1L))).build()
            dispatchGesture(g, null, null)
        } catch (_: Throwable) {}
    }

    fun global(action: String) {
        val a = when (action) {
            "back" -> GLOBAL_ACTION_BACK
            "home" -> GLOBAL_ACTION_HOME
            "recents" -> GLOBAL_ACTION_RECENTS
            "notifications" -> GLOBAL_ACTION_NOTIFICATIONS
            else -> return
        }
        try { performGlobalAction(a) } catch (_: Throwable) {}
    }

    /** Best-effort: APPEND text to the currently focused editable node. Null-safe no-op otherwise. */
    fun typeText(text: String) {
        try {
            val node: AccessibilityNodeInfo = findFocus(AccessibilityNodeInfo.FOCUS_INPUT) ?: return
            val cur = node.text?.toString() ?: ""
            setNodeText(node, cur + text)
        } catch (_: Throwable) {}
    }

    /** Best-effort: delete one trailing char from the focused editable node. Null-safe no-op. */
    fun delChar() {
        try {
            val node: AccessibilityNodeInfo = findFocus(AccessibilityNodeInfo.FOCUS_INPUT) ?: return
            val cur = node.text?.toString() ?: ""
            if (cur.isEmpty()) return
            setNodeText(node, cur.substring(0, cur.length - 1))
        } catch (_: Throwable) {}
    }

    private fun setNodeText(node: AccessibilityNodeInfo, value: String) {
        val args = Bundle().apply { putCharSequence(AccessibilityNodeInfo.ACTION_ARGUMENT_SET_TEXT_CHARSEQUENCE, value) }
        node.performAction(AccessibilityNodeInfo.ACTION_SET_TEXT, args)
        // Move caret to the end so subsequent appends land correctly.
        try {
            val sel = Bundle().apply {
                putInt(AccessibilityNodeInfo.ACTION_ARGUMENT_SELECTION_START_INT, value.length)
                putInt(AccessibilityNodeInfo.ACTION_ARGUMENT_SELECTION_END_INT, value.length)
            }
            node.performAction(AccessibilityNodeInfo.ACTION_SET_SELECTION, sel)
        } catch (_: Throwable) {}
    }

    companion object {
        @Volatile var instance: VRemoteInputService? = null
        fun isEnabled(): Boolean = instance != null
    }
}
