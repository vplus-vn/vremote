<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Contracts\AccessGrantRepository;
use App\Domain\Contracts\AuditRepository;
use App\Domain\Contracts\CommandRepository;
use App\Domain\Contracts\DeviceRepository;
use App\Domain\Contracts\DeviceTransferRepository;
use App\Domain\Contracts\EnrollmentRepository;
use App\Domain\Contracts\NonceRepository;
use App\Domain\Contracts\PlatformClient;
use App\Domain\Contracts\RevokedSessionRepository;
use App\Domain\Contracts\SessionRepository;
use App\Domain\Support\Clock;
use App\Domain\Support\DefaultSecrets;
use App\Domain\Support\Secrets;
use App\Domain\Support\SystemClock;
use App\Infrastructure\Persistence\Config\ConfigDeviceRepository;
use App\Infrastructure\Persistence\Mongo\MongoAccessGrantRepository;
use App\Infrastructure\Persistence\Mongo\MongoDeviceRepository;
use App\Infrastructure\Persistence\Mongo\MongoDeviceTransferRepository;
use App\Infrastructure\Persistence\Mongo\MongoEnrollmentRepository;
use App\Infrastructure\Persistence\Mongo\MongoNonceRepository;
use App\Infrastructure\Persistence\Mongo\MongoRevokedSessionRepository;
use App\Infrastructure\Platform\CoreLibPlatformClient;
use App\Infrastructure\Platform\FakePlatformClient;
use App\Infrastructure\Platform\CheckRefPlatformClient;
use App\Infrastructure\Platform\HttpPlatformClient;
use App\Infrastructure\Platform\JwtPlatformClient;
use App\Infrastructure\Platform\OidcPlatformClient;
use App\Services\AuditStore;
use App\Services\CommandStore;
use App\Services\SessionStore;
use Illuminate\Support\ServiceProvider;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Support services.
        $this->app->bind(Clock::class, SystemClock::class);
        $this->app->bind(Secrets::class, DefaultSecrets::class);

        // Existing file-backed stores keep the baseline session/command/audit paths.
        $this->app->bind(SessionRepository::class, SessionStore::class);
        $this->app->bind(CommandRepository::class, CommandStore::class);
        $this->app->bind(AuditRepository::class, AuditStore::class);

        // Device registry: MongoDB in production, config-JSON fallback otherwise.
        $useMongo = config('database.default') === 'mongodb'
            || env('DB_CONNECTION') === 'mongodb';
        $this->app->bind(
            DeviceRepository::class,
            $useMongo ? MongoDeviceRepository::class : ConfigDeviceRepository::class,
        );

        // v0.1.2 domain collections live in MongoDB (used by the device-lifecycle
        // and authorization endpoints). Bindings are lazy — no DB access at boot.
        $this->app->bind(EnrollmentRepository::class, MongoEnrollmentRepository::class);
        $this->app->bind(AccessGrantRepository::class, MongoAccessGrantRepository::class);
        $this->app->bind(DeviceTransferRepository::class, MongoDeviceTransferRepository::class);
        $this->app->bind(NonceRepository::class, MongoNonceRepository::class);
        $this->app->bind(RevokedSessionRepository::class, MongoRevokedSessionRepository::class);

        // Single Platform integration point (no VREMOTE_PLATFORM_MODE). Selection is
        // config-driven, not a mode toggle:
        //   - local/testing dev-auth         -> Fake (real adapter, fixed context)
        //   - core-lib JWT key configured    -> CoreLib: house-standard verification via
        //                                        vplus-platform/core-lib CheckAuthMiddleware
        //                                        (RS256 + iss/aud + TenantContext), same as
        //                                        the other vplus-platform services (#12)
        //   - VREMOTE_PLATFORM_URL set        -> OIDC fallback: verify RS256 JWT via the
        //                                        platform JWKS (.well-known/openid-configuration)
        //   - a static Platform public key    -> JWT fallback: verify with that key
        //   - otherwise                       -> HTTP introspection against the platform
        $this->app->bind(PlatformClient::class, function (): PlatformClient {
            $devAuth = (bool) config('vremote.dev_auth', false);
            if ($devAuth && $this->app->environment(['local', 'testing'])) {
                return new FakePlatformClient(actor: (string) config('vremote.dev_actor', 'local-admin'));
            }

            // Introspection (like the S&B admin portal): ask the Platform "who is this token?"
            // instead of verifying a JWT locally. The right path when the Platform issues OPAQUE
            // tokens. Enabled by VREMOTE_PLATFORM_INTROSPECT with VREMOTE_PLATFORM_URL set.
            $platformUrlIntrospect = (string) config('vremote.platform_url', '');
            if ((bool) config('vremote.platform_introspect', false) && $platformUrlIntrospect !== '') {
                // VPlus contract: exchange the opaque referenceToken for a JWT via checkRef.
                return new CheckRefPlatformClient(
                    $platformUrlIntrospect,
                    (string) config('vremote.platform_checkref_path', '/v1/auth/checkRef'),
                    (int) config('vremote.introspect_ttl', 30),
                );
            }

            // Primary: shared vplus-platform/core-lib auth (house standard). Only when a
            // core-lib JWT public key is actually configured; otherwise fall through to the
            // standards-based fallbacks so the stack keeps working during migration.
            if ($this->coreLibJwtConfigured()) {
                try {
                    return $this->app->make(CoreLibPlatformClient::class);
                } catch (\Throwable) {
                    // Misconfigured core-lib key (e.g. path missing): use the fallback below.
                }
            }

            $platformUrl = (string) config('vremote.platform_url', '');
            if ($platformUrl !== '') {
                return new OidcPlatformClient($platformUrl, (string) config('vremote.jwt_alg', 'RS256'));
            }

            $publicKey = (string) config('vremote.jwt_public_key', '');
            if ($publicKey !== '') {
                return new JwtPlatformClient($publicKey, (string) config('vremote.jwt_alg', 'RS256'));
            }

            return new HttpPlatformClient($platformUrl);
        });
    }

    /**
     * Whether core-lib has a usable JWT public key configured (inline PEM or a readable
     * key file), which is what CheckAuthMiddleware needs to verify Platform tokens.
     */
    private function coreLibJwtConfigured(): bool
    {
        $inline = (string) config('core.jwt.public_key', '');
        if (trim($inline) !== '') {
            return true;
        }

        $path = (string) config('core.jwt.public_key_path', '');

        return trim($path) !== '' && is_file($path);
    }

    public function boot(): void {}
}
