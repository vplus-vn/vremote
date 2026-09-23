<?php

use App\Http\Middleware\ApiEnvelope;
use App\Http\Middleware\ResolvePlatformContext;
use App\Http\Support\ApiError;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Platform\Core\Http\Middleware\CorrelationIdMiddleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            // v0.1.2: single Platform integration point (DSG-VREMOTE-002 §4).
            'vremote.platform' => ResolvePlatformContext::class,
        ]);
        // [v0.1.1] Chuẩn hoá core-lib/API v1 — X-Correlation-Id (core-lib) cho mọi request API và
        // envelope chuẩn cho /api/vremote/v1/* (opt-in qua X-Api-Envelope, xem ApiEnvelope) (2026-09-23)
        $middleware->api(prepend: [CorrelationIdMiddleware::class], append: [ApiEnvelope::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Binds the default Illuminate\Contracts\Debug\ExceptionHandler.
        // Required: Application::configure() does not register the handler on
        // its own, so omitting this leaves ExceptionHandler unbound and any
        // reported exception (console or HTTP) fatals with "not instantiable".

        // [v0.1.1] Chuẩn hoá API v1 — mọi lỗi dưới /api/* (validation 422, 404/405, 500...) trả
        // envelope lỗi chuẩn {error:{code,message,details?}} thay cho shape mặc định Laravel (2026-09-23)
        $exceptions->render(static function (Throwable $e, Request $request) {
            return $request->is('api/*') ? ApiError::fromThrowable($e) : null;
        });
    })
    ->create();
