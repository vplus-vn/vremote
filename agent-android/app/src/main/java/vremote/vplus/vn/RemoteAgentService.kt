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
import java.nio.charset.StandardCharsets
import java.util.concurrent.ConcurrentHashMap
import java.util.concurrent.TimeUnit
import kotlin.math.abs

/**
 * Always-reachable agent. Lifecycle:
 *  - The service connects the signaling WebSocket (+ pending polling) as soon as it has
 *    deviceId+token, INDEPENDENT of any screen-capture session, so the device is reachable the
 *    moment the app has been opened once (or after boot). Runs a foreground service (specialUse
 *    type on Android 14+) during this signaling-only phase.
 *  - Screen-capture consent (MediaProjection) is requested ONCE from MainActivity. The service
 *    then keeps ONE ScreenCapturerAndroid + VideoSource alive across sessions and switches the
 *    foreground type to mediaProjection. Each session creates a fresh PeerConnection + a new
 *    VideoTrack from the persistent source; ending a session disposes only the PeerConnection
 *    (and that session's track/inputChannel), keeping the capturer+projection alive → zero taps
 *    for every subsequent operator connect.
 *  - Only an explicit "Dừng" (ACTION_STOP), onDestroy, or the user revoking the projection
 *    (MediaProjection.Callback.onStop) tears the capturer/projection down.
 */
