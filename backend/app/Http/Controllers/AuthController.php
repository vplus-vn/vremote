<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Platform\PlatformContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * Auth proxy so the VRemote console authenticates through VRemote's own origin
 * (like the S&B admin portal packages login in), instead of talking cross-domain
 * to the Platform. `login` forwards credentials to the Platform login endpoint and
 * returns its response verbatim (the console stores whatever token it issues).
 * `me` echoes the identity VRemote resolved from the bearer token (via the bound
 * PlatformClient) so the console can render the signed-in user and gate the UI.
 */
final class AuthController
{
    /** POST /api/vremote/v1/auth/login — proxy to the Platform, no local auth. */
    public function login(Request $request): JsonResponse
    {
        $base = (string) config('vremote.platform_url', '');
        if ($base === '') {
            return response()->json([
                'error' => ['code' => 'PLATFORM_UNCONFIGURED', 'message' => 'VREMOTE_PLATFORM_URL is not set'],
            ], 503);
        }

        $url = rtrim($base, '/') . '/' . ltrim((string) config('vremote.platform_login_path', '/v1/auth/login'), '/');

        try {
            $response = Http::acceptJson()->timeout(10)->post($url, $request->all());
        } catch (\Throwable) {
            return response()->json([
                'error' => ['code' => 'PLATFORM_UNREACHABLE', 'message' => 'Platform login is unavailable'],
            ], 502);
        }

        $body = $response->json();

        return response()->json(is_array($body) ? $body : ['raw' => $response->body()], $response->status());
    }

    /** GET /api/vremote/v1/auth/me — behind the platform middleware; returns resolved identity. */
    public function me(Request $request): JsonResponse
    {
        $ctx = $request->attributes->get('platformContext');
        if (! $ctx instanceof PlatformContext) {
            return response()->json([
                'error' => ['code' => 'UNAUTHENTICATED', 'message' => 'Valid Platform token required'],
            ], 401);
        }

        return response()->json([
            'userId' => $ctx->userId,
            'tenantId' => $ctx->tenantId,
            'roles' => $ctx->roles,
            'permissions' => $ctx->permissions,
            'scopes' => $ctx->scopes,
        ]);
    }
}
