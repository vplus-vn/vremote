<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Contracts\PlatformClient;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the VPlus Platform identity context for a request (DSG-VREMOTE-002 §4).
 * Replaces the in-VRemote JWT silo (VRemoteAuth) with the single Platform integration
 * point. The concrete PlatformClient (Http in production, Fake in local/testing) is
 * bound in AppServiceProvider — there is no VREMOTE_PLATFORM_MODE toggle.
 */
final class ResolvePlatformContext
{
    public function __construct(private readonly PlatformClient $platform)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $context = $this->platform->verify((string) $request->bearerToken());

        if ($context === null || $context->userId === '') {
            return response()->json([
                'error' => ['code' => 'UNAUTHENTICATED', 'message' => 'Valid Platform token required'],
            ], 401);
        }

        $request->attributes->set('actor', $context->userId);
        $request->attributes->set('permissions', $context->permissions);
        $request->attributes->set('tenantId', $context->tenantId);
        $request->attributes->set('platformContext', $context);

        return $next($request);
    }
}
