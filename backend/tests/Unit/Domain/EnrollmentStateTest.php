<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Domain\Enrollment\EnrollmentState as S;
use PHPUnit\Framework\TestCase;

final class EnrollmentStateTest extends TestCase
{
    public function test_happy_path_chain(): void
    {
        self::assertTrue(S::canTransition(S::UNENROLLED, S::PENDING));
        self::assertTrue(S::canTransition(S::PENDING, S::VERIFIED));
        self::assertTrue(S::canTransition(S::VERIFIED, S::BOUND));
        self::assertTrue(S::canTransition(S::BOUND, S::ACTIVE));
        self::assertTrue(S::canTransition(S::ACTIVE, S::REVOKED));
    }

    public function test_branch_transitions(): void
    {
        self::assertTrue(S::canTransition(S::PENDING, S::EXPIRED));
        self::assertTrue(S::canTransition(S::PENDING, S::REVOKED));
        self::assertTrue(S::canTransition(S::VERIFIED, S::REJECTED));
    }

    public function test_illegal_transitions_are_denied(): void
    {
        self::assertFalse(S::canTransition(S::UNENROLLED, S::ACTIVE));
        self::assertFalse(S::canTransition(S::PENDING, S::BOUND));
        self::assertFalse(S::canTransition(S::ACTIVE, S::PENDING));
        self::assertFalse(S::canTransition(S::REVOKED, S::ACTIVE));
        self::assertFalse(S::canTransition('NONSENSE', S::PENDING));
    }

    public function test_terminal_states(): void
    {
        self::assertTrue(S::isTerminal(S::EXPIRED));
        self::assertTrue(S::isTerminal(S::REVOKED));
        self::assertTrue(S::isTerminal(S::REJECTED));
        self::assertFalse(S::isTerminal(S::ACTIVE));
        self::assertFalse(S::isTerminal(S::PENDING));
    }

    public function test_assert_transition_throws_on_illegal(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        S::assertTransition(S::UNENROLLED, S::ACTIVE);
    }
}
