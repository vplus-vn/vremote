<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Application\Transfer\TransferException;
use App\Application\Transfer\TransferService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Device transfer API (DSG-VREMOTE-002 §7). Domain endpoints — not part of the
 * locked api/vremote v0.0.1 contract. Requires the Platform context middleware.
 */
final class TransferController
{
    public function store(Request $request, string $deviceId, TransferService $service): JsonResponse
    {
        if (($resp = $this->requirePermission($request, 'edge.admin.control')) !== null) {
            return $resp;
        }
        $data = $request->validate([
            'toTenantId' => ['required', 'string', 'max:128'],
            'reason' => ['nullable', 'string', 'max:256'],
        ]);

        try {
            $result = $service->request($deviceId, $data['toTenantId'], $this->actor($request), (string) ($data['reason'] ?? ''));
        } catch (TransferException $e) {
            return $this->error(strtoupper($e->errorCode), $e->getMessage(), $e->errorCode === 'not_found' ? 404 : 422);
        }

        return response()->json($result, 201);
    }

    public function approve(Request $request, string $transferId, TransferService $service): JsonResponse
    {
        return $this->act($request, fn () => $service->approve($transferId, $this->actor($request)), ['transferId' => $transferId, 'status' => 'APPROVED']);
    }

    public function reject(Request $request, string $transferId, TransferService $service): JsonResponse
    {
        return $this->act($request, fn () => $service->reject($transferId, $this->actor($request)), ['transferId' => $transferId, 'status' => 'REJECTED']);
    }

    public function complete(Request $request, string $transferId, TransferService $service): JsonResponse
    {
        return $this->act($request, fn () => $service->complete($transferId, $this->actor($request)), ['transferId' => $transferId, 'status' => 'COMPLETED']);
    }

    private function act(Request $request, callable $op, array $ok): JsonResponse
    {
        if (($resp = $this->requirePermission($request, 'edge.admin.control')) !== null) {
            return $resp;
        }
        try {
            $op();
        } catch (TransferException $e) {
            return $this->error(strtoupper($e->errorCode), $e->getMessage(), $e->errorCode === 'not_found' ? 404 : 409);
        }

        return response()->json($ok, 200);
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
