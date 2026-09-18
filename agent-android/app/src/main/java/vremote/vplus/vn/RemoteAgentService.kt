package vremote.vplus.vn

import android.app.*
import android.content.*
import android.content.pm.ServiceInfo
import android.graphics.PixelFormat
import android.hardware.display.DisplayManager
import android.hardware.display.VirtualDisplay
import android.media.Image
import android.media.ImageReader
import android.media.projection.MediaProjection
import android.media.projection.MediaProjectionManager
import android.os.*
import android.util.DisplayMetrics
import android.view.WindowManager
import org.json.JSONArray
import org.json.JSONObject
import org.webrtc.*
import okhttp3.*
import java.nio.ByteBuffer
import java.nio.charset.StandardCharsets
import java.util.concurrent.ConcurrentHashMap
import java.util.concurrent.TimeUnit
import kotlin.math.abs

/**
 * Always-reachable agent.
 *
 * Screen capture is intentionally independent from the WebRTC video encoder. On RK3566 devices
 * the native encoder path has repeatedly crashed; the default MJPEG-over-DataChannel transport
 * therefore keeps the working path entirely outside MediaCodec/libvpx/OMX. VP8 remains available
 * as an explicit fallback when an encoder is known to work.
 */
class RemoteAgentService : Service() {
    private lateinit var socket: WebSocket
    private lateinit var factory: PeerConnectionFactory
    private lateinit var egl: EglBase

    private var peer: PeerConnection? = null
    private var source: VideoSource? = null
    private var sessionTrack: VideoTrack? = null
    private var mediaProjection: MediaProjection? = null
    private var imageReader: ImageReader? = null
    private var virtualDisplay: VirtualDisplay? = null
    private var captureThread: HandlerThread? = null
    private var captureHandler: Handler? = null
    private var jpegBmp: android.graphics.Bitmap? = null
    private var jpegBmpW = 0
    private var jpegBmpH = 0
    private var lastFrameMs = 0L
    @Volatile private var frame1Logged = false
    @Volatile private var capturing = false

    private var activeSession: String? = null
    private var activeCapability: String? = null
    private var activeIceServers: List<PeerConnection.IceServer> = emptyList()
    private var inputChannel: DataChannel? = null
    private var screenW = 0
    private var screenH = 0
    private var projectionIntent: Intent? = null
    private var expiryTask: Runnable? = null
    @Volatile private var destroyed = false
    private var polling = false
    private var shownRequest: String? = null
    private val notifId = 2
    private val consentNotifId = 3

    private val seenNonces = ConcurrentHashMap.newKeySet<String>()
    private val processedCommands = ConcurrentHashMap.newKeySet<String>()
    private val handler = Handler(Looper.getMainLooper())

    private var videoMode = "mjpeg"
    private var capW = 0
    private var capH = 0
    private var capFps = 10
    private var capMaxEdge = 640

    private fun cfg() = "[${videoMode} ${capW}x${capH} q$capMaxEdge]"
    private fun align16(v: Int) = (((v + 8) / 16) * 16).coerceAtLeast(16)

    private fun computeCaptureSize() {
        cacheScreenSize()
        var w = screenW
        var h = screenH
        if (w <= 0 || h <= 0) { w = 1280; h = 720 }
        val longEdge = maxOf(w, h)
        val maxE = capMaxEdge.coerceAtLeast(160)
        if (longEdge > maxE) {
            val scale = maxE.toDouble() / longEdge
            w = (w * scale).toInt()
            h = (h * scale).toInt()
        }
        capW = align16(w)
        capH = align16(h)
    }

    override fun onCreate() {
        super.onCreate()
        run {
            val pp = getSharedPreferences("vremote", MODE_PRIVATE)
            val last = pp.getString("crumb", "") ?: ""
            if (last.isNotEmpty()) pp.edit().putString("prevCrumb", last).commit()
        }
        getSystemService(NotificationManager::class.java).createNotificationChannel(
            NotificationChannel(CHANNEL, "VRemote", NotificationManager.IMPORTANCE_LOW)
        )
        startFgSignaling()
        PeerConnectionFactory.initialize(
            PeerConnectionFactory.InitializationOptions.builder(this).createInitializationOptions()
        )
        val p = getSharedPreferences("vremote", MODE_PRIVATE)
        capFps = p.getInt("capFps", 10).coerceIn(1, 30)
        capMaxEdge = p.getInt("capMaxEdge", 640).coerceAtLeast(160)
        videoMode = p.getString("videoMode", "mjpeg") ?: "mjpeg"
        if (videoMode != "mjpeg" && videoMode != "vp8") videoMode = "mjpeg"

        // RK3566 safety: MJPEG does not need WebRTC codec factories. The previous build still
        // constructed SoftwareVideoEncoderFactory() here and could crash before MJPEG ran.
        videoMode = "mjpeg"
        boot("factory build ${cfg()} no-encoder")
        factory = PeerConnectionFactory.builder()
            .createPeerConnectionFactory()
        boot("factory ok no-encoder")
    }

