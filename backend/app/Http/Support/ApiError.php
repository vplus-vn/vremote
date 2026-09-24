<?php

declare(strict_types=1);

namespace App\Http\Support;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Platform\Core\Http\ApiResponder;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * [v0.1.1] Chuẩn hoá API v1 — catalog mã lỗi + render exception về envelope lỗi chuẩn (2026-09-23)
 *
 * Nguồn chuẩn: control/docs/references/api-standard.md §4.2 — `{error:{code,message}}`, `code`
 * UPPER_SNAKE_CASE ổn định, HTTP status mang ngữ nghĩa. Dùng core-lib `ApiResponder` để dựng body.
 *
 * Mã nghiệp vụ do controller trả (giữ nguyên, client so khớp theo `code`): NOT_FOUND, FORBIDDEN,
 * UNAUTHENTICATED, INVALID_ARGUMENT, ALREADY_EXISTS, DEVICE_OFFLINE, DEVICE_UNREACHABLE,
 * DEVICE_NOT_PROVISIONED, GATED_OFF, CONNECT_REQUIRED, CONFIRM_REQUIRED, PLATFORM_UNCONFIGURED,
 * PLATFORM_UNREACHABLE, REPLAY_DETECTED + mã từ Enrollment/Pairing/Transfer exception (upper-case).
 */
final class ApiError
{
    private const BY_STATUS = [
        400 => ['BAD_REQUEST', 'Bad request.'],
        401 => ['UNAUTHENTICATED', 'Authentication required.'],
        403 => ['FORBIDDEN', 'Forbidden.'],
        404 => ['NOT_FOUND', 'Not found.'],
        405 => ['METHOD_NOT_ALLOWED', 'Method not allowed.'],
        409 => ['CONFLICT', 'Conflict.'],
        413 => ['PAYLOAD_TOO_LARGE', 'Payload too large.'],
        415 => ['UNSUPPORTED_MEDIA_TYPE', 'Unsupported media type.'],
        422 => ['VALIDATION_FAILED', 'The given data was invalid.'],
        429 => ['RATE_LIMITED', 'Too many requests.'],
        500 => ['INTERNAL_ERROR', 'Internal server error.'],
        502 => ['UPSTREAM_ERROR', 'Upstream error.'],
        503 => ['SERVICE_UNAVAILABLE', 'Service unavailable.'],
    ];

    public static function codeForStatus(int $status): string
    {
        return self::BY_STATUS[$status][0] ?? ($status >= 500 ? 'INTERNAL_ERROR' : 'ERROR');
    }

    public static function defaultMessage(int $status): string
    {
        return self::BY_STATUS[$status][1] ?? ($status >= 500 ? 'Internal server error.' : 'Request failed.');
    }

    /**
     * Render một exception thành envelope lỗi chuẩn. Không lộ chi tiết nội bộ (5xx luôn thông
     * điệp chung).
     */
    public static function fromThrowable(Throwable $e): JsonResponse
    {
        if ($e instanceof ValidationException) {
            return ApiResponder::fail('VALIDATION_FAILED', $e->getMessage(), $e->errors(), $e->status);
        }
        if ($e instanceof AuthenticationException) {
            return ApiResponder::fail('UNAUTHENTICATED', self::defaultMessage(401), [], 401);
        }
        if ($e instanceof AuthorizationException) {
            return ApiResponder::fail('FORBIDDEN', $e->getMessage() !== '' ? $e->getMessage() : self::defaultMessage(403), [], 403);
        }
        if ($e instanceof HttpExceptionInterface) {
            $status = $e->getStatusCode();
            $message = $status < 500 && $e->getMessage() !== '' ? $e->getMessage() : self::defaultMessage($status);
            $response = ApiResponder::fail(self::codeForStatus($status), $message, [], $status);
            foreach ($e->getHeaders() as $name => $value) {
                $response->headers->set($name, $value);
            }

            return $response;
        }

        return ApiResponder::fail('INTERNAL_ERROR', self::defaultMessage(500), [], 500);
    }
}
