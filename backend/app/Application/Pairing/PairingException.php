<?php

declare(strict_types=1);

namespace App\Application\Pairing;

use RuntimeException;

/** Domain error for the device-initiated pairing flow. `errorCode` maps to an API code/status. */
final class PairingException extends RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message)
    {
        parent::__construct($message);
    }
}
