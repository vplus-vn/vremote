<?php

declare(strict_types=1);

namespace App\Domain\Contracts;

use App\Domain\Authorization\AccessGrant;

/** Delegated/support access grants (DSG-VREMOTE-002 §5). */
interface AccessGrantRepository
{
    /**
     * Active (non-revoked, non-expired) grants for a device, hydrated as domain objects.
     *
     * @return list<AccessGrant>
     */
    public function activeForDevice(string $deviceId, int $nowEpoch): array;

    /** @return array<string,mixed>|null */
    public function get(string $grantId): ?array;

    /** @param array<string,mixed> $grant */
    public function create(array $grant): void;

    public function revoke(string $grantId, int $revokedAtEpoch): void;
}
