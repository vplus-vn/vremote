package vremote.vplus.vn

import org.webrtc.VideoCodecInfo
import org.webrtc.VideoEncoder
import org.webrtc.VideoEncoderFactory

/**
 * RK3566 isolation shim.
 *
 * WebRTC 150's SoftwareVideoEncoderFactory constructor enters native JNI immediately. The
 * affected RK3566 box reports the crash at this factory boundary before any video frame exists.
 * MJPEG mode needs no encoder, so this same-named class keeps the real WebRTC factory lazy.
 */
class SoftwareVideoEncoderFactory : VideoEncoderFactory {
    private var delegate: VideoEncoderFactory? = null

    private fun realFactory(): VideoEncoderFactory {
        val existing = delegate
        if (existing != null) return existing
        val created = org.webrtc.SoftwareVideoEncoderFactory()
        delegate = created
        return created
    }

    private fun vp8Enabled(): Boolean = try {
        val app = Class.forName("android.app.ActivityThread")
            .getMethod("currentApplication")
            .invoke(null)
        val prefs = (app as? android.content.Context)
            ?.getSharedPreferences("vremote", android.content.Context.MODE_PRIVATE)
        prefs?.getString("videoMode", "mjpeg") == "vp8"
    } catch (_: Throwable) {
        false
    }

    override fun getSupportedCodecs(): Array<VideoCodecInfo> =
        if (vp8Enabled()) realFactory().supportedCodecs else emptyArray()

    override fun createEncoder(info: VideoCodecInfo): VideoEncoder? =
        if (vp8Enabled()) realFactory().createEncoder(info) else null
}
