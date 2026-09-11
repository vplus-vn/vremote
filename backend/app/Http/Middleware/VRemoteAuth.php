<?php
namespace App\Http\Middleware;
use Closure;use Firebase\JWT\JWT;use Firebase\JWT\Key;use Illuminate\Http\Request;use Symfony\Component\HttpFoundation\Response;
final class VRemoteAuth{
 public function handle(Request $request,Closure $next):Response{
  if((bool)env('VREMOTE_DEV_AUTH',false)&&app()->environment(['local','testing'])){$request->attributes->set('actor',env('VREMOTE_DEV_ACTOR','dev-user'));$request->attributes->set('permissions',['edge.admin.view','edge.admin.control']);return $next($request);}
  $token=$request->bearerToken();$key=(string)env('VPLUS_USER_PUBLIC_KEY','');if(!$token||$key==='')return response()->json(['error'=>['code'=>'UNAUTHENTICATED','message'=>'Valid Vplus bearer token required']],401);
  try{$claims=(array)JWT::decode($token,new Key($key,(string)env('VPLUS_JWT_ALG','RS256')));}catch(\Throwable){return response()->json(['error'=>['code'=>'UNAUTHENTICATED','message'=>'Bearer token invalid']],401);}
  $actor=(string)($claims['sub']??'');$permissions=$claims['permissions']??[];if(!is_array($permissions))$permissions=array_filter(array_map('trim',explode(',',(string)$permissions)));if($actor===''||!$permissions)return response()->json(['error'=>['code'=>'FORBIDDEN','message'=>'Vplus permission claims missing']],403);
  $request->attributes->set('actor',$actor);$request->attributes->set('permissions',$permissions);$request->attributes->set('tenantId',$claims['tenantId']??null);return $next($request);
 }
}
