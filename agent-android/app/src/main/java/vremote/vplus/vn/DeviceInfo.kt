package vremote.vplus.vn

import android.content.Context
import android.media.MediaCodecList
import android.os.Build

/**
 * Collects the device + video-encoder capabilities needed to diagnose the native first-frame
 * encode crash WITHOUT guessing: which encoders exist for each codec, whether they are hardware
 * or software, which color formats they accept, and — most importantly — which frame sizes their
 * VideoCapabilities actually support (with the required width/height alignment). A capture size or
 * color format the codec doesn't really support is a prime suspect for a SIGSEGV at encode start.
 * The report is plain text so it can be screenshotted or pasted into the tracking issue.
 */
object DeviceInfo {
    private fun colorName(c: Int) = when (c) {
        19 -> "I420Planar(19)"
        20 -> "YUV420PackedPlanar(20)"
        21 -> "NV12SemiPlanar(21)"
        39 -> "NV21PackedSemiPlanar(39)"
        0x7F000789 -> "Surface(0x7F000789)"
        0x7F420888 -> "YUV420Flexible(0x7F420888)"
        else -> "0x%X".format(c)
    }

    fun report(): String {
        val sb = StringBuilder()
        sb.append("APP ${BuildConfig.VERSION_NAME} (code ${BuildConfig.VERSION_CODE})\n")
        sb.append("DEVICE ${Build.MANUFACTURER} ${Build.MODEL} / ${Build.DEVICE} / hw=${Build.HARDWARE}\n")
        sb.append("ANDROID ${Build.VERSION.RELEASE} (SDK ${Build.VERSION.SDK_INT})\n")
        if (Build.VERSION.SDK_INT >= 31) sb.append("SOC ${Build.SOC_MANUFACTURER} ${Build.SOC_MODEL}\n")
        sb.append("ABIS ${Build.SUPPORTED_ABIS.joinToString(",")}\n")

        val types = listOf("video/avc" to "H264", "video/x-vnd.on2.vp8" to "VP8", "video/x-vnd.on2.vp9" to "VP9", "video/hevc" to "H265")
        val list = try { MediaCodecList(MediaCodecList.ALL_CODECS) } catch (e: Throwable) {
            sb.append("\nMediaCodecList error: ${e.message}\n"); return sb.toString()
        }
        for ((mime, short) in types) {
            sb.append("\n== ENCODERS $short ($mime) ==\n")
            var any = false
            for (info in list.codecInfos) {
                if (!info.isEncoder) continue
                if (!info.supportedTypes.any { it.equals(mime, true) }) continue
                any = true
                val hw = if (Build.VERSION.SDK_INT >= 29) (if (info.isHardwareAccelerated) "HW" else "SW") else "?"
                sb.append("• ${info.name} [$hw]\n")
                try {
                    val caps = info.getCapabilitiesForType(mime)
                    sb.append("   colors: ${caps.colorFormats.joinToString(",") { colorName(it) }}\n")
                    val vc = caps.videoCapabilities
                    if (vc != null) {
                        sb.append("   W ${vc.supportedWidths} x H ${vc.supportedHeights}, align w=${vc.widthAlignment} h=${vc.heightAlignment}\n")
                        sb.append("   fps ${vc.supportedFrameRates}, bitrate ${vc.bitrateRange}\n")
                        for ((w, h) in listOf(320 to 240, 256 to 144, 640 to 360)) {
                            val ok = try { vc.isSizeSupported(w, h) } catch (_: Throwable) { false }
                            sb.append("   ${w}x${h}: ${if (ok) "OK" else "NOT-SUPPORTED"}\n")
                        }
                    }
                    val profiles = caps.profileLevels.joinToString(",") { "p${it.profile}/l${it.level}" }
                    if (profiles.isNotEmpty()) sb.append("   profiles: $profiles\n")
                } catch (e: Throwable) {
                    sb.append("   caps error: ${e.message}\n")
                }
            }
            if (!any) sb.append("(none)\n")
        }
        return sb.toString()
    }
}
