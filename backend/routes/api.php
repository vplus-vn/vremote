<?php
use App\Http\Controllers\AuditController;use App\Http\Controllers\CommandController;use App\Http\Controllers\DeviceController;use App\Http\Controllers\EnrollmentController;use App\Http\Controllers\IntegrationController;use App\Http\Controllers\SessionController;use App\Http\Controllers\TransferController;use Illuminate\Support\Facades\Route;
Route::get('/health',static fn()=>response()->json(['status'=>'ok','service'=>'vremote-backend']));
Route::prefix('v1')->middleware('vremote.auth')->group(function (): void {
 Route::get('/devices',[DeviceController::class,'index']);Route::get('/devices/{deviceId}',[DeviceController::class,'show']);
 Route::post('/sessions',[SessionController::class,'store']);Route::delete('/sessions/{sessionId}',[SessionController::class,'destroy']);
 Route::post('/devices/{deviceId}/commands',[CommandController::class,'store']);Route::get('/audit',[AuditController::class,'index']);Route::post('/integrations/sessions',[IntegrationController::class,'store']);
});

// v0.1.2 device-lifecycle domain endpoints (DSG-VREMOTE-002 §7) behind the Platform
// context middleware. Not part of the locked api/vremote v0.0.1 contract.
Route::prefix('v1')->middleware('vremote.platform')->group(function (): void {
 Route::post('/devices/{deviceId}/enrollments', [EnrollmentController::class, 'store']);
 Route::post('/enrollments/{enrollmentId}/consume', [EnrollmentController::class, 'consume']);
 Route::post('/devices/{deviceId}/enrollment/reset', [EnrollmentController::class, 'reset']);
 Route::post('/devices/{deviceId}/transfers', [TransferController::class, 'store']);
 Route::post('/transfers/{transferId}/approve', [TransferController::class, 'approve']);
 Route::post('/transfers/{transferId}/reject', [TransferController::class, 'reject']);
 Route::post('/transfers/{transferId}/complete', [TransferController::class, 'complete']);
});
