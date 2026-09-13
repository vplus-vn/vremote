<?php
use App\Http\Controllers\AuditController;use App\Http\Controllers\AuthController;use App\Http\Controllers\CommandController;use App\Http\Controllers\DeviceController;use App\Http\Controllers\EnrollmentController;use App\Http\Controllers\IntegrationController;use App\Http\Controllers\PairingController;use App\Http\Controllers\SessionController;use App\Http\Controllers\TransferController;use Illuminate\Support\Facades\Route;
// House standard (vplus-platform, as applied at sab-com-vn): every service owns
// /api/{service}/v1/{resource}. Laravel prefixes routes/api.php with `api`, so the
// `vremote/v1` group resolves to /api/vremote/v1/... and health to /api/vremote/health.
Route::get('/vremote/health',static fn()=>response()->json(['status'=>'ok','service'=>'vremote-backend']));
// Login is proxied to the Platform (no local auth) so the console authenticates through
// VRemote's own origin, like the S&B admin portal. `me` is behind the platform middleware.
Route::post('/vremote/v1/auth/login',[AuthController::class,'login']);
Route::get('/vremote/v1/auth/me',[AuthController::class,'me'])->middleware('vremote.platform');
// Device-initiated pairing (UltraViewer/TeamViewer style) — agent (device-facing) endpoints.
// No Platform token; the agent authenticates with its device credential where it matters.
Route::post('/vremote/v1/agent/announce',[PairingController::class,'announce']);
Route::get('/vremote/v1/agent/{deviceId}/pending',[PairingController::class,'pending']);
Route::post('/vremote/v1/agent/{deviceId}/respond',[PairingController::class,'respond']);
Route::post('/vremote/v1/agent/{deviceId}/policy',[PairingController::class,'policy']);
Route::prefix('vremote/v1')->middleware('vremote.platform')->group(function (): void {
 Route::get('/devices',[DeviceController::class,'index']);Route::post('/devices',[DeviceController::class,'store']);Route::get('/devices/{deviceId}',[DeviceController::class,'show']);Route::delete('/devices/{deviceId}',[DeviceController::class,'destroy']);
 Route::post('/devices/connect',[PairingController::class,'connect']);Route::get('/devices/{deviceId}/connect/{requestId}',[PairingController::class,'connectStatus']);
 Route::post('/sessions',[SessionController::class,'store']);Route::delete('/sessions/{sessionId}',[SessionController::class,'destroy']);
 Route::post('/devices/{deviceId}/commands',[CommandController::class,'store']);Route::get('/audit',[AuditController::class,'index']);Route::post('/integrations/sessions',[IntegrationController::class,'store']);
});

// v0.1.2 device-lifecycle domain endpoints (DSG-VREMOTE-002 §7) behind the Platform
// context middleware. Not part of the locked api/vremote v0.0.1 contract.
Route::prefix('vremote/v1')->middleware('vremote.platform')->group(function (): void {
 Route::post('/devices/{deviceId}/enrollments', [EnrollmentController::class, 'store']);
 Route::post('/enrollments/{enrollmentId}/consume', [EnrollmentController::class, 'consume']);
 Route::post('/devices/{deviceId}/enrollment/reset', [EnrollmentController::class, 'reset']);
 Route::post('/devices/{deviceId}/transfers', [TransferController::class, 'store']);
 Route::post('/transfers/{transferId}/approve', [TransferController::class, 'approve']);
 Route::post('/transfers/{transferId}/reject', [TransferController::class, 'reject']);
 Route::post('/transfers/{transferId}/complete', [TransferController::class, 'complete']);
});
