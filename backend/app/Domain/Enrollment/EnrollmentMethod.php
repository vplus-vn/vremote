<?php

declare(strict_types=1);

namespace App\Domain\Enrollment;

/** Enrollment credential delivery method (both produce the same transaction). */
final class EnrollmentMethod
{
    public const QR = 'qr';
    public const MANUAL = 'manual';

    public static function isValid(string $method): bool
    {
        return $method === self::QR || $method === self::MANUAL;
    }
}
