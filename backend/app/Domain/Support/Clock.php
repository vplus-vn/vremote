<?php

declare(strict_types=1);

namespace App\Domain\Support;

/** Injectable time source so time-dependent logic is unit-testable. */
interface Clock
{
    public function now(): int;
}
