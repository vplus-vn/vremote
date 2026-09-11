<?php

declare(strict_types=1);

namespace App\Infrastructure\Platform;

use App\Domain\Contracts\PlatformClient;
use App\Domain\Platform\PlatformContext;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

/**
 * Production Platform adapter that verifies a VPlus Platform-issued JWT with the
 * platform's public key (DSG-VREMOTE-002 §4). This is the same verification the
 * baseline VRemoteAuth middleware did, refactored behind the PlatformClient seam so
 * VRemote keeps no auth silo — the token is minted by the Platform, VRemote only
 * verifies it and maps claims to a PlatformContext.
 *
 * Claims: `sub` -> userId, `tenantId`, `permissions` (array or CSV), plus optional
 * `roles`/`scopes`. The key/alg come from config (config:cache-safe).
 */
final class JwtPlatformClient implements PlatformClient
{
    public function __construct(
        private readonly string $publicKey,
        private readonly string $algorithm = 'RS256',
    ) {
    }

    public function verify(string $bearerToken): ?PlatformContext
    {
        if ($bearerToken === '' || $this->publicKey === '') {
            return null;
        }

        try {
            $claims = (array) JWT::decode($bearerToken, new Key($this->publicKey, $this->algorithm));
        } catch (\Throwable) {
            return null;
        }

        $context = PlatformContext::fromClaims($claims);

        return $context->userId !== '' ? $context : null;
    }
}
