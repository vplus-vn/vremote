<?php

declare(strict_types=1);

namespace App\Domain\Contracts;

/** Device transfer workflow (DSG-VREMOTE-002 §7). */
interface DeviceTransferRepository
{
    /** @return array<string,mixed>|null */
    public function get(string $transferId): ?array;

    /** @param array<string,mixed> $transfer */
    public function create(array $transfer): void;

    /** @param array<string,mixed> $fields */
    public function update(string $transferId, array $fields): void;
}
