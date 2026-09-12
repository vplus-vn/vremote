<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Application\Pairing\PairingException;
use App\Application\Pairing\PairingService;
use App\Domain\Platform\PlatformContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Device-initiated pairing (UltraViewer/TeamViewer style).
 *
 * Agent endpoints (announce/pending/respond/policy) are device-facing: no Platform token,
 * authenticated by the device credential where it matters. Console endpoints
 * (connect/connectStatus) sit behind the Platform context middleware.
 */
final class PairingController
{
    // ---- Agent (device-facing) ----

    public function announce(Request $request, PairingService $pairing): JsonResponse
    {
        $data = $request->validate([
            'machineCode' => ['required', 'string', 'max:128'],
            'agentVersion' => ['nullable', 'string', 'max:64'],
            'platform' => ['nullable', 'string', 'max:32'],
            'sessionPasswordHash' => ['required', 'string', 'max:128'],
            'personalPasswordHash' => ['nullable', 'string', 'max:128'],
            'requireAccept' => ['nullable', 'boolean'],
        ]);

        try {
            $result = $pairing->announce(
                $data['machineCode'],
                (string) ($data['agentVersion'] ?? ''),
                (string) ($data['platform'] ?? 'android'),
                $data['sessionPasswordHash'],
                $data['personalPasswordHash'] ?? null,
                $request->has('requireAccept') ? (bool) $data['requireAccept'] : null,
            );
        } catch (PairingException $e) {
            return $this->fail($e);
        }

        return response()->json($result, 201);
    }

    public function pending(Request $request, string $deviceId, PairingService $pairing): JsonResponse
    {
        $token = $this->deviceToken($request);
        try {
            $pendingReq = $pairing->pendingForAgent($deviceId, $token);
        } catch (PairingException $e) {
            return $this->fail($e);
        }

        return response()->json(['pending' => $pendingReq]);
    }

    public function respond(Request $request, string $deviceId, PairingService $pairing): JsonResponse
    {
        $data = $request->validate([
            'requestId' => ['required', 'string', 'max:64'],
            'accept' => ['required', 'boolean'],
        ]);
        try {
            $pairing->respond($deviceId, $this->deviceToken($request), $data['requestId'], (bool) $data['accept']);
        } catch (PairingException $e) {
            return $this->fail($e);
        }

        return response()->json(['ok' => true]);
    }

    public function policy(Request $request, string $deviceId, PairingService $pairing): JsonResponse
    {
        $data = $request->validate([
            'personalPasswordHash' => ['nullable', 'string', 'max:128'],
            'requireAccept' => ['nullable', 'boolean'],
        ]);
        try {
            $pairing->updatePolicy(
                $deviceId,
                $this->deviceToken($request),
                $request->has('personalPasswordHash') ? (string) ($data['personalPasswordHash'] ?? '') : null,
                $request->has('requireAccept') ? (bool) $data['requireAccept'] : null,
            );
        } catch (PairingException $e) {
            return $this->fail($e);
        }

        return response()->json(['ok' => true]);
    }

    // ---- Console (operator) ----

    public function connect(Request $request, PairingService $pairing): JsonResponse
    {
        $data = $request->validate([
            'pairingId' => ['required', 'string', 'max:64'],
            'password' => ['required', 'string', 'max:128'],
        ]);
        $ctx = $request->attributes->get('platformContext');
        $tenantId = $ctx instanceof PlatformContext ? $ctx->tenantId : null;
        try {
            $result = $pairing->connect($data['pairingId'], $data['password'], $this->actor($request), $tenantId);
        } catch (PairingException $e) {
            return $this->fail($e);
        }

        return response()->json($result);
    }

    public function connectStatus(Request $request, string $deviceId, string $requestId, PairingService $pairing): JsonResponse
    {
        try {
            $result = $pairing->connectStatus($deviceId, $requestId, $this->actor($request));
        } catch (PairingException $e) {
            return $this->fail($e);
        }

        return response()->json($result);
    }

    // ---- helpers ----

    private function deviceToken(Request $request): string
    {
        // Agents present their device credential as a bearer token or X-Device-Token header.
        return (string) ($request->bearerToken() ?: $request->header('X-Device-Token', ''));
    }

    private function actor(Request $request): string
    {
        return (string) $request->attributes->get('actor', 'operator');
    }

    private function fail(PairingException $e): JsonResponse
    {
        $status = match ($e->errorCode) {
            'not_found' => 404,
            'invalid_credential', 'unauthorized' => 403,
            'forbidden' => 403,
            'expired', 'rejected' => 409,
            default => 422,
        };

        return response()->json(['error' => ['code' => strtoupper($e->errorCode), 'message' => $e->getMessage()]], $status);
    }
}
