<?php

declare(strict_types=1);
namespace App\Http\Controllers;
use App\Services\DeviceRegistry; use App\Services\SessionStore; use App\Services\SessionTokenService; use App\Services\TurnCredentialService; use App\Services\VRemoteSigningService;
use Illuminate\Http\JsonResponse; use Illuminate\Http\Request; use Illuminate\Support\Facades\Http; use Illuminate\Support\Str;
final class SessionController {
 public function store(Request $request,DeviceRegistry $registry,SessionStore $store,SessionTokenService $tokens,VRemoteSigningService $signer,TurnCredentialService $turn):JsonResponse{
  $data=$request->validate(['deviceId'=>['required','string','max:128'],'capability'=>['required','in:view,control'],'preferredMaxFps'=>['nullable','integer','min:1','max:60']]);
  if($data['capability']==='view'&&!$this->can($request,'edge.admin.view'))return response()->json(['error'=>['code'=>'FORBIDDEN','message'=>'edge.admin.view required']],403);
  if($data['capability']==='control'&&!$this->can($request,'edge.admin.control'))return response()->json(['error'=>['code'=>'FORBIDDEN','message'=>'edge.admin.control required']],403);
  $device=$registry->get($data['deviceId']);if(!$device)return response()->json(['error'=>['code'=>'NOT_FOUND','message'=>'Device not found']],404);if(($device['status']??'offline')!=='online')return response()->json(['error'=>['code'=>'DEVICE_OFFLINE','message'=>'Device is offline']],503);
  $ttl=(int)config('vremote.session_ttl',1800);$sessionId='S-'.Str::upper(Str::random(12));$nonce=bin2hex(random_bytes(16));$ts=time();$sig=$signer->sign((string)($device['deviceToken']??''),$signer->canonicalSessionEnable(['deviceId'=>$data['deviceId'],'sessionId'=>$sessionId,'capability'=>$data['capability'],'ttlSec'=>$ttl,'nonce'=>$nonce,'ts'=>$ts]));
  $store->put(['sessionId'=>$sessionId,'deviceId'=>$data['deviceId'],'capability'=>$data['capability'],'ttlSec'=>$ttl,'createdAt'=>now()->toIso8601String(),'actor'=>$request->attributes->get('actor','unknown')]);
  if(!$this->sendAgent(['type'=>'session.enable','deviceId'=>$data['deviceId'],'sessionId'=>$sessionId,'capability'=>$data['capability'],'ttlSec'=>$ttl,'nonce'=>$nonce,'ts'=>$ts,'sig'=>$sig])){$store->forget($sessionId);return response()->json(['error'=>['code'=>'DEVICE_UNREACHABLE','message'=>'Agent signaling channel unavailable']],503);}
  $ice=config('vremote.ice_servers',[]);$t=$turn->forSession($data['deviceId'],$ttl);if($t)$ice[]=$t;
  return response()->json(['sessionId'=>$sessionId,'capability'=>$data['capability'],'signalingUrl'=>config('vremote.signaling_url'),'sessionToken'=>$tokens->issue($sessionId,$data['deviceId'],$data['capability'],$ttl),'iceServers'=>$ice,'ttlSec'=>$ttl],201);
 }
 public function destroy(string $sessionId,SessionStore $store):JsonResponse{$session=$store->get($sessionId);if(!$session)return response()->json(null,404);$store->forget($sessionId);return response()->json(null,204);}
 private function can(Request $r,string $p):bool{return in_array($p,(array)$r->attributes->get('permissions',[]),true);}
 private function sendAgent(array $m):bool{try{return Http::withHeader('X-VRemote-Internal-Secret',(string)config('vremote.signaling_internal_secret'))->timeout(5)->post(rtrim((string)config('vremote.signaling_internal_url'),'/').'/agent/send',$m)->successful();}catch(\Throwable){return false;}}
}