    override fun onStartCommand(intent: Intent?, flags: Int, startId: Int): Int {
        if (intent?.action == ACTION_ACCEPT || intent?.action == ACTION_REJECT) {
            respondPending(intent.getStringExtra(EXTRA_REQUEST_ID) ?: "", intent.action == ACTION_ACCEPT)
            return START_STICKY
        }
        if (intent?.action == ACTION_STOP) {
            stopProjection()
            return START_STICKY
        }
        if (intent != null && intent.hasExtra(EXTRA_DATA)) {
            projectionIntent = intent.getParcelableExtraCompat(EXTRA_DATA)
            startProjection()
        }
        ensureConnected()
        if (!polling) {
            polling = true
            startPendingPolling()
        }
        return START_STICKY
    }

    private fun buildNotif(text: String) = Notification.Builder(this, CHANNEL)
        .setContentTitle("VRemote Agent")
        .setContentText(text)
        .setSmallIcon(android.R.drawable.ic_menu_view)
        .build()

    private fun startFgSignaling() {
        val n = buildNotif("Sẵn sàng nhận kết nối (chưa bật chia sẻ màn hình)")
        if (Build.VERSION.SDK_INT >= 34) startForeground(1, n, ServiceInfo.FOREGROUND_SERVICE_TYPE_SPECIAL_USE)
        else startForeground(1, n)
    }

    private fun startFgProjection() {
        val n = buildNotif("Sẵn sàng điều khiển / chia sẻ màn hình")
        if (Build.VERSION.SDK_INT >= 29) startForeground(1, n, ServiceInfo.FOREGROUND_SERVICE_TYPE_MEDIA_PROJECTION)
        else startForeground(1, n)
    }

    private fun ensureConnected() {
        if (::socket.isInitialized) return
        val p = getSharedPreferences("vremote", MODE_PRIVATE)
        val id = p.getString("deviceId", "") ?: ""
        val tok = p.getString("deviceToken", "") ?: ""
        if (id.isEmpty() || tok.isEmpty()) return
        connect()
    }

    private fun startPendingPolling() {
        handler.postDelayed(object : Runnable {
            override fun run() {
                if (destroyed) return
                pollPendingOnce()
                handler.postDelayed(this, 2000)
            }
        }, 2000)
    }

    private fun pollPendingOnce() {
        val p = getSharedPreferences("vremote", MODE_PRIVATE)
        val base = p.getString("baseUrl", "https://vremote.vplus.vn") ?: ""
        val id = p.getString("deviceId", "") ?: ""
        val tok = p.getString("deviceToken", "") ?: ""
        if (id.isEmpty() || tok.isEmpty()) return
        Thread {
            try {
                val pend = ApiClient.pending(base, id, tok)
                handler.post {
                    if (pend == null) {
                        if (shownRequest != null) {
                            shownRequest = null
                            getSystemService(NotificationManager::class.java).cancel(notifId)
                        }
                    } else if (pend.requestId != shownRequest) {
                        shownRequest = pend.requestId
                        showAcceptNotification(pend)
                    }
                }
            } catch (_: Throwable) { }
        }.start()
    }

    private fun showAcceptNotification(p: ApiClient.Pending) {
        val f = PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE
        val yes = PendingIntent.getService(
            this, 1,
            Intent(this, RemoteAgentService::class.java).setAction(ACTION_ACCEPT).putExtra(EXTRA_REQUEST_ID, p.requestId), f
        )
        val no = PendingIntent.getService(
            this, 2,
            Intent(this, RemoteAgentService::class.java).setAction(ACTION_REJECT).putExtra(EXTRA_REQUEST_ID, p.requestId), f
        )
        val n = Notification.Builder(this, CHANNEL)
            .setContentTitle("Yêu cầu kết nối")
            .setContentText("“${p.actor}” muốn xem màn hình.")
            .setSmallIcon(android.R.drawable.ic_menu_view)
            .addAction(android.R.drawable.ic_menu_view, "Đồng ý", yes)
            .addAction(android.R.drawable.ic_menu_close_clear_cancel, "Từ chối", no)
            .setAutoCancel(true)
            .build()
        getSystemService(NotificationManager::class.java).notify(notifId, n)
    }

    private fun respondPending(rid: String, accept: Boolean) {
        shownRequest = null
        getSystemService(NotificationManager::class.java).cancel(notifId)
        if (rid.isEmpty()) return
        val p = getSharedPreferences("vremote", MODE_PRIVATE)
        val base = p.getString("baseUrl", "https://vremote.vplus.vn") ?: ""
        val id = p.getString("deviceId", "") ?: ""
        val tok = p.getString("deviceToken", "") ?: ""
        Thread {
            try { ApiClient.respond(base, id, tok, rid, accept) } catch (_: Throwable) { }
        }.start()
    }

