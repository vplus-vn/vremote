<?php
namespace App\Http\Controllers;
use App\Services\DeviceRegistry;use App\Services\VRemoteSigningService;use Illuminate\Http\JsonResponse;use Illuminate\Http\Request;use Illuminate\Support\Facades\Http;use Illuminate\Support\Str;
final class CommandController{
 public function store(Request $r,string $deviceId,DeviceRegistry $registry,VRemoteSigningService $signer):JsonResponse{
  if(!in_array('edge.admin.control',(array)$r->attributes->get('permissions',[]),true))return response()->json(['error'=>['code'=>'FORBIDDEN','message'=>'edge.admin.control required']],403);
  $d=$registry->get($deviceId);if(!$d)return response()->json(['error'=>['code'=>'NOT_FOUND','message'=>'Device not found']],404);if(($d['status']??'offline')!=='online')return response()->json(['error'=>['code'=>'DEVICE_OFFLINE','message'=>'Device is offline']],503);
  $data=$r->validate(['type'=>['required','in:health,version,ping,logs,config-read,restart-app,reboot,config-push,apk-update,cache-clear,remote.enable,remote.disable'],'args'=>['nullable','array'],'commandId'=>['nullable','string','max:128'],'confirm'=>['nullable','boolean']]);$type=$data['type'];$gated=in_array($type,['reboot','config-push','apk-update','cache-clear','remote.enable','remote.disable'],true);if($gated&&($data['confirm']??false)!==true)return response()->json(['error'=>['code'=>'GATED_OFF','message'=>'confirm=true required']],403);
  $id=$data['commandId']??'CMD-'.Str::upper(Str::random(12));$args=$data['args']??null;$argsJson=$args===null?null:json_encode($args,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);$nonce=bin2hex(random_bytes(16));$ts=time();$sig=$signer->sign((string)$d['deviceToken'],$signer->canonicalCommand(['deviceId'=>$deviceId,'commandId'=>$id,'type'=>$type,'argsHash'=>$signer->argsHash($argsJson),'nonce'=>$nonce,'ts'=>$ts]));
  try{$ok=Http::withHeader('X-VRemote-Internal-Secret',(string)config('vremote.signaling_internal_secret'))->timeout(5)->post(rtrim((string)config('vremote.signaling_internal_url'),'/').'/agent/send',['type'=>'command','deviceId'=>$deviceId,'commandId'=>$id,'cmdType'=>$type,'args'=>$args,'nonce'=>$nonce,'ts'=>$ts,'sig'=>$sig])->successful();}catch(\Throwable){$ok=false;}
  if(!$ok)return response()->json(['error'=>['code'=>'DEVICE_UNREACHABLE','message'=>'Agent channel unavailable']],503);return response()->json(['commandId'=>$id,'accepted'=>true,'result'=>'accepted'],202);
 }
}
