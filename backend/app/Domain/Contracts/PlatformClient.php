<?php

declare(strict_types=1);

namespace App\Domain\Contracts;

use App\Domain\Platform\PlatformContext;

/**
 * The single VPlus Platform integration point (DSG-VREMOTE-002 §4).
 * Verifies a caller's bearer token/context and returns the platform identity
 * context, or null if the token is invalid. VRemote keeps no user/tenant/auth store.
 */
interface PlatformClient
{
    public function verify(string $bearerToken): ?PlatformContext;
}
