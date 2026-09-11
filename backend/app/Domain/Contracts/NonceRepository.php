<?php

declare(strict_types=1);

namespace App\Domain\Contracts;

/**
 * Server-side replay defense-in-depth (DSG-VREMOTE-002 §6). The agent remains the
 * authority per signing.md §4; the backend additionally records issued nonces so a
 * duplicate cannot be re-dispatched. Entries expire after the skew window (TTL index).
 */
interface NonceRepository
{
    /** Atomically record a nonce; returns false if it was already seen (replay). */
    public function remember(string $scope, string $nonce, int $nowEpoch): bool;
}
