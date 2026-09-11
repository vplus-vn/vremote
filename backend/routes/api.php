<?php
use App\Http\Controllers\CommandController;use App\Http\Controllers\DeviceController;use App\Http\Controllers\SessionController;use Illuminate\Support\Facades\Route;
Route::prefix('v1')->middleware('vremote.auth')->group(function():void{
 Route::get('/devices',[DeviceController::class,'index']);Route::get('/devices/{deviceId}',[DeviceController::class,'show']);Route::post('/sessions',[SessionController::class,'store']);Route::delete('/sessions/{sessionId}',[SessionController::class,'destroy']);Route::post('/devices/{deviceId}/commands',[CommandController::class,'store']);
});
