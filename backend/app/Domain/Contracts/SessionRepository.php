<?php

declare(strict_types=1);

namespace App\Domain\Contracts;

/**
 * Persistence seam for remote-view sessions.
 *
 * Baseline v0.1.0 is implemented by the file-backed {@see \App\Services\SessionStore};
 * v0.2.0 (DSG-VREMOTE-002 §3) swaps in a MongoDB implementation behind this same
 * interface without touching the Application/HTTP layers.
 */
interface SessionRepository
{
    /** @param array<string,mixed> $session */
    public function put(array $session): void;

    /** @return array<string,mixed>|null */
    public function get(string $id): ?array;

    public function forget(string $id): void;

    /** @return array<string,mixed> */
    public function all(): array;
}
