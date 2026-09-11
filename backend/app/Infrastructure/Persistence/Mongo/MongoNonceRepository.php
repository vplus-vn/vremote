<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Mongo;

use App\Domain\Contracts\NonceRepository;
use Illuminate\Support\Facades\DB;

/**
 * MongoDB nonce store (DSG-VREMOTE-002 §6). Relies on a unique index on `_id`
 * for atomic insert-or-fail, plus a TTL index on `seenAt` to auto-prune.
 */
final class MongoNonceRepository implements NonceRepository
{
    private const COLLECTION = 'signing_nonces';

    public function remember(string $scope, string $nonce, int $nowEpoch): bool
    {
        try {
            DB::connection('mongodb')->table(self::COLLECTION)->insert([
                '_id' => $scope . ':' . $nonce,
                'scope' => $scope,
                'seenAt' => $nowEpoch,
            ]);

            return true;
        } catch (\Throwable) {
            // Duplicate key => nonce already seen (replay).
            return false;
        }
    }
}
