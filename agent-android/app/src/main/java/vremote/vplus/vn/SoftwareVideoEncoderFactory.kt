package vremote.vplus.vn

import android.content.Context
import org.webrtc.VideoCodecInfo
import org.webrtc.VideoEncoder
import org.webrtc.VideoEncoderFactory

/**
 * RK3566 isolation shim.
 *
 * The WebRTC 150 SoftwareVideoEncoderFactory constructor immediately enters native JNI and
 * creates its native encoder factory. On the affected RK3566 box that happens before any video
 * track exists and is exactly the failure point reported by the tester ("factory [sw 0x0 q640]").
 *
 * MJPEG mode does not need an encoder at all. This same-named class shadows the imported WebRTC
 * implementation in RemoteAgentService.kt and keeps the native encoder factory completely lazy.
 * Only when the user explicitly selects VP8 do we instantiate the real WebRTC software factory.
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

    private fun vp8Enabled(): Boolean =
        try {
            AppContextHolder.context
                ?.getSharedPreferences("vremote", Context.MODE_PRIVATE)
                ?.getString("videoMode", "mjpeg") == "vp8"
        } catch (_: Throwable) {
            false
        }

    override fun getSupportedCodecs(): Array<VideoCodecInfo> =
        if (vp8Enabled()) realFactory().supportedCodecs else emptyArray()

    override fun createEncoder(info: VideoCodecInfo): VideoEncoder? =
        if (vp8Enabled()) realFactory().createEncoder(info) else null
}

/** Minimal process context holder used only by the lazy encoder isolation shim. */
object AppContextHolder {
    @Volatile var context: Context? = null
}
