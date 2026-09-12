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

        // Expand the Platform wildcard ("*") so controllers doing a direct in_array() check
        // against the `permissions` attribute (session/command) also pass for a full-access admin.
        $permissions = $context->permissions;
        if (in_array('*', $permissions, true)) {
            $permissions = array_values(array_unique(array_merge(
                $permissions,
                ['edge.admin.view', 'edge.admin.control'],
            )));
        }

        $request->attributes->set('actor', $context->userId);
        $request->attributes->set('permissions', $permissions);
        $request->attributes->set('tenantId', $context->tenantId);
        $request->attributes->set('platformContext', $context);

        return $next($request);
    }
}
