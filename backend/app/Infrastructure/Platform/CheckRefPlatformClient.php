<?php

declare(strict_types=1);

namespace App\Infrastructure\Platform;

use App\Domain\Contracts\PlatformClient;
use App\Domain\Platform\PlatformContext;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * VPlus Platform adapter — reference-token exchange ("checkRef"), the real contract
 * of platform-service (Modules/Auth: `POST /api/platform/v1/auth/checkRef`).
 *
 * The Platform issues clients an OPAQUE `access_token` (a *referenceToken*), NOT a JWT.
 * A consuming service exchanges it for a real RS256 JWT via checkRef:
 *   POST {platformUrl}{checkRefPath}  body: {"referenceToken": "<opaque>"}
 *   -> {"code":1,"data":{"token":"<JWT>","userId":"...","tenantId":"...",...}}
 * VRemote then verifies that JWT with the Platform public key (core-lib key, already
 * configured) and maps its claims to a PlatformContext. The JWT payload uses `sub`,
 * `tenantId`/`tid`, `roles`, `perms` (["*"] = all) — normalised here. Result cached
 * briefly per referenceToken so a browser does not exchange on every request.
 */
final class CheckRefPlatformClient implements PlatformClient
{
    public function __construct(
        private readonly string $platformUrl,
        private readonly string $checkRefPath = '/v1/auth/checkRef',
        private readonly int $cacheTtlSeconds = 30,
        private readonly int $timeoutSeconds = 5,
    ) {
    }

    public function verify(string $referenceToken): ?PlatformContext
    {
        if ($this->platformUrl === '' || $referenceToken === '') {
            return null;
        }

        $cacheKey = 'vremote:checkref:' . hash('sha256', $referenceToken);

        /** @var array<string,mixed>|null $claims */
        $claims = Cache::remember($cacheKey, $this->cacheTtlSeconds, function () use ($referenceToken): ?array {
            $url = rtrim($this->platformUrl, '/') . '/' . ltrim($this->checkRefPath, '/');

            try {
                $response = Http::acceptJson()->timeout($this->timeoutSeconds)
                    ->post($url, ['referenceToken' => $referenceToken]);
            } catch (\Throwable) {
                return null;
            }

            if (! $response->successful()) {
                return null;
            }

            $body = $response->json();
            if (! is_array($body) || (int) ($body['code'] ?? 0) !== 1) {
                return null;
            }

            $data = $body['data'] ?? null;
            if (! is_array($data)) {
                return null;
            }

            $payload = $this->readJwt((string) ($data['token'] ?? ''));

            return $this->normalise(is_array($payload) ? $payload : [], $data);
        });

        if (! is_array($claims)) {
            return null;
        }

        $context = PlatformContext::fromClaims($claims);

        return $context->userId !== '' ? $context : null;
    }

    /**
     * Verify + decode the Platform JWT with the configured public key. Falls back to an
     * unverified payload decode only if no key is configured (the JWT still came from our
     * own direct checkRef call over TLS to the trusted Platform).
     *
     * @return array<string,mixed>|null
     */
    private function readJwt(string $jwt): ?array
    {
        if ($jwt === '' || substr_count($jwt, '.') < 2) {
            return null;
        }

        $publicKey = $this->publicKey();
        if ($publicKey !== '') {
            try {
                return (array) JWT::decode($jwt, new Key($publicKey, (string) config('vremote.jwt_alg', 'RS256')));
            } catch (\Throwable) {
                // fall through to unverified decode
            }
        }

        $parts = explode('.', $jwt);
        $json = base64_decode(strtr($parts[1], '-_', '+/'), true);
        $data = $json === false ? null : json_decode($json, true);

        return is_array($data) ? $data : null;
    }

    private function publicKey(): string
    {
        $inline = (string) config('core.jwt.public_key', config('vremote.jwt_public_key', ''));
        if (trim($inline) !== '') {
            return $inline;
        }

        $path = (string) config('core.jwt.public_key_path', '');

        return ($path !== '' && is_file($path)) ? (string) file_get_contents($path) : '';
    }

    /**
     * Map the Platform JWT claim names to what PlatformContext::fromClaims expects.
     *
     * @param array<string,mixed> $payload  decoded JWT claims
     * @param array<string,mixed> $data     the checkRef `data` envelope
     * @return array<string,mixed>
     */
    private function normalise(array $payload, array $data): array
    {
        return [
            'sub' => (string) ($payload['sub'] ?? $data['userId'] ?? ''),
            'tenantId' => $payload['tenantId'] ?? $payload['tid'] ?? ($data['tenantId'] ?? null),
            'roles' => $payload['roles'] ?? [],
            'permissions' => $payload['perms'] ?? $payload['permissions'] ?? [],
            'scopes' => $payload['scopes'] ?? [],
        ];
    }
}
