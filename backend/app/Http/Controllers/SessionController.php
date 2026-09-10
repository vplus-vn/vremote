<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class SessionController
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['deviceId' => ['required','string'], 'capability' => ['required','in:view,control'], 'preferredMaxFps' => ['nullable','integer','min:1','max:60']]);
        $sessionId = 'S-'.Str::upper(Str::random(12));
        return response()->json([
            'sessionId' => $sessionId,
            'capability' => $data['capability'],
            'signalingUrl' => config('vremote.signaling_url', 'wss://vremote.example/signaling'),
            'sessionToken' => Str::random(48),
            'iceServers' => config('vremote.ice_servers', []),
            'ttlSec' => (int) config('vremote.session_ttl', 1800),
        ], 201);
    }

    public function destroy(string $sessionId): JsonResponse
    {
        return response()->json(null, 204);
    }
}
