package vn.vplus.vremote.agent

import android.app.Activity
import android.content.Intent
import android.media.projection.MediaProjectionManager
import android.os.Bundle
import android.widget.Button
import android.widget.EditText
import android.widget.LinearLayout
import android.widget.TextView

class MainActivity : Activity() {
    private val requestCode = 7001
    private lateinit var deviceId: EditText
    private lateinit var deviceToken: EditText
    private lateinit var signalingUrl: EditText

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        val p=getSharedPreferences("vremote",MODE_PRIVATE)
        deviceId=EditText(this).apply{hint="Device ID";setText(p.getString("deviceId","DEV-001"))}
        deviceToken=EditText(this).apply{hint="Device token";setText(p.getString("deviceToken",""))}
        signalingUrl=EditText(this).apply{hint="Signaling URL";setText(p.getString("signalingUrl","ws://10.0.2.2:8787/agent"))}
        val button=Button(this).apply{text="Enable VRemote Agent";setOnClickListener{saveAndRequest()}}
        setContentView(LinearLayout(this).apply{orientation=LinearLayout.VERTICAL;setPadding(32,48,32,32);addView(TextView(context).apply{text="VRemote Agent — screen is OFF until a signed session.enable"});addView(deviceId);addView(deviceToken);addView(signalingUrl);addView(button)})
    }
    private fun saveAndRequest(){
        getSharedPreferences("vremote",MODE_PRIVATE).edit().putString("deviceId",deviceId.text.toString()).putString("deviceToken",deviceToken.text.toString()).putString("signalingUrl",signalingUrl.text.toString()).apply()
        val mgr=getSystemService(MediaProjectionManager::class.java); startActivityForResult(mgr.createScreenCaptureIntent(),requestCode)
    }
    @Deprecated("Android callback API used for broad device compatibility")
    override fun onActivityResult(requestCode:Int,resultCode:Int,data:Intent?){super.onActivityResult(requestCode,resultCode,data);if(requestCode==this.requestCode&&resultCode==RESULT_OK&&data!=null){val i=Intent(this,RemoteAgentService::class.java).putExtra(RemoteAgentService.EXTRA_RESULT_CODE,resultCode).putExtra(RemoteAgentService.EXTRA_DATA,data);startForegroundService(i)}}
}
