<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Application\Enrollment\EnrollmentException;
use App\Application\Enrollment\EnrollmentService;
use App\Domain\Platform\PlatformContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Device enrollment API (DSG-VREMOTE-002 §7). Domain endpoints — not part of the
 * locked api/vremote v0.0.1 contract. Requires the Platform context middleware.
 */
final class EnrollmentController
{
    public function store(Request $request, string $deviceId, EnrollmentService $service): JsonResponse
    {
        if (($resp = $this->requirePermission($request, 'edge.admin.control')) !== null) {
            return $resp;
        }
        $data = $request->validate([
            'method' => ['required', 'in:qr,manual'],
            'tenantId' => ['nullable', 'string', 'max:128'],
            'ttlSec' => ['nullable', 'integer', 'min:60', 'max:3600'],
        ]);

        $ctx = $this->context($request);
        $tenantId = $data['tenantId'] ?? $ctx?->tenantId;
        if (! $tenantId) {
            return $this->error('INVALID_ARGUMENT', 'tenantId is required', 422);
        }

        try {
            $result = $service->create($deviceId, (string) $tenantId, $data['method'], $this->actor($request), (int) ($data['ttlSec'] ?? 900));
        } catch (EnrollmentException $e) {
            return $this->error(strtoupper($e->errorCode), $e->getMessage(), 422);
        }

        // Plaintext code/token are returned ONCE for display/QR; they are not persisted.
        return response()->json($result, 201);
    }

    public function consume(Request $request, string $enrollmentId, EnrollmentService $service): JsonResponse
    {
        if (($resp = $this->requirePermission($request, 'edge.admin.control')) !== null) {
            return $resp;
        }
        $data = $request->validate([
            'code' => ['required', 'string', 'max:64'],
            'token' => ['required', 'string', 'max:256'],
        ]);

        try {
            $result = $service->consume($enrollmentId, $data['code'], $data['token'], $this->actor($request));
        } catch (EnrollmentException $e) {
            $status = $e->errorCode === 'not_found' ? 404 : ($e->errorCode === 'invalid_credential' ? 403 : 409);

            return $this->error(strtoupper($e->errorCode), $e->getMessage(), $status);
        }

        // deviceCredential is returned ONCE for provisioning into the agent keystore.
        return response()->json($result, 200);
    }

    public function reset(Request $request, string $deviceId, EnrollmentService $service): JsonResponse
    {
        // Protected action. Backend enforces elevated permission + explicit confirm;
        // the on-device local-authorization requirement (DSG-VREMOTE-001 §9.5) is
        // enforced by the agent, not here.
        if (($resp = $this->requirePermission($request, 'edge.admin.control')) !== null) {
            return $resp;
        }
        $data = $request->validate([
            'confirm' => ['required', 'boolean'],
            'reason' => ['nullable', 'string', 'max:256'],
        ]);
        if ($data['confirm'] !== true) {
            return $this->error('CONFIRM_REQUIRED', 'confirm=true required', 403);
        }

        try {
            $service->reset($deviceId, $this->actor($request), (string) ($data['reason'] ?? 'unspecified'));
        } catch (EnrollmentException $e) {
            return $this->error(strtoupper($e->errorCode), $e->getMessage(), $e->errorCode === 'not_found' ? 404 : 409);
        }

        return response()->json(['deviceId' => $deviceId, 'enrollmentState' => 'UNENROLLED'], 200);
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

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return response()->json(['error' => ['code' => $code, 'message' => $message]], $status);
    }
}
