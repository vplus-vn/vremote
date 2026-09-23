<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Contracts\AuditRepository;
use App\Domain\Contracts\DeviceRepository;
use App\Domain\Enrollment\EnrollmentState;
use App\Domain\Platform\PlatformContext;
use App\Domain\Support\Clock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Platform\Core\Http\ApiResponder;

/**
 * Device registry API. Reads the durable device store (MongoDB in production, the
 * transitional config store otherwise) via DeviceRepository — the same store the
 * enrollment lifecycle writes to — so a device enrolled from an agent or registered
 * from the console shows up here, instead of the old env-JSON demo list.
 */
final class DeviceController
{
    public function index(Request $request, DeviceRepository $devices): JsonResponse
    {
        $status = (string) $request->query('status', 'all');
        abort_unless(in_array($status, ['online', 'offline', 'all'], true), 400);
        $limit = min(max((int) $request->query('limit', 50), 1), 100);

        $filters = [];
        if ($status !== 'all') {
            $filters['status'] = $status;
        }
        // Multi-tenant scoping: a full-access admin (Platform perms:["*"]) sees every
        // device; anyone else sees only their own tenant's devices.
        $ctx = $this->context($request);
        if ($ctx !== null && ! in_array('*', $ctx->permissions, true) && $ctx->tenantId) {
            $filters['tenantId'] = $ctx->tenantId;
        }

        $items = array_map(fn (array $d): array => $this->present($d), $devices->list($filters, $limit));

        return response()->json(['items' => array_values($items), 'nextCursor' => null]);
    }

    public function show(string $deviceId, DeviceRepository $devices): JsonResponse
    {
        $d = $devices->get($deviceId);
        if ($d === null) {
            return response()->json(['error' => ['code' => 'NOT_FOUND', 'message' => 'Device not found']], 404);
        }

        return response()->json($this->present($d));
    }

    /**
     * Register a device from the server (console-driven onboarding). Creates an
     * UNENROLLED device row bound to the caller's tenant; the agent then completes
     * enrollment with a code generated via POST /devices/{id}/enrollments.
     */
    public function store(Request $request, DeviceRepository $devices, Clock $clock, AuditRepository $audit): JsonResponse
    {
        if (($resp = $this->requirePermission($request, 'edge.admin.control')) !== null) {
            return $resp;
        }

        $data = $request->validate([
            'deviceId' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9._-]+$/'],
            'machineCode' => ['required', 'string', 'max:128'],
            'name' => ['nullable', 'string', 'max:128'],
            'platform' => ['nullable', 'in:android'],
            'tenantId' => ['nullable', 'string', 'max:128'],
        ]);

        $ctx = $this->context($request);
        $tenantId = $data['tenantId'] ?? $ctx?->tenantId;
        if (! $tenantId) {
            return $this->error('INVALID_ARGUMENT', 'tenantId is required', 422);
        }

        $deviceId = $data['deviceId'] ?? ('DEV-' . strtoupper(bin2hex(random_bytes(4))));
        if ($devices->get($deviceId) !== null) {
            return $this->error('ALREADY_EXISTS', 'deviceId already registered', 409);
        }
        if ($devices->findByMachineCode($data['machineCode']) !== null) {
            return $this->error('ALREADY_EXISTS', 'machineCode already registered', 409);
        }

        $now = $clock->now();
        $actor = $this->actor($request);
        $device = [
            'deviceId' => $deviceId,
            'machineCode' => $data['machineCode'],
            'name' => $data['name'] ?? $data['machineCode'],
            'platform' => $data['platform'] ?? 'android',
            'status' => 'offline',
            'enrollmentState' => EnrollmentState::UNENROLLED,
            'ownerTenantId' => (string) $tenantId,
            'agentVersion' => null,
            'activeSessions' => 0,
            'lastSeenAt' => null,
            'createdBy' => $actor,
            'createdAt' => $now,
        ];
        $devices->save($device);

        $audit->append([
            'action' => 'DEVICE_REGISTER', 'deviceId' => $deviceId, 'tenantId' => (string) $tenantId,
            'actor' => $actor, 'result' => 'created',
        ]);

