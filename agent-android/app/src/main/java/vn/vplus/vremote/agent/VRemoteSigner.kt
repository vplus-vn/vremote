package vn.vplus.vremote.agent

import android.util.Base64
import java.nio.charset.StandardCharsets
import javax.crypto.Mac
import javax.crypto.spec.SecretKeySpec

object VRemoteSigner {
    fun canonicalSessionEnable(deviceId:String, sessionId:String, capability:String, ttlSec:Int, nonce:String, ts:Long) = listOf(deviceId,sessionId,capability,ttlSec.toString(),nonce,ts.toString()).joinToString("\n")
    fun canonicalCommand(deviceId:String, commandId:String, type:String, argsHash:String, nonce:String, ts:Long) = listOf(deviceId,commandId,type,argsHash,nonce,ts.toString()).joinToString("\n")
    fun argsHash(argsJson:String?):String = argsJson?.toByteArray(StandardCharsets.UTF_8)?.let { java.security.MessageDigest.getInstance("SHA-256").digest(it).joinToString("") { b -> "%02x".format(b) } } ?: ""
    fun sign(deviceToken:String, canonical:String):String { require(deviceToken.isNotEmpty()); val mac=Mac.getInstance("HmacSHA256"); mac.init(SecretKeySpec(deviceToken.toByteArray(StandardCharsets.UTF_8),"HmacSHA256")); return Base64.encodeToString(mac.doFinal(canonical.toByteArray(StandardCharsets.UTF_8)),Base64.NO_WRAP) }
    fun verify(deviceToken:String, canonical:String, signature:String):Boolean { val expected=sign(deviceToken,canonical); return java.security.MessageDigest.isEqual(expected.toByteArray(StandardCharsets.UTF_8),signature.toByteArray(StandardCharsets.UTF_8)) }
}