    private fun connect() {
        val p = getSharedPreferences("vremote", MODE_PRIVATE)
        val id = p.getString("deviceId", "") ?: ""
        val token = p.getString("deviceToken", "") ?: ""
        val url = p.getString("signalingUrl", "ws://10.0.2.2:8787/agent") ?: ""
        socket = OkHttpClient.Builder().pingInterval(20, TimeUnit.SECONDS).build().newWebSocket(
            Request.Builder().url(url).build(),
            object : WebSocketListener() {
                override fun onOpen(w: WebSocket, r: Response) {
                    w.send(JSONObject().put("type", "agent.auth").put("deviceId", id).put("deviceToken", token).toString())
                    w.send(JSONObject().put("type", "agent.hello").put("deviceId", id).put("agentVersion", BuildConfig.VERSION_NAME).put("platform", "android").put("capabilities", listOf("screen.view", "screen.control")).toString())
                }
                override fun onMessage(w: WebSocket, text: String) {
                    val m = try { JSONObject(text) } catch (_: Throwable) { return }
                    handler.post { if (!destroyed) try { handle(m) } catch (_: Throwable) {} }
                }
                override fun onFailure(w: WebSocket, t: Throwable, r: Response?) {
                    handler.postDelayed({ if (!destroyed) try { socket = w; connect() } catch (_: Throwable) {} }, 5000)
                }
            }
        )
    }

    private fun crumb(s: String) {
        try { getSharedPreferences("vremote", MODE_PRIVATE).edit().putString("crumb", s + " @" + (System.currentTimeMillis() / 1000 % 100000)).commit() } catch (_: Throwable) { }
    }
    private fun boot(s: String) {
        try { getSharedPreferences("vremote", MODE_PRIVATE).edit().putString("boot", s + " @" + (System.currentTimeMillis() / 1000 % 100000)).commit() } catch (_: Throwable) { }
    }
    private fun flow(s: String) {
        try { getSharedPreferences("vremote", MODE_PRIVATE).edit().putString("flow", s + " @" + (System.currentTimeMillis() / 1000 % 100000)).commit() } catch (_: Throwable) { }
    }

    private fun handle(m: JSONObject) {
        when (m.optString("type")) {
            "session.enable" -> enable(m)
            "session.disable" -> disable(m)
            "command" -> command(m)
            "agent.signaling" -> {
                val pl = m.optJSONObject("payload") ?: return
                when (pl.optString("type")) {
                    "offer" -> handleOffer(pl)
                    "candidate" -> handleCandidate(pl)
                    "leave" -> endSession()
                }
            }
            "offer" -> handleOffer(m)
            "candidate" -> handleCandidate(m)
        }
    }

    private fun enable(m: JSONObject) {
        val p = getSharedPreferences("vremote", MODE_PRIVATE)
        val id = p.getString("deviceId", "") ?: ""
        val secret = p.getString("deviceToken", "") ?: ""
        val sid = m.optString("sessionId")
        val nonce = m.optString("nonce")
        val ts = m.optLong("ts", 0)
        val ttl = m.optInt("ttlSec", 0)
        val cap = m.optString("capability")
        val sig = m.optString("sig")
        if (m.optString("deviceId") != id || sid.isBlank() || nonce.isBlank() || sig.isBlank() || ttl !in 1..3600 || abs(System.currentTimeMillis() / 1000 - ts) > 300) return
        if (!VRemoteSigner.verify(secret, VRemoteSigner.canonicalSessionEnable(id, sid, cap, ttl, nonce, ts), sig)) return
        if (!seenNonces.add("session:$nonce")) return
        endSession()
        p.edit().putInt("lastSessionTtl", ttl).putLong("lastSessionExpires", ts + ttl).apply()
        activeSession = sid
        activeCapability = cap
        activeIceServers = parseIceServers(m.optJSONArray("iceServers"))
        cacheScreenSize()
        flow("enable cap=$cap source=${source != null} mode=$videoMode")
        expiryTask = Runnable { if (activeSession == sid) endSession() }
        handler.postDelayed(expiryTask!!, ttl * 1000L)
        // MJPEG uses ImageReader -> JPEG -> DataChannel and must not require a WebRTC VideoSource.
        // Only the explicit VP8 fallback needs the WebRTC source created by startProjection().
        if (videoMode == "vp8" && source == null) {
            notifyConsentNeeded()
            return
        }
        if (!newSessionPeer()) return
        crumb("peer ready, ready sent")
        sendSignal(JSONObject().put("type", "agent.signaling").put("sessionId", sid).put("payload", JSONObject().put("type", "ready").put("sessionId", sid)))
    }

