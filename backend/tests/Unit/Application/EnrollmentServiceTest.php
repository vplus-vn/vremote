<?php

declare(strict_types=1);

namespace Tests\Unit\Application;

use App\Application\Enrollment\EnrollmentException;
use App\Application\Enrollment\EnrollmentService;
use App\Domain\Contracts\AuditRepository;
use App\Domain\Contracts\DeviceRepository;
use App\Domain\Contracts\EnrollmentRepository;
use App\Domain\Device\CredentialState;
use App\Domain\Enrollment\EnrollmentState;
use App\Domain\Support\Clock;
use App\Domain\Support\DefaultSecrets;
use PHPUnit\Framework\TestCase;

final class EnrollmentServiceTest extends TestCase
{
    private EnrollmentRepository $enrollments;
    private DeviceRepository $devices;
    /** @var object&Clock */
    private $clock;
    private EnrollmentService $svc;

    protected function setUp(): void
    {
        $this->enrollments = new class implements EnrollmentRepository {
            /** @var array<string,array<string,mixed>> */
            public array $d = [];
            public function get(string $id): ?array { return $this->d[$id] ?? null; }
            public function findByCodeHash(string $h): ?array { foreach ($this->d as $e) { if (($e['codeHash'] ?? null) === $h) { return $e; } } return null; }
            public function create(array $e): void { $this->d[$e['enrollmentId']] = $e; }
            public function update(string $id, array $f): void { $this->d[$id] = array_merge($this->d[$id] ?? [], $f); }
        };
        $this->devices = new class implements DeviceRepository {
            /** @var array<string,array<string,mixed>> */
            public array $d = [];
            public function get(string $id): ?array { return $this->d[$id] ?? null; }
            public function findByMachineCode(string $m): ?array { return null; }
            public function findByPairingId(string $pairingId): ?array { return null; }
            public function delete(string $deviceId): void {}
            public function list(array $f = [], int $l = 50): array { return array_values($this->d); }
            public function save(array $x): void { $this->d[$x['deviceId']] = $x; }
            public function update(string $id, array $f): void { $this->d[$id] = array_merge($this->d[$id] ?? ['deviceId' => $id], $f); }
        };
        $audit = new class implements AuditRepository {
            /** @var list<array<string,mixed>> */
            public array $log = [];
            public function append(array $e): void { $this->log[] = $e; }
            public function all(): array { return $this->log; }
        };
        $this->clock = new class implements Clock {
            public int $t = 1000;
            public function now(): int { return $this->t; }
        };
        $this->svc = new EnrollmentService($this->enrollments, $this->devices, $audit, $this->clock, new DefaultSecrets());
    }

    public function test_create_stores_only_hashes_and_returns_secrets_once(): void
    {
        $r = $this->svc->create('DEV-1', 'T-A', 'qr', 'admin', 900);
        self::assertArrayHasKey('code', $r);
        self::assertStringContainsString('vremote://enroll', $r['qrUri']);
        $stored = $this->enrollments->d[$r['enrollmentId']];
        self::assertSame(EnrollmentState::PENDING, $stored['status']);
        self::assertArrayNotHasKey('code', $stored);
        self::assertSame(64, strlen($stored['codeHash']));
    }

    public function test_consume_binds_device_and_issues_credential(): void
    {
        $r = $this->svc->create('DEV-1', 'T-A', 'qr', 'admin', 900);
        $res = $this->svc->consume($r['enrollmentId'], $r['code'], $r['token'], 'admin');
        self::assertSame('T-A', $res['ownerTenantId']);
        $dev = $this->devices->get('DEV-1');
        self::assertSame('T-A', $dev['ownerTenantId']);
        self::assertSame(EnrollmentState::ACTIVE, $dev['enrollmentState']);
        self::assertSame(CredentialState::ACTIVE, $dev['credentialState']);
        self::assertSame($res['deviceCredential'], $dev['deviceToken']);
    }

    public function test_reconsume_is_rejected(): void
    {
        $r = $this->svc->create('DEV-1', 'T-A', 'qr', 'admin', 900);
        $this->svc->consume($r['enrollmentId'], $r['code'], $r['token'], 'admin');
        $this->expectException(EnrollmentException::class);
        $this->svc->consume($r['enrollmentId'], $r['code'], $r['token'], 'admin');
    }

    public function test_wrong_credential_rejected(): void
    {
        $r = $this->svc->create('DEV-2', 'T-B', 'manual', 'admin', 900);
        try {
            $this->svc->consume($r['enrollmentId'], 'WRON-GXXX', $r['token'], 'admin');
            self::fail('expected rejection');
        } catch (EnrollmentException $e) {
            self::assertSame('invalid_credential', $e->errorCode);
        }
    }

    public function test_expired_enrollment_rejected(): void
    {
        $r = $this->svc->create('DEV-3', 'T-C', 'qr', 'admin', 60);
        $this->clock->t += 120;
        try {
            $this->svc->consume($r['enrollmentId'], $r['code'], $r['token'], 'admin');
            self::fail('expected expiry');
        } catch (EnrollmentException $e) {
            self::assertSame('expired', $e->errorCode);
        }
        self::assertSame(EnrollmentState::EXPIRED, $this->enrollments->d[$r['enrollmentId']]['status']);
    }

    public function test_reset_unassigns_and_revokes(): void
    {
        $r = $this->svc->create('DEV-1', 'T-A', 'qr', 'admin', 900);
        $this->svc->consume($r['enrollmentId'], $r['code'], $r['token'], 'admin');
        $this->svc->reset('DEV-1', 'device-admin', 'demo->real');
        $dev = $this->devices->get('DEV-1');
        self::assertNull($dev['ownerTenantId']);
        self::assertSame(EnrollmentState::UNENROLLED, $dev['enrollmentState']);
        self::assertSame(CredentialState::REVOKED, $dev['credentialState']);
        self::assertNull($dev['deviceToken']);
    }
}
