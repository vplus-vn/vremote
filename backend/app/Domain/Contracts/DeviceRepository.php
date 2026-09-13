<?php

declare(strict_types=1);

namespace App\Domain\Contracts;

/**
 * Device registry (DSG-VREMOTE-002 §3). Replaces the env-JSON DeviceRegistry with a
 * durable store carrying ownerTenantId, enrollment/credential state and runtime status.
 */
interface DeviceRepository
{
    /** @return array<string,mixed>|null */
    public function get(string $deviceId): ?array;

    /** @return array<string,mixed>|null */
    public function findByMachineCode(string $machineCode): ?array;

    /** @return array<string,mixed>|null */
    public function findByPairingId(string $pairingId): ?array;

    /**
     * @param array{status?:string,tenantId?:string} $filters
     * @return list<array<string,mixed>>
     */
    public function list(array $filters = [], int $limit = 50): array;

    /** @param array<string,mixed> $device */
    public function save(array $device): void;

    /** @param array<string,mixed> $fields */
    public function update(string $deviceId, array $fields): void;

    /** Permanently remove a device row (cleanup / de-registration). */
    public function delete(string $deviceId): void;
}
