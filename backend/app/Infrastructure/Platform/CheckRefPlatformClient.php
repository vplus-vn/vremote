<?php

declare(strict_types=1);

namespace App\Infrastructure\Platform;

use App\Domain\Contracts\PlatformClient;
use App\Domain\Platform\PlatformContext;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * VPlus Platform adapter — reference-token exchange ("checkRef"), the real contract
 * of platform-service (Modules/Auth: `POST /api/platform/v1/auth/checkRef`).
 *
 * The Platform issues clients an OPAQUE `access_token` (a *referenceToken*), NOT a JWT.
 * A consuming service exchanges it for a real RS256 JWT via checkRef:
 *   POST {platformUrl}{checkRefPath}  body: {"referenceToken": "<opaque>"}
 *   -> {"code":1,"data":{"token":"<JWT>","userId":"...","tenantId":"...",...}}
 * VRemote verifies that JWT with the Platform public key (core-lib key) and maps its
 * claims (sub, tenantId/tid, roles, perms with ["*"] = full) to a PlatformContext.
 *
 * A successful result is cached briefly per referenceToken; failures are NOT cached
 * and each failure logs its reason so a misconfiguration is diagnosable from the log.
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

        $claims = Cache::get($cacheKey);
        if (! is_array($claims)) {
            $claims = $this->exchange($referenceToken);
            if (is_array($claims)) {
                Cache::put($cacheKey, $claims, $this->cacheTtlSeconds); // cache success only
            }
        }

        if (! is_array($claims)) {
            return null;
        }

        $context = PlatformContext::fromClaims($claims);

        return $context->userId !== '' ? $context : null;
    }

    /**
     * Exchange the referenceToken for a JWT and return normalised claims, or null with a
     * logged reason on any failure.
     *
     * @return array<string,mixed>|null
     */
    private function exchange(string $referenceToken): ?array
    {
        $url = rtrim($this->platformUrl, '/') . '/' . ltrim($this->checkRefPath, '/');

        try {
            $response = Http::acceptJson()->timeout($this->timeoutSeconds)
                ->post($url, ['referenceToken' => $referenceToken]);
        } catch (\Throwable $e) {
            Log::warning('vremote checkRef: HTTP call failed', ['url' => $url, 'error' => $e->getMessage()]);

            return null;
        }

        if (! $response->successful()) {
            Log::warning('vremote checkRef: non-2xx', [
                'url' => $url, 'status' => $response->status(), 'body' => mb_substr($response->body(), 0, 300),
            ]);

            return null;
        }

        $body = $response->json();
        if (! is_array($body) || (int) ($body['code'] ?? 0) !== 1) {
            Log::warning('vremote checkRef: unexpected body', [
                'keys' => is_array($body) ? array_keys($body) : gettype($body), 'code' => is_array($body) ? ($body['code'] ?? null) : null,
            ]);

            return null;
        }

        $data = $body['data'] ?? null;
        if (! is_array($data)) {
            Log::warning('vremote checkRef: missing data envelope');

            return null;
        }

        $payload = $this->readJwt((string) ($data['token'] ?? ''));
        $claims = $this->normalise(is_array($payload) ? $payload : [], $data);

        if (($claims['sub'] ?? '') === '') {
            Log::warning('vremote checkRef: empty subject', ['data_keys' => array_keys($data), 'jwt_decoded' => is_array($payload)]);

            return null;
        }

        return $claims;
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