    private fun startProjection(): Boolean {
        if (source != null) return true
        val data = projectionIntent ?: return false
        frame1Logged = false
        // MJPEG is deliberately independent of WebRTC media sources. Creating a VideoSource
        // touches the native WebRTC media pipeline even though MJPEG never adds a VideoTrack.
        // On RK3566 this is an unnecessary native-crash surface. Create it only for VP8 fallback.
        val src = if (videoMode == "vp8") {
            crumb("proj:createSource vp8")
            factory.createVideoSource(true).also { source = it }
        } else {
            crumb("proj:createSource skipped mjpeg")
            null
        }
        startFgProjection()
        crumb("proj:startCapture")
        computeCaptureSize()
        if (capW <= 0 || capH <= 0) {
            crumb("proj:invalid-size ${capW}x${capH}")
            onProjectionRevoked()
            return false
        }
        try {
            val mpm = getSystemService(MediaProjectionManager::class.java)
            val mp = mpm.getMediaProjection(Activity.RESULT_OK, data)
            mediaProjection = mp
            mp.registerCallback(object : MediaProjection.Callback() {
                override fun onStop() { handler.post { onProjectionRevoked() } }
            }, handler)
            val ir = ImageReader.newInstance(capW, capH, PixelFormat.RGBA_8888, 3)
            imageReader = ir
            val obs = src?.capturerObserver
            obs?.onCapturerStarted(true)
            val ct = HandlerThread("VRemoteCapture").apply { start() }
            captureThread = ct
            val ch2 = Handler(ct.looper)
            captureHandler = ch2
            ir.setOnImageAvailableListener({ reader ->
                val img = try { reader.acquireLatestImage() } catch (_: Throwable) { null } ?: return@setOnImageAvailableListener
                try {
                    val now = SystemClock.uptimeMillis()
                    val gap = 1000L / capFps.coerceIn(1, 30)
                    if (capturing && now - lastFrameMs >= gap) {
                        lastFrameMs = now
                        if (videoMode == "mjpeg") sendJpeg(img) else if (obs != null) pushI420(img, obs)
                    }
                } catch (_: Throwable) {
                } finally {
                    try { img.close() } catch (_: Throwable) { }
                }
            }, ch2)
            val dpi = resources.displayMetrics.densityDpi.coerceIn(120, 640)
            virtualDisplay = mp.createVirtualDisplay(
                "VRemote", capW, capH, dpi, DisplayManager.VIRTUAL_DISPLAY_FLAG_AUTO_MIRROR, ir.surface, null, ch2
            )
        } catch (t: Throwable) {
            crumb("proj:img FAIL ${t.message}")
            onProjectionRevoked()
            return false
        }
        capturing = true
        crumb("proj:capturing ${cfg()}")
        projectionHeld = true
        getSystemService(NotificationManager::class.java).cancel(consentNotifId)
        return true
    }

    private fun pushI420(img: Image, obs: CapturerObserver) {
        if (!capturing) return
        val w = capW
        val h = capH
        if (w <= 0 || h <= 0) return
        val plane = img.planes.firstOrNull() ?: return
        val rowStride = plane.rowStride
        val pxStride = plane.pixelStride
        val buf = plane.buffer
        val n = buf.remaining()
        val rgba = ByteArray(n)
        buf.get(rgba, 0, n)
        val cw = (w + 1) / 2
        val ch = (h + 1) / 2
        val yA = ByteArray(w * h)
        val uA = ByteArray(cw * ch)
        val vA = ByteArray(cw * ch)
        var y = 0
        while (y < h) {
            val rowBase = y * rowStride
            val yBase = y * w
            val cBase = (y / 2) * cw
            val chromaRow = (y and 1) == 0
            var x = 0
            while (x < w) {
                val i = rowBase + x * pxStride
                if (i + 2 >= rgba.size) break
                val r = rgba[i].toInt() and 0xFF
                val g = rgba[i + 1].toInt() and 0xFF
                val b = rgba[i + 2].toInt() and 0xFF
                var yy = ((66 * r + 129 * g + 25 * b + 128) shr 8) + 16
                if (yy < 16) yy = 16 else if (yy > 235) yy = 235
                yA[yBase + x] = yy.toByte()
                if (chromaRow && (x and 1) == 0) {
                    val ci = cBase + (x / 2)
                    var u = ((-38 * r - 74 * g + 112 * b + 128) shr 8) + 128
                    if (u < 16) u = 16 else if (u > 240) u = 240
                    uA[ci] = u.toByte()
                    var v = ((112 * r - 94 * g - 18 * b + 128) shr 8) + 128
                    if (v < 16) v = 16 else if (v > 240) v = 240
                    vA[ci] = v.toByte()
                }
                x++
            }
            y++
        }
        val i420 = JavaI420Buffer.allocate(w, h)
        fillPlane(i420.dataY, i420.strideY, yA, w, h)
        fillPlane(i420.dataU, i420.strideU, uA, cw, ch)
        fillPlane(i420.dataV, i420.strideV, vA, cw, ch)
        if (!frame1Logged) crumb("push:frame1 ${w}x$h")
        val frame = VideoFrame(i420, 0, System.nanoTime())
        try { obs.onFrameCaptured(frame) } finally { frame.release() }
        if (!frame1Logged) { frame1Logged = true; crumb("push:frame1 ok") }
    }

    private fun fillPlane(dst: ByteBuffer, stride: Int, src: ByteArray, w: Int, h: Int) {
        if (stride == w) {
            dst.position(0)
            dst.put(src, 0, w * h)
        } else {
            var row = 0
            while (row < h) {
                dst.position(row * stride)
                dst.put(src, row * w, w)
                row++
            }
        }
    }

