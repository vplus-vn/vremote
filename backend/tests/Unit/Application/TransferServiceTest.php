<?php

declare(strict_types=1);

namespace Tests\Unit\Application;

use App\Application\Transfer\TransferException;
use App\Application\Transfer\TransferService;
use App\Domain\Contracts\AuditRepository;
use App\Domain\Contracts\DeviceRepository;
use App\Domain\Contracts\DeviceTransferRepository;
use App\Domain\Transfer\TransferState;
use PHPUnit\Framework\TestCase;

final class TransferServiceTest extends TestCase
{
    private DeviceTransferRepository $transfers;
    private DeviceRepository $devices;
    private TransferService $svc;

    protected function setUp(): void
    {
        $this->transfers = new class implements DeviceTransferRepository {
            /** @var array<string,array<string,mixed>> */
            public array $d = [];
            public function get(string $id): ?array { return $this->d[$id] ?? null; }
            public function create(array $t): void { $this->d[$t['transferId']] = $t; }
            public function update(string $id, array $f): void { $this->d[$id] = array_merge($this->d[$id] ?? [], $f); }
        };
        $this->devices = new class implements DeviceRepository {
            /** @var array<string,array<string,mixed>> */
            public array $d = ['DEV-1' => ['deviceId' => 'DEV-1', 'ownerTenantId' => 'T-A']];
            public function get(string $id): ?array { return $this->d[$id] ?? null; }
            public function findByMachineCode(string $m): ?array { return null; }
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
        $this->svc = new TransferService($this->transfers, $this->devices, $audit);
    }

    public function test_full_transfer_moves_ownership_on_complete(): void
    {
        $r = $this->svc->request('DEV-1', 'T-B', 'admin', 'partner move');
        self::assertSame(TransferState::PENDING, $r['status']);
        $this->svc->approve($r['transferId'], 'boss');
        $this->svc->complete($r['transferId'], 'boss');
        self::assertSame(TransferState::COMPLETED, $this->transfers->d[$r['transferId']]['status']);
        self::assertSame('T-B', $this->devices->get('DEV-1')['ownerTenantId']);
    }

    public function test_complete_before_approve_is_illegal(): void
    {
        $r = $this->svc->request('DEV-1', 'T-B', 'admin');
        $this->expectException(TransferException::class);
        $this->svc->complete($r['transferId'], 'admin');
    }

    public function test_same_tenant_target_rejected(): void
    {
        try {
            $this->svc->request('DEV-1', 'T-A', 'admin');
            self::fail('expected invalid_target');
        } catch (TransferException $e) {
            self::assertSame('invalid_target', $e->errorCode);
        }
    }
}
