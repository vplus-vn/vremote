<?php

declare(strict_types=1);

namespace App\Domain\Contracts;

/**
 * Persistence seam for the append-only audit trail.
 *
 * Baseline v0.1.0 is implemented by the file-backed {@see \App\Services\AuditStore};
 * v0.2.0 (DSG-VREMOTE-002 §3) moves to a durable, per-event MongoDB collection so
 * records are not lost under concurrency.
 */
interface AuditRepository
{
    /** @param array<string,mixed> $entry */
    public function append(array $entry): void;

    /** @return array<int,array<string,mixed>> */
    public function all(): array;
}