    private fun sendJpeg(img: Image) {
        val dc = inputChannel ?: return
        if (dc.state() != DataChannel.State.OPEN) return
        if (dc.bufferedAmount() > 256 * 1024L) return
        val w = capW
        val h = capH
        if (w <= 0 || h <= 0) return
        val plane = img.planes.firstOrNull() ?: return
        val rowStride = plane.rowStride
        val buf = plane.buffer
        val bmpW = rowStride / 4
        if (bmpW < w) return
        var bmp = jpegBmp
        if (bmp == null || jpegBmpW != bmpW || jpegBmpH != h) {
            try { bmp?.recycle() } catch (_: Throwable) { }
            bmp = try { android.graphics.Bitmap.createBitmap(bmpW, h, android.graphics.Bitmap.Config.ARGB_8888) } catch (_: Throwable) { null }
            jpegBmp = bmp
            jpegBmpW = bmpW
            jpegBmpH = h
        }
        if (bmp == null) return
        try {
            buf.rewind()
            bmp.copyPixelsFromBuffer(buf)
            val baos = java.io.ByteArrayOutputStream(48 * 1024)
            val src = if (bmpW != w) android.graphics.Bitmap.createBitmap(bmp, 0, 0, w, h) else bmp
            try {
                if (!src.compress(android.graphics.Bitmap.CompressFormat.JPEG, 40, baos)) return
            } finally {
                if (src !== bmp) try { src.recycle() } catch (_: Throwable) { }
            }
            val bytes = baos.toByteArray()
            try {
                dc.send(DataChannel.Buffer(ByteBuffer.wrap(bytes), true))
                if (!frame1Logged) {
                    frame1Logged = true
                    crumb("jpeg:frame1 ${w}x${h} ${bytes.size}b sent")
                }
            } catch (_: Throwable) { }
        } catch (_: Throwable) {
            crumb("jpeg:frame FAIL ${w}x${h}")
        }
    }

    private fun parseIceServers(arr: JSONArray?): List<PeerConnection.IceServer> {
        val out = mutableListOf<PeerConnection.IceServer>()
        if (arr != null) for (i in 0 until arr.length()) {
            val o = arr.optJSONObject(i) ?: continue
            val urls = mutableListOf<String>()
            when (val u = o.opt("urls")) {
                is String -> if (u.isNotBlank()) urls.add(u)
                is JSONArray -> for (j in 0 until u.length()) u.optString(j).takeIf { it.isNotBlank() }?.let(urls::add)
            }
            if (urls.isEmpty()) continue
            val builder = PeerConnection.IceServer.builder(urls)
            val user = o.optString("username", "")
            val cred = o.optString("credential", "")
            if (user.isNotEmpty() && cred.isNotEmpty()) {
                builder.setUsername(user)
                builder.setPassword(cred)
            }
            try { out.add(builder.createIceServer()) } catch (_: Throwable) { }
        }
        return out
    }

    private fun newSessionPeer(): Boolean {
        val src = source
        if (videoMode == "vp8" && src == null) return false
        val servers = if (activeIceServers.isNotEmpty()) activeIceServers
        else listOf(PeerConnection.IceServer.builder("stun:stun.l.google.com:19302").createIceServer())
        crumb("peer:createPC ice=${servers.size}")
        val pc = factory.createPeerConnection(servers, observer()) ?: return false
        peer = pc
        // The default MJPEG mode intentionally adds NO WebRTC video track. This prevents the encoder
        // factory from being touched at all, which is the required RK3566 safety property.
        if (videoMode == "vp8") {
            crumb("peer:createTrack")
            val track = factory.createVideoTrack("screen" + SystemClock.uptimeMillis(), src)
            sessionTrack = track
            crumb("peer:addTrack")
            pc.addTrack(track, listOf("screen"))
            crumb("peer:addTrack ok")
        } else {
            crumb("peer:mjpeg no-track")
        }
        return true
    }

