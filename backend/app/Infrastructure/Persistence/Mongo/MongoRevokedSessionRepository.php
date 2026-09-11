<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Mongo;

use App\Domain\Contracts\RevokedSessionRepository;
use Illuminate\Support\Facades\DB;

/**
 * MongoDB session-token revocation list (DSG-VREMOTE-002 §6). TTL index on
 * `expiresAt` prunes entries once the underlying token would have expired anyway.
 */
final class MongoRevokedSessionRepository implements RevokedSessionRepository
{
    private const COLLECTION = 'revoked_sessions';

    private function table()
    {
        return DB::connection('mongodb')->table(self::COLLECTION);
    }

    public function revoke(string $jti, int $expiresAtEpoch): void
    {
        $this->table()->updateOrInsert(['_id' => $jti], ['_id' => $jti, 'expiresAt' => $expiresAtEpoch]);
    }

    public function isRevoked(string $jti): bool
    {
        return $this->table()->where('_id', $jti)->exists();
    }
}
