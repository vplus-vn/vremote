<?php

declare(strict_types=1);

namespace App\Domain\Contracts;

/**
 * Session-token revocation list (DSG-VREMOTE-002 §6). Lets DELETE /sessions/{id}
 * invalidate a still-unexpired session token (baseline gap H1). TTL-expired entries
 * are pruned automatically.
 */
interface RevokedSessionRepository
{
    public function revoke(string $jti, int $expiresAtEpoch): void;

    public function isRevoked(string $jti): bool;
}
