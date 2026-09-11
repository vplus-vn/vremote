package vn.vplus.vremote.agent

import android.app.Activity
import android.content.Intent
import android.media.projection.MediaProjectionManager
import android.os.Bundle
import android.widget.*

class MainActivity:Activity(){private val requestCode=7001;private lateinit var deviceId:EditText;private lateinit var deviceToken:EditText;private lateinit var signalingUrl:EditText;private lateinit var turnUrl:EditText;private lateinit var turnSecret:EditText
 override fun onCreate(b:Bundle?){super.onCreate(b);val p=getSharedPreferences("vremote",MODE_PRIVATE);deviceId=field("Device ID",p.getString("deviceId","DEV-001"));deviceToken=field("Device token",p.getString("deviceToken",""));signalingUrl=field("Signaling URL",p.getString("signalingUrl","ws://10.0.2.2:8787/agent"));turnUrl=field("TURN URL",p.getString("turnUrl","turn:10.0.2.2:3478"));turnSecret=field("TURN shared secret",p.getString("turnSecret",""));val button=Button(this).apply{text="Enable VRemote Agent";setOnClickListener{saveAndRequest()}};setContentView(LinearLayout(this).apply{orientation=LinearLayout.VERTICAL;setPadding(32,48,32,32);addView(TextView(context).apply{text="VRemote Agent — screen is OFF until a signed session.enable"});listOf(deviceId,deviceToken,signalingUrl,turnUrl,turnSecret,button).forEach{addView(it)}})}
 private fun field(h:String,v:String?)=EditText(this).apply{hint=h;setText(v?:"")}
 private fun saveAndRequest(){getSharedPreferences("vremote",MODE_PRIVATE).edit().putString("deviceId",deviceId.text.toString()).putString("deviceToken",deviceToken.text.toString()).putString("signalingUrl",signalingUrl.text.toString()).putString("turnUrl",turnUrl.text.toString()).putString("turnSecret",turnSecret.text.toString()).apply();startActivityForResult(getSystemService(MediaProjectionManager::class.java).createScreenCaptureIntent(),requestCode)}
 @Deprecated("Android callback API") override fun onActivityResult(r:Int,c:Int,d:Intent?){super.onActivityResult(r,c,d);if(r==requestCode&&c==RESULT_OK&&d!=null){startForegroundService(Intent(this,RemoteAgentService::class.java).putExtra(RemoteAgentService.EXTRA_RESULT_CODE,c).putExtra(RemoteAgentService.EXTRA_DATA,d))}}
}
