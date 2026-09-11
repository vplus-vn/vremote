<?php

declare(strict_types=1);

namespace App\Infrastructure\Platform;

use App\Domain\Contracts\PlatformClient;
use App\Domain\Platform\PlatformContext;
use Illuminate\Http\Request;
use Platform\Core\Http\Middleware\CheckAuthMiddleware;
use Symfony\Component\HttpFoundation\Response;

/**
 * House-standard Platform adapter (DSG-VREMOTE-002 §4, handoff #12).
 *
 * Delegates token verification to the shared vplus-platform/core-lib
 * CheckAuthMiddleware (RS256 verify + optional iss/aud check + TenantContext),
 * the same primitive used by the other vplus-platform services
 * (commerce-service / coupon-service). VRemote keeps NO user/tenant/auth store:
 * core-lib is the single verifier and this adapter only maps its verified claims
 * into VRemote's framework-free PlatformContext, so ResolvePlatformContext,
 * AuthorizationService and the controllers stay unchanged.
 *
 * The concrete CheckAuthMiddleware is built from core-lib's config/core.php
 * (CORE_JWT_PUBLIC_KEY / CORE_JWT_PUBLIC_KEY_PATH + optional issuer/audience) and
 * injected here. The OIDC/JWT clients remain as configured fallbacks (see
 * AppServiceProvider) for environments that do not carry a core-lib JWT key.
 */
final class CoreLibPlatformClient implements PlatformClient
{
    public function __construct(private readonly CheckAuthMiddleware $checkAuth) {}

    public function verify(string $bearerToken): ?PlatformContext
    {
        if ($bearerToken === '') {
            return null;
        }

        // Drive the bearer token through core-lib's middleware. The middleware sets
        // the verified claims on the request as the `auth` attribute and calls the
        // "next" closure only when verification succeeds; on any failure it
        // short-circuits with a 401 and the closure never runs.
        $request = Request::create('/', 'GET');
        $request->headers->set('Authorization', 'Bearer '.$bearerToken);

        $verified = false;
        /** @var array<string,mixed> $claims */
        $claims = [];

        $this->checkAuth->handle($request, function (Request $verifiedRequest) use (&$verified, &$claims): Response {
            $verified = true;
            $claims = (array) $verifiedRequest->attributes->get('auth', []);

            return new Response('', 200);
        });

        if (! $verified) {
            return null;
        }

        $context = PlatformContext::fromClaims($claims);

        return $context->userId !== '' ? $context : null;
    }
}
