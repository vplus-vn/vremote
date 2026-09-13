<?php

declare(strict_types=1);
namespace App\Http\Controllers;
use App\Application\Authorization\AuthorizationService;use App\Application\Pairing\PairingService;use App\Domain\Contracts\DeviceRepository;use App\Domain\Platform\PlatformContext;use App\Services\AuditStore;use App\Services\SessionStore;use App\Services\SessionTokenService;use App\Services\TurnCredentialService;use App\Services\VRemoteSigningService;use Illuminate\Http\JsonResponse;use Illuminate\Http\Request;use Illuminate\Support\Facades\Http;use Illuminate\Support\Str;
final class SessionController {
 public function store(Request $request,DeviceRepository $registry,SessionStore $store,SessionTokenService $tokens,VRemoteSigningService $signer,TurnCredentialService $turn,AuditStore $audit,AuthorizationService $authz,PairingService $pairing):JsonResponse{
  $data=$request->validate(['deviceId'=>['required','string','max:128'],'capability'=>['required','in:view,control'],'preferredMaxFps'=>['nullable','integer','min:1','max:60'],'connectGrant'=>['nullable','string','max:128']]);$perm=$data['capability']==='view'?'edge.admin.view':'edge.admin.control';
  $device=$registry->get($data['deviceId']);if(!$device)return response()->json(['error'=>['code'=>'NOT_FOUND','message'=>'Device not found']],404);if(($device['status']??'offline')!=='online')return response()->json(['error'=>['code'=>'DEVICE_OFFLINE','message'=>'Device is offline']],503);if(empty($device['deviceToken']))return response()->json(['error'=>['code'=>'DEVICE_NOT_PROVISIONED','message'=>'Device signing key unavailable']],503);
  // Authorization splits by device kind:
  //  - Pairing-managed (UltraViewer/TeamViewer style): the one-time connect grant from
  //    POST /devices/connect IS the authorization (operator supplied the device ID + password,
  //    and the device accepted when it requires accept). Platform RBAC is NOT additionally
  //    required — a valid platform login just gates reaching this endpoint at all. The grant is
  //    bound to the connecting actor, so it can only be redeemed by the user who paired.
  //  - Enrolled/managed devices: platform RBAC (+ tenant scoping when enforced).
  if(PairingService::isPairingManaged($device)){
   if(!$pairing->redeemGrant($device,(string)$request->attributes->get('actor','unknown'),(string)($data['connectGrant']??'')))return response()->json(['error'=>['code'=>'CONNECT_REQUIRED','message'=>'Enter the device ID + password to connect first']],403);
  }elseif(($resp=$this->authorizeDevice($request,$authz,$data['deviceId'],$perm))!==null){return $resp;}
  $ttl=(int)config('vremote.session_ttl',1800);$sessionId='S-'.Str::upper(Str::random(12));$nonce=bin2hex(random_bytes(16));$ts=time();$sig=$signer->sign((string)$device['deviceToken'],$signer->canonicalSessionEnable(['deviceId'=>$data['deviceId'],'sessionId'=>$sessionId,'capability'=>$data['capability'],'ttlSec'=>$ttl,'nonce'=>$nonce,'ts'=>$ts]));
  $session=['sessionId'=>$sessionId,'deviceId'=>$data['deviceId'],'capability'=>$data['capability'],'ttlSec'=>$ttl,'createdAt'=>now()->toIso8601String(),'actor'=>$request->attributes->get('actor','unknown'),'source'=>'console'];$store->put($session);
  if(!$this->sendAgent(['type'=>'session.enable','deviceId'=>$data['deviceId'],'sessionId'=>$sessionId,'capability'=>$data['capability'],'ttlSec'=>$ttl,'nonce'=>$nonce,'ts'=>$ts,'sig'=>$sig])){$store->forget($sessionId);return response()->json(['error'=>['code'=>'DEVICE_UNREACHABLE','message'=>'Agent signaling channel unavailable']],503);}
  $ice=config('vremote.ice_servers',[]);$t=$turn->forSession($data['deviceId'],$ttl);if($t)$ice[]=$t;$audit->append(['actor'=>$session['actor'],'action'=>'session.open','deviceId'=>$data['deviceId'],'sessionId'=>$sessionId,'capability'=>$data['capability'],'source'=>'console','result'=>'accepted']);
  return response()->json(['sessionId'=>$sessionId,'capability'=>$data['capability'],'signalingUrl'=>config('vremote.signaling_url'),'sessionToken'=>$tokens->issue($sessionId,$data['deviceId'],$data['capability'],$ttl),'iceServers'=>$ice,'ttlSec'=>$ttl],201);
 }
 public function destroy(Request $request,string $sessionId,SessionStore $store,AuditStore $audit):JsonResponse{
  // Authorization: any actor holding a remote-view/control permission may close a session.
  // Full owner/tenant-scoped authorization (IDOR hardening) lands in v0.2.0 — DSG-VREMOTE-002 §5/§6.
  if(!$this->can($request,'edge.admin.view')&&!$this->can($request,'edge.admin.control'))return response()->json(['error'=>['code'=>'FORBIDDEN','message'=>'edge.admin.view required']],403);
  $s=$store->get($sessionId);if(!$s)return response()->json(null,404);
  $store->forget($sessionId);
  // Push an explicit stop to the agent so screen capture ends on close, not only at TTL expiry
  // (baseline gap H1). Best-effort: an offline agent still lets the session close.
  if(!empty($s['deviceId']))$this->sendAgent(['type'=>'session.disable','deviceId'=>$s['deviceId'],'sessionId'=>$sessionId]);
  $audit->append(['actor'=>$s['actor']??'unknown','action'=>'session.close','deviceId'=>$s['deviceId']??null,'sessionId'=>$sessionId,'capability'=>$s['capability']??null,'source'=>$s['source']??'console','result'=>'closed','endReason'=>'closed_by_actor']);
  return response()->json(null,204);
 }
 private function can(Request $r,string $p):bool{return in_array($p,(array)$r->attributes->get('permissions',[]),true);}
 // Tenant-scoped authorization (DSG-VREMOTE-002 §5). When enforce_tenant is on, the caller
 // must own the device's tenant (or hold an access grant) AND have the permission; otherwise
 // (migration default) fall back to the permission-only check to preserve current behavior.
 private function authorizeDevice(Request $r,AuthorizationService $authz,string $deviceId,string $perm):?JsonResponse{
  if((bool)config('vremote.enforce_tenant',false)){$ctx=$r->attributes->get('platformContext');if(!$ctx instanceof PlatformContext||!$authz->canActOnDevice($ctx,$deviceId,$perm))return response()->json(['error'=>['code'=>'FORBIDDEN','message'=>'not authorized for this device ('.$perm.')']],403);return null;}
  if(!$this->can($r,$perm))return response()->json(['error'=>['code'=>'FORBIDDEN','message'=>$perm.' required']],403);
  return null;
 }
 private function sendAgent(array $m):bool{try{return Http::withHeader('X-VRemote-Internal-Secret',(string)config('vremote.signaling_internal_secret'))->timeout(5)->post(rtrim((string)config('vremote.signaling_internal_url'),'/').'/agent/send',$m)->successful();}catch(\Throwable){return false;}}
}
