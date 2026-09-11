<?php

declare(strict_types=1);
namespace App\Http\Controllers;
use App\Services\AuditStore;use App\Services\DeviceRegistry;use App\Services\SessionStore;use App\Services\SessionTokenService;use App\Services\TurnCredentialService;use App\Services\VRemoteSigningService;use Illuminate\Http\JsonResponse;use Illuminate\Http\Request;use Illuminate\Support\Facades\Http;use Illuminate\Support\Str;
final class IntegrationController{
 public function store(Request $r,DeviceRegistry $registry,SessionStore $sessions,SessionTokenService $tokens,VRemoteSigningService $signer,TurnCredentialService $turn,AuditStore $audit):JsonResponse{
  $d=$r->validate(['machineCode'=>['nullable','string','max:128'],'deviceId'=>['nullable','string','max:128'],'capability'=>['required','in:view,control'],'productId'=>['nullable','string','max:64']]);
  $deviceId=$d['deviceId']??null;if(!$deviceId&&isset($d['machineCode']))foreach($registry->all() as $id=>$device)if(($device['machineCode']??null)===$d['machineCode']){$deviceId=$id;break;}
  if(!$deviceId)return response()->json(['error'=>['code'=>'NOT_FOUND','message'=>'machineCode/deviceId could not be mapped']],404);
  $device=$registry->get($deviceId);if(!$device)return response()->json(['error'=>['code'=>'NOT_FOUND','message'=>'Device not found']],404);if(($device['status']??'offline')!=='online')return response()->json(['error'=>['code'=>'DEVICE_OFFLINE','message'=>'Device is offline']],503);
  $perm=$d['capability']==='view'?'edge.admin.view':'edge.admin.control';if(!in_array($perm,(array)$r->attributes->get('permissions',[]),true))return response()->json(['error'=>['code'=>'FORBIDDEN','message'=>$perm.' required']],403);
  if(empty($device['deviceToken']))return response()->json(['error'=>['code'=>'DEVICE_NOT_PROVISIONED','message'=>'Device signing key unavailable']],503);
  $ttl=min((int)config('vremote.session_ttl',1800),900);$sid='S-'.Str::upper(Str::random(12));$nonce=bin2hex(random_bytes(16));$ts=time();$sig=$signer->sign((string)$device['deviceToken'],$signer->canonicalSessionEnable(['deviceId'=>$deviceId,'sessionId'=>$sid,'capability'=>$d['capability'],'ttlSec'=>$ttl,'nonce'=>$nonce,'ts'=>$ts]));
  $sessions->put(['sessionId'=>$sid,'deviceId'=>$deviceId,'capability'=>$d['capability'],'ttlSec'=>$ttl,'createdAt'=>now()->toIso8601String(),'actor'=>$r->attributes->get('actor','unknown'),'source'=>'integration:'.($d['productId']??'unknown')]);
  try{$ok=Http::withHeader('X-VRemote-Internal-Secret',(string)config('vremote.signaling_internal_secret'))->timeout(5)->post(rtrim((string)config('vremote.signaling_internal_url'),'/').'/agent/send',['type'=>'session.enable','deviceId'=>$deviceId,'sessionId'=>$sid,'capability'=>$d['capability'],'ttlSec'=>$ttl,'nonce'=>$nonce,'ts'=>$ts,'sig'=>$sig])->successful();}catch(\Throwable){$ok=false;}
  if(!$ok){$sessions->forget($sid);return response()->json(['error'=>['code'=>'DEVICE_UNREACHABLE','message'=>'Agent signaling channel unavailable']],503);}
  $ice=config('vremote.ice_servers',[]);$tc=$turn->forSession($deviceId,$ttl);if($tc)$ice[]=$tc;$token=$tokens->issue($sid,$deviceId,$d['capability'],$ttl);$audit->append(['actor'=>$r->attributes->get('actor','unknown'),'action'=>'integration.session.open','deviceId'=>$deviceId,'sessionId'=>$sid,'capability'=>$d['capability'],'source'=>'integration:'.($d['productId']??'unknown'),'result'=>'accepted']);
  return response()->json(['embedToken'=>$token,'viewerUrl'=>rtrim((string)config('vremote.viewer_url'),'/').'/embed?token='.rawurlencode($token),'deviceId'=>$deviceId,'capability'=>$d['capability'],'ttlSec'=>$ttl,'signalingUrl'=>config('vremote.signaling_url'),'iceServers'=>$ice],201);
 }
}