    private fun observer() = object : PeerConnection.Observer {
        override fun onIceCandidate(c: IceCandidate) {
            sendSignal(
                JSONObject().put("type", "agent.signaling").put("sessionId", activeSession).put(
                    "payload", JSONObject().put("type", "candidate").put("sessionId", activeSession).put(
                        "candidate", JSONObject().put("sdpMid", c.sdpMid).put("sdpMLineIndex", c.sdpMLineIndex).put("candidate", c.sdp)
                    )
                )
            )
        }
        override fun onSignalingChange(s: PeerConnection.SignalingState) {}
        override fun onIceConnectionChange(s: PeerConnection.IceConnectionState) { crumb("ice:$s") }
        override fun onIceConnectionReceivingChange(b: Boolean) {}
        override fun onIceGatheringChange(s: PeerConnection.IceGatheringState) {}
        override fun onIceCandidatesRemoved(c: Array<IceCandidate>) {}
        override fun onAddStream(s: MediaStream) {}
        override fun onRemoveStream(s: MediaStream) {}
        override fun onDataChannel(d: DataChannel) {
            inputChannel = d
            d.registerObserver(object : DataChannel.Observer {
                override fun onBufferedAmountChange(p: Long) {}
                override fun onStateChange() {
                    if (d.state() == DataChannel.State.OPEN) sendControlStatus(d)
                }
                override fun onMessage(buf: DataChannel.Buffer) {
                    try {
                        val b = buf.data
                        val bytes = ByteArray(b.remaining())
                        b.get(bytes)
                        if (!buf.binary) onInput(String(bytes, StandardCharsets.UTF_8))
                    } catch (_: Throwable) { }
                }
            })
        }
        override fun onRenegotiationNeeded() {}
        override fun onAddTrack(r: RtpReceiver, m: Array<MediaStream>) {}
        override fun onConnectionChange(s: PeerConnection.PeerConnectionState) {
            crumb("conn:$s")
            if (s == PeerConnection.PeerConnectionState.FAILED || s == PeerConnection.PeerConnectionState.CLOSED) {
                handler.post { endSession() }
            }
        }
        override fun onSelectedCandidatePairChanged(e: CandidatePairChangeEvent) {}
    }

    private fun cacheScreenSize() {
        try {
            val wm = getSystemService(WindowManager::class.java)
            if (Build.VERSION.SDK_INT >= 30) {
                val b = wm.currentWindowMetrics.bounds
                screenW = b.width()
                screenH = b.height()
            } else {
                val dm = DisplayMetrics()
                @Suppress("DEPRECATION") wm.defaultDisplay.getRealMetrics(dm)
                screenW = dm.widthPixels
                screenH = dm.heightPixels
            }
        } catch (_: Throwable) { }
    }

    private fun onInput(text: String) {
        if (activeSession == null || activeCapability != "control") return
        val svc = VRemoteInputService.instance ?: return
        if (screenW <= 0 || screenH <= 0) cacheScreenSize()
        if (screenW <= 0 || screenH <= 0) return
        val m = try { JSONObject(text) } catch (_: Throwable) { return }
        val w = screenW
        val h = screenH
        fun px(v: Double, max: Int) = (v.coerceIn(0.0, 1.0) * max).toInt()
        when (m.optString("t")) {
            "tap" -> { val x = px(m.optDouble("x", 0.0), w); val y = px(m.optDouble("y", 0.0), h); handler.post { svc.tap(x, y) } }
            "long" -> { val x = px(m.optDouble("x", 0.0), w); val y = px(m.optDouble("y", 0.0), h); handler.post { svc.longPress(x, y) } }
            "swipe" -> { val x1 = px(m.optDouble("x1", 0.0), w); val y1 = px(m.optDouble("y1", 0.0), h); val x2 = px(m.optDouble("x2", 0.0), w); val y2 = px(m.optDouble("y2", 0.0), h); val ms = m.optLong("ms", 200).coerceIn(1, 60000); handler.post { svc.swipe(x1, y1, x2, y2, ms) } }
            "key" -> { val k = m.optString("k"); if (k == "del") handler.post { svc.delChar() } else handler.post { svc.global(k) } }
            "text" -> { val s = m.optString("s"); handler.post { svc.typeText(s) } }
        }
    }

    private fun handleCandidate(m: JSONObject) {
        val c = m.optJSONObject("candidate") ?: return
        peer?.addIceCandidate(IceCandidate(c.optString("sdpMid"), c.optInt("sdpMLineIndex"), c.optString("candidate")))
    }

    private fun handleOffer(m: JSONObject) {
        val sdp = m.optString("sdp")
        if (sdp.isBlank()) return
        if (peer == null) {
            if (activeSession == null || !newSessionPeer()) return
        }
        // In MJPEG mode the SDP should not negotiate a video m-line because the agent does not
        // create a video track. The browser still creates a data channel for the JPEG frames/control.
        val pc = peer ?: return
        crumb("offer:setRemote")
        pc.setRemoteDescription(object : SimpleSdpObserver() {
            override fun onSetSuccess() {
                crumb("offer:remote ok")
                pc.createAnswer(object : SimpleSdpObserver() {
                    override fun onCreateSuccess(d: SessionDescription) {
                        if (videoMode == "mjpeg") {
                            val noVideo = removeVideoMLine(d.description)
                            crumb("offer:answer [mjpeg,no-video]")
                            val fixed = SessionDescription(d.type, noVideo)
                            pc.setLocalDescription(object : SimpleSdpObserver() {
                                override fun onSetSuccess() { crumb("offer:local ok ${cfg()}") }
                                override fun onSetFailure(e: String) { crumb("offer:local FAIL ${cfg()} $e") }
                            }, fixed)
                            sendSignal(
                                JSONObject().put("type", "agent.signaling").put("sessionId", activeSession).put(
                                    "payload", JSONObject().put("type", "answer").put("sessionId", activeSession).put("sdp", fixed.description)
                                )
                            )
                        } else {
                            val fixed = SessionDescription(d.type, preferVp8(d.description))
                            crumb("offer:answer [vp8]")
                            pc.setLocalDescription(object : SimpleSdpObserver() {
                                override fun onSetSuccess() { crumb("offer:local ok ${cfg()}") }
                                override fun onSetFailure(e: String) { crumb("offer:local FAIL ${cfg()} $e") }
                            }, fixed)
                            sendSignal(
                                JSONObject().put("type", "agent.signaling").put("sessionId", activeSession).put(
                                    "payload", JSONObject().put("type", "answer").put("sessionId", activeSession).put("sdp", fixed.description)
                                )
                            )
                        }
                    }
                    override fun onCreateFailure(e: String) { crumb("offer:answer FAIL $e") }
                }, MediaConstraints())
            }
            override fun onSetFailure(e: String) { crumb("offer:remote FAIL $e") }
        }, SessionDescription(SessionDescription.Type.OFFER, sdp))
    }

