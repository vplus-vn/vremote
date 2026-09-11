<?php
namespace App\Services;
final class TurnCredentialService
{
    public function forSession(string $deviceId,int $ttl): array
    {
        $secret=(string)env('VREMOTE_TURN_SHARED_SECRET',''); if($secret==='') return [];
        $expires=time()+min($ttl,3600); $username=$expires.':'.$deviceId;
        $credential=base64_encode(hash_hmac('sha1',$username,$secret,true));
        return ['urls'=>array_values(array_filter(array_map('trim',explode(',',(string)env('VREMOTE_TURN_URLS',''))))),'username'=>$username,'credential'=>$credential];
    }
}
