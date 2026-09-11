<?php

declare(strict_types=1);

namespace App\Infrastructure\Platform;

use App\Domain\Contracts\PlatformClient;
use App\Domain\Platform\PlatformContext;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Introspection Platform adapter — the "use the platform service directly" path
 * (like the S&B admin portal), for when the Platform issues OPAQUE bearer tokens
 * that cannot be verified locally (i.e. not RS256 JWTs core-lib could check).
 *
 * On each request VRemote forwards the caller's bearer token to the Platform's
 * "who am I" endpoint (VREMOTE_PLATFORM_URL + VREMOTE_PLATFORM_ME_PATH, default
 * `/v1/me`). A 2xx JSON identity is trusted and mapped to a PlatformContext; the
 * result is cached briefly (keyed by token hash) so an authenticated browser does
 * not hit the Platform on every single call.
 *
 * Response shape is tolerant: the identity may be at the top level or wrapped under
 * `data`/`user`/`result`. Field names are normalised to what PlatformContext expects
 * (`id`/`_id`/`uuid`/`userId`/`sub` -> userId; `tenantId`/`tenant_id`/`tenant.id`).
 */
final class IntrospectionPlatformClient implements PlatformClient
{
    public function __construct(
        private readonly string $platformUrl,
        private readonly string $mePath = '/v1/me',
        private readonly int $cacheTtlSeconds = 30,
        private readonly int $timeoutSeconds = 5,
    ) {
    }

    public function verify(string $bearerToken): ?PlatformContext
    {
        if ($this->platformUrl === '' || $bearerToken === '') {
            return null;
        }

        $cacheKey = 'vremote:introspect:' . hash('sha256', $bearerToken);

        /** @var array<string,mixed>|null $claims */
        $claims = Cache::remember($cacheKey, $this->cacheTtlSeconds, function () use ($bearerToken): ?array {
            $url = rtrim($this->platformUrl, '/') . '/' . ltrim($this->mePath, '/');

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

            $body = $response->json();

            return is_array($body) ? $this->normalise($body) : null;
        });

        if (! is_array($claims)) {
            return null;
        }

        $context = PlatformContext::fromClaims($claims);

        return $context->userId !== '' ? $context : null;
    }

    /**
     * Unwrap a common envelope and normalise identity field names to the keys
     * PlatformContext::fromClaims understands.
     *
     * @param array<string,mixed> $body
     * @return array<string,mixed>
     */
    private function normalise(array $body): array
    {
        foreach (['data', 'user', 'result', 'profile'] as $wrapper) {
            if (isset($body[$wrapper]) && is_array($body[$wrapper])) {
                $body = $body[$wrapper];
                break;
            }
        }

        if (! isset($body['userId']) && ! isset($body['sub'])) {
            foreach (['id', '_id', 'uuid', 'userId', 'sub'] as $idKey) {
                if (isset($body[$idKey]) && (is_string($body[$idKey]) || is_int($body[$idKey]))) {
                    $body['userId'] = (string) $body[$idKey];
                    break;
                }
            }
        }

        if (! isset($body['tenantId'])) {
            if (isset($body['tenant_id'])) {
                $body['tenantId'] = $body['tenant_id'];
            } elseif (isset($body['tenant']) && is_array($body['tenant']) && isset($body['tenant']['id'])) {
                $body['tenantId'] = $body['tenant']['id'];
            }
        }

        // Permissions/roles may arrive as arrays of objects ({name: "..."}) — flatten to names.
        foreach (['permissions', 'roles', 'scopes'] as $listKey) {
            if (isset($body[$listKey]) && is_array($body[$listKey])) {
                $body[$listKey] = array_map(
                    static fn ($item) => is_array($item) ? (string) ($item['name'] ?? $item['slug'] ?? $item['key'] ?? '') : $item,
                    $body[$listKey],
                );
            }
        }

        return $body;
    }
}
