<?php

declare(strict_types=1);

namespace App\Domain\Authorization;

/**
 * Delegated/support access to a device (DSG-VREMOTE-001 §7, DSG-VREMOTE-002 §5).
 *
 * Framework-free value object. A grant lets a principal or tenant act on a device
 * WITHOUT changing the device's owner tenant. Scoped, time-boxed and revocable.
 */
final class AccessGrant
{
    /** @param list<string> $permissions */
    public function __construct(
        public readonly string $grantId,
        public readonly string $deviceId,
        public readonly ?string $principalUserId,
        public readonly ?string $tenantId,
        public readonly array $permissions,
        public readonly ?int $expiresAtEpoch,
        public readonly ?int $revokedAtEpoch = null,
    ) {
    }

    public function isActive(int $nowEpoch): bool
    {
        if ($this->revokedAtEpoch !== null) {
            return false;
        }
        if ($this->expiresAtEpoch !== null && $this->expiresAtEpoch <= $nowEpoch) {
            return false;
        }

        return true;
    }

    public function grantsPermission(string $permission): bool
    {
        return in_array($permission, $this->permissions, true);
    }

    /** Does this grant apply to the given caller identity? */
    public function appliesTo(?string $userId, ?string $tenantId): bool
    {
        if ($this->principalUserId !== null && $userId !== null && $this->principalUserId === $userId) {
            return true;
        }
        if ($this->tenantId !== null && $tenantId !== null && $this->tenantId === $tenantId) {
            return true;
        }

        return false;
    }
}
