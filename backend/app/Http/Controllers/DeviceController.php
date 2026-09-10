<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class DeviceController
{
    public function index(Request $request): JsonResponse
    {
        $status = $request->query('status', 'all');
        abort_unless(in_array($status, ['online', 'offline', 'all'], true), 400);
        $limit = min(max((int) $request->query('limit', 50), 1), 100);

        return response()->json(['items' => [], 'nextCursor' => null, 'status' => $status, 'limit' => $limit]);
    }

    public function show(string $deviceId): JsonResponse
    {
        abort(404);
    }
}
