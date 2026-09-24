<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Support\ApiError;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Platform\Core\Http\ApiResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * [v0.1.1] Chuẩn hoá API v1 — response envelope theo control/docs/references/api-standard.md §4 (2026-09-23)
 *
 * Bọc body của các endpoint nghiệp vụ `/api/vremote/v1/...` vào envelope chuẩn của
 * vplus-platform (core-lib `ApiResponse`): thành công `{data}`, lỗi `{error:{code,message,details?}}`.
 *
 * Tương thích ngược (contract `api/vremote` v0.0.1 đang khoá, agent Android đã cài ngoài
 * hiện trường đọc body trần): shape được CHỌN theo request —
 *   - header `X-Api-Envelope: v1`     → envelope chuẩn;
 *   - header `X-Api-Envelope: legacy` → body trần như v0.0.1;
 *   - không có header                 → theo `vremote.api_envelope_default` (mặc định `legacy`).
 * Lật mặc định sang `v1` là quyết định Contract Change Gate (chỉ đổi env, không đổi code).
 *
 * Lỗi do framework sinh (validation/404/405/500...) luôn theo envelope lỗi chuẩn ở cả hai chế
 * độ — xem bootstrap/app.php (trước đây là shape mặc định Laravel, không thuộc contract).
 * Ops endpoint (`/api/vremote/health`, `/api/vremote/ready`) không version, không bọc.
 */
final class ApiEnvelope
{
    public const HEADER = 'X-Api-Envelope';

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $request->is('api/vremote/v1/*')) {
            return $response;
        }

        $mode = self::mode($request);
        $response->headers->set(self::HEADER, $mode);

        if ($mode !== 'v1' || ! $response instanceof JsonResponse || $response->getStatusCode() === 204) {
            return $response;
        }

        $body = $response->getData(true);
        $status = $response->getStatusCode();

        if ($status >= 400) {
            // Controller đã trả envelope lỗi chuẩn → giữ nguyên.
            if (is_array($body) && isset($body['error']['code'])) {
                return $response;
            }
            // Body lỗi legacy (vd 409 replay của /commands, body null) → envelope lỗi, giữ
            // payload cũ trong `details` để client không mất thông tin.
            $details = is_array($body) ? $body : [];
            $message = is_array($body) && is_string($body['message'] ?? null) ? $body['message'] : ApiError::defaultMessage($status);
            unset($details['message']);
            $code = is_array($body) && is_string($body['result'] ?? null) && $body['result'] !== ''
                ? strtoupper($body['result'])
                : ApiError::codeForStatus($status);

            return $response->setData(ApiResponse::error($code, $message, $details));
        }

        // Upstream đã là envelope (vd auth/login proxy tới Platform trả {data}) → không bọc lần 2.
        if (is_array($body) && array_key_exists('data', $body)) {
            return $response;
        }

        return $response->setData(ApiResponse::success($body));
    }

    public static function mode(Request $request): string
    {
        $asked = strtolower(trim((string) $request->headers->get(self::HEADER, '')));
        if ($asked === 'v1' || $asked === 'legacy') {
            return $asked;
        }

        return config('vremote.api_envelope_default') === 'v1' ? 'v1' : 'legacy';
    }
}
