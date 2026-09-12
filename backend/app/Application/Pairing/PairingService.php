<?php

declare(strict_types=1);

namespace App\Application\Pairing;

use App\Domain\Contracts\AuditRepository;
use App\Domain\Contracts\DeviceRepository;
use App\Domain\Device\CredentialState;
use App\Domain\Enrollment\EnrollmentState;
use App\Domain\Support\Clock;
use App\Domain\Support\Secrets;

/**
 * Device-initiated pairing (UltraViewer / TeamViewer style), complementing the
 * server-initiated enrollment flow.
 *
 *  - The agent announces itself and shows a stable ID (pairingId) plus a random
 *    per-launch session password, and optionally a fixed "personal password" for
 *    unattended access. Only password HASHES reach the server.
 *  - An operator connects by entering ID + password on the console. If the device
 *    requires accept, the agent must approve on-device before a session is granted;
 *    otherwise the grant is issued immediately (unattended).
 *
 * Pairing state lives on the device document (one pending connect at a time) to
 * avoid an extra collection. Framework-free for unit-testability.
 */
final class PairingService
{
    private const SESSION_PW_TTL = 86400;   // session password valid until the next launch (24h cap)
    private const CONNECT_TTL = 120;        // seconds an operator waits for on-device accept
    private const GRANT_TTL = 120;          // seconds a connect grant may be redeemed for a session

    public function __construct(
        private readonly DeviceRepository $devices,
        private readonly AuditRepository $audit,
        private readonly Clock $clock,
        private readonly Secrets $secrets,
    ) {
    }

    /**
     * Agent announces on launch. Creates the device on first sight (assigning a stable
     * pairingId + a signaling credential returned once), and records the current session
     * password hash. Personal password / requireAccept are updated when provided.
     *
     * @return array{deviceId:string,pairingId:string,deviceToken:string,requireAccept:bool}
     */
    public function announce(
        string $machineCode,
        string $agentVersion,
        string $platform,
        string $sessionPasswordHash,
        ?string $personalPasswordHash,
        ?bool $requireAccept,
    ): array {
        if ($machineCode === '' || $sessionPasswordHash === '') {
            throw new PairingException('invalid_argument', 'machineCode and sessionPasswordHash are required');
        }

        $now = $this->clock->now();
        $device = $this->devices->findByMachineCode($machineCode);

        if ($device === null) {
            $deviceId = 'DEV-' . strtoupper(bin2hex(random_bytes(4)));
            $pairingId = $this->uniquePairingId();
            $deviceToken = $this->secrets->token(32);
            $device = [
                'deviceId' => $deviceId,
                'pairingId' => $pairingId,
                'machineCode' => $machineCode,
                'name' => $machineCode,
                'platform' => $platform ?: 'android',
                'deviceToken' => $deviceToken,      // signaling credential (never returned by device API)
                'ownerTenantId' => null,            // assigned by the first successful connect
                'enrollmentState' => EnrollmentState::UNENROLLED,
                'credentialState' => CredentialState::ACTIVE,
                'requireAccept' => true,
                'createdAt' => $now,
            ];
        }

        $deviceId = (string) $device['deviceId'];
        $pairingId = (string) ($device['pairingId'] ?? $this->uniquePairingId());
        $deviceToken = (string) ($device['deviceToken'] ?? $this->secrets->token(32));

        $fields = [
            'pairingId' => $pairingId,
            'deviceToken' => $deviceToken,
            'agentVersion' => $agentVersion,
            'platform' => $platform ?: ($device['platform'] ?? 'android'),
            'status' => 'online',
            'lastSeenAt' => $now,
            'sessionPwHash' => $sessionPasswordHash,
            'sessionPwExpiresAt' => $now + self::SESSION_PW_TTL,
            'source' => 'pairing',
        ];
        if ($personalPasswordHash !== null) {
            $fields['personalPwHash'] = $personalPasswordHash !== '' ? $personalPasswordHash : null;
        }
        if ($requireAccept !== null) {
            $fields['requireAccept'] = $requireAccept;
        }
        $this->devices->update($deviceId, $fields);

        return [
            'deviceId' => $deviceId,
            'pairingId' => $pairingId,
            'deviceToken' => $deviceToken,
            'requireAccept' => (bool) ($requireAccept ?? $device['requireAccept'] ?? true),
        ];
    }

