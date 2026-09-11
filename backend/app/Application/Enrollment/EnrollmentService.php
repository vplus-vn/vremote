<?php

declare(strict_types=1);

namespace App\Application\Enrollment;

use App\Domain\Contracts\AuditRepository;
use App\Domain\Contracts\DeviceRepository;
use App\Domain\Contracts\EnrollmentRepository;
use App\Domain\Device\CredentialState;
use App\Domain\Enrollment\EnrollmentMethod;
use App\Domain\Enrollment\EnrollmentState;
use App\Domain\Support\Clock;
use App\Domain\Support\Secrets;

/**
 * Device enrollment lifecycle (DSG-VREMOTE-001 §9, DSG-VREMOTE-002 §7).
 * Framework-free (constructor-injected collaborators) so create/consume/reset are
 * unit-testable. Secrets are returned to the caller ONCE and stored only as hashes;
 * nothing here logs a code/token/credential.
 */
final class EnrollmentService
{
    public function __construct(
        private readonly EnrollmentRepository $enrollments,
        private readonly DeviceRepository $devices,
        private readonly AuditRepository $audit,
        private readonly Clock $clock,
        private readonly Secrets $secrets,
    ) {
    }

    /**
     * Create a PENDING enrollment binding a device to a tenant.
     *
     * @return array{enrollmentId:string,code:string,token:string,expiresAt:int,qrUri:string}
     *         Plaintext code/token are for one-time display only.
     */
    public function create(string $deviceId, string $tenantId, string $method, string $actor, int $ttlSec = 900): array
    {
        if (! EnrollmentMethod::isValid($method)) {
            throw new EnrollmentException('invalid_method', 'method must be qr or manual');
        }
        if ($deviceId === '' || $tenantId === '') {
            throw new EnrollmentException('invalid_argument', 'deviceId and tenantId are required');
        }

        $now = $this->clock->now();
        $enrollmentId = 'ENR-' . strtoupper(bin2hex(random_bytes(6)));
        $code = $this->secrets->enrollmentCode();
        $token = $this->secrets->token();

        $this->enrollments->create([
            'enrollmentId' => $enrollmentId,
            'deviceId' => $deviceId,
            'tenantId' => $tenantId,
            'method' => $method,
            'status' => EnrollmentState::PENDING,
            'codeHash' => $this->secrets->hash($code),
            'tokenHash' => $this->secrets->hash($token),
            'createdBy' => $actor,
            'createdAt' => $now,
            'expiresAt' => $now + $ttlSec,
            'consumedAt' => null,
        ]);

        $this->audit->append([
            'action' => 'ENROLLMENT_CREATE', 'deviceId' => $deviceId, 'tenantId' => $tenantId,
            'actor' => $actor, 'enrollmentId' => $enrollmentId, 'method' => $method, 'result' => 'pending',
        ]);

        return [
            'enrollmentId' => $enrollmentId,
            'code' => $code,
            'token' => $token,
            'expiresAt' => $now + $ttlSec,
            'qrUri' => 'vremote://enroll?enrollmentId=' . rawurlencode($enrollmentId) . '&token=' . rawurlencode($token),
        ];
    }

    /**
     * Consume an enrollment (verify code+token), bind the device to its tenant and issue
     * a long-lived device runtime credential (returned ONCE). PENDING → VERIFIED → BOUND → ACTIVE.
     *
     * @return array{deviceId:string,ownerTenantId:string,deviceCredential:string}
     */
    public function consume(string $enrollmentId, string $code, string $token, string $actor): array
    {
        $e = $this->enrollments->get($enrollmentId);
        if ($e === null) {
            throw new EnrollmentException('not_found', 'enrollment not found');
        }

        $now = $this->clock->now();
        $status = (string) ($e['status'] ?? '');

        if ($status !== EnrollmentState::PENDING) {
            throw new EnrollmentException('not_pending', 'enrollment is not pending');
        }
        if ((int) ($e['expiresAt'] ?? 0) <= $now) {
            $this->transition($enrollmentId, $status, EnrollmentState::EXPIRED, ['expiredAt' => $now]);
            throw new EnrollmentException('expired', 'enrollment credential expired');
        }
        if (
            ! $this->secrets->verify($code, (string) ($e['codeHash'] ?? ''))
            || ! $this->secrets->verify($token, (string) ($e['tokenHash'] ?? ''))
        ) {
            $this->audit->append([
                'action' => 'ENROLLMENT_REJECT', 'deviceId' => $e['deviceId'] ?? null,
                'tenantId' => $e['tenantId'] ?? null, 'actor' => $actor, 'enrollmentId' => $enrollmentId,
                'result' => 'invalid_credential',
            ]);
            throw new EnrollmentException('invalid_credential', 'code or token mismatch');
        }

        $deviceId = (string) $e['deviceId'];
        $tenantId = (string) $e['tenantId'];

        // Walk the state machine explicitly (asserts legality at each step).
        EnrollmentState::assertTransition(EnrollmentState::PENDING, EnrollmentState::VERIFIED);
        EnrollmentState::assertTransition(EnrollmentState::VERIFIED, EnrollmentState::BOUND);
        EnrollmentState::assertTransition(EnrollmentState::BOUND, EnrollmentState::ACTIVE);

        // Issue the long-lived device runtime credential (NOT the enrollment token).
        $deviceCredential = $this->secrets->token(32);

        $this->devices->update($deviceId, [
            'ownerTenantId' => $tenantId,
            'enrollmentState' => EnrollmentState::ACTIVE,
            'credentialState' => CredentialState::ACTIVE,
            'deviceToken' => $deviceCredential, // signing key; never returned by device API
            'boundAt' => $now,
        ]);

        $this->enrollments->update($enrollmentId, [
            'status' => EnrollmentState::ACTIVE,
            'consumedAt' => $now,
            // one-time secrets can no longer be used
            'codeHash' => null,
            'tokenHash' => null,
        ]);

        $this->audit->append([
            'action' => 'ENROLLMENT_CONSUME', 'deviceId' => $deviceId, 'tenantId' => $tenantId,
            'actor' => $actor, 'enrollmentId' => $enrollmentId, 'result' => 'active',
        ]);

        return ['deviceId' => $deviceId, 'ownerTenantId' => $tenantId, 'deviceCredential' => $deviceCredential];
    }

    /**
     * Protected reset/unassign (DSG-VREMOTE-001 §9.5): revoke the device credential and
     * clear the tenant binding, returning the device to UNENROLLED. Authorization for this
     * action is enforced at the call site (device-admin / protected), not here.
     */
    public function reset(string $deviceId, string $actor, string $reason): void
    {
        $device = $this->devices->get($deviceId);
        if ($device === null) {
            throw new EnrollmentException('not_found', 'device not found');
        }

        $fromTenant = $device['ownerTenantId'] ?? null;

        $this->devices->update($deviceId, [
            'ownerTenantId' => null,
            'enrollmentState' => EnrollmentState::UNENROLLED,
            'credentialState' => CredentialState::REVOKED,
            'deviceToken' => null,
        ]);

        $this->audit->append([
            'action' => 'ENROLLMENT_RESET', 'deviceId' => $deviceId, 'fromTenant' => $fromTenant,
            'actor' => $actor, 'reason' => $reason, 'result' => 'unenrolled',
        ]);
    }

    /** @param array<string,mixed> $extra */
    private function transition(string $enrollmentId, string $from, string $to, array $extra = []): void
    {
        EnrollmentState::assertTransition($from, $to);
        $this->enrollments->update($enrollmentId, ['status' => $to] + $extra);
    }
}
