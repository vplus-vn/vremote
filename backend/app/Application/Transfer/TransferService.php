<?php

declare(strict_types=1);

namespace App\Application\Transfer;

use App\Domain\Contracts\AuditRepository;
use App\Domain\Contracts\DeviceRepository;
use App\Domain\Contracts\DeviceTransferRepository;
use App\Domain\Transfer\TransferState;

/**
 * Device transfer between tenants (DSG-VREMOTE-001 §7, DSG-VREMOTE-002 §7).
 * Framework-free; keeps the Device Identity and only changes ownerTenantId on
 * completion. Historical domain data is NOT carried to the new tenant.
 */
final class TransferService
{
    public function __construct(
        private readonly DeviceTransferRepository $transfers,
        private readonly DeviceRepository $devices,
        private readonly AuditRepository $audit,
    ) {
    }

    /** @return array{transferId:string,status:string} */
    public function request(string $deviceId, string $toTenantId, string $actor, string $reason = ''): array
    {
        $device = $this->devices->get($deviceId);
        if ($device === null) {
            throw new TransferException('not_found', 'device not found');
        }
        $fromTenantId = isset($device['ownerTenantId']) ? (string) $device['ownerTenantId'] : null;
        if ($toTenantId === '' || $toTenantId === $fromTenantId) {
            throw new TransferException('invalid_target', 'toTenantId must differ from the current owner');
        }

        $transferId = 'TRF-' . strtoupper(bin2hex(random_bytes(6)));
        $now = time();
        $this->transfers->create([
            'transferId' => $transferId, 'deviceId' => $deviceId,
            'fromTenantId' => $fromTenantId, 'toTenantId' => $toTenantId,
            'status' => TransferState::PENDING, 'requestedBy' => $actor, 'reason' => $reason,
            'createdAt' => $now, 'approvedBy' => null, 'completedAt' => null,
        ]);
        $this->audit->append([
            'action' => 'DEVICE_TRANSFER_REQUEST', 'deviceId' => $deviceId, 'transferId' => $transferId,
            'fromTenant' => $fromTenantId, 'toTenant' => $toTenantId, 'actor' => $actor, 'result' => 'pending',
        ]);

        return ['transferId' => $transferId, 'status' => TransferState::PENDING];
    }

    public function approve(string $transferId, string $actor): void
    {
        $this->transition($transferId, TransferState::APPROVED, ['approvedBy' => $actor], 'DEVICE_TRANSFER_APPROVE');
    }

    public function reject(string $transferId, string $actor): void
    {
        $this->transition($transferId, TransferState::REJECTED, ['rejectedBy' => $actor], 'DEVICE_TRANSFER_REJECT');
    }

    public function complete(string $transferId, string $actor): void
    {
        $t = $this->requireTransfer($transferId);
        TransferState::canTransition((string) $t['status'], TransferState::COMPLETED)
            || throw new TransferException('illegal_state', 'transfer is not approved');

        $now = time();
        // Ownership changes here; Device Identity is preserved.
        $this->devices->update((string) $t['deviceId'], ['ownerTenantId' => (string) $t['toTenantId']]);
        $this->transfers->update($transferId, ['status' => TransferState::COMPLETED, 'completedAt' => $now, 'completedBy' => $actor]);
        $this->audit->append([
            'action' => 'DEVICE_TRANSFER_COMPLETE', 'deviceId' => $t['deviceId'], 'transferId' => $transferId,
            'fromTenant' => $t['fromTenantId'] ?? null, 'toTenant' => $t['toTenantId'] ?? null,
            'actor' => $actor, 'result' => 'completed',
        ]);
    }

    /** @param array<string,mixed> $extra */
    private function transition(string $transferId, string $to, array $extra, string $auditAction): void
    {
        $t = $this->requireTransfer($transferId);
        $from = (string) $t['status'];
        if (! TransferState::canTransition($from, $to)) {
            throw new TransferException('illegal_state', "cannot move transfer {$from} -> {$to}");
        }
        $this->transfers->update($transferId, ['status' => $to] + $extra);
        $this->audit->append([
            'action' => $auditAction, 'deviceId' => $t['deviceId'] ?? null, 'transferId' => $transferId,
            'actor' => $extra['approvedBy'] ?? $extra['rejectedBy'] ?? null, 'result' => strtolower($to),
        ]);
    }

    /** @return array<string,mixed> */
    private function requireTransfer(string $transferId): array
    {
        $t = $this->transfers->get($transferId);
        if ($t === null) {
            throw new TransferException('not_found', 'transfer not found');
        }

        return $t;
    }
}
