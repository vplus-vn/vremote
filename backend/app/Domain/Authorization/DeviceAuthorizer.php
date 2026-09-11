<?php

declare(strict_types=1);

namespace App\Domain\Authorization;

use App\Domain\Platform\PlatformContext;

/**
 * Central authorization decision for acting on a device (DSG-VREMOTE-002 §5).
 *
 * Framework-free and pure so it is fully unit-testable. Enforces the tenant
 * ownership invariant (fixes the baseline gap where tenantId was ignored):
 *
 *   allow = caller holds the required permission AND owns the device's tenant
 *        OR an active, scoped access grant covers the caller for that permission.
 */
final class DeviceAuthorizer
{
    /**
     * @param list<AccessGrant> $activeGrants grants already loaded for this device
     */
    public function authorize(
        PlatformContext $ctx,
        ?string $ownerTenantId,
        string $requiredPermission,
        array $activeGrants = [],
        ?int $nowEpoch = null,
    ): bool {
        $now = $nowEpoch ?? time();

        // Primary path: same-tenant owner holding the permission.
        if (
            $ownerTenantId !== null
            && $ctx->tenantId !== null
            && $ctx->tenantId === $ownerTenantId
            && $ctx->hasPermission($requiredPermission)
        ) {
            return true;
        }

        // Delegated path: an active access grant for this caller and permission.
        foreach ($activeGrants as $grant) {
            if (
                $grant->deviceId !== '' // defensive
                && $grant->isActive($now)
                && $grant->grantsPermission($requiredPermission)
                && $grant->appliesTo($ctx->userId, $ctx->tenantId)
            ) {
                return true;
            }
        }

        return false;
    }
}
