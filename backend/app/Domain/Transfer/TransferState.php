<?php

declare(strict_types=1);

namespace App\Domain\Transfer;

/**
 * Device transfer workflow between tenants (DSG-VREMOTE-001 §7, DSG-VREMOTE-002 §7).
 *
 *   PENDING -> APPROVED -> COMPLETED
 *   PENDING -> REJECTED
 */
final class TransferState
{
    public const PENDING = 'PENDING';
    public const APPROVED = 'APPROVED';
    public const REJECTED = 'REJECTED';
    public const COMPLETED = 'COMPLETED';

    /** @var array<string,list<string>> */
    private const TRANSITIONS = [
        self::PENDING => [self::APPROVED, self::REJECTED],
        self::APPROVED => [self::COMPLETED],
        self::REJECTED => [],
        self::COMPLETED => [],
    ];

    public static function isValid(string $state): bool
    {
        return array_key_exists($state, self::TRANSITIONS);
    }

    public static function canTransition(string $from, string $to): bool
    {
        return self::isValid($from) && in_array($to, self::TRANSITIONS[$from], true);
    }

    public static function isTerminal(string $state): bool
    {
        return self::isValid($state) && self::TRANSITIONS[$state] === [];
    }
}