class RemoteAgentService : Service() {
    private lateinit var socket: WebSocket; private lateinit var factory: PeerConnectionFactory; private lateinit var egl: EglBase
    private var peer: PeerConnection?=null; private var source: VideoSource?=null; private var sessionTrack: VideoTrack?=null
    // GPU-free screen capture (RK3566 & other weak boxes SIGSEGV in the GL texture->I420 readback that
    // ScreenCapturerAndroid + SurfaceTextureHelper force on both the HW and SW encoders). MediaProjection
    // renders into an ImageReader (RGBA_8888 CPU buffers); each frame is converted to I420 on the CPU and
    // pushed straight to the VideoSource — no EglBase, no SurfaceTextureHelper, no YuvConverter, no GPU.
    private var mediaProjection: MediaProjection?=null; private var imageReader: ImageReader?=null; private var virtualDisplay: VirtualDisplay?=null
    private var captureThread: HandlerThread?=null; private var captureHandler: Handler?=null
    private var rgbaScratch: ByteArray?=null; private var yScratch: ByteArray?=null; private var uScratch: ByteArray?=null; private var vScratch: ByteArray?=null; private var lastFrameMs=0L; @Volatile private var frame1Logged=false
    // videoMode picks the transport, so at least one path is guaranteed to run on any box:
    //  "mjpeg" (default) — screen frames are JPEG-compressed with Android's Bitmap.compress and sent over
    //          the WebRTC DataChannel; NO WebRTC video encoder / MediaCodec / GPU is involved, so it cannot
    //          hit the RK3566 encoder faults. "vp8" — the WebRTC VideoTrack path (lighter, needs a working encoder).
    private var videoMode="mjpeg"; private var jpegBmp:android.graphics.Bitmap?=null; private var jpegBmpW=0; private var jpegBmpH=0
    // Guards the capture->encoder frame path against a use-after-free: a frame being converted on the
    // capture thread must NEVER reach onFrameCaptured after the VideoSource is disposed. releaseCapture
    // clears this, removes the listener, and JOINS the capture thread before disposing the source.
    @Volatile private var capturing=false
    private var activeSession:String?=null; private var activeCapability:String?=null; private var activeIceServers:List<PeerConnection.IceServer> = emptyList(); private var inputChannel:DataChannel?=null; private var screenW=0; private var screenH=0; private var projectionIntent:Intent?=null; private var expiryTask:Runnable?=null; @Volatile private var destroyed=false; private var polling=false; private var shownRequest:String?=null; private val notifId=2; private val consentNotifId=3
    private val seenNonces=ConcurrentHashMap.newKeySet<String>(); private val processedCommands=ConcurrentHashMap.newKeySet<String>(); private val handler=Handler(Looper.getMainLooper())
    // Encoder mode ("sw"/"hw") + capture size are pref-driven so the combo that survives this box's
    // video pipeline can be found in ONE build (MainActivity cycles them). Read once in onCreate/startProjection.
    private var encMode="sw"; private var capW=0; private var capH=0; private var capFps=10; private var capMaxEdge=640
    private fun cfg()="[$encMode ${capW}x${capH} q$capMaxEdge]"
    // Capture size follows the DEVICE'S OWN screen (aspect ratio preserved), downscaled so the long edge
    // <= capMaxEdge, then rounded to a multiple of 16 (H264/VP8 macroblock alignment — a non-16 dimension
    // makes the encoder crop/pad, which faulted on the weak box). This keeps the remote view correct for
    // portrait or landscape screens at ANY resolution, rather than a hardcoded size, while capMaxEdge lets
    // a weak box be tuned down. Fps stays modest.
    private fun align16(v:Int)=(((v+8)/16)*16).coerceAtLeast(16)
    private fun computeCaptureSize(){cacheScreenSize();var w=screenW;var h=screenH;if(w<=0||h<=0){w=1280;h=720};val longEdge=maxOf(w,h);val maxE=capMaxEdge.coerceAtLeast(160);if(longEdge>maxE){val s=maxE.toDouble()/longEdge;w=(w*s).toInt();h=(h*s).toInt()};capW=align16(w);capH=align16(h)}
    override fun onCreate(){super.onCreate();
     // Snapshot the PREVIOUS run's last breadcrumb before this process can overwrite it. A native
     // SIGSEGV (first-frame encode) leaves the true crash step in "crumb", but on restart the socket
     // reconnects and the server re-sends session.enable — enable() then overwrites "crumb" with
     // "enable cap=..." (source=null, since MediaProjection consent doesn't survive a restart) before
     // anyone can read it. Copy it to "prevCrumb" once per launch so the real crash step survives the
     // reopen. onCreate calls only boot() (never crumb()), so "crumb" here is still the prior run's.
     run{val pp=getSharedPreferences("vremote",MODE_PRIVATE);val last=pp.getString("crumb","")?:"";if(last.isNotEmpty())pp.edit().putString("prevCrumb",last).commit()}
     getSystemService(NotificationManager::class.java).createNotificationChannel(NotificationChannel(CHANNEL,"VRemote",NotificationManager.IMPORTANCE_LOW));startFgSignaling();PeerConnectionFactory.initialize(PeerConnectionFactory.InitializationOptions.builder(this).createInitializationOptions());egl=EglBase.create();
     // v0.0.19: FORCE pure-software VP8 encoding, ignoring the encMode pref. Every breadcrumb to date
     // (0.0.x texture-HW, 0.0.17 byte-buffer-HW, 0.0.18 CPU-I420 + HW) crashed at the SAME step —
     // "bitrate cap …k" → native SIGSEGV on the first frame — because all three used the box's HARDWARE
     // H264 encoder (OMX.rk), which faults on this RK3566 no matter how it is fed. SoftwareVideoEncoderFactory
     // is libvpx VP8: pure C, no MediaCodec, no OMX, no GPU. Combined with the ImageReader CPU capture the
     // ENTIRE send path is now 100% software and never touches the Rockchip hardware codec or the Mali GPU.
     // (The MainActivity SW/HW toggle is inert in this build; HW will be re-offered once SW is confirmed.)
     val p0=getSharedPreferences("vremote",MODE_PRIVATE);encMode="sw";capFps=p0.getInt("capFps",10);capMaxEdge=p0.getInt("capMaxEdge",640);videoMode=p0.getString("videoMode","mjpeg")?:"mjpeg"
     val encoderFactory:VideoEncoderFactory=SoftwareVideoEncoderFactory()
     // Startup markers go to a SEPARATE "boot" key so a service restart (START_STICKY, e.g. after a
     // crash) never overwrites the crash breadcrumb of the previous session (which lives in "crumb").
     boot("factory build ${cfg()} enc=$encMode")
     factory=PeerConnectionFactory.builder().setVideoEncoderFactory(encoderFactory).setVideoDecoderFactory(DefaultVideoDecoderFactory(egl.eglBaseContext)).createPeerConnectionFactory();boot("factory ok")}
    override fun onStartCommand(intent:Intent?,flags:Int,startId:Int):Int{
     if(intent?.action==ACTION_ACCEPT||intent?.action==ACTION_REJECT){respondPending(intent.getStringExtra(EXTRA_REQUEST_ID)?:"",intent.action==ACTION_ACCEPT);return START_STICKY}
     if(intent?.action==ACTION_STOP){stopProjection();return START_STICKY}
     if(intent!=null&&intent.hasExtra(EXTRA_DATA)){projectionIntent=intent.getParcelableExtraCompat(EXTRA_DATA);startProjection()}
     ensureConnected()
     if(!polling){polling=true;startPendingPolling()}
     return START_STICKY}
    // Foreground while only signaling is active (no projection yet): specialUse on Android 14+
    // (where a mediaProjection-typed FGS may not be started before consent), plain otherwise.
    private fun buildNotif(text:String)=Notification.Builder(this,CHANNEL).setContentTitle("VRemote Agent").setContentText(text).setSmallIcon(android.R.drawable.ic_menu_view).build()
    private fun startFgSignaling(){val n=buildNotif("Sẵn sàng nhận kết nối (chưa bật chia sẻ màn hình)");if(Build.VERSION.SDK_INT>=34)startForeground(1,n,ServiceInfo.FOREGROUND_SERVICE_TYPE_SPECIAL_USE) else startForeground(1,n)}
    private fun startFgProjection(){val n=buildNotif("Sẵn sàng điều khiển / chia sẻ màn hình");if(Build.VERSION.SDK_INT>=29)startForeground(1,n,ServiceInfo.FOREGROUND_SERVICE_TYPE_MEDIA_PROJECTION) else startForeground(1,n)}
    private fun ensureConnected(){if(::socket.isInitialized)return;val p=getSharedPreferences("vremote",MODE_PRIVATE);val id=p.getString("deviceId","")?:"";val tok=p.getString("deviceToken","")?:"";if(id.isEmpty()||tok.isEmpty())return;connect()}
    // Poll the backend for a pending connect the operator initiated; surface an accept/reject
    // notification (UltraViewer-style) that the user answers on the device.
    private fun startPendingPolling(){handler.postDelayed(object:Runnable{override fun run(){if(destroyed)return;pollPendingOnce();handler.postDelayed(this,2000)}},2000)}
    private fun pollPendingOnce(){val p=getSharedPreferences("vremote",MODE_PRIVATE);val base=p.getString("baseUrl","https://vremote.vplus.vn")?:"";val id=p.getString("deviceId","")?:"";val tok=p.getString("deviceToken","")?:"";if(id.isEmpty()||tok.isEmpty())return;Thread{try{val pend=ApiClient.pending(base,id,tok);handler.post{if(pend==null){if(shownRequest!=null){shownRequest=null;getSystemService(NotificationManager::class.java).cancel(notifId)}}else if(pend.requestId!=shownRequest){shownRequest=pend.requestId;showAcceptNotification(pend)}}}catch(_:Throwable){}}.start()}
    private fun showAcceptNotification(p:ApiClient.Pending){val f=PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE;val yes=PendingIntent.getService(this,1,Intent(this,RemoteAgentService::class.java).setAction(ACTION_ACCEPT).putExtra(EXTRA_REQUEST_ID,p.requestId),f);val no=PendingIntent.getService(this,2,Intent(this,RemoteAgentService::class.java).setAction(ACTION_REJECT).putExtra(EXTRA_REQUEST_ID,p.requestId),f);val n=Notification.Builder(this,CHANNEL).setContentTitle("Yêu cầu kết nối").setContentText("“${p.actor}” muốn xem màn hình.").setSmallIcon(android.R.drawable.ic_menu_view).addAction(android.R.drawable.ic_menu_view,"Đồng ý",yes).addAction(android.R.drawable.ic_menu_close_clear_cancel,"Từ chối",no).setAutoCancel(true).build();getSystemService(NotificationManager::class.java).notify(notifId,n)}
    private fun respondPending(rid:String,accept:Boolean){shownRequest=null;getSystemService(NotificationManager::class.java).cancel(notifId);if(rid.isEmpty())return;val p=getSharedPreferences("vremote",MODE_PRIVATE);val base=p.getString("baseUrl","https://vremote.vplus.vn")?:"";val id=p.getString("deviceId","")?:"";val tok=p.getString("deviceToken","")?:"";Thread{try{ApiClient.respond(base,id,tok,rid,accept)}catch(_:Throwable){}}.start()}
    private fun connect(){val p=getSharedPreferences("vremote",MODE_PRIVATE);val id=p.getString("deviceId","")?:"";val token=p.getString("deviceToken","")?:"";val url=p.getString("signalingUrl","ws://10.0.2.2:8787/agent")?:"";socket=OkHttpClient.Builder().pingInterval(20,TimeUnit.SECONDS).build().newWebSocket(Request.Builder().url(url).build(),object:WebSocketListener(){override fun onOpen(w:WebSocket,r:Response){w.send(JSONObject().put("type","agent.auth").put("deviceId",id).put("deviceToken",token).toString());w.send(JSONObject().put("type","agent.hello").put("deviceId",id).put("agentVersion",BuildConfig.VERSION_NAME).put("platform","android").put("capabilities",listOf("screen.view","screen.control")).toString())};override fun onMessage(w:WebSocket,text:String){val m=try{JSONObject(text)}catch(_:Throwable){return};handler.post{if(!destroyed)try{handle(m)}catch(_:Throwable){}}};override fun onFailure(w:WebSocket,t:Throwable,r:Response?){handler.postDelayed({if(!destroyed)try{connect()}catch(_:Throwable){}},5000)}})}
    // Diagnostic breadcrumb: persisted synchronously so the LAST step reached before a crash
    // (even a native SIGSEGV the JVM can't catch) is visible on the next app launch.
    private fun crumb(s:String){try{getSharedPreferences("vremote",MODE_PRIVATE).edit().putString("crumb",s+" @"+(System.currentTimeMillis()/1000%100000)).commit()}catch(_:Throwable){}}
    // Startup marker in a SEPARATE key so a service restart never clobbers the session crash breadcrumb.
    private fun boot(s:String){try{getSharedPreferences("vremote",MODE_PRIVATE).edit().putString("boot",s+" @"+(System.currentTimeMillis()/1000%100000)).commit()}catch(_:Throwable){}}
    // Lifecycle marker (session.enable etc.) in ITS OWN key. These fire on every websocket reconnect
    // (the server re-sends session.enable after a crash-restart), so they must NEVER share the "crumb"
    // key or they overwrite the real pipeline crash step before we can read it — exactly what hid the
    // native encoder SIGSEGV in v0.0.14/0.0.15. "crumb" now holds ONLY dangerous pipeline stages
    // (projection/peer/offer/encoder/bitrate), so the last one before death IS the crash site.
    private fun flow(s:String){try{getSharedPreferences("vremote",MODE_PRIVATE).edit().putString("flow",s+" @"+(System.currentTimeMillis()/1000%100000)).commit()}catch(_:Throwable){}}
    // The signaling server wraps the viewer's WebRTC messages as {type:"agent.signaling",payload:{...}};
    // unwrap it (the web agent does the same) so offer/candidate/leave actually reach us — top-level
    // offer/candidate kept as a fallback.
    private fun handle(m:JSONObject){when(m.optString("type")){"session.enable"->enable(m);"session.disable"->disable(m);"command"->command(m);"agent.signaling"->{val pl=m.optJSONObject("payload")?:return;when(pl.optString("type")){"offer"->handleOffer(pl);"candidate"->handleCandidate(pl);"leave"->endSession()}};"offer"->handleOffer(m);"candidate"->handleCandidate(m)}}
    private fun enable(m:JSONObject){val p=getSharedPreferences("vremote",MODE_PRIVATE);val id=p.getString("deviceId","")?:"";val secret=p.getString("deviceToken","")?:"";val sid=m.optString("sessionId");val nonce=m.optString("nonce");val ts=m.optLong("ts",0);val ttl=m.optInt("ttlSec",0);val cap=m.optString("capability");val sig=m.optString("sig");if(m.optString("deviceId")!=id||sid.isBlank()||nonce.isBlank()||sig.isBlank()||ttl !in 1..3600||abs(System.currentTimeMillis()/1000-ts)>300)return;if(!VRemoteSigner.verify(secret,VRemoteSigner.canonicalSessionEnable(id,sid,cap,ttl,nonce,ts),sig))return;if(!seenNonces.add("session:$nonce"))return;endSession();p.edit().putInt("lastSessionTtl",ttl).putLong("lastSessionExpires",ts+ttl).apply();activeSession=sid;activeCapability=cap;activeIceServers=parseIceServers(m.optJSONArray("iceServers"));cacheScreenSize();flow("enable cap=$cap source=${source!=null}");expiryTask=Runnable{if(activeSession==sid)endSession()};handler.postDelayed(expiryTask!!,ttl*1000L);if(source==null){notifyConsentNeeded();return};if(!newSessionPeer())return;crumb("peer ready, ready sent");sendSignal(JSONObject().put("type","agent.signaling").put("sessionId",sid).put("payload",JSONObject().put("type","ready").put("sessionId",sid)))}
    // Consent is a ONE-TIME step: create the source + GPU-free ImageReader capture once and keep them
    // alive across sessions. NO ScreenCapturerAndroid / SurfaceTextureHelper / EglBase — those force a
    // GL texture->I420 readback that SIGSEGVs on the RK3566 (crash pinned at the first encoded frame,
    // identical on HW and SW encoders). Here MediaProjection mirrors into an ImageReader (RGBA_8888 CPU
    // buffers), and pushI420 converts each frame to I420 on the CPU and feeds the VideoSource directly.
    private fun startProjection():Boolean{if(source!=null)return true;val data=projectionIntent?:return false;frame1Logged=false;crumb("proj:createSource");val src=factory.createVideoSource(true);source=src
     // Android 14+ requires the mediaProjection-typed FGS to be running BEFORE the MediaProjection is
     // acquired, so switch the foreground type FIRST, then getMediaProjection.
     startFgProjection();crumb("proj:startCapture")
     // Capture size follows THIS device's screen (aspect preserved, 16-aligned, long edge <= capMaxEdge).
     computeCaptureSize()
     try{
      val mpm=getSystemService(MediaProjectionManager::class.java)
      val mp=mpm.getMediaProjection(Activity.RESULT_OK,data);mediaProjection=mp
      // Android 14+ requires a registered callback before createVirtualDisplay.
      mp.registerCallback(object:MediaProjection.Callback(){override fun onStop(){handler.post{onProjectionRevoked()}}},handler)
      val ir=ImageReader.newInstance(capW,capH,PixelFormat.RGBA_8888,3);imageReader=ir
      val obs=src.capturerObserver;obs.onCapturerStarted(true)
      // Convert frames on a DEDICATED thread — the RGBA->I420 loop must never run on the main looper.
      val ct=HandlerThread("VRemoteCapture").apply{start()};captureThread=ct;val ch2=Handler(ct.looper);captureHandler=ch2
      // ImageReader fires at the display's refresh rate; throttle to capFps so the weak CPU keeps up.
      ir.setOnImageAvailableListener({reader->val img=try{reader.acquireLatestImage()}catch(_:Throwable){null}?:return@setOnImageAvailableListener;try{val now=SystemClock.uptimeMillis();val gap=1000L/capFps.coerceIn(1,30);if(capturing&&now-lastFrameMs>=gap){lastFrameMs=now;if(videoMode=="mjpeg")sendJpeg(img) else pushI420(img,obs)}}catch(_:Throwable){}finally{try{img.close()}catch(_:Throwable){}}},ch2)
      val dpi=resources.displayMetrics.densityDpi.coerceIn(120,640)
      virtualDisplay=mp.createVirtualDisplay("VRemote",capW,capH,dpi,DisplayManager.VIRTUAL_DISPLAY_FLAG_AUTO_MIRROR,ir.surface,null,ch2)
     }catch(t:Throwable){crumb("proj:img FAIL ${t.message}");onProjectionRevoked();return false}
     capturing=true;crumb("proj:capturing ${cfg()}");projectionHeld=true;getSystemService(NotificationManager::class.java).cancel(consentNotifId);return true}
    // Convert one RGBA_8888 screen frame to I420 on the CPU (BT.601, full 2x2 chroma subsample) and push
    // it to the VideoSource. Uses reusable scratch arrays + bulk ByteBuffer copies so a weak box keeps up
    // at the modest capFps. No GL anywhere — this is the whole point of the ImageReader path.
    private fun pushI420(img:Image,obs:CapturerObserver){
     if(!capturing)return
     val w=capW;val hgt=capH;if(w<=0||hgt<=0)return
     val plane=img.planes.firstOrNull()?:return;val rowStride=plane.rowStride;val pxStride=plane.pixelStride;val buf=plane.buffer
     val n=buf.remaining();var rgba=rgbaScratch;if(rgba==null||rgba.size<n){rgba=ByteArray(n);rgbaScratch=rgba};buf.get(rgba,0,n)
     val cw=(w+1)/2;val ch=(hgt+1)/2
     var yA=yScratch;if(yA==null||yA.size<w*hgt){yA=ByteArray(w*hgt);yScratch=yA}
     var uA=uScratch;if(uA==null||uA.size<cw*ch){uA=ByteArray(cw*ch);uScratch=uA}
     var vA=vScratch;if(vA==null||vA.size<cw*ch){vA=ByteArray(cw*ch);vScratch=vA}
     var y=0
     while(y<hgt){val rowBase=y*rowStride;val yBase=y*w;val cBase=(y/2)*cw;val chromaRow=(y and 1)==0;var x=0
      while(x<w){val i=rowBase+x*pxStride
       val r=rgba[i].toInt() and 0xFF;val g=rgba[i+1].toInt() and 0xFF;val b=rgba[i+2].toInt() and 0xFF
       var yy=(66*r+129*g+25*b+128 shr 8)+16;if(yy<16)yy=16 else if(yy>235)yy=235;yA[yBase+x]=yy.toByte()
       if(chromaRow&&(x and 1)==0){val ci=cBase+(x/2)
        var u=(-38*r-74*g+112*b+128 shr 8)+128;if(u<16)u=16 else if(u>240)u=240;uA[ci]=u.toByte()
        var v=(112*r-94*g-18*b+128 shr 8)+128;if(v<16)v=16 else if(v>240)v=240;vA[ci]=v.toByte()}
       x++}
      y++}
     val i420=JavaI420Buffer.allocate(w,hgt)
     fillPlane(i420.dataY,i420.strideY,yA,w,hgt);fillPlane(i420.dataU,i420.strideU,uA,cw,ch);fillPlane(i420.dataV,i420.strideV,vA,cw,ch)
     // First-frame breadcrumbs isolate a crash in OUR I420 push ("push:frame1") vs downstream in the
     // encoder (crumb stays past it). Only the first frame writes, so this costs nothing steady-state.
     if(!frame1Logged)crumb("push:frame1 ${w}x$hgt")
     val frame=VideoFrame(i420,0,System.nanoTime());try{obs.onFrameCaptured(frame)}finally{frame.release()}
     if(!frame1Logged){frame1Logged=true;crumb("push:frame1 ok")}
    }
    private fun fillPlane(dst:java.nio.ByteBuffer,stride:Int,src:ByteArray,w:Int,h:Int){if(stride==w){dst.position(0);dst.put(src,0,w*h)}else{var r=0;while(r<h){dst.position(r*stride);dst.put(src,r*w,w);r++}}}
    // MJPEG transport: JPEG-compress the RGBA frame with Android's Bitmap.compress (rock-solid, no
    // MediaCodec/GPU) and send it as a BINARY DataChannel message. The viewer draws it to a <canvas>.
    // Guarded by the channel's OPEN state + bufferedAmount so we never outrun a slow link.
    private fun sendJpeg(img:Image){
     val dc=inputChannel?:return;if(dc.state()!=DataChannel.State.OPEN)return;if(dc.bufferedAmount()>256*1024L)return
     val w=capW;val hgt=capH;if(w<=0||hgt<=0)return
     val plane=img.planes.firstOrNull()?:return;val rowStride=plane.rowStride;val buf=plane.buffer;val bmpW=rowStride/4;if(bmpW<w)return
     var bmp=jpegBmp;if(bmp==null||jpegBmpW!=bmpW||jpegBmpH!=hgt){try{bmp?.recycle()}catch(_:Throwable){};bmp=android.graphics.Bitmap.createBitmap(bmpW,hgt,android.graphics.Bitmap.Config.ARGB_8888);jpegBmp=bmp;jpegBmpW=bmpW;jpegBmpH=hgt}
     buf.rewind();bmp.copyPixelsFromBuffer(buf)
     val baos=java.io.ByteArrayOutputStream(48*1024)
     val src=if(bmpW!=w)android.graphics.Bitmap.createBitmap(bmp,0,0,w,hgt) else bmp
     src.compress(android.graphics.Bitmap.CompressFormat.JPEG,40,baos)
     if(src!==bmp)try{src.recycle()}catch(_:Throwable){}
     val bytes=baos.toByteArray()
     if(!frame1Logged){frame1Logged=true;crumb("jpeg:frame1 ${w}x$hgt ${bytes.size}b")}
     try{dc.send(DataChannel.Buffer(java.nio.ByteBuffer.wrap(bytes),true))}catch(_:Throwable){}
    }
    // ICE servers are authoritatively supplied by session.enable's `iceServers`; parse null-safely.
    // Each element: {"urls": String|[String], "username"?, "credential"?}. Falls back to google STUN.
    private fun parseIceServers(arr:JSONArray?):List<PeerConnection.IceServer>{val out=mutableListOf<PeerConnection.IceServer>();if(arr!=null)for(i in 0 until arr.length()){val o=arr.optJSONObject(i)?:continue;val urls=mutableListOf<String>();when(val u=o.opt("urls")){is String->if(u.isNotBlank())urls.add(u);is JSONArray->for(j in 0 until u.length()){val s=u.optString(j);if(s.isNotBlank())urls.add(s)}};if(urls.isEmpty())continue;val b=PeerConnection.IceServer.builder(urls);val user=o.optString("username","");val cred=o.optString("credential","");if(user.isNotEmpty()&&cred.isNotEmpty()){b.setUsername(user);b.setPassword(cred)};try{out.add(b.createIceServer())}catch(_:Throwable){}};return out}
    // Per-session PeerConnection with a NEW VideoTrack from the PERSISTENT source.
    private fun newSessionPeer():Boolean{val src=source?:return false;val servers=if(activeIceServers.isNotEmpty())activeIceServers else listOf(PeerConnection.IceServer.builder("stun:stun.l.google.com:19302").createIceServer());crumb("peer:createPC ice=${servers.size}");val pc=factory.createPeerConnection(servers,observer())?:return false;peer=pc
     // MJPEG mode sends frames over the DataChannel, so it adds NO WebRTC video track; VP8 mode adds one.
     if(videoMode=="vp8"){crumb("peer:createTrack");val track=factory.createVideoTrack("screen"+SystemClock.uptimeMillis(),src);sessionTrack=track;crumb("peer:addTrack");pc.addTrack(track,listOf("screen"));crumb("peer:addTrack ok")}else crumb("peer:mjpeg no-track");return true}
    private fun observer()=object:PeerConnection.Observer{override fun onIceCandidate(c:IceCandidate){sendSignal(JSONObject().put("type","agent.signaling").put("sessionId",activeSession).put("payload",JSONObject().put("type","candidate").put("sessionId",activeSession).put("candidate",JSONObject().put("sdpMid",c.sdpMid).put("sdpMLineIndex",c.sdpMLineIndex).put("candidate",c.sdp))))};override fun onSignalingChange(s:PeerConnection.SignalingState){};override fun onIceConnectionChange(s:PeerConnection.IceConnectionState){crumb("ice:$s")};override fun onIceConnectionReceivingChange(b:Boolean){};override fun onIceGatheringChange(s:PeerConnection.IceGatheringState){};override fun onIceCandidatesRemoved(c:Array<IceCandidate>){};override fun onAddStream(s:MediaStream){};override fun onRemoveStream(s:MediaStream){};override fun onDataChannel(d:DataChannel){inputChannel=d;d.registerObserver(object:DataChannel.Observer{override fun onBufferedAmountChange(p:Long){};override fun onStateChange(){if(d.state()==DataChannel.State.OPEN)sendControlStatus(d)};override fun onMessage(buf:DataChannel.Buffer){try{val b=buf.data;val bytes=ByteArray(b.remaining());b.get(bytes);onInput(String(bytes,StandardCharsets.UTF_8))}catch(_:Throwable){}}})};override fun onRenegotiationNeeded(){};override fun onAddTrack(r:RtpReceiver,m:Array<MediaStream>){};override fun onConnectionChange(s:PeerConnection.PeerConnectionState){crumb("conn:$s");if(s==PeerConnection.PeerConnectionState.FAILED||s==PeerConnection.PeerConnectionState.CLOSED)handler.post{endSession()}};override fun onSelectedCandidatePairChanged(e:CandidatePairChangeEvent){}}
    private fun cacheScreenSize(){try{val wm=getSystemService(WindowManager::class.java);if(Build.VERSION.SDK_INT>=30){val b=wm.currentWindowMetrics.bounds;screenW=b.width();screenH=b.height()}else{val dm=DisplayMetrics();@Suppress("DEPRECATION") wm.defaultDisplay.getRealMetrics(dm);screenW=dm.widthPixels;screenH=dm.heightPixels}}catch(_:Throwable){}}
    // Decode one input event (normalized 0..1 coords) and route injection to the AccessibilityService on the main thread.
    private fun onInput(text:String){if(activeSession==null||activeCapability!="control")return;val svc=VRemoteInputService.instance?:return;if(screenW<=0||screenH<=0)cacheScreenSize();if(screenW<=0||screenH<=0)return;val m=try{JSONObject(text)}catch(_:Throwable){return};val w=screenW;val h=screenH
     fun px(v:Double,max:Int)= (v.coerceIn(0.0,1.0)*max).toInt()
     when(m.optString("t")){
      "tap"->{val x=px(m.optDouble("x",0.0),w);val y=px(m.optDouble("y",0.0),h);handler.post{svc.tap(x,y)}}
      "long"->{val x=px(m.optDouble("x",0.0),w);val y=px(m.optDouble("y",0.0),h);handler.post{svc.longPress(x,y)}}
      "swipe"->{val x1=px(m.optDouble("x1",0.0),w);val y1=px(m.optDouble("y1",0.0),h);val x2=px(m.optDouble("x2",0.0),w);val y2=px(m.optDouble("y2",0.0),h);val ms=m.optLong("ms",200).coerceIn(1,60000);handler.post{svc.swipe(x1,y1,x2,y2,ms)}}
      "key"->{val k=m.optString("k");if(k=="del")handler.post{svc.delChar()} else handler.post{svc.global(k)}}
      "text"->{val s=m.optString("s");handler.post{svc.typeText(s)}}
     }}
    // List the video codec names in an SDP's m=video section (for a breadcrumb) — a=rtpmap:<pt> NAME/clock.
    private fun videoCodecsOf(sdp:String):String{val out=LinkedHashSet<String>();var inV=false;for(l in sdp.split("\r\n")){if(l.startsWith("m=")){inV=l.startsWith("m=video")};if(inV){val m=Regex("^a=rtpmap:\\d+ ([A-Za-z0-9-]+)/").find(l);if(m!=null){val n=m.groupValues[1].uppercase();if(n!="RTX"&&n!="RED"&&n!="ULPFEC")out.add(n)}}};return out.joinToString(",")}
    // Force the outgoing video to plain VP8 by pruning every other codec from the m=video section of the
    // ANSWER. Even with SoftwareVideoEncoderFactory the answer can offer VP9/AV1, whose initEncode
    // (libvpx-vp9 / libaom) SIGSEGVs on this RK3566 the moment setLocalDescription instantiates it —
    // which is exactly where every build has died (last crumb "offer:local ok"/"bitrate cap", before any
    // frame). VP8 (libvpx) is the most robust software encoder. Defensive: if no VP8 is present, return
    // the SDP unchanged so negotiation is never broken.
    private fun preferVp8(sdp:String):String{try{
     val lines=sdp.split("\r\n");val vp8=HashSet<String>();val apt=HashMap<String,String>()
     for(l in lines){val r=Regex("^a=rtpmap:(\\d+) VP8/",RegexOption.IGNORE_CASE).find(l);if(r!=null)vp8.add(r.groupValues[1]);val f=Regex("^a=fmtp:(\\d+) apt=(\\d+)").find(l);if(f!=null)apt[f.groupValues[1]]=f.groupValues[2]}
     if(vp8.isEmpty())return sdp
     val keep=HashSet(vp8);for((rtx,a) in apt)if(a in vp8)keep.add(rtx)
     val out=ArrayList<String>(lines.size);var inV=false
     for(l in lines){
      if(l.startsWith("m=")){inV=l.startsWith("m=video");if(inV){val p=l.split(" ");if(p.size>3){out.add((p.take(3)+p.drop(3).filter{it in keep}).joinToString(" "));continue}}}
      if(inV){val pm=Regex("^a=(rtpmap|rtcp-fb|fmtp):(\\d+)").find(l);if(pm!=null&&pm.groupValues[2] !in keep)continue}
      out.add(l)
     }
     return out.joinToString("\r\n")
    }catch(_:Throwable){return sdp}}
    // Properly chained SRD -> createAnswer -> SLD. The answer is munged to VP8-only BEFORE it is applied
    // locally (so our encoder is VP8) and sent (so the viewer decodes VP8). Fine breadcrumbs pinpoint the
    // failing step; setLocalDescription is where the outgoing video encoder is instantiated.
    private fun handleOffer(m:JSONObject){val sdp=m.optString("sdp");if(sdp.isBlank())return;if(peer==null){if(activeSession==null||source==null||!newSessionPeer())return};val pc=peer!!;crumb("offer:setRemote");pc.setRemoteDescription(object:SimpleSdpObserver(){override fun onSetSuccess(){crumb("offer:remote ok");pc.createAnswer(object:SimpleSdpObserver(){override fun onCreateSuccess(d:SessionDescription){crumb("offer:answer ["+videoCodecsOf(d.description)+"]");val fixed=SessionDescription(d.type,preferVp8(d.description));crumb("offer:vp8 ["+videoCodecsOf(fixed.description)+"]");pc.setLocalDescription(object:SimpleSdpObserver(){override fun onSetSuccess(){crumb("offer:local ok ${cfg()}")};override fun onSetFailure(e:String){crumb("offer:local FAIL ${cfg()} $e")}},fixed);crumb("offer:sld called");sendSignal(JSONObject().put("type","agent.signaling").put("sessionId",activeSession).put("payload",JSONObject().put("type","answer").put("sessionId",activeSession).put("sdp",fixed.description)))};override fun onCreateFailure(e:String){crumb("offer:answer FAIL $e")}},MediaConstraints())};override fun onSetFailure(e:String){crumb("offer:remote FAIL $e")}},SessionDescription(SessionDescription.Type.OFFER,sdp))}
    private fun handleCandidate(m:JSONObject){val c=m.optJSONObject("candidate")?:return;peer?.addIceCandidate(IceCandidate(c.optString("sdpMid"),c.optInt("sdpMLineIndex"),c.optString("candidate")))}
    private fun command(m:JSONObject){val p=getSharedPreferences("vremote",MODE_PRIVATE);val id=p.getString("deviceId","")?:"";val secret=p.getString("deviceToken","")?:"";val cid=m.optString("commandId");val type=m.optString("cmdType");val nonce=m.optString("nonce");val ts=m.optLong("ts",0);val sig=m.optString("sig");if(m.optString("deviceId")!=id||cid.isBlank()||nonce.isBlank()||sig.isBlank()||abs(System.currentTimeMillis()/1000-ts)>300){commandResult(cid,"rejected","stale_or_invalid");return};val args=m.optJSONObject("args");val argsJson=args?.toString();if(!VRemoteSigner.verify(secret,VRemoteSigner.canonicalCommand(id,cid,type,VRemoteSigner.argsHash(argsJson),nonce,ts),sig)){commandResult(cid,"invalid_signature",null);return};if(!seenNonces.add("cmd:$nonce")){commandResult(cid,"replay_detected",null);return};if(!processedCommands.add(cid)){commandResult(cid,"done",null);return};val gated=setOf("reboot","config-push","apk-update","cache-clear","remote.enable","remote.disable");if(type in gated&&!p.getBoolean("allowGatedCommands",false)){commandResult(cid,"gated_off",null);return};when(type){"health","ping"->commandResult(cid,"done",mapOf("online" to true,"activeSession" to activeSession));"version"->commandResult(cid,"done",mapOf("agentVersion" to BuildConfig.VERSION_NAME,"versionCode" to BuildConfig.VERSION_CODE));"logs","config-read"->commandResult(cid,"done",emptyMap<String,String>());"apk-update"->{val base=p.getString("baseUrl","https://vremote.vplus.vn")?:"";Thread{try{val info=ApiClient.latest(base);if(info.versionCode<=BuildConfig.VERSION_CODE)commandResult(cid,"done",mapOf("updated" to false,"installed" to BuildConfig.VERSION_NAME)) else if(!AgentUpdater.canInstall(this))commandResult(cid,"error","install_permission_required") else{AgentUpdater.install(this,AgentUpdater.download(this,base,info));commandResult(cid,"done",mapOf("updated" to true,"downloaded" to info.versionName))}}catch(e:Throwable){commandResult(cid,"error",e.message?:"update_failed")}}.start()};else->commandResult(cid,"error","unsupported_command")}}
    private fun commandResult(id:String,result:String,data:Any?){if(id.isBlank())return;val d=JSONObject();when(data){is Map<*,*>->data.forEach{(k,v)->d.put(k.toString(),v)};is String->d.put("detail",data)};sendSignal(JSONObject().put("type","command.result").put("deviceId",getSharedPreferences("vremote",MODE_PRIVATE).getString("deviceId","")).put("commandId",id).put("result",result).put("data",if(d.length()>0)d else JSONObject.NULL))}
    private fun notifyConsentNeeded(){getSystemService(NotificationManager::class.java).notify(consentNotifId,Notification.Builder(this,CHANNEL).setContentTitle("Cần bật chia sẻ màn hình").setContentText("Mở app VRemote và bấm “Bật sẵn sàng điều khiển” để cho phép một lần.").setSmallIcon(android.R.drawable.ic_menu_view).setAutoCancel(true).build())}
    // Tell the viewer whether this device can actually be controlled (the AccessibilityService must
    // be enabled by the user). Sent when the input data channel opens, so the console can warn the
    // operator "chỉ xem được" instead of silently dropping every tap.
    private fun sendControlStatus(d:DataChannel){try{val js=JSONObject().put("t","agent.status").put("control",VRemoteInputService.isEnabled());d.send(DataChannel.Buffer(java.nio.ByteBuffer.wrap(js.toString().toByteArray(StandardCharsets.UTF_8)),false))}catch(_:Throwable){}}
    // Weak encoders on cheap boxes can fault while configuring for WebRTC's high DEFAULT target bitrate
    // (~1.7-2 Mbps). Cap the video sender's max bitrate to a modest budget scaled to the quality tier,
    // applied right after the local description applies (encodings exist by then), so the encoder
    // initialises for a light load. Framerate is already bounded by the capturer's capFps.
    private fun capSenderBitrate(pc:PeerConnection){try{crumb("bitrate:start");val s=pc.senders.firstOrNull{it.track()?.kind()=="video"}?:return;val p=s.parameters?:return;val bps=when{capMaxEdge<=360->250_000;capMaxEdge<=480->400_000;capMaxEdge<=640->700_000;capMaxEdge<=960->1_200_000;else->2_000_000};var changed=false;for(e in p.encodings){e.maxBitrateBps=bps;changed=true};if(changed){s.parameters=p;crumb("bitrate cap ${bps/1000}k")}}catch(_:Throwable){}}
    private fun sendSignal(p:JSONObject){if(::socket.isInitialized)socket.send(p.toString())}
    private fun disable(m:JSONObject){if(m.optString("sessionId")==activeSession)endSession()}
    // End ONE session: dispose only the PeerConnection + this session's track/inputChannel. The
    // capturer+source+projection stay alive so the next connect needs zero taps.
    private fun endSession(){expiryTask?.let{handler.removeCallbacks(it)};expiryTask=null;try{inputChannel?.dispose()}catch(_:Throwable){};inputChannel=null;peer?.close();peer=null;sessionTrack?.dispose();sessionTrack=null;activeSession=null;activeCapability=null}
    // Explicit "Dừng": drop the projection but stay reachable on signaling.
    // Tear down the ImageReader capture pipeline (order: stop frames, close reader, stop projection,
    // dispose source). All null-safe so it's reusable from the startProjection failure path too.
    // Ordered teardown to prevent a use-after-free: (1) stop new frame pushes and remove the listener so
    // no further callbacks fire, (2) tear down the projection/reader, (3) JOIN the capture thread so any
    // in-flight pushI420 has fully returned, and ONLY THEN (4) dispose the VideoSource. Always runs on the
    // main thread (stop/onProjectionRevoked/onDestroy), so the join never deadlocks the capture thread.
    private fun releaseCapture(){
     capturing=false
     try{imageReader?.setOnImageAvailableListener(null,null)}catch(_:Throwable){}
     try{virtualDisplay?.release()}catch(_:Throwable){};virtualDisplay=null
     try{imageReader?.close()}catch(_:Throwable){};imageReader=null
     try{mediaProjection?.stop()}catch(_:Throwable){};mediaProjection=null
     val ct=captureThread;captureThread=null;captureHandler=null
     if(ct!=null)try{ct.quitSafely();ct.join(1000)}catch(_:Throwable){}
     try{source?.dispose()}catch(_:Throwable){};source=null
     try{jpegBmp?.recycle()}catch(_:Throwable){};jpegBmp=null;jpegBmpW=0;jpegBmpH=0
    }
    private fun stopProjection(){endSession();releaseCapture();projectionIntent=null;projectionHeld=false;startFgSignaling()}
    // User revoked capture (or it failed): clear it, tell the user to reopen the app to re-grant.
    private fun onProjectionRevoked(){endSession();releaseCapture();projectionIntent=null;projectionHeld=false;startFgSignaling();notifyConsentNeeded()}
    override fun onBind(i:Intent?):IBinder?=null
    override fun onDestroy(){destroyed=true;endSession();releaseCapture();projectionHeld=false;try{if(::socket.isInitialized)socket.close(1000,"shutdown")}catch(_:Throwable){};factory.dispose();egl.release();super.onDestroy()}
    companion object{const val EXTRA_RESULT_CODE="resultCode";const val EXTRA_DATA="projectionData";private const val CHANNEL="vremote-agent";const val ACTION_ACCEPT="vn.vplus.vremote.ACCEPT";const val ACTION_REJECT="vn.vplus.vremote.REJECT";const val ACTION_STOP="vn.vplus.vremote.STOP";const val EXTRA_REQUEST_ID="requestId";@Volatile var projectionHeld=false}
}
private open class SimpleSdpObserver:SdpObserver{override fun onCreateSuccess(s:SessionDescription){};override fun onSetSuccess(){};override fun onCreateFailure(s:String){};override fun onSetFailure(s:String){}}
private fun Intent.getParcelableExtraCompat(key:String):Intent?=if(Build.VERSION.SDK_INT>=33)getParcelableExtra(key,Intent::class.java)else @Suppress("DEPRECATION") getParcelableExtra(key)
