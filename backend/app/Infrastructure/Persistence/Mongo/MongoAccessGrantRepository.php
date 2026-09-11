<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Mongo;

use App\Domain\Authorization\AccessGrant;
use App\Domain\Contracts\AccessGrantRepository;
use Illuminate\Support\Facades\DB;

/** MongoDB access-grant store (DSG-VREMOTE-002 §5). */
final class MongoAccessGrantRepository implements AccessGrantRepository
{
    private const COLLECTION = 'device_access_grants';

    private function table()
    {
        return DB::connection('mongodb')->table(self::COLLECTION);
    }

    public function activeForDevice(string $deviceId, int $nowEpoch): array
    {
        $docs = $this->table()->where('deviceId', $deviceId)->whereNull('revokedAt')->get();

        $grants = [];
        foreach ($docs as $doc) {
            $d = (array) $doc;
            $grant = new AccessGrant(
                grantId: (string) ($d['grantId'] ?? $d['_id'] ?? ''),
                deviceId: (string) $d['deviceId'],
                principalUserId: isset($d['principalUserId']) ? (string) $d['principalUserId'] : null,
                tenantId: isset($d['tenantId']) ? (string) $d['tenantId'] : null,
                permissions: array_values(array_map('strval', (array) ($d['permissions'] ?? []))),
                expiresAtEpoch: isset($d['expiresAt']) ? (int) $d['expiresAt'] : null,
                revokedAtEpoch: isset($d['revokedAt']) ? (int) $d['revokedAt'] : null,
            );
            if ($grant->isActive($nowEpoch)) {
                $grants[] = $grant;
            }
        }

        return $grants;
    }

    public function get(string $grantId): ?array
    {
        $doc = $this->table()->where('_id', $grantId)->first();

        return $doc ? (array) $doc : null;
    }

    public function create(array $grant): void
    {
        $grant['_id'] = $grant['grantId'];
        $this->table()->insert($grant);
    }

    public function revoke(string $grantId, int $revokedAtEpoch): void
    {
        $this->table()->where('_id', $grantId)->update(['revokedAt' => $revokedAtEpoch]);
    }
}
