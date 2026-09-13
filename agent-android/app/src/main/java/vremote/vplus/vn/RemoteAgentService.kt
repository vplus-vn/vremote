package vremote.vplus.vn

import android.app.*
import android.content.*
import android.content.pm.ServiceInfo
import android.media.projection.MediaProjection
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
    private var peer: PeerConnection?=null; private var capturer: ScreenCapturerAndroid?=null; private var source: VideoSource?=null; private var helper: SurfaceTextureHelper?=null; private var sessionTrack: VideoTrack?=null
    private var activeSession:String?=null; private var activeCapability:String?=null; private var activeIceServers:List<PeerConnection.IceServer> = emptyList(); private var inputChannel:DataChannel?=null; private var screenW=0; private var screenH=0; private var projectionIntent:Intent?=null; private var expiryTask:Runnable?=null; @Volatile private var destroyed=false; private var polling=false; private var shownRequest:String?=null; private val notifId=2; private val consentNotifId=3
    private val seenNonces=ConcurrentHashMap.newKeySet<String>(); private val processedCommands=ConcurrentHashMap.newKeySet<String>(); private val handler=Handler(Looper.getMainLooper())
    override fun onCreate(){super.onCreate();getSystemService(NotificationManager::class.java).createNotificationChannel(NotificationChannel(CHANNEL,"VRemote",NotificationManager.IMPORTANCE_LOW));startFgSignaling();PeerConnectionFactory.initialize(PeerConnectionFactory.InitializationOptions.builder(this).createInitializationOptions());egl=EglBase.create();
     // Force the SOFTWARE VP8/VP9 encoder. Many cheap Android TV boxes/tablets crash natively in
     // their hardware MediaCodec encoder when it starts encoding a screen-capture surface (the app
     // just dies as video begins). Software encoding is slower but robust and avoids the SIGSEGV.
     factory=PeerConnectionFactory.builder().setVideoEncoderFactory(SoftwareVideoEncoderFactory()).setVideoDecoderFactory(DefaultVideoDecoderFactory(egl.eglBaseContext)).createPeerConnectionFactory()}
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
    private fun connect(){val p=getSharedPreferences("vremote",MODE_PRIVATE);val id=p.getString("deviceId","")?:"";val token=p.getString("deviceToken","")?:"";val url=p.getString("signalingUrl","ws://10.0.2.2:8787/agent")?:"";socket=OkHttpClient.Builder().pingInterval(20,TimeUnit.SECONDS).build().newWebSocket(Request.Builder().url(url).build(),object:WebSocketListener(){override fun onOpen(w:WebSocket,r:Response){w.send(JSONObject().put("type","agent.auth").put("deviceId",id).put("deviceToken",token).toString());w.send(JSONObject().put("type","agent.hello").put("deviceId",id).put("agentVersion",BuildConfig.VERSION_NAME).put("platform","android").put("capabilities",listOf("screen.view","screen.control")).toString())};override fun onMessage(w:WebSocket,text:String){try{handle(JSONObject(text))}catch(_:Throwable){}};override fun onFailure(w:WebSocket,t:Throwable,r:Response?){handler.postDelayed({if(!destroyed)try{connect()}catch(_:Throwable){}},5000)}})}
    // Diagnostic breadcrumb: persisted synchronously so the LAST step reached before a crash
    // (even a native SIGSEGV the JVM can't catch) is visible on the next app launch.
    private fun crumb(s:String){try{getSharedPreferences("vremote",MODE_PRIVATE).edit().putString("crumb",s+" @"+(System.currentTimeMillis()/1000%100000)).commit()}catch(_:Throwable){}}
    // The signaling server wraps the viewer's WebRTC messages as {type:"agent.signaling",payload:{...}};
    // unwrap it (the web agent does the same) so offer/candidate/leave actually reach us — top-level
    // offer/candidate kept as a fallback.
    private fun handle(m:JSONObject){when(m.optString("type")){"session.enable"->enable(m);"session.disable"->disable(m);"command"->command(m);"agent.signaling"->{val pl=m.optJSONObject("payload")?:return;when(pl.optString("type")){"offer"->handleOffer(pl);"candidate"->handleCandidate(pl);"leave"->endSession()}};"offer"->handleOffer(m);"candidate"->handleCandidate(m)}}
    private fun enable(m:JSONObject){val p=getSharedPreferences("vremote",MODE_PRIVATE);val id=p.getString("deviceId","")?:"";val secret=p.getString("deviceToken","")?:"";val sid=m.optString("sessionId");val nonce=m.optString("nonce");val ts=m.optLong("ts",0);val ttl=m.optInt("ttlSec",0);val cap=m.optString("capability");val sig=m.optString("sig");if(m.optString("deviceId")!=id||sid.isBlank()||nonce.isBlank()||sig.isBlank()||ttl !in 1..3600||abs(System.currentTimeMillis()/1000-ts)>300)return;if(!VRemoteSigner.verify(secret,VRemoteSigner.canonicalSessionEnable(id,sid,cap,ttl,nonce,ts),sig))return;if(!seenNonces.add("session:$nonce"))return;endSession();p.edit().putInt("lastSessionTtl",ttl).putLong("lastSessionExpires",ts+ttl).apply();activeSession=sid;activeCapability=cap;activeIceServers=parseIceServers(m.optJSONArray("iceServers"));cacheScreenSize();crumb("enable cap=$cap source=${source!=null}");expiryTask=Runnable{if(activeSession==sid)endSession()};handler.postDelayed(expiryTask!!,ttl*1000L);if(source==null){notifyConsentNeeded();return};if(!newSessionPeer())return;crumb("peer ready, ready sent");sendSignal(JSONObject().put("type","agent.signaling").put("sessionId",sid).put("payload",JSONObject().put("type","ready").put("sessionId",sid)))}
    // Consent is a ONE-TIME step: create the capturer+source once and keep them alive across sessions.
    private fun startProjection():Boolean{if(source!=null)return true;val data=projectionIntent?:return false;crumb("proj:createSource");val src=factory.createVideoSource(true);source=src;val h=SurfaceTextureHelper.create("VRemoteCapture",egl.eglBaseContext);helper=h;val capt=ScreenCapturerAndroid(data,object:MediaProjection.Callback(){override fun onStop(){handler.post{onProjectionRevoked()}}});capturer=capt;
     // Android 14+ requires the mediaProjection-typed FGS to be running BEFORE the MediaProjection
     // is acquired — ScreenCapturerAndroid.startCapture() acquires it internally, so switch the
     // foreground type FIRST, then startCapture. Revert to the signaling type if capture fails.
     startFgProjection();crumb("proj:startCapture");
     // Low resolution/fps: weak TV boxes crash their video pipeline at 720p when encoding starts.
     try{capt.initialize(h,this,src.capturerObserver);capt.startCapture(640,360,10)}catch(_:Throwable){onProjectionRevoked();return false};crumb("proj:capturing");projectionHeld=true;getSystemService(NotificationManager::class.java).cancel(consentNotifId);return true}
    // ICE servers are authoritatively supplied by session.enable's `iceServers`; parse null-safely.
    // Each element: {"urls": String|[String], "username"?, "credential"?}. Falls back to google STUN.
    private fun parseIceServers(arr:JSONArray?):List<PeerConnection.IceServer>{val out=mutableListOf<PeerConnection.IceServer>();if(arr!=null)for(i in 0 until arr.length()){val o=arr.optJSONObject(i)?:continue;val urls=mutableListOf<String>();when(val u=o.opt("urls")){is String->if(u.isNotBlank())urls.add(u);is JSONArray->for(j in 0 until u.length()){val s=u.optString(j);if(s.isNotBlank())urls.add(s)}};if(urls.isEmpty())continue;val b=PeerConnection.IceServer.builder(urls);val user=o.optString("username","");val cred=o.optString("credential","");if(user.isNotEmpty()&&cred.isNotEmpty()){b.setUsername(user);b.setPassword(cred)};try{out.add(b.createIceServer())}catch(_:Throwable){}};return out}
    // Per-session PeerConnection with a NEW VideoTrack from the PERSISTENT source.
    private fun newSessionPeer():Boolean{val src=source?:return false;val servers=if(activeIceServers.isNotEmpty())activeIceServers else listOf(PeerConnection.IceServer.builder("stun:stun.l.google.com:19302").createIceServer());crumb("peer:createPC ice=${servers.size}");val pc=factory.createPeerConnection(servers,observer())?:return false;peer=pc;crumb("peer:createTrack");val track=factory.createVideoTrack("screen"+SystemClock.uptimeMillis(),src);sessionTrack=track;crumb("peer:addTrack");pc.addTrack(track,listOf("screen"));crumb("peer:addTrack ok");return true}
    private fun observer()=object:PeerConnection.Observer{override fun onIceCandidate(c:IceCandidate){sendSignal(JSONObject().put("type","agent.signaling").put("sessionId",activeSession).put("payload",JSONObject().put("type","candidate").put("sessionId",activeSession).put("candidate",JSONObject().put("sdpMid",c.sdpMid).put("sdpMLineIndex",c.sdpMLineIndex).put("candidate",c.sdp))))};override fun onSignalingChange(s:PeerConnection.SignalingState){};override fun onIceConnectionChange(s:PeerConnection.IceConnectionState){crumb("ice:$s")};override fun onIceConnectionReceivingChange(b:Boolean){};override fun onIceGatheringChange(s:PeerConnection.IceGatheringState){};override fun onIceCandidatesRemoved(c:Array<IceCandidate>){};override fun onAddStream(s:MediaStream){};override fun onRemoveStream(s:MediaStream){};override fun onDataChannel(d:DataChannel){inputChannel=d;d.registerObserver(object:DataChannel.Observer{override fun onBufferedAmountChange(p:Long){};override fun onStateChange(){};override fun onMessage(buf:DataChannel.Buffer){try{val b=buf.data;val bytes=ByteArray(b.remaining());b.get(bytes);onInput(String(bytes,StandardCharsets.UTF_8))}catch(_:Throwable){}}})};override fun onRenegotiationNeeded(){};override fun onAddTrack(r:RtpReceiver,m:Array<MediaStream>){};override fun onConnectionChange(s:PeerConnection.PeerConnectionState){crumb("conn:$s");if(s==PeerConnection.PeerConnectionState.DISCONNECTED||s==PeerConnection.PeerConnectionState.FAILED)handler.post{endSession()}};override fun onSelectedCandidatePairChanged(e:CandidatePairChangeEvent){}}
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
    // Properly chained SRD -> createAnswer -> SLD, with fine breadcrumbs so the exact failing
    // step is visible. setLocalDescription is where the outgoing video encoder is instantiated.
    private fun handleOffer(m:JSONObject){val sdp=m.optString("sdp");if(sdp.isBlank()||peer==null)return;val pc=peer!!;crumb("offer:setRemote");pc.setRemoteDescription(object:SimpleSdpObserver(){override fun onSetSuccess(){crumb("offer:remote ok");pc.createAnswer(object:SimpleSdpObserver(){override fun onCreateSuccess(d:SessionDescription){crumb("offer:answer ok");pc.setLocalDescription(object:SimpleSdpObserver(){override fun onSetSuccess(){crumb("offer:local ok")};override fun onSetFailure(e:String){crumb("offer:local FAIL $e")}},d);crumb("offer:sld called");sendSignal(JSONObject().put("type","agent.signaling").put("sessionId",activeSession).put("payload",JSONObject().put("type","answer").put("sessionId",activeSession).put("sdp",d.description)))};override fun onCreateFailure(e:String){crumb("offer:answer FAIL $e")}},MediaConstraints())};override fun onSetFailure(e:String){crumb("offer:remote FAIL $e")}},SessionDescription(SessionDescription.Type.OFFER,sdp))}
    private fun handleCandidate(m:JSONObject){val c=m.optJSONObject("candidate")?:return;peer?.addIceCandidate(IceCandidate(c.optString("sdpMid"),c.optInt("sdpMLineIndex"),c.optString("candidate")))}
    private fun command(m:JSONObject){val p=getSharedPreferences("vremote",MODE_PRIVATE);val id=p.getString("deviceId","")?:"";val secret=p.getString("deviceToken","")?:"";val cid=m.optString("commandId");val type=m.optString("cmdType");val nonce=m.optString("nonce");val ts=m.optLong("ts",0);val sig=m.optString("sig");if(m.optString("deviceId")!=id||cid.isBlank()||nonce.isBlank()||sig.isBlank()||abs(System.currentTimeMillis()/1000-ts)>300){commandResult(cid,"rejected","stale_or_invalid");return};val args=m.optJSONObject("args");val argsJson=args?.toString();if(!VRemoteSigner.verify(secret,VRemoteSigner.canonicalCommand(id,cid,type,VRemoteSigner.argsHash(argsJson),nonce,ts),sig)){commandResult(cid,"invalid_signature",null);return};if(!seenNonces.add("cmd:$nonce")){commandResult(cid,"replay_detected",null);return};if(!processedCommands.add(cid)){commandResult(cid,"done",null);return};val gated=setOf("reboot","config-push","apk-update","cache-clear","remote.enable","remote.disable");if(type in gated&&!p.getBoolean("allowGatedCommands",false)){commandResult(cid,"gated_off",null);return};when(type){"health","ping"->commandResult(cid,"done",mapOf("online" to true,"activeSession" to activeSession));"version"->commandResult(cid,"done",mapOf("agentVersion" to BuildConfig.VERSION_NAME,"versionCode" to BuildConfig.VERSION_CODE));"logs","config-read"->commandResult(cid,"done",emptyMap<String,String>());"apk-update"->{val base=p.getString("baseUrl","https://vremote.vplus.vn")?:"";Thread{try{val info=ApiClient.latest(base);if(info.versionCode<=BuildConfig.VERSION_CODE)commandResult(cid,"done",mapOf("updated" to false,"installed" to BuildConfig.VERSION_NAME)) else if(!AgentUpdater.canInstall(this))commandResult(cid,"error","install_permission_required") else{AgentUpdater.install(this,AgentUpdater.download(this,base,info));commandResult(cid,"done",mapOf("updated" to true,"downloaded" to info.versionName))}}catch(e:Throwable){commandResult(cid,"error",e.message?:"update_failed")}}.start()};else->commandResult(cid,"error","unsupported_command")}}
    private fun commandResult(id:String,result:String,data:Any?){if(id.isBlank())return;val d=JSONObject();when(data){is Map<*,*>->data.forEach{(k,v)->d.put(k.toString(),v)};is String->d.put("detail",data)};sendSignal(JSONObject().put("type","command.result").put("deviceId",getSharedPreferences("vremote",MODE_PRIVATE).getString("deviceId","")).put("commandId",id).put("result",result).put("data",if(d.length()>0)d else JSONObject.NULL))}
    private fun notifyConsentNeeded(){getSystemService(NotificationManager::class.java).notify(consentNotifId,Notification.Builder(this,CHANNEL).setContentTitle("Cần bật chia sẻ màn hình").setContentText("Mở app VRemote và bấm “Bật sẵn sàng điều khiển” để cho phép một lần.").setSmallIcon(android.R.drawable.ic_menu_view).setAutoCancel(true).build())}
    private fun sendSignal(p:JSONObject){if(::socket.isInitialized)socket.send(p.toString())}
    private fun disable(m:JSONObject){if(m.optString("sessionId")==activeSession)endSession()}
    // End ONE session: dispose only the PeerConnection + this session's track/inputChannel. The
    // capturer+source+projection stay alive so the next connect needs zero taps.
    private fun endSession(){expiryTask?.let{handler.removeCallbacks(it)};expiryTask=null;try{inputChannel?.dispose()}catch(_:Throwable){};inputChannel=null;peer?.close();peer=null;sessionTrack?.dispose();sessionTrack=null;activeSession=null;activeCapability=null}
    // Explicit "Dừng": drop the projection but stay reachable on signaling.
    private fun stopProjection(){endSession();try{capturer?.stopCapture()}catch(_:Throwable){};capturer?.dispose();capturer=null;source?.dispose();source=null;helper?.dispose();helper=null;projectionIntent=null;projectionHeld=false;startFgSignaling()}
    // User revoked capture (or it failed): clear it, tell the user to reopen the app to re-grant.
    private fun onProjectionRevoked(){endSession();try{capturer?.stopCapture()}catch(_:Throwable){};capturer?.dispose();capturer=null;source?.dispose();source=null;helper?.dispose();helper=null;projectionIntent=null;projectionHeld=false;startFgSignaling();notifyConsentNeeded()}
    override fun onBind(i:Intent?):IBinder?=null
    override fun onDestroy(){destroyed=true;endSession();try{capturer?.stopCapture()}catch(_:Throwable){};capturer?.dispose();capturer=null;source?.dispose();source=null;helper?.dispose();helper=null;projectionHeld=false;try{if(::socket.isInitialized)socket.close(1000,"shutdown")}catch(_:Throwable){};factory.dispose();egl.release();super.onDestroy()}
    companion object{const val EXTRA_RESULT_CODE="resultCode";const val EXTRA_DATA="projectionData";private const val CHANNEL="vremote-agent";const val ACTION_ACCEPT="vn.vplus.vremote.ACCEPT";const val ACTION_REJECT="vn.vplus.vremote.REJECT";const val ACTION_STOP="vn.vplus.vremote.STOP";const val EXTRA_REQUEST_ID="requestId";@Volatile var projectionHeld=false}
}
private open class SimpleSdpObserver:SdpObserver{override fun onCreateSuccess(s:SessionDescription){};override fun onSetSuccess(){};override fun onCreateFailure(s:String){};override fun onSetFailure(s:String){}}
private fun Intent.getParcelableExtraCompat(key:String):Intent?=if(Build.VERSION.SDK_INT>=33)getParcelableExtra(key,Intent::class.java)else @Suppress("DEPRECATION") getParcelableExtra(key)
