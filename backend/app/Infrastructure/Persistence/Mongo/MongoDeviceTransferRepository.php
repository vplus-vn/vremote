<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Mongo;

use App\Domain\Contracts\DeviceTransferRepository;
use Illuminate\Support\Facades\DB;

/** MongoDB device-transfer store (DSG-VREMOTE-002 §7). */
final class MongoDeviceTransferRepository implements DeviceTransferRepository
{
    private const COLLECTION = 'device_transfers';

    private function table()
    {
        return DB::connection('mongodb')->table(self::COLLECTION);
    }

    public function get(string $transferId): ?array
    {
        $doc = $this->table()->where('_id', $transferId)->first();
        if (! $doc) {
            return null;
        }
        $d = (array) $doc;
        $d['transferId'] = (string) ($d['transferId'] ?? $d['_id'] ?? '');
        unset($d['_id']);

        return $d;
    }

    public function create(array $transfer): void
    {
        $transfer['_id'] = $transfer['transferId'];
        $this->table()->insert($transfer);
    }

    public function update(string $transferId, array $fields): void
    {
        $this->table()->where('_id', $transferId)->update($fields);
    }
}
