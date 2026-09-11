<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\DeviceRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class DeviceController
{
    public function index(Request $request, DeviceRegistry $registry): JsonResponse
    {
        $status=$request->query('status','all'); abort_unless(in_array($status,['online','offline','all'],true),400);
        $limit=min(max((int)$request->query('limit',50),1),100); $items=[];
        foreach($registry->all() as $id=>$d){ $d=is_array($d)?$d:[]; $s=$d['status']??'offline'; if($status!=='all'&&$s!==$status) continue; $items[]=['deviceId'=>$id,'machineCode'=>$d['machineCode']??null,'name'=>$d['name']??null,'platform'=>$d['platform']??'android','status'=>$s,'agentVersion'=>$d['agentVersion']??null,'lastSeenAt'=>$d['lastSeenAt']??null,'activeSessions'=>$d['activeSessions']??0]; if(count($items)>=$limit)break; }
        return response()->json(['items'=>$items,'nextCursor'=>null]);
    }

    public function show(string $deviceId, DeviceRegistry $registry): JsonResponse
    {
        $d=$registry->get($deviceId); if(!$d) return response()->json(['error'=>['code'=>'NOT_FOUND','message'=>'Device not found']],404);
        unset($d['deviceToken']); return response()->json(['deviceId'=>$deviceId]+$d);
    }
}
