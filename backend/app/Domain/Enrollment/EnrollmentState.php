<?php

declare(strict_types=1);

namespace App\Domain\Enrollment;

/**
 * Enrollment lifecycle state machine (DSG-VREMOTE-001 §9.3, DSG-VREMOTE-002 §7).
 *
 *   UNENROLLED -> PENDING -> VERIFIED -> BOUND -> ACTIVE
 *   PENDING  -> EXPIRED | REVOKED
 *   VERIFIED -> REJECTED
 *
 * Framework-free; pure transition rules so they are unit-testable.
 */
final class EnrollmentState
{
    public const UNENROLLED = 'UNENROLLED';
    public const PENDING = 'PENDING';
    public const VERIFIED = 'VERIFIED';
    public const BOUND = 'BOUND';
    public const ACTIVE = 'ACTIVE';
    public const EXPIRED = 'EXPIRED';
    public const REVOKED = 'REVOKED';
    public const REJECTED = 'REJECTED';

    /** @var array<string,list<string>> */
    private const TRANSITIONS = [
        self::UNENROLLED => [self::PENDING],
        self::PENDING => [self::VERIFIED, self::EXPIRED, self::REVOKED],
        self::VERIFIED => [self::BOUND, self::REJECTED],
        self::BOUND => [self::ACTIVE],
        self::ACTIVE => [self::REVOKED],
        self::EXPIRED => [],
        self::REVOKED => [],
        self::REJECTED => [],
    ];

    /** @return list<string> */
    public static function all(): array
    {
        return array_keys(self::TRANSITIONS);
    }

    public static function isValid(string $state): bool
    {
        return array_key_exists($state, self::TRANSITIONS);
    }

    public static function canTransition(string $from, string $to): bool
    {
        return self::isValid($from) && in_array($to, self::TRANSITIONS[$from], true);
    }

    /** @throws \InvalidArgumentException */
    public static function assertTransition(string $from, string $to): void
    {
        if (! self::canTransition($from, $to)) {
            throw new \InvalidArgumentException("Illegal enrollment transition: {$from} -> {$to}");
        }
    }

    public static function isTerminal(string $state): bool
    {
        return self::isValid($state) && self::TRANSITIONS[$state] === [];
    }
}
