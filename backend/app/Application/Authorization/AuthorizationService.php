<?php

declare(strict_types=1);

namespace App\Application\Authorization;

use App\Domain\Authorization\DeviceAuthorizer;
use App\Domain\Contracts\AccessGrantRepository;
use App\Domain\Contracts\DeviceRepository;
use App\Domain\Platform\PlatformContext;

/**
 * Application-level authorization for device actions (DSG-VREMOTE-002 §5).
 * Loads the device's owner tenant and any active access grants, then delegates the
 * decision to the pure DeviceAuthorizer. Enforces the tenant ownership invariant that
 * the baseline ignored (fixes H3).
 *
 * Framework-free (constructor-injected repositories) so it is fully unit-testable.
 */
final class AuthorizationService
{
    public function __construct(
        private readonly DeviceRepository $devices,
        private readonly AccessGrantRepository $grants,
        private readonly DeviceAuthorizer $authorizer,
    ) {
    }

    public function canActOnDevice(PlatformContext $ctx, string $deviceId, string $permission, ?int $nowEpoch = null): bool
    {
        $now = $nowEpoch ?? time();

        $device = $this->devices->get($deviceId);
        if ($device === null) {
            return false;
        }

        $ownerTenantId = isset($device['ownerTenantId']) && $device['ownerTenantId'] !== ''
            ? (string) $device['ownerTenantId']
            : null;

        $activeGrants = $this->grants->activeForDevice($deviceId, $now);

        return $this->authorizer->authorize($ctx, $ownerTenantId, $permission, $activeGrants, $now);
    }
}
