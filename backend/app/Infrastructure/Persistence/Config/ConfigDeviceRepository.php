<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Config;

use App\Domain\Contracts\DeviceRepository;

/**
 * Transitional read-only device registry backed by VREMOTE_DEVICES_JSON
 * (baseline v0.1.0 behavior) so the stack runs before MongoDB is provisioned.
 * Writes are no-ops — enrollment/binding requires the Mongo repository. The
 * service provider binds this only when DB_CONNECTION is not mongodb.
 */
final class ConfigDeviceRepository implements DeviceRepository
{
    /** @return array<string,array<string,mixed>> */
    private function all(): array
    {
        $raw = (string) config('vremote.devices', '{}');
        $data = json_decode($raw, true);

        return is_array($data) ? $data : [];
    }

    public function get(string $deviceId): ?array
    {
        $d = $this->all()[$deviceId] ?? null;

        return is_array($d) ? $d + ['deviceId' => $deviceId] : null;
    }

    public function findByMachineCode(string $machineCode): ?array
    {
        foreach ($this->all() as $id => $d) {
            if (is_array($d) && ($d['machineCode'] ?? null) === $machineCode) {
                return $d + ['deviceId' => (string) $id];
            }
        }

        return null;
    }

    public function findByPairingId(string $pairingId): ?array
    {
        foreach ($this->all() as $id => $d) {
            if (is_array($d) && ($d['pairingId'] ?? null) === $pairingId) {
                return $d + ['deviceId' => (string) $id];
            }
        }

        return null;
    }

    public function list(array $filters = [], int $limit = 50): array
    {
        $items = [];
        foreach ($this->all() as $id => $d) {
            $d = is_array($d) ? $d : [];
            if (isset($filters['status']) && ($d['status'] ?? 'offline') !== $filters['status']) {
                continue;
            }
            if (isset($filters['tenantId']) && ($d['ownerTenantId'] ?? null) !== $filters['tenantId']) {
                continue;
            }
            $items[] = $d + ['deviceId' => (string) $id];
            if (count($items) >= $limit) {
                break;
            }
        }

        return $items;
    }

    public function save(array $device): void
    {
        // Read-only config store: persistence requires the Mongo repository.
    }

    public function update(string $deviceId, array $fields): void
    {
        // Read-only config store: persistence requires the Mongo repository.
    }
}
