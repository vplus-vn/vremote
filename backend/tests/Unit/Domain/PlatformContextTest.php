<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Domain\Platform\PlatformContext;
use PHPUnit\Framework\TestCase;

final class PlatformContextTest extends TestCase
{
    public function test_accessors(): void
    {
        $ctx = new PlatformContext('u1', 'T-A', ['operator'], ['edge.admin.view'], ['device:DEV-1']);
        self::assertTrue($ctx->hasPermission('edge.admin.view'));
        self::assertFalse($ctx->hasPermission('edge.admin.control'));
        self::assertTrue($ctx->hasRole('operator'));
        self::assertTrue($ctx->hasScope('device:DEV-1'));
    }

    public function test_from_claims_with_arrays(): void
    {
        $ctx = PlatformContext::fromClaims([
            'userId' => 'u9', 'tenantId' => 'T-9',
            'roles' => ['admin'], 'permissions' => ['edge.admin.control'], 'scopes' => ['*'],
        ]);
        self::assertSame('u9', $ctx->userId);
        self::assertSame('T-9', $ctx->tenantId);
        self::assertTrue($ctx->hasPermission('edge.admin.control'));
    }

    public function test_from_claims_with_csv_string_and_sub_fallback(): void
    {
        $ctx = PlatformContext::fromClaims([
            'sub' => 'legacy-user',
            'permissions' => 'edge.admin.view, edge.admin.control',
        ]);
        self::assertSame('legacy-user', $ctx->userId);
        self::assertNull($ctx->tenantId);
        self::assertTrue($ctx->hasPermission('edge.admin.view'));
        self::assertTrue($ctx->hasPermission('edge.admin.control'));
    }
}