        return response()->json($this->present($device), 201);
    }

    /** Delete a device (cleanup / de-registration). Requires edge.admin.control. */
    public function destroy(Request $request, string $deviceId, DeviceRepository $devices, AuditRepository $audit): JsonResponse
    {
        if (($resp = $this->requirePermission($request, 'edge.admin.control')) !== null) {
            return $resp;
        }
        $device = $devices->get($deviceId);
        if ($device === null) {
            return response()->json(['error' => ['code' => 'NOT_FOUND', 'message' => 'Device not found']], 404);
        }
        // Tenant scoping: a non-admin may only delete devices in their own tenant.
        $ctx = $this->context($request);
        if ($ctx !== null && ! in_array('*', $ctx->permissions, true) && $ctx->tenantId
            && ($device['ownerTenantId'] ?? null) !== null && (string) $device['ownerTenantId'] !== (string) $ctx->tenantId) {
            return $this->error('FORBIDDEN', 'device belongs to another tenant', 403);
        }

        $devices->delete($deviceId);
        $audit->append([
            'action' => 'DEVICE_DELETE', 'deviceId' => $deviceId, 'tenantId' => $device['ownerTenantId'] ?? null,
            'actor' => $this->actor($request), 'result' => 'deleted',
        ]);

        return response()->json(['deviceId' => $deviceId, 'deleted' => true]);
    }

    /**
     * Update an editable field of a device (currently just its display name / alias). Requires
     * edge.admin.control. machineCode is the device's identity key (used to re-match on announce)
     * and is intentionally NOT editable here.
     */
    public function update(Request $request, string $deviceId, DeviceRepository $devices, AuditRepository $audit): JsonResponse
    {
        if (($resp = $this->requirePermission($request, 'edge.admin.control')) !== null) {
            return $resp;
        }
        $device = $devices->get($deviceId);
        if ($device === null) {
            return response()->json(['error' => ['code' => 'NOT_FOUND', 'message' => 'Device not found']], 404);
        }
        // Tenant scoping: a non-admin may only edit devices in their own tenant.
        $ctx = $this->context($request);
        if ($ctx !== null && ! in_array('*', $ctx->permissions, true) && $ctx->tenantId
            && ($device['ownerTenantId'] ?? null) !== null && (string) $device['ownerTenantId'] !== (string) $ctx->tenantId) {
            return $this->error('FORBIDDEN', 'device belongs to another tenant', 403);
        }

        $data = $request->validate(['name' => ['present', 'nullable', 'string', 'max:128']]);
        $name = isset($data['name']) ? trim((string) $data['name']) : '';
        $devices->update($deviceId, ['name' => $name !== '' ? $name : null]);
        $audit->append([
            'action' => 'DEVICE_UPDATE', 'deviceId' => $deviceId, 'tenantId' => $device['ownerTenantId'] ?? null,
            'actor' => $this->actor($request), 'result' => 'renamed',
        ]);

        return response()->json($this->present(array_merge($device, ['name' => $name !== '' ? $name : null])));
    }

    /**
     * Public view of a device row — never leaks the device signing credential.
     *
     * @param array<string,mixed> $d
     * @return array<string,mixed>
     */
    private function present(array $d): array
    {
        return [
            'deviceId' => (string) ($d['deviceId'] ?? ''),
            'pairingId' => $d['pairingId'] ?? null,
            'machineCode' => $d['machineCode'] ?? null,
            'name' => $d['name'] ?? null,
            'platform' => $d['platform'] ?? 'android',
            'status' => $d['status'] ?? 'offline',
            'enrollmentState' => $d['enrollmentState'] ?? EnrollmentState::UNENROLLED,
            'requireAccept' => (bool) ($d['requireAccept'] ?? false),
            'agentVersion' => $d['agentVersion'] ?? null,
            'lastSeenAt' => $d['lastSeenAt'] ?? null,
            'createdAt' => $d['createdAt'] ?? null,
            'activeSessions' => $d['activeSessions'] ?? 0,
            'deviceInfo' => $d['deviceInfo'] ?? null,
            'lastEvent' => $d['lastEvent'] ?? null,
        ];
    }

    private function context(Request $request): ?PlatformContext
    {
        $ctx = $request->attributes->get('platformContext');

        return $ctx instanceof PlatformContext ? $ctx : null;
    }

    private function actor(Request $request): string
    {
        return (string) $request->attributes->get('actor', 'unknown');
    }

    private function requirePermission(Request $request, string $permission): ?JsonResponse
    {
        $permissions = (array) $request->attributes->get('permissions', []);
        if (! in_array($permission, $permissions, true)) {
            return $this->error('FORBIDDEN', $permission . ' required', 403);
        }

        return null;
    }

    // [v0.1.1] Chuẩn hoá core-lib — dựng envelope lỗi bằng core-lib ApiResponder (2026-09-23)
    private function error(string $code, string $message, int $status): JsonResponse
    {
        return ApiResponder::fail($code, $message, [], $status);
    }
}
