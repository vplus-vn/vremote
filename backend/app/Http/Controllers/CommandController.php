<?php

declare(strict_types=1);
namespace App\Http\Controllers;
use App\Application\Authorization\AuthorizationService;use App\Domain\Platform\PlatformContext;use App\Services\AuditStore;use App\Services\CommandStore;use App\Services\DeviceRegistry;use App\Services\VRemoteSigningService;use Illuminate\Http\JsonResponse;use Illuminate\Http\Request;use Illuminate\Support\Facades\Http;use Illuminate\Support\Str;
final class CommandController{
 public function store(Request $r,string $deviceId,DeviceRegistry $registry,VRemoteSigningService $signer,CommandStore $commands,AuditStore $audit,AuthorizationService $authz):JsonResponse{
  $data=$r->validate(['type'=>['required','in:health,version,ping,logs,config-read,restart-app,reboot,config-push,apk-update,cache-clear,remote.enable,remote.disable'],'args'=>['nullable','array'],'commandId'=>['nullable','string','max:128'],'confirm'=>['nullable','boolean']]);
  $type=$data['type'];$safe=in_array($type,['health','version','ping','logs','config-read'],true);$perm=$safe?'edge.admin.view':'edge.admin.control';
  if((bool)config('vremote.enforce_tenant',false)){$ctx=$r->attributes->get('platformContext');if(!$ctx instanceof PlatformContext||!$authz->canActOnDevice($ctx,$deviceId,$perm))return response()->json(['error'=>['code'=>'FORBIDDEN','message'=>'not authorized for this device ('.$perm.')']],403);}
  elseif(!in_array($perm,(array)$r->attributes->get('permissions',[]),true))return response()->json(['error'=>['code'=>'FORBIDDEN','message'=>$perm.' required']],403);
  $d=$registry->get($deviceId);if(!$d)return response()->json(['error'=>['code'=>'NOT_FOUND','message'=>'Device not found']],404);if(($d['status']??'offline')!=='online')return response()->json(['error'=>['code'=>'DEVICE_OFFLINE','message'=>'Device is offline']],503);if(empty($d['deviceToken']))return response()->json(['error'=>['code'=>'DEVICE_NOT_PROVISIONED','message'=>'Device signing key unavailable']],503);
  $gated=in_array($type,['reboot','config-push','apk-update','cache-clear','remote.enable','remote.disable'],true);if($gated&&!(bool)config('vremote.gated_commands',false))return response()->json(['error'=>['code'=>'GATED_OFF','message'=>'GATED operations are disabled by policy']],403);if($gated&&($data['confirm']??false)!==true)return response()->json(['error'=>['code'=>'GATED_OFF','message'=>'confirm=true required']],403);
  $id=$data['commandId']??'CMD-'.Str::upper(Str::random(12));if($old=$commands->get($id))return response()->json(['commandId'=>$id,'accepted'=>false,'result'=>'replay_detected','detail'=>'commandId already exists'],409);
  $args=$data['args']??null;$argsJson=$args===null?null:json_encode($args,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);$nonce=bin2hex(random_bytes(16));$ts=time();$hash=$signer->argsHash($argsJson);$sig=$signer->sign((string)$d['deviceToken'],$signer->canonicalCommand(['deviceId'=>$deviceId,'commandId'=>$id,'type'=>$type,'argsHash'=>$hash,'nonce'=>$nonce,'ts'=>$ts]));
  $payload=['type'=>'command','deviceId'=>$deviceId,'commandId'=>$id,'cmdType'=>$type,'args'=>$args,'nonce'=>$nonce,'ts'=>$ts,'sig'=>$sig];
  try{$ok=Http::withHeader('X-VRemote-Internal-Secret',(string)config('vremote.signaling_internal_secret'))->timeout(5)->post(rtrim((string)config('vremote.signaling_internal_url'),'/').'/agent/send',$payload)->successful();}catch(\Throwable){$ok=false;}
  if(!$ok)return response()->json(['error'=>['code'=>'DEVICE_UNREACHABLE','message'=>'Agent channel unavailable']],503);
  $commands->put(['commandId'=>$id,'deviceId'=>$deviceId,'type'=>$type,'argsHash'=>$hash,'actor'=>$r->attributes->get('actor','unknown'),'acceptedAt'=>now()->toIso8601String(),'result'=>'accepted']);$audit->append(['actor'=>$r->attributes->get('actor','unknown'),'action'=>'command.send','deviceId'=>$deviceId,'commandType'=>$type,'result'=>'accepted']);
  return response()->json(['commandId'=>$id,'accepted'=>true,'result'=>'accepted'],202);
 }
}
