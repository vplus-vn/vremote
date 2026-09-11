<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class VRemoteAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        if ((bool) env('VREMOTE_DEV_AUTH', false) && app()->environment(['local', 'testing'])) {
            $request->attributes->set('actor', env('VREMOTE_DEV_ACTOR', 'dev-user'));
            $request->attributes->set('permissions', ['edge.admin.view', 'edge.admin.control']);
            return $next($request);
        }

        $header = $request->bearerToken();
        if (!$header) return response()->json(['error' => ['code' => 'UNAUTHENTICATED', 'message' => 'Bearer token required']], 401);

        // Production integration point for vplus identity. A verified upstream JWT subject
        // and permissions may be supplied by the gateway; never trust arbitrary client headers.
        $actor = $request->header('X-VPlus-Subject');
        $permissions = array_values(array_filter(array_map('trim', explode(',', (string) $request->header('X-VPlus-Permissions')))));
        if (!$actor || !$permissions) return response()->json(['error' => ['code' => 'UNAUTHENTICATED', 'message' => 'VPlus identity context missing']], 401);
        $request->attributes->set('actor', $actor);
        $request->attributes->set('permissions', $permissions);
        return $next($request);
    }
}
