<?php

declare(strict_types=1);

namespace App\Domain\Device;

/**
 * Long-lived Device Credential lifecycle (DSG-VREMOTE-001 §9.4).
 * The enrollment credential is NOT this credential.
 */
final class CredentialState
{
    public const ACTIVE = 'ACTIVE';
    public const ROTATION_REQUIRED = 'ROTATION_REQUIRED';
    public const REVOKED = 'REVOKED';
    public const RECOVERY_REQUIRED = 'RECOVERY_REQUIRED';

    /** @var array<string,list<string>> */
    private const TRANSITIONS = [
        self::ACTIVE => [self::ROTATION_REQUIRED, self::REVOKED, self::RECOVERY_REQUIRED],
        self::ROTATION_REQUIRED => [self::ACTIVE, self::REVOKED],
        self::RECOVERY_REQUIRED => [self::ACTIVE, self::REVOKED],
        self::REVOKED => [],
    ];

    public static function isValid(string $state): bool
    {
        return array_key_exists($state, self::TRANSITIONS);
    }

    public static function canTransition(string $from, string $to): bool
    {
        return self::isValid($from) && in_array($to, self::TRANSITIONS[$from], true);
    }

    /** A credential may open sessions/commands only while ACTIVE. */
    public static function isUsable(string $state): bool
    {
        return $state === self::ACTIVE;
    }
}
