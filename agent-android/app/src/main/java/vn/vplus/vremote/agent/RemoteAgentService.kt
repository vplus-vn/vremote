package vn.vplus.vremote.agent

import android.app.Notification
import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.Service
import android.content.Intent
import android.os.IBinder

class RemoteAgentService : Service() {
    override fun onCreate() {
        super.onCreate()
        val manager = getSystemService(NotificationManager::class.java)
        manager.createNotificationChannel(NotificationChannel(CHANNEL, "VRemote", NotificationManager.IMPORTANCE_LOW))
        startForeground(1, Notification.Builder(this, CHANNEL).setContentTitle("VRemote Agent").setContentText("Screen sharing disabled until authorized session").setSmallIcon(android.R.drawable.ic_menu_view).build())
    }
    override fun onStartCommand(intent: Intent?, flags: Int, startId: Int): Int = START_STICKY
    override fun onBind(intent: Intent?): IBinder? = null
    companion object { private const val CHANNEL = "vremote-agent" }
}
