<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Mongo;

use App\Domain\Contracts\DeviceRepository;
use Illuminate\Support\Facades\DB;

/**
 * MongoDB device registry (DSG-VREMOTE-002 §3). Uses deviceId as `_id`.
 * NOTE: integration-tested against MongoDB in CI/runtime; not exercised locally.
 */
final class MongoDeviceRepository implements DeviceRepository
{
    private const COLLECTION = 'devices';

    private function table()
    {
        return DB::connection('mongodb')->table(self::COLLECTION);
    }

    public function get(string $deviceId): ?array
    {
        $doc = $this->table()->where('_id', $deviceId)->first();

        return $doc ? $this->hydrate((array) $doc) : null;
    }

    public function findByMachineCode(string $machineCode): ?array
    {
        $doc = $this->table()->where('machineCode', $machineCode)->first();

        return $doc ? $this->hydrate((array) $doc) : null;
    }

    public function list(array $filters = [], int $limit = 50): array
    {
        $q = $this->table();
        if (isset($filters['status'])) {
            $q->where('status', $filters['status']);
        }
        if (isset($filters['tenantId'])) {
            $q->where('ownerTenantId', $filters['tenantId']);
        }

        return array_map(fn ($d) => $this->hydrate((array) $d), $q->limit($limit)->get()->all());
    }

    public function save(array $device): void
    {
        $device['_id'] = $device['deviceId'];
        $this->table()->updateOrInsert(['_id' => $device['deviceId']], $device);
    }

    public function update(string $deviceId, array $fields): void
    {
        $this->table()->updateOrInsert(['_id' => $deviceId], ['deviceId' => $deviceId] + $fields);
    }

    /** @param array<string,mixed> $doc */
    private function hydrate(array $doc): array
    {
        $doc['deviceId'] = (string) ($doc['deviceId'] ?? $doc['_id'] ?? '');
        unset($doc['_id']);

        return $doc;
    }
}
