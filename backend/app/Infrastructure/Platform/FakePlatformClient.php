<?php

declare(strict_types=1);

namespace App\Infrastructure\Platform;

use App\Domain\Contracts\PlatformClient;
use App\Domain\Platform\PlatformContext;

/**
 * Test/local Platform adapter (DSG-VREMOTE-002 §4). Returns a fixed context so the
 * stack runs without the real Platform. Only bound in local/testing — never production.
 * Replaces the old VREMOTE_DEV_AUTH middleware bypass with a real (but fake) adapter.
 */
final class FakePlatformClient implements PlatformClient
{
    /**
     * @param list<string> $permissions
     */
    public function __construct(
        private readonly string $actor = 'local-admin',
        private readonly ?string $tenantId = 'T-LOCAL',
        private readonly array $permissions = ['edge.admin.view', 'edge.admin.control'],
    ) {
    }

    public function verify(string $bearerToken): ?PlatformContext
    {
        // In fake mode any non-empty token (or none) resolves to the configured actor.
        return new PlatformContext(
            userId: $this->actor,
            tenantId: $this->tenantId,
            roles: ['operator'],
            permissions: $this->permissions,
            scopes: ['*'],
        );
    }
}