    /**
     * Operator connects by ID + password. Verifies against the personal password or the
     * live session password. When the device requires accept, records a pending request the
     * agent must approve; otherwise issues a connect grant immediately.
     *
     * @return array{status:string,deviceId:string,requestId?:string,connectGrant?:string}
     */
    public function connect(string $pairingId, string $password, string $actor, ?string $tenantId): array
    {
        $device = $this->devices->findByPairingId($this->normalizeId($pairingId));
        if ($device === null) {
            throw new PairingException('not_found', 'device ID not found');
        }
        if (! $this->passwordMatches($device, $password)) {
            $this->audit->append([
                'action' => 'PAIR_CONNECT', 'deviceId' => $device['deviceId'] ?? null,
                'actor' => $actor, 'result' => 'invalid_credential',
            ]);
            throw new PairingException('invalid_credential', 'wrong password');
        }

        $deviceId = (string) $device['deviceId'];
        $now = $this->clock->now();

        // First operator to connect claims the device for their tenant; later connects must match.
        $owner = $device['ownerTenantId'] ?? null;
        if ($owner === null && $tenantId) {
            $this->devices->update($deviceId, ['ownerTenantId' => $tenantId]);
        } elseif ($owner !== null && $tenantId !== null && (string) $owner !== (string) $tenantId) {
            throw new PairingException('forbidden', 'device belongs to another tenant');
        }

        if ((bool) ($device['requireAccept'] ?? true)) {
            $requestId = 'REQ-' . strtoupper(bin2hex(random_bytes(6)));
            $this->devices->update($deviceId, ['pendingConnect' => [
                'requestId' => $requestId, 'actor' => $actor, 'tenantId' => $tenantId,
                'status' => 'PENDING', 'createdAt' => $now, 'expiresAt' => $now + self::CONNECT_TTL,
            ]]);
            $this->audit->append(['action' => 'PAIR_CONNECT', 'deviceId' => $deviceId, 'actor' => $actor, 'result' => 'awaiting_accept']);

            return ['status' => 'awaiting_accept', 'deviceId' => $deviceId, 'requestId' => $requestId];
        }

        $grant = $this->issueGrant($deviceId, $actor, $now);
        $this->audit->append(['action' => 'PAIR_CONNECT', 'deviceId' => $deviceId, 'actor' => $actor, 'result' => 'ready']);

        return ['status' => 'ready', 'deviceId' => $deviceId, 'connectGrant' => $grant];
    }

    /**
     * Operator polls a pending connect. Returns the grant once the agent has approved.
     *
     * @return array{status:string,deviceId:string,connectGrant?:string}
     */
    public function connectStatus(string $deviceId, string $requestId, string $actor): array
    {
        $device = $this->devices->get($deviceId);
        $pending = is_array($device['pendingConnect'] ?? null) ? $device['pendingConnect'] : null;
        if ($device === null || $pending === null || ($pending['requestId'] ?? '') !== $requestId || ($pending['actor'] ?? '') !== $actor) {
            throw new PairingException('not_found', 'connect request not found');
        }

        $now = $this->clock->now();
        $status = (string) ($pending['status'] ?? 'PENDING');
        if ($status === 'PENDING' && (int) ($pending['expiresAt'] ?? 0) <= $now) {
            $this->devices->update($deviceId, ['pendingConnect' => null]);

            throw new PairingException('expired', 'connect request timed out');
        }
        if ($status === 'REJECTED') {
            $this->devices->update($deviceId, ['pendingConnect' => null]);

            throw new PairingException('rejected', 'the device rejected the connection');
        }
        if ($status === 'APPROVED') {
            $grant = $this->issueGrant($deviceId, $actor, $now);
            $this->devices->update($deviceId, ['pendingConnect' => null]);

            return ['status' => 'ready', 'deviceId' => $deviceId, 'connectGrant' => $grant];
        }

        return ['status' => 'awaiting_accept', 'deviceId' => $deviceId];
    }

    /** Agent polls for a pending connect it must accept (authenticated by its deviceToken). */
    public function pendingForAgent(string $deviceId, string $deviceToken): ?array
    {
        $device = $this->authAgent($deviceId, $deviceToken);
        $pending = is_array($device['pendingConnect'] ?? null) ? $device['pendingConnect'] : null;
        if ($pending === null || ($pending['status'] ?? '') !== 'PENDING') {
            return null;
        }
        if ((int) ($pending['expiresAt'] ?? 0) <= $this->clock->now()) {
            return null;
        }

        return ['requestId' => (string) $pending['requestId'], 'actor' => (string) ($pending['actor'] ?? 'operator')];
    }