    private fun removeVideoMLine(sdp: String): String {
        return try {
            val lines = sdp.split("\r\n")
            val out = ArrayList<String>(lines.size)
            var inVideo = false
            for (line in lines) {
                if (line.startsWith("m=")) inVideo = line.startsWith("m=video")
                if (inVideo) continue
                out.add(line)
            }
            out.joinToString("\r\n")
        } catch (_: Throwable) { sdp }
    }

    private fun preferVp8(sdp: String): String {
        return try {
            val lines = sdp.split("\r\n")
            val vp8 = HashSet<String>()
            val apt = HashMap<String, String>()
            for (l in lines) {
                Regex("^a=rtpmap:(\\d+) VP8/", RegexOption.IGNORE_CASE).find(l)?.let { vp8.add(it.groupValues[1]) }
                Regex("^a=fmtp:(\\d+) apt=(\\d+)").find(l)?.let { apt[it.groupValues[1]] = it.groupValues[2] }
            }
            if (vp8.isEmpty()) return sdp
            val keep = HashSet(vp8)
            for ((rtx, a) in apt) if (a in vp8) keep.add(rtx)
            val out = ArrayList<String>(lines.size)
            var inV = false
            for (l in lines) {
                if (l.startsWith("m=")) {
                    inV = l.startsWith("m=video")
                    if (inV) {
                        val p = l.split(" ")
                        if (p.size > 3) {
                            out.add((p.take(3) + p.drop(3).filter { it in keep }).joinToString(" "))
                            continue
                        }
                    }
                }
                if (inV) {
                    val pm = Regex("^a=(rtpmap|rtcp-fb|fmtp):(\\d+)").find(l)
                    if (pm != null && pm.groupValues[2] !in keep) continue
                }
                out.add(l)
            }
            out.joinToString("\r\n")
        } catch (_: Throwable) { sdp }
    }

    private fun command(m: JSONObject) {
        val p = getSharedPreferences("vremote", MODE_PRIVATE)
        val id = p.getString("deviceId", "") ?: ""
        val secret = p.getString("deviceToken", "") ?: ""
        val cid = m.optString("commandId")
        val type = m.optString("cmdType")
        val nonce = m.optString("nonce")
        val ts = m.optLong("ts", 0)
        val sig = m.optString("sig")
        if (m.optString("deviceId") != id || cid.isBlank() || nonce.isBlank() || sig.isBlank() || abs(System.currentTimeMillis() / 1000 - ts) > 300) {
            commandResult(cid, "rejected", "stale_or_invalid"); return
        }
        val argsJson = m.optJSONObject("args")?.toString()
        if (!VRemoteSigner.verify(secret, VRemoteSigner.canonicalCommand(id, cid, type, VRemoteSigner.argsHash(argsJson), nonce, ts), sig)) {
            commandResult(cid, "invalid_signature", null); return
        }
        if (!seenNonces.add("cmd:$nonce")) { commandResult(cid, "replay_detected", null); return }
        if (!processedCommands.add(cid)) { commandResult(cid, "done", null); return }
        val gated = setOf("reboot", "config-push", "apk-update", "cache-clear", "remote.enable", "remote.disable")
        if (type in gated && !p.getBoolean("allowGatedCommands", false)) { commandResult(cid, "gated_off", null); return }
        when (type) {
            "health", "ping" -> commandResult(cid, "done", mapOf("online" to true, "activeSession" to activeSession))
            "version" -> commandResult(cid, "done", mapOf("agentVersion" to BuildConfig.VERSION_NAME, "versionCode" to BuildConfig.VERSION_CODE))
            "logs", "config-read" -> commandResult(cid, "done", emptyMap<String, String>())
            "apk-update" -> {
                val base = p.getString("baseUrl", "https://vremote.vplus.vn") ?: ""
                Thread {
                    try {
                        val info = ApiClient.latest(base)
                        when {
                            info.versionCode <= BuildConfig.VERSION_CODE -> commandResult(cid, "done", mapOf("updated" to false, "installed" to BuildConfig.VERSION_NAME))
                            !AgentUpdater.canInstall(this) -> commandResult(cid, "error", "install_permission_required")
                            else -> { AgentUpdater.install(this, AgentUpdater.download(this, base, info)); commandResult(cid, "done", mapOf("updated" to true, "downloaded" to info.versionName)) }
                        }
                    } catch (e: Throwable) { commandResult(cid, "error", e.message ?: "update_failed") }
                }.start()
            }
            else -> commandResult(cid, "error", "unsupported_command")
        }
    }

