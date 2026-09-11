<?php

declare(strict_types=1);

namespace App\Application\Enrollment;

use RuntimeException;

/** Carries a machine-readable code the HTTP layer maps to an error response. */
final class EnrollmentException extends RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message = '')
    {
        parent::__construct($message !== '' ? $message : $errorCode);
    }
}
