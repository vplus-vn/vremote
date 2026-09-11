<?php

declare(strict_types=1);

namespace App\Infrastructure\Platform;

use App\Domain\Contracts\PlatformClient;
use App\Domain\Platform\PlatformContext;
use Illuminate\Support\Facades\Http;

/**
 * Production Platform adapter (DSG-VREMOTE-002 §4). Verifies the caller's bearer token
 * against the shared VPlus Platform at VREMOTE_PLATFORM_URL and maps the returned claims
 * to a PlatformContext. VRemote stores no users/tenants/roles of its own.
 *
 * Contract (minimal): POST {platformUrl}{verifyPath} with `Authorization: Bearer <token>`.
 * A 2xx JSON body carrying userId/tenantId/roles/permissions/scopes (or `sub`) is a valid
 * identity; anything else is treated as unauthenticated.
 */
final class HttpPlatformClient implements PlatformClient
{
    public function __construct(
        private readonly string $platformUrl,
        private readonly string $verifyPath = '/api/identity/verify',
        private readonly int $timeoutSeconds = 5,
    ) {
    }

    public function verify(string $bearerToken): ?PlatformContext
    {
        if ($this->platformUrl === '' || $bearerToken === '') {
            return null;
        }

        $url = rtrim($this->platformUrl, '/') . $this->verifyPath;

        try {
            $response = Http::withToken($bearerToken)
                ->acceptJson()
                ->timeout($this->timeoutSeconds)
                ->get($url);
        } catch (\Throwable) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $claims = $response->json();
        if (! is_array($claims)) {
            return null;
        }

        // Some platforms wrap identity under a key (e.g. {"data": {...}}).
        if (isset($claims['data']) && is_array($claims['data'])) {
            $claims = $claims['data'];
        }

        $context = PlatformContext::fromClaims($claims);
        if ($context->userId === '') {
            return null;
        }

        return $context;
    }
}
