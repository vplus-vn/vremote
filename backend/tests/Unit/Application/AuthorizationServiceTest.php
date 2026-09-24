<?php

declare(strict_types=1);

namespace Tests\Unit\Application;

use App\Application\Authorization\AuthorizationService;
use App\Domain\Authorization\AccessGrant;
use App\Domain\Authorization\DeviceAuthorizer;
use App\Domain\Contracts\AccessGrantRepository;
use App\Domain\Contracts\DeviceRepository;
use App\Domain\Platform\PlatformContext;
use PHPUnit\Framework\TestCase;

final class AuthorizationServiceTest extends TestCase
{
    /**
     * @param array<string,array<string,mixed>> $devices
     * @param list<AccessGrant> $grants
     */
    private function service(array $devices, array $grants = []): AuthorizationService
    {
        $deviceRepo = new class($devices) implements DeviceRepository {
            /** @param array<string,array<string,mixed>> $devices */
            public function __construct(private array $devices) {}
            public function get(string $deviceId): ?array { return $this->devices[$deviceId] ?? null; }
            public function findByMachineCode(string $machineCode): ?array { return null; }
            public function findByPairingId(string $pairingId): ?array { return null; }
            public function delete(string $deviceId): void {}
            public function list(array $filters = [], int $limit = 50): array { return array_values($this->devices); }
            public function save(array $device): void {}
            public function update(string $deviceId, array $fields): void {}
        };
        $grantRepo = new class($grants) implements AccessGrantRepository {
            /** @param list<AccessGrant> $grants */
            public function __construct(private array $grants) {}
            public function activeForDevice(string $deviceId, int $nowEpoch): array {
                return array_values(array_filter($this->grants, fn (AccessGrant $g) => $g->deviceId === $deviceId && $g->isActive($nowEpoch)));
            }
            public function get(string $grantId): ?array { return null; }
            public function create(array $grant): void {}
            public function revoke(string $grantId, int $revokedAtEpoch): void {}
        };

        return new AuthorizationService($deviceRepo, $grantRepo, new DeviceAuthorizer());
    }

    public function test_owner_tenant_allowed(): void
    {
        $svc = $this->service(['DEV-1' => ['deviceId' => 'DEV-1', 'ownerTenantId' => 'T-A']]);
        $ctx = new PlatformContext('u1', 'T-A', [], ['edge.admin.view']);
        self::assertTrue($svc->canActOnDevice($ctx, 'DEV-1', 'edge.admin.view'));
    }

    public function test_other_tenant_denied(): void
    {
        $svc = $this->service(['DEV-1' => ['deviceId' => 'DEV-1', 'ownerTenantId' => 'T-A']]);
        $ctx = new PlatformContext('u1', 'T-B', [], ['edge.admin.view']);
        self::assertFalse($svc->canActOnDevice($ctx, 'DEV-1', 'edge.admin.view'));
    }

    public function test_unknown_device_denied(): void
    {
        $svc = $this->service([]);
        $ctx = new PlatformContext('u1', 'T-A', [], ['edge.admin.view']);
        self::assertFalse($svc->canActOnDevice($ctx, 'DEV-x', 'edge.admin.view'));
    }

    public function test_support_grant_allows_cross_tenant(): void
    {
        $grant = new AccessGrant('G1', 'DEV-1', 'support-1', null, ['edge.admin.view'], 2_000_000_000);
        $svc = $this->service(['DEV-1' => ['deviceId' => 'DEV-1', 'ownerTenantId' => 'T-A']], [$grant]);
        $ctx = new PlatformContext('support-1', 'T-SUPPORT');
        self::assertTrue($svc->canActOnDevice($ctx, 'DEV-1', 'edge.admin.view', 1_000));
    }
}
