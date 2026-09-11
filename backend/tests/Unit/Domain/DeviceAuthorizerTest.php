<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Domain\Authorization\AccessGrant;
use App\Domain\Authorization\DeviceAuthorizer;
use App\Domain\Platform\PlatformContext;
use PHPUnit\Framework\TestCase;

final class DeviceAuthorizerTest extends TestCase
{
    private DeviceAuthorizer $auth;

    protected function setUp(): void
    {
        $this->auth = new DeviceAuthorizer();
    }

    private function ctx(string $tenant, array $perms): PlatformContext
    {
        return new PlatformContext(userId: 'u1', tenantId: $tenant, permissions: $perms);
    }

    public function test_owner_tenant_with_permission_is_allowed(): void
    {
        $ctx = $this->ctx('T-A', ['edge.admin.view']);
        self::assertTrue($this->auth->authorize($ctx, 'T-A', 'edge.admin.view'));
    }

    public function test_owner_tenant_without_permission_is_denied(): void
    {
        $ctx = $this->ctx('T-A', ['edge.admin.view']);
        self::assertFalse($this->auth->authorize($ctx, 'T-A', 'edge.admin.control'));
    }

    public function test_other_tenant_is_denied_without_grant(): void
    {
        $ctx = $this->ctx('T-B', ['edge.admin.view']);
        self::assertFalse($this->auth->authorize($ctx, 'T-A', 'edge.admin.view'));
    }

    public function test_null_owner_tenant_is_denied(): void
    {
        $ctx = $this->ctx('T-A', ['edge.admin.view']);
        self::assertFalse($this->auth->authorize($ctx, null, 'edge.admin.view'));
    }

    public function test_active_grant_for_user_allows_cross_tenant(): void
    {
        $ctx = new PlatformContext(userId: 'support-1', tenantId: 'T-SUPPORT', permissions: []);
        $grant = new AccessGrant('G1', 'DEV-1', 'support-1', null, ['edge.admin.view'], expiresAtEpoch: 2_000_000_000);
        self::assertTrue($this->auth->authorize($ctx, 'T-A', 'edge.admin.view', [$grant], nowEpoch: 1_000));
    }

    public function test_expired_grant_is_denied(): void
    {
        $ctx = new PlatformContext(userId: 'support-1', tenantId: 'T-SUPPORT');
        $grant = new AccessGrant('G1', 'DEV-1', 'support-1', null, ['edge.admin.view'], expiresAtEpoch: 500);
        self::assertFalse($this->auth->authorize($ctx, 'T-A', 'edge.admin.view', [$grant], nowEpoch: 1_000));
    }

    public function test_revoked_grant_is_denied(): void
    {
        $ctx = new PlatformContext(userId: 'support-1', tenantId: 'T-SUPPORT');
        $grant = new AccessGrant('G1', 'DEV-1', 'support-1', null, ['edge.admin.view'], expiresAtEpoch: 2_000_000_000, revokedAtEpoch: 900);
        self::assertFalse($this->auth->authorize($ctx, 'T-A', 'edge.admin.view', [$grant], nowEpoch: 1_000));
    }

    public function test_grant_for_other_permission_is_denied(): void
    {
        $ctx = new PlatformContext(userId: 'support-1', tenantId: 'T-SUPPORT');
        $grant = new AccessGrant('G1', 'DEV-1', 'support-1', null, ['edge.admin.view'], expiresAtEpoch: 2_000_000_000);
        self::assertFalse($this->auth->authorize($ctx, 'T-A', 'edge.admin.control', [$grant], nowEpoch: 1_000));
    }

    public function test_grant_by_tenant_applies_to_tenant_member(): void
    {
        $ctx = new PlatformContext(userId: 'anyone', tenantId: 'T-PARTNER');
        $grant = new AccessGrant('G1', 'DEV-1', null, 'T-PARTNER', ['edge.admin.control'], expiresAtEpoch: 2_000_000_000);
        self::assertTrue($this->auth->authorize($ctx, 'T-A', 'edge.admin.control', [$grant], nowEpoch: 1_000));
    }
}