    /** Agent approves/rejects a pending connect. */
    public function respond(string $deviceId, string $deviceToken, string $requestId, bool $accept): void
    {
        $device = $this->authAgent($deviceId, $deviceToken);
        $pending = is_array($device['pendingConnect'] ?? null) ? $device['pendingConnect'] : null;
        if ($pending === null || ($pending['requestId'] ?? '') !== $requestId) {
            throw new PairingException('not_found', 'no matching connect request');
        }
        $pending['status'] = $accept ? 'APPROVED' : 'REJECTED';
        $this->devices->update($deviceId, ['pendingConnect' => $pending]);
        $this->audit->append(['action' => 'PAIR_ACCEPT', 'deviceId' => $deviceId, 'result' => $accept ? 'approved' : 'rejected']);
    }

    /** Agent sets or clears its unattended personal password, and toggles the accept policy. */
    public function updatePolicy(string $deviceId, string $deviceToken, ?string $personalPasswordHash, ?bool $requireAccept): void
    {
        $this->authAgent($deviceId, $deviceToken);
        $fields = [];
        if ($personalPasswordHash !== null) {
            $fields['personalPwHash'] = $personalPasswordHash !== '' ? $personalPasswordHash : null;
        }
        if ($requireAccept !== null) {
            $fields['requireAccept'] = $requireAccept;
        }
        if ($fields !== []) {
            $this->devices->update($deviceId, $fields);
        }
    }

    /**
     * Redeem a connect grant when opening a session (called by SessionController for
     * pairing-managed devices). One-time: the grant is cleared on success.
     */
    public function redeemGrant(array $device, string $actor, string $grant): bool
    {
        $g = is_array($device['connectGrant'] ?? null) ? $device['connectGrant'] : null;
        if ($g === null || $grant === '') {
            return false;
        }
        $ok = ($g['actor'] ?? '') === $actor
            && (int) ($g['expiresAt'] ?? 0) > $this->clock->now()
            && $this->secrets->verify($grant, (string) ($g['tokenHash'] ?? ''));
        if ($ok) {
            $this->devices->update((string) $device['deviceId'], ['connectGrant' => null]);
        }

        return $ok;
    }

    /** Whether a device is pairing-managed (has any password set) and therefore gated by a grant. */
    public static function isPairingManaged(array $device): bool
    {
        return ! empty($device['personalPwHash']) || ! empty($device['sessionPwHash']);
    }

    private function issueGrant(string $deviceId, string $actor, int $now): string
    {
        $grant = $this->secrets->token(24);
        $this->devices->update($deviceId, ['connectGrant' => [
            'actor' => $actor, 'tokenHash' => $this->secrets->hash($grant), 'expiresAt' => $now + self::GRANT_TTL,
        ]]);

        return $grant;
    }

    /** @param array<string,mixed> $device */
    private function passwordMatches(array $device, string $password): bool
    {
        if ($password === '') {
            return false;
        }
        $personal = (string) ($device['personalPwHash'] ?? '');
        if ($personal !== '' && $this->secrets->verify($password, $personal)) {
            return true;
        }
        $session = (string) ($device['sessionPwHash'] ?? '');
        $notExpired = (int) ($device['sessionPwExpiresAt'] ?? 0) > $this->clock->now();

        return $session !== '' && $notExpired && $this->secrets->verify($password, $session);
    }

    /** @return array<string,mixed> */
    private function authAgent(string $deviceId, string $deviceToken): array
    {
        $device = $this->devices->get($deviceId);
        if ($device === null || $deviceToken === '' || ! hash_equals((string) ($device['deviceToken'] ?? ''), $deviceToken)) {
            throw new PairingException('unauthorized', 'invalid device credential');
        }

        return $device;
    }

    private function uniquePairingId(): string
    {
        for ($i = 0; $i < 8; $i++) {
            $id = (string) random_int(100_000_000, 999_999_999); // 9 digits, TeamViewer-like
            if ($this->devices->findByPairingId($id) === null) {
                return $id;
            }
        }

        return (string) random_int(100_000_000, 999_999_999);
    }

    private function normalizeId(string $pairingId): string
    {
        return preg_replace('/\D+/', '', $pairingId) ?? '';
    }
}