    private fun commandResult(id: String, result: String, data: Any?) {
        if (id.isBlank()) return
        val d = JSONObject()
        when (data) {
            is Map<*, *> -> data.forEach { (k, v) -> d.put(k.toString(), v) }
            is String -> d.put("detail", data)
        }
        sendSignal(
            JSONObject().put("type", "command.result")
                .put("deviceId", getSharedPreferences("vremote", MODE_PRIVATE).getString("deviceId", ""))
                .put("commandId", id).put("result", result)
                .put("data", if (d.length() > 0) d else JSONObject.NULL)
        )
    }

    private fun notifyConsentNeeded() {
        getSystemService(NotificationManager::class.java).notify(
            consentNotifId,
            Notification.Builder(this, CHANNEL)
                .setContentTitle("Cần bật chia sẻ màn hình")
                .setContentText("Mở app VRemote và bấm “Bật sẵn sàng điều khiển” để cho phép một lần.")
                .setSmallIcon(android.R.drawable.ic_menu_view).setAutoCancel(true).build()
        )
    }

    private fun sendControlStatus(d: DataChannel) {
        try {
            val js = JSONObject().put("t", "agent.status").put("control", VRemoteInputService.isEnabled())
            d.send(DataChannel.Buffer(ByteBuffer.wrap(js.toString().toByteArray(StandardCharsets.UTF_8)), false))
        } catch (_: Throwable) { }
    }

    private fun sendSignal(p: JSONObject) { if (::socket.isInitialized) socket.send(p.toString()) }
    private fun disable(m: JSONObject) { if (m.optString("sessionId") == activeSession) endSession() }

    private fun endSession() {
        expiryTask?.let { handler.removeCallbacks(it) }
        expiryTask = null
        try { inputChannel?.dispose() } catch (_: Throwable) { }
        inputChannel = null
        peer?.close(); peer = null
        sessionTrack?.dispose(); sessionTrack = null
        activeSession = null
        activeCapability = null
    }

    private fun releaseCapture() {
        capturing = false
        try { imageReader?.setOnImageAvailableListener(null, null) } catch (_: Throwable) { }
        try { virtualDisplay?.release() } catch (_: Throwable) { }
        virtualDisplay = null
        try { imageReader?.close() } catch (_: Throwable) { }
        imageReader = null
        try { mediaProjection?.stop() } catch (_: Throwable) { }
        mediaProjection = null
        val ct = captureThread
        captureThread = null
        captureHandler = null
        if (ct != null) try { ct.quitSafely(); ct.join(1000) } catch (_: Throwable) { }
        try { source?.dispose() } catch (_: Throwable) { }
        source = null
        try { jpegBmp?.recycle() } catch (_: Throwable) { }
        jpegBmp = null
        jpegBmpW = 0
        jpegBmpH = 0
    }

    private fun stopProjection() {
        endSession(); releaseCapture(); projectionIntent = null; projectionHeld = false; startFgSignaling()
    }

    private fun onProjectionRevoked() {
        endSession(); releaseCapture(); projectionIntent = null; projectionHeld = false; startFgSignaling(); notifyConsentNeeded()
    }

    override fun onBind(i: Intent?): IBinder? = null

    override fun onDestroy() {
        destroyed = true
        endSession()
        releaseCapture()
        projectionHeld = false
        try { if (::socket.isInitialized) socket.close(1000, "shutdown") } catch (_: Throwable) { }
        factory.dispose()
        egl.release()
        super.onDestroy()
    }

    companion object {
        const val EXTRA_RESULT_CODE = "resultCode"
        const val EXTRA_DATA = "projectionData"
        private const val CHANNEL = "vremote-agent"
        const val ACTION_ACCEPT = "vn.vplus.vremote.ACCEPT"
        const val ACTION_REJECT = "vn.vplus.vremote.REJECT"
        const val ACTION_STOP = "vn.vplus.vremote.STOP"
        const val EXTRA_REQUEST_ID = "requestId"
        @Volatile var projectionHeld = false
    }
}

private open class SimpleSdpObserver : SdpObserver {
    override fun onCreateSuccess(s: SessionDescription) {}
    override fun onSetSuccess() {}
    override fun onCreateFailure(s: String) {}
    override fun onSetFailure(s: String) {}
}

private fun Intent.getParcelableExtraCompat(key: String): Intent? = if (Build.VERSION.SDK_INT >= 33) getParcelableExtra(key, Intent::class.java) else @Suppress("DEPRECATION") getParcelableExtra(key)
