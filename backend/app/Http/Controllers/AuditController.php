<?php

declare(strict_types=1);
namespace App\Http\Controllers;
use App\Services\AuditStore;use Illuminate\Http\JsonResponse;use Illuminate\Http\Request;
final class AuditController{
 public function index(Request $r,AuditStore $store):JsonResponse{
  $limit=min(max((int)$r->query('limit',50),1),200);$device=(string)$r->query('deviceId','');$actor=(string)$r->query('actor','');$from=$r->query('from');$to=$r->query('to');$items=[];
  foreach($store->all() as $e){if($device!==''&&($e['deviceId']??'')!==$device)continue;if($actor!==''&&($e['actor']??'')!==$actor)continue;$ts=(string)($e['ts']??'');if($from&&$ts<$from)continue;if($to&&$ts>$to)continue;$items[]=$e;if(count($items)>=$limit)break;}
  return response()->json(['items'=>$items,'nextCursor'=>null]);
 }
}
