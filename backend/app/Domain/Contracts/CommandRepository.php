<?php

declare(strict_types=1);

namespace App\Domain\Contracts;

/**
 * Persistence seam for signed fleet commands (with commandId idempotency).
 *
 * Baseline v0.1.0 is implemented by the file-backed {@see \App\Services\CommandStore};
 * v0.2.0 (DSG-VREMOTE-002 §3.3) replaces the racy check-then-act guard with a unique
 * `_id` index in MongoDB behind this interface.
 */
interface CommandRepository
{
    /** @return array<string,mixed>|null */
    public function get(string $id): ?array;

    /** @param array<string,mixed> $command */
    public function put(array $command): void;
}
