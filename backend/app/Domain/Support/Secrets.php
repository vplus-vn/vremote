<?php

declare(strict_types=1);

namespace App\Domain\Support;

/**
 * Enrollment/credential secret generation + hashing (DSG-VREMOTE-002 §7).
 * Secrets are returned to the caller once and stored only as hashes.
 */
interface Secrets
{
    /** A human-typable enrollment code, e.g. "7H4K-92QM". */
    public function enrollmentCode(): string;

    /** A high-entropy one-time enrollment/runtime token. */
    public function token(int $bytes = 32): string;

    /** Deterministic, constant-time-comparable hash for storing a secret. */
    public function hash(string $value): string;

    public function verify(string $value, string $hash): bool;
}
