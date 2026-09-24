<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * [v0.1.1] Chuẩn hoá API v1 — ops endpoint không version (api-standard §3.3) (2026-09-23)
 *
 * - `GET /api/vremote/health`: liveness, KHÔNG chạm DB, luôn 200 khi app sống (deploy gate).
 * - `GET /api/vremote/ready`: readiness — kiểm MongoDB (khi DB_CONNECTION=mongodb) và kênh
 *   internal của signaling; 200 khi mọi check đạt, 503 khi có check lỗi (informational).
 */
final class OpsController
{
    public function health(): JsonResponse
    {
        return response()->json(['status' => 'ok', 'service' => 'vremote-backend']);
    }

    public function ready(): JsonResponse
    {
        $checks = [
            'mongodb' => $this->checkMongo(),
            'signaling' => $this->checkSignaling(),
        ];
        $ok = ! in_array('fail', $checks, true);

        return response()->json([
            'status' => $ok ? 'ready' : 'not_ready',
            'service' => 'vremote-backend',
            'checks' => $checks,
        ], $ok ? 200 : 503);
    }

    private function checkMongo(): string
    {
        if (config('database.default') !== 'mongodb') {
            return 'skipped';
        }
        try {
            DB::connection('mongodb')->getDatabase()->command(['ping' => 1]);

            return 'ok';
        } catch (\Throwable) {
            return 'fail';
        }
    }

    private function checkSignaling(): string
    {
        $url = (string) config('vremote.signaling_internal_url', '');
        if ($url === '') {
            return 'skipped';
        }
        try {
            // Bất kỳ phản hồi HTTP nào (kể cả 4xx) nghĩa là tiến trình signaling đang lắng nghe.
            Http::timeout(2)->get(rtrim($url, '/').'/');

            return 'ok';
        } catch (\Throwable) {
            return 'fail';
        }
    }
}
