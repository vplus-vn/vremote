<?php

declare(strict_types=1);

namespace App\Domain\Contracts;

/** Enrollment transactions (DSG-VREMOTE-002 §7). Secrets are stored hashed only. */
interface EnrollmentRepository
{
    /** @return array<string,mixed>|null */
    public function get(string $enrollmentId): ?array;

    /** @return array<string,mixed>|null */
    public function findByCodeHash(string $codeHash): ?array;

    /** @param array<string,mixed> $enrollment */
    public function create(array $enrollment): void;

    /** @param array<string,mixed> $fields */
    public function update(string $enrollmentId, array $fields): void;
}
