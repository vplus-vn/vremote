<?php

declare(strict_types=1);

namespace App\Infrastructure\Platform;

use App\Domain\Contracts\PlatformClient;
use App\Domain\Platform\PlatformContext;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Production Platform adapter (DSG-VREMOTE-002 §4) for an OIDC/OAuth2 platform.
 *
 * Per the platform contract (sab-com-vn/platform-service: RS256 JWTs, discovery at
 * `/.well-known/openid-configuration`), VRemote verifies the caller's bearer token
 * against the platform's JWKS (discovered from VREMOTE_PLATFORM_URL) and maps the
 * claims to a PlatformContext. The discovery document and JWKS are cached.
 *
 * Claim mapping (via PlatformContext::fromClaims): `sub` -> userId, plus `tenantId`,
 * `permissions`, `roles`, `scopes`. If the platform names these differently, set the
 * VREMOTE_CLAIM_* overrides (see config) — confirm against auth.service.ts.
 */
final class OidcPlatformClient implements PlatformClient
{
    public function __construct(
        private readonly string $platformUrl,
        private readonly string $algorithm = 'RS256',
        private readonly int $cacheTtlSeconds = 3600,
    ) {
    }

    public function verify(string $bearerToken): ?PlatformContext
    {
        if ($this->platformUrl === '' || $bearerToken === '') {
            return null;
        }

        $keys = $this->keySet();
        if ($keys === null) {
            return null;
        }

        try {
            $claims = (array) JWT::decode($bearerToken, $keys);
        } catch (\Throwable) {
            return null;
        }

        $context = PlatformContext::fromClaims($claims);

        return $context->userId !== '' ? $context : null;
    }

    /**
     * Fetch + cache the platform JWKS as a set of Firebase\JWT\Key, discovered via the
     * OIDC `.well-known/openid-configuration` document.
     *
     * @return array<string,\Firebase\JWT\Key>|null
     */
    private function keySet(): ?array
    {
        $jwks = Cache::remember('vremote:platform:jwks', $this->cacheTtlSeconds, function (): ?array {
            $base = rtrim($this->platformUrl, '/');
            try {
                $discovery = Http::acceptJson()->timeout(5)->get($base . '/.well-known/openid-configuration');
                $jwksUri = $discovery->successful() ? (string) ($discovery->json('jwks_uri') ?? '') : '';
                if ($jwksUri === '') {
                    $jwksUri = $base . '/.well-known/jwks.json';
                }
                $jwksResponse = Http::acceptJson()->timeout(5)->get($jwksUri);
                $jwks = $jwksResponse->successful() ? $jwksResponse->json() : null;

                return is_array($jwks) && isset($jwks['keys']) ? $jwks : null;
            } catch (\Throwable) {
                return null;
            }
        });

        if (! is_array($jwks) || ! isset($jwks['keys'])) {
            return null;
        }

        try {
            return JWK::parseKeySet($jwks, $this->algorithm);
        } catch (\Throwable) {
            return null;
        }
    }
}
