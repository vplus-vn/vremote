<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'vremote.auth' => App\Http\Middleware\VRemoteAuth::class,
            // v0.1.2: single Platform integration point (DSG-VREMOTE-002 §4).
            'vremote.platform' => App\Http\Middleware\ResolvePlatformContext::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Binds the default Illuminate\Contracts\Debug\ExceptionHandler.
        // Required: Application::configure() does not register the handler on
        // its own, so omitting this leaves ExceptionHandler unbound and any
        // reported exception (console or HTTP) fatals with "not instantiable".
    })
    ->create();
